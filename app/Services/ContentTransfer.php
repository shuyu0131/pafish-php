<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Auth;
use Pafish\Core\DB;

final class ContentTransfer
{
    public static function export(): array
    {
        $categories = DB::fetchAll('SELECT c.id,c.name,c.slug,c.description,c.parent_id,c.sort_order,c.cover_url,c.seo_title,c.meta_description,p.slug AS parent_slug FROM categories c LEFT JOIN categories p ON p.id=c.parent_id ORDER BY c.id');
        $tags = DB::fetchAll('SELECT id, name, slug, description, seo_title, meta_description FROM tags ORDER BY id');
        $pages = DB::fetchAll('SELECT title, slug, content, content_format, status, template, published_at, seo_title, meta_description, created_at, updated_at FROM pages ORDER BY id');
        $posts = DB::fetchAll('SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.content_format, p.template, p.cover_url, p.status, p.published_at, p.view_count, p.is_pinned, p.category_pinned, p.password, p.external_url, p.custom_fields, p.category_id, p.allow_comments, p.seo_title, p.meta_description, c.slug AS category_slug, p.created_at, p.updated_at FROM posts p LEFT JOIN categories c ON c.id = p.category_id WHERE p.deleted_at IS NULL ORDER BY p.id');
        $tagMap = [];
        foreach (DB::fetchAll('SELECT pt.post_id, t.slug FROM post_tags pt JOIN tags t ON t.id = pt.tag_id') as $row) {
            $tagMap[(int) $row['post_id']][] = $row['slug'];
        }
        foreach ($posts as &$post) {
            $post['tags'] = $tagMap[(int) ($post['id'] ?? 0)] ?? [];
            $post['custom_fields'] = EntityMeta::postCustomFields((int) $post['id'], $post['custom_fields'] ?? null);
            foreach (['auto_cover', 'cover_source'] as $key) {
                $value = EntityMeta::get('post', (int) $post['id'], 'core', $key);
                if ($value !== null) $post[$key] = $value;
            }
        }
        unset($post);
        return ['format' => 'pafish-content', 'version' => 1, 'exportedAt' => date(DATE_ATOM), 'categories' => $categories, 'tags' => $tags, 'pages' => $pages, 'posts' => $posts];
    }

