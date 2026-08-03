<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Core\Session;
use Pafish\Services\Markdown;
use Pafish\Services\Upload;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台编辑器配套 API（对齐 Node 版 upload / uploads / md-preview 端点）：
 * - POST /api/upload   文件上传（GD 压缩/云存储优先，见 Upload 服务）
 * - GET  /api/uploads  媒体库列表（24/页、q 搜索、type=image 仅图片——封面选择用）
 * - POST /api/md-preview  Markdown 服务端渲染（编辑器的分栏/预览模式）
 * 全部需要登录 + 内容管理权限 + POST 校验 CSRF
 */
final class ApiController extends AdminController
{
    private const LIB_PAGE_SIZE = 24; // 与 Node MediaPicker PAGE_SIZE 一致

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

    /** GET /api/uploads：媒体库（page / q / type=image） */
    public function uploads(Request $request, Response $response): Response
    {
        $guard = $this->guardJson($request, $response);
        if ($guard !== null) {
            return $guard;
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $q = trim((string) ($_GET['q'] ?? ''));
        $type = (string) ($_GET['type'] ?? '');

        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND original_name LIKE ?';
            $params[] = "%{$q}%";
        }
        if ($type === 'image') {
            $where .= " AND mime LIKE 'image/%'";
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
        return $this->json($response, ['items' => $items, 'total' => $total]);
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

    // ---------- 内部 ----------

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
