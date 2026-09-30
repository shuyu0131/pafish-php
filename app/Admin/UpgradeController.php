<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Services\Upgrade;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 系统更新（仅 ADMIN）：
 * - GET /admin/upgrade：旧入口重定向到工作台
 * - POST /admin/upgrade/check：检查更新（JSON，force 跳过 24h 缓存）
 * - POST /admin/upgrade/run：执行更新（下载→校验→备份→覆盖→upgrade.php，失败自动回滚）
 * - POST /admin/upgrade/upload：上传开发版 ZIP 并执行同一套校验与回滚流程
 */
final class UpgradeController extends AdminController
{
    /** GET /admin/upgrade：旧入口兼容重定向到工作台 */
    public function index(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        return $this->redirect($response, '/admin');
    }

    /** POST /admin/upgrade/check：检查更新（JSON） */
    public function check(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        $force = !empty($body['force']);
        $info = Upgrade::check($force);
        if (($info['error'] ?? '') !== '' && ($info['latest'] ?? '') === '') {
            return $this->json($response, ['error' => $info['error']], 400);
        }
        return $this->json($response, ['ok' => true] + $info);
    }

    /** POST /admin/upgrade/run：执行更新（失败自动回滚） */
    public function run(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        try {
            $result = Upgrade::run();
            return $this->json($response, $result);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /** POST /admin/upgrade/upload：上传开发版更新包并执行更新 */
    public function upload(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $file = $request->getUploadedFiles()['package'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
            return $this->json($response, ['error' => '请选择更新包'], 400);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => '更新包上传失败，请检查上传大小限制'], 400);
        }
        if ((int) ($file->getSize() ?? 0) > 16 * 1024 * 1024) {
            return $this->json($response, ['error' => '更新包超过 16MB 限制'], 400);
        }
        $source = $file->getStream()->getMetadata('uri');
        if (!is_string($source) || !is_file($source)) {
            return $this->json($response, ['error' => '无法读取上传的更新包'], 400);
        }
        try {
            return $this->json($response, Upgrade::runUploaded($source));
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }
}
