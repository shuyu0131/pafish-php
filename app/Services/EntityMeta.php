<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/** Namespaced metadata attached to existing core entities. */
final class EntityMeta
{
    private const SUBJECTS = [
        'post' => 'posts', 'page' => 'pages', 'user' => 'users', 'comment' => 'comments',
        'micro' => 'micro_statuses', 'category' => 'categories', 'tag' => 'tags',
    ];
    public const POST_FIELDS_SCOPE = 'core:custom_fields';

    public static function scope(string $ownerType, string $ownerName = ''): string
    {
        if ($ownerType === 'core' && $ownerName === '') return 'core';
        if (in_array($ownerType, ['plugin', 'theme'], true)
            && preg_match('/^[a-z0-9_-]{1,50}$/D', $ownerName) === 1) {
            return $ownerType . ':' . $ownerName;
        }
        throw new \InvalidArgumentException('元数据归属无效');
    }

    public static function get(string $subjectType, int $subjectId, string $scope, string $key, mixed $default = null): mixed
    {
        self::validate($subjectType, $subjectId, $scope, $key);
        $row = DB::fetchOne('SELECT meta_key, meta_value FROM entity_meta WHERE subject_type=? AND subject_id=? AND scope=? AND key_hash=? AND meta_key=? ORDER BY position,id LIMIT 1',
            [$subjectType, $subjectId, $scope, hash('sha256', $key, true), $key]);
        return $row === null ? $default : (string) $row['meta_value'];
    }

    /** @return list<array{key:string,value:string}> */
    public static function all(string $subjectType, int $subjectId, string $scope): array
    {
        self::validate($subjectType, $subjectId, $scope, '');
        return array_map(static fn (array $row): array => ['key' => (string) $row['meta_key'], 'value' => (string) $row['meta_value']],
            DB::fetchAll('SELECT meta_key,meta_value FROM entity_meta WHERE subject_type=? AND subject_id=? AND scope=? ORDER BY position,id', [$subjectType, $subjectId, $scope]));
    }

