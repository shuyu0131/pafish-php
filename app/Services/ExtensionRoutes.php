<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Auth;
use Pafish\Core\DB;

/** 扩展前台路由的声明校验、冲突检测与匹配。 */
final class ExtensionRoutes
{
    private const PATH_MAX_LENGTH = 160;
    private const HANDLER_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';
    private const PARAM_PATTERN = '/^\{[a-z][a-z0-9_]{0,30}\}$/';
    private const SEGMENT_PATTERN = '/^[a-z0-9_-]{1,50}$/';
    private const RESERVED_FIRST_SEGMENTS = [
        'admin', 'api', 'archives', 'category', 'css', 'js', 'login', 'pages', 'plugin',
        'post', 'profile', 'register', 'reset-password', 'forgot-password', 'search', 'tag', 'micro',
        'theme-assets', 'themes', 'uploads', 'rss.xml', 'sitemap.xml', 'robots.txt',
    ];

    /** 校验 manifest 中 routes 的结构。 */
    public static function validateDeclaration(mixed $routes, string $kind, string $name): ?string
    {
        if ($routes === null) {
            return null;
        }
        if (!is_array($routes)) {
            return 'routes 必须是数组';
        }
        $seen = [];
        foreach ($routes as $route) {
            $normalized = self::normalizeRoute($route, $kind, $name);
            if ($normalized === null) {
                return 'routes 包含无效路由声明';
            }
            foreach ($seen as $existing) {
                if ($normalized['method'] === $existing['method']
                    && self::pathsOverlap($normalized['fullPath'], $existing['fullPath'])) {
                    return 'routes 包含重复或冲突的路由声明';
                }
            }
            $key = $normalized['method'] . ' ' . self::shape($normalized['fullPath']);
            if (isset($seen[$key])) {
                return 'routes 包含重复或冲突的路由声明';
            }
            $seen[$key] = $normalized;
        }
        return null;
    }

    /** 从 manifest 返回已规范化的路由列表。无效项不会进入运行时。 */
    public static function fromManifest(array $manifest, string $kind, string $name): array
    {
        $routes = [];
        foreach ((array) ($manifest['routes'] ?? []) as $route) {
            $normalized = self::normalizeRoute($route, $kind, $name);
            if ($normalized !== null) {
                $routes[] = $normalized;
            }
        }
        return $routes;
    }

    /** 启用主题/插件前检查自然路径是否与核心或其他已启用扩展冲突。 */
    public static function assertCanActivate(string $kind, string $name, array $manifest): void
    {
        $candidate = array_values(array_filter(
            self::fromManifest($manifest, $kind, $name),
            static fn (array $route): bool => $route['public']
        ));
        foreach ($candidate as $route) {
            self::assertNotReserved($route['fullPath']);
            self::assertNotFlatPostConflict($route['fullPath']);
        }

        foreach (self::activePublicRoutes($kind, $name) as $existing) {
            foreach ($candidate as $route) {
                if ($route['method'] !== $existing['method']) {
                    continue;
                }
                if (self::pathsOverlap($route['fullPath'], $existing['fullPath'])) {
                    throw new \RuntimeException('路由与已启用的 ' . ($existing['kind'] === 'theme' ? '主题' : '插件') . '“' . $existing['name'] . '”冲突：' . $route['fullPath']);
                }
            }
        }
    }

    /** 当前请求匹配已启用主题和插件的声明式路由。 */
    public static function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path = self::normalizeRequestPath($path);
        if ($path === null) {
            return null;
        }

        // 后台插件路由按激活状态匹配；最终权限仍由 ExtensionController 统一检查。
        if (str_starts_with($path, '/admin/plugin/')) {
            foreach (Plugin::activeNames() as $name) {
                $desc = Plugin::describe($name);
                if ($desc['manifest'] === null) {
                    continue;
                }
                foreach (self::fromManifest($desc['manifest'], 'plugin', $name) as $route) {
                    if (!$route['admin'] || $route['method'] !== $method) {
                        continue;
                    }
                    if (($params = self::matchPath($route['fullPath'], $path)) !== null) {
                        return ['kind' => 'plugin', 'name' => $name, 'route' => $route, 'params' => $params];
                    }
                }
            }
            return null;
        }

        // 自然路径使用与 Slim 注册完全相同的冲突过滤结果，避免注册顺序与分发结果不一致。
        if (!str_starts_with($path, '/plugin/')) {
            foreach (self::publicRoutes() as $entry) {
                if ($entry['method'] === $method && ($params = self::matchPath($entry['fullPath'], $path)) !== null) {
                    return ['kind' => $entry['kind'], 'name' => $entry['name'], 'route' => $entry, 'params' => $params];
                }
            }
            return null;
        }

