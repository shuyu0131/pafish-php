<?php

declare(strict_types=1);

namespace Pafish\Core;

/**
 * Small file-backed fixed-window limiter for authentication endpoints.
 * The lock covers read, decision and write so concurrent requests cannot
 * bypass the counter.
 */
final class RateLimiter
{
    public static function allow(string $bucket, string $key, int $limit, int $window): bool
    {
        $limit = max(1, $limit);
        $window = max(1, $window);
        $root = defined('PAFISH_ROOT') ? PAFISH_ROOT : dirname(__DIR__, 2);
        $dir = $root . '/runtime/rate';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }

        $path = $dir . '/rl_' . hash('sha256', $bucket . "\0" . $key) . '.json';
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return false;
        }

        try {
            $now = time();
            $raw = is_file($path) ? @file_get_contents($path) : false;
            $state = is_string($raw) ? json_decode($raw, true) : null;
            $started = is_array($state) ? (int) ($state['started'] ?? 0) : 0;
            $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
            if ($started <= 0 || $now - $started >= $window) {
                $started = $now;
                $count = 0;
            }
            if ($count >= $limit) {
                return false;
            }
            $count++;
            $written = @file_put_contents($path, json_encode([
                'started' => $started,
                'count' => $count,
                'expires' => $started + $window,
            ], JSON_UNESCAPED_SLASHES), LOCK_EX);
            if ($written !== false && mt_rand(1, 100) === 1) {
                self::cleanup($dir, $now - 86400);
            }
            return $written !== false;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function clear(string $bucket, string $key): void
    {
        $root = defined('PAFISH_ROOT') ? PAFISH_ROOT : dirname(__DIR__, 2);
        $path = $root . '/runtime/rate/rl_' . hash('sha256', $bucket . "\0" . $key) . '.json';
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) return;
        try { if (flock($lock, LOCK_EX)) @unlink($path); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function cleanup(string $dir, int $cutoff): void
    {
        $files = glob($dir . '/rl_*.json');
        if (!is_array($files) || count($files) <= 2000) {
            return;
        }
        $removed = 0;
        foreach ($files as $file) {
            $lock = @fopen($file . '.lock', 'c');
            if ($lock === false) continue;
            try {
                if (!flock($lock, LOCK_EX | LOCK_NB)) continue;
                // Re-read only after acquiring the writer's lock; a request may have refreshed it.
                $raw = @file_get_contents($file);
                $state = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($state) && (int) ($state['started'] ?? 0) < $cutoff
                    && (int) ($state['expires'] ?? 0) <= time() && @unlink($file)) {
                    $removed++;
                }
                // Keep the lock inode: deleting it can split concurrent writers across two locks.
            } finally { flock($lock, LOCK_UN); fclose($lock); }
            if ($removed >= 200) {
                break;
            }
        }
    }
}
