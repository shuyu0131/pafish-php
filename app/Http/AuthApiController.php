<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Core\RateLimiter;
use Pafish\Core\Session;
use Pafish\Core\Url;
use Pafish\Services\EmailCode;
use Pafish\Services\LoginService;
use Pafish\Services\Notify;
use Pafish\Services\Settings;
use Pafish\Http\Responses;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 认证 API
 * - login / logout：会话登录登出
 * - register：开放注册（allow_registration）+ 可选邮箱验证码（require_email_verify，默认开）
 * - send-code：注册/忘记密码验证码（忘记密码防枚举，未注册邮箱统一返回成功）
 * - reset-by-code：验证码重置（一次性）
 * - forgot / reset：令牌流（30 分钟一次性链接，令牌只通过邮件传递）
 */
final class AuthApiController
{
    // ---------- 登录 / 登出 ----------

    public function login(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf(is_string($body['_csrf'] ?? null) ? $body['_csrf'] : null)) {
            return $this->json($response, ['error' => '请求已过期，请刷新页面重试'], 419);
        }
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $attempt = LoginService::attempt($request, $username, $password);
        if (!isset($attempt['user'])) {
            return $this->json($response, ['error' => $attempt['error'] ?? '登录失败'], (int) ($attempt['status'] ?? 401));
        }
        $user = $attempt['user'];
        Auth::login((int) $user['id']);
        Session::set('last_active_touch', time());
        LoginService::succeeded($user);

