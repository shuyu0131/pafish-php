<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\DB;
use Pafish\Core\Session;
use Pafish\Core\Url;
use Pafish\Services\Categories;
use Pafish\Services\Settings;
use Pafish\Services\Slug;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 文章管理
 * - 列表：状态 tabs/分类树/5 种排序/搜索/分页（per_page cookie 记忆）/批量操作/回收站
 * - 编辑器：新建/编辑共用，保存动作 draft/publish/schedule/auto
 * - 写操作：AJAX 返回 JSON，普通表单 302 + flash（PRG）
 */
final class PostsController extends AdminController
{
    private const PER_PAGE_OPTIONS = [10, 20, 50];
    private const STATUS_LABEL = [
        'DRAFT' => '草稿', 'PENDING' => '待审核', 'CHANGES_REQUESTED' => '退回修改',
        'PUBLISHED' => '已发布', 'SCHEDULED' => '定时',
    ];

    // ---------- 列表 ----------

    public function index(Request $request, Response $response): Response
    {
        $this->guardCanManage();

        $perRaw = (int) ($_GET['per'] ?? 0);
        $perPref = (int) ($_COOKIE['admin_posts_per_page'] ?? 0);
        $per = in_array($perRaw, self::PER_PAGE_OPTIONS, true) ? $perRaw
            : (in_array($perPref, self::PER_PAGE_OPTIONS, true) ? $perPref : 20);

        $isTrash = ($_GET['status'] ?? '') === 'trash';
        $status = !$isTrash && in_array((string) ($_GET['status'] ?? ''), ['PUBLISHED', 'DRAFT', 'PENDING', 'CHANGES_REQUESTED', 'SCHEDULED'], true)
            ? (string) $_GET['status'] : null;
        $category = (string) ($_GET['category'] ?? '');
        $author = (string) ($_GET['author'] ?? '');
        $q = trim((string) ($_GET['q'] ?? ''));
        $sort = in_array((string) ($_GET['sort'] ?? ''), ['latest', 'updated', 'pinned', 'views', 'comments'], true)
            ? (string) $_GET['sort'] : 'latest';
        $page = max(1, (int) ($_GET['page'] ?? 1));

        // 构建筛选条件
        $where = $isTrash ? 'p.deleted_at IS NOT NULL' : 'p.deleted_at IS NULL';
        $params = [];
        if (!\Pafish\Core\Auth::isAdmin()) {
            $where .= ' AND p.author_id = ?';
            $params[] = (int) \Pafish\Core\Auth::id();
        }
        if ($status !== null) {
            $where .= ' AND p.status = ?';
            $params[] = $status;
        }
        if ($category === 'none') {
            $where .= ' AND p.category_id IS NULL';
        } elseif (ctype_digit($category)) {
            $where .= ' AND p.category_id = ?';
            $params[] = (int) $category;
        }
        if (ctype_digit($author) && (int) $author > 0) {
            $where .= ' AND p.author_id = ?';
            $params[] = (int) $author;
        } else {
            $author = '';
        }
        if ($q !== '') {
            $where .= ' AND (p.title LIKE ? OR p.content LIKE ?)';
            $params[] = "%{$q}%";
            $params[] = "%{$q}%";
        }

        $orderBy = match ($sort) {
            'updated' => 'p.updated_at DESC',
            'pinned' => 'p.is_pinned DESC, p.published_at DESC, p.id DESC',
            'views' => 'p.view_count DESC',
            'comments' => '(SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) DESC, p.id DESC',
            default => 'p.published_at DESC, p.id DESC',
        };

        $total = (int) DB::value("SELECT COUNT(*) FROM posts p WHERE {$where}", $params);
        $totalPages = max(1, (int) ceil($total / $per));
        $posts = DB::fetchAll(
            "SELECT p.*, c.name AS category_name, u.username AS author_username, reviewer.username AS reviewer_username,
                    (SELECT COUNT(*) FROM comments c2 WHERE c2.post_id = p.id) AS comment_count,
                    p.like_count, p.favorite_count
             FROM posts p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN users reviewer ON reviewer.id = p.reviewed_by
             WHERE {$where}
             ORDER BY {$orderBy}
             LIMIT " . $per . ' OFFSET ' . (($page - 1) * $per),
            $params
        );

        // tabs 计数（按状态分组 + 回收站）
        $counts = ['PUBLISHED' => 0, 'DRAFT' => 0, 'PENDING' => 0, 'CHANGES_REQUESTED' => 0, 'SCHEDULED' => 0, 'TRASH' => 0];
        $countWhere = 'deleted_at IS NULL';
        $countParams = [];
        if (!\Pafish\Core\Auth::isAdmin()) {
            $countWhere .= ' AND author_id = ?';
            $countParams[] = (int) \Pafish\Core\Auth::id();
        }
        foreach (DB::fetchAll("SELECT status, COUNT(*) AS c FROM posts WHERE {$countWhere} GROUP BY status", $countParams) as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }
        $counts['TRASH'] = (int) DB::value("SELECT COUNT(*) FROM posts WHERE deleted_at IS NOT NULL" . (!\Pafish\Core\Auth::isAdmin() ? ' AND author_id = ?' : ''), $countParams);

        // 分类树下拉
        $catTree = Categories::tree();

        $html = $this->render('posts', [
            'posts' => $posts,
            'total' => $total,
            'totalPages' => $totalPages,
            'page' => $page,
            'per' => $per,
            'perOptions' => self::PER_PAGE_OPTIONS,
            'params' => ['status' => $status ?? ($isTrash ? 'trash' : ''), 'category' => $category, 'author' => $author, 'q' => $q, 'sort' => $sort],
            'counts' => $counts,
            'isTrash' => $isTrash,
            'catTree' => $catTree,
            'statusLabel' => self::STATUS_LABEL,
        ], '文章管理');

        $response->getBody()->write($html);
        return $response;
    }

    // ---------- 编辑器 ----------

    public static function templateOptions(): array
    {
        $options = ['default' => '默认模板'];
        foreach (\Pafish\Services\Theme::postTemplates(\Pafish\Services\Theme::active()) as $template) {
            $options[$template['name']] = $template['title'];
        }
        return $options;
    }

    private static function templateForSave(mixed $value, ?array $stored): string
    {
        $template = \Pafish\Services\Content::templateName($value);
        if ($template !== ($stored['template'] ?? null) && !array_key_exists($template, self::templateOptions())) {
            throw new \RuntimeException('所选文章模板不可用，请重新选择');
        }
        return $template;
    }

    /** Markdown 批量导入页 */
    public function importPage(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $html = $this->render('import', [
            'uploadUrl' => Url::to('/api/import-markdown'),
            'csrf' => \Pafish\Core\Session::csrfToken(),
        ], '导入 Markdown');
        $response->getBody()->write($html);
        return $response;
    }

    public function createEditor(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        return $this->renderEditor($request, $response, null);
    }

    /** Authenticated, CSRF-protected title-to-slug preview shared by admin editors. */
    public function previewSlug(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $body = $request->getParsedBody();
        if (!Session::verifyCsrf(is_array($body) && is_string($body['_csrf'] ?? null) ? $body['_csrf'] : null)) {
            return $this->json($response, ['error' => '请求已过期，请刷新页面重试'], 419);
        }
        $title = is_array($body) && is_string($body['title'] ?? null) ? trim($body['title']) : '';
        if (mb_strlen($title, 'UTF-8') > 255) {
            return $this->json($response, ['error' => '标题过长'], 400);
        }
        return $this->json($response, ['slug' => $title === '' ? '' : Slug::slugify($title)]);
    }

