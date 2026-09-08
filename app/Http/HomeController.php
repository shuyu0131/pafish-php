<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Pafish\Core\Auth;
use Pafish\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 前台首页：设置了首页页面则渲染该页面，否则文章列表（分页 + 友链）
 */
final class HomeController
{
    public function index(Request $request, Response $response): Response
    {
        // 后台"页面管理 → 设为首页"优先
        $homePageId = (string) Settings::get('home_page_id', '');
        if (ctype_digit($homePageId)) {
            $page = DB::fetchOne(
                "SELECT * FROM pages WHERE id = ? AND status = 'PUBLISHED'",
                [(int) $homePageId]
            );
            if ($page) {
                $response->getBody()->write(render('page', [
                    'title' => $page['title'],
                    'description' => '',
                    'page' => $page,
                ]));
                return $response;
            }
        }

        // 文章列表
        $perPage = min(50, max(1, (int) Settings::get('posts_per_page', '10')));
        $pageNum = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $offset = ($pageNum - 1) * $perPage;

        $where = "p.status = 'PUBLISHED' AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= NOW())";
        $where .= " AND (COALESCE(p.custom_fields, '') NOT LIKE ? OR p.author_id = ?)";
        $visibilityParams = ['%"key":"lumina_private","value":"y"%', Auth::id() ?? 0];
        $total = (int) DB::value("SELECT COUNT(*) FROM posts p WHERE {$where}", $visibilityParams);
        $totalPages = max(1, (int) ceil($total / $perPage));

        $posts = DB::fetchAll(
            "SELECT p.id, p.title, p.slug, p.excerpt, p.cover_url, p.custom_fields, p.published_at,
                    p.is_pinned, p.category_pinned, p.password, p.external_url, p.view_count,
                    u.username AS author_name,
                    c.name AS category_name, c.slug AS category_slug
             FROM posts p
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE {$where}
             ORDER BY p.is_pinned DESC, p.published_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $visibilityParams
        );

        // 批量取标签（避免 N+1）
        $posts = self::attachTags($posts);

        $response->getBody()->write(render('index', [
            'title' => '首页',
            'description' => (string) Settings::get('site_description', ''),
            'posts' => $posts,
            'pageNum' => $pageNum,
            'totalPages' => $totalPages,
            'listBaseUrl' => url_to('/'),
            'emptyText' => '还没有文章，敬请期待',
        ]));
        return $response;
    }

    /** 为文章数组批量附加 tags 列表（[['name'=>..,'slug'=>..], ...]） */
    public static function attachTags(array $posts): array
    {
        if ($posts === []) {
            return $posts;
        }
        $ids = array_map(static fn (array $p): int => (int) $p['id'], $posts);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::fetchAll(
            "SELECT pt.post_id, t.name, t.slug
             FROM post_tags pt JOIN tags t ON t.id = pt.tag_id
             WHERE pt.post_id IN ({$placeholders})",
            $ids
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['post_id']][] = ['name' => $row['name'], 'slug' => $row['slug']];
        }
        foreach ($posts as &$post) {
            $post['tags'] = $map[(int) $post['id']] ?? [];
        }
        unset($post);
        return $posts;
    }
}
