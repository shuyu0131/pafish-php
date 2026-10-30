<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/** Persistent 301 aliases for content slugs that have been changed. */
final class PermalinkRedirects
{
    public static function record(string $oldPath, string $targetPath, string $type): void
    {
        $oldPath = self::normalize($oldPath);
        $targetPath = self::normalize($targetPath);
        if ($oldPath === $targetPath || mb_strlen($oldPath, 'UTF-8') > 3072 || mb_strlen($targetPath, 'UTF-8') > 3072) return;
        if (self::isProtectedRoute($oldPath) || self::isProtectedRoute($targetPath)) return;
        DB::execute('UPDATE permalink_redirects SET target_path = ? WHERE target_path = ?', [$targetPath, $oldPath]);
        DB::execute('DELETE FROM permalink_redirects WHERE old_path_hash = ? AND old_path = ?', [self::pathHash($targetPath), $targetPath]);
        DB::execute(
            'INSERT INTO permalink_redirects (old_path, old_path_hash, target_path, content_type, created_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE target_path = VALUES(target_path), content_type = VALUES(content_type), created_at = VALUES(created_at)',
            [$oldPath, self::pathHash($oldPath), $targetPath, $type]
        );
    }

    public static function recordEntityChange(string $type, array $oldEntity, array $newEntity): void
    {
        $oldPaths = Permalinks::paths($type, $oldEntity);
        $newPaths = Permalinks::paths($type, $newEntity);

        // A deleted entity has no URL to preserve. Keep a temporarily private
        // entity's existing history, though, so it becomes usable again when
        // the entity is republished.
        if (!empty($newEntity['deleted_at'])) {
            $paths = array_merge($oldPaths, $newPaths);
            self::forgetPaths($paths);
            self::forgetTargets($paths);
            return;
        }
        if (!self::isPubliclyAddressable($type, $newEntity)) {
            if (self::isPubliclyAddressable($type, $oldEntity)) {
                // Preserve prior public paths through the unpublished period.
                // targetFor() suppresses these redirects until the target is
                // public again. Do not add aliases for a never-published draft.
                self::recordPaths($type, $oldPaths, Permalinks::path($type, $newEntity), $newPaths, (int) ($newEntity['id'] ?? 0));
            } else {
                // A private edit may change the target of aliases left by an
                // earlier published version, but must not create new aliases.
                self::retargetTargets($oldPaths, Permalinks::path($type, $newEntity));
                self::forgetPaths($newPaths);
            }
            return;
        }
        if (!self::isPubliclyAddressable($type, $oldEntity)) {
            self::forgetPaths($newPaths);
            return;
        }

        self::recordPaths($type, $oldPaths, Permalinks::path($type, $newEntity), $newPaths, (int) ($newEntity['id'] ?? 0));
    }

    public static function recordPaths(string $type, array $oldPaths, string $target, array $newPaths = [], int $entityId = 0): void
    {
        $target = self::normalize($target);
        $normalizedNewPaths = array_values(array_unique(array_map([self::class, 'normalize'], $newPaths)));
        foreach (array_unique($newPaths) as $newPath) {
            $newPath = self::normalize($newPath);
            DB::execute('DELETE FROM permalink_redirects WHERE old_path_hash = ? AND old_path = ? AND content_type = ?', [self::pathHash($newPath), $newPath, $type]);
        }
        foreach (array_unique($oldPaths) as $oldPath) {
            $oldPath = self::normalize($oldPath);
            if ($oldPath === $target || in_array($oldPath, $normalizedNewPaths, true)) continue;
            if (self::isProtectedRoute($oldPath) || self::contentExistsAtPath($oldPath, $type, $entityId)) continue;
            self::record($oldPath, $target, $type);
        }
    }

