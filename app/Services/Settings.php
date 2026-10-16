<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/**
 * 核心设置请求内缓存；旧扩展键名转到共用扩展存储，保留既有调用接口。
 */
final class Settings
{
    private static ?array $cache = null;

    /** 显式获取完整旧键名视图；普通站点读取使用 core()/get()。 */
    public static function all(): array
    {
        return array_replace(self::core(), ExtensionStorage::legacyPairs());
    }

    public static function core(): array
    {
        // Transactional reads must not escape into the request cache after a rollback.
        if (DB::pdo()->inTransaction()) {
            return array_column(DB::fetchAll('SELECT `key`, `value` FROM settings'), 'value', 'key');
        }
        if (self::$cache === null) {
            $rows = DB::fetchAll('SELECT `key`, `value` FROM settings');
            $map = [];
            foreach ($rows as $row) {
                $map[$row['key']] = $row['value'];
            }
            self::$cache = $map;
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $address = ExtensionStorage::legacyAddress($key);
        if ($address !== null) {
            $value = ExtensionStorage::get(...$address);
            // 只为未迁移/冲突保留的旧键兜底，不让旧值覆盖新的独立存储。
            return $value ?? DB::value('SELECT `value` FROM settings WHERE `key` = ?', [$key]) ?? $default;
        }
        $all = self::core();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** 写单键（upsert）；返回是否新增 */
    public static function set(string $key, string $value): bool
    {
        try {
            return MediaReferences::transaction(static function () use ($key, $value) {
                $result = self::persistSet($key, $value);
                MediaReferences::sync('setting', $key);
                return $result;
            });
        } catch (\Throwable $e) { self::reset(); throw $e; }
    }

    private static function persistSet(string $key, string $value): bool
    {
        $address = ExtensionStorage::legacyAddress($key);
        if ($address !== null) return ExtensionStorage::set(...array_merge($address, [$value]));
        $affected = DB::execute(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, $value]
        );
        self::reset();
        return $affected === 1;
    }

    /** 核心与扩展设置共同原子写入；兼容调用方事务，失败不污染缓存。 */
    public static function setMany(array $pairs): void
    {
        if ($pairs === []) return;
        try {
            MediaReferences::transaction(static function () use ($pairs): void {
                self::persistMany($pairs);
                foreach (array_keys($pairs) as $key) MediaReferences::sync('setting', (string) $key);
            });
        } catch (\Throwable $e) { self::reset(); throw $e; }
    }

    private static function persistMany(array $pairs): void
    {
        if ($pairs === []) {
            return;
        }
        $values = [];
        $params = [];
        $extensionRows = [];
        foreach ($pairs as $key => $value) {
            $address = ExtensionStorage::legacyAddress((string) $key);
            if ($address !== null) {
                $extensionRows[] = array_merge($address, [(string) $value]);
                continue;
            }
            $values[(string) $key] = (string) $value;
            $params[] = (string) $key;
            $params[] = (string) $value;
        }
        if ($values !== []) {
            DB::execute(
                'INSERT INTO settings (`key`, `value`) VALUES '
                    . implode(', ', array_fill(0, count($values), '(?, ?)'))
                    . ' ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
                $params
            );
        }
        ExtensionStorage::setMany($extensionRows);
        self::reset();
    }

    /** 删单键（返回是否存在） */
    public static function remove(string $key): bool
    {
        try {
            return MediaReferences::transaction(static function () use ($key) {
                $result = self::persistRemove($key);
                MediaReferences::forget('setting', $key);
                return $result;
            });
        } catch (\Throwable $e) { self::reset(); throw $e; }
    }

    private static function persistRemove(string $key): bool
    {
        $address = ExtensionStorage::legacyAddress($key);
        $removed = $address !== null && ExtensionStorage::remove(...$address);
        $exists = DB::execute('DELETE FROM settings WHERE `key` = ?', [$key]) > 0;
        if ($exists) self::reset();
        return $exists || $removed;
    }

    /**
     * 按前缀删一批键，返回删除条数。
     *
     * 同时支持核心表与已迁入 extension_storage 的旧命名空间。
     *
     * 用 `LIKE ? ESCAPE '\\'` 而不是拼接：`_` 和 `%` 在 LIKE 里是通配符，
     * 而 settings key 里 `_` 很常见（`site_name`、`plugin_settings`），
     * 不转义就会误删（`plugin_data:x` 的 `_` 会匹配任意字符）。
     */
    public static function deletePrefix(string $prefix): int
    {
        try {
            return MediaReferences::transaction(static function () use ($prefix) {
                $keys = array_values(array_filter(array_keys(self::core()), static fn ($key) => str_starts_with($key, $prefix)));
                $result = self::persistDeletePrefix($prefix);
                foreach ($keys as $key) MediaReferences::forget('setting', $key);
                return $result;
            });
        } catch (\Throwable $e) { self::reset(); throw $e; }
    }

    private static function persistDeletePrefix(string $prefix): int
    {
        // 空前缀会把整张表删光，几乎总是调用方的 bug，直接拒绝而不是静默清库
        if ($prefix === '') {
            throw new \InvalidArgumentException('Settings::deletePrefix() 不接受空前缀');
        }
        $extensionCount = ExtensionStorage::deleteLegacyPrefix($prefix);
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
        $count = DB::execute(
            "DELETE FROM settings WHERE `key` LIKE ? ESCAPE '\\\\'",
            [$escaped . '%']
        );
        self::reset();
        return $count + $extensionCount;
    }

    /** 清缓存（外部直改库后调用） */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
