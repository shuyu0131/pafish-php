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
        $notices = [];
        $accountState = Store::accountStatus();
        $account = $accountState['data'];
        foreach (self::KINDS as $kind) {
            $catalog = Store::catalog($kind, 'read');
            $items = $catalog->items;
            foreach ($items as &$item) {
                $local = Store::getInstalledVersion($kind, $item['name']);
                $item['installed'] = $local !== null;
                $item['localVersion'] = $local ?? '';
                $item['updateAvailable'] = $local !== null && Store::compareVersions($local, $item['version']) < 0;
                $item['purchased'] = false;
                if ($account) {
                    foreach (($account['purchases'] ?? $account['licenses'] ?? []) as $license) {
                        if (($license['type'] ?? '') === $kind && ($license['slug'] ?? '') === $item['name'] && ($license['status'] ?? 'active') === 'active') {
                            $item['purchased'] = true;
                            break;
                        }
                    }
                }
            }
            unset($item);
            if ($catalog->notice !== '' && !in_array($catalog->notice, $notices, true)) {
                $notices[] = $catalog->notice;
            }
            $cats[$kind] = [
                'items' => $items,
                'base' => $catalog->base,
                'installable' => $catalog->isInstallable(),
            ];
        }
        $response->getBody()->write($this->render('store', [
            'themeCat' => $cats['theme'],
            'pluginCat' => $cats['plugin'],
            'catalogNotice' => implode('；', $notices),
            'storeUrl' => Store::baseUrl(),
            'storeAccount' => $account,
            'storeAccountStatus' => $accountState['status'],
        ], t('admin.app_store')));
        return $response;
    }

    /** POST /admin/store/refresh：清除目录缓存并立即重新拉取，把失败原因交回页面。 */
    public function refresh(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        Store::clearCatalogCache();
        $notices = [];
        foreach (self::KINDS as $kind) {
            $catalog = Store::catalog($kind, 'refresh');
            if ($catalog->notice !== '' && !in_array($catalog->notice, $notices, true)) {
                $notices[] = $catalog->notice;
            }
        }
        // ok 仍为 true：缓存已被清空是既成事实，error 只表示这次抓取没成功。
        return $this->json($response, ['ok' => true, 'error' => implode('；', $notices)]);
    }

    /** POST /admin/store/install：从商店安装 */
    public function install(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        $kind = (string) ($body['kind'] ?? '');
        $name = (string) ($body['name'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            return $this->json($response, ['error' => t('admin.invalid_type')], 400);
        }
        try {
            $catalog = Store::catalog($kind, 'refresh');
            if (!$catalog->isInstallable()) {
                throw new \RuntimeException(t('admin.the_store_catalogue_is_unavailable_try_again_later'));
            }
            $item = $catalog->find($name);
            if ($item === null) {
                throw new \RuntimeException(t('admin.this_item_was_not_found_in_the_store'));
            }
            self::assertPurchaseAccess($kind, $item);
            $result = Store::installFromStore($kind, $name, $catalog->base, $item);
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
            return $this->json($response, ['error' => t('admin.invalid_type')], 400);
        }
        try {
            $catalog = Store::catalog($kind, 'refresh');
            if (!$catalog->isInstallable()) {
                throw new \RuntimeException(t('admin.the_store_catalogue_is_unavailable_try_again_later'));
            }
            $item = $catalog->find($name);
            if ($item === null) {
                throw new \RuntimeException(t('admin.this_item_was_not_found_in_the_store'));
            }
            self::assertPurchaseAccess($kind, $item);
            $result = Store::updateFromStore($kind, $name, $catalog->base, $item);
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

    /** 付费包只允许已绑定且已购买的官网账号安装或更新，避免绕过界面直接请求下载。 */
    private static function assertPurchaseAccess(string $kind, array $item): void
    {
        if (empty($item['paid'])) {
            return;
        }

        $state = Store::accountStatus();
        if (($state['status'] ?? '') !== 'bound') {
            throw new \RuntimeException(t('admin.link_the_official_account_that_purchased_this_app'));
        }
        foreach ((array)($state['data']['purchases'] ?? $state['data']['licenses'] ?? []) as $license) {
            if (($license['type'] ?? '') === $kind
                && ($license['slug'] ?? '') === ($item['name'] ?? '')
                && ($license['status'] ?? 'active') === 'active') {
                return;
            }
        }
        throw new \RuntimeException(t('admin.the_linked_official_account_has_not_purchased_this'));
    }
}
