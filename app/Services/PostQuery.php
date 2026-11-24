<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;
use Pafish\Core\Hooks;

/** Public content queries shared by controllers and theme modules. */
final class PostQuery
{
    private const OPTIONS = [
        'page', 'per_page', 'author_id', 'category_ids', 'tag_ids', 'include_ids', 'exclude_ids',
        'search', 'order_by', 'direction', 'pinned', 'protected', 'meta', 'related_to',
        'recent_comments', 'include_owned_private', 'include_child_categories', 'context',
    ];
    private const ORDER = [
        'date' => 'p.published_at', 'updated' => 'p.updated_at', 'id' => 'p.id',
        'title' => 'p.title', 'views' => 'p.view_count', 'likes' => 'p.like_count',
        'favorites' => 'p.favorite_count',
    ];

    /** @return array{posts:array,total:int,perPage:int,pageNum:int,totalPages:int} */
    public static function query(array $args = []): array
    {
        $context = $args['context'] ?? 'theme';
        if (!is_string($context) || preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/D', $context) !== 1) {
            throw new \InvalidArgumentException('文章查询 context 无效');
        }
        $args = Theme::preparePostQuery($args);
        $args = Hooks::applyFilters('posts_query_args', $args, ['context' => $context]);
        if (!is_array($args) || array_diff(array_keys($args), self::OPTIONS) !== []) {
            throw new \InvalidArgumentException('文章查询包含不支持的参数');
        }
        $page = self::number($args['page'] ?? 1);
        $perPage = self::number($args['per_page'] ?? Settings::get('posts_per_page', '10'));
        $perPage = max(1, min(50, (int) Hooks::applyFilters('posts_per_page', $perPage, [
            'context' => 'list', 'per_page' => $args['per_page'] ?? null, 'query_context' => $context,
        ])));
        $orderBy = $args['order_by'] ?? 'date';
        $direction = $args['direction'] ?? 'DESC';
        $protected = $args['protected'] ?? 'include';
        if (!is_string($orderBy) || !isset(self::ORDER[$orderBy])
            || !in_array($direction, ['ASC', 'DESC'], true)
            || !in_array($protected, ['include', 'exclude'], true)) {
            throw new \InvalidArgumentException('文章查询排序或密码文章策略无效');
        }
        foreach (['pinned', 'recent_comments', 'include_owned_private', 'include_child_categories'] as $flag) {
            if (isset($args[$flag]) && !is_bool($args[$flag])) throw new \InvalidArgumentException('文章查询开关必须为布尔值');
        }
        $pinned = $args['pinned'] ?? true;
        $order = ($pinned ? 'p.is_pinned DESC, ' : '') . self::ORDER[$orderBy] . ' ' . $direction;
        if ($orderBy !== 'id') $order .= ', p.id ' . $direction;
        $where = [];
        $params = [];
        if (isset($args['author_id'])) {
            $where[] = 'p.author_id=?';
            $params[] = self::number($args['author_id'], false);
        }
        foreach (['include_ids' => 'IN', 'exclude_ids' => 'NOT IN'] as $key => $operator) {
            $ids = self::ids($args[$key] ?? []);
            if ($ids !== []) {
                $where[] = 'p.id ' . $operator . ' (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                array_push($params, ...$ids);
            }
        }
        $categoryIds = self::ids($args['category_ids'] ?? []);
        if ($categoryIds !== [] && ($args['include_child_categories'] ?? false)) {
            // Bound caller input, not the number of descendants in a site's category tree.
            $categories = DB::fetchAll('SELECT id,parent_id FROM categories');
            $expanded = [];
            foreach ($categoryIds as $rootId) $expanded += Categories::descendants($categories, $rootId);
            $categoryIds = array_keys($expanded);
        }
        $tagIds = self::ids($args['tag_ids'] ?? []);
        if ($tagIds !== []) {
            $where[] = 'EXISTS (SELECT 1 FROM post_tags pt WHERE pt.post_id=p.id AND pt.tag_id IN (' . implode(',', array_fill(0, count($tagIds), '?')) . '))';
            array_push($params, ...$tagIds);
        }
        $unprotected = "(p.password IS NULL OR p.password='')";
        if ($protected === 'exclude') $where[] = $unprotected;
        $search = $args['search'] ?? '';
        if (!is_string($search) || mb_strlen($search) > 1000) throw new \InvalidArgumentException('文章查询关键词无效或过长');
        if ($search !== '') {
            $where[] = "(p.title LIKE ? OR ({$unprotected} AND (MATCH(p.title,p.excerpt,p.content) AGAINST (? IN NATURAL LANGUAGE MODE) OR p.excerpt LIKE ? OR p.content LIKE ?)))";
            array_push($params, '%' . $search . '%', $search, '%' . $search . '%', '%' . $search . '%');
        }
        $meta = $args['meta'] ?? [];
        if (!is_array($meta) || count($meta) > 10) throw new \InvalidArgumentException('文章元字段查询最多允许十个条件');
        if ($meta !== []) $where[] = $unprotected; // Do not expose protected metadata through a match oracle.
        foreach ($meta as $condition) {
            if (!is_array($condition) || array_diff(array_keys($condition), ['scope','key','value','compare']) !== []
                || !is_string($condition['scope'] ?? null)
                || preg_match('/^(?:core(?::custom_fields)?|(?:theme|plugin):[a-z0-9_-]{1,50})$/D', $condition['scope']) !== 1
                || !is_string($condition['key'] ?? null) || $condition['key'] === '' || mb_strlen($condition['key']) > 191) {
                throw new \InvalidArgumentException('文章元字段查询条件无效');
            }
            $compare = $condition['compare'] ?? '=';
            if (!in_array($compare, ['=', 'EXISTS', 'NOT EXISTS'], true)) throw new \InvalidArgumentException('文章元字段比较方式无效');
            $clause = "SELECT 1 FROM entity_meta em WHERE em.subject_type='post' AND em.subject_id=p.id AND em.scope=? AND em.key_hash=? AND em.meta_key=?";
            array_push($params, $condition['scope'], hash('sha256', $condition['key'], true), $condition['key']);
            if ($compare === '=') {
                if (!is_string($condition['value'] ?? null) || strlen($condition['value']) > 4096) throw new \InvalidArgumentException('文章元字段查询值无效或过长');
                $clause .= ' AND em.meta_value=?';
                $params[] = $condition['value'];
            }
            $where[] = ($compare === 'NOT EXISTS' ? 'NOT EXISTS' : 'EXISTS') . ' (' . $clause . ')';
        }
        if (isset($args['related_to'])) {
            $sourceId = self::number($args['related_to'], false);
            $source = Posts::findReadable($sourceId, true);
            $parts = [];
            $where[] = 'p.id<>?'; $params[] = $sourceId;
            if (!empty($source['category_id'])) { $parts[] = 'p.category_id=?'; $params[] = (int) $source['category_id']; }
            $parts[] = 'EXISTS (SELECT 1 FROM post_tags related_tag JOIN post_tags source_tag ON source_tag.tag_id=related_tag.tag_id WHERE related_tag.post_id=p.id AND source_tag.post_id=?)';
            $params[] = $sourceId;
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        [$posts, $total, $perPage, $page] = self::published(implode(' AND ', $where), $params, $page, $perPage, $order, $categoryIds,
            $args['recent_comments'] ?? false, $args['include_owned_private'] ?? true, $pinned && $orderBy === 'date' && $direction === 'DESC');
        return ['posts' => $posts, 'total' => $total, 'perPage' => $perPage, 'pageNum' => $page, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    private static function number(mixed $value, bool $clamp = true): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1)) {
            throw new \InvalidArgumentException('文章查询分页和 ID 必须为整数');
        }
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false) throw new \InvalidArgumentException('文章查询整数超出范围');
        if (!$clamp && $number < 1) throw new \InvalidArgumentException('文章查询 ID 必须大于零');
        return max(1, $number);
    }

    private static function ids(mixed $values): array
    {
        if (!is_array($values) || count($values) > 100) throw new \InvalidArgumentException('文章查询 ID 列表最多允许一百项');
        return array_values(array_unique(array_map(static fn (mixed $id): int => self::number($id, false), $values)));
    }

    /** Internal SQL compatibility entry for existing Listings callers; never pass request SQL here. */
    public static function published(string $extraWhere, array $params, int $pageNum, int $perPage,
        string $order = 'p.is_pinned DESC, p.published_at DESC, p.id DESC', array $categoryIds = [],
        bool $recentComments = true, bool $includeOwnedPrivate = true, bool $categoryPins = true): array
    {
        $pageNum = max(1, $pageNum); $perPage = max(1, min(50, $perPage));
        $where = Posts::publicWhere('p', $includeOwnedPrivate) . ($extraWhere !== '' ? " AND ({$extraWhere})" : '');
        $index = '';
        if ($categoryIds !== []) {
            $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $where .= " AND p.category_id IN ({$placeholders})";
            $params = array_merge($params, $categoryIds);
            if ($categoryPins) $order = 'p.is_pinned DESC, p.category_pinned DESC, p.published_at DESC, p.id DESC';
            $index = ' FORCE INDEX (idx_posts_category)';
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM posts p{$index} WHERE {$where}", $params);
        if ($total === 0 || $pageNum > intdiv($total - 1, $perPage) + 1) return [[], $total, $perPage, $pageNum];
        $offset = ($pageNum - 1) * $perPage;
        $posts = DB::fetchAll(
            "SELECT p.id, p.title, p.slug,
                    CASE WHEN p.password IS NOT NULL AND p.password <> '' THEN '' ELSE p.excerpt END AS excerpt,
                    CASE WHEN p.password IS NOT NULL AND p.password <> '' THEN '' ELSE p.content END AS content,
                    p.content_format, p.template, p.cover_url,
                    CASE WHEN p.password IS NOT NULL AND p.password <> '' THEN NULL ELSE p.custom_fields END AS custom_fields,
                    p.published_at, p.created_at, p.author_id, p.is_pinned, p.category_pinned,
                    (p.password IS NOT NULL AND p.password <> '') AS is_password_protected,
                    p.external_url, p.view_count, p.like_count, p.favorite_count, p.allow_comments, p.seo_title,
                    CASE WHEN p.password IS NOT NULL AND p.password <> '' THEN '' ELSE p.meta_description END AS meta_description,
                    (SELECT COUNT(*) FROM comments c WHERE c.post_id=p.id AND c.status='APPROVED') AS comment_count,
                    COALESCE(NULLIF(u.nickname, ''), u.username) AS author_name, u.avatar_url AS author_avatar,
                    c.id AS category_id, c.name AS category_name, c.slug AS category_slug
             FROM (SELECT p.id FROM posts p{$index} WHERE {$where} ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}) page_ids
             JOIN posts p ON p.id=page_ids.id
             LEFT JOIN users u ON u.id=p.author_id LEFT JOIN categories c ON c.id=p.category_id
             ORDER BY {$order}", $params);
        $posts = self::attachTags($posts);
        foreach ($posts as &$post) {
            $post['cover_url'] = Content::safeImageUrl($post['cover_url'] ?? null);
            $post['author_avatar'] = Content::safeImageUrl($post['author_avatar'] ?? null);
            $post['is_password_protected'] = (bool) $post['is_password_protected'];
            if ($recentComments) $post['recent_comments'] = [];
        }
        unset($post);
        if ($recentComments) {
            $ids = array_column(array_filter($posts, static fn (array $post): bool => !$post['is_password_protected']), 'id');
            $recent = Comments::recentForPosts($ids);
            foreach ($posts as &$post) $post['recent_comments'] = $recent[(int) $post['id']] ?? [];
            unset($post);
        }
        return [$posts, $total, $perPage, $pageNum];
    }

    public static function attachTags(array $posts): array
    {
        if ($posts === []) return [];
        $ids = array_map('intval', array_column($posts, 'id'));
        $rows = DB::fetchAll('SELECT pt.post_id,t.name,t.slug FROM post_tags pt JOIN tags t ON t.id=pt.tag_id WHERE pt.post_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        $map = [];
        foreach ($rows as $row) $map[(int) $row['post_id']][] = ['name' => $row['name'], 'slug' => $row['slug']];
        foreach ($posts as &$post) $post['tags'] = $map[(int) $post['id']] ?? [];
        unset($post);
        return $posts;
    }
}
