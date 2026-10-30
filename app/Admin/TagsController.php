<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\DB;
use Pafish\Services\Slug;
use Pafish\Services\PermalinkRedirects;
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
        $this->guardAdmin();
        $tags = DB::fetchAll(
            'SELECT t.*, COUNT(p.id) AS post_count
             FROM tags t LEFT JOIN post_tags pt ON pt.tag_id = t.id
             LEFT JOIN posts p ON p.id = pt.post_id AND p.deleted_at IS NULL
             GROUP BY t.id
             ORDER BY t.name ASC'
        );
        $response->getBody()->write($this->render('tags', [
            'tags' => $tags,
        ], '标签管理'));
        return $response;
    }

    /** 保存标签。 */
    public function save(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        $id = isset($args['id']) ? (int) $args['id'] : 0;

        try {
            $result = \Pafish\Services\MediaReferences::transaction(fn (): array => $this->saveTag($body, $id > 0 ? $id : null));
        } catch (\RuntimeException $e) {
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, '/admin/tags');
        }

        \Pafish\Core\Cache::clearPrefix('nav.');

        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true, 'id' => $result['id']]);
        }
        $this->flash('success', $result['created'] ? '标签已创建' : '标签已更新');
        return $this->redirect($response, '/admin/tags');
    }

    /** 删除（硬删；post_tags 级联清理） */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $id = (int) ($args['id'] ?? 0);
        \Pafish\Services\MediaReferences::transaction(static function () use ($id): void {
            foreach (DB::fetchAll('SELECT id FROM nav_items WHERE tag_id = ? FOR UPDATE', [$id]) as $nav) {
                \Pafish\Services\MediaReferences::forget('nav', (int) $nav['id']);
            }
            if ($id > 0) \Pafish\Services\EntityMeta::deleteSubject('tag', $id);
            DB::execute('DELETE FROM tags WHERE id = ?', [$id]);
        });
        \Pafish\Core\Cache::clearPrefix('nav.');
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        $this->flash('success', '标签已删除');
        return $this->redirect($response, '/admin/tags');
    }

    /** 保存逻辑：校验 + slug 去冲突（排除自身） */
    private function saveTag(array $body, ?int $id = null): array
    {
        $stored = $id !== null ? DB::fetchOne('SELECT * FROM tags WHERE id=?', [$id]) : null;
        if ($id !== null && $stored === null) throw new \RuntimeException('标签不存在');
        $seo = \Pafish\Services\Content::seoFields($body, $stored ?? []);
        $description = $body['description'] ?? $stored['description'] ?? '';
        if (!is_string($description) || mb_strlen($description) > 500) throw new \RuntimeException('标签描述不能超过 500 字');
        $description = trim($description);
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

        $slug = Slug::forSave(
            array_key_exists('slug', $body) ? (string) $body['slug'] : null,
            $name,
            $stored !== null ? (string) $stored['slug'] : null,
            static fn (string $candidate): bool => DB::fetchOne(
                'SELECT id FROM tags WHERE slug = ? AND (? IS NULL OR id <> ?) LIMIT 1', [$candidate, $id, $id]
            ) !== null,
            !empty($body['slug_auto']),
            100
        );
        if (mb_strlen($slug) > 100) {
            throw new \RuntimeException('标签地址不能超过 100 字');
        }
        if ($id !== null) {
            PermalinkRedirects::recordEntityChange('tag', $stored, array_replace($stored, ['slug' => $slug]));
            DB::execute('UPDATE tags SET name = ?, slug = ?, description=?, seo_title=?, meta_description=? WHERE id = ?',
                [$name, $slug, $description, $seo['seo_title'], $seo['meta_description'], $id]);
            return ['id' => $id, 'created' => false];
        }
        DB::execute('INSERT INTO tags (name, slug, description, seo_title, meta_description, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$name, $slug, $description, $seo['seo_title'], $seo['meta_description']]);
        $newId = (int) DB::pdo()->lastInsertId();
        PermalinkRedirects::forgetPaths(\Pafish\Services\Permalinks::paths('tag', ['slug' => $slug]));
        return ['id' => $newId, 'created' => true];
    }
}