    public static function import(array $payload, string $mode = 'skip'): array
    {
        if (DB::pdo()->inTransaction()) throw new \LogicException('内容导入必须独立提交后刷新缓存');
        if (($payload['format'] ?? '') !== 'pafish-content') {
            throw new \RuntimeException('不是有效的 pafish 内容包');
        }
        $mode = $mode === 'update' ? 'update' : 'skip';
        $counts = ['categories' => 0, 'tags' => 0, 'pages' => 0, 'posts' => 0];
        MediaReferences::transaction(function () use ($payload, $mode, &$counts): void {
            $categoryIds = [];
            $categoryCreated = [];
            $oldSlugs = [];
            foreach (($payload['categories'] ?? []) as $category) {
                if (isset($category['id'])) $oldSlugs[(string) $category['id']] = (string) ($category['slug'] ?? '');
            }
            foreach (($payload['categories'] ?? []) as $row) {
                $slug = trim((string) ($row['slug'] ?? ''));
                if ($slug === '') continue;
                $existing = DB::fetchOne('SELECT * FROM categories WHERE slug = ?', [$slug]);
                $seo = Content::seoFields($row, $existing ?? []);
                $cover = Content::imageUrl($row['cover_url'] ?? $existing['cover_url'] ?? '');
                if ($existing) {
                    $categoryIds[$slug] = (int) $existing['id'];
                    if ($mode === 'update') {
                        DB::execute('UPDATE categories SET name=?,description=?,sort_order=?,cover_url=?,seo_title=?,meta_description=? WHERE id=?',
                            [$row['name'] ?? $existing['name'],$row['description'] ?? $existing['description'],
                             $row['sort_order'] ?? $existing['sort_order'],$cover,$seo['seo_title'],$seo['meta_description'],$existing['id']]);
                        MediaReferences::sync('category', (int)$existing['id']);
                    }
                    continue;
                }
                DB::execute('INSERT INTO categories (name, slug, description, sort_order, cover_url, seo_title, meta_description) VALUES (?, ?, ?, ?, ?, ?, ?)', [(string) ($row['name'] ?? $slug), $slug, $row['description'] ?? null, (int) ($row['sort_order'] ?? 0),$cover,$seo['seo_title'],$seo['meta_description']]);
                $categoryIds[$slug] = DB::lastInsertId(); $categoryCreated[$slug]=true; $counts['categories']++;
                MediaReferences::sync('category', $categoryIds[$slug]);
            }
            $treeRows = DB::fetchAll('SELECT id,parent_id FROM categories ORDER BY id FOR UPDATE');
            $parents = [];
            foreach ($treeRows as $category) $parents[(int) $category['id']] = $category['parent_id'] === null ? null : (int) $category['parent_id'];
            $changedParents = [];
            foreach (($payload['categories'] ?? []) as $row) {
                $slug = trim((string) ($row['slug'] ?? ''));
                if (!isset($categoryIds[$slug]) || ($mode === 'skip' && !isset($categoryCreated[$slug]))) continue;
                if (!array_key_exists('parent_slug', $row) && !array_key_exists('parent_id', $row)) continue;
                $parentSlug = array_key_exists('parent_slug', $row) ? $row['parent_slug'] : ($oldSlugs[(string) ($row['parent_id'] ?? '')] ?? null);
                if (!empty($row['parent_id']) && $parentSlug === null && !array_key_exists('parent_slug', $row)) throw new \RuntimeException('内容包缺少父分类');
                if ($parentSlug !== null && $parentSlug !== '' && !isset($categoryIds[$parentSlug])) throw new \RuntimeException('内容包父分类不存在');
                $id = $categoryIds[$slug];
                $parents[$id] = $parentSlug === null || $parentSlug === '' ? null : $categoryIds[$parentSlug];
                $changedParents[$id] = $parents[$id];
            }
            foreach (array_keys($changedParents) as $id) {
                $seen = [];
                for ($current=$id; $current!==null; $current=$parents[$current] ?? null) {
                    if (isset($seen[$current])) throw new \RuntimeException('分类层级不能循环引用');
                    $seen[$current] = true;
                }
            }
            foreach ($changedParents as $id=>$parentId) DB::execute('UPDATE categories SET parent_id=? WHERE id=?', [$parentId,$id]);
            $tagIds = [];
            foreach (($payload['tags'] ?? []) as $row) {
                $slug = trim((string) ($row['slug'] ?? '')); if ($slug === '') continue;
                $existing = DB::fetchOne('SELECT * FROM tags WHERE slug = ?', [$slug]);
                $seo = Content::seoFields($row, $existing ?? []);
                $description = $row['description'] ?? $existing['description'] ?? '';
                if (!is_string($description) || mb_strlen($description)>500) throw new \RuntimeException('标签描述超出长度限制');
                if ($existing) {
                    $tagIds[$slug] = (int) $existing['id'];
                    if ($mode === 'update') DB::execute('UPDATE tags SET name=?,description=?,seo_title=?,meta_description=? WHERE id=?',
                        [$row['name'] ?? $existing['name'],$description,$seo['seo_title'],$seo['meta_description'],$existing['id']]);
                    continue;
                }
                DB::execute('INSERT INTO tags (name, slug, description, seo_title, meta_description) VALUES (?, ?, ?, ?, ?)', [(string) ($row['name'] ?? $slug), $slug,$description,$seo['seo_title'],$seo['meta_description']]);
                $tagIds[$slug] = DB::lastInsertId(); $counts['tags']++;
            }
            foreach (($payload['pages'] ?? []) as $row) {
                self::upsertPage($row, $mode, $counts);
            }
            foreach (($payload['posts'] ?? []) as $row) {
                self::upsertPost($row, $mode, $categoryIds, $tagIds, $counts);
            }
        });
        \Pafish\Core\Cache::clearPrefix('categories.');
        return $counts;
    }

    private static function upsertPage(array $row, string $mode, array &$counts): void
    {
        $slug = trim((string) ($row['slug'] ?? '')); if ($slug === '') return;
        $contentFormat = Content::format($row['content_format'] ?? 'markdown');
        if ($contentFormat === 'html') $row['content'] = Content::sanitize((string) ($row['content'] ?? ''));
        $existing = DB::fetchOne('SELECT * FROM pages WHERE slug = ?', [$slug]);
        if ($existing && $mode === 'skip') return;
        $seo = Content::seoFields($row, $existing ?? []);
        $values = [(string) ($row['title'] ?? $slug), (string) ($row['content'] ?? ''), (string) ($row['status'] ?? 'DRAFT'), (string) ($row['template'] ?? 'default'), $row['published_at'] ?? null];
        if ($existing) { DB::execute('UPDATE pages SET title = ?, content = ?, status = ?, template = ?, published_at = ? WHERE id = ?', [...$values, (int) $existing['id']]); }
        else { DB::execute('INSERT INTO pages (title, slug, content, status, template, published_at) VALUES (?, ?, ?, ?, ?, ?)', [$values[0], $slug, $values[1], $values[2], $values[3], $values[4]]); $counts['pages']++; }
        DB::execute('UPDATE pages SET content_format = ? WHERE slug = ?', [$contentFormat, $slug]);
        DB::execute('UPDATE pages SET seo_title=?,meta_description=? WHERE slug=?', [$seo['seo_title'],$seo['meta_description'],$slug]);
        MediaReferences::sync('page', (int) DB::value('SELECT id FROM pages WHERE slug = ?', [$slug]));
    }

