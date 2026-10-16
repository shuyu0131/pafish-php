<?php

declare(strict_types=1);

namespace Pafish\Core;

/** Small, dependency-free file cache for read-heavy site data. */
final class Cache
{
    private const DIR = 'runtime/cache';
    private const FILL_WAIT_US = 250000;
    private const MAX_MARKERS = 256;
    private static array $stats = [];

    private static function path(string $key): string
    {
        if (!preg_match('/^[a-zA-Z0-9:_\-.\/]+$/D', $key) || str_contains($key, '..')) {
            throw new \InvalidArgumentException('Invalid cache key');
        }
        $root = PAFISH_ROOT . '/' . self::DIR;
        if (!is_dir($root)) {
            @mkdir($root, 0775, true);
        }
        return $root . '/' . sha1($key) . '.php';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        // 事务可能已有写入或持有旧快照；不读共享缓存，也不发布未提交快照。
        if (DB::inTransaction()) { self::count('transaction_bypasses'); return $default; }
        $file = self::path($key);
        $lock = self::lock(LOCK_SH);
        if ($lock === false) { self::count('misses'); return $default; }
        try {
            $state = self::state($lock);
            clearstatcache(true, $file);
            $payload = $state === null ? null : self::payload($file);
            if ($state === null || !is_array($payload) || ($payload['key'] ?? null) !== $key
                || !isset($payload['expires']) || !array_key_exists('value', $payload)
                || !self::current($key, $payload, $state)
                || ((int)$payload['expires'] > 0 && (int)$payload['expires'] <= time())) {
                // Do not unlink under a shared reader lock; a later fill replaces expired data.
                self::count('misses');
                return $default;
            }
            self::count('hits');
            return $payload['value'];
        } finally { self::unlock($lock); }
    }

    /**
     * Read-through cache for shared data. Invalidation never waits for a database query.
     * A generation change during loading discards the fill; the in-flight caller may
     * still receive its earlier snapshot, but later callers cannot inherit it.
     */
    public static function remember(string $key, callable $load, int $ttl = 300, bool $cacheEmpty = true): mixed
    {
        self::path($key); // Validate even when a transaction bypasses shared storage.
        if (DB::inTransaction()) {
            self::count('transaction_bypasses');
            return self::load($load, true);
        }
        $missing = new \stdClass();
        $value = self::get($key, $missing);
        if ($value !== $missing) return $value;

        // At most 256 stable stripes, rather than a permanent file for every dynamic key.
        // Colliding keys may wait briefly, then fall back without publishing.
        $fill = @fopen(PAFISH_ROOT . '/' . self::DIR . '/.fill-' . substr(sha1($key), 0, 2) . '.lock', 'c+b');
        if ($fill === false) { self::count('errors'); return self::load($load, true); }
        $started = hrtime(true);
        $acquired = false;
        try {
            do {
                $acquired = flock($fill, LOCK_EX | LOCK_NB);
                if ($acquired) break;
                if (hrtime(true) - $started >= self::FILL_WAIT_US * 1000) break;
                usleep(5000);
            } while (true);
            $waited = (hrtime(true) - $started) / 1000000;
            self::count('lock_wait_ms', $waited);
            if ($waited >= 1) self::count('lock_waits');
            if (!$acquired) {
                self::count('lock_timeouts');
                // Bounded wait: an unavailable cache must not indefinitely block a page.
                return self::load($load, true);
            }
            $value = self::get($key, $missing);
            if ($value !== $missing) return $value;
            $lock = self::lock(LOCK_EX);
            if ($lock === false) return self::load($load, true);
            try {
                $snapshot = self::state($lock);
                if ($snapshot === null) {
                    $snapshot = self::freshState();
                    if (!self::storeState($lock, $snapshot)) $snapshot = null;
                }
            } finally { self::unlock($lock); }

            $value = self::load($load);
            if (!$cacheEmpty && $value === []) return $value;
            if ($snapshot === null || DB::inTransaction()) return $value;
            $lock = self::lock(LOCK_EX);
            if ($lock === false) return $value;
            try {
                $state = self::state($lock);
                if ($state !== null && $state['epoch'] === $snapshot['epoch'] && $state['revision'] === $snapshot['revision']) {
                    self::write($key, $value, $ttl, $state);
                }
                else self::count('rejected_fills');
            } finally { self::unlock($lock); }
            return $value;
        } finally {
            if ($acquired) flock($fill, LOCK_UN);
            fclose($fill);
        }
    }

