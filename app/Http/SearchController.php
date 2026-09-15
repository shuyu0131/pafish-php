<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 搜索页：MySQL FULLTEXT ngram 中文分词 + LIKE 兜底
 * 搜索页面
 */
final class SearchController
{
    public function index(Request $request, Response $response): Response
    {
        $keyword = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $pageNum = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $posts = [];
        $total = 0;
        $perPage = 10;

        if ($keyword !== '') {
            [$posts, $total, $perPage, $pageNum] = Listings::published(
                "MATCH(p.title, p.excerpt, p.content) AGAINST (? IN NATURAL LANGUAGE MODE)
                 OR p.title LIKE ? OR p.excerpt LIKE ? OR p.content LIKE ?",
                [$keyword, "%{$keyword}%", "%{$keyword}%", "%{$keyword}%"],
                $request
            );
        }
        $totalPages = max(1, (int) ceil($total / $perPage));

        $title = $keyword !== '' ? "“{$keyword}” 的搜索结果" : '搜索';
        $metaDesc = $keyword !== '' ? "搜索“{$keyword}”共找到 {$total} 篇文章" : '站内全文搜索';

        $response->getBody()->write(\render('search', [
            'title' => '搜索', // 页面标题固定，关键词在 h1 中展示
            'description' => $metaDesc,
            'og' => Listings::og($request, $title, $metaDesc),
            'keyword' => $keyword,
            'posts' => $posts,
            'total' => $total,
            'pageNum' => $pageNum,
            'totalPages' => $totalPages,
            // 分页 base 保留 q 参数（pagination 自动按 ?/& 拼接 page）
            'listBaseUrl' => \url_to('/search' . ($keyword !== '' ? '?q=' . rawurlencode($keyword) : '')),
        ]));
        return $response;
    }
}
