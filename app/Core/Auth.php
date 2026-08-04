<?php

declare(strict_types=1);

namespace Pafish\Core;

/**
 * 认证：会话内只存 user_id，每次请求回查数据库真实角色（防篡改）
 * 角色：ADMIN / EDITOR / USER
 */
final class Auth
{
    private const SESSION_KEY = 'user_id';

    private static ?array $user = null;

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
        Session::set(self::SESSION_KEY, $userId);
        self::$user = null;
    }

    public static function logout(): void
    {
        Session::remove(self::SESSION_KEY);
        self::$user = null;
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
        return in_array(self::user()['role'] ?? '', ['ADMIN', 'EDITOR'], true);
    }

    /** 后台页面守卫：未登录跳登录页 */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            $from = $_SERVER['REQUEST_URI'] ?? '/admin';
            header('Location: ' . Url::to('/login') . '?from=' . urlencode($from));
            exit;
        }
    }

    /** 仅管理员 */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            // 对齐 Node requireAdmin：非 ADMIN 重定向回工作台（redirect('/admin')）
            header('Location: ' . Url::to('/admin'));
            exit;
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