    public static function set(string $key, mixed $value, int $ttl = 300): void
    {
        if (DB::inTransaction()) return;
        self::path($key);
        $lock = self::lock(LOCK_EX);
        if ($lock === false) return;
        try {
            // A direct write also wins over any earlier in-flight loader.
            $state = self::nextState($lock);
            if (!self::storeState($lock, $state)) return;
            if (!self::write($key, $value, $ttl, $state)) {
                // A failed explicit replacement must not leave its previous value readable.
                $state['keys'][$key] = $state['revision'];
                self::storeState($lock, self::boundState($state));
            }
        } finally { self::unlock($lock); }
    }

    /** Caller holds the shared cache's exclusive lock. */
    private static function write(string $key, mixed $value, int $ttl, array $state): bool
    {
        $file = self::path($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        $data = '<?php return ' . var_export([
            'epoch' => $state['epoch'],
            'revision' => $state['revision'],
            'key' => $key,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'value' => $value,
        ], true) . ';';
        if (@file_put_contents($tmp, $data, LOCK_EX) === strlen($data)) {
            // Windows readers/scanners can briefly retain the target. Keep the old file
            // intact and retry atomically; never unlink it to force a partial replacement.
            for ($attempt = 0; $attempt < 4; $attempt++) {
                if (@rename($tmp, $file)) {
                    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
                    self::count('writes');
                    return true;
                }
                if ($attempt < 3) usleep(5000);
            }
            @unlink($tmp);
            self::count('errors');
            self::warnOnce('缓存文件落盘失败（rename）：' . $file);
            return false;
        }
        @unlink($tmp);
        self::count('errors');
        self::warnOnce('缓存写入失败（磁盘满或目录不可写）：' . dirname($file));
        return false;
    }

    /** Request-local counters only; never persist SQL, keys, content or user data. */
    public static function stats(): array
    {
        return array_replace(array_fill_keys(['hits','misses','loads','load_ms','uncached_loads',
            'lock_waits','lock_wait_ms','lock_timeouts','rejected_fills','transaction_bypasses',
            'writes','errors','invalidations','invalidation_ms','scanned_files'], 0), self::$stats);
    }

    public static function resetStats(): void { self::$stats = []; }

    private static function count(string $name, int|float $amount = 1): void
    {
        self::$stats[$name] = (self::$stats[$name] ?? 0) + $amount;
    }

    private static function load(callable $load, bool $uncached = false): mixed
    {
        self::count('loads');
        if ($uncached) self::count('uncached_loads');
        $started = hrtime(true);
        try { return $load(); }
        finally { self::count('load_ms', (hrtime(true) - $started) / 1000000); }
    }

    /** Stable lock file also stores bounded invalidation state. Never delete it. */
    private static function lock(int $mode): mixed
    {
        self::path('lock');
        $lock = @fopen(PAFISH_ROOT . '/' . self::DIR . '/.cache.lock', 'c+b');
        if ($lock !== false && flock($lock, $mode)) return $lock;
        if (is_resource($lock)) fclose($lock);
        self::count('errors');
        self::warnOnce('缓存锁不可用');
        return false;
    }

    private static function unlock(mixed $lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    private static function state(mixed $lock): ?array
    {
        rewind($lock);
        $data = stream_get_contents($lock);
        $state = is_string($data) ? json_decode($data, true) : null;
        if (!is_array($state) || ($state['format'] ?? null) !== 2
            || !is_string($state['epoch'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $state['epoch']) !== 1
            || !is_int($state['revision'] ?? null) || $state['revision'] < 0
            || !is_array($state['prefixes'] ?? null) || !is_array($state['keys'] ?? null)
            || count($state['prefixes']) + count($state['keys']) > self::MAX_MARKERS) return null;
        foreach ([$state['prefixes'], $state['keys']] as $markers) {
            foreach ($markers as $revision) {
                if (!is_int($revision) || $revision < 0 || $revision > $state['revision']) return null;
            }
        }
        return $state;
    }

    private static function freshState(): array
    {
        return ['format' => 2, 'epoch' => bin2hex(random_bytes(16)), 'revision' => 0, 'prefixes' => [], 'keys' => []];
    }

    private static function nextState(mixed $lock): array
    {
        $state = self::state($lock) ?? self::freshState();
        if ($state['revision'] === PHP_INT_MAX) $state = self::freshState();
        $state['revision']++;
        return $state;
    }

    /** A rare overflow safely evicts all values instead of growing metadata forever. */
    private static function boundState(array $state): array
    {
        return count($state['prefixes']) + count($state['keys']) > self::MAX_MARKERS ? self::freshState() : $state;
    }

    private static function storeState(mixed $lock, array $state): bool
    {
        $value = json_encode($state, JSON_THROW_ON_ERROR);
        rewind($lock);
        if (ftruncate($lock, 0) && fwrite($lock, $value) === strlen($value) && fflush($lock)) return true;
        self::count('errors');
        self::warnOnce('缓存失效标记写入失败');
        return false;
    }

    private static function current(string $key, array $payload, array $state): bool
    {
        $revision = $payload['revision'] ?? null;
        if (($payload['epoch'] ?? null) !== $state['epoch'] || !is_int($revision)
            || $revision < 0 || $revision > $state['revision'] || $revision < ($state['keys'][$key] ?? 0)) return false;
        foreach ($state['prefixes'] as $prefix => $invalidatedAt) {
            if ($revision < $invalidatedAt && str_starts_with($key, (string) $prefix)) return false;
        }
        return true;
    }

    /**
     * 缓存故障每个请求只记一次。
     *
     * 缓存失败会让每次请求都回源查库、站点变慢，但此前是完全静默的
     * （`@file_put_contents` + 无日志）。若每个请求每条缓存都记，磁盘满时
     * 反而会把 error.log 冲爆，所以这里按请求去重。
     */
    private static function warnOnce(string $message): void
    {
        static $reported = [];
        $kind = str_contains($message, 'rename') ? 'rename' : 'write';
        if (isset($reported[$kind])) {
            return;
        }
        $reported[$kind] = true;
        \Pafish\Core\Logger::error('[pafish-cache] ' . $message);
    }

    public static function delete(string $key): void
    {
        if (DB::inTransaction()) {
            DB::afterCommit(static fn () => self::delete($key));
            return;
        }
        $file = self::path($key);
        $lock = self::lock(LOCK_EX);
        if ($lock === false) return;
        try {
            $state = self::nextState($lock);
            $state['keys'][$key] = $state['revision'];
            if (!self::storeState($lock, self::boundState($state))) return;
            @unlink($file);
            if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
            self::count('invalidations');
        } finally { self::unlock($lock); }
    }

    public static function clearPrefix(string $prefix): void
    {
        if (DB::inTransaction()) {
            DB::afterCommit(static fn () => self::clearPrefix($prefix));
            return;
        }
        // Invalid key bytes cannot match any valid cache key. Never interpret a prefix as a path or glob.
        if ($prefix !== '' && (preg_match('/^[a-zA-Z0-9:_\-.\/]+$/D', $prefix) !== 1 || str_contains($prefix, '..'))) return;
        $lock = self::lock(LOCK_EX);
        if ($lock === false) return;
        $started = hrtime(true);
        try {
            if ($prefix !== '') {
                $state = self::nextState($lock);
                foreach (array_keys($state['prefixes']) as $old) {
                    if (str_starts_with((string) $old, $prefix)) unset($state['prefixes'][$old]);
                }
                foreach (array_keys($state['keys']) as $old) {
                    if (str_starts_with((string) $old, $prefix)) unset($state['keys'][$old]);
                }
                $state['prefixes'][$prefix] = $state['revision'];
                if (!self::storeState($lock, self::boundState($state))) return;
                self::count('invalidations');
                return;
            }
            // Full administrative clear also reclaims old/expired files; ordinary prefix invalidation never scans.
            if (!self::storeState($lock, self::freshState())) return;
            $dir = PAFISH_ROOT . '/' . self::DIR;
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                self::count('scanned_files');
                @unlink($file);
                if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
            }
            self::count('invalidations');
        } finally {
            self::count('invalidation_ms', (hrtime(true) - $started) / 1000000);
            self::unlock($lock);
        }
    }

    public static function clear(): void
    {
        self::clearPrefix('');
    }

    private static function payload(string $file): mixed
    {
        if (!is_file($file)) return null;
        try { return @include $file; }
        catch (\Throwable) { self::count('errors'); return null; }
    }
}
