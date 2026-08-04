<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 侧边栏组件管理（对齐 Node app/admin/widgets/ + actions.ts 组件系列）：
 * - 6 种内置类型：categories / tags / recent_posts / hot_posts / recent_comments / custom
 * - 列表 sort_order ASC, id ASC；行内编辑可改 type；新建 max(sort_order)+1
 * - title 留空用类型默认标题；content 仅 custom 类型保存，其余强制 NULL
 * - 显隐切换；上下移动=相邻交换；删除两步确认物理删
 * 权限：ADMIN+EDITOR（guardCanManage）；CSRF 由 AdminAuthMiddleware 统一校验
 */
final class WidgetsController extends AdminController
{
    /** 组件类型（对齐 Node WIDGET_TYPES） */
    public const TYPES = ['categories', 'tags', 'recent_posts', 'hot_posts', 'recent_comments', 'custom'];

    /** 类型中文标签（下拉 + 列表徽标） */
    public const TYPE_LABELS = [
        'categories' => '分类',
        'tags' => '标签',
        'recent_posts' => '最新文章',
        'hot_posts' => '热门文章',
        'recent_comments' => '最新评论',
        'custom' => '自定义文本',
    ];

    /** 类型默认标题（对齐 Node WIDGET_DEFAULT_TITLE） */
    public const DEFAULT_TITLES = [
        'categories' => '分类',
        'tags' => '标签',
        'recent_posts' => '最新文章',
        'hot_posts' => '热门文章',
        'recent_comments' => '最新评论',
        'custom' => '自定义',
    ];

    /** GET /admin/widgets */
    public function index(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $items = DB::fetchAll('SELECT * FROM widgets ORDER BY sort_order ASC, id ASC');
        $response->getBody()->write($this->render('widgets', [
            'items' => $items,
            'types' => self::TYPES,
            'typeLabels' => self::TYPE_LABELS,
            'defaultTitles' => self::DEFAULT_TITLES,
        ], '侧边栏组件'));
        return $response;
    }

    /** POST /admin/widgets/save 或 /admin/widgets/{id}/save（新建/编辑共用） */
    public function save(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $id = isset($args['id']) ? (int) $args['id'] : 0;
        try {
            $type = (string) ($request->getParsedBody()['type'] ?? '');
            if (!in_array($type, self::TYPES, true)) {
                throw new \RuntimeException('无效的组件类型');
            }
            $title = trim((string) ($request->getParsedBody()['title'] ?? ''));
            $title = $title === '' ? null : mb_substr($title, 0, 100);
            // content 仅 custom 类型保存，其余强制 NULL（对齐 Node）
            $content = null;
            if ($type === 'custom') {
                $content = mb_substr((string) ($request->getParsedBody()['content'] ?? ''), 0, 5000);
                if (trim($content) === '') {
                    throw new \RuntimeException('自定义组件内容不能为空');
                }
            }

            if ($id > 0) {
                // 编辑：不动 visible / sort_order；可改 type（对齐 Node updateWidget）
                DB::execute('UPDATE widgets SET type = ?, title = ?, content = ? WHERE id = ?', [$type, $title, $content, $id]);
            } else {
                $sort = (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM widgets');
                DB::execute('INSERT INTO widgets (type, title, content, sort_order) VALUES (?, ?, ?, ?)', [$type, $title, $content, $sort]);
            }
        } catch (\RuntimeException $e) {
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, '/admin/widgets');
        }

        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        $this->flash('success', $id > 0 ? '组件已更新' : '组件已添加');
        return $this->redirect($response, '/admin/widgets');
    }

    /** POST /admin/widgets/{id}/delete：物理删除 */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        DB::execute('DELETE FROM widgets WHERE id = ?', [(int) ($args['id'] ?? 0)]);
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/widgets/{id}/toggle：显隐切换 */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        DB::execute('UPDATE widgets SET visible = 1 - visible WHERE id = ?', [(int) ($args['id'] ?? 0)]);
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/widgets/{id}/move：与相邻项交换 sort_order（dir: up|down） */
    public function move(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $id = (int) ($args['id'] ?? 0);
        $dir = ($request->getParsedBody()['dir'] ?? '') === 'up' ? 'up' : 'down';
        DB::transaction(function () use ($id, $dir): void {
            $cur = DB::fetchOne('SELECT sort_order FROM widgets WHERE id = ?', [$id]);
            if ($cur === null) {
                return;
            }
            if ($dir === 'up') {
                $other = DB::fetchOne(
                    'SELECT id, sort_order FROM widgets WHERE sort_order < ? ORDER BY sort_order DESC, id DESC LIMIT 1',
                    [$cur['sort_order']]
                );
            } else {
                $other = DB::fetchOne(
                    'SELECT id, sort_order FROM widgets WHERE sort_order > ? ORDER BY sort_order ASC, id ASC LIMIT 1',
                    [$cur['sort_order']]
                );
            }
            if ($other === null) {
                return;
            }
            DB::execute('UPDATE widgets SET sort_order = ? WHERE id = ?', [$other['sort_order'], $id]);
            DB::execute('UPDATE widgets SET sort_order = ? WHERE id = ?', [$cur['sort_order'], $other['id']]);
        });
        return $this->json($response, ['ok' => true]);
    }
}
