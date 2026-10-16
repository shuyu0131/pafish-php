<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\RateLimiter;
use Pafish\Core\Session;

/**
 * 注册 / 忘记密码验证码：会话内按用途保存最新一份，绑定邮箱，不写数据库。
 * 使用 PHP 会话锁串行消费；自定义 session handler 也必须支持同一会话的互斥。
 */
final class EmailCode
{
    private const TTL = 600; // 10 分钟
    private const MIN_INTERVAL = 60; // 60 秒限频
    private const MAX_ATTEMPTS = 5;

    /** 生成 6 位数字验证码（覆盖当前会话同用途的旧码），返回 [code, tooFrequent] */
    public static function create(string $email, string $purpose): array
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || !in_array($purpose, ['register', 'reset'], true)) {
            throw new \InvalidArgumentException('验证码邮箱或用途无效');
        }
        Session::start();
        if (!RateLimiter::allow('email-code-send', $email . "\0" . $purpose, 1, self::MIN_INTERVAL)) {
            return ['', true];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Session::set('email_code:' . $purpose, [
            'email' => $email,
            'hash' => password_hash($code, PASSWORD_BCRYPT, ['cost' => 10]),
            'expires' => time() + self::TTL,
            'attempts' => 0,
        ]);
        return [$code, false];
    }

    /** 校验成功即移除；过期或连续错误五次也移除，不能复活旧码。 */
    public static function verify(string $email, string $purpose, string $code): bool
    {
        $email = strtolower(trim($email));
        if (preg_match('/^\d{6}$/D', $code) !== 1 || !in_array($purpose, ['register', 'reset'], true)) {
            return false;
        }
        Session::start();
        $key = 'email_code:' . $purpose;
        $record = Session::get($key);
        if (!is_array($record) || ($record['email'] ?? '') !== $email) return false;
        if ((int) ($record['expires'] ?? 0) <= time() || (int) ($record['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            Session::remove($key);
            return false;
        }
        if (password_verify($code, (string) ($record['hash'] ?? ''))) {
            Session::remove($key);
            return true;
        }
        $record['attempts'] = (int) ($record['attempts'] ?? 0) + 1;
        if ($record['attempts'] >= self::MAX_ATTEMPTS) Session::remove($key);
        else Session::set($key, $record);
        return false;
    }

    /** 发信失败时撤销当前会话的凭据。发送限频保留，避免失败被用于刷信。 */
    public static function forget(string $purpose): void
    {
        if (!in_array($purpose, ['register', 'reset'], true)) return;
        Session::start();
        Session::remove('email_code:' . $purpose);
    }
}
