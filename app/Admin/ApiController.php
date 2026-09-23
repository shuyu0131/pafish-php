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
 * 后台编辑器配套 API：上传、媒体库、Markdown 预览和批量导入。
 * - POST /api/upload            文件上传（GD 压缩/云存储优先，见 Upload 服务）
 * - GET  /api/uploads           媒体库列表（24/页、q 搜索、type=image 仅图片——封面选择用）
 * - POST /api/md-preview        Markdown 服务端渲染（编辑器的分栏/预览模式）
 * - POST /api/import-markdown   批量导入 .md 文件（frontmatter: title/date/tags）
 * 全部需要登录 + 内容管理权限 + POST 校验 CSRF
 */
final class ApiController extends AdminController
{
    private const LIB_PAGE_SIZE = 24;
    private const IMPORT_MAX_FILES = 50;
    private const IMPORT_MAX_SIZE = 1048576; // 1MB
    private const UPLOAD_CHUNK_BYTES = 1024 * 1024;

    /** POST /api/upload：multipart 上传（兼容 Vditor 编辑器 file[] 多文件与旧单文件两种调用） */
    public function upload(Request $request, Response $response): Response
    {
        $guard = $this->guardJson($request, $response);
        if ($guard !== null) {
            return $guard;
        }
        $body = $request->getParsedBody() ?? [];
        // Vditor 上传走 XHR，CSRF 放 X-CSRF-Token 头；其余调用放 _csrf 表单字段
        $csrf = (string) ($body['_csrf'] ?? '');
        if ($csrf === '') {
            $csrf = $request->getHeaderLine('X-CSRF-Token');
        }
        if (!Session::verifyCsrf($csrf)) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }

        $raw = $request->getUploadedFiles()['file'] ?? null;
        // 单文件时 PSR-7 返回单个对象，多文件（file[] 字段）返回数组；统一为数组
        $files = is_array($raw) ? $raw : [$raw];
        $files = array_values(array_filter(
            $files,
            static fn ($f) => $f instanceof \Psr\Http\Message\UploadedFileInterface
        ));
        if ($files === []) {
            return $this->json($response, ['error' => '未选择文件'], 400);
        }

        $succMap = [];
        $errMap = [];
        $first = null;
        foreach ($files as $file) {
            $name = (string) $file->getClientFilename();
            try {
                $result = Upload::handleStream((string) $file->getStream()->getContents(), $name);
                $succMap[$name] = $result['url'];
                $first = $first ?? [
                    'url' => $result['url'],
                    'mime' => $result['mime'],
                    'originalName' => $name,
                    'size' => $result['size'],
                    'width' => $result['width'],
                    'height' => $result['height'],
                ];
            } catch (\RuntimeException $e) {
                $errMap[$name] = $e->getMessage();
            }
        }
        if ($first === null) {
            $msg = $errMap !== [] ? '上传失败：' . implode('；', $errMap) : '上传失败';
            return $this->json($response, ['error' => $msg], 400);
        }

