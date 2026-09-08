<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\DB;
use Pafish\Services\Slug;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 标签管理
 * - 列表按 name ASC + 文章数；新建/更新/删除
 * - 删除硬删（post_tags 由外键 CASCADE 清理）
 */
final class TagsController extends AdminController
{
    /** 列表（按名称排序并附带文章数量） */
    public function index(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $tags = DB::fetchAll(
            'SELECT t.*, COUNT(pt.post_id) AS post_count
             FROM tags t LEFT JOIN post_tags pt ON pt.tag_id = t.id
             GROUP BY t.id
             ORDER BY t.name ASC'
        );
        $response->getBody()->write($this->render('tags', [
            'tags' => $tags,
        ], '标签管理'));
        return $response;
    }

    /** 保存（新建/更新共用；对齐 createTag/updateTag） */
    public function save(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $body = $request->getParsedBody() ?? [];
        $id = isset($args['id']) ? (int) $args['id'] : 0;

        try {
            $result = $this->saveTag($body, $id > 0 ? $id : null);
        } catch (\RuntimeException $e) {
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, '/admin/tags');
        }

        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true, 'id' => $result['id']]);
        }
        $this->flash('success', $result['created'] ? '标签已创建' : '标签已更新');
        return $this->redirect($response, '/admin/tags');
    }

    /** 删除（硬删；post_tags 级联清理） */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $id = (int) ($args['id'] ?? 0);
        DB::execute('DELETE FROM tags WHERE id = ?', [$id]);
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        $this->flash('success', '标签已删除');
        return $this->redirect($response, '/admin/tags');
    }

    /** 保存逻辑：校验 + slug 去冲突（排除自身） */
    private function saveTag(array $body, ?int $id = null): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('标签名称不能为空');
        }
        if (mb_strlen($name) > 100) {
            throw new \RuntimeException('标签名称不能超过 100 字');
        }
        if (DB::fetchOne(
            'SELECT id FROM tags WHERE name = ? AND (? IS NULL OR id <> ?) LIMIT 1',
            [$name, $id, $id]
        ) !== null) {
            throw new \RuntimeException('标签名称已存在');
        }

        $slug = trim((string) ($body['slug'] ?? ''));
        if ($slug === '') {
            $slug = Slug::slugify($name);
        }
        if (mb_strlen($slug) > 100) {
            throw new \RuntimeException('标签地址不能超过 100 字');
        }
        $slug = Slug::resolveUnique($slug, static function (string $candidate) use ($id): bool {
            return DB::fetchOne(
                'SELECT id FROM tags WHERE slug = ? AND (? IS NULL OR id <> ?) LIMIT 1',
                [$candidate, $id, $id]
            ) !== null;
        });

        if ($id !== null) {
            DB::execute('UPDATE tags SET name = ?, slug = ? WHERE id = ?', [$name, $slug, $id]);
            return ['id' => $id, 'created' => false];
        }
        DB::execute('INSERT INTO tags (name, slug, created_at) VALUES (?, ?, NOW())', [$name, $slug]);
        return ['id' => (int) DB::pdo()->lastInsertId(), 'created' => true];
    }
}
