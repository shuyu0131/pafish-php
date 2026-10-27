<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/** Shared structured records for extensions that do not need their own relational tables. */
final class ExtensionRecords
{
    private const MAX_PAYLOAD_BYTES = 262_144;
    private const MAX_PAGE_SIZE = 50;

    public static function get(string $kind, string $name, string $model, string $key): ?array
    {
        self::validateAddress($kind, $name, $model, $key);
        $row = DB::fetchOne('SELECT id, record_key, payload, revision, created_at, updated_at
            FROM extension_records WHERE extension_type=? AND extension_name=? AND model_name=? AND record_key=?',
            [$kind, $name, $model, $key]);
        return $row === null ? null : self::decode($row);
    }

    /** Insert or replace one record; expectedRevision enables optimistic concurrency checks. */
    public static function put(string $kind, string $name, string $model, string $key, array $data, ?int $expectedRevision = null): array
    {
        self::validateAddress($kind, $name, $model, $key);
        if ($expectedRevision !== null && $expectedRevision < 0) throw new \InvalidArgumentException('记录版本无效');
        if ($data !== [] && array_is_list($data)) throw new \InvalidArgumentException('扩展记录顶层必须是 JSON 对象');
        try {
            $payload = json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('扩展记录内容必须是有效的 JSON 对象', 0, $e);
        }
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) throw new \InvalidArgumentException('单条扩展记录不能超过 256 KiB');

        return MediaReferences::transaction(static function () use ($kind, $name, $model, $key, $payload, $expectedRevision): array {
            $address = [$kind, $name, $model, $key];
            if ($expectedRevision === null) {
                DB::execute('INSERT INTO extension_records (extension_type, extension_name, model_name, record_key, payload, revision)
                    VALUES (?, ?, ?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE payload=VALUES(payload), revision=revision+1', [...$address, $payload]);
            } elseif ($expectedRevision === 0) {
                $inserted = DB::execute('INSERT INTO extension_records (extension_type, extension_name, model_name, record_key, payload, revision)
                    VALUES (?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE id=id', [...$address, $payload]);
                if ($inserted !== 1) throw new \DomainException('扩展记录已存在，请重新读取后再保存', 409);
            } else {
                $updated = DB::execute('UPDATE extension_records SET payload=?, revision=revision+1
                    WHERE extension_type=? AND extension_name=? AND model_name=? AND record_key=? AND revision=?', [$payload, ...$address, $expectedRevision]);
                if ($updated !== 1) throw new \DomainException('扩展记录已被其他请求修改，请重新读取后再保存', 409);
            }

            MediaReferences::sync('extension_record', self::mediaKey($kind, $name, $model, $key));
            $row = DB::fetchOne('SELECT id, record_key, payload, revision, created_at, updated_at
                FROM extension_records WHERE extension_type=? AND extension_name=? AND model_name=? AND record_key=?', $address);
            if ($row === null) throw new \RuntimeException('扩展记录保存后无法读取');
            return self::decode($row);
        });
    }

    /** List by stable insertion cursor; dynamic JSON-field filtering is intentionally unsupported. */
    public static function list(string $kind, string $name, string $model, int $afterId = 0, int $limit = 20): array
    {
        self::validateAddress($kind, $name, $model, 'cursor');
        if ($afterId < 0 || $limit < 1 || $limit > self::MAX_PAGE_SIZE) throw new \InvalidArgumentException('扩展记录分页参数无效');
        $rows = DB::fetchAll('SELECT id, record_key, payload, revision, created_at, updated_at
            FROM extension_records WHERE extension_type=? AND extension_name=? AND model_name=? AND id>?
            ORDER BY id ASC LIMIT ' . ($limit + 1), [$kind, $name, $model, $afterId]);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $records = array_map(self::decode(...), $rows);
        return ['records' => $records, 'nextCursor' => $hasMore && $rows !== [] ? (int) $rows[array_key_last($rows)]['id'] : null];
    }

    public static function delete(string $kind, string $name, string $model, string $key, ?int $expectedRevision = null): bool
    {
        self::validateAddress($kind, $name, $model, $key);
        if ($expectedRevision !== null && $expectedRevision < 1) throw new \InvalidArgumentException('记录版本无效');
        return MediaReferences::transaction(static function () use ($kind, $name, $model, $key, $expectedRevision): bool {
            $address = [$kind, $name, $model, $key];
            $row = DB::fetchOne('SELECT id, revision FROM extension_records
                WHERE extension_type=? AND extension_name=? AND model_name=? AND record_key=? FOR UPDATE', $address);
            if ($row === null) return false;
            if ($expectedRevision !== null && (int) $row['revision'] !== $expectedRevision) {
                throw new \DomainException('扩展记录已被其他请求修改，请重新读取后再删除', 409);
            }
            DB::execute('DELETE FROM extension_records WHERE id=?', [$row['id']]);
            MediaReferences::forget('extension_record', self::mediaKey($kind, $name, $model, $key));
            return true;
        });
    }

    /** Called only after an administrator explicitly chooses to delete extension data. */
    public static function deleteOwner(string $kind, string $name): void
    {
        self::validateOwner($kind, $name);
        MediaReferences::transaction(static function () use ($kind, $name): void {
            $lastId = 0;
            do {
                $rows = DB::fetchAll('SELECT id, model_name, record_key FROM extension_records
                    WHERE extension_type=? AND extension_name=? AND id>? ORDER BY id LIMIT 200 FOR UPDATE', [$kind, $name, $lastId]);
                foreach ($rows as $row) {
                    $lastId = (int) $row['id'];
                    MediaReferences::forget('extension_record', self::mediaKey($kind, $name, (string) $row['model_name'], (string) $row['record_key']));
                    DB::execute('DELETE FROM extension_records WHERE id=?', [$lastId]);
                }
            } while (count($rows) === 200);
        });
    }

    private static function decode(array $row): array
    {
        $object = json_decode((string) $row['payload'], false, 512, JSON_THROW_ON_ERROR);
        if (!$object instanceof \stdClass) throw new \UnexpectedValueException('扩展记录内容不是 JSON 对象');
        $data = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \UnexpectedValueException('扩展记录内容不是 JSON 对象');
        return [
            'id' => (int) $row['id'],
            'key' => (string) $row['record_key'],
            'data' => $data,
            'revision' => (int) $row['revision'],
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    private static function mediaKey(string $kind, string $name, string $model, string $key): string
    {
        return json_encode([$kind, $name, $model, $key], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function validateAddress(string $kind, string $name, string $model, string $key): void
    {
        self::validateOwner($kind, $name);
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $model) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('扩展记录地址无效');
        }
    }

    private static function validateOwner(string $kind, string $name): void
    {
        if (!in_array($kind, ['plugin', 'theme'], true) || preg_match('/^[a-z0-9_-]{1,50}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('扩展记录归属无效');
        }
    }
}
