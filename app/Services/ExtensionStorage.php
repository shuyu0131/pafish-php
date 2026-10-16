<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/** 扩展共用的小数据存储；按身份和数据键隔离，不为每个插件创建相同结构的表。 */
final class ExtensionStorage
{
    public static function legacyAddress(string $key): ?array
    {
        if (preg_match('/^(plugin|theme)_data:([a-z0-9_-]{1,50})$/D', $key, $m)) {
            return [$m[1], $m[2], 'data'];
        }
        if (preg_match('/^plugin_settings:([a-z0-9_-]{1,50})$/D', $key, $m)) {
            return ['plugin', $m[1], 'settings'];
        }
        if (preg_match('/^theme_settings:([a-z0-9_-]{1,50}):([\x20-\x7e]{1,128})$/D', $key, $m)) {
            return ['theme', $m[1], 'setting:' . $m[2]];
        }
        if (preg_match('/^extension_(version|schema_fingerprint):(plugin|theme):([a-z0-9_-]{1,50})$/D', $key, $m)) {
            return [$m[2], $m[3], $m[1]];
        }
        return null;
    }

    private static function legacyKey(array $row): ?string
    {
        $kind = $row['extension_type']; $name = $row['extension_name']; $key = $row['data_key'];
        return match (true) {
            $key === 'data' => $kind . '_data:' . $name,
            $key === 'settings' && $kind === 'plugin' => 'plugin_settings:' . $name,
            str_starts_with($key, 'setting:') && $kind === 'theme' => 'theme_settings:' . $name . ':' . substr($key, 8),
            in_array($key, ['version', 'schema_fingerprint'], true) => 'extension_' . $key . ':' . $kind . ':' . $name,
            default => null,
        };
    }

    public static function get(string $kind, string $name, string $key, mixed $default = null): mixed
    {
        self::validate($kind, $name, $key);
        return DB::value('SELECT `value` FROM extension_storage WHERE extension_type = ? AND extension_name = ? AND data_key = ?', [$kind, $name, $key]) ?? $default;
    }

    public static function set(string $kind, string $name, string $key, string $value): bool
    {
        return MediaReferences::transaction(static function () use ($kind, $name, $key, $value) {
            $result = self::persistSet($kind, $name, $key, $value);
            if ($kind !== 'system') MediaReferences::sync('extension', MediaReferences::extensionKey($kind, $name, $key));
            return $result;
        });
    }

