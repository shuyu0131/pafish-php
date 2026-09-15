<?php

declare(strict_types=1);

namespace Pafish\Api;

use Pafish\Core\ApiKey;
use Pafish\Core\Config;
use Pafish\Core\DB;
use Pafish\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 开放 API v1：
 * - GET /api/v1/posts          文章列表（分页 + category/tag/q 过滤，两级置顶排序）
 * - GET /api/v1/posts/{slug}   文章详情（含正文与自定义字段）
 * - GET /api/v1/categories     分类列表（含文章数与层级）
 * - GET /api/v1/tags           标签列表（含文章数）
 * - GET /api/v1/comments       文章评论（仅已通过，含 parentId 便于组回复树）
 * 鉴权：X-API-Key 头（api_enabled + api_key 设置），常量时间比较
 */
final class V1Controller
{
    private const POSTS_MAX_PER_PAGE = 50;
    private const POSTS_DEFAULT_PER_PAGE = 10;
    private const COMMENTS_MAX_PER_PAGE = 100;

    /** 输出 JSON；$status 默认 200 */
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $cors = trim((string) Settings::get('api_cors', ''));
        if ($cors !== '') {
            $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
            $allowed = array_map('trim', explode(',', $cors));
            if (in_array('*', $allowed, true) || ($origin !== '' && in_array($origin, $allowed, true))) {
                $response = $response->withHeader('Access-Control-Allow-Origin', $origin !== '' ? $origin : '*');
                $response = $response->withHeader('Vary', 'Origin');
            }
        }
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }

    /** 鉴权失败响应 */
    private function authError(Response $response, array $fail): Response
    {
        return $this->json($response, ['error' => $fail[1]], $fail[0]);
    }

    /**
     * 墙钟（Asia/Shanghai）DATETIME → UTC ISO-8601
     */
    private function iso(?string $dt): ?string
    {
        if ($dt === null || $dt === '') {
            return null;
        }
        $tz = new \DateTimeZone((string) Config::get('timezone', 'Asia/Shanghai'));
        return (new \DateTimeImmutable($dt, $tz))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /** 文章基础 SELECT 片段（列表与详情共用列） */
    private const POST_COLS = 'p.id, p.title, p.slug, p.excerpt, p.cover_url, p.published_at,
        p.view_count, p.like_count, p.favorite_count, p.is_pinned, p.category_pinned,
        p.password, p.external_url, p.status, p.updated_at, p.custom_fields';

    /** 行 → 开放 API 输出结构（列表版，无正文/自定义字段） */
    private function postListItem(array $p): array
    {
        return [
            'id' => (string) $p['id'],
            'title' => $p['title'],
            'slug' => $p['slug'],
            'excerpt' => $p['excerpt'],
            'coverUrl' => $p['cover_url'],
            'publishedAt' => $this->iso($p['published_at']),
            'viewCount' => (int) $p['view_count'],
            'likeCount' => (int) $p['like_count'],
            'favoriteCount' => (int) $p['favorite_count'],
            'isPinned' => (bool) $p['is_pinned'],
            'categoryPinned' => (bool) $p['category_pinned'],
            'hasPassword' => $p['password'] !== null && $p['password'] !== '',
            'externalUrl' => $p['external_url'],
            'category' => $p['category_name'] !== null ? ['name' => $p['category_name'], 'slug' => $p['category_slug']] : null,
            'tags' => $p['tags'],
            'author' => $p['author_name'],
        ];
    }

    /** 取文章行（列表列 + 分类/作者），null 表示不存在 */
    private function fetchPostBySlug(string $slug): ?array
    {
        $p = DB::fetchOne(
            "SELECT " . self::POST_COLS . ", p.content,
                    u.username AS author_name,
                    c.name AS category_name, c.slug AS category_slug
             FROM posts p
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.slug = ? AND p.deleted_at IS NULL",
            [$slug]
        );
        if ($p === null) {
            return null;
        }
        $p['tags'] = array_map(
            static fn (array $t): array => ['name' => $t['name'], 'slug' => $t['slug']],
            DB::fetchAll('SELECT t.name, t.slug FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id = ? ORDER BY t.id ASC', [(int) $p['id']])
        );
        return $p;
    }

    /** GET /api/v1/posts */
    public function posts(Request $request, Response $response): Response
    {
        $fail = ApiKey::check($request);
        if ($fail !== null) {
            return $this->authError($response, $fail);
        }

        $sp = $request->getQueryParams();
        $page = max(1, (int) ($sp['page'] ?? 1));
        $maxLimit = min(self::POSTS_MAX_PER_PAGE, max(1, (int) Settings::get('api_max_limit', (string) self::POSTS_MAX_PER_PAGE)));
        $perPage = min($maxLimit, max(1, (int) ($sp['perPage'] ?? self::POSTS_DEFAULT_PER_PAGE)));
        $categorySlug = trim((string) ($sp['category'] ?? ''));
        $tagSlug = trim((string) ($sp['tag'] ?? ''));
        $q = trim((string) ($sp['q'] ?? ''));

        $where = "p.status = 'PUBLISHED' AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= NOW())";
        $params = [];

        if ($categorySlug !== '') {
            $cat = DB::fetchOne('SELECT id FROM categories WHERE slug = ?', [$categorySlug]);
            if ($cat === null) {
                return $this->json($response, ['error' => '分类不存在'], 404);
            }
            // 与前台一致：包含子分类的文章
            $ids = [(int) $cat['id']];
            $frontier = $ids;
            while ($frontier !== []) {
                $ph = implode(',', array_fill(0, count($frontier), '?'));
                $kids = DB::fetchAll("SELECT id FROM categories WHERE parent_id IN ({$ph})", $frontier);
                $frontier = array_map(static fn (array $k): int => (int) $k['id'], $kids);
                $ids = array_merge($ids, $frontier);
            }
            $where .= ' AND p.category_id IN (' . implode(',', $ids) . ')';
        }
        if ($tagSlug !== '') {
            $where .= ' AND p.id IN (SELECT pt.post_id FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE t.slug = ?)';
            $params[] = $tagSlug;
        }
        if ($q !== '') {
            $where .= ' AND (p.title LIKE ? OR p.content LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $total = (int) DB::value("SELECT COUNT(*) FROM posts p WHERE {$where}", $params);
        $offset = ($page - 1) * $perPage;
        $rows = DB::fetchAll(
            "SELECT " . self::POST_COLS . ",
                    u.username AS author_name,
                    c.name AS category_name, c.slug AS category_slug
             FROM posts p
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE {$where}
             ORDER BY p.is_pinned DESC, p.category_pinned DESC, p.published_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        $postIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $tagsByPost = [];
        if ($postIds !== []) {
            $ph = implode(',', array_fill(0, count($postIds), '?'));
            foreach (DB::fetchAll("SELECT pt.post_id, t.name, t.slug FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id IN ({$ph}) ORDER BY t.id ASC", $postIds) as $tag) {
                $tagsByPost[(int) $tag['post_id']][] = ['name' => $tag['name'], 'slug' => $tag['slug']];
            }
        }

        $posts = [];
        foreach ($rows as $r) {
            $r['tags'] = $tagsByPost[(int) $r['id']] ?? [];
            $posts[] = $this->postListItem($r);
        }

        return $this->json($response, [
            'posts' => $posts,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    /** GET /api/v1/posts/{slug} */
    public function postDetail(Request $request, Response $response, array $args): Response
    {
        $fail = ApiKey::check($request);
        if ($fail !== null) {
            return $this->authError($response, $fail);
        }

        $p = $this->fetchPostBySlug((string) ($args['slug'] ?? ''));
        $isPublic = $p !== null
            && $p['status'] === 'PUBLISHED'
            && $p['published_at'] !== null
            && $p['published_at'] <= date('Y-m-d H:i:s');
        if (!$isPublic) {
            return $this->json($response, ['error' => '文章不存在'], 404);
        }

        // 自定义字段 JSON 解析（坏数据容错为空数组）
        $customFields = [];
        if ($p['custom_fields'] !== null && $p['custom_fields'] !== '') {
            $parsed = json_decode($p['custom_fields'], true);
            if (is_array($parsed)) {
                $customFields = $parsed;
            }
        }

        $item = $this->postListItem($p);
        $item['content'] = $p['content'];
        $item['updatedAt'] = $this->iso($p['updated_at']);
        $item['customFields'] = $customFields;

        return $this->json($response, $item);
    }

    /** GET /api/v1/categories */
    public function categories(Request $request, Response $response): Response
    {
        $fail = ApiKey::check($request);
        if ($fail !== null) {
            return $this->authError($response, $fail);
        }

        $rows = DB::fetchAll(
            "SELECT c.id, c.name, c.slug, c.description, c.parent_id,
                    (SELECT COUNT(*) FROM posts p WHERE p.category_id = c.id AND p.status = 'PUBLISHED' AND p.deleted_at IS NULL) AS post_count
             FROM categories c
             ORDER BY c.parent_id ASC, c.sort_order ASC, c.id ASC"
        );
        $categories = array_map(static fn (array $c): array => [
            'id' => (string) $c['id'],
            'name' => $c['name'],
            'slug' => $c['slug'],
            'description' => $c['description'],
            'parentId' => $c['parent_id'] !== null ? (string) $c['parent_id'] : null,
            'postCount' => (int) $c['post_count'],
        ], $rows);

        return $this->json($response, ['categories' => $categories]);
    }

    /** GET /api/v1/tags */
    public function tags(Request $request, Response $response): Response
    {
        $fail = ApiKey::check($request);
        if ($fail !== null) {
            return $this->authError($response, $fail);
        }

        $rows = DB::fetchAll(
            "SELECT t.id, t.name, t.slug,
                    (SELECT COUNT(*) FROM post_tags pt JOIN posts p ON p.id = pt.post_id
                     WHERE pt.tag_id = t.id AND p.status = 'PUBLISHED' AND p.deleted_at IS NULL) AS post_count
             FROM tags t
             ORDER BY t.name ASC"
        );
        $tags = array_map(static fn (array $t): array => [
            'id' => (string) $t['id'],
            'name' => $t['name'],
            'slug' => $t['slug'],
            'postCount' => (int) $t['post_count'],
        ], $rows);

        return $this->json($response, ['tags' => $tags]);
    }

    /** GET /api/v1/comments?postId=2&page=1&perPage=50 */
    public function comments(Request $request, Response $response): Response
    {
        $fail = ApiKey::check($request);
        if ($fail !== null) {
            return $this->authError($response, $fail);
        }

        $sp = $request->getQueryParams();
        $postIdRaw = trim((string) ($sp['postId'] ?? ''));
        if (!preg_match('/^\d+$/', $postIdRaw)) {
            return $this->json($response, ['error' => '缺少有效的 postId 参数'], 400);
        }
        $postId = (int) $postIdRaw;
        $page = max(1, (int) ($sp['page'] ?? 1));
        $perPage = min(self::COMMENTS_MAX_PER_PAGE, max(1, (int) ($sp['perPage'] ?? 50)));

        $post = DB::fetchOne("SELECT id, status FROM posts WHERE id = ? AND deleted_at IS NULL", [$postId]);
        if ($post === null || $post['status'] !== 'PUBLISHED') {
            return $this->json($response, ['error' => '文章不存在'], 404);
        }

        $where = 'post_id = ? AND status = ?';
        $params = [$postId, 'APPROVED'];
        $total = (int) DB::value("SELECT COUNT(*) FROM comments WHERE {$where}", $params);
        $rows = DB::fetchAll(
            "SELECT id, post_id, parent_id, author_name, content, created_at, is_pinned, like_count
             FROM comments
             WHERE {$where}
             ORDER BY is_pinned DESC, created_at ASC
             LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $params
        );
        $tz = new \DateTimeZone((string) Config::get('timezone', 'Asia/Shanghai'));
        $comments = array_map(static fn (array $c): array => [
            'id' => (string) $c['id'],
            'postId' => (string) $c['post_id'],
            'parentId' => $c['parent_id'] !== null ? (string) $c['parent_id'] : null,
            'authorName' => $c['author_name'],
            'content' => $c['content'],
            'createdAt' => (new \DateTimeImmutable($c['created_at'], $tz))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
            'isPinned' => (bool) $c['is_pinned'],
            'likeCount' => (int) $c['like_count'],
        ], $rows);

        return $this->json($response, [
            'comments' => $comments,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    public function siteInfo(Request $request, Response $response): Response
    {
        $fail = ApiKey::check($request); if ($fail !== null) return $this->authError($response, $fail);
        return $this->json($response, ['site' => ['name' => Settings::get('site_name', '纸鱼博客'), 'subtitle' => Settings::get('site_subtitle', ''), 'description' => Settings::get('site_description', ''), 'url' => \absolute_url('/'), 'version' => \Pafish\Core\Version::current()]]);
    }

    public function pages(Request $request, Response $response): Response
    {
        $fail = ApiKey::check($request); if ($fail !== null) return $this->authError($response, $fail);
        $rows = DB::fetchAll("SELECT id,title,slug,content,status,published_at,updated_at FROM pages WHERE status='PUBLISHED' ORDER BY published_at DESC, id DESC");
        return $this->json($response, ['pages' => array_map(fn(array $p): array => ['id'=>(string)$p['id'],'title'=>$p['title'],'slug'=>$p['slug'],'content'=>$p['content'],'publishedAt'=>$this->iso($p['published_at']),'updatedAt'=>$this->iso($p['updated_at'])], $rows)]);
    }

    public function links(Request $request, Response $response): Response
    {
        $fail = ApiKey::check($request); if ($fail !== null) return $this->authError($response, $fail);
        $rows = DB::fetchAll('SELECT id,name,url,description FROM links WHERE visible=1 ORDER BY sort_order,id');
        return $this->json($response, ['links' => array_map(static fn(array $r): array => ['id'=>(string)$r['id'],'name'=>$r['name'],'url'=>$r['url'],'description'=>$r['description']], $rows)]);
    }

    public function menus(Request $request, Response $response): Response
    {
        $fail = ApiKey::check($request); if ($fail !== null) return $this->authError($response, $fail);
        $rows = DB::fetchAll('SELECT id,label,url,is_external FROM nav_items WHERE visible=1 ORDER BY sort_order,id');
        return $this->json($response, ['menus' => array_map(static fn(array $r): array => ['id'=>(string)$r['id'],'label'=>$r['label'],'url'=>$r['url'],'external'=>(bool)$r['is_external']], $rows)]);
    }
}
