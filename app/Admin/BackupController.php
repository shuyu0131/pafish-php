<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Services\Backup;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 数据备份：仅 ADMIN
 * - 立即备份（mysqldump CLI 优先 / 纯 PHP 兜底）/ 上传 SQL / 列表 / 下载 / 恢复 / 删除
 * - 恢复前自动创建安全备份，需输入文件名二次确认（前端）
 * - upload-* 文件不可删除
 */
final class BackupController extends AdminController
{
    /** GET /admin/backup */
    public function index(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $response->getBody()->write($this->render('backup', [
            'backups' => Backup::list(),
        ], '数据备份'));
        return $response;
    }

    /** POST /admin/backup/create */
    public function create(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        try {
            $file = Backup::create();
            return $this->json($response, ['ok' => true, 'file' => $file]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /** POST /admin/backup/upload：上传 SQL 文件 */
    public function upload(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
            return $this->json($response, ['error' => '请选择文件'], 400);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => '上传失败'], 400);
        }
        try {
            $tmp = (string) $file->getStream()->getMetadata('uri');
            $saved = Backup::saveUploaded($tmp, (string) $file->getClientFilename());
            return $this->json($response, ['ok' => true, 'file' => $saved]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /** POST /admin/backup/restore：恢复（恢复前自动安全备份；需文件名二次确认） */
    public function restore(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        $file = trim((string) ($body['file'] ?? ''));
        $confirm = trim((string) ($body['confirm'] ?? ''));
        if ($confirm !== '' && $confirm !== basename($file)) {
            return $this->json($response, ['error' => '确认名称与备份文件名不一致，已取消恢复'], 400);
        }
        try {
            $safety = Backup::restore($file);
            return $this->json($response, ['ok' => true, 'restored' => basename($file), 'safety' => $safety]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /** POST /admin/backup/delete */
    public function delete(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $file = trim((string) ($request->getParsedBody()['file'] ?? ''));
        try {
            Backup::delete($file);
            return $this->json($response, ['ok' => true]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /** GET /admin/backup/download?file= */
    public function download(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $file = (string) ($_GET['file'] ?? '');
        try {
            $path = Backup::resolve($file);
        } catch (\Throwable $e) {
            $response->getBody()->write($e->getMessage());
            return $response->withStatus(404);
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            $response->getBody()->write('备份文件不存在');
            return $response->withStatus(404);
        }
        return $response
            ->withBody(new \Slim\Psr7\Stream($stream))
            ->withHeader('Content-Type', 'application/sql')
            ->withHeader('Content-Disposition', 'attachment; filename="' . rawurlencode(basename($path)) . '"')
            ->withHeader('Content-Length', (string) filesize($path));
    }
}
