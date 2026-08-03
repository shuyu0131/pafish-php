<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Services\Upload;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 媒体库（对齐 Node app/admin/uploads 页 + deleteUpload + POST /api/uploads/external）：
 * - 列表 48/页（q 搜索、type 5 类筛选：图片/文档/压缩包/音频/视频、分页窗口 ±2）
 * - 删除：先删数据库行再删文件（本地路径穿越防护 / 云存储插件 deleteFile 静默失败）
 * - 外部资源：仅存链接不下载（mime 按扩展名推断、size=0）
 * 权限：ADMIN+EDITOR（guardCanManage）；CSRF 由 AdminAuthMiddleware 统一校验
 */
final class MediaController extends AdminController
{
    private const PAGE_SIZE = 48; // 对齐 Node 后台媒体库页 PAGE_SIZE

    /** GET /admin/uploads：媒体库列表 */
    public function index(Request $request, Response $response): Response
    {
        $this->guardCanManage();
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
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);
        $items = DB::fetchAll(
            "SELECT * FROM uploads WHERE {$where} ORDER BY id DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
            $params
        );

        $response->getBody()->write($this->render('uploads', [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'q' => $q,
            'type' => $type,
        ], '媒体库'));
        return $response;
    }

    /** POST /admin/uploads/{id}/delete：先删库再删文件（对齐 Node deleteUpload） */
    public function delete(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $id = (int) ($request->getAttribute('id') ?? 0);
        $row = DB::fetchOne('SELECT * FROM uploads WHERE id = ?', [$id]);
        if ($row === null) {
            return $this->json($response, ['error' => '媒体不存在或已删除'], 400);
        }
        DB::execute('DELETE FROM uploads WHERE id = ?', [$id]);

        $url = (string) $row['url'];
        if (!str_contains($url, '/uploads/')) {
            // 云存储：第一个声明 storage 且实现 deleteFile 的激活插件（M5 接入），失败静默记日志
            apply_filters('upload_delete_from_cloud', null, ['url' => $url]);
        } else {
            // 本地：base 子路径校验防路径穿越后删除（失败忽略，与 Node 一致）
            $base = realpath(dirname(__DIR__, 2) . '/public/uploads');
            if ($base !== false) {
                $path = parse_url($url, PHP_URL_PATH) ?? $url;
                $candidate = realpath($base . DIRECTORY_SEPARATOR . basename((string) $path));
                if ($candidate !== false && str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) {
                    @unlink($candidate);
                }
            }
        }
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/uploads/external：添加外部资源（仅存链接，对齐 Node POST /api/uploads/external） */
    public function external(Request $request, Response $response): Response
    {
        $this->guardCanManage();
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
        DB::execute(
            'INSERT INTO uploads (original_name, url, mime, size, width, height, uploader_id) VALUES (?, ?, ?, 0, NULL, NULL, ?)',
            [$name, $url, $mime, Auth::id()]
        );
        $id = (int) DB::lastInsertId();

        return $this->json($response, ['ok' => true, 'id' => (string) $id, 'url' => $url, 'originalName' => $name]);
    }
}
