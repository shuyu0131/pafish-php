<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Core\RateLimiter;
use Pafish\Core\Session;
use Pafish\Core\Url;
use Pafish\Services\Settings;
use Pafish\Services\Slug;
use Pafish\Services\Points;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Core-owned account center; uses the same controls and layout as account settings. */
final class AccountController extends AdminController
{
    public function index(Request $request, Response $response): Response
    {
        Auth::requireLogin();
        $user = Auth::user() ?? [];
        $userId = (int) ($user['id'] ?? 0);
        $query = $request->getQueryParams();
        $section = is_string($query['section'] ?? null) ? $query['section'] : 'overview';
        $pointsEnabled = Points::available();
        $sections = ['overview' => '概览', 'posts' => '我的投稿', 'comments' => '我的评论', 'favorites' => '我的收藏', 'likes' => '我的点赞'];
        if ($pointsEnabled) $sections['points'] = '积分记录';
        if (!isset($sections[$section])) $section = 'overview';
        $public = "p.status = 'PUBLISHED' AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= NOW())";
        $base = Url::to('/admin');
        $sectionUrl = static function (string $section, int $page = 1, int $per = 10, string $status = '') use ($base): string {
            $query = compact('section', 'page', 'per');
            if ($status !== '') $query['status'] = $status;
            return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($query);
        };
        $postCount = (int) DB::value('SELECT COUNT(*) FROM posts WHERE author_id=? AND deleted_at IS NULL', [$userId]);
        $submissionCounts = ['' => $postCount];
        foreach (DB::fetchAll('SELECT status,COUNT(*) AS total FROM posts WHERE author_id=? AND deleted_at IS NULL GROUP BY status', [$userId]) as $row) {
            $submissionCounts[(string) $row['status']] = (int) $row['total'];
        }
        $canSubmit = Auth::can('posts.manage') || Settings::get('submissions_enabled', 'true') === 'true';
        $submissionStatus = is_string($query['status'] ?? null) ? $query['status'] : '';
        $submissionStatuses = ['PENDING', 'CHANGES_REQUESTED', 'DRAFT', 'PUBLISHED'];
        if ($section === 'posts' && !in_array($submissionStatus, $submissionStatuses, true)) $submissionStatus = '';
        $stats = [
            ['label' => '我的投稿', 'value' => $postCount, 'icon' => 'file-text', 'url' => $sectionUrl('posts')],
            ['label' => '我的评论', 'value' => (int) DB::value("SELECT COUNT(*) FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.user_id=? AND c.status <> 'SPAM' AND {$public}", [$userId]), 'icon' => 'message', 'url' => $sectionUrl('comments')],
            ['label' => '我的收藏', 'value' => (int) DB::value("SELECT COUNT(*) FROM post_reactions r JOIN posts p ON p.id=r.post_id WHERE r.user_id=? AND r.kind='favorite' AND {$public}", [$userId]), 'icon' => 'star', 'url' => $sectionUrl('favorites')],
            ['label' => '我的点赞', 'value' => (int) DB::value("SELECT COUNT(*) FROM post_reactions r JOIN posts p ON p.id=r.post_id WHERE r.user_id=? AND r.kind='like' AND {$public}", [$userId]), 'icon' => 'heart', 'url' => $sectionUrl('likes')],
        ];
        if ($pointsEnabled) $stats[] = ['label' => '我的积分', 'value' => Points::balance($userId), 'icon' => 'star', 'url' => $sectionUrl('points')];
        $perOptions = [10, 20, 50];
        $per = is_scalar($query['per'] ?? null) ? (int) $query['per'] : 10;
        if (!in_array($per, $perOptions, true)) $per = 10;
        $page = max(1, is_scalar($query['page'] ?? null) ? (int) $query['page'] : 1);
        $total = match ($section) {
            'posts' => $submissionStatus !== ''
                ? (int) DB::value('SELECT COUNT(*) FROM posts WHERE author_id=? AND deleted_at IS NULL AND status=?', [$userId, $submissionStatus])
                : $postCount,
            'comments' => (int) DB::value("SELECT COUNT(*) FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.user_id=? AND c.status <> 'SPAM' AND {$public}", [$userId]),
            'favorites' => (int) DB::value("SELECT COUNT(*) FROM post_reactions r JOIN posts p ON p.id=r.post_id WHERE r.user_id=? AND r.kind='favorite' AND {$public}", [$userId]),
            'likes' => (int) DB::value("SELECT COUNT(*) FROM post_reactions r JOIN posts p ON p.id=r.post_id WHERE r.user_id=? AND r.kind='like' AND {$public}", [$userId]),
            'points' => (int) DB::value('SELECT COUNT(*) FROM point_transactions WHERE user_id=?', [$userId]),
            default => 0,
        };
        $pages = max(1, (int) ceil($total / $per));
        $page = min($page, $pages);
        $offset = ($page - 1) * $per;
        $items = match ($section) {
            'comments' => DB::fetchAll("SELECT c.id,c.content,c.status,c.created_at,p.title,p.slug FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.user_id=? AND c.status <> 'SPAM' AND {$public} ORDER BY c.created_at DESC,c.id DESC LIMIT {$per} OFFSET {$offset}", [$userId]),
            'favorites', 'likes' => DB::fetchAll("SELECT p.title,p.slug,r.created_at FROM post_reactions r JOIN posts p ON p.id=r.post_id WHERE r.user_id=? AND r.kind=? AND {$public} ORDER BY r.created_at DESC,p.id DESC LIMIT {$per} OFFSET {$offset}", [$userId, $section === 'favorites' ? 'favorite' : 'like']),
            'points' => DB::fetchAll("SELECT amount,reason,created_at FROM point_transactions WHERE user_id=? ORDER BY id DESC LIMIT {$per} OFFSET {$offset}", [$userId]),
            'posts' => DB::fetchAll(
                'SELECT p.id,p.title,p.slug,p.status,p.review_note,p.reviewed_at,p.created_at,p.updated_at,p.published_at,
                        c.name AS category_name,reviewer.username AS reviewer_username,reviewer.nickname AS reviewer_nickname
                 FROM posts p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN users reviewer ON reviewer.id=p.reviewed_by
                 WHERE p.author_id=? AND p.deleted_at IS NULL' . ($submissionStatus !== '' ? ' AND p.status=?' : '') . '
                 ORDER BY p.updated_at DESC,p.id DESC LIMIT ' . $per . ' OFFSET ' . $offset,
                $submissionStatus !== '' ? [$userId, $submissionStatus] : [$userId]
            ),
            default => [],
        };
        $response->getBody()->write($this->render('user-dashboard', compact('stats', 'section', 'sections', 'items', 'page', 'pages', 'total', 'per', 'perOptions', 'sectionUrl', 'canSubmit', 'submissionStatus', 'submissionCounts'), '个人中心'));
        return $response;
    }

