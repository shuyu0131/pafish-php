<?php

declare(strict_types=1);

namespace Pafish\Core;

/**
 * 会话：7 天有效、httpOnly、SameSite=Lax；附 CSRF 令牌
 */
final class Session
{
    private const LIFETIME = 604800; // 7 天
    private const REQUEST_TTL = 3600;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('pafish_session');
        session_set_cookie_params([
            'lifetime' => self::LIFETIME,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** 读取文章密码解锁记录，供前台文章门使用。 */
    public static function unlockedPost(int $postId, string $passwordHash, int $now): bool
    {
        $records = self::get('unlocked_posts', []);
        if (!is_array($records)) {
            return false;
        }
        $record = $records[(string) $postId] ?? null;
        if (!is_array($record)
            || (int) ($record['expires'] ?? 0) < $now
            || !is_string($record['password'] ?? null)
            || !hash_equals((string) $record['password'], hash('sha256', $passwordHash))) {
            return false;
        }
        return true;
    }

    /** 保存文章密码解锁记录，不把密码或可伪造状态放入客户端 Cookie。 */
    public static function unlockPost(int $postId, string $passwordHash, int $expires): void
    {
        $records = self::get('unlocked_posts', []);
        if (!is_array($records)) {
            $records = [];
        }
        $records[(string) $postId] = [
            'password' => hash('sha256', $passwordHash),
            'expires' => $expires,
        ];
        self::set('unlocked_posts', $records);
    }

    /** 登出：清空会话并删除 cookie */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }

    /** CSRF 令牌（惰性生成，随会话持久） */
    public static function csrfToken(): string
    {
        $token = $_SESSION['_csrf'] ?? null;
        if (!$token) {
            $token = bin2hex(random_bytes(16));
            $_SESSION['_csrf'] = $token;
        }
        return $token;
    }

    public static function verifyCsrf(?string $token): bool
    {
        if (Auth::isBearerAuthenticated()) {
            return true;
        }
        $expected = $_SESSION['_csrf'] ?? null;
        return is_string($expected) && is_string($token) && hash_equals($expected, $token);
    }

    /** 返回一次性表单请求令牌；用于防止网络重试/双击造成重复写入。 */
    public static function requestToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** 读取同一会话中已完成的幂等请求结果。 */
    public static function replay(string $scope, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $now = time();
        $records = is_array($_SESSION['_request_replays'][$scope] ?? null)
            ? $_SESSION['_request_replays'][$scope] : [];
        $hit = null;
        foreach ($records as $key => $record) {
            if (!is_array($record) || (int) ($record['at'] ?? 0) + self::REQUEST_TTL < $now) {
                unset($records[$key]);
                continue;
            }
            if ((string) $key === $token && is_array($record['result'] ?? null)) {
                $hit = $record['result'];
            }
        }
        $_SESSION['_request_replays'][$scope] = array_slice($records, -32, 32, true);
        return $hit;
    }

    /** 记录成功请求结果；失败请求不会占用令牌，允许用户安全重试。 */
    public static function remember(string $scope, string $token, array $result): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return;
        }
        $records = is_array($_SESSION['_request_replays'][$scope] ?? null)
            ? $_SESSION['_request_replays'][$scope] : [];
        $records[$token] = ['at' => time(), 'result' => $result];
        $_SESSION['_request_replays'][$scope] = array_slice($records, -32, 32, true);
    }

    private static function isHttps(): bool
    {
        $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
        if (in_array($https, ['1', 'on', 'https', 'true'], true)) {
            return true;
        }

        // Proxies may send a comma-separated chain; the first value is the
        // client-facing scheme. Empty, "off" and "http" must remain plain HTTP.
        $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        $scheme = strtolower(trim(explode(',', $forwarded, 2)[0] ?? ''));
        return $scheme === 'https';
    }
}
