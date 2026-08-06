<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Core\Session;
use Pafish\Services\Markdown;
use Pafish\Services\Slug;
use Pafish\Services\Upload;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台编辑器配套 API（对齐 Node 版 upload / uploads / md-preview / import-markdown 端点）：
 * - POST /api/upload            文件上传（GD 压缩/云存储优先，见 Upload 服务）
 * - GET  /api/uploads           媒体库列表（24/页、q 搜索、type=image 仅图片——封面选择用）
 * - POST /api/md-preview        Markdown 服务端渲染（编辑器的分栏/预览模式）
 * - POST /api/import-markdown   批量导入 .md 文件（frontmatter: title/date/tags）
 * 全部需要登录 + 内容管理权限 + POST 校验 CSRF
 */
final class ApiController extends AdminController
{
    private const LIB_PAGE_SIZE = 24; // 与 Node MediaPicker PAGE_SIZE 一致
    private const IMPORT_MAX_FILES = 50; // 与 Node MAX_FILES 一致
    private const IMPORT_MAX_SIZE = 1048576; // 1MB，与 Node MAX_FILE_SIZE 一致

    /** POST /api/upload：multipart 上传 */
    public function upload(Request $request, Response $response): Response
    {
        $guard = $this->guardJson($request, $response);
        if ($guard !== null) {
            return $guard;
        }
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
            return $this->json($response, ['error' => '未选择文件'], 400);
        }
        try {
            $result = Upload::handleStream((string) $file->getStream()->getContents(), (string) $file->getClientFilename());
        } catch (\RuntimeException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
        return $this->json($response, [
            'ok' => true,
            'url' => $result['url'],
            'mime' => $result['mime'],
            'originalName' => (string) $file->getClientFilename(),
            'size' => $result['size'],
            'width' => $result['width'],
            'height' => $result['height'],
        ]);
    }

