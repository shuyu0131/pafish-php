<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Core\Session;
use Pafish\Services\Points;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** 用户管理：检索筛选、资料维护、角色/状态操作及账号安全管理。 */
final class UsersController extends AdminController
{
    private const ROLES = ['ADMIN', 'EDITOR', 'USER'];

    /** 管理员直接创建账号，不依赖前台注册/邮箱验证码。 */
    public function create(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        $username = trim((string) ($body['username'] ?? ''));
        $nickname = trim((string) ($body['nickname'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $role = strtoupper((string) ($body['role'] ?? 'USER'));
        if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $username)) return $this->json($response, ['error' => '用户名需为 3-50 位字母、数字、下划线或连字符'], 400);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) return $this->json($response, ['error' => '邮箱格式不正确'], 400);
        if (mb_strlen($password) < 6 || mb_strlen($password) > 72) return $this->json($response, ['error' => '密码长度需 6-72 位'], 400);
        if (!in_array($role, self::ROLES, true)) return $this->json($response, ['error' => '无效角色'], 400);
        if (DB::fetchOne('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1', [$username, $email])) return $this->json($response, ['error' => '用户名或邮箱已存在'], 409);
        DB::execute('INSERT INTO users (username, nickname, email, password_hash, role) VALUES (?, ?, ?, ?, ?)', [
            $username, $nickname !== '' ? mb_substr($nickname, 0, 50) : null, $email,
            password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]), $role,
        ]);
        return $this->json($response, ['ok' => true, 'id' => (int) DB::lastInsertId()]);
    }

    /** GET /admin/users */
    public function index(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $allowedPer = [10, 20, 50, 100, 500];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = (int) ($_GET['per'] ?? ($_COOKIE['admin_users_per_page'] ?? 20));
        if (!in_array($per, $allowedPer, true)) {
            $per = 20;
        }
        setcookie('admin_users_per_page', (string) $per, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
        $q = trim((string) ($_GET['q'] ?? ''));
        $role = strtoupper(trim((string) ($_GET['role'] ?? '')));
        $state = trim((string) ($_GET['state'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'latest');
        if (!in_array($role, self::ROLES, true)) {
            $role = '';
        }
        if (!in_array($state, ['', 'active', 'disabled'], true)) {
            $state = '';
        }
        if (!in_array($sort, ['latest', 'oldest', 'name', 'content', 'activity', 'role', 'state'], true)) {
            $sort = 'latest';
        }

        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(u.username LIKE ? OR u.nickname LIKE ? OR u.email LIKE ?)';
            $term = '%' . $q . '%';
            array_push($params, $term, $term, $term);
        }
        if ($role !== '') {
            $where[] = 'u.role = ?';
            $params[] = $role;
        }
        if ($state === 'active') {
            $where[] = 'u.disabled = 0';
        } elseif ($state === 'disabled') {
            $where[] = 'u.disabled = 1';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM users u WHERE {$whereSql}", $params);
        $pages = max(1, (int) ceil($total / $per));
        $page = min($page, $pages);
        $orderBy = match ($sort) {
            'oldest' => 'u.created_at ASC, u.id ASC',
            'name' => 'COALESCE(NULLIF(u.nickname, \'\'), u.username) ASC, u.id DESC',
            'content' => 'post_count DESC, comment_count DESC, u.id DESC',
            'activity' => 'u.last_active_at DESC, u.created_at DESC, u.id DESC',
            'role' => "FIELD(u.role, 'ADMIN', 'EDITOR', 'USER'), u.created_at DESC, u.id DESC",
            'state' => 'u.disabled DESC, u.created_at DESC, u.id DESC',
            default => 'u.created_at DESC, u.id DESC',
        };
        $rows = DB::fetchAll(
            "SELECT u.*, "
            . '(SELECT COUNT(*) FROM posts p WHERE p.author_id = u.id AND p.deleted_at IS NULL) AS post_count, '
            . '(SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id) AS comment_count '
            . "FROM users u WHERE {$whereSql} ORDER BY {$orderBy} LIMIT {$per} OFFSET " . (($page - 1) * $per),
            $params
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
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per' => $per,
            'perOptions' => $allowedPer,
            'filters' => ['q' => $q, 'role' => $role, 'state' => $state, 'sort' => $sort],
        ], '用户管理'));
        return $response;
    }

    /** GET /admin/users/{id}/edit：独立用户资料页。 */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $id = (int) ($args['id'] ?? 0);
        $user = DB::fetchOne(
            'SELECT u.*, '
            . '(SELECT COUNT(*) FROM posts p WHERE p.author_id = u.id AND p.deleted_at IS NULL) AS post_count, '
            . '(SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id) AS comment_count '
            . 'FROM users u WHERE u.id = ?',
            [$id]
        );
        if ($user === null) {
            $this->flash('error', '用户不存在');
            return $this->redirect($response, '/admin/users');
        }
        if ($id === 1 && (string) $user['role'] === 'ADMIN' && (int) (Auth::id() ?? 0) !== 1) {
            $this->flash('error', '创始人账号只能由本人编辑');
            return $this->redirect($response, '/admin/users');
        }
        $pointsEnabled = Points::available();
        if ($pointsEnabled) {
            $user['points_balance'] = Points::balance($id);
        }
        $response->getBody()->write($this->render('user-edit', [
            'user' => $user,
            'me' => Auth::user(),
            'pointsEnabled' => $pointsEnabled,
            'isFounder' => $id === 1 && (string) $user['role'] === 'ADMIN',
        ], '编辑用户'));
        return $response;
    }

    /** POST /admin/users/{id}/save：保存用户资料，可选重设密码。 */
    public function update(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $user = $this->findUser($request, $response, $args);
        if ($user instanceof Response) {
            return $user;
        }
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->userUpdateError($request, $response, (int) $user['id'], '会话已过期，请刷新页面重试', 419);
        }
        $id = (int) $user['id'];
        $username = mb_substr(trim((string) ($body['username'] ?? $user['username'])), 0, 50);
        $nickname = mb_substr(trim((string) ($body['nickname'] ?? $user['nickname'] ?? '')), 0, 50);
        $email = mb_substr(trim((string) ($body['email'] ?? $user['email'])), 0, 255);
        $avatarUrl = mb_substr(trim((string) ($body['avatar_url'] ?? $user['avatar_url'] ?? '')), 0, 500);
        $description = array_key_exists('description', $body)
            ? mb_substr(trim((string) $body['description']), 0, 500)
            : (string) ($user['description'] ?? '');
        $role = strtoupper(trim((string) ($body['role'] ?? $user['role'])));
        $password = (string) ($body['password'] ?? '');
        $passwordConfirm = (string) ($body['password_confirm'] ?? '');

        if ($id === 1 && (string) $user['role'] === 'ADMIN' && (int) (Auth::id() ?? 0) !== 1) {
            return $this->userUpdateError($request, $response, $id, '创始人账号只能由本人编辑', 403);
        }
        if ($id === 1) {
            $role = 'ADMIN';
        }

        if (!preg_match('/^[\\w\\x{4e00}-\\x{9fa5}-]{2,50}$/u', $username)) {
            return $this->userUpdateError($request, $response, $id, '用户名需 2-50 位，仅限中文、字母、数字、下划线和连字符');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            return $this->userUpdateError($request, $response, $id, '邮箱格式不正确');
        }
        if (!in_array($role, self::ROLES, true)) {
            return $this->userUpdateError($request, $response, $id, '无效角色');
        }
        if ($avatarUrl !== '' && !preg_match('#^(?:https?://|/)#i', $avatarUrl)) {
            return $this->userUpdateError($request, $response, $id, '头像地址需使用 http(s) 或站内路径');
        }
        if ($password !== '' && (mb_strlen($password) < 6 || mb_strlen($password) > 72)) {
            return $this->userUpdateError($request, $response, $id, '密码长度需 6-72 位，留空表示不修改');
        }
        if ($password !== '' && array_key_exists('password_confirm', $body) && !hash_equals($password, $passwordConfirm)) {
            return $this->userUpdateError($request, $response, $id, '两次输入的密码不一致');
        }

        $duplicate = DB::fetchOne(
            'SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1',
            [$username, strtolower($email), $id]
        );
        if ($duplicate !== null) {
            return $this->userUpdateError($request, $response, $id, '用户名或邮箱已被其他用户使用', 409);
        }

        if ($password !== '') {
            DB::execute(
                'UPDATE users SET username = ?, nickname = ?, email = ?, avatar_url = ?, description = ?, role = ?, password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?',
                [$username, $nickname !== '' ? $nickname : null, strtolower($email), $avatarUrl !== '' ? $avatarUrl : null, $description !== '' ? $description : null, $role, password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]), $id]
            );
        } else {
            DB::execute(
                'UPDATE users SET username = ?, nickname = ?, email = ?, avatar_url = ?, description = ?, role = ? WHERE id = ?',
                [$username, $nickname !== '' ? $nickname : null, strtolower($email), $avatarUrl !== '' ? $avatarUrl : null, $description !== '' ? $description : null, $role, $id]
            );
        }

        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        $this->flash('success', '用户资料已更新');
        return $this->redirect($response, '/admin/users/' . $id . '/edit');
    }

    /** POST /admin/users/bulk：批量禁用、解禁或变更角色。 */
    public function bulk(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        $action = (string) ($body['action'] ?? '');
        if (!in_array($action, ['disable', 'enable', 'set_role'], true)) {
            return $this->json($response, ['error' => '无效操作'], 400);
        }
        $role = strtoupper(trim((string) ($body['role'] ?? '')));
        if ($action === 'set_role' && !in_array($role, self::ROLES, true)) {
            return $this->json($response, ['error' => '无效角色'], 400);
        }
        $ids = $body['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return $this->json($response, ['error' => '请先选择用户'], 400);
        }
        $meId = (int) (Auth::id() ?? 0);
        $changed = 0;
        foreach ($ids as $id) {
            if ($id === $meId) {
                continue;
            }
            $target = DB::fetchOne('SELECT id, role FROM users WHERE id = ?', [$id]);
            if ($target === null || ((int) $target['id'] === 1 && (string) $target['role'] === 'ADMIN')) {
                continue;
            }
            if ($action === 'set_role') {
                $changed += DB::execute('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
            } else {
                $newState = $action === 'disable' ? 1 : 0;
                $changed += DB::execute(
                    'UPDATE users SET disabled = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?',
                    [$newState, $id]
                );
            }
        }
        return $this->json($response, ['ok' => true, 'changed' => $changed]);
    }

    /** POST /admin/users/{id}/role：变更角色（含自己） */
    public function updateRole(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        $user = $this->findUser($request, $response, $args);
        if ($user instanceof Response) {
            return $user;
        }
        if ((int) $user['id'] === 1 && (string) $user['role'] === 'ADMIN') {
            return $this->json($response, ['error' => '创始人账号不能修改角色'], 403);
        }
        $role = (string) ($body['role'] ?? '');
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
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        $user = $this->findUser($request, $response, $args);
        if ($user instanceof Response) {
            return $user;
        }
        if ((int) $user['id'] === (int) $me['id']) {
            return $this->json($response, ['error' => '不能禁用自己的账号'], 400);
        }
        if ((int) $user['id'] === 1 && (string) $user['role'] === 'ADMIN') {
            return $this->json($response, ['error' => '创始人账号不能禁用'], 403);
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
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        $user = $this->findUser($request, $response, $args);
        if ($user instanceof Response) {
            return $user;
        }
        $new = (string) ($body['password'] ?? '');
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
            return $this->userUpdateError($request, $response, (int) ($args['id'] ?? 0), '会话已过期，请刷新页面重试', 419);
        }
        $user = DB::fetchOne('SELECT id FROM users WHERE id = ?', [(int) ($args['id'] ?? 0)]);
        if ($user === null) {
            return $this->userUpdateError($request, $response, (int) ($args['id'] ?? 0), '用户不存在', 404);
        }
        $amountText = trim((string) ($body['amount'] ?? ''));
        if (!preg_match('/^-?[1-9][0-9]*$/', $amountText) || abs((int) $amountText) > 100000000) {
            return $this->userUpdateError($request, $response, (int) $user['id'], '积分调整必须是 1 至 100000000 的整数（可为负数）');
        }
        $reason = trim((string) ($body['reason'] ?? '管理员调整'));
        if ($reason === '') {
            $reason = '管理员调整';
        }
        try {
            $balance = Points::adjust((int) $user['id'], (int) $amountText, mb_substr($reason, 0, 120));
            if (!$this->isAjax($request)) {
                $this->flash('success', '积分已调整，当前余额为 ' . $balance);
                return $this->redirect($response, '/admin/users/' . (int) $user['id'] . '/edit');
            }
            return $this->json($response, ['ok' => true, 'balance' => $balance]);
        } catch (\Throwable $e) {
            error_log('[pafish-user-points] ' . $e->getMessage());
            return $this->userUpdateError($request, $response, (int) $user['id'], '积分调整失败，请稍后重试', 400);
        }
    }

    /** POST /admin/users/{id}/delete：删除用户并将其内容转移给当前管理员。 */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->userDeleteError($request, $response, '会话已过期，请刷新页面重试', 419);
        }
        $user = DB::fetchOne('SELECT * FROM users WHERE id = ?', [(int) ($args['id'] ?? 0)]);
        $meId = (int) (Auth::id() ?? 0);
        if ($user === null) {
            return $this->userDeleteError($request, $response, '用户不存在', 404);
        }
        $id = (int) $user['id'];
        if ($id === $meId) {
            return $this->userDeleteError($request, $response, '不能删除当前登录账号');
        }
        if ($id === 1 && (string) $user['role'] === 'ADMIN') {
            return $this->userDeleteError($request, $response, '创始人账号不能删除', 403);
        }
        $target = DB::fetchOne('SELECT id FROM users WHERE id = ?', [$meId]);
        if ($target === null) {
            return $this->userDeleteError($request, $response, '当前管理员账号不存在', 409);
        }

        try {
            DB::transaction(function () use ($id, $meId): void {
                // 先转移有 NOT NULL 外键的内容，避免删除用户触发约束失败或丢失微语。
                DB::execute('UPDATE posts SET author_id = ? WHERE author_id = ?', [$meId, $id]);
                DB::execute('UPDATE micro_statuses SET author_id = ? WHERE author_id = ?', [$meId, $id]);
                DB::execute('DELETE FROM users WHERE id = ?', [$id]);
            });
        } catch (\Throwable $e) {
            error_log('[pafish-user-delete] ' . $e->getMessage());
            return $this->userDeleteError($request, $response, '删除用户失败，请检查数据库约束后重试', 500);
        }

        \do_action('after_user_delete', [
            'id' => (string) $id,
            'username' => (string) $user['username'],
            'email' => (string) $user['email'],
            'transfer_to' => (string) $meId,
        ]);
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true, 'transfer_to' => $meId]);
        }
        $this->flash('success', '用户已删除，内容已转移给当前管理员');
        return $this->redirect($response, '/admin/users');
    }

    private function userUpdateError(Request $request, Response $response, int $id, string $message, int $status = 400): Response
    {
        if ($this->isAjax($request)) {
            return $this->json($response, ['error' => $message], $status);
        }
        $this->flash('error', $message);
        return $this->redirect($response, '/admin/users/' . $id . '/edit');
    }

    private function userDeleteError(Request $request, Response $response, string $message, int $status = 400): Response
    {
        if ($this->isAjax($request)) {
            return $this->json($response, ['error' => $message], $status);
        }
        $this->flash('error', $message);
        return $this->redirect($response, '/admin/users');
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
