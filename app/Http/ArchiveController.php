<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Pafish\Services\Posts;
use Pafish\Services\Theme;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 文章归档页：按 YYYY年MM月 分组
 * 归档页面
 */
final class ArchiveController
{
    public function index(Request $request, Response $response): Response
    {
        // 归档页是可选入口；导航与 Sitemap 使用同一可用性规则。
        if (!Theme::hasArchivePage()) {
            return Listings::notFound($response, '页面不存在');
        }

        $posts = DB::fetchAll(
            "SELECT p.title, p.slug, p.published_at, p.created_at FROM posts p
             WHERE " . Posts::publicWhere('p') . "
             ORDER BY p.published_at DESC"
        );

        // 按年月分组
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
            'og' => Listings::og($request, '文章归档', '按月份归档浏览全部文章', []),
            'groups' => $groups,
            'total' => count($posts),
        ]));
        return $response;
    }
}
