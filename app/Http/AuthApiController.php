<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Services\EmailCode;
use Pafish\Services\Notify;
use Pafish\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 认证 API（对齐 Node 版 src/app/api/auth/*）
 * - login / logout：会话登录登出
 * - register：开放注册（allow_registration）+ 可选邮箱验证码（require_email_verify，默认开）
 * - send-code：注册/忘记密码验证码（忘记密码防枚举，未注册邮箱统一返回成功）
 * - reset-by-code：验证码重置（一次性）
 * - forgot / reset：令牌流（30 分钟一次性链接；未配置 SMTP 时返回 resetUrl 自托管兜底）
 */
final class AuthApiController
{
    // ---------- 登录 / 登出 ----------

    public function login(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            return $this->json($response, ['error' => '请输入用户名和密码'], 400);
        }

        $loginDecision = \apply_filters('before_login', [
            'allowed' => true,
            'status' => 403,
            'error' => '登录被安全策略拒绝',
        ], [
            'username' => $username,
            'plugins' => is_array($body['plugins'] ?? null) ? $body['plugins'] : [],
            'ip' => (string) (($request->getServerParams()['REMOTE_ADDR'] ?? '') ?: 'unknown'),
        ]);
        if (is_array($loginDecision) && ($loginDecision['allowed'] ?? true) === false) {
            $status = max(400, min(499, (int) ($loginDecision['status'] ?? 403)));
            return $this->json($response, ['error' => (string) ($loginDecision['error'] ?? '登录被安全策略拒绝')], $status);
        }

