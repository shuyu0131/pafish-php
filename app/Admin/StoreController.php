<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Services\Store;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 应用商店（对齐 Node app/admin/store/page.tsx + store-view；仅 ADMIN）：
 * - 双 Tab（主题 / 插件），卡片列表：标题/版本/描述/作者 + 已安装徽章/更新可用/安装·更新按钮
 * - 源标识：内置商店 / 远程地址（远程失败回退内置并提示）
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
        foreach (self::KINDS as $kind) {
            $cat = Store::fetchCatalog($kind);
            foreach ($cat['items'] as &$item) {
                $local = Store::getInstalledVersion($kind, $item['name']);
                $item['installed'] = $local !== null;
                $item['localVersion'] = $local ?? '';
                $item['updateAvailable'] = $local !== null && Store::compareVersions($local, $item['version']) < 0;
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
        ], '应用商店'));
        return $response;
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
}
