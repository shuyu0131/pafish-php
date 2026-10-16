<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;
use Pafish\Core\Session;

/** 文章互动的状态和计数写入；匿名状态由服务端会话保管。 */
final class PostReactions
{
    public static function state(int $postId, ?int $userId): array
    {
        $state = ['like' => false, 'favorite' => false];
        if ($userId === null) {
            $likes = Session::get('post_likes', []);
            $state['like'] = is_array($likes) && ($likes[$postId] ?? false) === true;
        } else {
            foreach (DB::fetchAll('SELECT kind FROM post_reactions WHERE post_id = ? AND user_id = ?', [$postId, $userId]) as $row) {
                if (array_key_exists((string) $row['kind'], $state)) {
                    $state[$row['kind']] = true;
                }
            }
        }
        return $state;
    }

    /** active 为空时兼容切换请求；指定目标状态可安全重试，不会重复加减。 */
    public static function change(int $postId, string $kind, ?int $userId, ?bool $active = null): array
    {
        if ($postId < 1 || !in_array($kind, ['like', 'favorite'], true)) {
            throw new \InvalidArgumentException('参数错误', 400);
        }
        if ($kind === 'favorite' && $userId === null) {
            throw new \DomainException('请先登录后再收藏', 401);
        }
        if ($userId === null && DB::pdo()->inTransaction()) {
            // 会话状态不受数据库回滚控制，匿名操作必须持有最外层事务。
            throw new \LogicException('匿名互动不能在已有业务事务中调用');
        }
        $result = DB::transaction(static function () use ($postId, $kind, $userId, $active): array {
            // 目标校验与关系写入锁同一行，删除或取消发布不能插入在二者之间。
            $post = DB::fetchOne("SELECT id, like_count, favorite_count FROM posts WHERE id = ?
                AND status = 'PUBLISHED' AND deleted_at IS NULL
                AND (published_at IS NULL OR published_at <= NOW()) FOR UPDATE", [$postId]);
            if ($post === null) {
                throw new \DomainException('文章不存在或尚未发布', 404);
            }
            $current = $userId === null
                ? self::state($postId, null)[$kind]
                : DB::fetchOne('SELECT 1 FROM post_reactions WHERE post_id = ? AND user_id = ? AND kind = ? FOR UPDATE',
                    [$postId, $userId, $kind]) !== null;
            $desired = $active ?? !$current;
            $count = (int) $post[$kind . '_count'];
            if ($desired !== $current) {
                if ($userId !== null) {
                    if ($desired) {
                        DB::execute('INSERT INTO post_reactions (user_id, post_id, kind) VALUES (?, ?, ?)', [$userId, $postId, $kind]);
                    } else {
                        DB::execute('DELETE FROM post_reactions WHERE user_id = ? AND post_id = ? AND kind = ?', [$userId, $postId, $kind]);
                    }
                }
                $delta = $desired ? 1 : -1;
                DB::execute("UPDATE posts SET {$kind}_count = GREATEST({$kind}_count + ?, 0) WHERE id = ?", [$delta, $postId]);
                $count = max(0, $count + $delta);
            }
            return ['ok' => true, 'active' => $desired, 'count' => $count, 'changed' => $desired !== $current];
        });
        if ($userId === null) {
            $likes = Session::get('post_likes', []);
            $likes = is_array($likes) ? $likes : [];
            if ($result['active']) {
                $likes[$postId] = true;
            } else {
                unset($likes[$postId]);
            }
            Session::set('post_likes', $likes);
        }
        return $result;
    }
}
