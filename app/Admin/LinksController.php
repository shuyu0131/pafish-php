<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\DB;
use Pafish\Core\Cache;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 友情链接管理：
 * - 列表 sort_order ASC, id ASC；标题「展示在首页底部，共 N 个（含隐藏）」
 * - 新建 max(sort_order)+1 追加末尾；编辑不动 visible/sort_order
 * - 显隐切换；上下移动=与相邻项交换 sort_order（事务）；删除确认后物理删
 * - 字段：name(1-100) / url(1-500) / description(≤255 可空，空串存 NULL)
 * 权限：仅 ADMIN；CSRF 由 AdminAuthMiddleware 统一校验
 */
final class LinksController extends AdminController
{
    /** GET /admin/links */
    public function index(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $items = DB::fetchAll('SELECT * FROM links ORDER BY sort_order ASC, id ASC');
        $response->getBody()->write($this->render('links', [
            'items' => $items,
        ], '友情链接'));
        return $response;
    }

    /** POST /admin/links/save 或 /admin/links/{id}/save（新建/编辑共用） */
    public function save(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $id = isset($args['id']) ? (int) $args['id'] : 0;
        try {
            $name = trim((string) ($request->getParsedBody()['name'] ?? ''));
            $url = trim((string) ($request->getParsedBody()['url'] ?? ''));
            $description = trim((string) ($request->getParsedBody()['description'] ?? ''));
            if ($name === '' || $url === '') {
                throw new \RuntimeException('名称和地址不能为空');
            }
            if (mb_strlen($name) > 100 || mb_strlen($url) > 500 || mb_strlen($description) > 255) {
                throw new \RuntimeException('内容超出长度限制');
            }
            $description = $description === '' ? null : $description;
            $stored = $id > 0 ? DB::fetchOne('SELECT icon_url FROM links WHERE id=?', [$id]) : null;
            if ($id > 0 && !$stored) throw new \RuntimeException('链接不存在');
            $iconUrl = \Pafish\Services\Content::imageUrl($request->getParsedBody()['icon_url'] ?? $stored['icon_url'] ?? '');

            \Pafish\Services\MediaReferences::transaction(function () use (&$id, $name, $url, $description, $iconUrl): void {
                if ($id > 0) {
                    // 编辑：不动 visible / sort_order
                    DB::execute('UPDATE links SET name = ?, url = ?, description = ?, icon_url=? WHERE id = ?', [$name, $url, $description, $iconUrl, $id]);
                } else {
                    // 新建：追加到末尾
                    $sort = (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM links');
                    DB::execute('INSERT INTO links (name, url, description, sort_order, icon_url) VALUES (?, ?, ?, ?, ?)', [$name, $url, $description, $sort, $iconUrl]);
                    $id = DB::lastInsertId();
                }
                \Pafish\Services\MediaReferences::sync('link', $id);
            });
        } catch (\RuntimeException $e) {
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, '/admin/links');
        }

        if ($this->isAjax($request)) {
            Cache::clearPrefix('links.');
            return $this->json($response, ['ok' => true]);
        }
        Cache::clearPrefix('links.');
        $this->flash('success', $id > 0 ? '链接已更新' : '链接已添加');
        return $this->redirect($response, '/admin/links');
    }

    /** POST /admin/links/{id}/delete：物理删除 */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $id = (int) ($args['id'] ?? 0);
        \Pafish\Services\MediaReferences::transaction(static function () use ($id): void {
            DB::execute('DELETE FROM links WHERE id = ?', [$id]);
            if ($id > 0) \Pafish\Services\MediaReferences::forget('link', $id);
        });
        Cache::clearPrefix('links.');
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/links/{id}/toggle：显隐切换 */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $id = (int) ($args['id'] ?? 0);
        DB::execute('UPDATE links SET visible = 1 - visible WHERE id = ?', [$id]);
        Cache::clearPrefix('links.');
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/links/{id}/move：按实际顺序相邻移动（dir: up|down，边界直接返回） */
    public function move(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        try {
            \Pafish\Services\AdminOrdering::move('links', (int) ($args['id'] ?? 0),
                ($request->getParsedBody()['dir'] ?? '') === 'up' ? 'up' : 'down');
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
        return $this->json($response, ['ok' => true]);
    }

    public function reorder(Request $request, Response $response): Response
    {
        return $this->reorderList($request, $response, 'links');
    }
}
