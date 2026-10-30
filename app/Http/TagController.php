<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Pafish\Services\Permalinks;

/**
 * 标签页：该标签下的文章列表
 * 标签页面
 */
final class TagController
{
    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = rawurldecode((string) ($args['slug'] ?? ''));
        $parsed = Permalinks::entityFromPath('tag', Permalinks::requestPath($request));
        if ($parsed !== null) $slug = (string) $parsed['slug'];
        $tag = DB::fetchOne('SELECT id,name,slug,description,seo_title,meta_description FROM tags WHERE slug = ?', [$slug]);
        if (!$tag) {
            return Listings::notFound($response, '标签不存在');
        }
        if ($parsed !== null) {
            $redirect = Permalinks::canonicalRedirect($request, $response, Permalinks::path('tag', $tag));
            if ($redirect !== null) return $redirect;
        }

        [$posts, $total, $perPage, $pageNum] = Listings::published(
            'EXISTS (SELECT 1 FROM post_tags pt WHERE pt.post_id = p.id AND pt.tag_id = ?)',
            [(int) $tag['id']],
            $request
        );
        $totalPages = max(1, (int) ceil($total / $perPage));

        $metaDesc = $tag['meta_description'] ?: ($tag['description'] ?: "「{$tag['name']}」标签下的文章列表");
        $seoTitle = $tag['seo_title'] ?: $tag['name'];

        $response->getBody()->write(\render('tag', [
            'title' => $tag['name'],
            'seoTitle' => $seoTitle,
            'description' => $metaDesc,
            'og' => Listings::og($request, $seoTitle, $metaDesc),
            'tag' => $tag,
            'posts' => $posts,
            'total' => $total,
            'pageNum' => $pageNum,
            'totalPages' => $totalPages,
            'listBaseUrl' => \url_to(Permalinks::path('tag', $tag)),
        ]));
        return $response;
    }
}
