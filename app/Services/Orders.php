<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Auth;
use Pafish\Core\DB;

/** Shared, extension-scoped order storage and payment result handling. */
final class Orders
{
    private const STATUSES = ['pending', 'paid', 'failed', 'cancelled', 'refunded'];

    public static function create(
        string $appName,
        string $skuName,
        string|int|null $skuId,
        string|int|float $price,
        string $payType,
        ?int $userId = null,
    ): int {
        self::validateAppName($appName);
        $skuName = strtolower(trim($skuName));
        $payType = strtolower(trim($payType));
        if (preg_match('/^[a-z0-9_-]{1,50}$/', $skuName) !== 1
            || preg_match('/^[a-z0-9_-]{1,30}$/', $payType) !== 1) {
            throw new \InvalidArgumentException('订单商品类型或支付方式无效');
        }
        $amount = self::money($price);
        $userId ??= Auth::id();
        if ($userId !== null && $userId <= 0) throw new \InvalidArgumentException('订单用户 ID 无效');
        $skuId = $skuId === null ? null : (string) $skuId;
        if ($skuId !== null && (trim($skuId) === '' || mb_strlen($skuId) > 100)) {
            throw new \InvalidArgumentException('订单商品 ID 无效');
        }

        DB::execute('INSERT INTO orders (app_name, order_uid, sku_name, sku_id, price, pay_type)
            VALUES (?, ?, ?, ?, ?, ?)', [$appName, $userId, $skuName, $skuId, $amount, $payType]);
        return DB::lastInsertId();
    }

    public static function get(string $appName, int $orderId): ?array
    {
        self::validateAppName($appName);
        if ($orderId <= 0) throw new \InvalidArgumentException('订单 ID 无效');
        return DB::fetchOne('SELECT * FROM orders WHERE app_name = ? AND id = ?', [$appName, $orderId]);
    }

    /** Update a payment callback result; repeated identical callbacks are idempotent. */
    public static function update(string $appName, int $orderId, array $updates): array
    {
        self::validateAppName($appName);
        if ($orderId <= 0) throw new \InvalidArgumentException('订单 ID 无效');
        $allowed = ['status', 'pay_price', 'refund_amount', 'out_trade_no'];
        if (!array_key_exists('status', $updates) || array_diff(array_keys($updates), $allowed) !== []) {
            throw new \InvalidArgumentException('订单更新字段无效');
        }

        return DB::transaction(static function () use ($appName, $orderId, $updates): array {
            $order = DB::fetchOne('SELECT * FROM orders WHERE app_name = ? AND id = ? FOR UPDATE', [$appName, $orderId]);
            if ($order === null) throw new \RuntimeException('订单不存在', 404);
            $nextStatus = (string) ($updates['status'] ?? $order['status']);
            if (!in_array($nextStatus, self::STATUSES, true)) throw new \InvalidArgumentException('订单状态无效');
            $payPrice = array_key_exists('pay_price', $updates) ? self::money($updates['pay_price']) : (string) $order['pay_price'];
            $refundAmount = array_key_exists('refund_amount', $updates) ? self::money($updates['refund_amount']) : (string) $order['refund_amount'];
            $tradeNo = array_key_exists('out_trade_no', $updates) ? trim((string) $updates['out_trade_no']) : (string) ($order['out_trade_no'] ?? '');
            if (strlen($tradeNo) > 128 || (array_key_exists('out_trade_no', $updates) && $tradeNo === '')) {
                throw new \InvalidArgumentException('外部交易号无效');
            }

            $currentStatus = (string) $order['status'];
            if ($nextStatus !== $currentStatus) {
                $allowedTransitions = [
                    'pending' => ['paid', 'failed', 'cancelled'],
                    'paid' => ['refunded'],
                    'failed' => [],
                    'cancelled' => [],
                    'refunded' => [],
                ];
                if (!in_array($nextStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
                    throw new \DomainException('订单状态不能从 ' . $currentStatus . ' 变更为 ' . $nextStatus, 409);
                }
            }
            if ($nextStatus === 'paid' && ($payPrice === '0.00' || $tradeNo === '')) {
                throw new \InvalidArgumentException('支付成功订单必须包含支付金额和外部交易号');
            }
            if (self::cents($refundAmount) > self::cents($payPrice)
                || ($nextStatus === 'refunded' && self::cents($refundAmount) <= 0)) {
                throw new \InvalidArgumentException('退款金额超出已支付金额');
            }
            if ($nextStatus !== 'refunded' && self::cents($refundAmount) !== 0) {
                throw new \InvalidArgumentException('退款金额只能用于退款状态');
            }
            if (in_array($currentStatus, ['paid', 'refunded'], true)
                && ($payPrice !== (string) $order['pay_price'] || $tradeNo !== (string) $order['out_trade_no'])) {
                throw new \DomainException('已支付订单的支付金额和交易号不可修改', 409);
            }

            DB::execute('UPDATE orders SET status = ?, pay_price = ?, refund_amount = ?, out_trade_no = ? WHERE app_name = ? AND id = ?',
                [$nextStatus, $payPrice, $refundAmount, $tradeNo !== '' ? $tradeNo : null, $appName, $orderId]);
            $updated = DB::fetchOne('SELECT * FROM orders WHERE app_name = ? AND id = ?', [$appName, $orderId]);
            if ($updated === null) throw new \RuntimeException('更新后的订单无法读取');
            return $updated;
        });
    }

    private static function validateAppName(string $appName): void
    {
        if (preg_match('/^(?:plugin|theme):[a-z0-9_-]{1,50}$/', $appName) !== 1) {
            throw new \InvalidArgumentException('订单应用名称无效');
        }
    }

    private static function money(string|int|float $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/', $value, $parts) !== 1) {
            throw new \InvalidArgumentException('订单金额必须是非负的两位小数金额');
        }
        return (string) (int) $parts[1] . '.' . str_pad((string) ($parts[2] ?? ''), 2, '0');
    }

    private static function cents(string $amount): int
    {
        [$whole, $fraction] = explode('.', $amount, 2);
        return ((int) $whole * 100) + (int) $fraction;
    }
}
