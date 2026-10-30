<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Url;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Central permalink templates shared by URL generation, settings validation and routing. */
final class Permalinks
{
    public const DEFAULT_POST = '/post/%postname%';
    private const POST_TEMPLATES = ['/post/%postname%', '/%postname%', '/%year%/%month%/%postname%'];
    private const CONTENT_TEMPLATES = [
        'page' => ['setting' => 'page_permalink_structure', 'default' => '/pages/%pagename%', 'templates' => ['/pages/%pagename%', '/page/%pagename%']],
        'category' => ['setting' => 'category_permalink_structure', 'default' => '/category/%category%', 'templates' => ['/category/%category%', '/category/%category_path%', '/categories/%category%']],
        'tag' => ['setting' => 'tag_permalink_structure', 'default' => '/tag/%tag%', 'templates' => ['/tag/%tag%', '/tags/%tag%']],
    ];

    public static function postTemplate(?string $value = null): string
    {
        $value ??= (string) Settings::get('permalink_structure', self::DEFAULT_POST);
        return in_array($value, self::POST_TEMPLATES, true) ? $value : self::DEFAULT_POST;
    }

    public static function validatePostTemplate(string $value): bool
    {
        return in_array($value, self::POST_TEMPLATES, true);
    }

    public static function postPath(array $post, ?string $template = null): string
    {
        $date = strtotime((string) ($post['published_at'] ?? $post['created_at'] ?? ''));
        // Missing dates must not make a URL change every time it is rendered.
        if ($date === false) $date = 0;
        return str_replace(
            ['%postname%', '%year%', '%month%'],
            [rawurlencode((string) ($post['slug'] ?? '')), date('Y', $date), date('m', $date)],
            self::postTemplate($template)
        );
    }

    public static function postPaths(array $post): array
    {
        $paths = [];
        foreach (self::POST_TEMPLATES as $template) $paths[] = self::postPath($post, $template);
        return array_values(array_unique($paths));
    }

    /** @return array<string,string> template => label */
    public static function options(string $type): array
    {
        if ($type === 'post') {
            return [
                '/post/%postname%' => '/post/文章别名（兼容默认）',
                '/%postname%' => '/文章别名',
                '/%year%/%month%/%postname%' => '/年份/月/文章别名',
            ];
        }
        $config = self::CONTENT_TEMPLATES[$type] ?? null;
        if ($config === null) return [];
        $prefix = ['page' => '页面', 'category' => '分类', 'tag' => '标签'][$type];
        return array_combine($config['templates'], array_map(
            static fn (string $template): string => str_replace(
                ['%pagename%', '%category%', '%category_path%', '%tag%'],
                [$prefix . '别名', $prefix . '别名', $prefix . '别名', $prefix . '路径'],
                $template
            ),
            $config['templates']
        )) ?: [];
    }

    public static function template(string $type): string
    {
        if ($type === 'post') return self::postTemplate();
        $config = self::CONTENT_TEMPLATES[$type] ?? null;
        if ($config === null) throw new \InvalidArgumentException('Unknown permalink content type');
        $value = (string) Settings::get($config['setting'], $config['default']);
        return in_array($value, $config['templates'], true) ? $value : $config['default'];
    }

    public static function validate(string $type, string $template): bool
    {
        return $type === 'post'
            ? self::validatePostTemplate($template)
            : in_array($template, self::CONTENT_TEMPLATES[$type]['templates'] ?? [], true);
    }

    public static function settingKey(string $type): ?string
    {
        if ($type === 'post') return 'permalink_structure';
        return self::CONTENT_TEMPLATES[$type]['setting'] ?? null;
    }

    public static function path(string $type, array $entity, ?string $template = null): string
    {
        $template ??= self::template($type);
        if ($type === 'post') return self::postPath($entity, $template);
        $slug = rawurlencode((string) ($entity['slug'] ?? ''));
        if ($type === 'category' && $template === '/category/%category_path%') {
            $id = (int) ($entity['id'] ?? 0);
            if ($id <= 0 && $slug !== '') $id = (int) (\Pafish\Core\DB::value('SELECT id FROM categories WHERE slug = ? LIMIT 1', [$entity['slug']]) ?? 0);
            return '/category/' . self::categoryPath($id);
        }
        $variable = match ($type) {
            'page' => '%pagename%',
            'category' => '%category%',
            'tag' => '%tag%',
            default => throw new \InvalidArgumentException('Unknown permalink content type'),
        };
        return str_replace($variable, $slug, $template);
    }

    /** Every supported previous shape is retained as a redirect source after a slug change. */
    public static function paths(string $type, array $entity): array
    {
        if ($type === 'post') return self::postPaths($entity);
        $config = self::CONTENT_TEMPLATES[$type] ?? null;
        if ($config === null) throw new \InvalidArgumentException('Unknown permalink content type');
        $slug = rawurlencode((string) ($entity['slug'] ?? ''));
        if ($type === 'category' && $config['templates'] !== [] && in_array('/category/%category_path%', $config['templates'], true)) {
            $templates = ['/category/%category%', '/category/%category_path%', '/categories/%category%'];
            return array_values(array_unique(array_map(static function (string $template) use ($entity, $slug): string {
                return str_contains($template, '%category_path%')
                    ? '/category/' . self::categoryPath((int) ($entity['id'] ?? 0))
                    : str_replace('%category%', $slug, $template);
            }, $templates)));
        }
        $variable = match ($type) {
            'page' => '%pagename%', 'category' => '%category%', 'tag' => '%tag%',
        };
        return array_values(array_unique(array_map(
            static fn (string $template): string => str_replace($variable, $slug, $template),
            $config['templates']
        )));
    }