    /** Keep the old administrator URL working while moving its queue into the article list. */
    public function legacySubmissions(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        return $this->redirect($response, '/admin/posts?status=PENDING');
    }

    /** Render the shared article editor with the restricted author-submission controls. */
    public function submissionEditor(Request $request, Response $response, ?array $post): Response
    {
        \Pafish\Core\Auth::requireLogin();
        if ($post !== null) {
            $owned = DB::fetchOne(
                'SELECT id FROM posts WHERE id=? AND author_id=? AND deleted_at IS NULL',
                [(int) ($post['id'] ?? 0), (int) \Pafish\Core\Auth::id()]
            );
            if ($owned === null) {
                return $this->notFound($response);
            }
        } elseif (!\Pafish\Core\Auth::can('posts.manage') && Settings::get('submissions_enabled', 'true') !== 'true') {
            return $this->redirect($response, '/admin?section=posts');
        }
        return $this->renderEditor($request, $response, $post, true);
    }

    public function editEditor(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $id = (int) ($args['id'] ?? 0);
        $post = DB::fetchOne(
            'SELECT * FROM posts WHERE id = ?' . (!\Pafish\Core\Auth::isAdmin() ? ' AND author_id = ?' : ''),
            \Pafish\Core\Auth::isAdmin() ? [$id] : [$id, (int) \Pafish\Core\Auth::id()]
        );
        if (!$post) {
            return $this->notFound($response);
        }
        return $this->renderEditor($request, $response, $post);
    }

