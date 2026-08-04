<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
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

        $where = self::PUBLISHED . ($extraWhere !== '' ? " AND ({$extraWhere})" : '');
        $total = (int) DB::value("SELECT COUNT(*) FROM posts p WHERE {$where}", $params);

        $posts = DB::fetchAll(
            "SELECT p.id, p.title, p.slug, p.excerpt, p.cover_url, p.published_at,
                    p.is_pinned, p.category_pinned, p.password, p.external_url, p.view_count,
                    u.username AS author_name,
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
