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
        $path = parse_url($scriptName, PHP_URL_PATH) ?: '/index.php';
        $path = '/' . ltrim(str_replace('\\', '/', (string) $path), '/');
        if (preg_match('#^(.*?)/index\\.php(?:/.*)?$#i', $path, $match) === 1) {
            self::$base = rtrim((string) ($match[1] ?? ''), '/');
            return;
        }
        self::$base = '';
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
        if ($pretty && str_starts_with($path, '/post/')) {
            $slug = rawurldecode(substr($path, 6));
            $structure = (string) \Pafish\Services\Settings::get('permalink_structure', '/post/%postname%');
            if ($structure === '/%postname%') {
                $path = '/' . rawurlencode($slug);
            }
        }
        $path = ltrim($path, '/');
        if ($pretty) {
            return self::base() . '/' . $path;
        }
        return self::base() . '/index.php?p=' . $path;
    }

    /**
     * 判断是否为可用于站内跳转的相对路径。
     *
     * 反斜杠和编码后的斜杠在浏览器 URL 规范化时可能变成外部主机，
     * 因此校验路径部分的解码结果，而不是只检查原始字符串前缀。
     */
    public static function isLocalPath(string $path): bool
    {
        if ($path === '' || $path[0] !== '/') {
            return false;
        }
        $pathPart = explode('?', $path, 2)[0];
        $decodedPath = rawurldecode($pathPart);
        if ($decodedPath === '' || $decodedPath[0] !== '/') {
            return false;
        }
        if (
            str_starts_with($decodedPath, '//')
            || str_contains($decodedPath, '\\')
            || preg_match('/[\\x00-\\x1F\\x7F]/', $path) === 1
            || preg_match('/[\\x00-\\x1F\\x7F]/', $decodedPath) === 1
        ) {
            return false;
        }
        return true;
    }

    /** 返回安全的站内路径；不安全输入使用指定回退路径。 */
    public static function localPathOr(string $path, string $fallback = '/'): string
    {
        return self::isLocalPath($path) ? $path : $fallback;
    }

    /**
     * 静态资源直链（css/js/uploads/vendor 等 public/ 下文件）。
     * 项目根目录部署使用 /public/ 前缀；public/ 作为文档根目录时省略前缀。
     * 本地文件按修改时间附加版本号，主程序升级后浏览器不会继续使用旧 CSS/JS 缓存。
     * 前台主题布局样式由 Theme 服务内联注入，不经过此处。
     */
    public static function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $url = self::publicPath($path);
        $pathOnly = explode('?', $path, 2)[0];
        $file = dirname(__DIR__, 2) . '/public/' . $pathOnly;
        if (!is_file($file)) {
            return $url;
        }
        // 文件时间能覆盖单文件替换；程序版本再提供一次升级级别的缓存边界，
        // 避免旧站点保留同一时间戳的 CSS/JS 时继续命中旧资源。
        return $url . (str_contains($path, '?') ? '&' : '?')
            . 'v=' . rawurlencode(Version::current() . '-' . (string) filemtime($file));
    }

    /** 静态文件 URL，不附加缓存版本参数；上传地址会持久化，需保持稳定。 */
    public static function publicPath(string $path): string
    {
        $path = ltrim($path, '/');
        $prefix = self::publicIsDocumentRoot() ? '' : '/public';
        return self::base() . $prefix . '/' . $path;
    }

    /** 判断 Web 文档根是否直接指向项目的 public/ 目录。 */
    private static function publicIsDocumentRoot(): bool
    {
        $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($documentRoot === '') {
            return false;
        }
        $documentRoot = realpath($documentRoot);
        $publicRoot = realpath(dirname(__DIR__, 2) . '/public');
        if ($documentRoot === false || $publicRoot === false) {
            return false;
        }
        return DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($documentRoot, $publicRoot) === 0
            : $documentRoot === $publicRoot;
    }

    /** 主题资源 URL。 */
    public static function themeAsset(string $theme, string $path): string
    {
        $theme = trim($theme, '/');
        $path = trim(str_replace('\\', '/', $path), '/');
        if (
            preg_match('/^[a-z0-9_-]{1,50}$/', $theme) !== 1
            || $path === ''
            || str_contains($path, '..')
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_contains($path, '//')
        ) {
            return self::base() . '/index.php?p=theme-assets';
        }

        $resolved = \Pafish\Services\Theme::resolveAssetPath($theme, $path);
        if ($resolved === null) {
            return self::base() . '/index.php?p=theme-assets';
        }
        $segments = array_values(array_filter(explode('/', $resolved), static fn (string $part): bool => $part !== ''));
        $url = self::base() . '/themes/' . rawurlencode($theme) . '/' . implode('/', array_map('rawurlencode', $segments));
        $file = dirname(__DIR__, 2) . '/themes/' . $theme . '/' . $resolved;
        if (is_file($file)) {
            $url .= '?v=' . (string) filemtime($file);
        }
        return $url;
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

    /**
     * Build an absolute URL only from the administrator-provided canonical site URL.
     * Security-sensitive links must not fall back to the untrusted Host header.
     */
    public static function trustedAbsolute(string $path = ''): ?string
    {
        $base = rtrim((string) Config::get('site_url', ''), '/');
        $parts = parse_url($base);
        if ($base === '' || !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || (string) ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass'])
            || str_contains($base, "\r") || str_contains($base, "\n")) {
            return null;
        }
        return $base . self::to($path);
    }
}
