<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Pafish\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 文章列表查询共享助手（首页/分类/标签/搜索共用）
 * 列表查询：per_page 设置（1-50）、已发布过滤、两级置顶排序
 */
final class Listings
{
    /**
     * 分页查询已发布文章（返回 [posts, total, perPage, pageNum]）
     * @param string $extraWhere 内部 SQL 条件（不含前导 AND），值必须用 $params 绑定
     * @param string $order 内部固定排序，不接受请求中的原始 SQL
     * @param list<int> $categoryIds 分类及其后代；非空时使用分类内置顶顺序
     */
    public static function published(
        string $extraWhere,
        array $params,
        Request $request,
        string $order = 'p.is_pinned DESC, p.published_at DESC, p.id DESC',
        ?int $perPage = null,
        array $categoryIds = []
    ): array {
        $perPage = min(50, max(1, $perPage ?? (int) Settings::get('posts_per_page', '10')));
        $page = $request->getQueryParams()['page'] ?? 1;
        $pageNum = is_scalar($page) ? max(1, (int) $page) : 1;

        $where = \Pafish\Services\Posts::publicWhere('p') . ($extraWhere !== '' ? " AND ({$extraWhere})" : '');
        $index = '';
        if ($categoryIds !== []) {
            $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $where .= " AND p.category_id IN ({$placeholders})";
            $params = array_merge($params, $categoryIds);
            $order = 'p.is_pinned DESC, p.category_pinned DESC, p.published_at DESC, p.id DESC';
            // 多分类仍需合并排序；限定覆盖索引，避免优化器按发布时间索引回读大量正文。
            $index = ' FORCE INDEX (idx_posts_category)';
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM posts p{$index} WHERE {$where}", $params);
        // 保持越界页为空；在乘法前判断，防止超大 page 导致 OFFSET 溢出。
        if ($total === 0 || $pageNum > intdiv($total - 1, $perPage) + 1) {
            return [[], $total, $perPage, $pageNum];
        }
        $offset = ($pageNum - 1) * $perPage;

        $posts = DB::fetchAll(
            "SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.content_format, p.template, p.cover_url, p.custom_fields, p.published_at, p.author_id,
                    p.is_pinned, p.category_pinned, p.password, p.external_url, p.view_count,
                    p.like_count, p.favorite_count, p.allow_comments, p.seo_title, p.meta_description,
                    (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id AND c.status = 'APPROVED') AS comment_count,
                    COALESCE(NULLIF(u.nickname, ''), u.username) AS author_name, u.avatar_url AS author_avatar,
                    c.name AS category_name, c.slug AS category_slug
             FROM (
                 SELECT p.id FROM posts p{$index}
                 WHERE {$where}
                 ORDER BY {$order}
                 LIMIT {$perPage} OFFSET {$offset}
             ) page_ids
             JOIN posts p ON p.id = page_ids.id
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN categories c ON c.id = p.category_id
             ORDER BY {$order}",
            $params
        );
        $posts = HomeController::attachTags($posts);
        foreach ($posts as &$post) {
            $post['cover_url'] = \Pafish\Services\Content::safeImageUrl($post['cover_url'] ?? null);
        }
        unset($post);

        if ($posts !== []) {
            $recent = \Pafish\Services\Comments::recentForPosts(array_column($posts, 'id'));
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

    /** 列表页 OG 与 canonical。 */
    public static function og(Request $request, string $title, string $description = '', array $queryKeys = ['page']): array
    {
        $path = $request->getUri()->getPath();
        $base = \Url::base();
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $url = \Url::absolute($path);
        // 仅保留改变页面内容的参数，避免追踪参数生成重复 canonical。
        $params = $request->getQueryParams();
        $query = [];
        foreach ($queryKeys as $key) {
            if (!is_scalar($params[$key] ?? null)) continue;
            if ($key === 'page') {
                $page = max(1, (int) $params[$key]);
                if ($page > 1) $query[$key] = $page;
            } elseif ($key === 'q') {
                $keyword = trim((string) $params[$key]);
                if ($keyword !== '') $query[$key] = $keyword;
            }
        }
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $og = ['title' => $title];
        if ($description !== '') {
            $og['description'] = $description;
        }
        $og['url'] = $url;
        return $og;
    }
}
