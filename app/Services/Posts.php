<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;
use Pafish\Core\Hooks;
use Pafish\Core\Session;

/** Shared public visibility and reading gate; does not grant editing permission. */
final class Posts
{
    public static function commentsOpen(array $post): bool
    {
        return Settings::get('comments_enabled', 'true') !== 'false'
            && (int) ($post['allow_comments'] ?? 1) === 1;
    }

    public static function publicWhere(string $alias = '', bool $includeOwnedPrivate = true): string
    {
        if ($alias !== '' && !preg_match('/^[a-z][a-z0-9_]*$/iD', $alias)) throw new \InvalidArgumentException('Invalid SQL alias');
        $p = $alias === '' ? '' : $alias . '.';
        $where = "{$p}status='PUBLISHED' AND {$p}deleted_at IS NULL AND ({$p}published_at IS NULL OR {$p}published_at<=NOW())";
        $visibility = Hooks::applyFilters('posts_public_visibility_sql', '', [
            'alias' => $alias,
            'include_owned_private' => $includeOwnedPrivate,
        ]);
        if (is_string($visibility) && trim($visibility) !== '') {
            $where .= ' AND (' . $visibility . ')';
        }
        return $where;
    }

    public static function isPublic(?array $post): bool
    {
        $public = $post !== null && $post['status'] === 'PUBLISHED' && empty($post['deleted_at'])
            && ($post['published_at'] === null || $post['published_at'] <= date('Y-m-d H:i:s'));
        if (!$public) {
            return false;
        }
        return Hooks::applyDecisionFilters('post_is_public', true, $post) === true;
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
