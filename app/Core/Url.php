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
     * 静态资源直链（css/js/uploads/vendor 等 public/ 下文件）：
     * 无论 pretty_urls 开关，资源始终对外保持根路径（Web 服务器直连；
     * 无静态配置的环境由框架入口的 PHP 兜底直出），仅需带上子目录前缀。
     */
    public static function asset(string $path): string
    {
        return self::base() . '/' . ltrim($path, '/');
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