    private static function upsertPost(array $row, string $mode, array $categoryIds, array $tagIds, array &$counts): void
    {
        $slug = trim((string) ($row['slug'] ?? '')); if ($slug === '') return;
        $contentFormat = Content::format($row['content_format'] ?? 'markdown');
        if ($contentFormat === 'html') $row['content'] = Content::sanitize((string) ($row['content'] ?? ''));
        $existing = DB::fetchOne('SELECT * FROM posts WHERE slug = ?', [$slug]);
        if ($existing && $mode === 'skip') return;
        $autoCover = null;
        if (array_key_exists('auto_cover', $row)) {
            if (!in_array($row['auto_cover'], [true, false, 1, 0, '1', '0'], true)) throw new \RuntimeException('自动封面选项无效');
            $autoCover = in_array($row['auto_cover'], [true, 1, '1'], true) ? '1' : '0';
        }
        $coverSource = null;
        if (array_key_exists('cover_source', $row)) {
            if (!in_array($row['cover_source'], ['auto', 'manual', 'none'], true)) throw new \RuntimeException('封面来源无效');
            $coverSource = $row['cover_source'];
        } elseif ($existing && array_key_exists('cover_url', $row)
            && (string) ($row['cover_url'] ?? '') !== (string) ($existing['cover_url'] ?? '')) {
            // 旧包替换了封面，不能沿用被替换图片的自动来源，否则编辑时可能误清新封面。
            $coverSource = trim((string) ($row['cover_url'] ?? '')) !== '' ? 'manual' : 'none';
        }
        $template = Content::templateName($row['template'] ?? $existing['template'] ?? 'default');
        $seo = Content::seoFields($row, $existing ?? []);
        $allowComments = $row['allow_comments'] ?? $existing['allow_comments'] ?? 1;
        if (!in_array($allowComments,[true,false,1,0,'1','0'],true)) throw new \RuntimeException('评论开关无效');
        $author = Auth::id() ?? (int) DB::value('SELECT id FROM users ORDER BY id LIMIT 1');
        $cat = isset($row['category_slug']) ? ($categoryIds[$row['category_slug']] ?? null) : ($row['category_id'] ?? null);
        // 导出包的字段是数组；旧 TEXT 镜像需序列化，不能让 PDO 把它写成 "Array"。
        if (is_array($row['custom_fields'] ?? null)) $row['custom_fields'] = json_encode($row['custom_fields'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $values = [(string) ($row['title'] ?? $slug), (string) ($row['excerpt'] ?? ''), (string) ($row['content'] ?? ''), $row['cover_url'] ?? null, (string) ($row['status'] ?? 'DRAFT'), $row['published_at'] ?? null, (int) ($row['is_pinned'] ?? 0), (int) ($row['category_pinned'] ?? 0), $row['password'] ?? null, $row['external_url'] ?? null, $row['custom_fields'] ?? null, $author, $cat];
        if ($existing) { DB::execute('UPDATE posts SET title=?, excerpt=?, content=?, cover_url=?, status=?, published_at=?, is_pinned=?, category_pinned=?, password=?, external_url=?, custom_fields=?, category_id=? WHERE id=?', [$values[0],$values[1],$values[2],$values[3],$values[4],$values[5],$values[6],$values[7],$values[8],$values[9],$values[10],$values[12],(int)$existing['id']]); $postId = (int) $existing['id']; }
        else { DB::execute('INSERT INTO posts (title, slug, excerpt, content, cover_url, status, published_at, is_pinned, category_pinned, password, external_url, custom_fields, author_id, category_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$values[0],$slug,$values[1],$values[2],$values[3],$values[4],$values[5],$values[6],$values[7],$values[8],$values[9],$values[10],$values[11],$values[12]]); $postId = DB::lastInsertId(); $counts['posts']++; }
        DB::execute('UPDATE posts SET content_format = ?, template = ? WHERE id = ?', [$contentFormat, $template, $postId]);
        DB::execute('UPDATE posts SET allow_comments=?,seo_title=?,meta_description=? WHERE id=?',
            [(int)(bool)$allowComments,$seo['seo_title'],$seo['meta_description'],$postId]);
        $customFields = $row['custom_fields'] ?? [];
        if (is_string($customFields)) $customFields = json_decode($customFields, true) ?? [];
        EntityMeta::replace('post', $postId, EntityMeta::POST_FIELDS_SCOPE, is_array($customFields) ? $customFields : []);
        if ($autoCover !== null) EntityMeta::set('post', $postId, 'core', 'auto_cover', $autoCover);
        if ($coverSource !== null) EntityMeta::set('post', $postId, 'core', 'cover_source', $coverSource);
        MediaReferences::sync('post', $postId);
        DB::execute('DELETE FROM post_tags WHERE post_id = ?', [$postId]);
        foreach (($row['tags'] ?? []) as $tag) { $slugTag = is_array($tag) ? (string) ($tag['slug'] ?? '') : (string) $tag; if (isset($tagIds[$slugTag])) DB::execute('INSERT IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)', [$postId, $tagIds[$slugTag]]); }
    }
}
