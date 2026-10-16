<?php

declare(strict_types=1);

namespace Pafish\Core;

use Pafish\Services\Settings;

/**
 * 认证：会话内只存 user_id，每次请求回查数据库真实角色（防篡改）
 * 角色：ADMIN / EDITOR / USER
 */
final class Auth
{
    private const SESSION_KEY = 'user_id';

    private static ?array $user = null;
    private static bool $activityTouched = false;

    public const PASSWORD_RULE = '密码至少6个字符，且不超过72字节';

    /** 所有新账号密码共用 bcrypt 的字节上限，避免多字节密码被静默截断。 */
    public static function validPassword(string $password): bool
    {
        return mb_strlen($password, 'UTF-8') >= 6 && strlen($password) <= 72 && !str_contains($password, "\0");
    }

    public const CAPABILITIES = [
        'dashboard.view', 'posts.manage', 'pages.manage', 'taxonomy.manage',
        'media.manage', 'comments.manage', 'links.manage', 'appearance.manage',
        'settings.manage', 'plugins.manage', 'store.manage', 'upgrade.manage',
        'users.manage', 'backup.manage', 'transfer.manage', 'api.manage',
    ];

    /** 能力的中文标签，供后台权限矩阵展示。 */
    public const CAPABILITY_LABELS = [
        'dashboard.view'    => '访问后台',
        'posts.manage'      => '管理文章',
        'pages.manage'      => '管理页面',
        'taxonomy.manage'   => '管理分类与标签',
        'media.manage'      => '管理媒体库',
        'comments.manage'   => '管理评论',
        'links.manage'      => '管理友情链接',
        'appearance.manage' => '管理主题外观',
        'settings.manage'   => '修改站点设置',
        'plugins.manage'    => '管理插件',
        'store.manage'      => '访问应用商店',
        'upgrade.manage'    => '执行系统升级',
        'users.manage'      => '管理用户',
        'backup.manage'     => '备份与恢复',
        'transfer.manage'   => '站点迁移',
        'api.manage'        => '管理开放 API',
    ];

    private const DEFAULT_ROLE_CAPABILITIES = [
        'ADMIN' => self::CAPABILITIES,
        'EDITOR' => ['dashboard.view', 'posts.manage', 'media.manage', 'comments.manage'],
        'USER' => ['dashboard.view'],
    ];

    /**
     * 各角色的默认能力。
     *
     * 公开给后台设置页用于「恢复默认」，避免管理员误改后无法回退。
     *
     * @return array<string, array<int, string>>
     */
    public static function defaultRoleCapabilities(): array
    {
        return self::DEFAULT_ROLE_CAPABILITIES;
    }

    /** 当前登录用户（回查库），未登录返回 null */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user ?: null;
        }
        $id = Session::get(self::SESSION_KEY);
        if (!$id) {
            self::$user = [];
            return null;
        }
        self::$user = DB::fetchOne('SELECT * FROM users WHERE id = ? AND disabled = 0', [(int) $id]) ?? [];
        if (self::$user) {
            self::touchActivity();
        }
        return self::$user ?: null;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user ? (int) $user['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        Session::set(self::SESSION_KEY, $userId);
        Session::remove('last_active_touch');
        self::$user = null;
        self::$activityTouched = false;
    }

    public static function logout(): void
    {
        Session::remove(self::SESSION_KEY);
        Session::remove('last_active_touch');
        self::$user = null;
        self::$activityTouched = false;
    }

    /** 每个会话最多每 6 小时写入一次最近活动时间，避免后台页面产生高频写入。 */
    private static function touchActivity(): void
    {
        if (self::$activityTouched || !self::$user) {
            return;
        }
        $now = time();
        $last = (int) Session::get('last_active_touch', 0);
        if ($last > 0 && $now - $last < 21600) {
            self::$activityTouched = true;
            return;
        }
        DB::execute('UPDATE users SET last_active_at = NOW() WHERE id = ?', [(int) self::$user['id']]);
        Session::set('last_active_touch', $now);
        self::$activityTouched = true;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'ADMIN';
    }

    public static function isEditor(): bool
    {
        return (self::user()['role'] ?? '') === 'EDITOR';
    }

    /** 内容管理权限：ADMIN + EDITOR */
    public static function canManagePosts(): bool
    {
        return self::can('posts.manage');
    }

    /** Effective capabilities; ADMIN is always unrestricted for recovery. */
    public static function capabilities(?string $role = null): array
    {
        $role ??= (string) (self::user()['role'] ?? '');
        if ($role === 'ADMIN') {
            return self::CAPABILITIES;
        }
        $defaults = self::DEFAULT_ROLE_CAPABILITIES[$role] ?? [];
        $raw = Settings::get('role_capabilities', '');
        $configured = is_string($raw) ? json_decode($raw, true) : $raw;
        if (is_array($configured) && isset($configured[$role]) && is_array($configured[$role])) {
            // 非管理员的设置仅能收缩其角色上限，不能重新授予系统管理能力。
            $allowed = array_values(array_intersect(
                array_map('strval', $configured[$role]),
                self::CAPABILITIES,
                $defaults
            ));
            return array_values(array_unique($allowed));
        }
        return $defaults;
    }

    public static function roleCapabilities(): array
    {
        $result = [];
        foreach (array_keys(self::DEFAULT_ROLE_CAPABILITIES) as $role) {
            $result[$role] = self::capabilities($role);
        }
        return $result;
    }

    public static function can(string $capability): bool
    {
        return in_array($capability, self::capabilities(), true);
    }

    /** 站内 302 重定向：$path 为站内路径，$query 为已 urlencode 的查询串（由中间件转为响应） */
    public static function redirect(string $path, string $query = ''): never
    {
        throw new RedirectException(Url::to($path) . ($query === '' ? '' : '?' . $query));
    }

    /** 后台页面守卫：未登录跳登录页 */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            $from = Url::localPathOr((string) ($_SERVER['REQUEST_URI'] ?? '/admin'), '/admin');
            self::redirect('/login', 'from=' . rawurlencode($from));
        }
    }

    /** 仅管理员 */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            self::redirect(self::isEditor() ? '/admin' : '/profile');
        }
    }

    public static function requireCapability(string $capability): void
    {
        self::requireLogin();
        if (!self::can($capability)) {
            self::redirect(self::isEditor() ? '/admin' : '/profile');
        }
    }

    /** API 守卫：未登录返回 401 JSON（由调用方输出） */
    public static function requireApiUser(): ?array
    {
        $user = self::user();
        if (!$user) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        return $user;
    }
}