        $user = DB::fetchOne(
            'SELECT id, username, password_hash, role, disabled FROM users WHERE username = ?',
            [$username]
        );
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return $this->json($response, ['error' => '用户名或密码错误'], 401);
        }
        if ((int) $user['disabled'] === 1) {
            return $this->json($response, ['error' => '账号已被禁用，请联系管理员'], 403);
        }

        Auth::login((int) $user['id']);

        // 钩子：登录成功
        \do_action('after_login', [
            'id' => (string) $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
        ]);

        return $this->json($response, ['ok' => true, 'username' => $user['username'], 'role' => $user['role']]);
    }

    public function logout(Request $request, Response $response): Response
    {
        $user = Auth::user();
        if ($user) {
            \do_action('after_logout', ['id' => (string) $user['id']]);
        }
        Auth::logout();
        return $this->json($response, ['ok' => true]);
    }

    // ---------- 注册 ----------

    public function register(Request $request, Response $response): Response
    {
        if ((string) Settings::get('allow_registration', 'true') === 'false') {
            return $this->json($response, ['error' => '注册已关闭'], 403);
        }

        $body = $request->getParsedBody() ?? [];
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
        $len = mb_strlen($password);
        if ($len < 6 || $len > 72) {
            return $this->json($response, ['error' => '密码长度需 6-72 位'], 400);
        }

        $registerDecision = \apply_filters('before_register', [
            'allowed' => true,
            'status' => 403,
            'error' => '注册被安全策略拒绝',
        ], [
            'username' => $username,
            'email' => strtolower($email),
            'plugins' => is_array($body['plugins'] ?? null) ? $body['plugins'] : [],
            'ip' => (string) (($request->getServerParams()['REMOTE_ADDR'] ?? '') ?: 'unknown'),
        ]);
        if (is_array($registerDecision) && ($registerDecision['allowed'] ?? true) === false) {
            $status = max(400, min(499, (int) ($registerDecision['status'] ?? 403)));
            return $this->json($response, ['error' => (string) ($registerDecision['error'] ?? '注册被安全策略拒绝')], $status);
        }

        // 邮箱验证码：站点开启时必须通过（校验通过后自动标记已使用）；默认开启
        if ((string) Settings::get('require_email_verify', 'true') !== 'false') {
            if (!EmailCode::verify(strtolower($email), 'register', $code)) {
                return $this->json($response, ['error' => '验证码错误或已过期'], 400);
            }
        }

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
        \do_action('after_register', ['id' => (string) $userId, 'username' => $username]);

        return $this->json($response, ['ok' => true, 'username' => $username]);
    }

    // ---------- 验证码 ----------

    public function sendCode(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $purpose = (string) ($body['purpose'] ?? '');

        if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            return $this->json($response, ['error' => '邮箱格式不正确'], 400);
        }
        if ($purpose !== 'register' && $purpose !== 'reset') {
            return $this->json($response, ['error' => '用途不正确'], 400);
        }

        if ($purpose === 'register' && (string) Settings::get('allow_registration', 'true') === 'false') {
            return $this->json($response, ['error' => '注册已关闭'], 403);
        }

        // 注册场景：邮箱已注册则直接提示（防止用验证码探测）；忘记密码场景统一响应防枚举
        if ($purpose === 'register') {
            $exists = DB::fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
            if ($exists) {
                return $this->json($response, ['error' => '该邮箱已注册，可直接登录'], 409);
            }
        }

        [$code, $tooFrequent] = EmailCode::create($email, $purpose);
        if ($tooFrequent) {
            return $this->json($response, ['error' => '发送过于频繁，请 60 秒后再试'], 429);
        }

        // 忘记密码：用户不存在（或被禁用）时静默返回成功（防枚举），不发信
        if ($purpose === 'reset') {
            $user = DB::fetchOne('SELECT id, disabled FROM users WHERE email = ?', [$email]);
            if (!$user || (int) $user['disabled'] === 1) {
                return $this->json($response, ['ok' => true]);
            }
        }

        try {
            Notify::sendEmailCode($email, $code, $purpose);
        } catch (\Throwable) {
            return $this->json($response, ['error' => '邮件服务未配置或发送失败，请联系管理员'], 400);
        }

        return $this->json($response, ['ok' => true]);
    }

    public function resetByCode(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $code = trim((string) ($body['code'] ?? ''));
        $newPassword = (string) ($body['newPassword'] ?? '');

        if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            return $this->json($response, ['error' => '邮箱格式不正确'], 400);
        }
        $len = mb_strlen($newPassword);
        if ($len < 6 || $len > 72) {
            return $this->json($response, ['error' => '密码长度需 6-72 位'], 400);
        }

        $user = DB::fetchOne('SELECT id, disabled FROM users WHERE email = ?', [$email]);
        if (!$user || (int) $user['disabled'] === 1) {
            return $this->json($response, ['error' => '用户不存在'], 404);
        }

        // 校验失败统一提示（含过期 / 不存在 / 已使用）
        if (!EmailCode::verify($email, 'reset', $code)) {
            return $this->json($response, ['error' => '验证码错误或已过期'], 400);
        }

        DB::execute(
            'UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?',
            [password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]), (int) $user['id']]
        );

        return $this->json($response, ['ok' => true]);
    }

    // ---------- 令牌流（forgot / reset） ----------

    public function forgot(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            return $this->json($response, ['error' => '邮箱格式不正确'], 400);
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

        $resetUrl = \absolute_url('/reset-password?token=' . $token);
        $mailed = false;
        try {
            Notify::sendResetLinkEmail($email, (string) $user['username'], $resetUrl);
            $mailed = true;
        } catch (\Throwable) {
            $mailed = false;
        }

        // 未配置 SMTP 时返回链接方便自托管用户（生产环境配置 SMTP 后此分支不触发）
        return $this->json($response, ['ok' => true, 'sent' => $mailed, 'resetUrl' => $mailed ? null : $resetUrl]);
    }

    public function reset(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $token = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');

        $len = mb_strlen($password);
        if ($len < 6 || $len > 72) {
            return $this->json($response, ['error' => '密码长度需 6-72 位'], 400);
        }

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

        DB::execute(
            'UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?',
            [password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]), (int) $user['id']]
        );

        return $this->json($response, ['ok' => true]);
    }

    // ---------- 内部 ----------

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
