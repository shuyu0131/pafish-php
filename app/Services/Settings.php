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

    /** 清缓存（外部直改库后调用） */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