    /** GET /api/uploads：媒体库（page / q / type 5 类筛选，对齐 Node GET /api/uploads） */
    public function uploads(Request $request, Response $response): Response
    {
        $guard = $this->guardJson($request, $response);
        if ($guard !== null) {
            return $guard;
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $q = trim((string) ($_GET['q'] ?? ''));
        $type = (string) ($_GET['type'] ?? '');
        if (!in_array($type, ['image', 'doc', 'archive', 'audio', 'video'], true)) {
            $type = '';
        }

        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND original_name LIKE ?';
            $params[] = "%{$q}%";
        }
        $tw = Upload::typeWhere($type);
        if ($tw !== '') {
            $where .= ' AND ' . $tw;
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM uploads WHERE {$where}", $params);
        $items = DB::fetchAll(
            "SELECT id, original_name, url, mime, size, width, height
             FROM uploads WHERE {$where}
             ORDER BY id DESC
             LIMIT " . self::LIB_PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::LIB_PAGE_SIZE),
            $params
        );
        $items = array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'originalName' => (string) $row['original_name'],
            'url' => (string) $row['url'],
            'mime' => (string) $row['mime'],
            'size' => (int) $row['size'],
            'width' => $row['width'] !== null ? (int) $row['width'] : null,
            'height' => $row['height'] !== null ? (int) $row['height'] : null,
        ], $items);
        return $this->json($response, ['items' => $items, 'total' => $total, 'page' => $page, 'pageSize' => self::LIB_PAGE_SIZE]);
    }

    /** POST /api/md-preview：服务端渲染 Markdown（编辑器分栏/预览模式） */
    public function mdPreview(Request $request, Response $response): Response
    {
        $guard = $this->guardJson($request, $response);
        if ($guard !== null) {
            return $guard;
        }
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        $content = (string) ($body['content'] ?? '');
        return $this->json($response, ['html' => Markdown::render($content)]);
    }

    /**
     * POST /api/import-markdown：批量导入 .md 文件为文章（对齐 Node import-markdown route）
     * - 最多 50 个文件、单文件 1MB；frontmatter 仅解析 title / date / tags
     * - 缺 title 用文件名；slug = slugify(title) 冲突追加 -2/-3；正文空则该文件失败
     * - status: draft → DRAFT；publish → PUBLISHED（date 有效则作为发布时间，否则当前时间）
     * - 逐文件独立 try/catch，单文件失败不影响其余
     */
    public function importMarkdown(Request $request, Response $response): Response
    {
        $guard = $this->guardJson($request, $response);
        if ($guard !== null) {
            return $guard;
        }
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        $raw = $request->getUploadedFiles()['files'] ?? [];
        // 单文件时 PSR-7 返回单个对象，多文件（files[] 字段）返回数组；统一为数组
        // 注意：PHP 8.4+ 新 multipart 解析器对同名 files 字段只保留最后一个，前端须用 files[]
        $files = is_array($raw) ? $raw : [$raw];
        $files = array_values(array_filter(
            $files,
            static fn ($f) => $f instanceof \Psr\Http\Message\UploadedFileInterface
        ));
        if ($files === []) {
            return $this->json($response, ['error' => '未选择 Markdown 文件'], 400);
        }
        if (count($files) > self::IMPORT_MAX_FILES) {
            return $this->json($response, ['error' => '一次最多导入 50 个文件'], 400);
        }
        $status = ($body['status'] ?? '') === 'publish' ? 'PUBLISHED' : 'DRAFT';

        $created = 0;
        $failed = 0;
        $results = [];
        foreach ($files as $file) {
            $name = (string) $file->getClientFilename();
            try {
                if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'md') {
                    throw new \RuntimeException('仅支持 .md 文件');
                }
                $buffer = (string) $file->getStream()->getContents();
                if (strlen($buffer) > self::IMPORT_MAX_SIZE) {
                    throw new \RuntimeException('文件超过 1MB 限制');
                }
                $this->importOne($name, $buffer, $status);
                $created++;
                $results[] = ['name' => $name, 'ok' => true];
            } catch (\Throwable $e) {
                $failed++;
                $results[] = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()];
            }
        }
        return $this->json($response, ['created' => $created, 'failed' => $failed, 'results' => $results]);
    }

    // ---------- 内部 ----------

    /** 解析单个文件并创建文章（对齐 Node parseFrontmatter + create 逻辑） */
    private function importOne(string $name, string $buffer, string $status): void
    {
        $meta = self::parseFrontmatter($buffer);
        $content = $meta['content'];
        if (trim($content) === '') {
            throw new \RuntimeException('正文为空');
        }
        // 缺 title 用文件名去 .md 后缀（≤255，对齐 Node slice(0,255)）
        $title = mb_substr(trim($meta['title'] !== '' ? $meta['title'] : preg_replace('/\.md$/i', '', $name)), 0, 255);
        // slug = slugify(title) 冲突自动 -2/-3（含回收站全局唯一，对齐 Node uniqueSlug）
        $slug = Slug::resolveUnique(Slug::slugify($title), static fn (string $s): bool =>
            DB::value('SELECT COUNT(*) FROM posts WHERE slug = ?', [$s]) > 0);
        // 发布时间：仅发布状态生效；date 无效回退当前时间（对齐 Node NaN → new Date()）
        $publishedAt = null;
        if ($status === 'PUBLISHED') {
            $at = $meta['date'] !== '' ? strtotime($meta['date']) : false;
            $publishedAt = date('Y-m-d H:i:s', $at !== false ? $at : time());
        }
        // 标签：按 [,，\s]+ 拆分、最多 5 个；按名复用或新建，单个失败静默跳过（对齐 Node catch → null）
        $tagIds = [];
        foreach (array_slice(preg_split('/[,，\s]+/', $meta['tags'], -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $tagName) {
            try {
                $tagIds[] = PostsController::resolveNewTags([trim($tagName)])[0] ?? 0;
            } catch (\Throwable $e) {
                // 标签创建失败不影响文章
            }
        }
        $tagIds = array_values(array_filter($tagIds));

        $now = date('Y-m-d H:i:s');
        $authorId = Auth::id();
        $pdo = DB::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO posts (title, slug, excerpt, content, status, published_at, author_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$title, $slug, '', $content, $status, $publishedAt, $authorId, $now, $now]);
        $postId = (int) $pdo->lastInsertId();
        if ($tagIds !== []) {
            PostsController::replaceTags($postId, $tagIds);
        }
    }

    /** 简易 frontmatter 解析（对齐 Node parseFrontmatter：仅 title/date/tags，key 转小写） */
    private static function parseFrontmatter(string $text): array
    {
        if (!preg_match('/^---\r?\n([\s\S]*?)\r?\n---\r?\n?/', $text, $m)) {
            return ['title' => '', 'date' => '', 'tags' => '', 'content' => $text];
        }
        $meta = [];
        foreach (preg_split('/\r?\n/', $m[1]) as $line) {
            if (preg_match('/^([A-Za-z_][\w-]*)\s*:\s*(.*)$/', $line, $kv)) {
                $meta[strtolower($kv[1])] = trim($kv[2]);
            }
        }
        return [
            'title' => (string) ($meta['title'] ?? ''),
            'date' => (string) ($meta['date'] ?? ''),
            'tags' => (string) ($meta['tags'] ?? ''),
            'content' => trim(substr($text, strlen($m[0]))),
        ];
    }

    /** 未登录/无权限时返回 JSON 401/403（而非重定向） */
    private function guardJson(Request $request, Response $response): ?Response
    {
        if (!Auth::check()) {
            return $this->json($response, ['error' => '未登录'], 401);
        }
        if (!Auth::canManagePosts()) {
            return $this->json($response, ['error' => '无权限'], 403);
        }
        return null;
    }
}
