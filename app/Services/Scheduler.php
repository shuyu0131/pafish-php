<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/**
 * 定时发布：
 * - 把已到发布时间的 SCHEDULED 文章转为 PUBLISHED
 * - 查询层兜底：前台只显示 PUBLISHED 且 published_at <= NOW()，即使任务未跑也不会提前泄露
 * 双通道触发：
 *   1) cron.php（宝塔/系统计划任务，`php cron.php`）
 *   2) 前台请求低频兜底 maybeRun()（runtime/scheduler.lock 限频，无 cron 环境可用）
 */
final class Scheduler
{
    /**
     * 到期文章转 PUBLISHED；返回本次发布数量
     * 更新条件带 status='SCHEDULED' 防并发重复处理
     */
    public static function publishDue(): int
    {
        if (DB::pdo()->inTransaction()) throw new \LogicException('定时发布必须独立提交后触发事件');
        $rows = DB::fetchAll(
            "SELECT id, title, slug, published_at, category_id, external_url, is_pinned, category_pinned
             FROM posts WHERE status = 'SCHEDULED' AND deleted_at IS NULL AND published_at <= NOW()"
        );
        if ($rows === []) {
            return 0;
        }
        $updated = 0;
        foreach ($rows as $row) {
            $row = DB::transaction(static function () use ($row): ?array {
                $current = DB::fetchOne("SELECT * FROM posts WHERE id=? AND status='SCHEDULED' AND deleted_at IS NULL AND published_at<=NOW() FOR UPDATE", [(int) $row['id']]);
                if ($current === null) return null;
                $changed = DB::execute("UPDATE posts SET status='PUBLISHED',updated_at=updated_at WHERE id=? AND status='SCHEDULED' AND deleted_at IS NULL AND published_at<=NOW()", [(int) $row['id']]);
                return $changed === 1 ? $current : null;
            });
            if ($row === null) continue;
            $updated++;
            \do_action('after_post_published', [
                'id' => (string) $row['id'],
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'status' => 'PUBLISHED',
                'publishedAt' => $row['published_at'] ? (string) $row['published_at'] : null,
                'categoryId' => $row['category_id'] ? (string) $row['category_id'] : null,
                'externalUrl' => $row['external_url'] ? (string) $row['external_url'] : null,
                'isPinned' => (bool) $row['is_pinned'],
                'categoryPinned' => (bool) $row['category_pinned'],
                'action' => 'schedule',
                'previousStatus' => 'SCHEDULED',
                'trigger' => 'schedule',
            ]);
        }
        return $updated;
    }

    /**
     * 前台请求低频触发兜底：runtime/scheduler.lock 限频（默认 60s 一次），
     * 有 cron 的环境该调用几乎不执行查询（lock 命中直接返回）
     */
    public static function maybeRun(int $interval = 60): void
    {
        $lock = dirname(__DIR__, 2) . '/runtime/scheduler.lock';
        $handle = @fopen($lock, 'c+');
        if ($handle === false) return;
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) return;
            $last = (int) stream_get_contents($handle);
            if ($last > 0 && time() - $last < max(1, $interval)) return;
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, (string) time()) === false || !fflush($handle)) return;
            if (self::publishDue() > 0) \Pafish\Core\Logger::error('[pafish] 定时发布：前台请求兜底已发布到期文章');
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
