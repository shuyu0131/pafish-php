<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Pafish\Services\Permalinks;

/**
 * 分类页：包含其所有子分类的文章（递归后代），两级置顶排序
 * 分类页面
 */
final class CategoryController
{
    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = rawurldecode((string) ($args['slug'] ?? ''));
        $parsed = Permalinks::entityFromPath('category', Permalinks::requestPath($request));
        if ($parsed !== null) $slug = (string) $parsed['slug'];
        $category = DB::fetchOne('SELECT id,name,slug,description,seo_title,meta_description,cover_url,parent_id,sort_order FROM categories WHERE slug = ?', [$slug]);
        if (!$category) {
            return Listings::notFound($response, '分类不存在');
        }
        if ($parsed !== null) {
            $redirect = Permalinks::canonicalRedirect($request, $response, Permalinks::path('category', $category));
            if ($redirect !== null) return $redirect;
        }

        ['posts' => $posts, 'total' => $total, 'pageNum' => $pageNum, 'totalPages' => $totalPages] = \Pafish\Services\Posts::query([
            'context' => 'category', 'category_ids' => [(int) $category['id']], 'include_child_categories' => true,
            'page' => Listings::pageNumber($request), 'recent_comments' => true,
        ]);

        $category['cover_url'] = \Pafish\Services\Content::safeImageUrl($category['cover_url'] ?? null);
        $description = (string) ($category['description'] ?? '');
        $metaDesc = $category['meta_description'] ?: ($description !== '' ? $description : "「{$category['name']}」分类下的文章列表");
        $seoTitle = $category['seo_title'] ?: $category['name'];
        $og = Listings::og($request, $seoTitle, $metaDesc);
        if (!empty($category['cover_url'])) $og['image'] = \absolute_url($category['cover_url']);

        $response->getBody()->write(\render('category', [
            'title' => $category['name'],
            'seoTitle' => $seoTitle,
            'description' => $metaDesc,
            'og' => $og,
            'category' => $category,
            'listKind' => 'category',
            'posts' => $posts,
            'total' => $total,
            'pageNum' => $pageNum,
            'totalPages' => $totalPages,
            'listBaseUrl' => \url_to(Permalinks::path('category', $category)),
        ]));
        return $response;
    }
}
