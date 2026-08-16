<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/** 积分红包：创建时冻结作者积分，领取时锁定红包并写入双方账本。 */
final class RedPacket
{
    public static function fields(array|string|null $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $fields = [];
        foreach (is_array($raw) ? $raw : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['key'] ?? ''));
            if ($key !== '' && !array_key_exists($key, $fields)) {
                $fields[$key] = trim((string) ($row['value'] ?? ''));
            }
        }
        return $fields;
    }

    public static function available(): bool
    {
        return Points::available() && self::tableAvailable('redpackets');
    }

    public static function validateConfig(array|string|null $raw, int $creatorId): void
    {
        $fields = self::fields($raw);
        if (($fields['lumina_type'] ?? '') !== 'redpacket') {
            return;
        }
        if (!self::available()) {
            throw new \RuntimeException('积分红包功能尚未启用，请先完成数据库迁移');
        }
        $total = self::positiveInt($fields['lumina_redpacket_total'] ?? '', '红包总积分', 100000000);
        $count = self::positiveInt($fields['lumina_redpacket_count'] ?? '', '红包份数', 1000000);
        if ($total < $count) {
            throw new \RuntimeException('红包总积分必须不少于红包份数');
        }
        if (!in_array(($fields['lumina_redpacket_mode'] ?? 'random'), ['random', 'equal'], true)) {
            throw new \RuntimeException('红包类型无效');
        }
        if ($creatorId <= 0) {
            throw new \RuntimeException('创建红包需要有效的作者账号');
        }
    }

    /** 在文章落库前预检；已创建的红包编辑标题时不重复要求余额。 */
    public static function validateForPost(?int $postId, int $creatorId, array|string|null $raw): void
    {
        $fields = self::fields($raw);
        $existing = ($postId && self::tableAvailable('redpackets'))
            ? DB::fetchOne('SELECT creator_id, total_points, total_count, mode FROM redpackets WHERE post_id = ?', [$postId])
            : null;
        if (($fields['lumina_type'] ?? '') !== 'redpacket') {
            if ($existing !== null) {
                throw new \RuntimeException('已创建的红包不能移除或改为其他内容类型');
            }
            return;
        }
        self::validateConfig($fields, $creatorId);
        $total = self::positiveInt($fields['lumina_redpacket_total'] ?? '', '红包总积分', 100000000);
        $count = self::positiveInt($fields['lumina_redpacket_count'] ?? '', '红包份数', 1000000);
        $mode = $fields['lumina_redpacket_mode'] ?? 'random';
        if ($existing !== null) {
            if ((int) $existing['creator_id'] !== $creatorId || (int) $existing['total_points'] !== $total || (int) $existing['total_count'] !== $count || (string) $existing['mode'] !== $mode) {
                throw new \RuntimeException('红包发布后不能修改积分、份数或类型');
            }
            return;
        }
        if (Points::balance($creatorId) < $total) {
            throw new \RuntimeException('创建红包需要足够的积分余额');
        }
    }

    public static function syncPost(int $postId, int $creatorId, array|string|null $raw): void
    {
        $fields = self::fields($raw);
        if (($fields['lumina_type'] ?? '') !== 'redpacket') {
            return;
        }
        self::validateConfig($fields, $creatorId);
        $total = self::positiveInt($fields['lumina_redpacket_total'], '红包总积分', 100000000);
        $count = self::positiveInt($fields['lumina_redpacket_count'], '红包份数', 1000000);
        $mode = in_array(($fields['lumina_redpacket_mode'] ?? 'random'), ['random', 'equal'], true)
            ? $fields['lumina_redpacket_mode'] : 'random';
        $title = mb_substr(trim((string) ($fields['lumina_redpacket_title'] ?? '恭喜发财，大吉大利')), 0, 120);
        if ($title === '') {
            $title = '恭喜发财，大吉大利';
        }
        DB::transaction(function () use ($postId, $creatorId, $total, $count, $mode, $title): void {
            $existing = DB::fetchOne('SELECT * FROM redpackets WHERE post_id = ? FOR UPDATE', [$postId]);
            if ($existing !== null) {
                if ((int) $existing['creator_id'] !== $creatorId || (int) $existing['total_points'] !== $total || (int) $existing['total_count'] !== $count || (string) $existing['mode'] !== $mode) {
                    throw new \RuntimeException('红包发布后不能修改积分、份数或类型');
                }
                DB::execute('UPDATE redpackets SET title = ?, updated_at = NOW() WHERE id = ?', [$title, (int) $existing['id']]);
                return;
            }
            Points::adjustLocked($creatorId, -$total, '创建 积分红包', 'redpacket_create', $postId);
            DB::execute(
                'INSERT INTO redpackets (post_id, creator_id, mode, total_points, remaining_points, total_count, remaining_count, title) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$postId, $creatorId, $mode, $total, $total, $count, $count, $title]
            );
        });
    }

    public static function state(int $postId, ?int $userId = null): array
    {
        if ($postId <= 0 || !self::available()) {
            return ['available' => false, 'status' => 'UNAVAILABLE'];
        }
        $packet = DB::fetchOne('SELECT * FROM redpackets WHERE post_id = ?', [$postId]);
        if ($packet === null) {
            return ['available' => true, 'status' => 'MISSING'];
        }
        $claim = $userId ? DB::fetchOne('SELECT amount FROM redpacket_claims WHERE packet_id = ? AND user_id = ?', [(int) $packet['id'], $userId]) : null;
        $status = (string) $packet['status'];
        if ($status === 'OPEN' && ((int) $packet['remaining_count'] <= 0 || (int) $packet['remaining_points'] <= 0)) {
            $status = 'EMPTY';
        }
        return [
            'available' => true,
            'status' => $status,
            'id' => (int) $packet['id'],
            'title' => (string) $packet['title'],
            'total_points' => (int) $packet['total_points'],
            'remaining_points' => (int) $packet['remaining_points'],
            'total_count' => (int) $packet['total_count'],
            'remaining_count' => (int) $packet['remaining_count'],
            'creator_id' => (int) $packet['creator_id'],
            'claimed' => $claim !== null,
            'claimed_amount' => $claim ? (int) $claim['amount'] : null,
        ];
    }

    public static function claim(int $postId, int $userId): array
    {
        if (!self::available()) {
            throw new \RuntimeException('积分红包功能尚未启用，请先完成数据库迁移');
        }
        if ($userId <= 0) {
            throw new \RuntimeException('请先登录');
        }
        return DB::transaction(function () use ($postId, $userId): array {
            $packet = DB::fetchOne('SELECT * FROM redpackets WHERE post_id = ? FOR UPDATE', [$postId]);
            if ($packet === null) {
                throw new \RuntimeException('红包不存在');
            }
            if ((int) $packet['creator_id'] === $userId) {
                throw new \RuntimeException('不能领取自己创建的红包');
            }
            if (DB::fetchOne('SELECT amount FROM redpacket_claims WHERE packet_id = ? AND user_id = ? FOR UPDATE', [(int) $packet['id'], $userId])) {
                throw new \RuntimeException('你已经领取过这个红包');
            }
            $remainingCount = (int) $packet['remaining_count'];
            $remainingPoints = (int) $packet['remaining_points'];
            if ((string) $packet['status'] !== 'OPEN' || $remainingCount <= 0 || $remainingPoints <= 0) {
                throw new \RuntimeException('红包已经领完');
            }
            $amount = (string) $packet['mode'] === 'equal'
                ? ($remainingCount === 1 ? $remainingPoints : intdiv($remainingPoints, $remainingCount))
                : ($remainingCount === 1 ? $remainingPoints : random_int(1, max(1, $remainingPoints - ($remainingCount - 1))));
            DB::execute('INSERT INTO redpacket_claims (packet_id, user_id, amount) VALUES (?, ?, ?)', [(int) $packet['id'], $userId, $amount]);
            $nextCount = $remainingCount - 1;
            $nextPoints = $remainingPoints - $amount;
            DB::execute(
                "UPDATE redpackets SET remaining_points = ?, remaining_count = ?, status = ?, updated_at = NOW() WHERE id = ?",
                [$nextPoints, $nextCount, $nextCount === 0 ? 'EMPTY' : 'OPEN', (int) $packet['id']]
            );
            Points::adjustLocked($userId, $amount, '领取 积分红包', 'redpacket_claim', (int) $packet['id']);
            return ['amount' => $amount, 'remaining_count' => $nextCount, 'remaining_points' => $nextPoints];
        });
    }

    /** 永久删除文章前调用：停用红包，把剩余积分退还给作者（幂等，可重复执行）。 */
    public static function refundForPost(int $postId): void
    {
        if ($postId <= 0 || !self::available()) {
            return;
        }
        DB::transaction(function () use ($postId): void {
            $packet = DB::fetchOne('SELECT * FROM redpackets WHERE post_id = ? FOR UPDATE', [$postId]);
            if ($packet === null || (string) $packet['status'] === 'REFUNDED') {
                return;
            }
            $remaining = (int) $packet['remaining_points'];
            if ($remaining > 0) {
                Points::adjustLocked((int) $packet['creator_id'], $remaining, '文章删除退还红包积分', 'redpacket_refund', $postId);
            }
            DB::execute(
                'UPDATE redpackets SET remaining_points = 0, status = ?, updated_at = NOW() WHERE id = ?',
                ['REFUNDED', (int) $packet['id']]
            );
        });
    }

    public static function recentClaims(int $userId, int $limit = 8): array
    {
        if ($userId <= 0 || !self::available()) {
            return [];
        }
        $limit = max(1, min(30, $limit));
        return DB::fetchAll(
            "SELECT c.amount, c.claimed_at, p.title, p.post_id
             FROM redpacket_claims c JOIN redpackets p ON p.id = c.packet_id
             WHERE c.user_id = ? ORDER BY c.claimed_at DESC LIMIT {$limit}",
            [$userId]
        );
    }

    private static function positiveInt(mixed $value, string $label, int $max): int
    {
        $text = trim((string) $value);
        if (!preg_match('/^[1-9][0-9]*$/', $text)) {
            throw new \RuntimeException($label . '必须是正整数');
        }
        $number = (int) $text;
        if ($number <= 0 || $number > $max) {
            throw new \RuntimeException($label . '超出允许范围');
        }
        return $number;
    }

    private static function tableAvailable(string $table): bool
    {
        try {
            DB::value('SELECT 1 FROM `' . $table . '` LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
