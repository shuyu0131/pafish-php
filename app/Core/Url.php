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

    /**
     * 静态资源直链（css/js/uploads/vendor 等 public/ 下文件）。
     * 开箱即用：URL 带 /public/ 前缀直接指向真实文件，Web 服务器（Nginx/Apache）
     * 任何配置下都能直接读盘返回，无需 try_files / PHP 兜底 / .htaccess 静态重写。
     * 本地文件按修改时间附加版本号，主程序升级后浏览器不会继续使用旧 CSS/JS 缓存。
     * 前台主题布局样式由 Theme 服务内联注入，不经过此处。
     */
    public static function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $url = self::base() . '/public/' . $path;
        $pathOnly = explode('?', $path, 2)[0];
        $file = dirname(__DIR__, 2) . '/public/' . $pathOnly;
        if (!is_file($file)) {
            return $url;
        }
        return $url . (str_contains($path, '?') ? '&' : '?') . 'v=' . (string) filemtime($file);
    }

    /**
     * API 路径（含 query 适配）：pretty 模式 /api/xxx?q=1；
     * 非 pretty 模式 /index.php?p=api/xxx&q=1（?p= 后不能出现 '?'，query 改用 & 拼接）
     */
    public static function api(string $path): string
    {
        $pretty = (bool) Config::get('pretty_urls', true);
        [$pathPart, $query] = array_pad(explode('?', $path, 2), 2, '');
        $pathPart = ltrim($pathPart, '/');
        if ($pretty) {
            return self::base() . '/api/' . $pathPart . ($query !== '' ? '?' . $query : '');
        }
        return self::base() . '/index.php?p=api/' . $pathPart . ($query !== '' ? '&' . $query : '');
    }

    /** 站点绝对 URL（RSS / sitemap / OG / 媒体复制链接用）；已含协议的外链原样返回 */
    public static function absolute(string $path = ''): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $base = rtrim((string) Config::get('site_url', ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return $base . self::to($path);
    }
}
