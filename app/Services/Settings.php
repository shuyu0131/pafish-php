<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/**
 * 站点设置：settings 键值表，请求内全表缓存
 */
final class Settings
{
    private static ?array $cache = null;

    /** 全量设置（键=>值），单请求内缓存 */
    public static function all(): array
    {
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
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** 写单键（upsert）；返回是否新增 */
    public static function set(string $key, string $value): bool
    {
        $exists = DB::value('SELECT COUNT(*) FROM settings WHERE `key` = ?', [$key]) > 0;
        if ($exists) {
            DB::execute('UPDATE settings SET `value` = ? WHERE `key` = ?', [$value, $key]);
        } else {
            DB::execute('INSERT INTO settings (`key`, `value`) VALUES (?, ?)', [$key, $value]);
        }
        self::$cache[$key] = $value;
        return !$exists;
    }

    /** 批量写 */
    public static function setMany(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            self::set((string) $key, (string) $value);
        }
    }

    /** 删单键（返回是否存在） */
    public static function remove(string $key): bool
    {
        $exists = DB::execute('DELETE FROM settings WHERE `key` = ?', [$key]) > 0;
        if ($exists) {
            unset(self::$cache[$key]);
        }
        return $exists;
    }

    /**
     * 按前缀删一批键，返回删除条数。
     *
     * settings 的命名空间是靠**冒号拼字符串**实现的（`theme_settings:`、`plugin_settings:`、
     * `plugin_data:`、`extension_version:`…），没有独立的表来承载。
     * 所以"清理某个命名空间"只能靠前缀匹配；这里给它一个统一入口，
     * 避免每个调用点各写一遍 LIKE 或各自遍历全表。
     *
     * 用 `LIKE ? ESCAPE '\\'` 而不是拼接：`_` 和 `%` 在 LIKE 里是通配符，
     * 而 settings key 里 `_` 很常见（`site_name`、`plugin_settings`），
     * 不转义就会误删（`plugin_data:x` 的 `_` 会匹配任意字符）。
     */
    public static function deletePrefix(string $prefix): int
    {
        // 空前缀会把整张表删光，几乎总是调用方的 bug，直接拒绝而不是静默清库
        if ($prefix === '') {
            throw new \InvalidArgumentException('Settings::deletePrefix() 不接受空前缀');
        }
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
        $count = DB::execute(
            "DELETE FROM settings WHERE `key` LIKE ? ESCAPE '\\\\'",
            [$escaped . '%']
        );
        // 缓存是全表快照，删完必须按同一规则把它也剔除，否则请求内仍读到旧值
        if (self::$cache !== null) {
            foreach (array_keys(self::$cache) as $key) {
                if (str_starts_with($key, $prefix)) {
                    unset(self::$cache[$key]);
                }
            }
        }
        return $count;
    }

    /** 清缓存（外部直改库后调用） */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
