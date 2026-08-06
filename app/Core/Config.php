<?php

declare(strict_types=1);

namespace Pafish\Core;

/**
 * 配置中心：读取 config.php（安装向导生成），点号路径取值
 */
final class Config
{
    private static ?array $data = null;

    public static function load(string $file): array
    {
        self::$data = require $file;
        return self::$data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$data ?? [];
        foreach (explode('.', $key) as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }
        return $value;
    }

    public static function all(): array
    {
        return self::$data ?? [];
    }
}
