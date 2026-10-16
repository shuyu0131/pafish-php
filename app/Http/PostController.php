<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;
use Pafish\Core\Auth;
use Pafish\Core\Session;
use Pafish\Core\RateLimiter;
use Pafish\Http\Responses;
use Pafish\Services\Settings;
use Pafish\Services\PostReactions;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 前台文章详情：
 * 密码门（会话解锁 24h）/ 浏览量（cookie 7 天去重）/ 点赞收藏（服务端状态 + DB 计数）
 * 上下篇 / 相关推荐 / 自定义字段 / 面包屑 / JSON-LD / OG
 */
final class PostController
{
    private const UNLOCK_TTL = 86400;      // 密码解锁会话 24h
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
        $settings = Settings::core();
        $showCustomFields = \theme_value('show_custom_fields', '1') !== '0';
        $showRelated = \theme_value('show_related_posts', '1') !== '0';

        // ---- 密码门 ----
        if (!empty($post['password'])) {
            $postId = (int) $post['id'];
            $unlocked = Session::unlockedPost($postId, (string) $post['password'], time());
            $method = strtoupper($request->getMethod());

            // POST 提交密码：验证通过 → 写入服务端会话 → 重定向回本页（PRG）
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
                Session::unlockPost($postId, (string) $post['password'], time() + self::UNLOCK_TTL);
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

        // ---- 浏览量：cookie 7 天去重 ----
        $viewCookie = 'pafish_viewed_' . $post['id'];
        if (empty($_COOKIE[$viewCookie])) {
            DB::execute('UPDATE posts SET view_count = view_count + 1 WHERE id = ?', [(int) $post['id']]);
            $post['view_count'] = (int) $post['view_count'] + 1;
            setcookie($viewCookie, '1', time() + self::VIEW_TTL, '/', '', false, false);
        }

        // 登录用户使用持久记录，匿名状态只信任服务端会话。
        $userId = Auth::id();
        $reactionState = PostReactions::state((int) $post['id'], $userId);
        $liked = $reactionState['like'];
        $favorited = $reactionState['favorite'];

        // ---- 自定义字段解析（坏数据容错为空） ----
        // 字段由当前主题或插件自行声明和消费，核心只负责传递原始数据。
        $customFields = $this->parseCustomFields($post['custom_fields'] ?? null);

        // ---- 评论区数据（评论功能关闭时整块隐藏） ----
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
            'commentsOpen' => \Pafish\Services\Posts::commentsOpen($post),
            'needReview' => $needReview,
            'commentRoots' => $comments['roots'],
            'commentTotal' => $comments['total'],
            'commentTotalPages' => $comments['totalPages'],
        ], $neighbors);
    }

    /** 点赞/收藏：会话 CSRF、组合限流；指定 active 时可幂等重试。 */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $kind = (string) ($args['kind'] ?? ''); // like | favorite
        if (!in_array($kind, ['like', 'favorite'], true)) {
            return $this->json($response, ['error' => '参数错误'], 400);
        }
        $userId = Auth::id();
        if ($kind === 'favorite' && $userId === null) {
            return $this->json($response, ['error' => '请先登录后再收藏', 'login_url' => \url_to('/login')], 401);
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $token = $body['_csrf'] ?? $request->getHeaderLine('X-CSRF-Token');
        if (!is_string($token) || !Session::verifyCsrf($token)) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        $active = null;
        if (array_key_exists('active', $body)) {
            if (!in_array($body['active'], [true, false, 1, 0, '1', '0'], true)) {
                return $this->json($response, ['error' => '互动状态无效'], 400);
            }
            $active = in_array($body['active'], [true, 1, '1'], true);
        }
        $ip = \Pafish\Core\ClientIp::fromRequest($request);
        if (!RateLimiter::allow('post-reaction-ip', $ip, 60, 60)
            || ($userId !== null && !RateLimiter::allow('post-reaction-user', (string) $userId, 30, 60))) {
            return $this->json($response, ['error' => '操作过于频繁，请稍后再试'], 429);
        }
        try {
            $result = PostReactions::change($id, $kind, $userId, $active);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->json($response, ['error' => $e->getMessage()], (int) $e->getCode());
        }
        if ($result['changed']) {
            $this->fireReactionHooks($id, $kind, $result['active'], $result['count'], $userId);
        }
        return $this->json($response, $result);
    }

    /** 互动事件契约：点赞/收藏成功切换后触发；匿名用户 userId 为 null。 */
    private function fireReactionHooks(int $postId, string $kind, bool $active, int $count, ?int $userId): void
    {
        $payload = [
            'postId' => (string) $postId,
            'kind' => $kind,
            'active' => $active,
            'count' => $count,
            'userId' => $userId !== null ? (string) $userId : null,
        ];
        \do_action('after_post_reaction', $payload);
        if ($kind === 'favorite') {
            \do_action('after_post_favorite', $payload);
        }
    }

    private function renderPost(Request $request, Response $response, array $post, array $extra, array $neighbors): Response
    {
        // 锁定文章的 meta 不带摘要与正文，只给固定提示；没填摘要时取正文前 160 字纯文本兜底
        $excerpt = trim((string) ($post['excerpt'] ?? ''));
        if (!empty($extra['locked'])) {
            $excerpt = '本文章已加密，请输入密码后查看';
        } elseif ($excerpt === '') {
            $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags(\content_html((string) ($post['content'] ?? ''), (string) ($post['content_format'] ?? 'markdown')))));
            $excerpt = mb_substr($plain, 0, 160);
        }
        if (empty($extra['locked']) && !empty($post['meta_description'])) $excerpt = $post['meta_description'];
        $seoTitle = $post['seo_title'] ?: $post['title'];
        $data = array_merge($extra, [
            'post' => $post,
            'title' => $post['title'],
            'seoTitle' => $seoTitle,
            'description' => $excerpt, // meta description 用摘要
            'og' => [
                'type' => 'article',
                'title' => $seoTitle,
                'description' => $excerpt,
                'url' => \absolute_url('/post/' . rawurlencode((string) $post['slug'])),
                'image' => $post['cover_url'] ? \absolute_url((string) $post['cover_url']) : '',
            ],
        ]);
        // 仅在已解锁分支渲染正文，避免锁定页面把文章内容交给主题或插件。
        if (!empty($extra['locked'])) {
            unset($data['post']['content']);
            unset($data['post']['meta_description']);
            $data['contentHtml'] = '';
            $response->getBody()->write(\render('post', $data));
            return $response;
        }

        $markdown = (string) ($post['content'] ?? '');
        $postId = (string) ($post['id'] ?? '');
        $markdown = \apply_content_filters($markdown, [
            'type' => 'post',
            'id' => $postId,
            'data' => $post,
            // 兼容早期扩展上下文。
            'postId' => $postId,
            'post' => $post,
        ]);
        $data['contentHtml'] = \content_html($markdown, (string) ($post['content_format'] ?? 'markdown'));
        $response->getBody()->write(\render('post', $data));
        return $response;
    }

    private function findPublishedPost(string $slug): ?array
    {
        $post = DB::fetchOne(
            "SELECT p.*, COALESCE(NULLIF(u.nickname, ''), u.username) AS author_name, u.avatar_url AS author_avatar, u.email AS author_email,
                    c.name AS category_name, c.slug AS category_slug
             FROM posts p
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.slug = ? AND p.deleted_at IS NULL",
            [$slug]
        );
        if (!\Pafish\Services\Posts::isPublic($post)) return null;
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

    private function verifyPassword(string $input, string $hash): bool
    {
        // 按 PHP 支持的算法验证；bcrypt/Argon2 格式即使损坏也不能把哈希字符串当密码。
        if ((password_get_info($hash)['algoName'] ?? 'unknown') !== 'unknown'
            || str_starts_with($hash, '$2') || str_starts_with($hash, '$argon2')) {
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
        return Responses::json($response, $data, $status);
    }
}
