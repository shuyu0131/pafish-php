<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\DB;

/**
 * 评论区数据：
 * 顶层评论分页（每页 20，置顶优先 + 时间正序）、回复树递归 5 层、
 * 父节点缺失的回复按顶层展示（不丢评论）、liked_comments cookie 回显
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
        $topCount = (int) DB::value(
            "SELECT COUNT(*) FROM comments c WHERE {$base} AND c.parent_id IS NULL",
            [$postId]
        );

        // 顶层评论分页（置顶优先，其余按时间正序）
        $columns = self::columns();
        $topComments = DB::fetchAll(
            "SELECT {$columns} FROM comments c
             LEFT JOIN users u ON u.id = c.user_id
             WHERE {$base} AND c.parent_id IS NULL
             ORDER BY c.is_pinned DESC, c.created_at ASC
             LIMIT " . self::PAGE_SIZE . " OFFSET " . (($page - 1) * self::PAGE_SIZE),
            [$postId]
        );

        // 递归加载整棵回复树（最多 5 层）
        $all = $topComments;
        $batch = array_map(static fn (array $c): int => (int) $c['id'], $topComments);
        for ($depth = 0; $depth < self::MAX_DEPTH && $batch !== []; $depth++) {
            $ph = implode(',', array_fill(0, count($batch), '?'));
            $kids = DB::fetchAll(
                "SELECT {$columns} FROM comments c
                 LEFT JOIN users u ON u.id = c.user_id
                 WHERE {$base} AND c.parent_id IN ({$ph})
                 ORDER BY c.created_at ASC",
                array_merge([$postId], $batch)
            );
            if ($kids === []) {
                break;
            }
            $all = array_merge($all, $kids);
            $batch = array_map(static fn (array $k): int => (int) $k['id'], $kids);
        }

        // 当前浏览器已点赞的评论（cookie 回显）
        $likedSet = array_values(array_filter(array_map('trim', explode(',', (string) ($_COOKIE['liked_comments'] ?? '')))));

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
                'likeCount' => (int) $c['like_count'],
                'liked' => in_array((string) $c['id'], $likedSet, true),
                'replies' => [],
            ];
        }
        $refs = [];
        foreach ($nodes as $id => &$node) {
            $refs[$id] = &$node;
        }
        unset($node);
        $roots = [];
        foreach ($all as $c) {
            $id = (string) $c['id'];
            $parentId = $c['parent_id'] !== null ? (string) $c['parent_id'] : '';
            if ($parentId !== '' && isset($refs[$parentId])) {
                $refs[$parentId]['replies'][] = &$refs[$id];
            } else {
                $roots[] = &$refs[$id];
            }
        }
        unset($refs);

        return [
            'roots' => $roots,
            'total' => $total,
            'totalPages' => max(1, (int) ceil($topCount / self::PAGE_SIZE)),
        ];
    }

    /** 评论者头像：用户设置了 avatarUrl 用之，否则使用 cravatar 默认头像 */
    public static function avatarUrl(?string $avatarUrl, string $email): string
    {
        $avatarUrl = trim((string) $avatarUrl);
        if ($avatarUrl !== '') {
            return $avatarUrl;
        }
        $hash = md5(strtolower(trim($email)));
        return "https://cravatar.cn/avatar/{$hash}?d=identicon&s=80";
    }

    private static function columns(): string
    {
        return 'c.id, c.author_name, c.author_email, c.content, c.created_at, c.parent_id, c.is_pinned, c.like_count, u.nickname, u.avatar_url';
    }
}