    /** Read the migrated custom-field rows, falling back to the retained legacy column during rollout. */
    public static function postCustomFields(int $postId, mixed $legacyValue = null): array
    {
        $fields = self::all('post', $postId, self::POST_FIELDS_SCOPE);
        if ($fields !== []) return $fields;
        $rows = is_string($legacyValue) ? json_decode($legacyValue, true) : null;
        if (!is_array($rows)) return [];
        $fields = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $key = mb_substr(trim((string) ($row['key'] ?? '')), 0, 50);
            $value = mb_substr(trim((string) ($row['value'] ?? '')), 0, 500);
            if ($key !== '' || $value !== '') $fields[] = ['key' => $key, 'value' => $value];
        }
        return $fields;
    }

    public static function set(string $subjectType, int $subjectId, string $scope, string $key, string $value): void
    {
        self::replaceKey($subjectType, $subjectId, $scope, $key, [$value]);
    }

    public static function delete(string $subjectType, int $subjectId, string $scope, string $key): void
    {
        self::validate($subjectType, $subjectId, $scope, $key);
        MediaReferences::transaction(static function () use ($subjectType, $subjectId, $scope, $key): void {
            self::lockSubject($subjectType, $subjectId);
            $rows = DB::fetchAll('SELECT id FROM entity_meta WHERE subject_type=? AND subject_id=? AND scope=? AND key_hash=? AND meta_key=? FOR UPDATE',
                [$subjectType, $subjectId, $scope, hash('sha256', $key, true), $key]);
            if ($rows === []) return;
            DB::execute('DELETE FROM entity_meta WHERE subject_type=? AND subject_id=? AND scope=? AND key_hash=? AND meta_key=?',
                [$subjectType, $subjectId, $scope, hash('sha256', $key, true), $key]);
            foreach ($rows as $row) MediaReferences::forget('entity_meta', (int) $row['id']);
        });
    }

    /** Read a value only when the extension declared this subject/key in its manifest. */
    public static function getForExtension(string $kind, string $name, string $subjectType, int $subjectId, string $key, mixed $default = null): mixed
    {
        $field = self::declaredField($kind, $name, $subjectType, $key);
        return self::get($subjectType, $subjectId, self::scope($kind, $name), $key, $default ?? $field['default']);
    }

    public static function setForExtension(string $kind, string $name, string $subjectType, int $subjectId, string $key, mixed $value): void
    {
        $field = self::declaredField($kind, $name, $subjectType, $key);
        self::set($subjectType, $subjectId, self::scope($kind, $name), $key, EntityMetaSchema::validateValue($field, $value));
    }

    public static function deleteForExtension(string $kind, string $name, string $subjectType, int $subjectId, string $key): void
    {
        self::declaredField($kind, $name, $subjectType, $key);
        self::delete($subjectType, $subjectId, self::scope($kind, $name), $key);
    }

    /** Active plugin and theme fields shown on the core editor form. */
    public static function editorFields(string $subjectType, int $subjectId): array
    {
        $out = [];
        foreach (self::activeOwners() as [$kind, $name, $title, $manifest]) {
            foreach ((array) ($manifest['meta'][$subjectType] ?? []) as $field) {
                if (!is_array($field)) continue;
                $out[] = $field + [
                    'scope' => self::scope($kind, $name),
                    'owner' => $title,
                    'value' => $subjectId > 0
                        ? self::get($subjectType, $subjectId, self::scope($kind, $name), (string) $field['key'], $field['default'] ?? '')
                        : (string) ($field['default'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /** Persist only submitted keys declared by currently active extensions; omitted keys are preserved. */
    public static function saveEditorFields(string $subjectType, int $subjectId, mixed $submitted): void
    {
        if (!is_array($submitted)) throw new \RuntimeException('扩展字段数据格式无效');
        $fieldsByScope = [];
        foreach (self::activeOwners() as [$kind, $name, , $manifest]) {
            $scope = self::scope($kind, $name);
            foreach ((array) ($manifest['meta'][$subjectType] ?? []) as $field) {
                if (is_array($field) && is_string($field['key'] ?? null)) $fieldsByScope[$scope][$field['key']] = [$kind, $name, $field];
            }
        }
        foreach ($submitted as $scope => $values) {
            if (!is_string($scope) || !isset($fieldsByScope[$scope]) || !is_array($values)) {
                throw new \RuntimeException('扩展字段归属无效');
            }
            foreach ($values as $key => $value) {
                if (!is_string($key) || !isset($fieldsByScope[$scope][$key])) throw new \RuntimeException('提交了未声明的扩展字段');
                [$kind, $name, $field] = $fieldsByScope[$scope][$key];
                self::setForExtension($kind, $name, $subjectType, $subjectId, $key, $value);
            }
        }
    }

    /** Replace one owner's rows while preserving caller order and legacy duplicate keys. */
    public static function replace(string $subjectType, int $subjectId, string $scope, array $fields): void
    {
        self::validate($subjectType, $subjectId, $scope, '');
        $normalized = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !is_string($field['key'] ?? null) || !is_scalar($field['value'] ?? null)) continue;
            $key = mb_substr(trim($field['key']), 0, 191);
            $value = (string) $field['value'];
            if ($scope === self::POST_FIELDS_SCOPE) $value = mb_substr(trim($value), 0, 500);
            if ($key !== '' || $value !== '') $normalized[] = ['key' => $key, 'value' => $value];
        }
        if (count($normalized) > 65536) throw new \InvalidArgumentException('单个实体字段数量超出限制');

        MediaReferences::transaction(static function () use ($subjectType, $subjectId, $scope, $normalized): void {
            self::lockSubject($subjectType, $subjectId);
            $old = DB::fetchAll('SELECT id FROM entity_meta WHERE subject_type=? AND subject_id=? AND scope=? ORDER BY id FOR UPDATE', [$subjectType, $subjectId, $scope]);
            DB::execute('DELETE FROM entity_meta WHERE subject_type=? AND subject_id=? AND scope=?', [$subjectType, $subjectId, $scope]);
            foreach ($old as $row) MediaReferences::forget('entity_meta', (int) $row['id']);
            foreach ($normalized as $position => $field) {
                DB::execute('INSERT INTO entity_meta (subject_type,subject_id,scope,meta_key,key_hash,position,meta_value) VALUES (?,?,?,?,?,?,?)',
                    [$subjectType, $subjectId, $scope, $field['key'], hash('sha256', $field['key'], true), $position, $field['value']]);
                if ($scope !== self::POST_FIELDS_SCOPE) MediaReferences::sync('entity_meta', DB::lastInsertId());
            }
        });
    }

    public static function deleteScope(string $subjectType, int $subjectId, string $scope): void
    {
        self::validate($subjectType, $subjectId, $scope, '');
        MediaReferences::transaction(static function () use ($subjectType, $subjectId, $scope): void {
            self::lockSubject($subjectType, $subjectId);
            $rows = DB::fetchAll('SELECT id FROM entity_meta WHERE subject_type=? AND subject_id=? AND scope=? FOR UPDATE', [$subjectType, $subjectId, $scope]);
            DB::execute('DELETE FROM entity_meta WHERE subject_type=? AND subject_id=? AND scope=?', [$subjectType, $subjectId, $scope]);
            foreach ($rows as $row) MediaReferences::forget('entity_meta', (int) $row['id']);
        });
    }

    /** Remove every field owned by an extension when its uninstall explicitly deletes data. */
    public static function deleteOwner(string $ownerType, string $ownerName): void
    {
        $scope = self::scope($ownerType, $ownerName);
        MediaReferences::transaction(static function () use ($scope): void {
            $subjects = DB::fetchAll('SELECT DISTINCT subject_type,subject_id FROM entity_meta WHERE scope=? ORDER BY subject_type,subject_id', [$scope]);
            foreach ($subjects as $subject) {
                $type = (string) $subject['subject_type'];
                $id = (int) $subject['subject_id'];
                self::lockSubject($type, $id);
                $rows = DB::fetchAll('SELECT id FROM entity_meta WHERE subject_type=? AND subject_id=? AND scope=? FOR UPDATE', [$type, $id, $scope]);
                if ($rows === []) continue;
                DB::execute('DELETE FROM entity_meta WHERE subject_type=? AND subject_id=? AND scope=?', [$type, $id, $scope]);
                foreach ($rows as $row) MediaReferences::forget('entity_meta', (int) $row['id']);
            }
        });
    }

    /** Call before permanently deleting a subject; soft deletion intentionally keeps metadata. */
    public static function deleteSubject(string $subjectType, int $subjectId): void
    {
        if (!isset(self::SUBJECTS[$subjectType]) || $subjectId < 1) throw new \InvalidArgumentException('元数据实体地址无效');
        MediaReferences::transaction(static function () use ($subjectType, $subjectId): void {
            self::lockSubject($subjectType, $subjectId);
            $rows = DB::fetchAll('SELECT id FROM entity_meta WHERE subject_type=? AND subject_id=? FOR UPDATE', [$subjectType, $subjectId]);
            DB::execute('DELETE FROM entity_meta WHERE subject_type=? AND subject_id=?', [$subjectType, $subjectId]);
            foreach ($rows as $row) MediaReferences::forget('entity_meta', (int) $row['id']);
        });
    }

    /** Idempotent import used by the versioned PHP migration; malformed legacy values remain in posts.custom_fields. */
    public static function migrateLegacyPostFields(\PDO $pdo): void
    {
        $lastId = 0;
        do {
            $stmt = $pdo->prepare('SELECT id,custom_fields FROM posts WHERE id>? AND custom_fields IS NOT NULL AND custom_fields<>\'\' ORDER BY id LIMIT 100');
            $stmt->execute([$lastId]);
            $posts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($posts as $post) {
                $lastId = (int) $post['id'];
                $rows = json_decode((string) $post['custom_fields'], true);
                if (!is_array($rows) || self::hasPostFields($pdo, $lastId)) continue;
                $position = 0;
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $key = mb_substr(trim((string) ($row['key'] ?? '')), 0, 191);
                    $value = mb_substr(trim((string) ($row['value'] ?? '')), 0, 500);
                    if ($key === '' && $value === '') continue;
                    $insert = $pdo->prepare('INSERT IGNORE INTO entity_meta (subject_type,subject_id,scope,meta_key,key_hash,position,meta_value) VALUES (\'post\',?, ?, ?, ?, ?, ?)');
                    $insert->execute([$lastId, self::POST_FIELDS_SCOPE, $key, hash('sha256', $key, true), $position++, $value]);
                }
            }
        } while (count($posts) === 100);
    }

    private static function hasPostFields(\PDO $pdo, int $id): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM entity_meta WHERE subject_type=\'post\' AND subject_id=? AND scope=? LIMIT 1');
        $stmt->execute([$id, self::POST_FIELDS_SCOPE]);
        return $stmt->fetchColumn() !== false;
    }

    private static function replaceKey(string $subjectType, int $subjectId, string $scope, string $key, array $values): void
    {
        self::validate($subjectType, $subjectId, $scope, $key);
        if (count($values) > 65536) throw new \InvalidArgumentException('单个字段值数量超出限制');
        MediaReferences::transaction(static function () use ($subjectType, $subjectId, $scope, $key, $values): void {
            self::lockSubject($subjectType, $subjectId);
            $hash = hash('sha256', $key, true);
            $old = DB::fetchAll('SELECT id FROM entity_meta WHERE subject_type=? AND subject_id=? AND scope=? AND key_hash=? AND meta_key=? FOR UPDATE', [$subjectType, $subjectId, $scope, $hash, $key]);
            DB::execute('DELETE FROM entity_meta WHERE subject_type=? AND subject_id=? AND scope=? AND key_hash=? AND meta_key=?', [$subjectType, $subjectId, $scope, $hash, $key]);
            foreach ($old as $row) MediaReferences::forget('entity_meta', (int) $row['id']);
            foreach (array_values($values) as $position => $value) {
                if (!is_string($value) || mb_strlen($value) > 16000000) throw new \InvalidArgumentException('元数据值无效');
                DB::execute('INSERT INTO entity_meta (subject_type,subject_id,scope,meta_key,key_hash,position,meta_value) VALUES (?,?,?,?,?,?,?)',
                    [$subjectType, $subjectId, $scope, $key, $hash, $position, $value]);
                MediaReferences::sync('entity_meta', DB::lastInsertId());
            }
        });
    }

    private static function lockSubject(string $subjectType, int $subjectId): void
    {
        $table = self::SUBJECTS[$subjectType] ?? throw new \InvalidArgumentException('元数据实体类型无效');
        if ($subjectId < 1 || DB::value("SELECT id FROM {$table} WHERE id=? FOR UPDATE", [$subjectId]) === null) {
            throw new \RuntimeException('元数据所属实体不存在');
        }
    }

    private static function declaredField(string $kind, string $name, string $subjectType, string $key): array
    {
        if ($kind === 'plugin') {
            $manifest = Plugin::manifest($name);
        } elseif ($kind === 'theme') {
            $manifest = Theme::manifest($name);
        } else {
            throw new \InvalidArgumentException('扩展类型无效');
        }
        if ($manifest === null) throw new \InvalidArgumentException('扩展清单不可用');
        foreach ((array) ($manifest['meta'][$subjectType] ?? []) as $field) {
            if (is_array($field) && ($field['key'] ?? null) === $key) return $field;
        }
        throw new \InvalidArgumentException('扩展未声明该实体字段');
    }

    private static function activeOwners(): array
    {
        $owners = [];
        foreach (Plugin::activeNames() as $name) {
            $manifest = Plugin::manifest($name);
            if ($manifest !== null && is_array($manifest['meta'] ?? null)) {
                $owners[] = ['plugin', $name, (string) ($manifest['title'] ?? $name), $manifest];
            }
        }
        $name = Theme::active();
        $manifest = Theme::manifest($name);
        if ($manifest !== null && is_array($manifest['meta'] ?? null)) {
            $owners[] = ['theme', $name, (string) ($manifest['title'] ?? $name), $manifest];
        }
        return $owners;
    }

    private static function validate(string $subjectType, int $subjectId, string $scope, string $key): void
    {
        if (!isset(self::SUBJECTS[$subjectType]) || $subjectId < 1
            || preg_match('/^(?:core|plugin:[a-z0-9_-]{1,50}|theme:[a-z0-9_-]{1,50}|core:custom_fields)$/D', $scope) !== 1
            || mb_strlen($key) > 191 || str_contains($key, "\0")) {
            throw new \InvalidArgumentException('元数据地址无效');
        }
    }
}
