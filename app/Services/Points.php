<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/** 轻量、可审计的整数积分账本。所有余额变化都必须同时写入流水。 */
final class Points
{
    public static function available(): bool
    {
        try {
            DB::value('SELECT 1 FROM user_points LIMIT 1');
            DB::value('SELECT 1 FROM point_transactions LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function balance(int $userId): int
    {
        if ($userId <= 0 || !self::available()) {
            return 0;
        }
        return (int) (DB::value('SELECT balance FROM user_points WHERE user_id = ?', [$userId]) ?? 0);
    }

    public static function transactions(int $userId, int $limit = 12): array
    {
        if ($userId <= 0 || !self::available()) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        return DB::fetchAll(
            "SELECT amount, reason, reference_type, reference_id, created_at
             FROM point_transactions WHERE user_id = ? ORDER BY id DESC LIMIT {$limit}",
            [$userId]
        );
    }

    /** 在独立事务中调整余额；带引用时幂等，适合管理员发放和系统奖励。 */
    public static function adjust(int $userId, int $amount, string $reason, ?string $referenceType = null, ?int $referenceId = null): int
    {
        if ($userId <= 0 || $amount === 0) {
            throw new \RuntimeException('积分参数无效');
        }
        if (!self::available()) {
            throw new \RuntimeException('积分功能尚未启用，请先完成数据库迁移');
        }
        return DB::transaction(fn () => self::adjustLocked($userId, $amount, $reason, $referenceType, $referenceId));
    }

    /** 仅供同一事务内的红包服务调用。 */
    public static function adjustLocked(int $userId, int $amount, string $reason, ?string $referenceType = null, ?int $referenceId = null): int
    {
        DB::execute('INSERT IGNORE INTO user_points (user_id, balance) VALUES (?, 0)', [$userId]);
        if ($referenceType !== null && $referenceId !== null) {
            $existing = DB::fetchOne(
                'SELECT amount FROM point_transactions WHERE user_id = ? AND reference_type = ? AND reference_id = ? LIMIT 1',
                [$userId, $referenceType, $referenceId]
            );
            if ($existing !== null) {
                return (int) DB::value('SELECT balance FROM user_points WHERE user_id = ?', [$userId]);
            }
        }
        $account = DB::fetchOne('SELECT balance FROM user_points WHERE user_id = ? FOR UPDATE', [$userId]);
        $balance = (int) ($account['balance'] ?? 0);
        $next = $balance + $amount;
        if ($next < 0) {
            throw new \RuntimeException('积分余额不足');
        }
        DB::execute(
            'INSERT INTO point_transactions (user_id, amount, reason, reference_type, reference_id) VALUES (?, ?, ?, ?, ?)',
            [$userId, $amount, mb_substr($reason, 0, 120), $referenceType, $referenceId]
        );
        DB::execute('UPDATE user_points SET balance = ? WHERE user_id = ?', [$next, $userId]);
        return $next;
    }
}
