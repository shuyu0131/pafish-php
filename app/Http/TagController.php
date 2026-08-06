<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 标签页：该标签下的文章列表
 * 对齐 Node 版 tag/[slug]/page.tsx
 */
final class TagController
{
    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = rawurldecode((string) ($args['slug'] ?? ''));
        $tag = DB::fetchOne('SELECT id, name, slug FROM tags WHERE slug = ?', [$slug]);
        if (!$tag) {
            return Listings::notFound($response, '标签不存在');
        }

        [$posts, $total, $perPage, $pageNum] = Listings::published(
            'EXISTS (SELECT 1 FROM post_tags pt WHERE pt.post_id = p.id AND pt.tag_id = ?)',
            [(int) $tag['id']],
            $request
        );
        $totalPages = max(1, (int) ceil($total / $perPage));

        $metaDesc = "「{$tag['name']}」标签下的文章列表";

        $response->getBody()->write(\render('tag', [
            'title' => $tag['name'],
            'description' => $metaDesc,
            'og' => Listings::og($request, $tag['name'], $metaDesc),
            'tag' => $tag,
            'posts' => $posts,
            'total' => $total,
            'pageNum' => $pageNum,
            'totalPages' => $totalPages,
            'listBaseUrl' => \url_to('/tag/' . rawurlencode($slug)),
        ]));
        return $response;
    }
}
