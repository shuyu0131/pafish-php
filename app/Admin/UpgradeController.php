<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Services\Upgrade;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 系统更新（仅 ADMIN）：
 * - GET /admin/upgrade：旧入口重定向到工作台
 * - POST /admin/upgrade/check：检查更新（JSON，force 跳过自动检查缓存）
 * - POST /admin/upgrade/run：执行更新（下载→校验→备份→覆盖→upgrade.php，失败自动回滚）
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
            // 将最近一次升级状态一并返回，后台才能区分“预检未执行”“已回滚”
            // 和“回滚也失败”，避免统一显示成已经恢复。
            $state = Upgrade::state();
            $diagnostic = null;
            if (is_array($state) && ($state['phase'] ?? '') === 'failed') {
                $diagnostic = array_intersect_key($state, array_flip([
                    'failed_phase', 'failed_path', 'files_modified', 'rollback_completed',
                    'rollback_error', 'database_restore_error', 'target', 'package_sha256',
                ]));
            }
            return $this->json($response, [
                'error' => $e->getMessage(),
                'upgradeState' => $diagnostic,
            ], 400);
        }
    }
}
