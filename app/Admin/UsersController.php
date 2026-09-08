<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Core\Session;
use Pafish\Services\Points;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 用户管理：仅 ADMIN（guardAdmin）
 * - 列表：全量用户 + 文章/评论计数，按注册时间正序
 * - 角色下拉即时更新（含自己）
 * - 禁用/解禁（不能禁自己；禁用时清空重置令牌）、重置密码（内联新密码表单）
 * - 无创建/删除用户功能，用户通过前台注册产生
 * 统一错误文案和密码长度校验
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
        $pointsEnabled = Points::available();
        if ($pointsEnabled) {
            foreach ($rows as &$row) {
                $row['points_balance'] = Points::balance((int) $row['id']);
            }
            unset($row);
        }
        $response->getBody()->write($this->render('users', [
            'users' => $rows,
            'me' => Auth::user(),
            'pointsEnabled' => $pointsEnabled,
        ], '用户管理'));
        return $response;
    }

    /** POST /admin/users/{id}/role：变更角色（含自己） */
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

    /** POST /admin/users/{id}/points：管理员手动调整积分，作为唯一的初始积分入口。 */
    public function adjustPoints(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        $user = DB::fetchOne('SELECT id FROM users WHERE id = ?', [(int) ($args['id'] ?? 0)]);
        if ($user === null) {
            return $this->json($response, ['error' => '用户不存在'], 400);
        }
        $amountText = trim((string) ($body['amount'] ?? ''));
        if (!preg_match('/^-?[1-9][0-9]*$/', $amountText) || abs((int) $amountText) > 100000000) {
            return $this->json($response, ['error' => '积分调整必须是 1 至 100000000 的整数（可为负数）'], 400);
        }
        $reason = trim((string) ($body['reason'] ?? '管理员调整'));
        if ($reason === '') {
            $reason = '管理员调整';
        }
        try {
            $balance = Points::adjust((int) $user['id'], (int) $amountText, mb_substr($reason, 0, 120));
            return $this->json($response, ['ok' => true, 'balance' => $balance]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
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