        return $this->json($response, ['ok' => true, 'username' => $user['username'], 'role' => $user['role']]);
    }

    public function logout(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $csrf = (string) ($body['_csrf'] ?? $request->getHeaderLine('X-CSRF-Token'));
        if (!Session::verifyCsrf($csrf)) {
            return $this->json($response, ['error' => '请求已过期，请刷新页面重试'], 419);
        }
        $user = Auth::user();
        if ($user) {
            \do_action('after_logout', ['id' => (string) $user['id']]);
        }
        Auth::logout();

        // 原生表单提交（浏览器导航）会把用户带到接口地址；只有明确标记为
        // XHR 的脚本请求才返回 JSON，其余请求统一回到首页。
        if (!$this->isAjax($request)) {
            return $response->withStatus(302)->withHeader('Location', Url::to('/'));
        }
        return $this->json($response, ['ok' => true]);
    }

    /** 只有明确的 XHR 请求才返回 JSON，避免原生表单停留在接口页面。 */
    private function isAjax(Request $request): bool
    {
        return strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

    // ---------- 注册 ----------

    public function register(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf(is_string($body['_csrf'] ?? null) ? $body['_csrf'] : null)) {
            return $this->json($response, ['error' => '请求已过期，请刷新页面重试'], 419);
        }
        if ((string) Settings::get('allow_registration', 'true') === 'false') {
            return $this->json($response, ['error' => '注册已关闭'], 403);
        }

        $username = mb_substr(trim((string) ($body['username'] ?? '')), 0, 50);
        $email = mb_substr(trim((string) ($body['email'] ?? '')), 0, 255);
        $password = (string) ($body['password'] ?? '');
        $code = trim((string) ($body['code'] ?? ''));

        if (!preg_match('/^[\w\x{4e00}-\x{9fa5}-]{2,50}$/u', $username)) {
            return $this->json($response, ['error' => '用户名需 2-50 位，仅限中文、字母、数字、下划线和连字符'], 400);
        }
        if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            return $this->json($response, ['error' => '邮箱格式不正确'], 400);
        }
        if (!Auth::validPassword($password)) {
            return $this->json($response, ['error' => Auth::PASSWORD_RULE], 400);
        }

        $registerDecision = \apply_decision_filters('before_register', [
            'allowed' => true,
            'status' => 403,
            'error' => '注册被安全策略拒绝',
        ], [
            'username' => $username,
            'email' => strtolower($email),
            'plugins' => is_array($body['plugins'] ?? null) ? $body['plugins'] : [],
            'ip' => (string) (($request->getServerParams()['REMOTE_ADDR'] ?? '') ?: 'unknown'),
        ]);
        if ($registerDecision === false || (is_array($registerDecision) && ($registerDecision['allowed'] ?? true) === false)) {
            $status = max(400, min(499, (int) (is_array($registerDecision) ? ($registerDecision['status'] ?? 403) : 403)));
            return $this->json($response, ['error' => is_array($registerDecision) ? (string) ($registerDecision['error'] ?? '注册被安全策略拒绝') : '注册被安全策略拒绝'], $status);
        }

        if ((string) Settings::get('require_email_verify', 'true') !== 'false') {
            $verifyKey = $this->remoteIp($request) . '|' . strtolower($email) . '|register';
            if (!RateLimiter::allow('auth-code-verify', $verifyKey, 8, 600)
                || !EmailCode::verify(strtolower($email), 'register', $code)) {
                return $this->json($response, ['error' => '验证码错误或已过期'], 400);
            }
            RateLimiter::clear('auth-code-verify', $verifyKey);
        }

        // When verification is enabled, disclose account conflicts only after the
        // caller proves control of the mailbox, avoiding an account-existence oracle.
        $exists = DB::fetchOne(
            'SELECT username, email FROM users WHERE username = ? OR email = ?',
            [$username, $email]
        );
        if ($exists) {
            return $this->json($response, [
                'error' => ($exists['username'] === $username) ? '用户名已被占用' : '邮箱已被注册',
            ], 409);
        }

        DB::execute(
            'INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)',
            [$username, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]), 'USER']
        );
        $userId = (int) DB::lastInsertId();

        // 注册后自动登录
        Auth::login($userId);
        Session::remove('auth_register_email');
        \do_action('after_register', ['id' => (string) $userId, 'username' => $username]);

        return $this->json($response, ['ok' => true, 'username' => $username]);
    }

    // ---------- 验证码 ----------

    public function sendCode(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf(is_string($body['_csrf'] ?? null) ? $body['_csrf'] : null)) {
            return $this->json($response, ['error' => '请求已过期，请刷新页面重试'], 419);
        }
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $purpose = (string) ($body['purpose'] ?? '');

        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->json($response, ['error' => '邮箱格式不正确'], 400);
        }
        if ($purpose !== 'register' && $purpose !== 'reset') {
            return $this->json($response, ['error' => '用途不正确'], 400);
        }

        if ($purpose === 'register' && (string) Settings::get('allow_registration', 'true') === 'false') {
            return $this->json($response, ['error' => '注册已关闭'], 403);
        }

        if (!RateLimiter::allow('auth-code-send-ip', $this->remoteIp($request), 10, 3600)) {
            return $this->json($response, ['error' => '请求过于频繁，请稍后再试'], 429);
        }

        // 注册场景直接提示邮箱已注册；忘记密码场景统一响应。
        if ($purpose === 'register') {
            $exists = DB::fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
            if ($exists) {
                return $this->json($response, ['error' => '该邮箱已注册，可直接登录'], 409);
            }
        }

        // 忘记密码：用户不存在（或被禁用）时静默返回成功（防枚举），不发信
        if ($purpose === 'reset') {
            $user = DB::fetchOne('SELECT id, disabled FROM users WHERE email = ?', [$email]);
            if (!$user || (int) $user['disabled'] === 1) {
                EmailCode::forget('reset');
                Session::set('auth_reset_email', $email);
                return $this->json($response, ['ok' => true]);
            }
        }

        [$code, $tooFrequent] = EmailCode::create($email, $purpose);
        if ($tooFrequent) {
            return $this->json($response, ['error' => '发送过于频繁，请 60 秒后再试'], 429);
        }

        try {
            Notify::sendEmailCode($email, $code, $purpose);
        } catch (\Throwable) {
            EmailCode::forget($purpose);
            Session::remove($purpose === 'register' ? 'auth_register_email' : 'auth_reset_email');
            return $this->json($response, ['error' => '邮件服务未配置或发送失败，请联系管理员'], 400);
        }

        Session::set($purpose === 'register' ? 'auth_register_email' : 'auth_reset_email', $email);

        return $this->json($response, ['ok' => true]);
    }

    public function resetByCode(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf(is_string($body['_csrf'] ?? null) ? $body['_csrf'] : null)) {
            return $this->json($response, ['error' => '请求已过期，请刷新页面重试'], 419);
        }
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $code = trim((string) ($body['code'] ?? ''));
        $newPassword = (string) ($body['newPassword'] ?? '');

        if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            return $this->json($response, ['error' => '邮箱格式不正确'], 400);
        }
        if (!Auth::validPassword($newPassword)) {
            return $this->json($response, ['error' => Auth::PASSWORD_RULE], 400);
        }

        $user = DB::fetchOne('SELECT id, disabled FROM users WHERE email = ?', [$email]);
        if (!$user || (int) $user['disabled'] === 1) {
            return $this->json($response, ['error' => '验证码错误或已过期'], 400);
        }

        // 校验失败统一提示（含过期 / 不存在 / 已使用）
        $verifyKey = $this->remoteIp($request) . '|' . $email . '|reset';
        if (!RateLimiter::allow('auth-code-verify', $verifyKey, 8, 600)
            || !EmailCode::verify($email, 'reset', $code)) {
            return $this->json($response, ['error' => '验证码错误或已过期'], 400);
        }
        RateLimiter::clear('auth-code-verify', $verifyKey);

        $changed = DB::transaction(static function () use ($newPassword, $user, $email): int {
            $changed = DB::execute(
                'UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ? AND disabled = 0 AND email = ?',
                [password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]), (int) $user['id'], $email]
            );
            if ($changed === 1) \Pafish\Services\AppTokens::revokeAll((int) $user['id']);
            return $changed;
        });
        if ($changed !== 1) return $this->json($response, ['error' => '验证码错误或已过期'], 400);

        Session::remove('auth_reset_email');

        return $this->json($response, ['ok' => true]);
    }

    // ---------- 令牌流（forgot / reset） ----------

    public function forgot(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf(is_string($body['_csrf'] ?? null) ? $body['_csrf'] : null)) {
            return $this->json($response, ['error' => '请求已过期，请刷新页面重试'], 419);
        }
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            return $this->json($response, ['error' => '邮箱格式不正确'], 400);
        }

        if (!RateLimiter::allow('auth-forgot-ip', $this->remoteIp($request), 5, 3600)) {
            return $this->json($response, ['error' => '请求过于频繁，请稍后再试'], 429);
        }

        $user = DB::fetchOne('SELECT id, username, disabled FROM users WHERE email = ?', [$email]);
        // 统一响应，避免暴露邮箱是否注册
        if (!$user || (int) $user['disabled'] === 1) {
            return $this->json($response, ['ok' => true, 'sent' => false]);
        }

        $token = bin2hex(random_bytes(32));
        DB::execute(
            'UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?',
            [$token, date('Y-m-d H:i:s', time() + 30 * 60), (int) $user['id']]
        );

        $resetUrl = Url::trustedAbsolute('/reset-password?token=' . rawurlencode($token));
        if ($resetUrl === null) {
            DB::execute(
                'UPDATE users SET reset_token = NULL, reset_token_expires = NULL WHERE id = ? AND reset_token = ?',
                [(int) $user['id'], $token]
            );
            return $this->json($response, ['ok' => true, 'sent' => false]);
        }
        $mailed = false;
        try {
            Notify::sendResetLinkEmail($email, (string) $user['username'], $resetUrl);
            $mailed = true;
        } catch (\Throwable) {
            DB::execute(
                'UPDATE users SET reset_token = NULL, reset_token_expires = NULL WHERE id = ? AND reset_token = ?',
                [(int) $user['id'], $token]
            );
            return $this->json($response, ['ok' => true, 'sent' => false]);
        }

        return $this->json($response, ['ok' => true, 'sent' => $mailed]);
    }

    public function reset(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf(is_string($body['_csrf'] ?? null) ? $body['_csrf'] : null)) {
            return $this->json($response, ['error' => '请求已过期，请刷新页面重试'], 419);
        }
        $token = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if (!Auth::validPassword($password)) {
            return $this->json($response, ['error' => Auth::PASSWORD_RULE], 400);
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) return $this->json($response, ['error' => '重置链接无效或已过期'], 400);

        $user = DB::fetchOne(
            'SELECT id, disabled FROM users WHERE reset_token = ? AND reset_token_expires > NOW()',
            [$token]
        );
        if (!$user) {
            return $this->json($response, ['error' => '重置链接无效或已过期'], 400);
        }
        if ((int) $user['disabled'] === 1) {
            return $this->json($response, ['error' => '账号已被禁用，请联系管理员'], 403);
        }

        // 条件更新同时消费令牌；两个请求即使都完成了上面的读取，也只允许一个成功。
        $changed = DB::transaction(static function () use ($password, $user, $token): int {
            $changed = DB::execute(
                'UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL
                 WHERE id = ? AND reset_token = ? AND reset_token_expires > NOW() AND disabled = 0',
                [password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]), (int) $user['id'], $token]
            );
            if ($changed === 1) \Pafish\Services\AppTokens::revokeAll((int) $user['id']);
            return $changed;
        });
        if ($changed !== 1) return $this->json($response, ['error' => '重置链接无效或已过期'], 400);

        Session::remove('auth_reset_email');

        return $this->json($response, ['ok' => true]);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        return Responses::json($response, $data, $status);
    }

    private function remoteIp(Request $request): string
    {
        $ip = trim((string) (($request->getServerParams()['REMOTE_ADDR'] ?? '') ?: 'unknown'));
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
    }
}