    /** Remove aliases that have become valid route shapes for current content. */
    public static function reconcileSupportedPaths(string $type): void
    {
        if (!in_array($type, ['post', 'page', 'category', 'tag'], true)) {
            throw new \InvalidArgumentException('Unknown permalink content type');
        }
        $aliases = DB::fetchAll(
            'SELECT old_path, old_path_hash, target_path FROM permalink_redirects WHERE content_type = ?',
            [$type]
        );
        foreach ($aliases as $alias) {
            $path = self::normalize((string) $alias['old_path']);
            if (self::isProtectedRoute($path) || self::contentExistsAtPath($path)) {
                DB::execute(
                    'DELETE FROM permalink_redirects WHERE old_path_hash = ? AND old_path = ? AND content_type = ?',
                    [$alias['old_path_hash'], $alias['old_path'], $type]
                );
                continue;
            }

            $target = self::publicEntityForPath($type, self::normalize((string) $alias['target_path']));
            if ($target === null) {
                DB::execute(
                    'DELETE FROM permalink_redirects WHERE old_path_hash = ? AND old_path = ? AND content_type = ?',
                    [$alias['old_path_hash'], $alias['old_path'], $type]
                );
                continue;
            }
            $canonicalTarget = self::normalize(Permalinks::path($type, $target));
            if ($canonicalTarget !== self::normalize((string) $alias['target_path'])) {
                DB::execute(
                    'UPDATE permalink_redirects SET target_path = ? WHERE old_path_hash = ? AND old_path = ? AND content_type = ?',
                    [$canonicalTarget, $alias['old_path_hash'], $alias['old_path'], $type]
                );
            }
        }
    }

    private static function isProtectedRoute(string $path): bool
    {
        return \Pafish\Services\ExtensionRoutes::isReservedRuntimePath($path)
            || \Pafish\Services\ExtensionRoutes::claimsPublicPath($path);
    }

    /** Keep a redirect from shadowing any existing content route, including drafts. */
    private static function contentExistsAtPath(string $path, ?string $ignoreType = null, int $ignoreId = 0): bool
    {
        $post = Permalinks::postFromPath($path);
        if ($post !== null && DB::value(
            'SELECT id FROM posts WHERE slug = ? AND deleted_at IS NULL'
                . ($ignoreType === 'post' && $ignoreId > 0 ? ' AND id <> ?' : '') . ' LIMIT 1',
            $ignoreType === 'post' && $ignoreId > 0 ? [$post['slug'], $ignoreId] : [$post['slug']]
        ) !== null) {
            return true;
        }
        foreach (['page', 'category', 'tag'] as $type) {
            $parsed = Permalinks::entityFromPath($type, $path);
            if ($parsed === null) continue;
            $table = ['page' => 'pages', 'category' => 'categories', 'tag' => 'tags'][$type];
            $sql = $type === 'page'
                ? 'SELECT id FROM pages WHERE slug = ? AND deleted_at IS NULL'
                : "SELECT id FROM {$table} WHERE slug = ?";
            $params = [$parsed['slug']];
            if ($ignoreType === $type && $ignoreId > 0) {
                $sql .= ' AND id <> ?';
                $params[] = $ignoreId;
            }
            if (DB::value($sql . ' LIMIT 1', $params) !== null) return true;
        }
        return false;
    }

    private static function isPubliclyAddressable(string $type, array $entity): bool
    {
        if ($type === 'post' || $type === 'page') {
            if (($entity['status'] ?? null) !== 'PUBLISHED' || !empty($entity['deleted_at'])) return false;
            $publishedAt = $entity['published_at'] ?? null;
            return $publishedAt === null || $publishedAt === '' || (string) $publishedAt <= date('Y-m-d H:i:s');
        }
        return in_array($type, ['category', 'tag'], true);
    }

    private static function publicEntityForPath(string $type, string $path): ?array
    {
        if ($type === 'post') {
            $parsed = Permalinks::postFromPath($path);
            if ($parsed === null) return null;
            return DB::fetchOne(
                "SELECT id, slug, status, published_at, created_at, deleted_at FROM posts
                 WHERE slug = ? AND status = 'PUBLISHED' AND deleted_at IS NULL
                   AND (published_at IS NULL OR published_at <= NOW()) LIMIT 1",
                [$parsed['slug']]
            );
        }

        $parsed = Permalinks::entityFromPath($type, $path);
        if ($parsed === null) return null;
        $table = match ($type) {
            'page' => 'pages',
            'category' => 'categories',
            'tag' => 'tags',
        };
        if ($type === 'page') {
            return DB::fetchOne(
                "SELECT id, slug, status, published_at, deleted_at FROM pages
                 WHERE slug = ? AND status = 'PUBLISHED' AND deleted_at IS NULL
                   AND (published_at IS NULL OR published_at <= NOW()) LIMIT 1",
                [$parsed['slug']]
            );
        }
        if ($type === 'category') {
            return DB::fetchOne("SELECT id, slug, parent_id FROM {$table} WHERE slug = ? LIMIT 1", [$parsed['slug']]);
        }
        return DB::fetchOne("SELECT id, slug FROM {$table} WHERE slug = ? LIMIT 1", [$parsed['slug']]);
    }

