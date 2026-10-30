<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\DB;
use Pafish\Core\Cache;
use Pafish\Services\Categories;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 导航项支持核心内容目标、自定义链接和无链接分组；parent_id 支持任意层级。
 * 权限：仅 ADMIN；CSRF 由 AdminAuthMiddleware 统一校验
 */
final class NavController extends AdminController
{
    /** GET /admin/nav */
    public function index(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $menus = \nav_menus();
        $requestedSlug = trim((string) ($request->getQueryParams()['menu'] ?? ''));
        $selectedMenu = null;
        foreach ($menus as $menu) {
            if ($menu['slug'] === $requestedSlug) { $selectedMenu = $menu; break; }
            if ($selectedMenu === null && (int) $menu['is_primary'] === 1) $selectedMenu = $menu;
        }
        $selectedMenu ??= $menus[0] ?? ['id' => 1, 'name' => '主导航', 'slug' => 'main', 'is_primary' => 1];
        $rows = DB::fetchAll("SELECT n.id, n.item_type, n.label, n.url, n.is_external, n.visible, n.parent_id,
                    n.category_id, n.page_id, n.post_id, n.tag_id,
                    c.name AS category_name, c.slug AS category_slug,
                    pg.title AS page_title, pg.slug AS page_slug,
                    p.title AS post_title, p.slug AS post_slug, p.published_at AS post_published_at, p.created_at AS post_created_at,
                    t.name AS tag_name, t.slug AS tag_slug
             FROM nav_items n
             LEFT JOIN categories c ON c.id = n.category_id
             LEFT JOIN pages pg ON pg.id = n.page_id
             LEFT JOIN posts p ON p.id = n.post_id
             LEFT JOIN tags t ON t.id = n.tag_id
             WHERE n.menu_id = ?
             ORDER BY n.sort_order, n.id", [(int) $selectedMenu['id']]);
        foreach ($rows as &$item) {
            $item['type'] = (string) ($item['item_type'] ?? 'link');
            if ($item['type'] === 'link' && $item['category_id'] !== null) $item['type'] = 'category';
            $item['target_id'] = match ($item['type']) {
                'category' => $item['category_id'], 'page' => $item['page_id'], 'post' => $item['post_id'], 'tag' => $item['tag_id'], default => null,
            };
            switch ($item['type']) {
                case 'category':
                    if ($item['category_id'] !== null && $item['category_name'] !== null) {
                        $item['label'] = (string) $item['category_name'];
                        $item['url'] = \Pafish\Services\Permalinks::path('category', ['id' => $item['category_id'], 'slug' => $item['category_slug']]);
                    }
                    break;
                case 'page':
                    if ($item['page_title'] !== null) {
                        $item['label'] = (string) $item['page_title'];
                        $item['url'] = \Pafish\Services\Permalinks::path('page', ['slug' => $item['page_slug']]);
                    }
                    break;
                case 'post':
                    if ($item['post_title'] !== null) {
                        $item['label'] = (string) $item['post_title'];
                        $item['url'] = \Pafish\Services\Permalinks::path('post', ['slug' => $item['post_slug'], 'published_at' => $item['post_published_at'], 'created_at' => $item['post_created_at']]);
                    }
                    break;
                case 'tag':
                    if ($item['tag_id'] !== null && $item['tag_name'] !== null) {
                        $item['label'] = (string) $item['tag_name'];
                        $item['url'] = \Pafish\Services\Permalinks::path('tag', ['slug' => $item['tag_slug']]);
                    }
                    break;
                case 'home': $item['label'] = '首页'; $item['url'] = '/'; break;
                case 'archive': $item['label'] = '归档'; $item['url'] = '/archives'; break;
                case 'search': $item['label'] = '搜索'; $item['url'] = '/search'; break;
                case 'group': $item['url'] = ''; break;
            }
            if ($item['type'] !== 'link') $item['is_external'] = 0;
            unset($item['item_type'], $item['category_name'], $item['category_slug'], $item['page_title'], $item['page_slug'], $item['post_title'], $item['post_slug'], $item['post_published_at'], $item['post_created_at'], $item['tag_name'], $item['tag_slug']);
        }
        unset($item);
        $items = [];
        $flatten = static function (array $nodes, int $depth = 0) use (&$flatten, &$items): void {
            foreach ($nodes as $position => $node) {
                $children = is_array($node['children'] ?? null) ? $node['children'] : [];
                unset($node['children']);
                $node['depth'] = $depth;
                $node['sibling_index'] = $position;
                $node['sibling_count'] = count($nodes);
                $items[] = $node;
                if ($children !== []) $flatten($children, $depth + 1);
            }
        };
        $flatten(\nav_tree($rows));
        $response->getBody()->write($this->render('nav', [
            'items' => $items,
            'menus' => $menus,
            'selectedMenu' => $selectedMenu,
            'categories' => Categories::tree(),
            'pages' => DB::fetchAll("SELECT id, title, slug FROM pages WHERE status='PUBLISHED' AND deleted_at IS NULL AND (published_at IS NULL OR published_at <= NOW()) ORDER BY title, id"),
            'posts' => DB::fetchAll("SELECT id, title, slug FROM posts WHERE " . \Pafish\Services\Posts::publicWhere('posts') . " ORDER BY title, id"),
            'tags' => DB::fetchAll('SELECT id, name FROM tags ORDER BY name, id'),
            'archivesAvailable' => \Pafish\Services\Theme::hasArchivePage(),
        ], '导航菜单'));
        return $response;
    }

    /** POST /admin/nav/save 或 /admin/nav/{id}/save（新建/编辑共用） */
    public function save(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $id = isset($args['id']) ? (int) $args['id'] : 0;
        try {
            $body = $request->getParsedBody() ?? [];
            $menuId = (int) ($body['menu_id'] ?? DB::value('SELECT id FROM nav_menus WHERE is_primary = 1 ORDER BY id LIMIT 1'));
            if ($menuId < 1 || !DB::fetchOne('SELECT id FROM nav_menus WHERE id = ?', [$menuId])) throw new \RuntimeException('菜单组不存在或已删除');
            $type = (string) ($body['type'] ?? 'link');
            if (!in_array($type, ['link', 'group', 'home', 'archive', 'search', 'category', 'page', 'post', 'tag'], true)) {
                throw new \RuntimeException('导航类型无效');
            }
            if ($type === 'archive' && !\Pafish\Services\Theme::hasArchivePage()) throw new \RuntimeException('当前主题未提供归档页面');
            $categoryId = $pageId = $postId = $tagId = null;
            $isExternal = 0;
            if ($type === 'link' || $type === 'group') {
                $label = trim((string) ($body['label'] ?? ''));
                $url = $type === 'group' ? '' : trim((string) ($body['url'] ?? ''));
                $isExternal = $type === 'link' && ($body['is_external'] ?? '') === '1' ? 1 : 0;
            } elseif (in_array($type, ['category', 'page', 'post', 'tag'], true)) {
                $rawTarget = trim((string) ($body['target_id'] ?? ''));
                if (preg_match('/^(category|page|post|tag):([1-9][0-9]*)$/D', $rawTarget, $targetMatch) === 1) {
                    if ($targetMatch[1] !== $type) throw new \RuntimeException('目标类型与导航类型不一致');
                    $rawTarget = $targetMatch[2];
                }
                if ($rawTarget === '' || !ctype_digit($rawTarget) || (int) $rawTarget < 1) throw new \RuntimeException('请选择目标内容');
                $targetId = (int) $rawTarget;
                [$table, $titleColumn] = match ($type) {
                    'category' => ['categories', 'name'],
                    'page' => ['pages', 'title'],
                    'post' => ['posts', 'title'],
                    'tag' => ['tags', 'name'],
                };
                $where = match ($type) {
                    'page' => " AND status='PUBLISHED' AND deleted_at IS NULL AND (published_at IS NULL OR published_at <= NOW())",
                    'post' => ' AND ' . \Pafish\Services\Posts::publicWhere(),
                    default => '',
                };
                $target = DB::fetchOne("SELECT * FROM {$table} WHERE id = ?{$where}", [$targetId]);
                if ($target === null) throw new \RuntimeException('目标内容不存在、未发布或已删除');
                $label = (string) $target[$titleColumn];
                $url = \Pafish\Services\Permalinks::path($type, $target);
                $categoryId = $type === 'category' ? $targetId : null;
                $pageId = $type === 'page' ? $targetId : null;
                $postId = $type === 'post' ? $targetId : null;
                $tagId = $type === 'tag' ? $targetId : null;
            } else {
                [$label, $url] = match ($type) {
                    'home' => ['首页', '/'], 'archive' => ['归档', '/archives'], 'search' => ['搜索', '/search'],
                };
            }
            $rawParent = trim((string) ($body['parent_id'] ?? ''));
            if ($rawParent !== '' && !ctype_digit($rawParent)) {
                throw new \RuntimeException('上级导航项无效');
            }
            $parentId = $rawParent !== '' && (int) $rawParent > 0 ? (int) $rawParent : null;
            if (($type === 'link' && ($label === '' || $url === '')) || ($type === 'group' && $label === '')) {
                throw new \RuntimeException('名称和地址不能为空');
            }
            if (mb_strlen($label) > 100 || mb_strlen($url) > 500) {
                throw new \RuntimeException('内容超出长度限制');
            }
            if ($type === 'link' && parse_url($url, PHP_URL_SCHEME) !== null
                && (!$isExternal || !\nav_external_url_is_safe($url))) {
                throw new \RuntimeException('外部地址仅支持 HTTP、HTTPS、邮件或电话链接，并需勾选外部链接');
            }
            if ($type === 'link' && parse_url($url, PHP_URL_SCHEME) === null) {
                if ($isExternal || str_starts_with($url, '//')) {
                    throw new \RuntimeException('站内地址请填写以 / 开头的路径；外部地址请填写完整链接');
                }
                if (!str_starts_with($url, '/')) $url = '/' . $url;
                if (!\Pafish\Core\Url::isLocalPath($url)) throw new \RuntimeException('站内地址格式无效');
            }
            if (mb_strlen($url) > 500) throw new \RuntimeException('地址不能超过 500 字');

            \Pafish\Services\MediaReferences::transaction(function () use (&$id, $menuId, $label, $url, $isExternal, $parentId, $categoryId, $pageId, $postId, $tagId, $type): void {
                if ($categoryId !== null) {
                    $category = DB::fetchOne('SELECT id FROM categories WHERE id = ? FOR UPDATE', [$categoryId]);
                    if ($category === null) throw new \RuntimeException('分类不存在或已被删除');
                }
                if ($pageId !== null && !DB::fetchOne("SELECT id FROM pages WHERE id = ? AND status='PUBLISHED' AND deleted_at IS NULL AND (published_at IS NULL OR published_at <= NOW()) FOR UPDATE", [$pageId])) {
                    throw new \RuntimeException('所选页面已不再公开');
                }
                if ($postId !== null && !DB::fetchOne('SELECT id FROM posts WHERE id = ? AND ' . \Pafish\Services\Posts::publicWhere() . ' FOR UPDATE', [$postId])) {
                    throw new \RuntimeException('所选文章已不再公开');
                }
                if ($tagId !== null && !DB::fetchOne('SELECT id FROM tags WHERE id = ? FOR UPDATE', [$tagId])) {
                    throw new \RuntimeException('标签不存在或已删除');
                }
                $rows = DB::fetchAll('SELECT id, parent_id, sort_order FROM nav_items WHERE menu_id = ? FOR UPDATE', [$menuId]);
                $parents = [];
                $sortOrders = [];
                foreach ($rows as $row) {
                    $parents[(int) $row['id']] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
                    $sortOrders[(int) $row['id']] = (int) $row['sort_order'];
                }
                if ($id > 0 && !array_key_exists($id, $parents)) throw new \RuntimeException('导航项不存在或已被删除');
                if ($parentId !== null) {
                    if (!array_key_exists($parentId, $parents)) throw new \RuntimeException('上级导航不存在或已被删除');
                    $seen = [];
                    for ($ancestor = $parentId; $ancestor !== null; $ancestor = $parents[$ancestor] ?? null) {
                        if ($ancestor === $id) throw new \RuntimeException('导航项不能移入自己的子项');
                        if (isset($seen[$ancestor])) throw new \RuntimeException('导航层级已损坏，请先修复循环引用');
                        $seen[$ancestor] = true;
                    }
                }

                if ($id > 0) {
                    $oldParentId = $parents[$id];
                    $sort = $oldParentId === $parentId
                        ? $sortOrders[$id]
                        : (int) DB::value('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM nav_items WHERE menu_id = ? AND parent_id <=> ?', [$menuId, $parentId]);
                    DB::execute('UPDATE nav_items SET item_type = ?, label = ?, url = ?, is_external = ?, category_id = ?, page_id = ?, post_id = ?, tag_id = ?, parent_id = ?, sort_order = ? WHERE id = ?', [$type, $label, $url, $isExternal, $categoryId, $pageId, $postId, $tagId, $parentId, $sort, $id]);
                } else {
                    $sort = (int) DB::value('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM nav_items WHERE menu_id = ? AND parent_id <=> ?', [$menuId, $parentId]);
                    DB::execute('INSERT INTO nav_items (menu_id, item_type, label, url, sort_order, is_external, parent_id, category_id, page_id, post_id, tag_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$menuId, $type, $label, $url, $sort, $isExternal, $parentId, $categoryId, $pageId, $postId, $tagId]);
                    $id = DB::lastInsertId();
                }
                \Pafish\Services\MediaReferences::sync('nav', $id);
            });
        } catch (\RuntimeException $e) {
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, '/admin/nav');
        }

        Cache::clearPrefix('nav.');
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
        $id = (int) ($args['id'] ?? 0);
        \Pafish\Services\MediaReferences::transaction(static function () use ($id): void {
            DB::execute('DELETE FROM nav_items WHERE id = ?', [$id]);
            if ($id > 0) \Pafish\Services\MediaReferences::forget('nav', $id);
        });
        Cache::clearPrefix('nav.');
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/nav/{id}/toggle：显隐切换 */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        DB::execute('UPDATE nav_items SET visible = 1 - visible WHERE id = ?', [(int) ($args['id'] ?? 0)]);
        Cache::clearPrefix('nav.');
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/nav/{id}/move：按实际顺序相邻移动（dir: up|down） */
    public function move(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        try {
            \Pafish\Services\AdminOrdering::move('nav', (int) ($args['id'] ?? 0),
                ($request->getParsedBody()['dir'] ?? '') === 'up' ? 'up' : 'down');
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
        return $this->json($response, ['ok' => true]);
    }

    /** Create or rename a named menu group. */
    public function saveMenu(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $id = (int) ($args['id'] ?? 0);
        $name = trim((string) (($request->getParsedBody() ?? [])['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) return $this->json($response, ['error' => '菜单名称需填写且不能超过 100 字'], 400);
        if ($id > 0) {
            if (!DB::value('SELECT id FROM nav_menus WHERE id = ?', [$id])) return $this->json($response, ['error' => '菜单不存在'], 404);
            DB::execute('UPDATE nav_menus SET name = ? WHERE id = ?', [$name, $id]);
            $slug = (string) DB::value('SELECT slug FROM nav_menus WHERE id = ?', [$id]);
        } else {
            $base = \Pafish\Services\Slug::slugify($name);
            $slug = \Pafish\Services\Slug::resolveUniqueWithinLength($base, static fn (string $candidate): bool => DB::fetchOne('SELECT id FROM nav_menus WHERE slug = ?', [$candidate]) !== null, 100);
            $order = (int) DB::value('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM nav_menus');
            DB::execute('INSERT INTO nav_menus (name, slug, is_primary, sort_order) VALUES (?, ?, 0, ?)', [$name, $slug, $order]);
        }
        Cache::clearPrefix('nav.');
        return $this->json($response, ['ok' => true, 'slug' => $slug]);
    }

    /** Make one menu the default returned to themes that do not specify a menu slug. */
    public function primaryMenu(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $id = (int) ($args['id'] ?? 0);
        if (!DB::fetchOne('SELECT id FROM nav_menus WHERE id = ?', [$id])) return $this->json($response, ['error' => '菜单不存在'], 404);
        DB::transaction(static function () use ($id): void {
            DB::execute('UPDATE nav_menus SET is_primary = 0');
            DB::execute('UPDATE nav_menus SET is_primary = 1 WHERE id = ?', [$id]);
        });
        Cache::clearPrefix('nav.');
        return $this->json($response, ['ok' => true]);
    }

    /** Clone a menu and rebuild its parent links while preserving per-level order. */
    public function cloneMenu(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $sourceId = (int) ($args['id'] ?? 0);
        $source = DB::fetchOne('SELECT * FROM nav_menus WHERE id = ?', [$sourceId]);
        if ($source === null) return $this->json($response, ['error' => '菜单不存在'], 404);
        try {
            $name = mb_substr((string) $source['name'] . ' 副本', 0, 100);
            $base = \Pafish\Services\Slug::slugify($name);
            $slug = \Pafish\Services\Slug::resolveUniqueWithinLength($base, static fn (string $candidate): bool => DB::fetchOne('SELECT id FROM nav_menus WHERE slug = ?', [$candidate]) !== null, 100);
            $newMenuId = \Pafish\Services\MediaReferences::transaction(static function () use ($sourceId, $name, $slug): int {
                $order = (int) DB::value('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM nav_menus');
                DB::execute('INSERT INTO nav_menus (name, slug, is_primary, sort_order) VALUES (?, ?, 0, ?)', [$name, $slug, $order]);
                $menuId = DB::lastInsertId();
                $rows = DB::fetchAll('SELECT * FROM nav_items WHERE menu_id = ? ORDER BY sort_order, id FOR UPDATE', [$sourceId]);
                $map = [];
                $pending = $rows;
                while ($pending !== []) {
                    $progress = false;
                    foreach ($pending as $key => $item) {
                        $oldParent = $item['parent_id'] === null ? null : (int) $item['parent_id'];
                        if ($oldParent !== null && !isset($map[$oldParent])) continue;
                        $parent = $oldParent === null ? null : $map[$oldParent];
                        DB::execute('INSERT INTO nav_items (menu_id, item_type, label, url, sort_order, visible, is_external, parent_id, category_id, page_id, post_id, tag_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                            $menuId, $item['item_type'], $item['label'], $item['url'], $item['sort_order'], $item['visible'], $item['is_external'],
                            $parent, $item['category_id'], $item['page_id'], $item['post_id'], $item['tag_id'],
                        ]);
                        $newId = DB::lastInsertId();
                        $map[(int) $item['id']] = $newId;
                        \Pafish\Services\MediaReferences::sync('nav', $newId);
                        unset($pending[$key]);
                        $progress = true;
                    }
                    if (!$progress) throw new \RuntimeException('菜单层级存在循环，无法复制');
                }
                return $menuId;
            });
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => '复制菜单失败'], 400);
        }
        Cache::clearPrefix('nav.');
        return $this->json($response, ['ok' => true, 'slug' => $slug, 'id' => $newMenuId]);
    }

    /** Delete a menu and choose a new primary menu when needed. */
    public function deleteMenu(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $id = (int) ($args['id'] ?? 0);
        $menus = \nav_menus();
        if (count($menus) <= 1) return $this->json($response, ['error' => '至少要保留一个菜单'], 400);
        $menu = DB::fetchOne('SELECT * FROM nav_menus WHERE id = ?', [$id]);
        if ($menu === null) return $this->json($response, ['error' => '菜单不存在'], 404);
        $primarySlug = (string) ($menu['is_primary'] ? '' : DB::value('SELECT slug FROM nav_menus WHERE is_primary = 1 ORDER BY id LIMIT 1'));
        \Pafish\Services\MediaReferences::transaction(static function () use ($id, $menu, &$primarySlug): void {
            foreach (DB::fetchAll('SELECT id FROM nav_items WHERE menu_id = ? FOR UPDATE', [$id]) as $item) {
                \Pafish\Services\MediaReferences::forget('nav', (int) $item['id']);
            }
            DB::execute('DELETE FROM nav_items WHERE menu_id = ?', [$id]);
            DB::execute('DELETE FROM nav_menus WHERE id = ?', [$id]);
            if ((int) $menu['is_primary'] === 1) {
                $next = DB::fetchOne('SELECT id, slug FROM nav_menus ORDER BY sort_order, id LIMIT 1 FOR UPDATE');
                if ($next !== null) {
                    DB::execute('UPDATE nav_menus SET is_primary = 1 WHERE id = ?', [(int) $next['id']]);
                    $primarySlug = (string) $next['slug'];
                }
            }
        });
        Cache::clearPrefix('nav.');
        return $this->json($response, ['ok' => true, 'primarySlug' => $primarySlug]);
    }

}
