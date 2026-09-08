<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\DB;
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
    private const STATUS_LABEL = ['DRAFT' => '草稿', 'PUBLISHED' => '已发布', 'SCHEDULED' => '定时'];

    // ---------- 列表 ----------

    public function index(Request $request, Response $response): Response
    {
        $this->guardCanManage();

        $perRaw = (int) ($_GET['per'] ?? 0);
        $perPref = (int) ($_COOKIE['admin_posts_per_page'] ?? 0);
        $per = in_array($perRaw, self::PER_PAGE_OPTIONS, true) ? $perRaw
            : (in_array($perPref, self::PER_PAGE_OPTIONS, true) ? $perPref : 20);

        $isTrash = ($_GET['status'] ?? '') === 'trash';
        $status = !$isTrash && in_array((string) ($_GET['status'] ?? ''), ['PUBLISHED', 'DRAFT', 'SCHEDULED'], true)
            ? (string) $_GET['status'] : null;
        $category = (string) ($_GET['category'] ?? '');
        $q = trim((string) ($_GET['q'] ?? ''));
        $sort = in_array((string) ($_GET['sort'] ?? ''), ['latest', 'updated', 'pinned', 'views', 'comments'], true)
            ? (string) $_GET['sort'] : 'latest';
        $page = max(1, (int) ($_GET['page'] ?? 1));

        // 构建筛选条件
        $where = $isTrash ? 'p.deleted_at IS NOT NULL' : 'p.deleted_at IS NULL';
        $params = [];
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
        if ($q !== '') {
            $where .= ' AND (p.title LIKE ? OR p.content LIKE ?)';
            $params[] = "%{$q}%";
            $params[] = "%{$q}%";
        }

        $orderBy = match ($sort) {
            'updated' => 'p.updated_at DESC',
            'pinned' => 'p.is_pinned DESC, p.published_at DESC',
            'views' => 'p.view_count DESC',
            'comments' => '(SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) DESC, p.id DESC',
            default => 'p.published_at DESC',
        };

        $total = (int) DB::value("SELECT COUNT(*) FROM posts p WHERE {$where}", $params);
        $totalPages = max(1, (int) ceil($total / $per));
        $posts = DB::fetchAll(
            "SELECT p.*, c.name AS category_name, u.username AS author_username,
                    (SELECT COUNT(*) FROM comments c2 WHERE c2.post_id = p.id) AS comment_count
             FROM posts p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN users u ON u.id = p.author_id
             WHERE {$where}
             ORDER BY {$orderBy}
             LIMIT " . $per . ' OFFSET ' . (($page - 1) * $per),
            $params
        );

        // tabs 计数（按状态分组 + 回收站）
        $counts = ['PUBLISHED' => 0, 'DRAFT' => 0, 'SCHEDULED' => 0, 'TRASH' => 0];
        foreach (DB::fetchAll("SELECT status, COUNT(*) AS c FROM posts WHERE deleted_at IS NULL GROUP BY status") as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }
        $counts['TRASH'] = (int) DB::value('SELECT COUNT(*) FROM posts WHERE deleted_at IS NOT NULL');

        // 分类树下拉
        $catTree = Categories::tree();

        $html = $this->render('posts', [
            'posts' => $posts,
            'total' => $total,
            'totalPages' => $totalPages,
            'page' => $page,
            'per' => $per,
            'perOptions' => self::PER_PAGE_OPTIONS,
            'params' => ['status' => $status ?? ($isTrash ? 'trash' : ''), 'category' => $category, 'q' => $q, 'sort' => $sort],
            'counts' => $counts,
            'isTrash' => $isTrash,
            'catTree' => $catTree,
            'statusLabel' => self::STATUS_LABEL,
        ], '文章管理');

        $response->getBody()->write($html);
        return $response;
    }

    // ---------- 编辑器 ----------

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

    public function editEditor(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $id = (int) ($args['id'] ?? 0);
        $post = DB::fetchOne('SELECT * FROM posts WHERE id = ?', [$id]);
        if (!$post) {
            return $this->notFound($response);
        }
        return $this->renderEditor($request, $response, $post);
    }

    /** 保存文章（新建/更新共用；对齐 createPost/updatePost） */
    public function save(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $body = $request->getParsedBody() ?? [];
        $id = isset($args['id']) ? (int) $args['id'] : 0;

        try {
            $result = $this->savePost($body, $id > 0 ? $id : null);
        } catch (\RuntimeException $e) {
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, $id > 0 ? "/admin/posts/{$id}/edit" : '/admin/posts/new');
        }

        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true, 'id' => $result['id'], 'status' => $result['status']]);
        }
        $this->flash('success', $result['created'] ? '文章已保存' : '文章已更新');
        return $this->redirect($response, "/admin/posts/{$result['id']}/edit");
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
        $op = (string) ($body['op'] ?? '');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $publishedRows = [];

        try {
            switch ($op) {
                case 'publish':
                    $publishedRows = DB::fetchAll(
                        "SELECT * FROM posts WHERE id IN ({$in}) AND deleted_at IS NULL AND status <> 'PUBLISHED'",
                        $ids
                    );
                    DB::execute("UPDATE posts SET status = 'PUBLISHED', published_at = NOW() WHERE id IN ({$in}) AND deleted_at IS NULL", $ids);
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
                    foreach ($ids as $pid) {
                        \Pafish\Services\RedPacket::refundForPost($pid);
                    }
                    DB::execute("DELETE FROM posts WHERE id IN ({$in}) AND deleted_at IS NOT NULL", $ids);
                    break;
                case 'move':
                    $rawCat = (string) ($body['category_id'] ?? '');
                    $categoryId = ctype_digit($rawCat) ? (int) $rawCat : null;
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

    // ---------- 核心保存逻辑（M3d 导入复用） ----------

    /**
     * 创建/更新文章。$id 为 null 创建；返回 ['id','status','created']
     * @throws \RuntimeException 中文错误信息
     */
    public function savePost(array $body, ?int $id = null): array
    {
        $action = (string) ($body['action'] ?? 'draft');

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException('标题不能为空');
        }
        if (mb_strlen($title) > 255) {
            throw new \RuntimeException('标题不能超过 255 个字符');
        }
        $content = (string) ($body['content'] ?? '');
        if (trim($content) === '') {
            throw new \RuntimeException('内容不能为空');
        }
        if (mb_strlen($content) > 16000000) {
            throw new \RuntimeException('内容过长');
        }

        // 别名：手动填了用之，否则标题自动生成；冲突友好报错
        $slug = trim((string) ($body['slug'] ?? ''));
        $slug = $slug !== '' ? $slug : Slug::slugify($title);
        if ($id !== null) {
            $exists = DB::value('SELECT COUNT(*) FROM posts WHERE slug = ? AND id != ?', [$slug, $id]) > 0;
        } else {
            $exists = DB::value('SELECT COUNT(*) FROM posts WHERE slug = ?', [$slug]) > 0;
        }
        if ($exists) {
            throw new \RuntimeException('别名已被使用，请更换');
        }

        // 摘要/封面：仅填空（对齐 fillExcerptCover）
        $excerpt = trim((string) ($body['excerpt'] ?? ''));
        $coverUrl = trim((string) ($body['cover_url'] ?? ''));
        if ($excerpt === '') {
            $excerpt = self::extractSummary($content);
        }
        if ($coverUrl === '') {
            $coverUrl = (string) self::extractCover($content);
        }
        if (mb_strlen($excerpt) > 500) {
            $excerpt = mb_substr($excerpt, 0, 500);
        }
        if (mb_strlen($coverUrl) > 500) {
            $coverUrl = '';
        }

        // 分类：newCategory 优先于 categoryId
        $categoryId = null;
        $newCategory = trim((string) ($body['new_category'] ?? ''));
        if ($newCategory !== '') {
            $categoryId = self::resolveNewCategory($newCategory);
        } else {
            $rawCat = (string) ($body['category_id'] ?? '');
            $categoryId = ctype_digit($rawCat) && (int) $rawCat > 0 ? (int) $rawCat : null;
        }

        // 标签：tagIds 已存在 + newTags 按名称匹配/新建
        $tagIds = self::parseIntList($body['tag_ids'] ?? []);
        $newTags = self::parseStringList($body['new_tags'] ?? []);
        $tagIds = array_values(array_unique(array_merge($tagIds, self::resolveNewTags($newTags))));

        $isPinned = !empty($body['is_pinned']);
        $categoryPinned = !empty($body['category_pinned']);
        $externalUrl = trim((string) ($body['external_url'] ?? ''));
        if (mb_strlen($externalUrl) > 500) {
            $externalUrl = '';
        }

        // 状态与发布时间
        $status = 'DRAFT';
        $publishedAt = null;
        if ($action === 'publish') {
            $status = 'PUBLISHED';
            $publishedAt = date('Y-m-d H:i:s');
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
            $password = password_hash(trim($newPass), PASSWORD_BCRYPT);
            $passwordSet = true;
        } elseif (!empty($body['remove_password'])) {
            $password = null;
            $passwordSet = true;
        }

        // 自定义字段（JSON 数组 [{key,value}]）
        $customFields = self::parseCustomFields((string) ($body['custom_fields'] ?? ''));

        $authorId = \Pafish\Core\Auth::id();
        \Pafish\Services\RedPacket::validateForPost($id, (int) $authorId, $customFields);
        $now = date('Y-m-d H:i:s');
        $extensions = self::pluginExtensions($body['plugins'] ?? []);
        $previousStatus = null;

        if ($id === null) {
            $created = true;
            $pdo = DB::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO posts (title, slug, excerpt, content, cover_url, status, published_at,
                    is_pinned, category_pinned, password, external_url, custom_fields, author_id, category_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $title, $slug, $excerpt, $content, $coverUrl !== '' ? $coverUrl : null,
                $status, $publishedAt, $isPinned ? 1 : 0, $categoryPinned ? 1 : 0,
                $password, $externalUrl !== '' ? $externalUrl : null, $customFields,
                $authorId, $categoryId, $now, $now,
            ]);
            $id = (int) $pdo->lastInsertId();
            self::replaceTags($id, $tagIds);
            $payload = self::postPayload($id, $title, $slug, $status, $publishedAt, $categoryId, $externalUrl, $isPinned, $categoryPinned, $action, null);
            \do_action('after_create_post', $payload);
        } else {
            $created = false;
            $existing = DB::fetchOne('SELECT * FROM posts WHERE id = ?', [$id]);
            if (!$existing) {
                throw new \RuntimeException('文章不存在');
            }
            $previousStatus = (string) $existing['status'];
            // auto（自动保存）：保留原状态与发布时间
            if ($action === 'auto') {
                $status = $existing['status'];
                $publishedAt = $existing['published_at'];
            }
            if (!$passwordSet) {
                $password = $existing['password'];
            }
            DB::execute(
                'UPDATE posts SET title = ?, slug = ?, excerpt = ?, content = ?, cover_url = ?, status = ?,
                    published_at = ?, is_pinned = ?, category_pinned = ?, password = ?, external_url = ?,
                    custom_fields = ?, category_id = ?, updated_at = ?
                 WHERE id = ?',
                [
                    $title, $slug, $excerpt, $content, $coverUrl !== '' ? $coverUrl : null,
                    $status, $publishedAt, $isPinned ? 1 : 0, $categoryPinned ? 1 : 0,
                    $password, $externalUrl !== '' ? $externalUrl : null, $customFields,
                    $categoryId, $now, $id,
                ]
            );
            self::replaceTags($id, $tagIds);
            $payload = self::postPayload($id, $title, $slug, $status, $publishedAt, $categoryId, $externalUrl, $isPinned, $categoryPinned, $action, $previousStatus);
            \do_action('after_update_post', $payload);
        }

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
        \Pafish\Services\RedPacket::syncPost((int) $id, (int) $authorId, $customFields);

        return ['id' => $id, 'status' => $status, 'created' => $created];
    }

    // ---------- 内部 ----------

    private function renderEditor(Request $request, Response $response, ?array $post): Response
    {
        $isEdit = $post !== null;
        $initial = $post ?: [
            'title' => '', 'slug' => '', 'excerpt' => '', 'content' => '', 'cover_url' => null,
            'status' => 'DRAFT', 'is_pinned' => 0, 'category_pinned' => 0,
            'password' => null, 'external_url' => null, 'category_id' => null,
        ];
        $customFields = [];
        if ($post && $post['custom_fields']) {
            $parsed = json_decode($post['custom_fields'], true);
            if (is_array($parsed)) {
                $customFields = $parsed;
            }
        }

        // 已选标签 id 集
        $tagIds = [];
        if ($isEdit) {
            foreach (DB::fetchAll('SELECT tag_id FROM post_tags WHERE post_id = ?', [$post['id']]) as $row) {
                $tagIds[] = (int) $row['tag_id'];
            }
        }
        $tags = DB::fetchAll('SELECT * FROM tags ORDER BY name ASC');

        $html = $this->render('post-editor', [
            'post' => $initial,
            'isEdit' => $isEdit,
            'postId' => $isEdit ? (int) $post['id'] : 0,
            'catTree' => Categories::tree(),
            'tags' => $tags,
            'tagIds' => $tagIds,
            'customFields' => $customFields,
            'hasPassword' => $isEdit && $initial['password'] !== null,
            'isScheduled' => $isEdit && $initial['status'] === 'SCHEDULED',
            'statusLabel' => self::STATUS_LABEL,
            'headExtra' => editor_head_extra(),
        ], $isEdit ? '编辑文章' : '写文章');

        $response->getBody()->write($html);
        return $response;
    }

    private function singleOp(Request $request, Response $response, int $id, string $op): Response
    {
        $this->guardCanManage();
        try {
            $post = DB::fetchOne('SELECT * FROM posts WHERE id = ?', [$id]);
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
                    \Pafish\Services\RedPacket::refundForPost($id);
                    DB::execute('DELETE FROM posts WHERE id = ? AND deleted_at IS NOT NULL', [$id]);
                    \do_action('after_purge_post', self::postPayloadFromRow($post));
                    break;
            }
        } catch (\Throwable $e) {
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, '/admin/posts');
        }
        if (!$this->isAjax($request)) {
            $this->flash('success', '操作成功');
            return $this->redirect($response, '/admin/posts');
        }
        return $this->json($response, ['ok' => true]);
    }

    /** 从 Markdown 提取纯文本摘要（前 180 字） */
    public static function extractSummary(string $md, int $max = 180): string
    {
        $text = (string) preg_replace('/```[\s\S]*?```/', ' ', $md);
        $text = (string) preg_replace('/!\[[^\]]*\]\([^)\s]*\)/', ' ', $text);
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)\s]*\)/', '$1', $text);
        $text = (string) preg_replace('/[#>*_`~\-|]/', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_substr($text, 0, $max);
    }

    /** 提取第一张图片 URL */
    public static function extractCover(string $md): ?string
    {
        if (preg_match('/!\[[^\]]*\]\(([^)\s]+)\)/', $md, $m)) {
            return $m[1];
        }
        return null;
    }

    /** 标签解析：按名称匹配已有，否则新建（slug 去冲突），返回 id 数组（M3d 导入复用） */
    public static function resolveNewTags(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $existing = DB::fetchOne('SELECT id FROM tags WHERE name = ?', [$name]);
            if ($existing) {
                $ids[] = (int) $existing['id'];
                continue;
            }
            $slug = Slug::resolveUnique(Slug::slugify($name), fn (string $s) => DB::value('SELECT COUNT(*) FROM tags WHERE slug = ?', [$s]) > 0);
            DB::execute('INSERT INTO tags (name, slug) VALUES (?, ?)', [$name, $slug]);
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
        $slug = Slug::resolveUnique(Slug::slugify($name), fn (string $s) => DB::value('SELECT COUNT(*) FROM categories WHERE slug = ?', [$s]) > 0);
        DB::execute('INSERT INTO categories (name, slug, description, parent_id, sort_order) VALUES (?, ?, NULL, NULL, 0)', [$name, $slug]);
        return (int) DB::pdo()->lastInsertId();
    }

    /** 全量重建文章标签关联（M3d 导入复用） */
    public static function replaceTags(int $postId, array $tagIds): void
    {
        DB::execute('DELETE FROM post_tags WHERE post_id = ?', [$postId]);
        if ($tagIds !== []) {
            $in = implode(',', array_fill(0, count($tagIds), '?'));
            DB::execute("INSERT IGNORE INTO post_tags (post_id, tag_id) SELECT {$postId}, id FROM tags WHERE id IN ({$in})", $tagIds);
        }
    }

    /** 自定义字段解析：JSON 数组，过滤空行，空则 null（对齐 serializeCustomFields） */
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
        ?string $previousStatus = null
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
            $previousStatus
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
