<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Pafish\Services\Markdown;
use Pafish\Services\Settings;use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 前台文章详情（对标 Node 版 /post/[slug]/page.tsx）：
 * 密码门（cookie 解锁 24h）/ 浏览量（cookie 7 天去重）/ 点赞收藏（cookie 列表 + DB 计数）
 * 上下篇 / 相关推荐 / 自定义字段 / 面包屑 / JSON-LD / OG
 */
final class PostController
{
    private const UNLOCK_TTL = 86400;      // 密码解锁 cookie 24h
    private const VIEW_TTL = 604800;       // 浏览量去重 7 天

    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = rawurldecode((string) ($args['slug'] ?? ''));
        $post = $this->findPublishedPost($slug);
        if ($post === null) {
            return $this->notFound($response);
        }

        // 评论分页参数（cpage），非法值回退第 1 页
        $commentPage = max(1, (int) ($request->getQueryParams()['cpage'] ?? 1));
        $settings = Settings::all();
        $showCustomFields = \Theme::value('show_custom_fields', '1') !== '0';
        $showRelated = \Theme::value('show_related_posts', '1') !== '0';

        // ---- 密码门 ----
        if (!empty($post['password'])) {
            $unlocked = ($_COOKIE['unlocked_post_' . $post['id']] ?? '') === '1';
            $method = strtoupper($request->getMethod());

            // POST 提交密码：验证通过 → 写解锁 cookie → 重定向回本页（PRG）
            if ($method === 'POST') {
                if (!$this->verifyPassword((string) ($request->getParsedBody()['password'] ?? ''), $post['password'])) {
                    return $this->renderPost($request, $response, $post, [
                        'passwordError' => '密码错误，请重试',
                        'commentPage' => $commentPage,
                        'settings' => $settings,
                        'theme' => \Theme::values(),
                        'showCustomFields' => $showCustomFields,
                        'showRelated' => $showRelated,
                        'locked' => true,
                    ], $this->neighbors($post));
                }
                setcookie('unlocked_post_' . $post['id'], '1', time() + self::UNLOCK_TTL, '/', '', false, true);
                return $response
                    ->withHeader('Location', \url_to('/post/' . rawurlencode($post['slug'])))
                    ->withStatus(302);
            }

            if (!$unlocked) {
                return $this->renderPost($request, $response, $post, [
                    'commentPage' => $commentPage,
                    'settings' => $settings,
                    'theme' => \Theme::values(),
                    'showCustomFields' => $showCustomFields,
                    'showRelated' => $showRelated,
                    'locked' => true,
                ], $this->neighbors($post));
            }
        }

        // ---- 浏览量：cookie 7 天去重（对齐 Node ViewTracker） ----
        $viewCookie = 'pafish_viewed_' . $post['id'];
        if (empty($_COOKIE[$viewCookie])) {
            DB::execute('UPDATE posts SET view_count = view_count + 1 WHERE id = ?', [(int) $post['id']]);
            $post['view_count'] = (int) $post['view_count'] + 1;
            setcookie($viewCookie, '1', time() + self::VIEW_TTL, '/', '', false, false);
        }

        // ---- 点赞/收藏初始状态（cookie 列表，对齐 Node） ----
        $postKey = (string) $post['id'];
        $liked = in_array($postKey, $this->cookieIds('liked_posts'), true);
        $favorited = in_array($postKey, $this->cookieIds('favorited_posts'), true);

        // ---- 自定义字段解析（坏数据容错为空） ----
        $customFields = $this->parseCustomFields($post['custom_fields'] ?? null);

        // ---- 评论区数据（对齐 Node CommentSection；评论功能关闭时整块隐藏） ----
        $commentsEnabled = (string) Settings::get('comments_enabled', 'true') !== 'false';
        $needReview = (string) Settings::get('comments_need_review', 'true') !== 'false';
        $comments = $commentsEnabled
            ? Comments::section((int) $post['id'], $commentPage)
            : ['roots' => [], 'total' => 0, 'totalPages' => 1];

        $neighbors = $this->neighbors($post);
        $related = $showRelated ? $this->related($post) : [];

