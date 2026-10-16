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
        self::validateAdjustment($userId, $amount, $referenceType, $referenceId);
        if (!self::available()) {
            throw new \RuntimeException('积分功能尚未启用，请先完成数据库迁移');
        }
        return DB::transaction(fn () => self::adjustLocked($userId, $amount, $reason, $referenceType, $referenceId));
    }

    /** 在调用方已开启事务时调整余额。 */
    public static function adjustLocked(int $userId, int $amount, string $reason, ?string $referenceType = null, ?int $referenceId = null): int
    {
        if (!DB::pdo()->inTransaction()) {
            throw new \RuntimeException('积分调整必须在事务中执行');
        }
        self::validateAdjustment($userId, $amount, $referenceType, $referenceId);
        DB::execute('INSERT INTO user_points (user_id, balance) VALUES (?, 0) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)', [$userId]);
        // 所有调整先锁同一账户，再以当前读检查引用，避免等待锁前的旧快照漏掉并发流水。
        $account = DB::fetchOne('SELECT balance FROM user_points WHERE user_id = ? FOR UPDATE', [$userId]);
        $balance = (int) $account['balance'];
        if ($referenceType !== null && $referenceId !== null) {
            $existing = DB::fetchOne(
                'SELECT amount FROM point_transactions WHERE user_id = ? AND reference_type = ? AND reference_id = ? LIMIT 1 FOR UPDATE',
                [$userId, $referenceType, $referenceId]
            );
            if ($existing !== null) {
                return $balance;
            }
        }
        if ($balance < 0) {
            throw new \RuntimeException('积分账户余额异常');
        }
        if ($amount < 0 && $amount < -$balance) {
            throw new \RuntimeException('积分余额不足');
        }
        if ($amount > 0 && $amount > PHP_INT_MAX - $balance) {
            throw new \RuntimeException('积分余额超出上限');
        }
        $next = $balance + $amount;
        DB::execute(
            'INSERT INTO point_transactions (user_id, amount, reason, reference_type, reference_id) VALUES (?, ?, ?, ?, ?)',
            [$userId, $amount, mb_substr($reason, 0, 120), $referenceType, $referenceId]
        );
        DB::execute('UPDATE user_points SET balance = ? WHERE user_id = ?', [$next, $userId]);
        return $next;
    }

    private static function validateAdjustment(int $userId, int $amount, ?string $referenceType, ?int $referenceId): void
    {
        if ($userId <= 0 || $amount === 0) {
            throw new \RuntimeException('积分参数无效');
        }
        if (($referenceType === null) !== ($referenceId === null)
            || ($referenceType !== null && (trim($referenceType) === '' || mb_strlen($referenceType) > 40))
            || ($referenceId !== null && $referenceId <= 0)) {
            throw new \RuntimeException('积分业务引用必须同时提供有效类型和正整数 ID');
        }
    }
}