        // Vditor 期望 {code:0,message:'',data:{succMap:{文件名:url}}}；旧调用（媒体库/封面）用 ok/url 单文件字段，两者共存
        return $this->json($response, [
            'ok' => true,
            'code' => 0,
            'message' => '',
            'data' => ['succMap' => $succMap, 'errMap' => $errMap],
            'url' => $first['url'],
            'mime' => $first['mime'],
            'originalName' => $first['originalName'],
            'size' => $first['size'],
            'width' => $first['width'],
            'height' => $first['height'],
        ]);
    }

    public function uploadChunk(Request $request, Response $response): Response
    {
        $guard = $this->guardJson($request, $response);
        if ($guard !== null) return $guard;
        $body = $request->getParsedBody() ?? [];
        $csrf = (string)($body['_csrf'] ?? $request->getHeaderLine('X-CSRF-Token'));
        if (!Session::verifyCsrf($csrf)) return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        $uploadId = strtolower(trim((string)($body['upload_id'] ?? '')));
        $index = filter_var($body['chunk_index'] ?? null, FILTER_VALIDATE_INT);
        $total = filter_var($body['chunk_total'] ?? null, FILTER_VALIDATE_INT);
        $filename = trim((string)($body['filename'] ?? ''));
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!preg_match('/^[a-f0-9]{24,64}$/', $uploadId) || $index === false || $total === false || $index < 0 || $total < 1 || $total > 256 || $index >= $total || $filename === '' || !$file instanceof \Psr\Http\Message\UploadedFileInterface) {
            return $this->json($response, ['error' => '分片参数无效'], 400);
        }
        $size = (int)($file->getSize() ?? 0);
        if ($size <= 0 || $size > self::UPLOAD_CHUNK_BYTES) return $this->json($response, ['error' => '分片大小无效'], 400);
        $root = dirname(__DIR__, 2) . '/storage/chunks/' . $uploadId;
        $this->cleanupExpiredChunks(dirname($root));
        if (!is_dir($root) && !@mkdir($root, 0700, true)) return $this->json($response, ['error' => '无法创建上传临时目录'], 500);
        $metaPath = $root . '/meta.json';
        $meta = is_file($metaPath) ? json_decode((string)@file_get_contents($metaPath), true) : null;
        $userId = (int)(Auth::id() ?? 0);
        if (!is_array($meta)) {
            $meta = ['user' => $userId, 'filename' => $filename, 'total' => $total, 'size' => 0, 'created' => time()];
            @file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        if ((int)($meta['user'] ?? -1) !== $userId || (int)($meta['total'] ?? 0) !== $total || (string)($meta['filename'] ?? '') !== $filename) {
            return $this->json($response, ['error' => '上传会话无效'], 403);
        }
        $partPath = $root . '/' . $index . '.part';
        try {
            $file->moveTo($partPath);
        } catch (\Throwable) {
            return $this->json($response, ['error' => '保存分片失败'], 500);
        }
        $meta['size'] = array_sum(array_map(static fn(string $p): int => (int)@filesize($p), glob($root . '/*.part') ?: []));
        @file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_UNICODE), LOCK_EX);
        $parts = glob($root . '/*.part') ?: [];
        if (count($parts) < $total) return $this->json($response, ['ok' => true, 'complete' => false, 'uploaded' => $meta['size']]);
        $assembled = $root . '/assembled.bin';
        $out = @fopen($assembled, 'wb');
        if (!$out) return $this->json($response, ['error' => '无法合并分片'], 500);
        for ($i = 0; $i < $total; $i++) {
            $part = $root . '/' . $i . '.part';
            if (!is_file($part)) { fclose($out); return $this->json($response, ['error' => '分片缺失，请重试'], 409); }
            $in = @fopen($part, 'rb');
            if (!$in) { fclose($out); return $this->json($response, ['error' => '读取分片失败'], 500); }
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);
        try {
            $result = Upload::handlePath($assembled, $filename);
        } catch (\Throwable $e) {
            $this->removeChunkDirectory($root);
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
        $this->removeChunkDirectory($root);
        return $this->json($response, ['ok' => true, 'complete' => true, 'url' => $result['url'], 'mime' => $result['mime'], 'originalName' => $filename, 'size' => $result['size']]);
    }

    private function removeChunkDirectory(string $root): void
    {
        foreach (glob($root . '/*') ?: [] as $file) if (is_file($file)) @unlink($file);
        @rmdir($root);
    }

    private function cleanupExpiredChunks(string $base): void
    {
        if (!is_dir($base)) return;
        foreach (glob($base . '/*/meta.json') ?: [] as $metaPath) {
            if ((int) @filemtime($metaPath) < time() - 86400) {
                $this->removeChunkDirectory(dirname($metaPath));
            }
        }
    }

    /** GET /api/uploads：媒体库（page / q / type 5 类筛选） */
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
        if (!Auth::isAdmin()) {
            $where .= ' AND uploader_id = ?';
            $params[] = (int) Auth::id();
        }
        if ($q !== '') {
            $where .= ' AND original_name LIKE ?';
            $params[] = "%{$q}%";
        }
        $tw = Upload::typeWhere($type);
        if ($tw !== '') {
            $where .= ' AND ' . $tw;
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM uploads WHERE {$where}", $params);
        $pages = max(1, (int) ceil($total / self::LIB_PAGE_SIZE));
        $page = min($page, $pages);
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
        return $this->json($response, ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'pageSize' => self::LIB_PAGE_SIZE]);
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
     * POST /api/import-markdown：批量导入 .md 文件为文章
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
                // 多文件请求使用 files[] 字段。
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

    /** 解析单个文件并创建文章 */
    private function importOne(string $name, string $buffer, string $status): void
    {
        $meta = self::parseFrontmatter($buffer);
        $content = $meta['content'];
        if (trim($content) === '') {
            throw new \RuntimeException('正文为空');
        }
        // 缺 title 用文件名去 .md 后缀（≤255）
        $title = mb_substr(trim($meta['title'] !== '' ? $meta['title'] : preg_replace('/\.md$/i', '', $name)), 0, 255);
        // slug = slugify(title)，冲突自动 -2/-3（含回收站全局唯一）
        $slug = Slug::resolveUnique(Slug::slugify($title), static fn (string $s): bool =>
            DB::value('SELECT COUNT(*) FROM posts WHERE slug = ?', [$s]) > 0);
        // 发布时间：仅发布状态生效；date 无效回退当前时间
        $publishedAt = null;
        if ($status === 'PUBLISHED') {
            $at = $meta['date'] !== '' ? strtotime($meta['date']) : false;
            $publishedAt = date('Y-m-d H:i:s', $at !== false ? $at : time());
        }
        $excerpt = '';
        $externalUrl = '';

        // Markdown 导入也是文章写入入口，必须遵守与编辑器相同的保存前拦截契约。
        $before = \apply_decision_filters('before_post_save', [
            'id' => null,
            'title' => $title,
            'slug' => $slug,
            'excerpt' => '',
            'content' => $content,
            'status' => $status,
            'publishedAt' => $publishedAt,
            'categoryId' => null,
            'externalUrl' => '',
            'isPinned' => false,
            'categoryPinned' => false,
            'action' => $status === 'PUBLISHED' ? 'publish' : 'draft',
        ], ['id' => null, 'created' => true, 'source' => 'markdown_import']);
        if ($before === false || (is_array($before) && ($before['allowed'] ?? true) === false)) {
            throw new \RuntimeException(is_array($before) ? (string) ($before['error'] ?? '文章保存被扩展拒绝') : '文章保存被扩展拒绝');
        }
        if (is_array($before)) {
            foreach (['title', 'slug', 'excerpt', 'content', 'externalUrl'] as $field) {
                if (array_key_exists($field, $before) && is_scalar($before[$field])) {
                    ${$field} = trim((string) $before[$field]);
                }
            }
            if ($content === '' || mb_strlen($content) > 16000000 || $title === '' || mb_strlen($title) > 255
                || $slug === '' || mb_strlen($slug) > 255 || mb_strlen($excerpt) > 500 || mb_strlen($externalUrl) > 500) {
                throw new \RuntimeException('文章保存前过滤器返回了无效内容');
            }
            if (DB::value('SELECT COUNT(*) FROM posts WHERE slug = ?', [$slug]) > 0) {
                throw new \RuntimeException('别名已被使用，请更换');
            }
        }

        // 标签：按 [,，\s]+ 拆分、最多 5 个；按名复用或新建，单个失败静默跳过
        $tagIds = [];
        foreach (array_slice(preg_split('/[,，\s]+/', $meta['tags'], -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $tagName) {
            try {
                $tagName = trim($tagName);
                $existing = DB::fetchOne('SELECT id FROM tags WHERE name = ?', [$tagName]);
                if ($existing !== null) {
                    $tagIds[] = (int) $existing['id'];
                } elseif (Auth::isAdmin()) {
                    $tagIds[] = PostsController::resolveNewTags([$tagName])[0] ?? 0;
                }
            } catch (\Throwable $e) {
                // 标签创建失败不影响文章
            }
        }
        $tagIds = array_values(array_filter($tagIds));

        $now = date('Y-m-d H:i:s');
        $authorId = Auth::id();
        $pdo = DB::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO posts (title, slug, excerpt, content, external_url, status, published_at, author_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$title, $slug, $excerpt, $content, $externalUrl !== '' ? $externalUrl : null, $status, $publishedAt, $authorId, $now, $now]);
        $postId = (int) $pdo->lastInsertId();
        if ($tagIds !== []) {
            PostsController::replaceTags($postId, $tagIds);
        }
        $payload = [
            'id' => (string) $postId,
            'title' => $title,
            'slug' => $slug,
            'status' => $status,
            'publishedAt' => $publishedAt,
            'categoryId' => null,
            'externalUrl' => $externalUrl !== '' ? $externalUrl : null,
            'isPinned' => false,
            'categoryPinned' => false,
        ];
        \do_action('after_create_post', $payload);
        if ($status === 'PUBLISHED') {
            $payload['trigger'] = 'import';
            \do_action('after_post_published', $payload);
        }
    }

    /** 简易 frontmatter 解析（仅 title/date/tags，key 转小写） */
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
