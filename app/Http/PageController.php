<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Services\ContentRepository;
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
        $page = ContentRepository::publishedPageBySlug($slug);
        if (!$page) {
            return Listings::notFound($response, '页面不存在');
        }

        // meta description：正文去 Markdown 标记取前 120 字
        $plain = \Pafish\Services\Content::plain((string) ($page['content'] ?? ''), (string) ($page['content_format'] ?? 'markdown'));
        $desc = $page['meta_description'] ?: mb_substr(trim((string) $plain), 0, 120);
        $seoTitle = $page['seo_title'] ?: $page['title'];

        $pageId = (string) ($page['id'] ?? '');
        $commentPage = max(1, (int) ($request->getQueryParams()['cpage'] ?? 1));
        $commentsEnabled = (string) \Pafish\Services\Settings::get('comments_enabled', 'true') !== 'false';
        $comments = $commentsEnabled
            ? \Pafish\Http\Comments::section((int) $page['id'], $commentPage, 'page')
            : ['roots' => [], 'total' => 0, 'totalPages' => 1];
        $content = \apply_content_filters((string) ($page['content'] ?? ''), [
            'type' => 'page',
            'id' => $pageId,
            'data' => $page,
            // 兼容早期扩展上下文。
            'pageId' => $pageId,
            'page' => $page,
        ]);
        $response->getBody()->write(\render('page', [
            'title' => $page['title'],
            'seoTitle' => $seoTitle,
            'description' => $desc,
            'og' => Listings::og($request, $seoTitle, $desc, []),
            'page' => $page,
            'contentHtml' => \content_html($content, (string) ($page['content_format'] ?? 'markdown')),
            'commentsEnabled' => $commentsEnabled,
            'commentsOpen' => $commentsEnabled && !empty($page['allow_comments']),
            'needReview' => (string) \Pafish\Services\Settings::get('comments_need_review', 'true') !== 'false',
            'commentPage' => $commentPage,
            'commentRoots' => $comments['roots'],
            'commentTotal' => $comments['total'],
            'commentTotalPages' => $comments['totalPages'],
        ]));
        return $response;
    }
}
