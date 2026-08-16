<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Version;
use Pafish\Services\Upgrade;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 系统更新（仅 ADMIN）：
 * - GET /admin/upgrade：更新页面（当前版本/最新版本/变更说明/检查·更新按钮）
 * - POST /admin/upgrade/check：检查更新（JSON，force 跳过 24h 缓存）
 * - POST /admin/upgrade/run：执行更新（下载→校验→备份→覆盖→upgrade.php，失败自动回滚）
 */
final class UpgradeController extends AdminController
{
    /** GET /admin/upgrade：系统更新页 */
    public function index(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $info = Upgrade::check();
        $current = Version::current();
        $minVersion = (string) ($info['minVersion'] ?? '');
        $minOk = $minVersion === '' || Version::compare($current, $minVersion) >= 0;
        $response->getBody()->write($this->render('upgrade', [
            'info' => $info,
            'current' => $current,
            'minOk' => $minOk,
        ], '系统更新'));
        return $response;
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
}
