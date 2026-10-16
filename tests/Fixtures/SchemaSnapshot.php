<?php

declare(strict_types=1);

namespace Pafish\Tests;

use PDO;

/** Reads structural metadata only. No application rows or configuration values. */
final class SchemaSnapshot
{
    public static function read(PDO $pdo, array $names, string $prefix = ''): array
    {
        $result = [];
        $logical = static fn (string $name): string => $prefix !== '' && str_starts_with($name, $prefix)
            ? substr($name, strlen($prefix)) : $name;
        foreach ($names as $name) {
            if (!is_string($name) || preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $prefix . $name) !== 1) {
                throw new \InvalidArgumentException('Invalid inventory table name');
            }
            $physical = $prefix . $name;
            $query = static function (string $sql) use ($pdo, $physical): array {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$physical]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            };
            $table = $query('SELECT ENGINE AS engine,TABLE_COLLATION AS collation,TABLE_COMMENT AS comment
                FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?')[0] ?? null;
            if ($table === null) continue;
            $columns = [];
            foreach ($query('SELECT COLUMN_NAME AS name,COLUMN_TYPE AS type,IS_NULLABLE AS nullable,
                COLUMN_DEFAULT AS default_value,EXTRA AS extra,CHARACTER_SET_NAME AS charset,
                COLLATION_NAME AS collation,GENERATION_EXPRESSION AS expression,COLUMN_COMMENT AS comment
                FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION') as $row) {
                $key = $row['name'];
                unset($row['name']);
                $columns[$key] = $row;
            }
            $indexes = [];
            foreach ($query('SELECT INDEX_NAME AS name,NON_UNIQUE AS non_unique,INDEX_TYPE AS type,
                SEQ_IN_INDEX AS sequence_number,COLUMN_NAME AS column_name,SUB_PART AS prefix_length,COLLATION AS direction
                FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX') as $row) {
                $key = $logical($row['name']);
                unset($row['name']);
                $indexes[$key][] = $row;
            }
            $foreignKeys = [];
            foreach ($query('SELECT k.CONSTRAINT_NAME AS name,k.ORDINAL_POSITION AS sequence_number,
                k.COLUMN_NAME AS column_name,k.REFERENCED_TABLE_NAME AS parent_table,k.REFERENCED_COLUMN_NAME AS parent_column,
                r.DELETE_RULE AS on_delete,r.UPDATE_RULE AS on_update
                FROM information_schema.KEY_COLUMN_USAGE k
                JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
                    AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
                WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION') as $row) {
                $key = $logical($row['name']);
                unset($row['name']);
                $row['parent_table'] = $logical($row['parent_table']);
                $foreignKeys[$key][] = $row;
            }
            $result[$name] = ['table'=>$table, 'columns'=>$columns, 'indexes'=>$indexes, 'foreign_keys'=>$foreignKeys];
        }
        return $result;
    }

    /** Column order, statistics and AUTO_INCREMENT next values do not define the contract. */
    public static function diff(array $expected, array $actual): array
    {
        $diff = [];
        foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $table) {
            if (!isset($expected[$table]) || !isset($actual[$table])) {
                $diff[] = ['table'=>$table,'kind'=>isset($actual[$table])?'extra_table':'missing_table'];
                continue;
            }
            foreach (['engine','collation'] as $field) {
                if ($expected[$table]['table'][$field] !== $actual[$table]['table'][$field]) {
                    $diff[] = ['table'=>$table,'kind'=>'table_property','name'=>$field,
                        'expected'=>$expected[$table]['table'][$field],'actual'=>$actual[$table]['table'][$field]];
                }
            }
            foreach (['columns','indexes','foreign_keys'] as $group) {
                foreach (array_unique(array_merge(array_keys($expected[$table][$group]), array_keys($actual[$table][$group]))) as $key) {
                    $before = $expected[$table][$group][$key] ?? null;
                    $after = $actual[$table][$group][$key] ?? null;
                    // Comments are reported separately; missing annotation is not a data-shape mismatch.
                    if ($group === 'columns') { unset($before['comment'], $after['comment']); }
                    if ($before !== $after) $diff[] = ['table'=>$table,'kind'=>$group,'name'=>$key,'expected'=>$before,'actual'=>$after];
                }
            }
        }
        return $diff;
    }
}
