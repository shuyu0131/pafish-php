<?php

declare(strict_types=1);

namespace Pafish\Services;

/** Resolve PHP template names while keeping every resolved path inside its owner. */
final class TemplateFiles
{
    public static function validName(string $name): bool
    {
        return strlen($name) <= 240 && preg_match('~^[a-z0-9_-]{1,64}(?:/[a-z0-9_-]{1,64}){0,7}$~D', $name) === 1;
    }

    public static function directory(string $root, string $relative = ''): ?string
    {
        if ($relative !== '' && !self::validName($relative)) return null;
        $base = realpath($root);
        $directory = $base === false ? false : realpath($base . ($relative === '' ? '' : '/' . $relative));
        return $base !== false && $directory !== false && self::inside($base, $directory) && is_dir($directory) ? $directory : null;
    }

    public static function find(string $root, string $name): ?string
    {
        if (!self::validName($name)) return null;
        $base = realpath($root);
        $file = $base === false ? false : realpath($base . '/' . $name . '.php');
        return $base !== false && $file !== false && self::inside($base, $file) && is_file($file) && is_readable($file) ? $file : null;
    }

    private static function inside(string $root, string $path): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        if (DIRECTORY_SEPARATOR === '\\') { $root = strtolower($root); $path = strtolower($path); }
        return $path === $root || str_starts_with($path, $root . '/');
    }
}
