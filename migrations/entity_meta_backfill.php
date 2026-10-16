<?php

declare(strict_types=1);

return static function (\PDO $pdo): void {
    $lastId = 0;
    do {
        $select = $pdo->prepare('SELECT id FROM posts WHERE id>? AND custom_fields IS NOT NULL AND custom_fields<>\'\' ORDER BY id LIMIT 100');
        $select->execute([$lastId]);
        $ids = $select->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            $lastId = $id;
            $pdo->beginTransaction();
            try {
                $read = $pdo->prepare('SELECT custom_fields FROM posts WHERE id=? FOR UPDATE');
                $read->execute([$id]);
                $raw = $read->fetchColumn();
                $fields = is_string($raw) ? json_decode($raw, true) : null;
                if (!is_array($fields)) {
                    $pdo->commit();
                    continue;
                }

                $pdo->prepare('DELETE FROM entity_meta WHERE subject_type=\'post\' AND subject_id=? AND scope=\'core:custom_fields\'')->execute([$id]);
                $insert = $pdo->prepare('INSERT INTO entity_meta (subject_type,subject_id,scope,meta_key,key_hash,position,meta_value) VALUES (\'post\',?,\'core:custom_fields\',?,?,?,?)');
                $position = 0;
                foreach ($fields as $field) {
                    if (!is_array($field)) continue;
                    $key = mb_substr(trim((string) ($field['key'] ?? '')), 0, 191);
                    $value = mb_substr(trim((string) ($field['value'] ?? '')), 0, 500);
                    if ($key === '' && $value === '') continue;
                    $insert->execute([$id, $key, hash('sha256', $key, true), $position++, $value]);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
    } while (count($ids) === 100);
};
