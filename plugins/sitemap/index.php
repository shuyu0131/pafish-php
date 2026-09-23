<?php

declare(strict_types=1);

use Pafish\Core\DB;
use Pafish\Core\Url;

/** XML 字段转义。 */
function pafish_sitemap_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** 构造单条 sitemap URL 记录。 */
function pafish_sitemap_entry(string $path, ?string $lastmod, string $frequency, string $priority): string
{
    $xml = "  <url>\n";
    $xml .= '    <loc>' . pafish_sitemap_escape(Url::absolute($path)) . "</loc>\n";
    if ($lastmod !== null && $lastmod !== '') {
        $time = strtotime($lastmod);
        if ($time !== false) {
            $xml .= '    <lastmod>' . gmdate('Y-m-d\\TH:i:s\\Z', $time) . "</lastmod>\n";
        }
    }
    $xml .= "    <changefreq>{$frequency}</changefreq>\n";
    $xml .= "    <priority>{$priority}</priority>\n";
    return $xml . "  </url>\n";
}

return [
    /**
     * SitemapController 会优先调用已启用插件的 renderSitemap。
     * 仅收录可匿名访问的已发布内容，并最多生成 50,000 条记录。
     */
    'renderSitemap' => function (object $ctx): string {
        $settings = $ctx->getSettings();
        $enabled = static fn (string $key): bool => ($settings[$key] ?? '1') === '1';
        $frequency = (string) ($settings['update_frequency'] ?? 'weekly');
        if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            $frequency = 'weekly';
        }
        $tagMinPosts = (int) ($settings['tag_min_posts'] ?? '2');
        if (!in_array($tagMinPosts, [1, 2, 3, 5, 10], true)) {
            $tagMinPosts = 2;
        }

        $entries = [];
        $latestModified = null;
        $add = static function (string $path, ?string $modified, string $priority) use (&$entries, &$latestModified, $frequency): void {
            if (count($entries) >= 49999) {
                return;
            }
            $entries[] = pafish_sitemap_entry($path, $modified, $frequency, $priority);
            if ($modified !== null && ($latestModified === null || strtotime($modified) > strtotime($latestModified))) {
                $latestModified = $modified;
            }
        };

        if ($enabled('include_posts')) {
            $posts = DB::fetchAll(
                "SELECT slug, updated_at FROM posts
                 WHERE status = 'PUBLISHED' AND deleted_at IS NULL
                   AND (published_at IS NULL OR published_at <= NOW())
                   AND (password IS NULL OR password = '')
                 ORDER BY published_at DESC LIMIT 49999"
            );
            foreach ($posts as $post) {
                $add('/post/' . rawurlencode((string) $post['slug']), (string) ($post['updated_at'] ?? ''), '0.8');
            }
        }

        if ($enabled('include_pages')) {
            $pages = DB::fetchAll(
                "SELECT slug, updated_at FROM pages
                 WHERE status = 'PUBLISHED' AND (published_at IS NULL OR published_at <= NOW())
                 ORDER BY published_at DESC"
            );
            foreach ($pages as $page) {
                $add('/pages/' . rawurlencode((string) $page['slug']), (string) ($page['updated_at'] ?? ''), '0.5');
            }
        }

        if ($enabled('include_categories')) {
            $categories = DB::fetchAll(
                "SELECT c.slug FROM categories c
                 WHERE EXISTS (
                    SELECT 1 FROM posts p
                    WHERE p.category_id = c.id AND p.status = 'PUBLISHED' AND p.deleted_at IS NULL
                      AND (p.published_at IS NULL OR p.published_at <= NOW())
                      AND (p.password IS NULL OR p.password = '')
                 ) ORDER BY c.sort_order ASC, c.id ASC",
            );
            foreach ($categories as $category) {
                $add('/category/' . rawurlencode((string) $category['slug']), null, '0.6');
            }
        }

        if ($enabled('include_tags')) {
            $tags = DB::fetchAll(
                "SELECT t.slug FROM tags t
                 INNER JOIN post_tags pt ON pt.tag_id = t.id
                 INNER JOIN posts p ON p.id = pt.post_id
                 WHERE p.status = 'PUBLISHED' AND p.deleted_at IS NULL
                   AND (p.published_at IS NULL OR p.published_at <= NOW())
                   AND (p.password IS NULL OR p.password = '')
                 GROUP BY t.id, t.slug
                 HAVING COUNT(p.id) >= ?
                 ORDER BY t.name ASC",
                [$tagMinPosts]
            );
            foreach ($tags as $tag) {
                $add('/tag/' . rawurlencode((string) $tag['slug']), null, '0.4');
            }
        }

        $homeModified = $latestModified !== null ? pafish_sitemap_entry('/', $latestModified, 'daily', '1.0') : pafish_sitemap_entry('/', null, 'daily', '1.0');
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            . $homeModified . implode('', $entries) . "</urlset>\n";
    },
];
