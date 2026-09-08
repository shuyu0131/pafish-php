<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * sitemap.xml（首页/归档/文章/分类/标签/页面）
 * sitemap.xml 输出；页面 URL 使用 /pages/{slug}
 */
final class SitemapController
{
    public function index(Request $request, Response $response): Response
    {
        $posts = DB::fetchAll(
            "SELECT slug, published_at FROM posts
             WHERE status = 'PUBLISHED' AND deleted_at IS NULL
               AND (published_at IS NULL OR published_at <= NOW())
             ORDER BY published_at DESC"
        );
        $categories = DB::fetchAll('SELECT slug FROM categories');
        $tags = DB::fetchAll('SELECT slug FROM tags');
        $pages = DB::fetchAll(
            "SELECT slug, updated_at FROM pages WHERE status = 'PUBLISHED'"
        );

        $urls = [
            ['/archives', null, 'daily', 0.8],
        ];
        foreach ($posts as $p) {
            $urls[] = [
                '/post/' . rawurlencode((string) $p['slug']),
                $p['published_at'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $p['published_at'])) : null,
                'weekly',
                0.8,
            ];
        }
        foreach ($categories as $c) {
            $urls[] = ['/category/' . rawurlencode((string) $c['slug']), null, 'weekly', 0.5];
        }
        foreach ($tags as $t) {
            $urls[] = ['/tag/' . rawurlencode((string) $t['slug']), null, 'weekly', 0.4];
        }
        foreach ($pages as $pg) {
            $urls[] = [
                '/pages/' . rawurlencode((string) $pg['slug']),
                $pg['updated_at'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $pg['updated_at'])) : null,
                'monthly',
                0.5,
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" .
            "  <url>\n    <loc>" . \absolute_url('/') . "</loc>\n    <changefreq>daily</changefreq>\n    <priority>1</priority>\n  </url>\n";
        foreach ($urls as [$path, $lastmod, $freq, $priority]) {
            $xml .= "  <url>\n" .
                "    <loc>" . \absolute_url($path) . "</loc>\n" .
                ($lastmod ? "    <lastmod>{$lastmod}</lastmod>\n" : '') .
                "    <changefreq>{$freq}</changefreq>\n" .
                "    <priority>{$priority}</priority>\n" .
                "  </url>\n";
        }
        $xml .= '</urlset>';

        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }
}