    /** Let a newly-created entity claim a path that was previously a redirect source. */
    public static function forgetPaths(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            $path = self::normalize((string) $path);
            DB::execute('DELETE FROM permalink_redirects WHERE old_path_hash = ? AND old_path = ?', [self::pathHash($path), $path]);
        }
    }

    /** Remove stale aliases that still lead to a newly private/deleted URL. */
    public static function forgetTargets(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            $path = self::normalize((string) $path);
            DB::execute('DELETE FROM permalink_redirects WHERE target_path = ?', [$path]);
        }
    }

    /** Move existing redirect targets without creating aliases for private content. */
    private static function retargetTargets(array $oldPaths, string $target): void
    {
        $target = self::normalize($target);
        foreach (array_unique($oldPaths) as $oldPath) {
            $oldPath = self::normalize((string) $oldPath);
            if ($oldPath !== $target) {
                DB::execute('UPDATE permalink_redirects SET target_path = ? WHERE target_path = ?', [$target, $oldPath]);
            }
        }
    }

    public static function recordSlugChange(string $type, array $entity, string $oldSlug, string $newSlug): void
    {
        if ($oldSlug === $newSlug || $oldSlug === '') return;
        $oldEntity = array_replace($entity, ['slug' => $oldSlug]);
        self::recordEntityChange($type, $oldEntity, array_replace($entity, ['slug' => $newSlug]));
    }

    public static function targetFor(string $path): ?string
    {
        $path = self::normalize($path);
        $target = DB::value('SELECT target_path FROM permalink_redirects WHERE old_path_hash = ? AND old_path = ? LIMIT 1', [self::pathHash($path), $path]);
        if (!is_string($target) || $target === '' || $target === $path) return null;
        if (self::isProtectedRoute($path) || self::contentExistsAtPath($path)) return null;
        if (self::isProtectedRoute(self::normalize($target))) return null;
        $targetState = self::isPublicContentAtPath(self::normalize($target));
        if ($targetState === false) return null;
        return $target;
    }

    /** Historical aliases are inactive while a known destination is private. */
    private static function isPublicContentAtPath(string $path): ?bool
    {
        $post = Permalinks::postFromPath($path);
        if ($post !== null) {
            $row = DB::fetchOne(
                'SELECT status, published_at, deleted_at FROM posts WHERE slug = ? LIMIT 1',
                [$post['slug']]
            );
            if ($row === null) return null;
            return ($row['status'] ?? null) === 'PUBLISHED'
                && empty($row['deleted_at'])
                && (($row['published_at'] ?? null) === null || (string) $row['published_at'] <= date('Y-m-d H:i:s'));
        }
        $page = Permalinks::entityFromPath('page', $path);
        if ($page !== null) {
            $row = DB::fetchOne('SELECT status, published_at, deleted_at FROM pages WHERE slug = ? LIMIT 1', [$page['slug']]);
            if ($row === null) return null;
            return ($row['status'] ?? null) === 'PUBLISHED'
                && empty($row['deleted_at'])
                && (($row['published_at'] ?? null) === null || (string) $row['published_at'] <= date('Y-m-d H:i:s'));
        }
        foreach (['category', 'tag'] as $type) {
            $parsed = Permalinks::entityFromPath($type, $path);
            if ($parsed === null) continue;
            $table = $type === 'category' ? 'categories' : 'tags';
            if (DB::value("SELECT id FROM {$table} WHERE slug = ? LIMIT 1", [$parsed['slug']]) !== null) return true;
            return null;
        }
        return null;
    }

    private static function pathHash(string $path): string
    {
        return hash('sha256', $path, true);
    }

    public static function queryWithoutRouteParam(string $query): string
    {
        $parts = array_filter(explode('&', $query), static function (string $part): bool {
            $key = explode('=', $part, 2)[0];
            return rawurldecode(str_replace('+', ' ', $key)) !== 'p';
        });
        return implode('&', $parts);
    }

    public static function normalize(string $path): string
    {
        $path = rawurldecode(trim($path));
        $segments = array_map(static fn (string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', trim($path, '/')));
        $normalized = '/' . implode('/', $segments);
        return $normalized === '' ? '/' : $normalized;
    }
}
