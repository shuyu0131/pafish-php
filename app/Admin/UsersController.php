<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Core\Session;
use Pafish\Services\Points;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** 用户管理：分页检索、角色/状态筛选、批量禁用/解禁及账号操作。 */
final class UsersController extends AdminController
{
    private const ROLES = ['ADMIN', 'EDITOR', 'USER'];

    /** 管理员直接创建账号，不依赖前台注册/邮箱验证码。 */
    public function create(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
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
        $allowedPer = [10, 20, 50, 100];
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
        if (!in_array($sort, ['latest', 'oldest', 'name', 'content'], true)) {
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

    /** POST /admin/users/bulk：批量禁用或解禁用户 */
    public function bulk(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        $action = (string) ($body['action'] ?? '');
        if (!in_array($action, ['disable', 'enable'], true)) {
            return $this->json($response, ['error' => '无效操作'], 400);
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
            $newState = $action === 'disable' ? 1 : 0;
            $changed += DB::execute(
                'UPDATE users SET disabled = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?',
                [$newState, $id]
            );
        }
        return $this->json($response, ['ok' => true, 'changed' => $changed]);
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
