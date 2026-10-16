<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Config;
use Pafish\Core\DB;

/** One reference per source/field/upload; drafts and recycled content remain protected. */
final class MediaReferences
{
    private const SOURCES = [
        'post' => ['posts', ['content', 'cover_url', 'custom_fields']],
        'page' => ['pages', ['content']],
        'micro' => ['micro_statuses', ['content', 'media_json']],
        'user' => ['users', ['avatar_url']],
        'link' => ['links', ['url', 'description', 'icon_url']],
        'category' => ['categories', ['cover_url']],
        'nav' => ['nav_items', ['url', 'label']],
        'widget' => ['widgets', ['content', 'title']],
        'setting' => ['settings', ['value']],
        'extension' => ['extension_storage', ['value']],
        'entity_meta' => ['entity_meta', ['meta_value']],
    ];
    private const STATE = "extension_type = 'system' AND extension_name = 'media_references' AND data_key = 'state'";

    /** Take the shared rebuild gate BEFORE changing any source/upload. */
    public static function transaction(callable $write): mixed
    {
        return DB::transaction(static function () use ($write) {
            if (DB::value('SELECT value FROM extension_storage WHERE ' . self::STATE . ' LOCK IN SHARE MODE') === null) {
                throw new \RuntimeException('媒体引用结构尚未初始化，请完成数据库迁移');
            }
            return $write();
        });
    }

    public static function ready(): bool
    {
        return DB::value('SELECT value FROM extension_storage WHERE ' . self::STATE) === 'ready';
    }

    /** Importing an arbitrary URL can reveal references written before it was catalogued. */
    public static function catalogTransaction(callable $write): mixed
    {
        return DB::transaction(static function () use ($write) {
            if (DB::value('SELECT value FROM extension_storage WHERE ' . self::STATE . ' FOR UPDATE') === null) {
                throw new \RuntimeException('媒体引用结构尚未初始化');
            }
            $result = $write();
            DB::execute("UPDATE extension_storage SET value = 'pending' WHERE " . self::STATE);
            return $result;
        });
    }

