<?php

declare(strict_types=1);

namespace Pafish\Services;

/** Normalize historical/imported article credentials without treating stored hashes as passwords. */
final class PostPasswords
{
    public static function normalize(mixed $password): ?string
    {
        if ($password === null || $password === '') return null;
        if (!is_string($password)) throw new \RuntimeException('文章密码格式无效');
        if ((password_get_info($password)['algoName'] ?? 'unknown') !== 'unknown'
            || str_starts_with($password, '$2') || str_starts_with($password, '$argon2')) {
            return $password;
        }
        // Bcrypt must not truncate long legacy passwords or reject embedded NUL bytes.
        if (strlen($password) > 72 || str_contains($password, "\0")) {
            if (!defined('PASSWORD_ARGON2ID')) {
                throw new \RuntimeException('迁移此文章密码需要 PHP 支持 Argon2id');
            }
            return password_hash($password, PASSWORD_ARGON2ID);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }
}
