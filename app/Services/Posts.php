<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;
use Pafish\Core\Session;

/** Shared public visibility and reading gate; does not grant editing permission. */
final class Posts
{
    public static function commentsOpen(array $post): bool
    {
        return Settings::get('comments_enabled', 'true') !== 'false'
            && (int) ($post['allow_comments'] ?? 1) === 1;
    }

    public static function publicWhere(string $alias = ''): string
    {
        if ($alias !== '' && !preg_match('/^[a-z][a-z0-9_]*$/iD', $alias)) throw new \InvalidArgumentException('Invalid SQL alias');
        $p = $alias === '' ? '' : $alias . '.';
        return "{$p}status='PUBLISHED' AND {$p}deleted_at IS NULL AND ({$p}published_at IS NULL OR {$p}published_at<=NOW())";
    }

    public static function isPublic(?array $post): bool
    {
        return $post !== null && $post['status'] === 'PUBLISHED' && empty($post['deleted_at'])
            && ($post['published_at'] === null || $post['published_at'] <= date('Y-m-d H:i:s'));
    }

    public static function requireReadable(?array $post, bool $sessionUnlock = false): array
    {
        if (!self::isPublic($post)) throw new \DomainException('文章不存在', 404);
        $password = (string) ($post['password'] ?? '');
        if ($password !== '' && (!$sessionUnlock || !Session::unlockedPost((int) $post['id'], $password, time()))) {
            throw new \DomainException('文章需要密码', 403);
        }
        return $post;
    }

    public static function findReadable(int $id, bool $sessionUnlock = false, bool $lock = false): array
    {
        $where = self::publicWhere();
        return self::requireReadable(DB::fetchOne("SELECT * FROM posts WHERE id=? AND {$where}" . ($lock ? ' FOR UPDATE' : ''), [$id]), $sessionUnlock);
    }
}
