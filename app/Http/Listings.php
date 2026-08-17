<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Pafish\Core\Auth;
use Pafish\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 文章列表查询共享助手（分类/标签/搜索共用）
 * 对齐 Node 版：per_page 设置（1-50）、已发布过滤、两级置顶排序
 */
final class Listings
{
    /** 已发布过滤条件（不含 AND 前缀，SQL 片段） */
    private const PUBLISHED = "p.status = 'PUBLISHED' AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= NOW())";

    /**
     * 分页查询已发布文章（返回 [posts, total, perPage, pageNum]）
     * @param string $extraWhere 附加条件（含 "AND" 或不含均可，无则 ''）
     */
    public static function published(
        string $extraWhere,
        array $params,
        Request $request,
        string $order = 'p.is_pinned DESC, p.published_at DESC'
    ): array {
        $perPage = min(50, max(1, (int) Settings::get('posts_per_page', '10')));
        $pageNum = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $offset = ($pageNum - 1) * $perPage;

        // Lumina 的“仅自己可看”字段保存在规范化 JSON 中。列表层过滤，避免私密内容进入
        // 搜索、分类和标签页；详情页还会再校验一次，防止直链绕过。
        $privateMarker = '%"key":"lumina_private","value":"y"%';
        $visibility = "(COALESCE(p.custom_fields, '') NOT LIKE ? OR p.author_id = ?)";
        $params = array_merge([$privateMarker, Auth::id() ?? 0], $params);
        $where = self::PUBLISHED . " AND {$visibility}" . ($extraWhere !== '' ? " AND ({$extraWhere})" : '');
        $total = (int) DB::value("SELECT COUNT(*) FROM posts p WHERE {$where}", $params);

        $posts = DB::fetchAll(
            "SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.cover_url, p.custom_fields, p.published_at,
                    p.is_pinned, p.category_pinned, p.password, p.external_url, p.view_count,
                    p.like_count, p.favorite_count,
                    (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id AND c.status = 'APPROVED') AS comment_count,
                    u.username AS author_name, u.avatar_url AS author_avatar,
                    c.name AS category_name, c.slug AS category_slug
             FROM posts p
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE {$where}
             ORDER BY {$order}
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        $posts = HomeController::attachTags($posts);

        // Lumina 信息流卡片底部展示每帖最近 6 条已通过评论(非回复),批量取避免 N+1
        if ($posts !== []) {
            $ids = array_map(static fn (array $post): int => (int) $post['id'], $posts);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::fetchAll(
                "SELECT c.post_id, c.author_name, c.content, c.created_at
                 FROM comments c
                 WHERE c.post_id IN ({$placeholders}) AND c.status = 'APPROVED' AND c.parent_id IS NULL
                 ORDER BY c.created_at DESC",
                $ids
            );
            $recent = [];
            foreach ($rows as $row) {
                $postId = (int) $row['post_id'];
                if (isset($recent[$postId]) && count($recent[$postId]) >= 6) {
                    continue;
                }
                $recent[$postId][] = [
                    'author_name' => (string) $row['author_name'],
                    'content' => (string) $row['content'],
                    'created_at' => (string) $row['created_at'],
                ];
            }
            foreach ($posts as $index => $post) {
                $posts[$index]['recent_comments'] = $recent[(int) $post['id']] ?? [];
            }
        }

        return [$posts, $total, $perPage, $pageNum];
    }

    /** 404 全壳错误页 */
    public static function notFound(Response $response, string $message): Response
    {
        $response->getBody()->write(\render('error', [
            'status' => 404,
            'message' => $message,
            'backUrl' => \url_to('/'),
        ]));
        return $response->withStatus(404);
    }

    /** 列表页 OG/canonical（当前完整 URL，对齐 Next 自动生成的 canonical） */
    public static function og(Request $request, string $title, string $description = ''): array
    {
        $uri = $request->getUri();
        $url = \Url::absolute($uri->getPath());
        if ($uri->getQuery() !== '') {
            $url .= '?' . $uri->getQuery();
        }
        $og = ['title' => $title];
        if ($description !== '') {
            $og['description'] = $description;
        }
        $og['url'] = $url;
        return $og;
    }
}