    private static function persistSet(string $kind, string $name, string $key, string $value): bool
    {
        self::validate($kind, $name, $key);
        return DB::execute('INSERT INTO extension_storage (extension_type, extension_name, data_key, `value`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$kind, $name, $key, $value]) === 1;
    }

    /** 一次 upsert；调用方可把它与其他表的写入放在同一事务。 */
    public static function setMany(array $rows): void
    {
        if ($rows === []) return;
        MediaReferences::transaction(static function () use ($rows): void {
            self::persistMany($rows);
            foreach ($rows as [$kind, $name, $key, $value]) {
                if ($kind !== 'system') MediaReferences::sync('extension', MediaReferences::extensionKey($kind, $name, $key));
            }
        });
    }

    private static function persistMany(array $rows): void
    {
        if ($rows === []) return;
        $params = [];
        foreach ($rows as [$kind, $name, $key, $value]) {
            self::validate($kind, $name, $key);
            array_push($params, $kind, $name, $key, $value);
        }
        DB::execute('INSERT INTO extension_storage (extension_type, extension_name, data_key, `value`) VALUES '
            . implode(', ', array_fill(0, count($rows), '(?, ?, ?, ?)'))
            . ' ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', $params);
    }

    public static function remove(string $kind, string $name, string $key): bool
    {
        return MediaReferences::transaction(static function () use ($kind, $name, $key) {
            $result = self::persistRemove($kind, $name, $key);
            if ($kind !== 'system') MediaReferences::forget('extension', MediaReferences::extensionKey($kind, $name, $key));
            return $result;
        });
    }

    private static function persistRemove(string $kind, string $name, string $key): bool
    {
        self::validate($kind, $name, $key);
        return DB::execute('DELETE FROM extension_storage WHERE extension_type = ? AND extension_name = ? AND data_key = ?', [$kind, $name, $key]) > 0;
    }

    /** 锁住一个 JSON 对象再修改，避免并发日志/历史记录互相覆盖；兼容旧 settings 源值。 */
    public static function updateJson(string $legacyKey, callable $update): array
    {
        return MediaReferences::transaction(static fn () => self::persistJson($legacyKey, $update));
    }

    private static function persistJson(string $legacyKey, callable $update): array
    {
        $address = self::legacyAddress($legacyKey);
        if ($address === null || !in_array($address[2], ['data', 'settings'], true)) {
            throw new \InvalidArgumentException('仅支持扩展 JSON 数据或设置');
        }
        // 首次写入也通过唯一键串行化；已有目标值绝不被旧源或空对象覆盖。
        DB::execute('INSERT INTO extension_storage (extension_type, extension_name, data_key, `value`)
            VALUES (?, ?, ?, COALESCE((SELECT `value` FROM settings WHERE `key` = ?), \'{}\'))
            ON DUPLICATE KEY UPDATE data_key = VALUES(data_key)', [...$address, $legacyKey]);
        $raw = DB::value('SELECT `value` FROM extension_storage WHERE extension_type = ? AND extension_name = ? AND data_key = ? FOR UPDATE', $address);
        $current = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($current)) {
            throw new \UnexpectedValueException('扩展 JSON 数据不是对象或数组');
        }
        $next = $update($current);
        if (!is_array($next)) {
            throw new \UnexpectedValueException('扩展数据修改回调必须返回数组');
        }
        self::set(...[...$address, json_encode($next, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        return $next;
    }

    /** 只读指定扩展的数据，不把其他插件日志带进普通站点设置读取。 */
    public static function values(string $kind, string $name): array
    {
        self::validate($kind, $name, 'data');
        $values = [];
        foreach (DB::fetchAll('SELECT data_key, `value` FROM extension_storage WHERE extension_type = ? AND extension_name = ?', [$kind, $name]) as $row) {
            $values[$row['data_key']] = $row['value'];
        }
        return $values;
    }

    /** 兼容显式请求旧键名全集的内部工具；常规读取不调用此方法。 */
    public static function legacyPairs(): array
    {
        $pairs = [];
        foreach (DB::fetchAll("SELECT extension_type, extension_name, data_key, `value` FROM extension_storage WHERE extension_type IN ('plugin', 'theme')") as $row) {
            $key = self::legacyKey($row);
            if ($key !== null) $pairs[$key] = $row['value'];
        }
        return $pairs;
    }

    public static function deleteLegacyPrefix(string $prefix): int
    {
        // 前缀操作低频（恢复默认/卸载）；用字面前缀匹配，避免 LIKE 的 _/% 误删。
        $count = 0;
        foreach (self::legacyPairs() as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $address = self::legacyAddress($key);
                $count += (int) self::remove(...$address);
            }
        }
        return $count;
    }

    /** 归属记录随“保留数据卸载”一起保留，防止其他扩展接管同名旧表。 */
    public static function claimTable(string $physical, string $kind, string $name, string $logical): void
    {
        self::validate($kind, $name, 'table:' . $logical);
        $owner = json_encode([$kind, $name, $logical], JSON_THROW_ON_ERROR);
        DB::execute("INSERT INTO extension_storage (extension_type, extension_name, data_key, `value`) VALUES ('system', 'table_owners', ?, ?) ON DUPLICATE KEY UPDATE data_key = VALUES(data_key)", [$physical, $owner]);
        if (self::getTableOwner($physical) !== $owner) {
            throw new \RuntimeException('扩展表归属冲突：' . $physical);
        }
    }

    public static function getTableOwner(string $physical): ?string
    {
        return DB::value("SELECT `value` FROM extension_storage WHERE extension_type = 'system' AND extension_name = 'table_owners' AND data_key = ?", [$physical]);
    }

    public static function releaseTable(string $physical, string $kind, string $name, string $logical): void
    {
        DB::execute("DELETE FROM extension_storage WHERE extension_type = 'system' AND extension_name = 'table_owners' AND data_key = ? AND BINARY `value` = BINARY ?", [$physical, json_encode([$kind, $name, $logical], JSON_THROW_ON_ERROR)]);
    }

    private static function validate(string $kind, string $name, string $key): void
    {
        if (!in_array($kind, ['plugin', 'theme'], true) || preg_match('/^[a-z0-9_-]{1,50}$/D', $name) !== 1
            || preg_match('/^[\x20-\x7e]{1,191}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('扩展存储地址不合法');
        }
    }
}
