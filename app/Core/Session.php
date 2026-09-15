<?php

declare(strict_types=1);

namespace Pafish\Core;

/**
 * 会话：7 天有效、httpOnly、SameSite=Lax；附 CSRF 令牌
 */
final class Session
{
    private const LIFETIME = 604800; // 7 天

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
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
        $expected = $_SESSION['_csrf'] ?? null;
        return is_string($expected) && is_string($token) && hash_equals($expected, $token);
    }

    private static function isHttps(): bool
    {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}
