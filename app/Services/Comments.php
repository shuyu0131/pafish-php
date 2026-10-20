<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Auth;
use Pafish\Core\DB;

/** Comment lifecycle and bounded previews; writes serialize on the article row, retain real FKs. */
final class Comments
{
    public const STATUSES = ['PENDING', 'APPROVED', 'REJECTED', 'SPAM', 'TRASH'];

    /** 列表卡片只取每篇最近六条顶层已审评论；不把热门文章的整段评论读回 PHP。 */
    public static function recentForPosts(array $postIds, bool $includeReplyIds = false): array
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds), static fn (int $id): bool => $id > 0)));
        $recent = [];
        foreach (array_chunk($postIds, 50) as $ids) {
            // MySQL 5.7 supports this bounded per-post query consistently with native prepares.
            $rows = [];
            foreach ($ids as $id) {
                array_push($rows, ...DB::fetchAll(
                    "SELECT id,post_id,author_name,content,created_at FROM comments
                     WHERE post_id=? AND status='APPROVED' AND parent_id IS NULL
                     ORDER BY created_at DESC,id DESC LIMIT 6",
                    [$id]
                ));
            }
            foreach ($rows as $row) {
                $preview = [
                    'author_name' => (string) $row['author_name'],
                    'content' => (string) $row['content'],
                    'created_at' => (string) $row['created_at'],
                ];
                if ($includeReplyIds) $preview['id'] = (string) $row['id'];
                $recent[(int) $row['post_id']][] = $preview;
            }
        }
        return $recent;
    }

    public static function create(array $data): int
    {
        return DB::transaction(static function () use ($data): int {
            $post = Posts::findReadable((int) $data['post_id'], true, true);
            if (!Posts::commentsOpen($post)) throw new \DomainException('此文章已关闭评论', 403);
            $parentId = empty($data['parent_id']) ? null : (int) $data['parent_id'];
            $seen = [];
            for ($id = $parentId; $id !== null;) {
                if (isset($seen[$id])) throw new \DomainException('评论回复关系异常', 409);
                $seen[$id] = true;
                $parent = DB::fetchOne('SELECT post_id,status,parent_id FROM comments WHERE id=? FOR UPDATE', [$id]);
                if (!$parent || (int) $parent['post_id'] !== (int) $post['id'] || $parent['status'] !== 'APPROVED') {
                    throw new \DomainException('只能回复当前文章已通过的评论', 400);
                }
                $id = $parent['parent_id'] === null ? null : (int) $parent['parent_id'];
            }
            if (!in_array($data['status'], self::STATUSES, true)) throw new \InvalidArgumentException('评论状态无效');
            if (trim((string) $data['content']) === '') throw new \DomainException('回复内容不能为空', 400);
            DB::execute('INSERT INTO comments (post_id,author_name,author_email,user_id,content,status,parent_id,notify_reply,ip) VALUES (?,?,?,?,?,?,?,?,?)', [
                (int) $post['id'], $data['author_name'], $data['author_email'], $data['user_id'] ?? null,
                $data['content'], $data['status'], $parentId, !empty($data['notify_reply']) ? 1 : 0, $data['ip'] ?? null,
            ]);
            return DB::lastInsertId();
        });
    }

    /** Returns committed changes for the HTTP layer to emit hooks. */
    public static function moderate(array $ids, string $status): array
    {
        if (!in_array($status, self::STATUSES, true)) throw new \DomainException('无效的状态', 400);
        return DB::transaction(static function () use ($ids, $status): array {
            $rows = self::lockManaged($ids);
            $selected = array_fill_keys(array_map('intval', $ids), true);
            if ($status === 'APPROVED') {
                foreach ($ids as $id) {
                    $seen = [];
                    while (isset($rows[$id])) {
                        if (isset($seen[$id])) throw new \DomainException('评论回复关系异常', 409);
                        $seen[$id] = $selected[$id] = true;
                        $id = (int) ($rows[$id]['parent_id'] ?? 0);
                    }
                }
            } else {
                $selected = self::descendants($rows, $selected);
            }
            $events = [];
            foreach ($rows as $id => $row) {
                if (!isset($selected[$id]) || $row['status'] === $status) continue;
                DB::execute('UPDATE comments SET status=? WHERE id=?', [$status, $id]);
                $events[] = ['hook'=>'after_comment_status','data'=>[
                    'id'=>(string) $id, 'postId'=>(string) $row['post_id'], 'from'=>$row['status'], 'to'=>$status,
                ]];
            }
            return $events;
        });
    }

    public static function delete(array $ids): array
    {
        return DB::transaction(static function () use ($ids): array {
            $rows = self::lockManaged($ids);
            $selected = self::descendants($rows, array_fill_keys(array_map('intval', $ids), true));
            return self::deleteRows($rows, $selected);
        });
    }

    /** Article purge shares the leaf-first deletion path, with article management permission. */
    public static function deleteForPost(int $postId): array
    {
        return DB::transaction(static function () use ($postId): array {
            $post = DB::fetchOne('SELECT id,author_id FROM posts WHERE id=? FOR UPDATE', [$postId]);
            if (!Auth::can('posts.manage') || !$post || (!Auth::isAdmin() && (int) $post['author_id'] !== Auth::id())) {
                throw new \DomainException('无权删除文章评论', 403);
            }
            $rows = [];
            foreach (DB::fetchAll('SELECT * FROM comments WHERE post_id=? ORDER BY id FOR UPDATE', [$postId]) as $row) $rows[(int) $row['id']] = $row;
            return self::deleteRows($rows, array_fill_keys(array_keys($rows), true));
        });
    }

    private static function deleteRows(array $rows, array $selected): array
    {
        $events = [];
        // Delete leaves explicitly: no 15-level InnoDB cascade limit and no missing child hooks.
        while ($selected !== []) {
            $parents = [];
            foreach ($selected as $id => $_) {
                $pid = (int) ($rows[$id]['parent_id'] ?? 0);
                if (isset($selected[$pid])) $parents[$pid] = true;
            }
            $leaves = array_diff_key($selected, $parents);
            if ($leaves === []) throw new \DomainException('评论回复关系异常', 409);
            foreach ($leaves as $id => $_) {
                EntityMeta::deleteSubject('comment', (int) $id);
                DB::execute('DELETE FROM comments WHERE id=?', [$id]);
                $events[] = ['hook'=>'after_comment_delete','data'=>[
                    'id'=>(string) $id, 'postId'=>(string) $rows[$id]['post_id'], 'authorName'=>$rows[$id]['author_name'],
                ]];
                unset($selected[$id]);
            }
        }
        return $events;
    }

    public static function reply(int $parentId, string $content): array
    {
        return DB::transaction(static function () use ($parentId, $content): array {
            $rows = self::lockManaged([$parentId]);
            $parent = $rows[$parentId];
            $user = Auth::user();
            $content = trim($content);
            if ($content === '') throw new \DomainException('回复内容不能为空', 400);
            $events = self::moderate([$parentId], 'APPROVED');
            DB::execute('INSERT INTO comments (post_id,author_name,author_email,user_id,content,status,parent_id) VALUES (?,?,?,?,?,?,?)', [
                $parent['post_id'], $user['nickname'] ?: $user['username'], '', $user['id'], $content, 'APPROVED', $parentId,
            ]);
            $id = DB::lastInsertId();
            $events[] = ['hook'=>'after_comment_reply','data'=>[
                'id'=>(string) $id,'postId'=>(string) $parent['post_id'],'parentId'=>(string) $parentId,
                'content'=>$content,'author'=>$user['nickname'] ?: $user['username'],
            ]];
            return $events;
        });
    }

    public static function pin(int $id): void
    {
        DB::transaction(static function () use ($id): void {
            $rows = self::lockManaged([$id]);
            DB::execute('UPDATE comments SET is_pinned=? WHERE id=?', [empty($rows[$id]['is_pinned']) ? 1 : 0, $id]);
        });
    }

    public static function emit(array $events): void
    {
        if (DB::pdo()->inTransaction()) throw new \LogicException('评论事件必须在事务提交后发送');
        foreach ($events as $event) \do_action($event['hook'], $event['data']);
    }

    private static function lockManaged(array $ids): array
    {
        if (!Auth::can('comments.manage')) throw new \DomainException('无权管理评论', 403);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || min($ids) < 1) throw new \DomainException('评论编号无效', 400);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $targets = DB::fetchAll("SELECT id,post_id FROM comments WHERE id IN ({$ph})", $ids);
        if (count($targets) !== count($ids)) throw new \DomainException('评论不存在或无权管理', 403);
        $postIds = array_values(array_unique(array_column($targets, 'post_id')));
        sort($postIds, SORT_NUMERIC);
        $rows = [];
        foreach ($postIds as $postId) {
            $post = DB::fetchOne('SELECT id,author_id FROM posts WHERE id=? FOR UPDATE', [$postId]);
            if (!$post || (!Auth::isAdmin() && (int) $post['author_id'] !== Auth::id())) throw new \DomainException('无权管理评论', 403);
            foreach (DB::fetchAll('SELECT * FROM comments WHERE post_id=? ORDER BY id FOR UPDATE', [$postId]) as $row) $rows[(int) $row['id']] = $row;
        }
        foreach ($ids as $id) if (!isset($rows[$id])) throw new \DomainException('评论已变更，请刷新后重试', 409);
        return $rows;
    }

    private static function descendants(array $rows, array $ids): array
    {
        $children = [];
        foreach ($rows as $id=>$row) $children[(int) ($row['parent_id'] ?? 0)][] = $id;
        $queue = array_keys($ids);
        for ($i=0; $i<count($queue); $i++) {
            foreach ($children[$queue[$i]] ?? [] as $id) {
                if (!isset($ids[$id])) { $ids[$id]=true; $queue[]=$id; }
            }
        }
        return $ids;
    }
}
