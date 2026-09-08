<?php

declare(strict_types=1);

namespace Pafish\Core;

/** Small, dependency-free file cache for read-heavy site data. */
final class Cache
{
    private const DIR = 'runtime/cache';

    private static function path(string $key): string
    {
        if (!preg_match('/^[a-zA-Z0-9:_\-.\/]+$/', $key) || str_contains($key, '..')) {
            throw new \InvalidArgumentException('Invalid cache key');
        }
        $root = PAFISH_ROOT . '/' . self::DIR;
        if (!is_dir($root)) {
            @mkdir($root, 0775, true);
        }
        return $root . '/' . sha1($key) . '.php';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $file = self::path($key);
        if (!is_file($file)) {
            return $default;
        }
        $payload = @include $file;
        if (!is_array($payload) || !isset($payload['expires'], $payload['value'])) {
            return $default;
        }
        if ((int) $payload['expires'] > 0 && (int) $payload['expires'] < time()) {
            @unlink($file);
            return $default;
        }
        return $payload['value'];
    }

    public static function set(string $key, mixed $value, int $ttl = 300): void
    {
        $file = self::path($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        $data = '<?php return ' . var_export([
            'key' => $key,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'value' => $value,
        ], true) . ';';
        if (@file_put_contents($tmp, $data, LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
    }

    public static function delete(string $key): void
    {
        @unlink(self::path($key));
    }

    public static function clearPrefix(string $prefix): void
    {
        $dir = PAFISH_ROOT . '/' . self::DIR;
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $payload = @include $file;
            if ($prefix === '' || (is_array($payload) && str_starts_with((string) ($payload['key'] ?? ''), $prefix))) {
                @unlink($file);
            }
        }
    }

    public static function clear(): void
    {
        self::clearPrefix('');
    }
}
