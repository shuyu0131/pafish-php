<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 文章归档页：按 YYYY年MM月 分组
 * 对齐 Node 版 archives/page.tsx
 */
final class ArchiveController
{
    public function index(Request $request, Response $response): Response
    {
        $posts = DB::fetchAll(
            "SELECT title, slug, published_at FROM posts
             WHERE status = 'PUBLISHED' AND deleted_at IS NULL
               AND (published_at IS NULL OR published_at <= NOW())
             ORDER BY published_at DESC"
        );

        // 按 年月 分组（Node 版 formatDate(publishedAt, "yyyy年MM月")）
        $groups = [];
        foreach ($posts as $p) {
            if (empty($p['published_at'])) {
                continue;
            }
            $month = \format_date($p['published_at'], 'Y年m月');
            $last = $groups === [] ? null : $groups[array_key_last($groups)];
            if ($last !== null && $last['month'] === $month) {
                $groups[array_key_last($groups)]['posts'][] = $p;
            } else {
                $groups[] = ['month' => $month, 'posts' => [$p]];
            }
        }

        $response->getBody()->write(\render('archives', [
            'title' => '文章归档',
            'description' => '按月份归档浏览全部文章',
            'og' => Listings::og($request, '文章归档', '按月份归档浏览全部文章'),
            'groups' => $groups,
            'total' => count($posts),
        ]));
        return $response;
    }
}
