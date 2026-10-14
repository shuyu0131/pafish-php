<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Pafish\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * RSS 2.0 订阅（/rss.xml；最近 20 篇已发布文章）
 * RSS 输出
 */
final class RssController
{
    private const FEED_LIMIT = 20;

    public function index(Request $request, Response $response): Response
    {
        $posts = DB::fetchAll(
            "SELECT title, slug, excerpt, password, published_at FROM posts
             WHERE status = 'PUBLISHED' AND deleted_at IS NULL
               AND (published_at IS NULL OR published_at <= NOW())
             ORDER BY published_at DESC
             LIMIT " . self::FEED_LIMIT
        );

        // 统一走 site_name() 助手：空值时回退与前台一致，避免 RSS 显示空白站名
        $siteName = site_name();
        $description = (string) Settings::get('site_description', '');
        $homeUrl = \absolute_url('/');

        $items = '';
        foreach ($posts as $p) {
            $url = \absolute_url('/post/' . rawurlencode((string) $p['slug']));
            $excerpt = ($p['password'] !== null && $p['password'] !== '')
                ? '本文章已加密，请输入密码后查看'
                : (string) ($p['excerpt'] ?? '');
            $items .= "<item>\n" .
                "    <title><![CDATA[" . $p['title'] . "]]></title>\n" .
                "    <link>" . $url . "</link>\n" .
                "    <guid>" . $url . "</guid>\n" .
                "    <pubDate>" . ($p['published_at'] ? date('r', strtotime((string) $p['published_at'])) : '') . "</pubDate>\n" .
                "    <description><![CDATA[" . $excerpt . "]]></description>\n" .
                "  </item>";
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<rss version="2.0">' . "\n" .
            '  <channel>' . "\n" .
            "    <title><![CDATA[{$siteName}]]></title>\n" .
            "    <link>{$homeUrl}</link>\n" .
            "    <description><![CDATA[{$description}]]></description>\n" .
            "    <language>zh-CN</language>\n" .
            "    <lastBuildDate>" . gmdate('D, d M Y H:i:s') . " GMT</lastBuildDate>\n" .
            $items . "\n" .
            '  </channel>' . "\n" .
            '</rss>';

        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/rss+xml; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
