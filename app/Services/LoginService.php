<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;
use Pafish\Core\RateLimiter;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Shared password verification and attempt limits for browser and app logins. */
final class LoginService
{
    /** @return array{user?:array,error?:string,status?:int,loginKey?:string} */
    public static function attempt(Request $request, string $username, string $password): array
    {
        if ($username === '' || $password === '') return ['error' => '请输入用户名和密码', 'status' => 400];

        $ip = trim((string) (($request->getServerParams()['REMOTE_ADDR'] ?? '') ?: 'unknown'));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) $ip = 'unknown';
        $loginKey = $ip . '|' . mb_strtolower($username, 'UTF-8');
        if (!RateLimiter::allow('auth-login-ip', $ip, 30, 300)
            || !RateLimiter::allow('auth-login-combo', $loginKey, 8, 600)) {
            return ['error' => '登录尝试过于频繁，请稍后再试', 'status' => 429];
        }

        $body = $request->getParsedBody() ?? [];
        $loginDecision = \apply_decision_filters('before_login', [
            'allowed' => true,
            'status' => 403,
            'error' => '登录被安全策略拒绝',
        ], [
            'username' => $username,
            'plugins' => is_array($body['plugins'] ?? null) ? $body['plugins'] : [],
            'ip' => (string) (($request->getServerParams()['REMOTE_ADDR'] ?? '') ?: 'unknown'),
        ]);
        if ($loginDecision === false || (is_array($loginDecision) && ($loginDecision['allowed'] ?? true) === false)) {
            $status = max(400, min(499, (int) (is_array($loginDecision) ? ($loginDecision['status'] ?? 403) : 403)));
            $error = is_array($loginDecision) ? (string) ($loginDecision['error'] ?? '登录被安全策略拒绝') : '登录被安全策略拒绝';
            return ['error' => $error, 'status' => $status];
        }

        $user = DB::fetchOne('SELECT id, username, password_hash, role, disabled FROM users WHERE username = ?', [$username]);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return ['error' => '用户名或密码错误', 'status' => 401];
        }
        if ((int) $user['disabled'] === 1) return ['error' => '账号已被禁用，请联系管理员', 'status' => 403];

        $storedIp = filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
        DB::execute('UPDATE users SET last_login_ip = ?, last_active_at = NOW() WHERE id = ?', [$storedIp, (int) $user['id']]);
        RateLimiter::clear('auth-login-combo', $loginKey);
        unset($user['password_hash'], $user['disabled']);
        return ['user' => $user, 'loginKey' => $loginKey];
    }

    public static function succeeded(array $user): void
    {
        \do_action('after_login', [
            'id' => (string) $user['id'],
            'username' => (string) $user['username'],
            'role' => (string) $user['role'],
        ]);
    }
}
