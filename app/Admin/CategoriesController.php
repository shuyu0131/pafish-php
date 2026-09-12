<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\DB;
use Pafish\Core\Cache;
use Pafish\Services\Categories;
use Pafish\Services\Slug;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 分类管理
 * - 树形列表（depth 缩进 + 文章数）；同级上移/下移（交换 sortOrder）
 * - 创建时 sortOrder = 同父级最大 + 1；删除时子分类与文章自动置 NULL
 * - 防自引用双保险：前端禁用自身+后代（disabledMap），后端 BFS 校验
 */
final class CategoriesController extends AdminController
{
    /** 列表：树 + 每分类文章数 + 防自引用 disabledMap（id → 自身及后代 id 集合） */
    public function index(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $tree = Categories::tree();
        $counts = [];
        foreach (DB::fetchAll('SELECT category_id, COUNT(*) AS c FROM posts WHERE category_id IS NOT NULL GROUP BY category_id') as $row) {
            $counts[(int) $row['category_id']] = (int) $row['c'];
        }
        // 编辑某分类时，其父级下拉需禁用"自身 + 全部后代"（后端 save 仍 BFS 双保险）
        $allRows = DB::fetchAll('SELECT id, parent_id FROM categories');
        $disabledMap = [];
        foreach ($tree as $c) {
            $disabledMap[(int) $c['id']] = array_keys(Categories::descendants($allRows, (int) $c['id']));
        }
        $response->getBody()->write($this->render('categories', [
            'tree' => $tree,
            'counts' => $counts,
            'flatForSelect' => Categories::tree(),
            'disabledMap' => $disabledMap,
        ], '分类管理'));
        return $response;
    }

    /** 保存分类。 */
    public function save(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $body = $request->getParsedBody() ?? [];
        $id = isset($args['id']) ? (int) $args['id'] : 0;

        try {
            $result = $this->saveCategory($body, $id > 0 ? $id : null);
        } catch (\RuntimeException $e) {
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, '/admin/categories');
        }

        Cache::clearPrefix('categories.');
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true, 'id' => $result['id']]);
        }
        $this->flash('success', $result['created'] ? '分类已创建' : '分类已更新');
        return $this->redirect($response, '/admin/categories');
    }

    /** 同级上移/下移（交换 sortOrder） */
    public function move(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $id = (int) ($args['id'] ?? 0);
        $dir = ($request->getParsedBody()['dir'] ?? '') === 'up' ? 'up' : 'down';

        $cat = DB::fetchOne('SELECT * FROM categories WHERE id = ?', [$id]);
        if (!$cat) {
            return $this->json($response, ['error' => '分类不存在'], 404);
        }
        $parent = $cat['parent_id']; // NULL 或 int
        $siblings = DB::fetchAll(
            'SELECT * FROM categories WHERE parent_id <=> ? ORDER BY sort_order ASC, id ASC',
            [$parent]
        );
        $idx = null;
        foreach ($siblings as $i => $s) {
            if ((int) $s['id'] === $id) {
                $idx = $i;
                break;
            }
        }
        $swapWith = $dir === 'up' ? $idx - 1 : $idx + 1;
        if ($idx === null || !isset($siblings[$swapWith])) {
            return $this->json($response, ['ok' => true]); // 边界（已在首/尾）静默
        }
        DB::transaction(function () use ($siblings, $idx, $swapWith): void {
            $ordered = $siblings;
            [$ordered[$idx], $ordered[$swapWith]] = [$ordered[$swapWith], $ordered[$idx]];
            foreach ($ordered as $position => $item) {
                DB::execute('UPDATE categories SET sort_order = ? WHERE id = ?', [$position, (int) $item['id']]);
            }
        });
        Cache::clearPrefix('categories.');
        return $this->json($response, ['ok' => true]);
    }

    /** 删除：子分类与文章 category_id 置 NULL（双保险，不依赖外键）后硬删除 */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $id = (int) ($args['id'] ?? 0);
        $db = DB::pdo();
        $db->beginTransaction();
        try {
            DB::execute('UPDATE categories SET parent_id = NULL WHERE parent_id = ?', [$id]);
            DB::execute('UPDATE posts SET category_id = NULL WHERE category_id = ?', [$id]);
            DB::execute('DELETE FROM categories WHERE id = ?', [$id]);
            Cache::clearPrefix('categories.');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => '删除失败'], 500);
            }
            $this->flash('error', '删除失败');
            return $this->redirect($response, '/admin/categories');
        }
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        $this->flash('success', '分类已删除');
        return $this->redirect($response, '/admin/categories');
    }

    /** 保存逻辑：校验 + slug 去冲突 + 父级防自引用 + sortOrder */
    private function saveCategory(array $body, ?int $id = null): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('分类名称不能为空');
        }
        if (mb_strlen($name) > 100) {
            throw new \RuntimeException('分类名称不能超过 100 字');
        }
        if (DB::fetchOne(
            'SELECT id FROM categories WHERE name = ? AND (? IS NULL OR id <> ?) LIMIT 1',
            [$name, $id, $id]
        ) !== null) {
            throw new \RuntimeException('分类名称已存在');
        }

        $slug = trim((string) ($body['slug'] ?? ''));
        if ($slug === '') {
            $slug = Slug::slugify($name);
        }
        if (mb_strlen($slug) > 100) {
            throw new \RuntimeException('分类地址不能超过 100 字');
        }
        $slug = Slug::resolveUnique($slug, static function (string $candidate) use ($id): bool {
            return DB::fetchOne(
                'SELECT id FROM categories WHERE slug = ? AND (? IS NULL OR id <> ?) LIMIT 1',
                [$candidate, $id, $id]
            ) !== null;
        });

        $description = trim((string) ($body['description'] ?? ''));
        if (mb_strlen($description) > 500) {
            throw new \RuntimeException('分类描述不能超过 500 字');
        }

        // 父分类：0/空视为根（存 NULL）；防自引用/循环后端校验
        $rawParent = (int) ($body['parent_id'] ?? 0);
        $parentId = $rawParent > 0 ? $rawParent : null;
        if ($parentId !== null) {
            if ($id !== null && $parentId === $id) {
                throw new \RuntimeException('父分类不能是自己');
            }
            $parent = DB::fetchOne('SELECT id FROM categories WHERE id = ?', [$parentId]);
            if (!$parent) {
                throw new \RuntimeException('父分类不存在');
            }
            if ($id !== null && !Categories::canBeParent($id, $parentId)) {
                throw new \RuntimeException('不能选择自己的子分类作为父分类');
            }
        }

        if ($id !== null) {
            DB::execute(
                'UPDATE categories SET name = ?, slug = ?, description = ?, parent_id = ? WHERE id = ?',
                [$name, $slug, $description, $parentId, $id]
            );
            return ['id' => $id, 'created' => false];
        }

        // 新建：sortOrder = 同父级最大 + 1
        $sortOrder = (int) DB::value(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM categories WHERE parent_id <=> ?',
            [$parentId]
        );
        DB::execute(
            'INSERT INTO categories (name, slug, description, parent_id, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$name, $slug, $description, $parentId, $sortOrder]
        );
        return ['id' => (int) DB::pdo()->lastInsertId(), 'created' => true];
    }
}