        foreach (Plugin::activeNames() as $name) {
            $desc = Plugin::describe($name);
            if ($desc['manifest'] === null) {
                continue;
            }
            foreach (self::fromManifest($desc['manifest'], 'plugin', $name) as $route) {
                if ($route['method'] === $method && ($params = self::matchPath($route['fullPath'], $path)) !== null) {
                    return ['kind' => 'plugin', 'name' => $name, 'route' => $route, 'params' => $params];
                }
            }
        }
        return null;
    }

    /** 返回本请求需要注册到 Slim 的自然路径，核心路由优先级由 routes.php 控制。 */
    public static function publicRoutes(): array
    {
        $routes = [];
        foreach (self::activePublicRoutes('', '') as $route) {
            try {
                self::assertNotReserved($route['fullPath']);
                self::assertNotFlatPostConflict($route['fullPath']);
            } catch (\Throwable $e) {
                error_log('[pafish-extension] 忽略非法已启用自然路由 ' . $route['kind'] . ':' . $route['name'] . ' ' . $route['fullPath'] . '：' . $e->getMessage());
                continue;
            }
            $conflict = false;
            foreach ($routes as $existing) {
                if ($existing['method'] === $route['method'] && self::pathsOverlap($existing['fullPath'], $route['fullPath'])) {
                    error_log('[pafish-extension] 忽略冲突自然路由 ' . $route['kind'] . ':' . $route['name'] . ' ' . $route['fullPath']);
                    $conflict = true;
                    break;
                }
            }
            if (!$conflict) {
                $routes[] = $route;
            }
        }
        return $routes;
    }

    /** 由控制器调用的匿名 POST 固定窗口限流。 */
    public static function allowAnonymousPost(string $kind, string $name, string $path): bool
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $bucket = hash('sha256', $kind . ':' . $name . ':' . $path . ':' . $ip);
        $dir = dirname(__DIR__, 2) . '/runtime/extension-rate-limit';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            // 无法持久化限流状态时拒绝匿名写操作，避免静默失去保护。
            return false;
        }
        $file = $dir . '/' . $bucket . '.json';
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return false;
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return false;
            }
            $raw = stream_get_contents($fp);
            $now = time();
            $timestamps = json_decode($raw ?: '[]', true);
            $timestamps = is_array($timestamps) ? array_values(array_filter($timestamps, static fn ($time): bool => is_int($time) && $time > $now - 60)) : [];
            if (count($timestamps) >= 10) {
                return false;
            }
            $timestamps[] = $now;
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, json_encode($timestamps));
            fflush($fp);
            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private static function normalizeRoute(mixed $route, string $kind, string $name): ?array
    {
        if (!is_array($route)) {
            return null;
        }
        // 已规范化的条目直接返回：Plugin::normalize() 会把规范化结果写回 manifest，
        // 而 match()/publicRoutes() 又会拿这份 manifest 再调一次本方法。
        // 若二次规范化，非 admin 路由上的 capability => null 会被判为非法而整条丢弃。
        if (isset($route['fullPath'], $route['csrf']) && array_key_exists('capability', $route)) {
            return $route;
        }
        $method = strtoupper((string) ($route['method'] ?? ''));
        $path = self::normalizeDeclaredPath($route['path'] ?? null);
        $handler = (string) ($route['handler'] ?? '');
        $auth = (string) ($route['auth'] ?? 'guest');
        $response = (string) ($route['response'] ?? ($method === 'POST' ? 'json' : 'html'));
        $admin = $kind === 'plugin' && (($route['admin'] ?? false) === true);
        $public = $kind === 'theme' || (($route['public'] ?? false) === true);
        $capability = $route['capability'] ?? null;

        if (!in_array($kind, ['theme', 'plugin'], true)
            || !in_array($method, ['GET', 'POST'], true)
            || $path === null
            || preg_match(self::HANDLER_PATTERN, $handler) !== 1
            || !in_array($auth, ['guest', 'login', 'editor', 'admin'], true)
            || !in_array($response, ['html', 'json', 'redirect'], true)
            || ($kind === 'theme' && (($route['public'] ?? true) !== true))
            || (array_key_exists('admin', $route) && !is_bool($route['admin']))
            || (($route['admin'] ?? false) === true && $kind !== 'plugin')
            || ($admin && (($route['public'] ?? false) === true
                || $auth === 'guest'
                || !is_string($capability)
                || !in_array($capability, Auth::CAPABILITIES, true)))
            || (!$admin && array_key_exists('capability', $route))) {
            return null;
        }
        $fullPath = $admin
            ? '/admin/plugin/' . $name . ($path === '/' ? '' : $path)
            : ($public ? $path : '/plugin/' . $name . ($path === '/' ? '' : $path));
        if ($public && !self::hasStaticFirstSegment($fullPath)) {
            return null;
        }
        return [
            'method' => $method,
            'path' => $path,
            'fullPath' => $fullPath,
            'handler' => $handler,
            'auth' => $auth,
            'csrf' => $method === 'POST',
            'response' => $response,
            'public' => $public,
            'admin' => $admin,
            'capability' => $admin ? $capability : null,
        ];
    }

    private static function normalizeDeclaredPath(mixed $path): ?string
    {
        if (!is_string($path) || $path === '' || strlen($path) > self::PATH_MAX_LENGTH || !str_starts_with($path, '/')) {
            return null;
        }
        if ($path !== '/' && str_ends_with($path, '/')) {
            return null;
        }
        $segments = $path === '/' ? [] : explode('/', substr($path, 1));
        if (count($segments) > 8 || count($segments) === 0) {
            return $path === '/' ? '/' : null;
        }
        $params = [];
        foreach ($segments as $segment) {
            if ($segment === '' || (preg_match(self::SEGMENT_PATTERN, $segment) !== 1 && preg_match(self::PARAM_PATTERN, $segment) !== 1)) {
                return null;
            }
            if (preg_match(self::PARAM_PATTERN, $segment) === 1) {
                $param = substr($segment, 1, -1);
                if (isset($params[$param])) {
                    return null;
                }
                $params[$param] = true;
            }
        }
        return $path;
    }

    private static function activePublicRoutes(string $skipKind, string $skipName): array
    {
        $out = [];
        $themeName = Theme::active();
        if (!($skipKind === 'theme' && $skipName === $themeName)) {
            $desc = Theme::describe($themeName);
            if ($desc['manifest'] !== null) {
                foreach (self::fromManifest($desc['manifest'], 'theme', $themeName) as $route) {
                    if ($route['public']) {
                        $out[] = ['kind' => 'theme', 'name' => $themeName] + $route;
                    }
                }
            }
        }
        foreach (Plugin::activeNames() as $pluginName) {
            if ($skipKind === 'plugin' && $skipName === $pluginName) {
                continue;
            }
            $desc = Plugin::describe($pluginName);
            if ($desc['manifest'] === null) {
                continue;
            }
            foreach (self::fromManifest($desc['manifest'], 'plugin', $pluginName) as $route) {
                if ($route['public']) {
                    $out[] = ['kind' => 'plugin', 'name' => $pluginName] + $route;
                }
            }
        }
        return $out;
    }

    private static function assertNotReserved(string $path): void
    {
        $first = explode('/', ltrim($path, '/'))[0] ?? '';
        if (in_array($first, self::RESERVED_FIRST_SEGMENTS, true)) {
            throw new \RuntimeException('路由占用了核心保留路径：' . $path);
        }
    }

    private static function assertNotFlatPostConflict(string $path): void
    {
        if ((string) Settings::get('permalink_structure', '/post/%postname%') !== '/%postname%') {
            return;
        }
        $segments = explode('/', ltrim($path, '/'));
        if (count($segments) !== 1 || preg_match(self::SEGMENT_PATTERN, $segments[0]) !== 1) {
            return;
        }
        if (DB::fetchOne("SELECT id FROM posts WHERE slug = ? AND status = 'PUBLISHED' AND deleted_at IS NULL LIMIT 1", [$segments[0]]) !== null) {
            throw new \RuntimeException('路由与已发布文章 slug 冲突：' . $path);
        }
    }

    private static function hasStaticFirstSegment(string $path): bool
    {
        $first = explode('/', ltrim($path, '/'))[0] ?? '';
        return preg_match(self::SEGMENT_PATTERN, $first) === 1;
    }

    private static function normalizeRequestPath(string $path): ?string
    {
        $path = '/' . trim($path, '/');
        if (strlen($path) > self::PATH_MAX_LENGTH || str_contains($path, "\0") || str_contains($path, '\\')) {
            return null;
        }
        return $path;
    }

    private static function matchPath(string $pattern, string $path): ?array
    {
        $patternParts = $pattern === '/' ? [] : explode('/', ltrim($pattern, '/'));
        $pathParts = $path === '/' ? [] : explode('/', ltrim($path, '/'));
        if (count($patternParts) !== count($pathParts)) {
            return null;
        }
        $params = [];
        foreach ($patternParts as $i => $part) {
            $actual = rawurldecode($pathParts[$i]);
            if ($actual === '' || str_contains($actual, '/') || str_contains($actual, '\\') || str_contains($actual, "\0")) {
                return null;
            }
            if (preg_match(self::PARAM_PATTERN, $part) === 1) {
                $params[substr($part, 1, -1)] = $actual;
            } elseif ($part !== $actual) {
                return null;
            }
        }
        return $params;
    }

    private static function shape(string $path): string
    {
        return preg_replace('/\{[a-z][a-z0-9_]*\}/', '{}', $path) ?? $path;
    }

    private static function pathsOverlap(string $left, string $right): bool
    {
        $a = explode('/', ltrim($left, '/'));
        $b = explode('/', ltrim($right, '/'));
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $part) {
            $aVariable = preg_match(self::PARAM_PATTERN, $part) === 1;
            $bVariable = preg_match(self::PARAM_PATTERN, $b[$i]) === 1;
            if (!$aVariable && !$bVariable && $part !== $b[$i]) {
                return false;
            }
        }
        return true;
    }
}
