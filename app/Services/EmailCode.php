<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/**
 * 邮箱验证码：注册 / 忘记密码共用
 * 对齐 Node 版 src/lib/email-code.ts：
 * - 6 位数字，10 分钟过期，校验通过后标记 used（一次性）
 * - 同一邮箱同一用途 60 秒内不能重复发送（文件限频，重启即失效）
 * - 发送新码会覆盖旧码（旧码立即失效）
 */
final class EmailCode
{
    private const TTL = 600; // 10 分钟
    private const MIN_INTERVAL = 60; // 60 秒限频

    /** 生成 6 位数字验证码并入库（覆盖旧码），返回 [code, tooFrequent] */
    public static function create(string $email, string $purpose): array
    {
        $rateFile = self::rateFile($email, $purpose);
        $now = time();
        $last = is_file($rateFile) ? (int) @file_get_contents($rateFile) : 0;
        if ($now - $last < self::MIN_INTERVAL) {
            return ['', true];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::execute(
            'INSERT INTO email_codes (email, purpose, code, expires_at) VALUES (?, ?, ?, ?)',
            [$email, $purpose, $code, date('Y-m-d H:i:s', $now + self::TTL)]
        );
        @file_put_contents($rateFile, (string) $now);
        return [$code, false];
    }

    /** 校验验证码：成功则标记 used（一次性）；失败（含过期/不存在/已用）统一返回 false */
    public static function verify(string $email, string $purpose, string $code): bool
    {
        if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $row = DB::fetchOne(
            'SELECT id FROM email_codes
             WHERE email = ? AND purpose = ? AND code = ? AND used = 0 AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1',
            [$email, $purpose, $code]
        );
        if (!$row) {
            return false;
        }
        DB::execute('UPDATE email_codes SET used = 1 WHERE id = ?', [(int) $row['id']]);
        return true;
    }

    private static function rateFile(string $email, string $purpose): string
    {
        $dir = dirname(__DIR__, 2) . '/runtime/rate';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/ec_' . $purpose . '_' . md5(strtolower($email)) . '.ts';
    }
}
