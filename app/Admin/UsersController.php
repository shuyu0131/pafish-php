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
            return $this->json($response, ['error' => t('admin.your_session_has_expired_refresh_the_page_and')], 419);
        }
        $username = trim((string) ($body['username'] ?? ''));
        $nickname = trim((string) ($body['nickname'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $role = strtoupper((string) ($body['role'] ?? 'USER'));
        if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $username)) return $this->json($response, ['error' => t('admin.username_must_contain_3_50_letters_numbers_underscores')], 400);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) return $this->json($response, ['error' => t('admin.invalid_email_format')], 400);
        if (!Auth::validPassword($password)) return $this->json($response, ['error' => t('admin.password_rule')], 400);
        if (!in_array($role, self::ROLES, true)) return $this->json($response, ['error' => t('admin.invalid_role')], 400);
        if (DB::fetchOne('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1', [$username, $email])) return $this->json($response, ['error' => t('admin.username_or_email_already_exists')], 409);
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
        $overview = DB::fetchOne("SELECT COUNT(*) AS total,
            SUM(role = 'ADMIN') AS admins,
            SUM(role = 'EDITOR') AS editors,
            SUM(disabled = 1) AS disabled FROM users") ?: [];
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
        $pointsEnabled = Points::available();
        $rows = DB::fetchAll(
            "SELECT u.*, " . ($pointsEnabled ? 'COALESCE(up.balance, 0)' : '0') . ' AS points_balance, '
            . '(SELECT COUNT(*) FROM posts p WHERE p.author_id = u.id AND p.deleted_at IS NULL) AS post_count, '
            . '(SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id) AS comment_count '
            . 'FROM users u ' . ($pointsEnabled ? 'LEFT JOIN user_points up ON up.user_id = u.id ' : '')
            . "WHERE {$whereSql} ORDER BY {$orderBy} LIMIT {$per} OFFSET " . (($page - 1) * $per),
            $params
        );
        $response->getBody()->write($this->render('users', [
            'users' => $rows,
            'me' => Auth::user(),
            'pointsEnabled' => $pointsEnabled,
            'total' => $total,
            'overview' => $overview,
            'page' => $page,
            'pages' => $pages,
            'per' => $per,
            'perOptions' => $allowedPer,
            'filters' => ['q' => $q, 'role' => $role, 'state' => $state, 'sort' => $sort],
        ], t('admin.users')));
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
            $this->flash('error', t('admin.user_not_found'));
            return $this->redirect($response, '/admin/users');
        }
        if ($id === 1 && (string) $user['role'] === 'ADMIN' && (int) (Auth::id() ?? 0) !== 1) {
            $this->flash('error', t('admin.only_the_founder_can_edit_this_account'));
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
        ], t('admin.edit_user')));
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
            return $this->userUpdateError($request, $response, (int) $user['id'], t('admin.your_session_has_expired_refresh_the_page_and'), 419);
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
            return $this->userUpdateError($request, $response, $id, t('admin.only_the_founder_can_edit_this_account'), 403);
        }
        if ($id === 1) {
            $role = 'ADMIN';
        }

        if (!preg_match('/^[\\w\\x{4e00}-\\x{9fa5}-]{2,50}$/u', $username)) {
            return $this->userUpdateError($request, $response, $id, t('admin.username_must_contain_2_50_chinese_characters_letters'));
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            return $this->userUpdateError($request, $response, $id, t('admin.invalid_email_format'));
        }
        if (!in_array($role, self::ROLES, true)) {
            return $this->userUpdateError($request, $response, $id, t('admin.invalid_role'));
        }
        $roleError = $this->adminRoleChangeError($user, $role);
        if ($roleError !== null) {
            return $this->userUpdateError($request, $response, $id, $roleError, 403);
        }
        if ($avatarUrl !== '' && !preg_match('#^(?:https?://|/)#i', $avatarUrl)) {
            return $this->userUpdateError($request, $response, $id, t('admin.the_avatar_url_must_use_http_s_or'));
        }
        if ($password !== '' && (!Auth::validPassword($password))) {
            return $this->userUpdateError($request, $response, $id, t('admin.password_rule') . t('admin.leave_blank_to_keep_the_current_value'));
        }
        if ($password !== '' && array_key_exists('password_confirm', $body) && !hash_equals($password, $passwordConfirm)) {
            return $this->userUpdateError($request, $response, $id, t('admin.the_passwords_do_not_match'));
        }

        $duplicate = DB::fetchOne(
            'SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1',
            [$username, strtolower($email), $id]
        );
        if ($duplicate !== null) {
            return $this->userUpdateError($request, $response, $id, t('admin.username_or_email_is_used_by_another_user'), 409);
        }

        \Pafish\Services\MediaReferences::transaction(static function () use ($password, $username, $nickname, $email, $avatarUrl, $description, $role, $id): void {
            if ($password !== '') {
                DB::execute(
                    'UPDATE users SET username = ?, nickname = ?, email = ?, avatar_url = ?, description = ?, role = ?, password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?',
                    [$username, $nickname !== '' ? $nickname : null, strtolower($email), $avatarUrl !== '' ? $avatarUrl : null, $description !== '' ? $description : null, $role, password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]), $id]
                );
                \Pafish\Services\AppTokens::revokeAll($id);
            } else {
                DB::execute(
                    'UPDATE users SET username = ?, nickname = ?, email = ?, avatar_url = ?, description = ?, role = ? WHERE id = ?',
                    [$username, $nickname !== '' ? $nickname : null, strtolower($email), $avatarUrl !== '' ? $avatarUrl : null, $description !== '' ? $description : null, $role, $id]
                );
            }

            \Pafish\Services\MediaReferences::sync('user', $id);
            \Pafish\Core\Cache::clearPrefix('micro.latest.');
        });
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        $this->flash('success', t('admin.user_profile_updated'));
        return $this->redirect($response, '/admin/users/' . $id . '/edit');
    }

    /** POST /admin/users/bulk：批量禁用、解禁或变更角色。 */
    public function bulk(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => t('admin.your_session_has_expired_refresh_the_page_and')], 419);
        }
        $action = (string) ($body['action'] ?? '');
        if (!in_array($action, ['disable', 'enable', 'set_role'], true)) {
            return $this->json($response, ['error' => t('admin.invalid_action')], 400);
        }
        $role = strtoupper(trim((string) ($body['role'] ?? '')));
        if ($action === 'set_role' && !in_array($role, self::ROLES, true)) {
            return $this->json($response, ['error' => t('admin.invalid_role')], 400);
        }
        $ids = $body['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return $this->json($response, ['error' => t('admin.select_users_first')], 400);
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
            return $this->json($response, ['error' => t('admin.your_session_has_expired_refresh_the_page_and')], 419);
        }
        $user = $this->findUser($request, $response, $args);
        if ($user instanceof Response) {
            return $user;
        }
        if ((int) $user['id'] === 1 && (string) $user['role'] === 'ADMIN') {
            return $this->json($response, ['error' => t('admin.the_founder_s_role_cannot_be_changed')], 403);
        }
        $role = (string) ($body['role'] ?? '');
        if (!in_array($role, self::ROLES, true)) {
            return $this->json($response, ['error' => t('admin.invalid_role')], 400);
        }
        $roleError = $this->adminRoleChangeError($user, $role);
        if ($roleError !== null) {
            return $this->json($response, ['error' => $roleError], 403);
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
            return $this->json($response, ['error' => t('admin.your_session_has_expired_refresh_the_page_and')], 419);
        }
        $user = $this->findUser($request, $response, $args);
        if ($user instanceof Response) {
            return $user;
        }
        if ((int) $user['id'] === (int) $me['id']) {
            return $this->json($response, ['error' => t('admin.you_cannot_disable_your_own_account')], 400);
        }
        if ((int) $user['id'] === 1 && (string) $user['role'] === 'ADMIN') {
            return $this->json($response, ['error' => t('admin.the_founder_s_account_cannot_be_disabled')], 403);
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
            return $this->json($response, ['error' => t('admin.your_session_has_expired_refresh_the_page_and')], 419);
        }
        $user = $this->findUser($request, $response, $args);
        if ($user instanceof Response) {
            return $user;
        }
        if ((int) $user['id'] === 1 && (string) $user['role'] === 'ADMIN' && (int) (Auth::id() ?? 0) !== 1) {
            return $this->json($response, ['error' => t('admin.only_the_founder_can_reset_this_account_s')], 403);
        }
        $new = (string) ($body['password'] ?? '');
        if (!Auth::validPassword($new)) {
            return $this->json($response, ['error' => t('admin.password_rule')], 400);
        }
        DB::transaction(static function () use ($new, $user): void {
            DB::execute(
                'UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?',
                [password_hash($new, PASSWORD_BCRYPT, ['cost' => 10]), (int) $user['id']]
            );
            \Pafish\Services\AppTokens::revokeAll((int) $user['id']);
        });
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/users/{id}/points：管理员手动调整积分，作为唯一的初始积分入口。 */
    public function adjustPoints(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->userUpdateError($request, $response, (int) ($args['id'] ?? 0), t('admin.your_session_has_expired_refresh_the_page_and'), 419);
        }
        $user = DB::fetchOne('SELECT id FROM users WHERE id = ?', [(int) ($args['id'] ?? 0)]);
        if ($user === null) {
            return $this->userUpdateError($request, $response, (int) ($args['id'] ?? 0), t('admin.user_not_found'), 404);
        }
        $amountText = trim((string) ($body['amount'] ?? ''));
        if (!preg_match('/^-?[1-9][0-9]*$/', $amountText) || abs((int) $amountText) > 100000000) {
            return $this->userUpdateError($request, $response, (int) $user['id'], t('admin.the_adjustment_must_be_an_integer_from_1'));
        }
        $reason = trim((string) ($body['reason'] ?? t('admin.administrator_adjustment')));
        if ($reason === '') {
            $reason = t('admin.administrator_adjustment');
        }
        try {
            $balance = Points::adjust((int) $user['id'], (int) $amountText, mb_substr($reason, 0, 120));
            if (!$this->isAjax($request)) {
                $this->flash('success', t('admin.points_adjusted_current_balance') . $balance);
                return $this->redirect($response, '/admin/users/' . (int) $user['id'] . '/edit');
            }
            return $this->json($response, ['ok' => true, 'balance' => $balance]);
        } catch (\Throwable $e) {
            \Pafish\Core\Logger::error('[pafish-user-points] ' . $e->getMessage());
            return $this->userUpdateError($request, $response, (int) $user['id'], t('admin.unable_to_adjust_points_please_try_again_later'), 400);
        }
    }

    /** POST /admin/users/{id}/delete：删除用户并将其内容转移给当前管理员。 */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->userDeleteError($request, $response, t('admin.your_session_has_expired_refresh_the_page_and'), 419);
        }
        $user = DB::fetchOne('SELECT * FROM users WHERE id = ?', [(int) ($args['id'] ?? 0)]);
        $meId = (int) (Auth::id() ?? 0);
        if ($user === null) {
            return $this->userDeleteError($request, $response, t('admin.user_not_found'), 404);
        }
        $id = (int) $user['id'];
        if ($id === $meId) {
            return $this->userDeleteError($request, $response, t('admin.you_cannot_delete_your_own_account'));
        }
        if ($id === 1 && (string) $user['role'] === 'ADMIN') {
            return $this->userDeleteError($request, $response, t('admin.the_founder_s_account_cannot_be_deleted'), 403);
        }
        $target = DB::fetchOne('SELECT id FROM users WHERE id = ?', [$meId]);
        if ($target === null) {
            return $this->userDeleteError($request, $response, t('admin.the_current_administrator_account_does_not_exist'), 409);
        }

        try {
            \Pafish\Services\MediaReferences::transaction(function () use ($id, $meId): void {
                // 删除用户前转移其文章和微语。
                DB::execute('UPDATE posts SET author_id = ? WHERE author_id = ?', [$meId, $id]);
                DB::execute('UPDATE micro_statuses SET author_id = ? WHERE author_id = ?', [$meId, $id]);
                // 删除用户互动后更新文章汇总计数。
                DB::execute("UPDATE posts p JOIN (
                    SELECT post_id, SUM(kind = 'like') AS likes, SUM(kind = 'favorite') AS favorites
                    FROM post_reactions WHERE user_id = ? GROUP BY post_id
                ) r ON r.post_id = p.id
                SET p.like_count = GREATEST(p.like_count - r.likes, 0),
                    p.favorite_count = GREATEST(p.favorite_count - r.favorites, 0)", [$id]);
                \Pafish\Services\EntityMeta::deleteSubject('user', $id);
                DB::execute('DELETE FROM users WHERE id = ?', [$id]);
                \Pafish\Services\MediaReferences::forget('user', $id);
                \Pafish\Core\Cache::clearPrefix('micro.latest.');
            });
        } catch (\Throwable $e) {
            \Pafish\Core\Logger::error('[pafish-user-delete] ' . $e->getMessage());
            return $this->userDeleteError($request, $response, t('admin.unable_to_delete_this_user_check_database_constraints'), 500);
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
        $this->flash('success', t('admin.user_deleted_content_transferred_to_the_current_administrator'));
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

    /** 防止当前管理员或最后一个启用管理员被降级，避免系统失去管理入口。 */
    private function adminRoleChangeError(array $user, string $newRole): ?string
    {
        if ((string) ($user['role'] ?? '') !== 'ADMIN' || $newRole === 'ADMIN') {
            return null;
        }
        if ((int) ($user['id'] ?? 0) === (int) (Auth::id() ?? 0)) {
            return t('admin.you_cannot_remove_your_own_administrator_privileges');
        }
        $activeAdmins = (int) DB::value("SELECT COUNT(*) FROM users WHERE role = 'ADMIN' AND disabled = 0");
        $targetIsActive = (int) ($user['disabled'] ?? 0) === 0;
        return $targetIsActive && $activeAdmins <= 1 ? t('admin.at_least_one_active_administrator_must_remain') : null;
    }

    /** 按 id 查用户；不存在时返回 400 JSON 响应（调用方直接 return） */
    private function findUser(Request $request, Response $response, array $args): Response|array
    {
        $user = DB::fetchOne('SELECT * FROM users WHERE id = ?', [(int) ($args['id'] ?? 0)]);
        if ($user === null) {
            return $this->json($response, ['error' => t('admin.user_not_found')], 400);
        }
        return $user;
    }
}
