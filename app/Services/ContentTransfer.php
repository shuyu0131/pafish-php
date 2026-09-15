<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Auth;
use Pafish\Core\DB;

final class ContentTransfer
{
    public static function export(): array
    {
        $categories = DB::fetchAll('SELECT id, name, slug, description, parent_id, sort_order FROM categories ORDER BY id');
        $tags = DB::fetchAll('SELECT id, name, slug FROM tags ORDER BY id');
        $pages = DB::fetchAll('SELECT title, slug, content, status, template, published_at, created_at, updated_at FROM pages ORDER BY id');
        $posts = DB::fetchAll('SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.cover_url, p.status, p.published_at, p.view_count, p.is_pinned, p.category_pinned, p.password, p.external_url, p.custom_fields, p.category_id, c.slug AS category_slug, p.created_at, p.updated_at FROM posts p LEFT JOIN categories c ON c.id = p.category_id WHERE p.deleted_at IS NULL ORDER BY p.id');
        $tagMap = [];
        foreach (DB::fetchAll('SELECT pt.post_id, t.slug FROM post_tags pt JOIN tags t ON t.id = pt.tag_id') as $row) {
            $tagMap[(int) $row['post_id']][] = $row['slug'];
        }
        foreach ($posts as &$post) {
            $post['tags'] = $tagMap[(int) ($post['id'] ?? 0)] ?? [];
        }
        unset($post);
        return ['format' => 'pafish-content', 'version' => 1, 'exportedAt' => date(DATE_ATOM), 'categories' => $categories, 'tags' => $tags, 'pages' => $pages, 'posts' => $posts];
    }

    public static function import(array $payload, string $mode = 'skip'): array
    {
        if (($payload['format'] ?? '') !== 'pafish-content') {
            throw new \RuntimeException('不是有效的 pafish 内容包');
        }
        $mode = $mode === 'update' ? 'update' : 'skip';
        $counts = ['categories' => 0, 'tags' => 0, 'pages' => 0, 'posts' => 0];
        DB::transaction(function () use ($payload, $mode, &$counts): void {
            $categoryIds = [];
            foreach (($payload['categories'] ?? []) as $row) {
                $slug = trim((string) ($row['slug'] ?? ''));
                if ($slug === '') continue;
                $existing = DB::fetchOne('SELECT id FROM categories WHERE slug = ?', [$slug]);
                if ($existing) { $categoryIds[$slug] = (int) $existing['id']; continue; }
                DB::execute('INSERT INTO categories (name, slug, description, sort_order) VALUES (?, ?, ?, ?)', [(string) ($row['name'] ?? $slug), $slug, $row['description'] ?? null, (int) ($row['sort_order'] ?? 0)]);
                $categoryIds[$slug] = DB::lastInsertId(); $counts['categories']++;
            }
            $tagIds = [];
            foreach (($payload['tags'] ?? []) as $row) {
                $slug = trim((string) ($row['slug'] ?? '')); if ($slug === '') continue;
                $existing = DB::fetchOne('SELECT id FROM tags WHERE slug = ?', [$slug]);
                if ($existing) { $tagIds[$slug] = (int) $existing['id']; continue; }
                DB::execute('INSERT INTO tags (name, slug) VALUES (?, ?)', [(string) ($row['name'] ?? $slug), $slug]);
                $tagIds[$slug] = DB::lastInsertId(); $counts['tags']++;
            }
            foreach (($payload['pages'] ?? []) as $row) {
                self::upsertPage($row, $mode, $counts);
            }
            foreach (($payload['posts'] ?? []) as $row) {
                self::upsertPost($row, $mode, $categoryIds, $tagIds, $counts);
            }
        });
        return $counts;
    }

    private static function upsertPage(array $row, string $mode, array &$counts): void
    {
        $slug = trim((string) ($row['slug'] ?? '')); if ($slug === '') return;
        $existing = DB::fetchOne('SELECT id FROM pages WHERE slug = ?', [$slug]);
        if ($existing && $mode === 'skip') return;
        $values = [(string) ($row['title'] ?? $slug), (string) ($row['content'] ?? ''), (string) ($row['status'] ?? 'DRAFT'), (string) ($row['template'] ?? 'default'), $row['published_at'] ?? null];
        if ($existing) { DB::execute('UPDATE pages SET title = ?, content = ?, status = ?, template = ?, published_at = ? WHERE id = ?', [...$values, (int) $existing['id']]); }
        else { DB::execute('INSERT INTO pages (title, slug, content, status, template, published_at) VALUES (?, ?, ?, ?, ?, ?)', [$values[0], $slug, $values[1], $values[2], $values[3], $values[4]]); $counts['pages']++; }
    }

    private static function upsertPost(array $row, string $mode, array $categoryIds, array $tagIds, array &$counts): void
    {
        $slug = trim((string) ($row['slug'] ?? '')); if ($slug === '') return;
        $existing = DB::fetchOne('SELECT id FROM posts WHERE slug = ?', [$slug]);
        if ($existing && $mode === 'skip') return;
        $author = Auth::id() ?? (int) DB::value('SELECT id FROM users ORDER BY id LIMIT 1');
        $cat = isset($row['category_slug']) ? ($categoryIds[$row['category_slug']] ?? null) : ($row['category_id'] ?? null);
        $values = [(string) ($row['title'] ?? $slug), (string) ($row['excerpt'] ?? ''), (string) ($row['content'] ?? ''), $row['cover_url'] ?? null, (string) ($row['status'] ?? 'DRAFT'), $row['published_at'] ?? null, (int) ($row['is_pinned'] ?? 0), (int) ($row['category_pinned'] ?? 0), $row['password'] ?? null, $row['external_url'] ?? null, $row['custom_fields'] ?? null, $author, $cat];
        if ($existing) { DB::execute('UPDATE posts SET title=?, excerpt=?, content=?, cover_url=?, status=?, published_at=?, is_pinned=?, category_pinned=?, password=?, external_url=?, custom_fields=?, category_id=? WHERE id=?', [$values[0],$values[1],$values[2],$values[3],$values[4],$values[5],$values[6],$values[7],$values[8],$values[9],$values[10],$values[12],(int)$existing['id']]); $postId = (int) $existing['id']; }
        else { DB::execute('INSERT INTO posts (title, slug, excerpt, content, cover_url, status, published_at, is_pinned, category_pinned, password, external_url, custom_fields, author_id, category_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$values[0],$slug,$values[1],$values[2],$values[3],$values[4],$values[5],$values[6],$values[7],$values[8],$values[9],$values[10],$values[11],$values[12]]); $postId = DB::lastInsertId(); $counts['posts']++; }
        DB::execute('DELETE FROM post_tags WHERE post_id = ?', [$postId]);
        foreach (($row['tags'] ?? []) as $tag) { $slugTag = is_array($tag) ? (string) ($tag['slug'] ?? '') : (string) $tag; if (isset($tagIds[$slugTag])) DB::execute('INSERT IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)', [$postId, $tagIds[$slugTag]]); }
    }
}
