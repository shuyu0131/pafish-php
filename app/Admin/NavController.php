<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 导航菜单管理（对齐 Node app/admin/nav/ + actions.ts 导航系列）：
 * - 列表 sort_order ASC, id ASC；标题「配置顶部导航与移动端菜单（共 N 项）」
 * - 新建 max(sort_order)+1；编辑不动 visible/sort_order；is_external 新窗口
 * - 显隐切换；上下移动=相邻交换；删除两步确认物理删
 * - 字段：label(1-100) / url(1-500) / is_external(bool)
 * 权限：仅 ADMIN（guardAdmin，参考 emlog 编辑不可改外观）；CSRF 由 AdminAuthMiddleware 统一校验
 */
final class NavController extends AdminController
{
    /** GET /admin/nav */
    public function index(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $items = DB::fetchAll('SELECT * FROM nav_items ORDER BY sort_order ASC, id ASC');
        $response->getBody()->write($this->render('nav', [
            'items' => $items,
        ], '导航菜单'));
        return $response;
    }

    /** POST /admin/nav/save 或 /admin/nav/{id}/save（新建/编辑共用） */
    public function save(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $id = isset($args['id']) ? (int) $args['id'] : 0;
        try {
            $label = trim((string) ($request->getParsedBody()['label'] ?? ''));
            $url = trim((string) ($request->getParsedBody()['url'] ?? ''));
            $isExternal = ($request->getParsedBody()['is_external'] ?? '') === '1' ? 1 : 0;
            if ($label === '' || $url === '') {
                throw new \RuntimeException('名称和地址不能为空');
            }
            if (mb_strlen($label) > 100 || mb_strlen($url) > 500) {
                throw new \RuntimeException('内容超出长度限制');
            }

            if ($id > 0) {
                // 编辑：不动 visible / sort_order（对齐 Node updateNavItem）
                DB::execute('UPDATE nav_items SET label = ?, url = ?, is_external = ? WHERE id = ?', [$label, $url, $isExternal, $id]);
            } else {
                $sort = (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM nav_items');
                DB::execute('INSERT INTO nav_items (label, url, sort_order, is_external) VALUES (?, ?, ?, ?)', [$label, $url, $sort, $isExternal]);
            }
        } catch (\RuntimeException $e) {
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, '/admin/nav');
        }

        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        $this->flash('success', $id > 0 ? '导航项已更新' : '导航项已添加');
        return $this->redirect($response, '/admin/nav');
    }

    /** POST /admin/nav/{id}/delete：物理删除 */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        DB::execute('DELETE FROM nav_items WHERE id = ?', [(int) ($args['id'] ?? 0)]);
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/nav/{id}/toggle：显隐切换 */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        DB::execute('UPDATE nav_items SET visible = 1 - visible WHERE id = ?', [(int) ($args['id'] ?? 0)]);
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/nav/{id}/move：与相邻项交换 sort_order（dir: up|down） */
    public function move(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $id = (int) ($args['id'] ?? 0);
        $dir = ($request->getParsedBody()['dir'] ?? '') === 'up' ? 'up' : 'down';
        DB::transaction(function () use ($id, $dir): void {
            $cur = DB::fetchOne('SELECT sort_order FROM nav_items WHERE id = ?', [$id]);
            if ($cur === null) {
                return;
            }
            if ($dir === 'up') {
                $other = DB::fetchOne(
                    'SELECT id, sort_order FROM nav_items WHERE sort_order < ? ORDER BY sort_order DESC, id DESC LIMIT 1',
                    [$cur['sort_order']]
                );
            } else {
                $other = DB::fetchOne(
                    'SELECT id, sort_order FROM nav_items WHERE sort_order > ? ORDER BY sort_order ASC, id ASC LIMIT 1',
                    [$cur['sort_order']]
                );
            }
            if ($other === null) {
                return;
            }
            DB::execute('UPDATE nav_items SET sort_order = ? WHERE id = ?', [$other['sort_order'], $id]);
            DB::execute('UPDATE nav_items SET sort_order = ? WHERE id = ?', [$cur['sort_order'], $other['id']]);
        });
        return $this->json($response, ['ok' => true]);
    }
}
