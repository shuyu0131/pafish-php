<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 个人资料（对齐 Node app/admin/profile/）：任何登录用户可访问
 * - 资料表单：头像（上传/粘贴地址）、昵称、用户名、邮箱；校验与文案对齐 Node
 * - 修改密码：需当前密码；成功提示「密码已修改，下次登录请使用新密码」
 */
final class ProfileController extends AdminController
{
    /** GET /admin/profile */
    public function index(Request $request, Response $response): Response
    {
        Auth::requireLogin();
        $response->getBody()->write($this->render('profile', [
            'me' => Auth::user(),
        ], '个人资料'));
        return $response;
    }

    /** POST /admin/profile/save：保存头像/昵称/用户名/邮箱（无需邮箱验证码，对齐 Node） */
    public function save(Request $request, Response $response): Response
    {
        Auth::requireLogin();
        $me = Auth::user();
        $body = $request->getParsedBody() ?? [];
        $nickname = mb_substr(trim((string) ($body['nickname'] ?? '')), 0, 50);
        $username = mb_substr(trim((string) ($body['username'] ?? '')), 0, 50);
        $email = mb_substr(trim((string) ($body['email'] ?? '')), 0, 255);
        $avatarUrl = mb_substr(trim((string) ($body['avatar_url'] ?? '')), 0, 500);

        if (!preg_match('/^[\w\x{4e00}-\x{9fa5}-]{2,50}$/u', $username)) {
            return $this->json($response, ['error' => '用户名需 2-50 位，仅限中文、字母、数字、下划线和连字符'], 400);
        }
        if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            return $this->json($response, ['error' => '邮箱格式不正确'], 400);
        }
        $dup = DB::fetchOne('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, (int) $me['id']]);
        if ($dup) {
            return $this->json($response, ['error' => '用户名已被占用'], 409);
        }
        $dup = DB::fetchOne('SELECT id FROM users WHERE email = ? AND id <> ?', [strtolower($email), (int) $me['id']]);
        if ($dup) {
            return $this->json($response, ['error' => '邮箱已被注册'], 409);
        }

        DB::execute(
            'UPDATE users SET nickname = ?, username = ?, email = ?, avatar_url = ? WHERE id = ?',
            [$nickname, $username, strtolower($email), $avatarUrl, (int) $me['id']]
        );
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/profile/password：修改当前用户密码（需当前密码） */
    public function changePassword(Request $request, Response $response): Response
    {
        Auth::requireLogin();
        $me = Auth::user();
        $body = $request->getParsedBody() ?? [];
        $current = (string) ($body['current_password'] ?? '');
        $new = (string) ($body['new_password'] ?? '');
        if ($current === '') {
            return $this->json($response, ['error' => '请输入当前密码'], 400);
        }
        if (!password_verify($current, (string) ($me['password_hash'] ?? ''))) {
            return $this->json($response, ['error' => '当前密码不正确'], 400);
        }
        $len = mb_strlen($new);
        if ($len < 6 || $len > 72) {
            return $this->json($response, ['error' => '新密码长度需 6-72 位'], 400);
        }
        DB::execute(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($new, PASSWORD_BCRYPT, ['cost' => 10]), (int) $me['id']]
        );
        return $this->json($response, ['ok' => true]);
    }
}
