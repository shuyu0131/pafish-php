<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Config;

/**
 * 主题系统（对标 Node 版 src/lib/theme.ts）：
 * - 主题 = themes/{name}/theme.json（manifest + 设置 schema）+ theme.css（语义 CSS 变量）+ 可选 PHP 模板文件
 * - 模板解析优先级：主题目录文件 → 系统内置 fallback（app/Views/theme/）
 */
final class Theme
{
    private const NAME_PATTERN = '/^[a-z0-9_-]{1,50}$/';

    private static ?array $manifests = [];
    private static ?array $values = null;

    public static function root(): string
    {
        return dirname(__DIR__, 2) . '/themes';
    }

    /** 当前激活主题名（默认 default；非法值回退 default） */
    public static function active(): string
    {
        $name = (string) Settings::get('active_theme', 'default');
        return self::isValidName($name) ? $name : 'default';
    }

    /** 扫描 themes/ 目录下的全部主题名 */
    public static function list(): array
    {
        $names = [];
        foreach (glob(self::root() . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            if (self::isValidName($name) && is_file($dir . '/theme.json')) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    /** 读取主题 manifest（theme.json）；无效返回 null */
    public static function manifest(string $name): ?array
    {
        if (!self::isValidName($name)) {
            return null;
        }
        if (array_key_exists($name, self::$manifests)) {
            return self::$manifests[$name];
        }
        $file = self::root() . '/' . $name . '/theme.json';
        if (!is_file($file)) {
            return self::$manifests[$name] = null;
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (!is_array($json) || ($json['name'] ?? null) !== $name) {
            return self::$manifests[$name] = null;
        }
        return self::$manifests[$name] = $json;
    }

    /** 主题 CSS（theme.css 内容，前台 <style> 注入）；无文件返回 null */
    public static function css(string $name): ?string
    {
        if (!self::isValidName($name)) {
            return null;
        }
        $file = self::root() . '/' . $name . '/theme.css';
        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /**
     * 主题设置值：theme.json settings 默认值 + 已保存的 theme:{key} 覆盖
     */
    public static function values(): array
    {
        if (self::$values !== null) {
            return self::$values;
        }
        $manifest = self::manifest(self::active());
        $values = [];
        foreach (($manifest['settings'] ?? []) as $field) {
            if (is_array($field) && isset($field['key'])) {
                $values[$field['key']] = (string) ($field['default'] ?? '');
            }
        }
        // 覆盖已保存值
        $all = Settings::all();
        foreach ($all as $key => $value) {
            if (str_starts_with($key, 'theme:') && isset($values[substr($key, 6)])) {
                $values[substr($key, 6)] = $value;
            }
        }
        return self::$values = $values;
    }

    public static function value(string $key, string $default = ''): string
    {
        $values = self::values();
        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    /**
     * 模板文件解析：主题覆盖优先，系统 fallback 兜底
     * 模板名白名单防路径穿越
     */
    public static function template(string $name): string
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new \RuntimeException('非法的模板名：' . $name);
        }
        $themeFile = self::root() . '/' . self::active() . '/' . $name . '.php';
        if (is_file($themeFile)) {
            return $themeFile;
        }
        $systemFile = dirname(__DIR__) . '/Views/theme/' . $name . '.php';
        if (is_file($systemFile)) {
            return $systemFile;
        }
        throw new \RuntimeException('模板不存在：' . $name);
    }

    private static function isValidName(string $name): bool
    {
        return $name !== '' && preg_match(self::NAME_PATTERN, $name) === 1;
    }
}
