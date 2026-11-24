<?php

declare(strict_types=1);

namespace Pafish\Http;

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
     * @param string $extraWhere 内部 SQL 条件（不含前导 AND）
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
        $resolvedPerPage = $perPage ?? (int) Settings::get('posts_per_page', '10');
        $filteredPerPage = \Pafish\Core\Hooks::applyFilters('posts_per_page', $resolvedPerPage, [
            'context' => 'list',
            'per_page' => $perPage,
        ]);
        $perPage = min(50, max(1, (int) $filteredPerPage));
        $page = $request->getQueryParams()['page'] ?? 1;
        $pageNum = is_scalar($page) ? max(1, (int) $page) : 1;

        return \Pafish\Services\PostQuery::published($extraWhere, $params, $pageNum, $perPage, $order, $categoryIds);
    }

    public static function pageNumber(Request $request): int
    {
        $page = $request->getQueryParams()['page'] ?? 1;
        return is_scalar($page) ? max(1, (int) $page) : 1;
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