    public static function categoryPath(int $id): string
    {
        if ($id <= 0) return '';
        $segments = [];
        $seen = [];
        $category = \Pafish\Core\DB::fetchOne('SELECT id, slug, parent_id FROM categories WHERE id = ?', [$id]);
        while ($category !== null) {
            $currentId = (int) $category['id'];
            if (isset($seen[$currentId])) break;
            $seen[$currentId] = true;
            array_unshift($segments, rawurlencode((string) $category['slug']));
            $parent = (int) ($category['parent_id'] ?? 0);
            $category = $parent > 0
                ? \Pafish\Core\DB::fetchOne('SELECT id, slug, parent_id FROM categories WHERE id = ?', [$parent])
                : null;
        }
        return implode('/', $segments);
    }

    public static function categorySlugFromPath(string $path): ?string
    {
        $path = '/' . trim($path, '/');
        if (preg_match('#^/category/(.+)$#u', $path, $m)) {
            $segments = array_map('rawurldecode', explode('/', $m[1]));
            if (in_array('', $segments, true)) return null;
            $leaf = \Pafish\Core\DB::fetchOne('SELECT id,slug FROM categories WHERE slug = ? LIMIT 1', [end($segments)]);
            if ($leaf === null) return null;

            // A single-segment category URL is a supported legacy shape. Multi-level
            // URLs must match the category's actual ancestry; changed ancestry is
            // handled by the saved 301 alias before this controller runs.
            if (count($segments) > 1) {
                $current = array_map('rawurldecode', explode('/', self::categoryPath((int) $leaf['id'])));
                if ($segments !== $current) return null;
            }
            return (string) $leaf['slug'];
        }
        if (preg_match('#^/(?:category|categories)/([^/]+)$#u', $path, $m)) return rawurldecode($m[1]);
        return null;
    }

    public static function entityFromPath(string $type, string $path): ?array
    {
        if ($type === 'post') return self::postFromPath($path);
        if ($type === 'category') {
            $slug = self::categorySlugFromPath($path);
            return $slug === null ? null : ['slug' => $slug];
        }
        $template = self::template($type);
        $variable = match ($type) { 'page' => 'pagename', 'category' => 'category', 'tag' => 'tag' };
        $marker = '%' . $variable . '%';
        $prefix = preg_quote(substr($template, 0, strpos($template, $marker)), '#');
        $suffix = preg_quote(substr($template, strpos($template, $marker) + strlen($marker)), '#');
        if (preg_match('#^' . $prefix . '([^/]+)' . $suffix . '$#u', '/' . ltrim($path, '/'), $m)) {
            return ['slug' => rawurldecode($m[1])];
        }
        // Current and historical standard prefixes remain readable after settings change.
        $fallback = match ($type) {
            'page' => '#^/(?:pages|page)/([^/]+)$#u',
            'category' => '#^/(?:category|categories)/([^/]+)$#u',
            'tag' => '#^/(?:tag|tags)/([^/]+)$#u',
        };
        return preg_match($fallback, '/' . ltrim($path, '/'), $m) ? ['slug' => rawurldecode($m[1])] : null;
    }

    public static function postFromPath(string $path): ?array
    {
        $path = '/' . trim(rawurldecode($path), '/');
        // Root-level post URLs remain routable after switching back to a prefixed
        // template so the controller can issue the canonical redirect.
        if (preg_match('#^/([^/]+)$#u', $path, $m)) {
            return ['slug' => $m[1], 'legacy' => false];
        }
        if (preg_match('#^/(\d{4})/(\d{2})/([^/]+)$#u', $path, $m)) {
            return ['slug' => $m[3], 'year' => (int) $m[1], 'month' => (int) $m[2], 'legacy' => false];
        }
        if (preg_match('#^/post/([^/]+)$#u', $path, $m)) return ['slug' => $m[1], 'legacy' => true];
        return null;
    }

    public static function canonicalRedirect(Request $request, Response $response, string $canonicalPath): ?Response
    {
        if (!in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) return null;
        $current = parse_url($request->getUri()->getPath(), PHP_URL_PATH) ?: '/';
        $base = rtrim(Url::base(), '/');
        if ($base !== '' && ($current === $base || str_starts_with($current, $base . '/'))) {
            $current = substr($current, strlen($base)) ?: '/';
        }
        $current = PermalinkRedirects::normalize($current);
        $canonicalPath = PermalinkRedirects::normalize($canonicalPath);
        if ($current === $canonicalPath) return null;
        $location = Url::to($canonicalPath);
        $query = PermalinkRedirects::queryWithoutRouteParam($request->getUri()->getQuery());
        if ($query !== '') $location .= (str_contains($location, '?') ? '&' : '?') . $query;
        return $response->withHeader('Location', $location)->withStatus(301);
    }

    public static function requestPath(Request $request): string
    {
        $path = parse_url($request->getUri()->getPath(), PHP_URL_PATH) ?: '/';
        $base = rtrim(Url::base(), '/');
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        return $path;
    }
}