        return $this->renderPost($request, $response, $post, [
            'commentPage' => $commentPage,
            'settings' => $settings,
            'theme' => \Theme::values(),
            'showCustomFields' => $showCustomFields,
            'showRelated' => $showRelated,
            'locked' => false,
            'liked' => $liked,
            'favorited' => $favorited,
            'customFields' => $customFields,
            'prevPost' => $neighbors[0],
            'nextPost' => $neighbors[1],
            'related' => $related,
            'commentsEnabled' => $commentsEnabled,
            'needReview' => $needReview,
            'commentRoots' => $comments['roots'],
            'commentTotal' => $comments['total'],
            'commentTotalPages' => $comments['totalPages'],
        ], $neighbors);
    }

    /** 点赞/收藏切换（POST JSON，cookie 列表幂等切换 + DB 计数增减） */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $kind = (string) ($args['kind'] ?? ''); // like | favorite
        if (!in_array($kind, ['like', 'favorite'], true)) {
            return $this->json($response, ['error' => '参数错误'], 400);
        }
        // 收藏需要登录后才可使用（点赞保持匿名，与 Node 版一致）
        if ($kind === 'favorite' && !\is_logged_in()) {
            return $this->json($response, ['error' => '请先登录后再收藏', 'login_url' => \url_to('/login')], 401);
        }
        $post = DB::fetchOne('SELECT id FROM posts WHERE id = ?', [$id]);
        if (!$post) {
            return $this->json($response, ['error' => 'Not Found'], 404);
        }

        $cookieName = $kind === 'like' ? 'liked_posts' : 'favorited_posts';
        $ids = $this->cookieIds($cookieName);
        $key = (string) $id;
        $nowLiked = in_array($key, $ids, true);

        if ($nowLiked) {
            $ids = array_values(array_diff($ids, [$key]));
            DB::execute("UPDATE posts SET {$kind}_count = GREATEST({$kind}_count - 1, 0) WHERE id = ?", [$id]);
        } else {
            $ids[] = $key;
            DB::execute("UPDATE posts SET {$kind}_count = {$kind}_count + 1 WHERE id = ?", [$id]);
        }

        setcookie($cookieName, implode(',', $ids), time() + 31536000, '/', '', false, false);
        $count = (int) DB::value("SELECT {$kind}_count FROM posts WHERE id = ?", [$id]);
        return $this->json($response, ['ok' => true, 'active' => !$nowLiked, 'count' => $count]);
    }

    // ---------- 内部 ----------

    private function renderPost(Request $request, Response $response, array $post, array $extra, array $neighbors): Response
    {
        $data = array_merge($extra, [
            'post' => $post,
            'title' => $post['title'],
            'description' => (string) ($post['excerpt'] ?? ''), // meta description 用摘要（对齐 Node）
            'og' => [
                'type' => 'article',
                'title' => $post['title'],
                'description' => $post['excerpt'] ?? '',
                'url' => \absolute_url('/post/' . rawurlencode((string) $post['slug'])),
                'image' => $post['cover_url'] ? \absolute_url((string) $post['cover_url']) : '',
            ],
        ]);
        // 正文渲染放最后一步（避免密码错误分支重复渲染开销差异）
        $data['contentHtml'] = Markdown::render((string) ($post['content'] ?? ''));
        $response->getBody()->write(\render('post', $data));
        return $response;
    }

    private function findPublishedPost(string $slug): ?array
    {
        $post = DB::fetchOne(
            "SELECT p.*, u.username AS author_name,
                    c.name AS category_name, c.slug AS category_slug
             FROM posts p
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.slug = ? AND p.deleted_at IS NULL",
            [$slug]
        );
        if (!$post || $post['status'] !== 'PUBLISHED') {
            return null;
        }
        $publishedAt = $post['published_at'];
        if ($publishedAt !== null && strtotime((string) $publishedAt) > time()) {
            return null; // 定时文章未到发布时间
        }
        // 标签
        $tags = DB::fetchAll(
            "SELECT t.name, t.slug FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id = ? ORDER BY t.id ASC",
            [(int) $post['id']]
        );
        $post['tags'] = $tags;
        return $post;
    }

    /** [上一篇, 下一篇]（按发布时间相邻） */
    private function neighbors(array $post): array
    {
        $where = "status = 'PUBLISHED' AND deleted_at IS NULL AND (published_at IS NULL OR published_at <= NOW())";
        $prev = DB::fetchOne(
            "SELECT title, slug FROM posts WHERE {$where} AND (published_at < ? OR (published_at IS NULL AND id < ?)) ORDER BY published_at DESC LIMIT 1",
            [$post['published_at'], (int) $post['id']]
        );
        $next = DB::fetchOne(
            "SELECT title, slug FROM posts WHERE {$where} AND (published_at > ? OR (published_at IS NULL AND id > ?)) ORDER BY published_at ASC LIMIT 1",
            [$post['published_at'], (int) $post['id']]
        );
        return [$prev, $next];
    }

    /** 相关推荐：同分类或共享标签，排除自身，最多 3 篇 */
    private function related(array $post): array
    {
        $id = (int) $post['id'];
        $catId = (int) ($post['category_id'] ?? 0);
        $tagIds = array_map(static fn (array $t): int => (int) DB::value('SELECT id FROM tags WHERE slug = ?', [$t['slug']]), $post['tags'] ?? []);
        $tagIds = array_filter($tagIds);
        $params = [$id];
        $sql = "SELECT p.id, p.title, p.slug, p.excerpt, p.published_at,
                       c.name AS category_name, c.slug AS category_slug
                FROM posts p
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE p.status = 'PUBLISHED' AND p.deleted_at IS NULL
                  AND (p.published_at IS NULL OR p.published_at <= NOW())
                  AND p.id != ?";
        $or = [];
        if ($catId > 0) {
            $or[] = 'p.category_id = ?';
            $params[] = $catId;
        }
        if ($tagIds !== []) {
            $ph = implode(',', array_fill(0, count($tagIds), '?'));
            $or[] = "p.id IN (SELECT post_id FROM post_tags WHERE tag_id IN ({$ph}))";
            $params = array_merge($params, $tagIds);
        }
        if ($or === []) {
            return [];
        }
        $sql .= ' AND (' . implode(' OR ', $or) . ') ORDER BY p.published_at DESC LIMIT 3';
        return DB::fetchAll($sql, $params);
    }

    private function parseCustomFields(mixed $raw): array
    {
        if (!$raw) {
            return [];
        }
        $parsed = json_decode((string) $raw, true);
        return is_array($parsed) ? $parsed : [];
    }

    private function cookieIds(string $name): array
    {
        $value = $_COOKIE[$name] ?? '';
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    private function verifyPassword(string $input, string $hash): bool
    {
        // bcrypt（password_hash 生成）或明文比对（老数据兜底）
        if (str_starts_with($hash, '$2')) {
            return password_verify($input, $hash);
        }
        return hash_equals($hash, $input);
    }

    private function notFound(Response $response): Response
    {
        $response->getBody()->write(\render('error', [
            'status' => 404,
            'message' => '文章不存在',
            'backUrl' => \url_to('/'),
        ]));
        return $response->withStatus(404);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
