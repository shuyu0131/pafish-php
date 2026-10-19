<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Pafish\Services\Settings;
use Pafish\Services\Theme;
use Pafish\Services\ContentRepository;
use Pafish\Services\ExtensionKernel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 前台首页：设置了首页页面则渲染该页面，否则文章列表（分页 + 友链）
 */
final class HomeController
{
    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $authorValue = $query['author'] ?? null;
        if (is_scalar($authorValue) && ctype_digit((string) $authorValue) && (int) $authorValue > 0) {
            $authorPage = ExtensionKernel::providerFor('theme', Theme::active(), 'authorTimeline');
            if (is_callable($authorPage)) return $authorPage($request, $response, (int) $authorValue);
        }

        // 后台"页面管理 → 设为首页"优先
        $homePageId = (string) Settings::get('home_page_id', '');
        if (ctype_digit($homePageId)) {
            $page = ContentRepository::publishedPageById((int) $homePageId);
            if ($page) {
                $pageId = (string) ($page['id'] ?? '');
                $content = \apply_content_filters((string) ($page['content'] ?? ''), [
                    'type' => 'page',
                    'id' => $pageId,
                    'data' => $page,
                    // 兼容早期扩展上下文。
                    'pageId' => $pageId,
                    'page' => $page,
                ]);
                $description = $page['meta_description'] ?: (string) Settings::get('site_description', '');
                $response->getBody()->write(render('page', [
                    'title' => $page['title'],
                    'seoTitle' => $page['seo_title'] ?: $page['title'],
                    'description' => $description,
                    'og' => [
                        'type' => 'website',
                        'description' => $description,
                        'url' => \absolute_url('/'),
                    ],
                    'page' => $page,
                    'contentHtml' => \content_html($content, (string) ($page['content_format'] ?? 'markdown')),
                ]));
                return $response;
            }
        }

        // 文章列表
        $themeLimit = (int) Theme::value('recent_posts_limit', '0');
        $perPage = $themeLimit > 0
            ? min(30, max(1, $themeLimit))
            : min(50, max(1, (int) Settings::get('posts_per_page', '10')));
        [$posts, $total, $perPage, $pageNum] = Listings::published('', [], $request, perPage: $perPage);
        $totalPages = max(1, (int) ceil($total / $perPage));

        $response->getBody()->write(render('index', [
            'title' => '首页',
            'description' => (string) Settings::get('site_description', ''),
            'og' => [
                'type' => 'website',
                'description' => (string) Settings::get('site_description', ''),
                'url' => Listings::og($request, '首页')['url'],
            ],
            'posts' => $posts,
            'lumina_list_kind' => 'home',
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
