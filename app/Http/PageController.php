<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 独立页面详情（/pages/{slug}；设为首页的页面由首页直接渲染，不经此路由）
 * 独立页面
 */
final class PageController
{
    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = rawurldecode((string) ($args['slug'] ?? ''));
        $page = DB::fetchOne(
            "SELECT * FROM pages WHERE slug = ? AND status = 'PUBLISHED'",
            [$slug]
        );
        if (!$page) {
            return Listings::notFound($response, '页面不存在');
        }

        // meta description：正文去 Markdown 标记取前 120 字
        $plain = preg_replace('/[#*`>\[\]()!\-]/', '', (string) ($page['content'] ?? ''));
        $desc = mb_substr(trim((string) $plain), 0, 120);

        $response->getBody()->write(\render('page', [
            'title' => $page['title'],
            'description' => $desc,
            'og' => Listings::og($request, (string) $page['title'], $desc),
            'page' => $page,
            'contentHtml' => \md((string) ($page['content'] ?? '')),
        ]));
        return $response;
    }
}