    public function newSubmission(Request $request, Response $response): Response
    {
        Auth::requireLogin();
        if (!Auth::can('posts.manage') && Settings::get('submissions_enabled', 'true') !== 'true') {
            $this->flash('info', '站点暂未开放用户投稿');
            return $this->redirect($response, '/admin?section=posts');
        }
        return $this->renderSubmissionEditor($request, $response, null);
    }

    public function editSubmission(Request $request, Response $response, array $args): Response
    {
        Auth::requireLogin();
        $post = DB::fetchOne('SELECT * FROM posts WHERE id=? AND author_id=? AND deleted_at IS NULL', [(int) ($args['id'] ?? 0), (int) Auth::id()]);
        if ($post === null) return $this->notFound($response);
        return $this->renderSubmissionEditor($request, $response, $post);
    }

    public function saveSubmission(Request $request, Response $response): Response
    {
        Auth::requireLogin();
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $id = filter_var($body['post_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
        $userId = (int) Auth::id();
        $isAjax = $this->isAjax($request);
        $editUrl = static fn (int $postId): string => $postId > 0 ? '/admin/submissions/' . $postId . '/edit' : '/admin/submissions/new';
        $errorResponse = function (string $message, int $status = 400) use ($isAjax, $response, $editUrl, $id): Response {
            if ($isAjax) return $this->json($response, ['error' => $message], $status);
            $this->flash('error', $message);
            return $this->redirect($response, $editUrl($id));
        };
        $stored = $id > 0 ? DB::fetchOne('SELECT * FROM posts WHERE id=? AND author_id=? AND deleted_at IS NULL', [$id, $userId]) : null;
        if ($id > 0 && $stored === null) return $this->notFound($response);
        if ($id === 0 && !Auth::can('posts.manage') && Settings::get('submissions_enabled', 'true') !== 'true') {
            return $errorResponse('站点暂未开放用户投稿');
        }
        $action = (string) ($body['action'] ?? 'publish');
        if (!in_array($action, ['draft', 'publish'], true)) {
            return $errorResponse('保存操作无效');
        }
        $requestToken = trim((string) ($body['_idempotency'] ?? ''));
        $replayed = Session::replay('account-post-submission', $requestToken);
        if ($replayed !== null) {
            if ($isAjax) return $this->json($response, [
                'ok' => true,
                'id' => (int) ($replayed['id'] ?? $id),
                'status' => (string) ($replayed['status'] ?? ''),
                'message' => (string) ($replayed['message'] ?? '投稿已保存'),
            ]);
            $this->flash('success', (string) ($replayed['message'] ?? '投稿已保存'));
            return $this->redirect($response, '/admin?section=posts');
        }
        if ($id > 0 && !Auth::can('posts.manage') && Settings::get('submissions_enabled', 'true') !== 'true' && $action === 'publish') {
            return $errorResponse('站点暂未开放新投稿或重新提交；可以保存为草稿或稍后再试');
        }
        if (!RateLimiter::allow('account-post-submission', (string) $userId, 10, 3600)) {
            return $errorResponse('投稿操作过于频繁，请稍后再试', 429);
        }
        $title = is_string($body['title'] ?? null) ? trim($body['title']) : '';
        $slug = (string) ($stored['slug'] ?? '');
        if ($slug === '') {
            $slug = Slug::resolveUnique(Slug::slugify($title), static fn (string $candidate): bool => DB::value('SELECT COUNT(*) FROM posts WHERE slug=?', [$candidate]) > 0);
        }
        $content = is_string($body['content'] ?? null) ? $body['content'] : '';
        $tagIds = array_key_exists('tag_ids', $body)
            ? $body['tag_ids']
            : ($id > 0 ? array_map('intval', array_column(DB::fetchAll('SELECT tag_id FROM post_tags WHERE post_id=?', [$id]), 'tag_id')) : []);
        try {
            $result = (new PostsController())->saveForCurrentUser([
                'action' => $action,
                'title' => $title,
                'slug' => $slug,
                'excerpt' => is_string($body['excerpt'] ?? null) ? $body['excerpt'] : '',
                'content' => $content,
                'content_format' => (string) ($stored['content_format'] ?? 'markdown'),
                'cover_url' => is_string($body['cover_url'] ?? null) ? $body['cover_url'] : (string) ($stored['cover_url'] ?? ''),
                'auto_cover' => is_scalar($body['auto_cover'] ?? null) ? (string) $body['auto_cover'] : '0',
                'category_id' => is_scalar($body['category_id'] ?? null) ? (string) $body['category_id'] : (string) ($stored['category_id'] ?? ''),
                'tag_ids' => $tagIds,
                'template' => is_string($body['template'] ?? null) ? $body['template'] : (string) ($stored['template'] ?? 'default'),
                'external_url' => (string) ($stored['external_url'] ?? ''),
                'allow_comments' => is_scalar($body['allow_comments'] ?? null) ? (string) $body['allow_comments'] : (int) ($stored['allow_comments'] ?? 1),
            ], $id > 0 ? $id : null);
        } catch (\PDOException $e) {
            \Pafish\Core\Logger::exception('用户投稿保存失败', $e);
            return $errorResponse('保存失败，请稍后重试', 500);
        } catch (\RuntimeException $e) {
            return $errorResponse($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $errorResponse($e->getMessage());
        }
        $message = match ((string) ($result['status'] ?? '')) {
            'PENDING' => '投稿已提交，审核通过后发布',
            'PUBLISHED' => $id > 0 ? '文章已更新' : '文章已发布',
            default => '草稿已保存',
        };
        Session::remember('account-post-submission', $requestToken, ['id' => $result['id'], 'status' => $result['status'], 'message' => $message]);
        if ($isAjax) return $this->json($response, ['ok' => true, 'id' => (int) $result['id'], 'status' => $result['status'], 'message' => $message]);
        $this->flash('success', $message);
        return $this->redirect($response, '/admin?section=posts');
    }

    public function deleteSubmission(Request $request, Response $response, array $args): Response
    {
        Auth::requireLogin();
        $id = (int) ($args['id'] ?? 0);
        if (DB::value('SELECT id FROM posts WHERE id=? AND author_id=? AND deleted_at IS NULL', [$id, (int) Auth::id()]) === null) {
            return $this->notFound($response);
        }
        try {
            (new PostsController())->moderateForCurrentUser($id, 'delete');
            $this->flash('success', '投稿已撤回');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->redirect($response, '/admin?section=posts');
    }

    private function renderSubmissionEditor(Request $request, Response $response, ?array $post): Response
    {
        return (new PostsController())->submissionEditor($request, $response, $post);
    }
}