    /** 保存文章。 */
    public function save(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $body = $request->getParsedBody() ?? [];
        $id = isset($args['id']) ? (int) $args['id'] : 0;
        $requestToken = trim((string) ($body['_idempotency'] ?? ''));

        try {
            // 网络重试或双击可能重复发送同一 POST；同一令牌只执行一次写入。
            $result = Session::replay('post_save', $requestToken);
            if ($result === null) {
                $result = $this->savePost($body, $id > 0 ? $id : null);
                Session::remember('post_save', $requestToken, $result);
            }
        } catch (\PDOException $e) {
            // 驱动异常只进日志：客户端拿到 SQLSTATE 与列名既是噪音，也泄露表结构。
            \Pafish\Core\Logger::exception('文章保存失败', $e);
            $message = '保存失败，请稍后重试';
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => $message], 500);
            }
            $this->flash('error', $message);
            return $this->redirect($response, $id > 0 ? "/admin/posts/{$id}/edit" : '/admin/posts/new');
        } catch (\RuntimeException $e) {
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, $id > 0 ? "/admin/posts/{$id}/edit" : '/admin/posts/new');
        }

        \Pafish\Core\Cache::clearPrefix('nav.');

        $published = (string) ($result['status'] ?? '') === 'PUBLISHED';
        $statusMessage = match ((string) ($result['status'] ?? '')) {
            'PENDING' => '投稿已提交，审核通过后发布',
            'CHANGES_REQUESTED' => '文章已保存，请按审核意见修改后重新提交',
            default => $published ? '文章已发布' : ($result['created'] ? '文章已保存' : '文章已更新'),
        };
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true, 'id' => $result['id'], 'status' => $result['status'], 'message' => $statusMessage, 'coverUrl' => $result['coverUrl'] ?? '', 'coverAuto' => (bool) ($result['coverAuto'] ?? false)]);
        }
        $this->flash('success', $statusMessage);
        return $this->redirect($response, $published ? '/admin/posts' : "/admin/posts/{$result['id']}/edit");
    }

    // ---------- 单行操作 ----------

    public function delete(Request $request, Response $response, array $args): Response
    {
        return $this->singleOp($request, $response, (int) ($args['id'] ?? 0), 'delete');
    }

    public function restore(Request $request, Response $response, array $args): Response
    {
        return $this->singleOp($request, $response, (int) ($args['id'] ?? 0), 'restore');
    }

    public function purge(Request $request, Response $response, array $args): Response
    {
        return $this->singleOp($request, $response, (int) ($args['id'] ?? 0), 'purge');
    }

    /** 批量操作（publish/draft/pin/unpin/delete/restore/purge/move，最多 100 个） */
    public function batch(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $body = $request->getParsedBody() ?? [];
        $rawIds = $body['ids'] ?? [];
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($rawIds) ? $rawIds : []))));
        $ids = array_slice($ids, 0, 100);
        if ($ids === []) {
            return $this->json($response, ['error' => '未选择文章'], 400);
        }
        if (!\Pafish\Core\Auth::isAdmin()) {
            $rawIn = implode(',', array_fill(0, count($ids), '?'));
            $visible = DB::fetchAll(
                "SELECT id FROM posts WHERE id IN ({$rawIn}) AND author_id = ?",
                array_merge($ids, [(int) \Pafish\Core\Auth::id()])
            );
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $visible);
            if ($ids === []) {
                return $this->json($response, ['error' => '没有可操作的文章'], 403);
            }
        }
        $op = (string) ($body['op'] ?? '');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $publishedRows = [];
        $commentEvents = [];

        try {
            switch ($op) {
                case 'publish':
                    $publishedRows = DB::fetchAll(
                        "SELECT * FROM posts WHERE id IN ({$in}) AND deleted_at IS NULL AND status <> 'PUBLISHED'",
                        $ids
                    );
                    DB::execute("UPDATE posts SET status='PUBLISHED',published_at=NOW(),review_note=NULL,reviewed_by=?,reviewed_at=NOW() WHERE id IN ({$in}) AND deleted_at IS NULL", array_merge([(int) (\Pafish\Core\Auth::id() ?? 0)], $ids));
                    break;
                case 'draft':
                    DB::execute("UPDATE posts SET status = 'DRAFT', published_at = NULL WHERE id IN ({$in}) AND deleted_at IS NULL", $ids);
                    break;
                case 'pin':
                    DB::execute("UPDATE posts SET is_pinned = 1 WHERE id IN ({$in})", $ids);
                    break;
                case 'unpin':
                    DB::execute("UPDATE posts SET is_pinned = 0 WHERE id IN ({$in})", $ids);
                    break;
                case 'delete':
                    DB::execute("UPDATE posts SET deleted_at = NOW() WHERE id IN ({$in}) AND deleted_at IS NULL", $ids);
                    break;
                case 'restore':
                    DB::execute("UPDATE posts SET deleted_at = NULL WHERE id IN ({$in})", $ids);
                    break;
                case 'purge':
                    $commentEvents = \Pafish\Services\MediaReferences::transaction(static function () use ($ids, $in): array {
                        $events = [];
                        $rows = DB::fetchAll("SELECT id FROM posts WHERE id IN ({$in}) AND deleted_at IS NOT NULL ORDER BY id FOR UPDATE", $ids);
                        foreach ($rows as $row) {
                            array_push($events, ...\Pafish\Services\Comments::deleteForPost((int) $row['id']));
                            \Pafish\Services\EntityMeta::deleteSubject('post', (int) $row['id']);
                            foreach (DB::fetchAll('SELECT id FROM nav_items WHERE post_id = ? FOR UPDATE', [(int) $row['id']]) as $nav) {
                                \Pafish\Services\MediaReferences::forget('nav', (int) $nav['id']);
                            }
                            DB::execute('DELETE FROM posts WHERE id = ?', [$row['id']]);
                            \Pafish\Services\MediaReferences::forget('post', (int) $row['id']);
                        }
                        return $events;
                    });
                    break;
                case 'move':
                    $rawCat = (string) ($body['category_id'] ?? '');
                    $categoryId = $rawCat === 'none' ? null : (ctype_digit($rawCat) ? (int) $rawCat : null);
                    if ($rawCat === '') {
                        return $this->json($response, ['error' => '请选择分类'], 400);
                    }
                    if ($categoryId !== null && !DB::fetchOne('SELECT id FROM categories WHERE id = ?', [$categoryId])) {
                        return $this->json($response, ['error' => '分类不存在'], 400);
                    }
                    DB::execute("UPDATE posts SET category_id = ? WHERE id IN ({$in})", array_merge([$categoryId], $ids));
                    break;
                default:
                    return $this->json($response, ['error' => '操作失败'], 400);
            }
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => '操作失败'], 500);
        }

        \Pafish\Services\Comments::emit($commentEvents);
        \Pafish\Core\Cache::clearPrefix('nav.');
        foreach ($publishedRows as $row) {
            $payload = self::postPayloadFromRow($row, 'batch', (string) $row['status']);
            $payload['status'] = 'PUBLISHED';
            $payload['publishedAt'] = date('Y-m-d H:i:s');
            $payload['trigger'] = 'batch';
            \do_action('after_post_published', $payload);
        }

        if (!$this->isAjax($request)) {
            $this->flash('success', '操作成功');
            return $this->redirect($response, '/admin/posts');
        }
        return $this->json($response, ['ok' => true]);
    }

    /** Approve a user submission or return it with a concrete revision request. */
    public function review(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $decision = is_string($body['decision'] ?? null) ? $body['decision'] : '';
        $note = is_string($body['review_note'] ?? null) ? trim($body['review_note']) : '';
        $errorResponse = function (string $message, int $status) use ($request, $response): Response {
            if ($this->isAjax($request)) return $this->json($response, ['error' => $message], $status);
            $this->flash('error', $message);
            return $this->redirect($response, '/admin/posts?status=PENDING');
        };
        if (!in_array($decision, ['publish', 'changes_requested'], true)) {
            return $errorResponse('审核操作无效', 400);
        }
        if ($decision === 'changes_requested' && ($note === '' || mb_strlen($note) > 2000)) {
            return $errorResponse('请填写审核意见，最多 2000 个字符', 400);
        }
        $id = (int) ($args['id'] ?? 0);
        try {
            $result = DB::transaction(static function () use ($id, $decision, $note): array {
                $post = DB::fetchOne('SELECT * FROM posts WHERE id=? AND deleted_at IS NULL FOR UPDATE', [$id]);
                if ($post === null || !in_array((string) $post['status'], ['PENDING', 'CHANGES_REQUESTED'], true)) {
                    throw new \RuntimeException('这篇文章当前不在待审核状态');
                }
                $reviewedBy = (int) (\Pafish\Core\Auth::id() ?? 0);
                if ($decision === 'publish') {
                    DB::execute("UPDATE posts SET status='PUBLISHED',published_at=NOW(),review_note=NULL,reviewed_by=?,reviewed_at=NOW() WHERE id=?", [$reviewedBy, $id]);
                    $saved = DB::fetchOne('SELECT * FROM posts WHERE id=?', [$id]);
                    $payload = self::postPayloadFromRow($saved, 'review_publish', (string) $post['status']);
                    DB::afterCommit(static fn () => \do_action('after_post_published', $payload));
                    return ['status' => 'PUBLISHED'];
                }
                DB::execute("UPDATE posts SET status='CHANGES_REQUESTED',published_at=NULL,review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?", [$note, $reviewedBy, $id]);
                return ['status' => 'CHANGES_REQUESTED'];
            });
        } catch (\PDOException $e) {
            \Pafish\Core\Logger::exception('投稿审核失败', $e);
            return $errorResponse('审核失败，请稍后重试', 500);
        } catch (\RuntimeException $e) {
            return $errorResponse($e->getMessage(), 409);
        }
        \Pafish\Core\Cache::clearPrefix('nav.');
        if (!$this->isAjax($request)) {
            $this->flash('success', $result['status'] === 'PUBLISHED' ? '投稿已通过并发布' : '已退回作者修改');
            return $this->redirect($response, '/admin/posts?status=PENDING');
        }
        return $this->json($response, ['ok' => true, 'status' => $result['status']]);
    }

    // ---------- 核心保存逻辑 ----------

    /**
     * 创建/更新文章。$id 为 null 创建；返回 ['id','status','created']
     * @throws \RuntimeException 中文错误信息
     */
    public function savePost(array $body, ?int $id = null): array
    {
        return \Pafish\Services\MediaReferences::transaction(function () use ($body, $id): array {
            return $this->persistPost($body, $id);
        });
    }

    /** Save a front-end post for the authenticated owner; managers may edit other authors. */
    public function saveForCurrentUser(array $body, ?int $id = null): array
    {
        if (!\Pafish\Core\Auth::check()) {
            throw new \RuntimeException('请先登录');
        }
        $allowManagerEdit = \Pafish\Core\Auth::can('posts.manage');
        $submissionOpen = Settings::get('submissions_enabled', 'true') === 'true';
        if (!$allowManagerEdit && (!$submissionOpen && ($id === null || (string) ($body['action'] ?? 'draft') === 'publish'))) {
            throw new \RuntimeException('当前站点已关闭用户投稿');
        }
        $result = \Pafish\Services\MediaReferences::transaction(function () use ($body, $id, $allowManagerEdit): array {
            return $this->persistPost($body, $id, $allowManagerEdit, !$allowManagerEdit);
        });
        \Pafish\Core\Cache::clearPrefix('nav.');
        return $result;
    }

    /** Front-end status changes preserve content and use the same lifecycle as admin operations. */
    public function moderateForCurrentUser(int $id, string $operation): array
    {
        if (!\Pafish\Core\Auth::check()) throw new \RuntimeException('请先登录');
        if (!in_array($operation, ['draft', 'publish', 'delete'], true)) throw new \InvalidArgumentException('操作无效');
        $result = DB::transaction(static function () use ($id, $operation): array {
            $manager = \Pafish\Core\Auth::can('posts.manage');
            $post = DB::fetchOne(
                'SELECT * FROM posts WHERE id=? AND deleted_at IS NULL' . ($manager ? '' : ' AND author_id=?') . ' FOR UPDATE',
                $manager ? [$id] : [$id, (int) \Pafish\Core\Auth::id()]
            );
            if ($post === null) throw new \RuntimeException('文章不存在或无权操作');
            if ($operation === 'delete') {
                DB::execute('UPDATE posts SET deleted_at=NOW() WHERE id=?', [$id]);
                $payload = self::postPayloadFromRow($post);
                DB::afterCommit(static fn () => \do_action('after_delete_post', $payload));
                return ['deleted' => true];
            }
            if (!$manager && $operation === 'publish' && Settings::get('submissions_enabled', 'true') !== 'true') {
                throw new \RuntimeException('当前站点已关闭用户投稿');
            }
            $reviewRequired = !$manager && Settings::get('submissions_need_review', 'true') !== 'false';
            $status = $operation === 'draft' ? 'DRAFT' : ($reviewRequired ? 'PENDING' : 'PUBLISHED');
            if ($post['status'] !== $status) {
                if ($status === 'PENDING') {
                    DB::execute("UPDATE posts SET status='PENDING',published_at=NULL,review_note=NULL,reviewed_by=NULL,reviewed_at=NULL WHERE id=?", [$id]);
                } elseif ($status === 'PUBLISHED' && $manager) {
                    DB::execute("UPDATE posts SET status='PUBLISHED',published_at=NOW(),review_note=NULL,reviewed_by=?,reviewed_at=NOW() WHERE id=?", [(int) (\Pafish\Core\Auth::id() ?? 0), $id]);
                } else {
                    DB::execute('UPDATE posts SET status=?,published_at=? WHERE id=?', [$status, $status === 'DRAFT' ? null : date('Y-m-d H:i:s'), $id]);
                }
                if ($status === 'PUBLISHED') {
                    $saved = DB::fetchOne('SELECT * FROM posts WHERE id=?', [$id]);
                    $payload = self::postPayloadFromRow($saved, 'publish', (string) $post['status']);
                    DB::afterCommit(static fn () => \do_action('after_post_published', $payload));
                }
            }
            return ['draft' => $status === 'DRAFT' ? 'y' : 'n', 'status' => $status];
        });
        \Pafish\Core\Cache::clearPrefix('nav.');
        return $result;
    }

    private function persistPost(array $body, ?int $id = null, bool $allowManagerEdit = false, bool $userSubmission = false): array
    {
        $action = (string) ($body['action'] ?? 'draft');
        if ($userSubmission && !in_array($action, ['draft', 'publish', 'auto'], true)) {
            throw new \RuntimeException('普通用户只能保存草稿或提交审核');
        }
        $mayEditAnyAuthor = \Pafish\Core\Auth::isAdmin()
            || ($allowManagerEdit && \Pafish\Core\Auth::can('posts.manage'));
        $stored = $id !== null ? DB::fetchOne('SELECT * FROM posts WHERE id = ? FOR UPDATE', [$id]) : null;
        if ($id !== null && $stored === null) throw new \RuntimeException('文章不存在');
        if ($id !== null && !$mayEditAnyAuthor) {
            $owned = DB::fetchOne('SELECT id FROM posts WHERE id = ? AND author_id = ?' . ($userSubmission ? ' AND deleted_at IS NULL' : ''), [$id, (int) \Pafish\Core\Auth::id()]);
            if ($owned === null) {
                throw new \RuntimeException('无权编辑这篇文章');
            }
        }

        if ($userSubmission) {
            // 投稿者只能改写作内容与分类标签；管理字段始终取已保存值。
            [$manualCover, $autoCoverOn] = $stored !== null ? self::coverEditorState($stored) : ['', false, ''];
            $storedCustomFields = $stored !== null
                ? \Pafish\Services\EntityMeta::postCustomFields($id, $stored['custom_fields'] ?? null)
                : [];
            $body['slug'] = (string) ($stored['slug'] ?? '');
            $body['template'] = is_string($body['template'] ?? null) ? $body['template'] : (string) ($stored['template'] ?? 'default');
            $body['cover_url'] = is_string($body['cover_url'] ?? null) ? $body['cover_url'] : $manualCover;
            $body['auto_cover'] = is_scalar($body['auto_cover'] ?? null) ? (string) $body['auto_cover'] : ($autoCoverOn ? '1' : '0');
            $body['external_url'] = (string) ($stored['external_url'] ?? '');
            $body['allow_comments'] = is_scalar($body['allow_comments'] ?? null) ? (string) $body['allow_comments'] : (int) ($stored['allow_comments'] ?? 1);
            $body['seo_title'] = (string) ($stored['seo_title'] ?? '');
            $body['meta_description'] = (string) ($stored['meta_description'] ?? '');
            $body['custom_fields'] = $storedCustomFields === [] ? '' : json_encode($storedCustomFields, JSON_UNESCAPED_UNICODE);
            $body['new_category'] = '';
            $body['new_tags'] = [];
            $body['password'] = '';
            // Older extension-backed posts may store their body in structured fields.
            // Only trust data already persisted for this owned post, never a client flag.
            $body['extension_content'] = $stored !== null && (
                $storedCustomFields !== []
                || (int) DB::value("SELECT COUNT(*) FROM entity_meta WHERE subject_type='post' AND subject_id=? AND scope<>'core'", [$id]) > 0
            );
            unset($body['remove_password'], $body['extension_meta'], $body['plugins']);
        }

        $seo = \Pafish\Services\Content::seoFields($body, $stored ?? []);
        $allowComments = $body['allow_comments'] ?? $stored['allow_comments'] ?? 1;
        if (!in_array($allowComments, [true,false,1,0,'1','0'], true)) throw new \RuntimeException('评论开关无效');
        $template = self::templateForSave($body['template'] ?? ($stored['template'] ?? 'default'), $stored);

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException('标题不能为空');
        }
        if (mb_strlen($title) > 255) {
            throw new \RuntimeException('标题不能超过 255 个字符');
        }
        // 外链要在这里读：导航站/书签站的条目只有标题 + 外链、没有自己的详情正文，
        // 所以「填了外链」是允许正文为空的唯一条件，必须赶在正文校验之前拿到。
        $externalUrl = trim((string) ($body['external_url'] ?? ''));
        if (mb_strlen($externalUrl) > 500) {
            $externalUrl = '';
        }
        $contentFormat = \Pafish\Services\Content::format($body['content_format'] ?? ($id !== null ? (DB::value('SELECT content_format FROM posts WHERE id = ?', [$id]) ?: 'markdown') : 'markdown'));
        $content = (string) ($body['content'] ?? '');
        if ($contentFormat === 'html') $content = \Pafish\Services\Content::sanitize($content);
        $extensionMeta = null;
        if (array_key_exists('extension_meta', $body)) {
            $extensionMeta = is_array($body['extension_meta']) ? $body['extension_meta'] : json_decode((string) $body['extension_meta'], true);
            if (!is_array($extensionMeta)) throw new \RuntimeException('扩展字段数据格式无效');
        }
        // An extension must explicitly attest that its structured fields carry the
        // post body. Merely selecting a type or toggle must never bypass the
        // ordinary non-empty-content check.
        $hasExtensionContent = !empty($body['extension_content']);
        if (trim($content) === '' && $externalUrl === '' && !$hasExtensionContent) {
            throw new \RuntimeException('内容不能为空（若这是纯外链条目，请填写外链地址）');
        }
        if (mb_strlen($content) > 16000000) {
            throw new \RuntimeException('内容过长');
        }

        $slug = Slug::forSave(
            array_key_exists('slug', $body) ? (string) $body['slug'] : null,
            $title,
            $stored !== null ? (string) $stored['slug'] : null,
            static fn (string $candidate): bool => DB::value(
                'SELECT COUNT(*) FROM posts WHERE slug = ?' . ($id !== null ? ' AND id <> ?' : ''),
                $id !== null ? [$candidate, $id] : [$candidate]
            ) > 0,
            !empty($body['slug_auto'])
        );
        if (mb_strlen($slug, 'UTF-8') > 255) throw new \RuntimeException('文章地址不能超过 255 字');

        // 自动取图偏好与封面来源分别保存：手动地址优先，自动封面不能变成手动输入。
        // 缺省控件保留原有设置；关闭自动取图只清除派生封面，不影响手动封面。
        $excerpt = trim((string) ($body['excerpt'] ?? ''));
        [$manualCover, $storedAutoCoverOn] = $stored !== null ? self::coverEditorState($stored) : ['', false, ''];
        $coverUrl = (string) \Pafish\Services\Content::imageUrl($body['cover_url'] ?? $manualCover);
        $autoCover = $body['auto_cover'] ?? $storedAutoCoverOn;
        if (!in_array($autoCover, [true, false, 1, 0, '1', '0'], true)) throw new \RuntimeException('自动封面选项无效');
        $autoCoverOn = in_array($autoCover, [true, 1, '1'], true);
        $coverAuto = false;
        if ($excerpt === '') {
            $excerpt = self::extractSummary($content, 180, $contentFormat);
        }
        if (mb_strlen($excerpt) > 500) {
            $excerpt = mb_substr($excerpt, 0, 500);
        }

        // 分类：newCategory 优先于 categoryId
        $categoryId = null;
        $newCategory = trim((string) ($body['new_category'] ?? ''));
        if ($newCategory !== '' && !\Pafish\Core\Auth::isAdmin()) {
            throw new \RuntimeException('编辑不能新建分类，请选择已有分类');
        }
        if ($newCategory !== '') {
            $categoryId = self::resolveNewCategory($newCategory);
        } else {
            $rawCat = (string) ($body['category_id'] ?? '');
            $categoryId = ctype_digit($rawCat) && (int) $rawCat > 0 ? (int) $rawCat : null;
        }
        if ($categoryId !== null && DB::value('SELECT id FROM categories WHERE id=?', [$categoryId]) === null) {
            throw new \RuntimeException('所选分类不存在');
        }

        // 标签：tagIds 已存在 + newTags 按名称匹配/新建
        $tagIds = self::parseIntList($body['tag_ids'] ?? []);
        $newTags = self::parseStringList($body['new_tags'] ?? []);
        if ($newTags !== [] && !\Pafish\Core\Auth::isAdmin()) {
            throw new \RuntimeException('编辑不能新建标签，请选择已有标签');
        }
        $tagIds = array_values(array_unique(array_merge($tagIds, self::resolveNewTags($newTags))));

        $canManagePosts = \Pafish\Core\Auth::can('posts.manage');
        $isPinned = $userSubmission ? (bool) ($stored['is_pinned'] ?? false) : ($canManagePosts && !empty($body['is_pinned']));
        $categoryPinned = $userSubmission ? (bool) ($stored['category_pinned'] ?? false) : ($canManagePosts && !empty($body['category_pinned']));
        // $externalUrl 已在正文校验前读取（纯外链条目允许正文为空）。

        // 状态与发布时间
        $status = 'DRAFT';
        $publishedAt = null;
        if ($action === 'publish') {
            $reviewRequired = $userSubmission && Settings::get('submissions_need_review', 'true') !== 'false';
            $status = $reviewRequired ? 'PENDING' : 'PUBLISHED';
            $publishedAt = $reviewRequired ? null : date('Y-m-d H:i:s');
        } elseif ($action === 'schedule') {
            $scheduledAt = (string) ($body['scheduled_at'] ?? '');
            if ($scheduledAt === '') {
                throw new \RuntimeException('定时发布需要选择时间');
            }
            $at = strtotime(str_replace('T', ' ', $scheduledAt));
            if ($at === false || $at <= time()) {
                throw new \RuntimeException('定时发布时间必须晚于当前时间');
            }
            $status = 'SCHEDULED';
            $publishedAt = date('Y-m-d H:i:s', $at);
        }

        // 密码三态：新值 → bcrypt；removePassword → null；两者皆无 → 保持原值
        $password = null;
        $passwordSet = false;
        $newPass = (string) ($body['password'] ?? '');
        if (trim($newPass) !== '') {
            if (strlen(trim($newPass)) > 72 || str_contains($newPass, "\0")) throw new \RuntimeException('文章密码过长或含无效字符，最多72字节');
            $password = password_hash(trim($newPass), PASSWORD_BCRYPT);
            $passwordSet = true;
        } elseif (!empty($body['remove_password'])) {
            $password = null;
            $passwordSet = true;
        }

        // 自定义字段（JSON 数组 [{key,value}]）
        $customFields = self::parseCustomFields((string) ($body['custom_fields'] ?? ''));

        $authorId = \Pafish\Core\Auth::id();
        $now = date('Y-m-d H:i:s');
        $extensions = self::pluginExtensions($body['plugins'] ?? []);
        $previousStatus = null;
        $reviewNote = $stored['review_note'] ?? null;
        $reviewedBy = $stored['reviewed_by'] ?? null;
        $reviewedAt = $stored['reviewed_at'] ?? null;
        if ($userSubmission && $stored !== null && $action === 'auto' && $stored['status'] === 'PUBLISHED'
            && Settings::get('submissions_need_review', 'true') !== 'false') {
            $status = 'PENDING';
            $publishedAt = null;
        }
        if ($userSubmission && in_array($action, ['publish', 'auto'], true) && $status === 'PENDING') {
            $reviewNote = $reviewedBy = $reviewedAt = null;
        } elseif ($userSubmission && $action === 'publish') {
            $reviewNote = $reviewedBy = $reviewedAt = null;
        } elseif (!$userSubmission && $action === 'publish' && in_array((string) ($stored['status'] ?? ''), ['PENDING', 'CHANGES_REQUESTED'], true)) {
            $reviewNote = null;
            $reviewedBy = (int) (\Pafish\Core\Auth::id() ?? 0);
            $reviewedAt = $now;
        }

        // 文章保存前过滤器：扩展可返回更新后的字段；未知字段不会进入核心写入。
        $before = \apply_decision_filters('before_post_save', [
            'id' => $id !== null ? (string) $id : null,
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $excerpt,
            'content' => $content,
            'contentFormat' => $contentFormat,
            'template' => $template,
            'status' => $status,
            'publishedAt' => $publishedAt,
            'categoryId' => $categoryId,
            'externalUrl' => $externalUrl,
            'isPinned' => $isPinned,
            'categoryPinned' => $categoryPinned,
            'allowComments' => (bool) $allowComments,
            'seoTitle' => $seo['seo_title'],
            'metaDescription' => $seo['meta_description'],
            'action' => $action,
        ], ['id' => $id !== null ? (string) $id : null, 'created' => $id === null]);
        if ($before === false || (is_array($before) && ($before['allowed'] ?? true) === false)) {
            throw new \RuntimeException(is_array($before) ? (string) ($before['error'] ?? '文章保存被扩展拒绝') : '文章保存被扩展拒绝');
        }
        if (is_array($before)) {
            $seo = \Pafish\Services\Content::seoFields([
                'seo_title' => $before['seoTitle'] ?? $seo['seo_title'],
                'meta_description' => $before['metaDescription'] ?? $seo['meta_description'],
            ]);
            $allowComments = $before['allowComments'] ?? $allowComments;
            if (!in_array($allowComments, [true,false,1,0,'1','0'], true)) throw new \RuntimeException('文章保存前过滤器返回了无效评论开关');
            foreach (['title', 'slug', 'excerpt', 'content', 'externalUrl'] as $field) {
                if (array_key_exists($field, $before) && is_scalar($before[$field])) {
                    ${$field} = trim((string) $before[$field]);
                }
            }
            $slug = Slug::normalize((string) $slug);
            $contentFormat = \Pafish\Services\Content::format($before['contentFormat'] ?? $contentFormat);
            $template = self::templateForSave($before['template'] ?? $template, $stored);
            if ($contentFormat === 'html') $content = \Pafish\Services\Content::sanitize($content);
            // 正文为空只在「同时没有外链」时才非法：过滤器可能把外链补上或清掉，
            // 所以这里要用过滤后的值重新判断，不能沿用入口处的结论。
            if (($content === '' && $externalUrl === '' && !$hasExtensionContent)
                || mb_strlen($content) > 16000000 || $title === '' || mb_strlen($title) > 255
                || $slug === '' || mb_strlen($slug) > 255
                || mb_strlen($excerpt) > 500 || mb_strlen($externalUrl) > 500) {
                throw new \RuntimeException('文章保存前过滤器返回了无效内容');
            }
            $slugConflict = $id !== null
                ? DB::value('SELECT COUNT(*) FROM posts WHERE slug = ? AND id != ?', [$slug, $id]) > 0
                : DB::value('SELECT COUNT(*) FROM posts WHERE slug = ?', [$slug]) > 0;
            if ($slugConflict) {
                throw new \RuntimeException('别名已被使用，请更换');
            }
        }

        // 扩展可能在保存前改写正文；从最终入库内容提取，避免封面与正文首图不一致。
        if ($coverUrl === '' && $autoCoverOn) {
            try {
                $coverUrl = (string) \Pafish\Services\Content::imageUrl((string) self::extractCover($content, $contentFormat));
                $coverAuto = $coverUrl !== '';
            } catch (\RuntimeException) {
                // A relative image in the body must not create a cover relative to the admin URL.
                $coverUrl = '';
            }
        }

        if ($id === null) {
            $created = true;
            $pdo = DB::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO posts (title, slug, excerpt, content, content_format, cover_url, status, review_note, reviewed_by, reviewed_at, published_at,
                    is_pinned, category_pinned, password, external_url, custom_fields, author_id, category_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $title, $slug, $excerpt, $content, $contentFormat, $coverUrl !== '' ? $coverUrl : null,
                $status, $reviewNote, $reviewedBy, $reviewedAt, $publishedAt, $isPinned ? 1 : 0, $categoryPinned ? 1 : 0,
                $password, $externalUrl !== '' ? $externalUrl : null, $customFields,
                $authorId, $categoryId, $now, $now,
            ]);
            $id = (int) $pdo->lastInsertId();
            \Pafish\Services\PermalinkRedirects::forgetPaths(\Pafish\Services\Permalinks::paths('post', [
                'slug' => $slug,
                'published_at' => $publishedAt,
                'created_at' => $now,
            ]));
            \Pafish\Services\EntityMeta::replace('post', $id, \Pafish\Services\EntityMeta::POST_FIELDS_SCOPE,
                is_array($decodedFields = json_decode((string) $customFields, true)) ? $decodedFields : []);
            if ($extensionMeta !== null) \Pafish\Services\EntityMeta::saveEditorFields('post', $id, $extensionMeta);
            DB::execute('UPDATE posts SET allow_comments=?,seo_title=?,meta_description=?,template=? WHERE id=?',
                [(int) (bool) $allowComments,$seo['seo_title'],$seo['meta_description'],$template,$id]);
            self::replaceTags($id, $tagIds);
            \Pafish\Services\MediaReferences::sync('post', $id);
            $payload = self::postPayload($id, $title, $slug, $status, $publishedAt, $categoryId, $externalUrl, $isPinned, $categoryPinned, $action, null, $seo + ['allow_comments' => $allowComments, 'template' => $template]);
        } else {
            $created = false;
            $existing = DB::fetchOne(
                'SELECT * FROM posts WHERE id = ?' . ($mayEditAnyAuthor ? '' : ' AND author_id = ?'),
                $mayEditAnyAuthor ? [$id] : [$id, (int) \Pafish\Core\Auth::id()]
            );
            if (!$existing) {
                throw new \RuntimeException('文章不存在');
            }
            $previousStatus = (string) $existing['status'];
            // auto（自动保存）：保留原状态与发布时间；投稿者编辑已发布内容时重新进入审核。
            if ($action === 'auto' && !($userSubmission && $existing['status'] === 'PUBLISHED' && Settings::get('submissions_need_review', 'true') !== 'false')) {
                $status = $existing['status'];
                $publishedAt = $existing['published_at'];
            }
            if (!$passwordSet) {
                $password = $existing['password'];
            }
            \Pafish\Services\PermalinkRedirects::recordEntityChange('post', $existing, array_replace($existing, [
                'slug' => $slug,
                'published_at' => $publishedAt ?? ($existing['published_at'] ?? null),
            ]));
            DB::execute(
                'UPDATE posts SET title = ?, slug = ?, excerpt = ?, content = ?, content_format = ?, cover_url = ?, status = ?,
                    review_note = ?, reviewed_by = ?, reviewed_at = ?, published_at = ?, is_pinned = ?, category_pinned = ?, password = ?, external_url = ?,
                    custom_fields = ?, category_id = ?, updated_at = ?
                 WHERE id = ?',
                [
                    $title, $slug, $excerpt, $content, $contentFormat, $coverUrl !== '' ? $coverUrl : null,
                    $status, $reviewNote, $reviewedBy, $reviewedAt, $publishedAt, $isPinned ? 1 : 0, $categoryPinned ? 1 : 0,
                    $password, $externalUrl !== '' ? $externalUrl : null, $customFields,
                    $categoryId, $now, $id,
                ]
            );
            DB::execute('UPDATE posts SET allow_comments=?,seo_title=?,meta_description=?,template=? WHERE id=?',
                [(int) (bool) $allowComments,$seo['seo_title'],$seo['meta_description'],$template,$id]);
            \Pafish\Services\EntityMeta::replace('post', $id, \Pafish\Services\EntityMeta::POST_FIELDS_SCOPE,
                is_array($decodedFields = json_decode((string) $customFields, true)) ? $decodedFields : []);
            if ($extensionMeta !== null) \Pafish\Services\EntityMeta::saveEditorFields('post', $id, $extensionMeta);
            self::replaceTags($id, $tagIds);
            \Pafish\Services\MediaReferences::sync('post', $id);
            $payload = self::postPayload($id, $title, $slug, $status, $publishedAt, $categoryId, $externalUrl, $isPinned, $categoryPinned, $action, $previousStatus, $seo + ['allow_comments' => $allowComments, 'template' => $template]);
        }

        // 开关是逐篇偏好；来源单独记录，避免「手动封面 + 自动兜底」重开后丢失手动地址。
        \Pafish\Services\EntityMeta::set('post', $id, \Pafish\Services\EntityMeta::scope('core'), 'auto_cover', $autoCoverOn ? '1' : '0');
        \Pafish\Services\EntityMeta::set('post', $id, \Pafish\Services\EntityMeta::scope('core'), 'cover_source',
            $coverAuto ? 'auto' : ($coverUrl !== '' ? 'manual' : 'none'));
        \do_action($created ? 'after_create_post' : 'after_update_post', $payload);

        if ($status === 'PUBLISHED' && $previousStatus !== 'PUBLISHED') {
            $publishedPayload = $payload;
            $publishedPayload['trigger'] = $created ? 'create' : 'update';
            \do_action('after_post_published', $publishedPayload);
        }
        \do_action('after_post_save', [
            'post' => $payload,
            'created' => $created,
            'action' => $action,
            'extensions' => $extensions,
        ]);

        return ['id' => $id, 'status' => $status, 'created' => $created, 'coverUrl' => $coverUrl, 'coverAuto' => $coverAuto];
    }

    private function renderEditor(Request $request, Response $response, ?array $post, bool $submissionMode = false): Response
    {
        $isEdit = $post !== null;
        $initial = $post ?: [
            'title' => '', 'slug' => '', 'excerpt' => '', 'content' => '', 'cover_url' => null,
            'status' => 'DRAFT', 'is_pinned' => 0, 'category_pinned' => 0,
            'password' => null, 'external_url' => null, 'category_id' => null,
            'allow_comments' => 1, 'template' => 'default', 'content_format' => 'markdown',
        ];
        $customFields = $post ? \Pafish\Services\EntityMeta::postCustomFields((int) $post['id'], $post['custom_fields'] ?? null) : [];
        $submissionHasExtensionContent = $submissionMode && $post !== null && trim((string) ($post['content'] ?? '')) === '' && (
            $customFields !== []
            || (int) DB::value("SELECT COUNT(*) FROM entity_meta WHERE subject_type='post' AND subject_id=? AND scope<>'core'", [(int) $post['id']]) > 0
        );

        // 封面地址框只装手动封面，自动取到的那张走预览
        [$initial['cover_url'], $coverAuto, $autoCoverUrl] = $isEdit ? self::coverEditorState($post) : ['', false, ''];

        // 已选标签 id 集
        $tagIds = [];
        if ($isEdit) {
            foreach (DB::fetchAll('SELECT tag_id FROM post_tags WHERE post_id = ?', [$post['id']]) as $row) {
                $tagIds[] = (int) $row['tag_id'];
            }
        }
        $tags = DB::fetchAll('SELECT * FROM tags ORDER BY name ASC');

        $canSubmit = \Pafish\Core\Auth::can('posts.manage') || Settings::get('submissions_enabled', 'true') === 'true';
        $reviewRequired = !\Pafish\Core\Auth::can('posts.manage') && Settings::get('submissions_need_review', 'true') !== 'false';
        $html = $this->render('post-editor', [
            'post' => $initial,
            'templateOptions' => self::templateOptions(),
            'isEdit' => $isEdit,
            'submissionMode' => $submissionMode,
            'submissionHasExtensionContent' => $submissionHasExtensionContent,
            'submissionCanSubmit' => $canSubmit,
            'submissionReviewRequired' => $reviewRequired,
            'submissionStatusLabel' => self::STATUS_LABEL[(string) ($initial['status'] ?? 'DRAFT')] ?? (string) ($initial['status'] ?? 'DRAFT'),
            'postId' => $isEdit ? (int) $post['id'] : 0,
            'catTree' => Categories::tree(),
            'tags' => $tags,
            'tagIds' => $tagIds,
            'customFields' => $customFields,
            'coverAuto' => $coverAuto,
            'autoCoverUrl' => $autoCoverUrl,
            'hasPassword' => $isEdit && $initial['password'] !== null,
            'isScheduled' => $isEdit && $initial['status'] === 'SCHEDULED',
            'statusLabel' => self::STATUS_LABEL,
            'headExtra' => editor_head_extra() . ($submissionMode ? '' : \Pafish\Services\Theme::editorHeadExtra()),
            'hiddenCustomFieldKeys' => \Pafish\Services\Theme::inactiveEditorFieldKeys(),
        ], $isEdit ? '编辑文章' : '写文章');

        $response->getBody()->write($html);
        return $response;
    }

    private function singleOp(Request $request, Response $response, int $id, string $op): Response
    {
        $this->guardCanManage();
        try {
            $post = DB::fetchOne(
                'SELECT * FROM posts WHERE id = ?' . (!\Pafish\Core\Auth::isAdmin() ? ' AND author_id = ?' : ''),
                \Pafish\Core\Auth::isAdmin() ? [$id] : [$id, (int) \Pafish\Core\Auth::id()]
            );
            if (!$post) {
                throw new \RuntimeException('文章不存在');
            }
            switch ($op) {
                case 'delete':
                    DB::execute('UPDATE posts SET deleted_at = NOW() WHERE id = ?', [$id]);
                    \do_action('after_delete_post', self::postPayloadFromRow($post));
                    break;
                case 'restore':
                    DB::execute('UPDATE posts SET deleted_at = NULL WHERE id = ?', [$id]);
                    break;
                case 'purge':
                    $commentEvents = \Pafish\Services\MediaReferences::transaction(static function () use ($id): array {
                        if (DB::fetchOne('SELECT id FROM posts WHERE id = ? AND deleted_at IS NOT NULL FOR UPDATE', [$id])) {
                            $events = \Pafish\Services\Comments::deleteForPost($id);
                            \Pafish\Services\EntityMeta::deleteSubject('post', $id);
                            foreach (DB::fetchAll('SELECT id FROM nav_items WHERE post_id = ? FOR UPDATE', [$id]) as $nav) {
                                \Pafish\Services\MediaReferences::forget('nav', (int) $nav['id']);
                            }
                            DB::execute('DELETE FROM posts WHERE id = ?', [$id]);
                            \Pafish\Services\MediaReferences::forget('post', $id);
                            return $events;
                        }
                        return [];
                    });
                    \Pafish\Services\Comments::emit($commentEvents);
                    \do_action('after_purge_post', self::postPayloadFromRow($post));
                    break;
            }
        } catch (\PDOException $e) {
            \Pafish\Core\Logger::exception('文章操作失败', $e);
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => '操作失败，请稍后重试'], 500);
            }
            $this->flash('error', '操作失败，请稍后重试');
            return $this->redirect($response, '/admin/posts');
        } catch (\Throwable $e) {
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, '/admin/posts');
        }
        \Pafish\Core\Cache::clearPrefix('nav.');
        if (!$this->isAjax($request)) {
            $this->flash('success', '操作成功');
            return $this->redirect($response, '/admin/posts');
        }
        return $this->json($response, ['ok' => true]);
    }

    /** 正文先按自身格式渲染，再提取摘要；不把 HTML 标签写进列表摘要。 */
    public static function extractSummary(string $content, int $max = 180, string $format = 'markdown'): string
    {
        return mb_substr(\Pafish\Services\Content::plain($content, $format), 0, $max);
    }

    /** 提取正文中实际渲染的第一张图片，忽略代码块中的图片语法。 */
    public static function extractCover(string $content, string $format = 'markdown'): ?string
    {
        $html = \Pafish\Services\Content::render($content, $format);
        if (preg_match('/<img\b[^>]*\bsrc="([^"]+)"/i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return null;
    }

    /**
     * 编辑器封面卡的三元状态：手动地址 / 自动取图偏好 / 自动取到的图。
     *
     * 旧数据未记录来源时，只在封面等于正文首图且没有明确关闭自动取图时推断为自动；
     * 其余按手动封面保留。记录来源后不再用图片地址是否相同来猜测。
     *
     * @return array{0:string,1:bool,2:string}
     */
    private static function coverEditorState(array $post): array
    {
        $stored = trim((string) ($post['cover_url'] ?? ''));
        $flag = \Pafish\Services\EntityMeta::get('post', (int) $post['id'], \Pafish\Services\EntityMeta::scope('core'), 'auto_cover');
        $autoCoverOn = $flag === '1';
        if ($stored === '') return ['', $autoCoverOn, ''];
        $source = \Pafish\Services\EntityMeta::get('post', (int) $post['id'], \Pafish\Services\EntityMeta::scope('core'), 'cover_source');
        if ($source === 'auto') return ['', $autoCoverOn, $stored];
        if ($source === 'manual' || $source === 'none' || $flag === '0') return [$stored, $autoCoverOn, ''];
        try {
            $derived = (string) \Pafish\Services\Content::imageUrl(
                (string) self::extractCover((string) ($post['content'] ?? ''), (string) ($post['content_format'] ?? 'markdown'))
            );
        } catch (\RuntimeException) {
            $derived = '';
        }
        $isDerived = $derived !== '' && $derived === $stored;
        return [$isDerived ? '' : $stored, $flag === null ? $isDerived : $autoCoverOn, $isDerived ? $stored : ''];
    }

    /** 标签解析：按名称匹配已有，否则新建（slug 去冲突），返回 id 数组。 */
    public static function resolveNewTags(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $existing = DB::fetchOne('SELECT id FROM tags WHERE name = ?', [$name]);
            if ($existing) {
                $ids[] = (int) $existing['id'];
                continue;
            }
            $slug = Slug::forSave('', (string) $name, null,
                fn (string $s): bool => DB::value('SELECT COUNT(*) FROM tags WHERE slug = ?', [$s]) > 0,
                true, 100);
            DB::execute('INSERT INTO tags (name, slug) VALUES (?, ?)', [$name, $slug]);
            \Pafish\Services\PermalinkRedirects::forgetPaths(\Pafish\Services\Permalinks::paths('tag', ['slug' => $slug]));
            $ids[] = (int) DB::pdo()->lastInsertId();
        }
        return $ids;
    }

    /** 新分类：同名复用，否则创建（顶级，slug 去冲突） */
    private static function resolveNewCategory(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $existing = DB::fetchOne('SELECT id FROM categories WHERE name = ?', [$name]);
        if ($existing) {
            return (int) $existing['id'];
        }
        $slug = Slug::forSave('', $name, null,
            fn (string $s): bool => DB::value('SELECT COUNT(*) FROM categories WHERE slug = ?', [$s]) > 0,
            true, 100);
        DB::execute('INSERT INTO categories (name, slug, description, parent_id, sort_order) VALUES (?, ?, NULL, NULL, 0)', [$name, $slug]);
        $id = (int) DB::pdo()->lastInsertId();
        \Pafish\Services\PermalinkRedirects::forgetPaths(\Pafish\Services\Permalinks::paths('category', ['id' => $id, 'slug' => $slug]));
        \Pafish\Core\Cache::clearPrefix('categories.');
        return $id;
    }

    /** 全量重建文章标签关联。 */
    public static function replaceTags(int $postId, array $tagIds): void
    {
        DB::execute('DELETE FROM post_tags WHERE post_id = ?', [$postId]);
        if ($tagIds !== []) {
            $in = implode(',', array_fill(0, count($tagIds), '?'));
            DB::execute("INSERT IGNORE INTO post_tags (post_id, tag_id) SELECT {$postId}, id FROM tags WHERE id IN ({$in})", $tagIds);
        }
    }

    /** 解析自定义字段。 */
    private static function parseCustomFields(string $raw): ?string
    {
        $rows = json_decode($raw, true);
        $clean = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = trim((string) ($row['key'] ?? ''));
                $value = trim((string) ($row['value'] ?? ''));
                if ($key !== '' || $value !== '') {
                    $clean[] = [
                        'key' => mb_substr($key, 0, 50),
                        'value' => mb_substr($value, 0, 500),
                    ];
                }
            }
        }
        return $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /** 整数列表（支持数组或 JSON 字符串） */
    private static function parseIntList(mixed $raw): array
    {
        $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($arr)) {
            return [];
        }
        return array_values(array_filter(array_map(fn ($v) => (int) $v, $arr), fn ($v) => $v > 0));
    }

    /** 字符串列表（支持数组或 JSON 字符串） */
    private static function parseStringList(mixed $raw): array
    {
        $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($arr)) {
            return [];
        }
        return array_values(array_filter(array_map(
            fn ($v) => mb_substr(trim((string) $v), 0, 100),
            $arr
        ), fn ($v) => $v !== ''));
    }

    /** 钩子 payload */
    private static function postPayload(
        int $id,
        string $title,
        string $slug,
        string $status,
        ?string $publishedAt,
        ?int $categoryId,
        ?string $externalUrl,
        bool $isPinned,
        bool $categoryPinned,
        ?string $action = null,
        ?string $previousStatus = null,
        array $controls = []
    ): array
    {
        return [
            'id' => (string) $id,
            'title' => $title,
            'slug' => $slug,
            'status' => $status,
            'publishedAt' => $publishedAt,
            'categoryId' => $categoryId !== null ? (string) $categoryId : null,
            'externalUrl' => $externalUrl,
            'isPinned' => $isPinned,
            'categoryPinned' => $categoryPinned,
            'action' => $action,
            'previousStatus' => $previousStatus,
            'allowComments' => (bool) ($controls['allow_comments'] ?? 1),
            'seoTitle' => (string) ($controls['seo_title'] ?? ''),
            'metaDescription' => (string) ($controls['meta_description'] ?? ''),
            'template' => (string) ($controls['template'] ?? 'default'),
        ];
    }

    private static function postPayloadFromRow(array $row, ?string $action = null, ?string $previousStatus = null): array
    {
        return self::postPayload(
            (int) $row['id'],
            (string) $row['title'],
            (string) $row['slug'],
            (string) $row['status'],
            $row['published_at'] ? (string) $row['published_at'] : null,
            $row['category_id'] ? (int) $row['category_id'] : null,
            $row['external_url'] ? (string) $row['external_url'] : null,
            (bool) $row['is_pinned'],
            (bool) $row['category_pinned'],
            $action,
            $previousStatus,
            $row
        );
    }

    /** API v2 插件编辑器字段：plugins[plugin-name][field]，仅保留标量字符串。 */
    private static function pluginExtensions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $clean = [];
        foreach ($raw as $plugin => $fields) {
            if (!is_string($plugin) || preg_match('/^[a-z0-9_-]{1,50}$/', $plugin) !== 1 || !is_array($fields)) {
                continue;
            }
            foreach ($fields as $key => $value) {
                if (!is_string($key) || preg_match('/^[a-z0-9_-]{1,50}$/', $key) !== 1 || !is_scalar($value)) {
                    continue;
                }
                $clean[$plugin][$key] = mb_substr((string) $value, 0, 10000);
            }
        }
        return $clean;
    }

    private function notFound(Response $response): Response
    {
        return $this->redirect($response, '/admin/posts', 302);
    }
}
