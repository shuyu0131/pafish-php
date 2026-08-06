<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 用户管理（对齐 Node app/admin/users/）：仅 ADMIN（guardAdmin）
 * - 列表：全量用户 + 文章/评论计数，按注册时间正序（Node 不分页）
 * - 角色下拉即时更新（含自己，Node 无最后管理员保护）
 * - 禁用/解禁（不能禁自己；禁用时清空重置令牌）、重置密码（内联新密码表单）
 * - 无创建/删除用户功能（Node 版同样没有，用户仅通过前台注册产生）
 * 文案对齐 Node：仅管理员可操作 / 无效角色 / 用户不存在 / 不能禁用自己的账号 / 密码长度需 6-72 位
 */
final class UsersController extends AdminController
{
    private const ROLES = ['ADMIN', 'EDITOR', 'USER'];

    /** GET /admin/users */
    public function index(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $rows = DB::fetchAll(
            'SELECT u.*, '
            . '(SELECT COUNT(*) FROM posts p WHERE p.author_id = u.id) AS post_count, '
            . '(SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id) AS comment_count '
            . 'FROM users u ORDER BY u.created_at ASC'
        );
        $response->getBody()->write($this->render('users', [
            'users' => $rows,
            'me' => Auth::user(),
        ], '用户管理'));
        return $response;
    }

    /** POST /admin/users/{id}/role：变更角色（含自己，对齐 Node RoleSelect） */
    public function updateRole(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $user = $this->findUser($request, $response, $args);
        if ($user instanceof Response) {
            return $user;
        }
        $role = (string) ($request->getParsedBody()['role'] ?? '');
        if (!in_array($role, self::ROLES, true)) {
            return $this->json($response, ['error' => '无效角色'], 400);
        }
        DB::execute('UPDATE users SET role = ? WHERE id = ?', [$role, (int) $user['id']]);
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/users/{id}/toggle：禁用/解禁（不能禁自己；禁用时清空重置令牌） */
    public function toggleDisabled(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $me = Auth::user();
        $user = $this->findUser($request, $response, $args);
        if ($user instanceof Response) {
            return $user;
        }
        if ((int) $user['id'] === (int) $me['id']) {
            return $this->json($response, ['error' => '不能禁用自己的账号'], 400);
        }
        $new = ((int) $user['disabled']) === 1 ? 0 : 1;
        DB::execute(
            'UPDATE users SET disabled = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?',
            [$new, (int) $user['id']]
        );
        return $this->json($response, ['ok' => true, 'disabled' => $new]);
    }

    /** POST /admin/users/{id}/reset-password：管理员重置该用户密码 */
    public function resetPassword(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $user = $this->findUser($request, $response, $args);
        if ($user instanceof Response) {
            return $user;
        }
        $new = (string) ($request->getParsedBody()['password'] ?? '');
        $len = mb_strlen($new);
        if ($len < 6 || $len > 72) {
            return $this->json($response, ['error' => '密码长度需 6-72 位'], 400);
        }
        DB::execute(
            'UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?',
            [password_hash($new, PASSWORD_BCRYPT, ['cost' => 10]), (int) $user['id']]
        );
        return $this->json($response, ['ok' => true]);
    }

    /** 按 id 查用户；不存在时返回 400 JSON 响应（调用方直接 return） */
    private function findUser(Request $request, Response $response, array $args): Response|array
    {
        $user = DB::fetchOne('SELECT * FROM users WHERE id = ?', [(int) ($args['id'] ?? 0)]);
        if ($user === null) {
            return $this->json($response, ['error' => '用户不存在'], 400);
        }
        return $user;
    }
}
