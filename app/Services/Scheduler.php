<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/**
 * 定时发布（对齐 Node src/lib/scheduler.ts publishScheduledPosts）：
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
        $ids = array_map(
            static fn (array $r): int => (int) $r['id'],
            DB::fetchAll(
                "SELECT id FROM posts WHERE status = 'SCHEDULED' AND deleted_at IS NULL AND published_at <= NOW()"
            )
        );
        if ($ids === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $updated = DB::execute(
            "UPDATE posts SET status = 'PUBLISHED', updated_at = updated_at WHERE status = 'SCHEDULED' AND id IN ({$ph})",
            $ids
        );
        return $updated;
    }

    /**
     * 前台请求低频触发兜底：runtime/scheduler.lock 限频（默认 60s 一次），
     * 有 cron 的环境该调用几乎不执行查询（lock 命中直接返回）
     */
    public static function maybeRun(int $interval = 60): void
    {
        $lock = dirname(__DIR__, 2) . '/runtime/scheduler.lock';
        $last = @filemtime($lock);
        if ($last !== false && (time() - $last) < $interval) {
            return;
        }
        @touch($lock);
        if (self::publishDue() > 0) {
            error_log('[pafish] 定时发布：前台请求兜底已发布到期文章');
        }
    }
}