    public static function extensionKey(string $kind, string $name, string $key): string
    {
        return json_encode([$kind, $name, $key], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Lock and read the current persisted source, not a stale caller snapshot. */
    public static function sync(string $type, string|int $key): void
    {
        self::transaction(static function () use ($type, $key): void {
            [$table, $fields] = self::source($type);
            [$where, $params] = self::address($type, (string) $key);
            $row = DB::fetchOne('SELECT ' . implode(', ', $fields) . ' FROM ' . $table . ' WHERE ' . $where . ' FOR UPDATE', $params);
            self::replace($type, (string) $key, $row ?? []);
        });
    }

    /** Must share the caller's permanent deletion transaction. */
    public static function forget(string $type, string|int $key): void
    {
        self::source($type);
        self::address($type, (string) $key);
        self::transaction(static fn () => self::replace($type, (string) $key, []));
    }

    public static function register(int $id, string $url): void
    {
        self::transaction(static function () use ($id, $url): void {
            $normalized = self::normalizeUrl($url);
            DB::execute('UPDATE uploads SET url_hash = ? WHERE id = ?',
                [$normalized === null ? null : hash('sha256', $normalized, true), $id]);
        });
    }

    /** Check/delete under the upload lock; physical I/O follows the commit. */
    public static function deleteUpload(int $id, ?int $ownerId, bool $force = false): array
    {
        return self::transaction(static function () use ($id, $ownerId, $force): array {
            if (DB::value('SELECT value FROM extension_storage WHERE ' . self::STATE . ' LOCK IN SHARE MODE') !== 'ready') {
                throw new \RuntimeException('请先重建媒体引用，再删除媒体', 409);
            }
            $row = DB::fetchOne('SELECT * FROM uploads WHERE id = ?' . ($ownerId === null ? '' : ' AND uploader_id = ?') . ' FOR UPDATE',
                $ownerId === null ? [$id] : [$id, $ownerId]);
            if ($row === null) throw new \RuntimeException('媒体不存在或已删除', 404);
            $count = count(DB::fetchAll('SELECT upload_id FROM media_references WHERE upload_id = ? FOR UPDATE', [$id]));
            if ($count > 0 && !$force) throw new \RuntimeException('媒体仍被内容引用，请确认后强制删除', 409);
            // Duplicate catalog entries must not delete the file behind a surviving entry.
            $shared = $row['url_hash'] !== null && DB::value('SELECT id FROM uploads WHERE url_hash = ? AND id <> ? LIMIT 1', [$row['url_hash'], $id]) !== null;
            DB::execute('DELETE FROM uploads WHERE id = ?', [$id]);
            return $row + ['keep_file' => $shared];
        });
    }

    /** Explicit maintenance only; atomic rebuild blocks participating writers. */
    public static function rebuild(): array
    {
        if (DB::pdo()->inTransaction()) throw new \LogicException('引用重建不能在业务事务内运行');
        return DB::transaction(static function (): array {
            if (DB::value('SELECT value FROM extension_storage WHERE ' . self::STATE . ' FOR UPDATE') === null) {
                throw new \RuntimeException('媒体引用结构尚未初始化');
            }
            DB::execute('DELETE FROM media_references');
            $last = 0;
            do {
                $uploads = DB::fetchAll('SELECT id, url FROM uploads WHERE id > ? ORDER BY id LIMIT 200', [$last]);
                foreach ($uploads as $upload) {
                    $last = (int) $upload['id'];
                    self::register($last, $upload['url']);
                }
            } while (count($uploads) === 200);
            $sources = 0;
            foreach (self::SOURCES as $type => [$table, $fields]) {
                $offset = 0;
                do {
                    $columns = $type === 'setting' ? 'key' : ($type === 'extension' ? 'extension_type, extension_name, data_key' : 'id');
                    // settings.key needs quoting (KEY is reserved).
                    $columnsSql = $type === 'setting' ? '`key`' : $columns;
                    $where = match ($type) {
                        'extension' => " WHERE extension_type IN ('plugin', 'theme')",
                        'entity_meta' => " WHERE scope <> 'core:custom_fields'",
                        default => '',
                    };
                    $rows = DB::fetchAll('SELECT ' . $columnsSql . ', ' . implode(', ', $fields) . ' FROM ' . $table . $where
                        . ' ORDER BY ' . $columnsSql . ' LIMIT 200 OFFSET ' . $offset);
                    foreach ($rows as $row) {
                        $key = $type === 'setting' ? $row['key'] : ($type === 'extension'
                            ? self::extensionKey($row['extension_type'], $row['extension_name'], $row['data_key']) : (string) $row['id']);
                        self::replace($type, $key, array_intersect_key($row, array_flip($fields)), false);
                        $sources++;
                    }
                    $offset += count($rows);
                } while (count($rows) === 200);
            }
            DB::execute('UPDATE uploads u LEFT JOIN (SELECT upload_id, COUNT(*) AS total FROM media_references GROUP BY upload_id) r ON r.upload_id = u.id
                SET u.usage_count = COALESCE(r.total, 0),
                u.last_used_at = CASE WHEN r.total > 0 THEN COALESCE(u.last_used_at, NOW()) ELSE NULL END');
            DB::execute("UPDATE extension_storage SET value = 'ready' WHERE " . self::STATE);
            return ['sources' => $sources, 'references' => (int) DB::value('SELECT COUNT(*) FROM media_references')];
        });
    }

    /** Local upload paths are canonical; remote URLs retain their query strings. */
    public static function normalizeUrl(string $url): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url)) return null;
        $site = parse_url((string) Config::get('site_url', '')) ?: [];
        $parts = parse_url($url);
        if ($parts === false) return null;
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) return null;
        $local = $host === '' || ($host === strtolower((string) ($site['host'] ?? ''))
            && (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)) === (int) ($site['port'] ?? (($site['scheme'] ?? '') === 'https' ? 443 : 80)));
        $path = (string) ($parts['path'] ?? '');
        if ($local) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            if (str_ends_with($path, 'index.php') && is_string($query['p'] ?? null)) $path = '/' . ltrim($query['p'], '/');
            $base = rtrim((string) ($site['path'] ?? ''), '/');
            if ($base !== '' && str_starts_with($path, $base . '/')) $path = substr($path, strlen($base));
            if (str_starts_with($path, './')) $path = substr($path, 2);
            $path = '/' . ltrim($path, '/');
            // Percent-encoded local filenames and their decoded form are one resource.
            $path = rawurldecode($path);
            if (str_starts_with($path, '/public/uploads/')) $path = substr($path, 7);
            if (str_starts_with($path, '/uploads/') && !str_contains($path, '..') && !str_contains($path, '\\')
                && !preg_match('/[\x00-\x1f\x7f]/', $path)) return 'local:' . $path;
            if ($host === '') return null;
        }
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) return null;
        return ($scheme === '' ? 'https' : $scheme) . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private static function urls(string $text): array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            $out = [];
            array_walk_recursive($decoded, static function ($value) use (&$out) {
                if (is_string($value)) array_push($out, ...self::urls($value));
            });
            return array_values(array_unique($out));
        }
        if (is_string($decoded)) $text = $decoded;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Exact values/quoted attributes preserve legal commas and balanced parentheses.
        $candidates = [$text];
        preg_match_all('~(?:src|href|poster)\s*=\s*(["\x27])(.*?)\1~isu', $text, $attributes);
        array_push($candidates, ...$attributes[2]);
        preg_match_all('~(?:https?://|//|/|(?:\./)?(?:public/)?uploads/|(?:\./)?index\.php\?p=)[^\s<>"\x27\x60\[\]{},]+~iu', $text, $matches);
        $out = [];
        foreach ($candidates as $candidate) {
            $normalized = self::normalizeUrl($candidate);
            if ($normalized !== null) $out[] = hash('sha256', $normalized);
        }
        foreach ($matches[0] as $url) {
            $url = rtrim($url, '.,;!');
            while (str_ends_with($url, ')') && substr_count($url, ')') > substr_count($url, '(')) $url = substr($url, 0, -1);
            $normalized = self::normalizeUrl($url);
            if ($normalized !== null) $out[] = hash('sha256', $normalized);
        }
        return array_values(array_unique($out));
    }

    private static function replace(string $type, string $key, array $fields, bool $counters = true): void
    {
        if (mb_strlen($key) > 512) throw new \InvalidArgumentException('媒体引用来源键过长');
        $sourceHash = hash('sha256', $key, true);
        $roles = [];
        foreach ($fields as $role => $value) {
            foreach (self::urls((string) ($value ?? '')) as $hash) $roles[$hash][$role] = true;
        }
        $matched = [];
        foreach (array_chunk(array_keys($roles), 200) as $hashes) {
            $rows = DB::fetchAll('SELECT id, url_hash FROM uploads WHERE url_hash IN (' . implode(',', array_fill(0, count($hashes), '?')) . ') ORDER BY id FOR UPDATE', array_map('hex2bin', $hashes));
            foreach ($rows as $row) $matched[(int) $row['id']] = $roles[bin2hex($row['url_hash'])];
        }
        // Serialize writers of the same resource BEFORE taking relation range locks.
        $targetIds = array_keys($matched);
        sort($targetIds, SORT_NUMERIC);
        foreach ($targetIds as $id) {
            if (DB::value('SELECT id FROM uploads WHERE id = ? FOR UPDATE', [$id]) === null) {
                throw new \RuntimeException('引用的媒体刚刚被删除，请重新选择媒体');
            }
        }
        // A current read is essential when the caller already established an RR snapshot.
        $old = DB::fetchAll('SELECT upload_id FROM media_references WHERE source_type = ? AND source_hash = ? FOR UPDATE', [$type, $sourceHash]);
        $ids = array_unique(array_merge(array_keys($matched), array_column($old, 'upload_id')));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            if (DB::value('SELECT id FROM uploads WHERE id = ? FOR UPDATE', [$id]) === null) {
                if (isset($matched[(int) $id])) throw new \RuntimeException('引用的媒体刚刚被删除，请重新选择媒体');
                continue;
            }
            DB::execute('DELETE FROM media_references WHERE source_type = ? AND source_hash = ? AND upload_id = ?', [$type, $sourceHash, $id]);
            foreach (array_keys($matched[(int) $id] ?? []) as $role) {
                DB::execute('INSERT INTO media_references (source_type, source_hash, source_key, upload_id, role) VALUES (?, ?, ?, ?, ?)', [$type, $sourceHash, $key, $id, $role]);
            }
            if ($counters) DB::execute('UPDATE uploads u LEFT JOIN (SELECT upload_id, COUNT(*) AS total FROM media_references WHERE upload_id = ? GROUP BY upload_id) r ON r.upload_id = u.id
                SET u.usage_count = COALESCE(r.total, 0), u.last_used_at = CASE WHEN r.total > 0 THEN NOW() ELSE NULL END WHERE u.id = ?', [$id, $id]);
        }
    }

    private static function source(string $type): array
    {
        return self::SOURCES[$type] ?? throw new \InvalidArgumentException('未知媒体引用来源');
    }

    private static function address(string $type, string $key): array
    {
        if ($type === 'setting') return ['`key`' . ' = ?', [$key]];
        if ($type === 'extension') {
            $parts = json_decode($key, true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($parts) || count($parts) !== 3 || !in_array($parts[0] ?? null, ['plugin', 'theme'], true)) throw new \InvalidArgumentException('无效扩展引用地址');
            return ['extension_type = ? AND extension_name = ? AND data_key = ?', $parts];
        }
        if (!ctype_digit($key) || (int) $key < 1) throw new \InvalidArgumentException('无效媒体引用来源 ID');
        return ['id = ?', [$key]];
    }
}
