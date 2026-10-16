<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Services\Upload;
use Pafish\Services\MediaReferences;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 媒体库：支持本地和外部资源。
 * - 列表 48/页（q 搜索、type 5 类筛选：图片/文档/压缩包/音频/视频、分页窗口 ±2）
 * - 删除：先删数据库行再删文件（本地路径穿越防护 / 云存储插件 deleteFile 静默失败）
 * - 外部资源：仅存链接不下载（mime 按扩展名推断、size=0）
 * 权限：ADMIN+EDITOR（media.manage）；编辑仅管理自己上传的资源，CSRF 由 AdminAuthMiddleware 统一校验
 */
final class MediaController extends AdminController
{
    private const PAGE_SIZES = [24, 48, 96];

    /** GET /admin/uploads：媒体库列表 */
    public function index(Request $request, Response $response): Response
    {
        $this->guardCapability('media.manage');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = (int) ($_GET['per'] ?? self::PAGE_SIZES[1]);
        if (!in_array($perPage, self::PAGE_SIZES, true)) {
            $perPage = self::PAGE_SIZES[1];
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        $type = (string) ($_GET['type'] ?? '');
        $date = trim((string) ($_GET['date'] ?? ''));
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
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $where .= ' AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $date;
            $params[] = $date;
        } else {
            $date = '';
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM uploads WHERE {$where}", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $items = DB::fetchAll(
            "SELECT * FROM uploads WHERE {$where} ORDER BY id DESC LIMIT " . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );

        $response->getBody()->write($this->render('uploads', [
            'referencesReady' => MediaReferences::ready(),
            'canRebuildReferences' => Auth::isAdmin(),
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per' => $perPage,
            'q' => $q,
            'type' => $type,
            'date' => $date,
        ], '媒体库'));
        return $response;
    }

    /** POST /admin/uploads/rebuild-references: explicit administrator maintenance. */
    public function rebuildReferences(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        try {
            $result = MediaReferences::rebuild();
        } catch (\Throwable $e) {
            \Pafish\Core\Logger::error('[media-rebuild] ' . $e->getMessage());
            return $this->json($response, ['error' => '媒体引用重建失败，请检查日志后重试'], 500);
        }
        return $this->json($response, ['ok' => true] + $result);
    }

    /** Check and delete the record in one transaction, then remove its physical file. */
    public function delete(Request $request, Response $response, array $args = []): Response
    {
        $this->guardCapability('media.manage');
        $id = (int) ($args['id'] ?? $request->getAttribute('id') ?? 0);
        try {
            $row = MediaReferences::deleteUpload($id, Auth::isAdmin() ? null : (int) Auth::id(),
                (string) ($request->getParsedBody()['force'] ?? '') === '1');
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [404, 409], true) ? $e->getCode() : 400;
            $owner = Auth::isAdmin() ? '' : ' AND uploader_id = ?';
            return $this->json($response, ['error' => $e->getMessage(),
                'usageCount' => $status === 409 && MediaReferences::ready()
                    ? (int) DB::value('SELECT usage_count FROM uploads WHERE id = ?' . $owner, Auth::isAdmin() ? [$id] : [$id, (int) Auth::id()]) : 0], $status);
        }
        if ($row['keep_file']) return $this->json($response, ['ok' => true]);
        $url = (string) $row['url'];
        $resource = MediaReferences::normalizeUrl($url);
        if ($resource !== null && str_starts_with($resource, 'local:/uploads/')) {
            $base = realpath(dirname(__DIR__, 2) . '/public/uploads');
            if ($base !== false) {
                $candidate = realpath($base . DIRECTORY_SEPARATOR . substr($resource, strlen('local:/uploads/')));
                if ($candidate !== false && str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) @unlink($candidate);
            }
        } elseif (preg_match('/^https?:\/\//i', $url) === 1) {
            // 云端文件（完整 http(s) URL，云存储插件托管）→ 调插件删除
            apply_filters('upload_delete_from_cloud', null, ['url' => $url]);
        }
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/uploads/external：添加外部资源（仅存链接） */
    public function external(Request $request, Response $response): Response
    {
        $this->guardCapability('media.manage');
        $body = $request->getParsedBody() ?? [];
        $url = trim((string) ($body['url'] ?? ''));
        if (!preg_match('#^https?://#i', $url)) {
            return $this->json($response, ['error' => '仅支持 http/https 开头的完整地址'], 400);
        }
        if (mb_strlen($url) > 500) {
            return $this->json($response, ['error' => '地址过长（最多 500 字符）'], 400);
        }
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            $path = parse_url($url, PHP_URL_PATH) ?? '';
            $name = rawurldecode((string) basename($path));
            if ($name === '' || $name === '/') {
                $name = '外部资源';
            }
        }
        $name = mb_substr($name, 0, 100);

        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $mime = Upload::MIME_MAP[$ext] ?? 'application/octet-stream';
        $id = MediaReferences::catalogTransaction(static function () use ($name, $url, $mime): int {
            DB::execute(
                'INSERT INTO uploads (original_name, url, mime, size, width, height, uploader_id) VALUES (?, ?, ?, 0, NULL, NULL, ?)',
                [$name, $url, $mime, Auth::id()]
            );
            $id = DB::lastInsertId();
            MediaReferences::register($id, $url);
            return $id;
        });
        return $this->json($response, ['ok' => true, 'id' => (string) $id, 'url' => $url, 'originalName' => $name]);
    }
}
