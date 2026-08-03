<?php

declare(strict_types=1);

namespace Pafish\Core;

/**
 * 链接生成：统一处理伪静态/查询串两种部署模式与子目录部署
 * - pretty_urls = true  （.htaccess / nginx try_files）：/post/xxx
 * - pretty_urls = false （不支持伪静态的主机）：index.php?p=post/xxx
 * 子目录部署（如 /blog/）自动带上前缀
 */
final class Url
{
    private static ?string $base = null;

    /** 初始化：根据入口脚本位置计算子目录前缀（如 /blog） */
    public static function init(string $scriptName): void
    {
        $dir = dirname($scriptName);
        self::$base = ($dir === '/' || $dir === '\\') ? '' : rtrim($dir, '/\\');
    }

    public static function base(): string
    {
        if (self::$base === null) {
            self::init($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        }
        return self::$base;
    }

    /** 生成站内链接（如 to('/post/hello')）；首页传 '/' */
    public static function to(string $path): string
    {
        $pretty = (bool) Config::get('pretty_urls', true);
        $path = ltrim($path, '/');
        if ($pretty) {
            return self::base() . '/' . $path;
        }
        return self::base() . '/index.php?p=' . $path;
    }

    /** 站点绝对 URL（RSS / sitemap / OG 用） */
    public static function absolute(string $path = ''): string
    {
        $base = rtrim((string) Config::get('site_url', ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return $base . self::to($path);
    }
}
