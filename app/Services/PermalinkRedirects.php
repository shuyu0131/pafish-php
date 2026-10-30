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
        self::recordPaths($type, Permalinks::paths($type, $oldEntity), Permalinks::path($type, $newEntity), Permalinks::paths($type, $newEntity));
    }

    public static function recordPaths(string $type, array $oldPaths, string $target, array $newPaths = []): void
    {
        $target = self::normalize($target);
        foreach (array_unique($newPaths) as $newPath) {
            $newPath = self::normalize($newPath);
            DB::execute('DELETE FROM permalink_redirects WHERE old_path_hash = ? AND old_path = ? AND content_type = ?', [self::pathHash($newPath), $newPath, $type]);
        }
        foreach (array_unique($oldPaths) as $oldPath) {
            $oldPath = self::normalize($oldPath);
            if ($oldPath !== $target) self::record($oldPath, $target, $type);
        }
    }

    /** Let a newly-created entity claim a path that was previously a redirect source. */
    public static function forgetPaths(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            $path = self::normalize((string) $path);
            DB::execute('DELETE FROM permalink_redirects WHERE old_path_hash = ? AND old_path = ?', [self::pathHash($path), $path]);
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
        return is_string($target) && $target !== '' && $target !== $path ? $target : null;
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
