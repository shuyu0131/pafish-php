<?php

declare(strict_types=1);

namespace Pafish\Core;

/**
 * 配置中心：读取安装向导生成的运行配置，点号路径取值。
 * 新安装使用 runtime/config.php；为兼容旧站点，仍会识别根目录 config.php。
 */
final class Config
{
    private static ?array $data = null;

    /**
     * 解析当前安装使用的配置文件。运行目录优先，避免新旧配置同时存在时
     * 仍意外读取旧配置。
     */
    public static function resolveFile(string $root): ?string
    {
        $root = rtrim($root, '/\\');
        foreach (['runtime/config.php', 'config.php'] as $relative) {
            $file = $root . '/' . $relative;
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

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
