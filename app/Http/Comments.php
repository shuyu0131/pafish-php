<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Core\Session;
use Pafish\Core\Url;

/**
 * 评论区数据：
 * 顶层评论分页（每页 20，置顶优先 + 时间正序）、完整加载回复并限制显示深度、
 * 父节点缺失的回复按顶层展示（不丢评论）
 */
final class Comments
{
    private const PAGE_SIZE = 20;
    private const MAX_DEPTH = 5; // 楼中楼最多 5 层，防深链滥用

    /** 构建评论区完整数据：roots（树）+ total + totalPages */
    public static function section(int $postId, int $page): array
    {
        $page = max(1, $page);

        $base = "c.post_id = ? AND c.status = 'APPROVED'";
        $total = (int) DB::value(
            "SELECT COUNT(*) FROM comments c WHERE {$base}",
            [$postId]
        );
        $root = "(c.parent_id IS NULL OR NOT EXISTS (SELECT 1 FROM comments parent WHERE parent.id=c.parent_id AND parent.post_id=c.post_id AND parent.status='APPROVED'))";
        $topCount = (int) DB::value(
            "SELECT COUNT(*) FROM comments c WHERE {$base} AND {$root}",
            [$postId]
        );

        // 顶层评论分页（置顶优先，其余按时间正序）
        $columns = self::columns();
        $topComments = DB::fetchAll(
            "SELECT {$columns} FROM comments c
             LEFT JOIN users u ON u.id = c.user_id
             WHERE {$base} AND {$root}
             ORDER BY c.is_pinned DESC, c.created_at ASC,c.id ASC
             LIMIT " . self::PAGE_SIZE . " OFFSET " . (($page - 1) * self::PAGE_SIZE),
            [$postId]
        );

        // Load every descendant of the paginated roots, in bounded query batches.
        $all = $topComments;
        $seen = array_fill_keys(array_column($all, 'id'), true);
        $batch = array_column($all, 'id');
        while ($batch !== []) {
            $next = [];
            foreach (array_chunk($batch, 200) as $ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $kids = DB::fetchAll("SELECT {$columns} FROM comments c LEFT JOIN users u ON u.id=c.user_id WHERE {$base} AND c.parent_id IN ({$ph}) ORDER BY c.created_at,c.id", array_merge([$postId], $ids));
                foreach ($kids as $child) {
                    if (isset($seen[$child['id']])) continue;
                    $seen[$child['id']] = true;
                    $all[] = $child;
                    $next[] = $child['id'];
                }
            }
            $batch = $next;
        }

        // 待审评论不进入公开统计或分页，只向评论者本人和管理员回显。
        $pending = self::visiblePending($postId, $columns);
        if ($pending !== []) {
            $all = array_merge($all, $pending);
        }

        // 构建节点 + 回复树（引用索引；父节点缺失的按顶层展示）
        $nodes = [];
        foreach ($all as $c) {
            $nodes[(string) $c['id']] = [
                'id' => (string) $c['id'],
                'authorName' => (string) ($c['nickname'] ?: $c['author_name']),
                'avatar' => self::avatarUrl($c['avatar_url'], (string) $c['author_email']),
                'content' => (string) $c['content'],
                'createdAtLabel' => \format_date($c['created_at'], 'yyyy-MM-dd HH:mm'),
                'isPinned' => (int) $c['is_pinned'] === 1,
                'isPending' => $c['status'] === 'PENDING',
                'replies' => [],
            ];
        }
        $parents = [];
        foreach ($all as $c) $parents[(string) $c['id']] = (string) ($c['parent_id'] ?? '');
        // Defensive handling of historical corrupt parent chains, including visible pending nodes.
        $checked = [];
        foreach (array_keys($parents) as $id) {
            $path = [];
            $current = $id;
            while ($current !== '' && isset($parents[$current]) && !isset($checked[$current])) {
                if (isset($path[$current])) {
                    $parents[$current] = '';
                    break;
                }
                $path[$current] = true;
                $current = $parents[$current];
            }
            $checked += $path;
        }
        $roots = [];
        foreach ($all as $c) {
            $id = (string) $c['id'];
            $path = [];
            $seen = [$id => true];
            $parentId = $parents[$id];
            while ($parentId !== '' && isset($nodes[$parentId]) && !isset($seen[$parentId])) {
                $seen[$parentId] = true;
                $path[] = $parentId;
                $parentId = $parents[$parentId];
            }
            // Keep max display depth without omitting or rewriting stored replies.
            $parentId = $path === [] ? '' : $path[max(0, count($path) - self::MAX_DEPTH)];
            if ($parentId !== '') $nodes[$parentId]['replies'][] = &$nodes[$id];
            else $roots[] = &$nodes[$id];
        }

        return [
            'roots' => $roots,
            'total' => $total,
            'totalPages' => max(1, (int) ceil($topCount / self::PAGE_SIZE)),
        ];
    }

    /** 评论者头像：用户设置了头像用之，否则返回本地默认头像 */
    public static function avatarUrl(?string $avatarUrl, string $email): string
    {
        $avatarUrl = trim((string) $avatarUrl);
        if ($avatarUrl !== '') {
            return $avatarUrl;
        }
        return Url::asset('/images/default-avatar.svg');
    }

    private static function columns(): string
    {
        return 'c.id, c.author_name, c.author_email, c.user_id, c.content, c.status, c.created_at, c.parent_id, c.is_pinned, u.nickname, u.avatar_url';
    }

    /** 仅管理员、登录评论者本人或当前访客会话可见的待审评论。 */
    private static function visiblePending(int $postId, string $columns): array
    {
        if (Auth::isAdmin()) {
            return DB::fetchAll(
                "SELECT {$columns} FROM comments c
                 LEFT JOIN users u ON u.id = c.user_id
                 WHERE c.post_id = ? AND c.status = 'PENDING'
                 ORDER BY c.created_at ASC",
                [$postId]
            );
        }

        $viewerId = Auth::id();
        if ($viewerId !== null) {
            return DB::fetchAll(
                "SELECT {$columns} FROM comments c
                 LEFT JOIN users u ON u.id = c.user_id
                 WHERE c.post_id = ? AND c.status = 'PENDING' AND c.user_id = ?
                 ORDER BY c.created_at ASC",
                [$postId, $viewerId]
            );
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) Session::get('pending_comment_ids', [])),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return DB::fetchAll(
            "SELECT {$columns} FROM comments c
             LEFT JOIN users u ON u.id = c.user_id
             WHERE c.post_id = ? AND c.status = 'PENDING' AND c.id IN ({$placeholders})
             ORDER BY c.created_at ASC",
            array_merge([$postId], $ids)
        );
    }
}
