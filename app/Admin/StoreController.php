<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Services\Store;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 应用商店（仅 ADMIN）：
 * - 双 Tab（主题 / 插件），卡片列表：标题/版本/描述/作者 + 已安装徽章/更新可用/安装·更新按钮
 * - 源标识：远程商店地址 / 未配置提示（远程失败显示 error）
 * - 安装拒绝已存在（提示直接更新）；更新失败自动恢复旧版本
 */
final class StoreController extends AdminController
{
    private const KINDS = ['theme', 'plugin'];

    /** GET /admin/store：商店（双 Tab 目录） */
    public function index(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $cats = [];
        $error = '';
        $accountState = Store::accountStatus();
        $account = $accountState['data'];
        foreach (self::KINDS as $kind) {
            $cat = Store::fetchCatalog($kind);
            foreach ($cat['items'] as &$item) {
                $local = Store::getInstalledVersion($kind, $item['name']);
                $item['installed'] = $local !== null;
                $item['localVersion'] = $local ?? '';
                $item['updateAvailable'] = $local !== null && Store::compareVersions($local, $item['version']) < 0;
                $item['purchased'] = false;
                if ($account) {
                    foreach (($account['licenses'] ?? []) as $license) {
                        if (($license['type'] ?? '') === $kind && ($license['slug'] ?? '') === $item['name'] && ($license['status'] ?? '') === 'active') {
                            $item['purchased'] = true;
                            break;
                        }
                    }
                }
            }
            unset($item);
            if (isset($cat['error']) && $cat['error'] !== '') {
                $error = (string) $cat['error'];
            }
            $cats[$kind] = $cat;
        }
        $response->getBody()->write($this->render('store', [
            'themeCat' => $cats['theme'],
            'pluginCat' => $cats['plugin'],
            'catError' => $error,
            'storeUrl' => Store::baseUrl(),
            'storeAccount' => $account,
            'storeAccountStatus' => $accountState['status'],
            // The store view creates its detail drawer in-place; load its scoped
            // styles through the admin layout so it is visible and fixed above content.
            'headExtra' => '<link rel="stylesheet" href="' . e(asset_url('/css/admin-store-enhanced.css')) . '">',
        ], '应用商店'));
        return $response;
    }

    /** POST /admin/store/refresh：清除目录缓存，由页面重新请求官方运行时目录。 */
    public function refresh(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        Store::clearCatalogCache();
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/store/install：从商店安装 */
    public function install(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        $kind = (string) ($body['kind'] ?? '');
        $name = (string) ($body['name'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            return $this->json($response, ['error' => '无效的类型'], 400);
        }
        try {
            $cat = Store::fetchCatalog($kind);
            $item = self::findItem($cat['items'], $name);
            if ($item === null) {
                throw new \RuntimeException('商店中不存在该条目');
            }
            self::assertPurchaseAccess($kind, $item);
            $result = Store::installFromStore($kind, $name, (string) $cat['base'], $item);
            return $this->json($response, [
                'ok' => true,
                'name' => $result['name'],
                'title' => $result['title'],
                'version' => $result['version'],
            ]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /** POST /admin/store/update：从商店更新（失败自动恢复旧版本） */
    public function update(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        $kind = (string) ($body['kind'] ?? '');
        $name = (string) ($body['name'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            return $this->json($response, ['error' => '无效的类型'], 400);
        }
        try {
            $cat = Store::fetchCatalog($kind);
            $item = self::findItem($cat['items'], $name);
            if ($item === null) {
                throw new \RuntimeException('商店中不存在该条目');
            }
            self::assertPurchaseAccess($kind, $item);
            $result = Store::updateFromStore($kind, $name, (string) $cat['base'], $item);
            return $this->json($response, [
                'ok' => true,
                'name' => $result['name'],
                'title' => $result['title'],
                'version' => $result['version'],
            ]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /** 在目录条目中按名称查找 */
    private static function findItem(array $items, string $name): ?array
    {
        foreach ($items as $item) {
            if (($item['name'] ?? '') === $name) {
                return $item;
            }
        }
        return null;
    }

    /** 付费包只允许已绑定且已购买的官网账号安装或更新，避免绕过界面直接请求下载。 */
    private static function assertPurchaseAccess(string $kind, array $item): void
    {
        if (empty($item['paid'])) {
            return;
        }

        $state = Store::accountStatus();
        if (($state['status'] ?? '') !== 'bound') {
            throw new \RuntimeException('该付费应用需要已绑定的官网账号，请先在「站点设置 → 应用商店」中绑定账号');
        }
        foreach ((array)($state['data']['licenses'] ?? []) as $license) {
            if (($license['type'] ?? '') === $kind
                && ($license['slug'] ?? '') === ($item['name'] ?? '')
                && ($license['status'] ?? '') === 'active') {
                return;
            }
        }
        throw new \RuntimeException('当前绑定的官网账号尚未购买此应用');
    }
}
