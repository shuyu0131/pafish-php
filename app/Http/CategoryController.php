<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 分类页：包含其所有子分类的文章（递归后代），两级置顶排序
 * 对齐 Node 版 category/[slug]/page.tsx
 */
final class CategoryController
{
    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = rawurldecode((string) ($args['slug'] ?? ''));
        $category = DB::fetchOne('SELECT id, name, slug, description FROM categories WHERE slug = ?', [$slug]);
        if (!$category) {
            return Listings::notFound($response, '分类不存在');
        }

        // 递归收集后代分类 id（含自身；防环兜底）
        $ids = [(int) $category['id']];
        $frontier = $ids;
        while ($frontier !== [] && count($ids) < 500) {
            $ph = implode(',', array_fill(0, count($frontier), '?'));
            $kids = DB::fetchAll("SELECT id FROM categories WHERE parent_id IN ({$ph})", $frontier);
            $frontier = array_map(static fn (array $k): int => (int) $k['id'], $kids);
            $ids = array_merge($ids, $frontier);
        }
        $idPh = implode(',', array_fill(0, count($ids), '?'));

        [$posts, $total, $perPage, $pageNum] = Listings::published(
            "p.category_id IN ({$idPh})",
            $ids,
            $request,
            'p.is_pinned DESC, p.category_pinned DESC, p.published_at DESC'
        );
        $totalPages = max(1, (int) ceil($total / $perPage));

        $description = (string) ($category['description'] ?? '');
        $metaDesc = $description !== '' ? $description : "「{$category['name']}」分类下的文章列表";

        $response->getBody()->write(\render('category', [
            'title' => $category['name'],
            'description' => $metaDesc,
            'og' => Listings::og($request, $category['name'], $metaDesc),
            'category' => $category,
            'posts' => $posts,
            'total' => $total,
            'pageNum' => $pageNum,
            'totalPages' => $totalPages,
            'listBaseUrl' => \url_to('/category/' . rawurlencode($slug)),
        ]));
        return $response;
    }
}
