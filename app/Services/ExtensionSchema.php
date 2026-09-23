<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/** 扩展自有表的安全、增量 schema 管理；绝不根据清单删除对象。 */
final class ExtensionSchema
{
    private const NAME = '/^[a-z][a-z0-9_]{0,40}$/';
    private const TYPES = [
        'int' => 'INT', 'bigint' => 'BIGINT', 'smallint' => 'SMALLINT',
        'string' => 'VARCHAR(255)', 'text' => 'TEXT', 'bool' => 'TINYINT(1)',
        'datetime' => 'DATETIME', 'json' => 'JSON', 'decimal' => 'DECIMAL(20,6)',
    ];

    public static function validateDeclaration(mixed $schema, string $kind, string $name): ?string
    {
        if ($schema === null) {
            return null;
        }
        if (!is_array($schema) || !is_array($schema['tables'] ?? null)) {
            return 'schema 必须包含 tables 数组';
        }
        if (!in_array($kind, ['plugin', 'theme'], true) || preg_match('/^[a-z0-9_-]{1,50}$/', $name) !== 1) {
            return '扩展身份不合法';
        }
        $tables = [];
        foreach ($schema['tables'] as $table) {
            if (!is_array($table) || !is_string($table['name'] ?? null) || preg_match(self::NAME, $table['name']) !== 1) {
                return 'schema 表名不合法';
            }
            if (isset($tables[$table['name']])) {
                return 'schema 包含重复表名';
            }
            $tables[$table['name']] = true;
            if (!is_array($table['columns'] ?? null) || $table['columns'] === []) {
                return 'schema 表必须包含 columns 数组';
            }
            $columns = [];
            foreach ($table['columns'] as $column) {
                if (!is_array($column) || !is_string($column['name'] ?? null) || preg_match(self::NAME, $column['name']) !== 1
                    || !isset(self::TYPES[(string) ($column['type'] ?? '')])) {
                    return 'schema 列声明不合法';
                }
                if (isset($columns[$column['name']])) {
                    return 'schema 包含重复列';
                }
                if (!empty($column['autoIncrement']) && (string) $column['type'] !== 'bigint' && (string) $column['type'] !== 'int') {
                    return 'schema 自增列只能使用 int 或 bigint';
                }
                if (empty($column['nullable']) && empty($column['autoIncrement']) && !array_key_exists('default', $column)) {
                    return 'schema 非空列必须声明 default，保证增量加列安全';
                }
                if (array_key_exists('default', $column) && $column['default'] !== null && !is_scalar($column['default'])) {
                    return 'schema default 必须是标量或 null';
                }
                $columns[$column['name']] = true;
            }
            foreach ((array) ($table['primary'] ?? []) as $column) {
                if (!is_string($column) || !isset($columns[$column])) {
                    return 'schema primary 引用了不存在的列';
                }
            }
            foreach ($table['columns'] as $column) {
                if (!empty($column['autoIncrement']) && !in_array($column['name'], (array) ($table['primary'] ?? []), true)) {
                    return 'schema 自增列必须包含在 primary 中';
                }
            }
            foreach ((array) ($table['indexes'] ?? []) as $index) {
                if (!is_array($index) || !is_string($index['name'] ?? null) || preg_match(self::NAME, $index['name']) !== 1
                    || !is_array($index['columns'] ?? null) || $index['columns'] === []) {
                    return 'schema 索引声明不合法';
                }
                foreach ($index['columns'] as $column) {
                    if (!is_string($column) || !isset($columns[$column])) {
                        return 'schema 索引引用了不存在的列';
                    }
                }
            }
        }
        return null;
    }

    public static function sync(string $kind, string $name, mixed $schema): void
    {
        $error = self::validateDeclaration($schema, $kind, $name);
        if ($error !== null) {
            throw new \RuntimeException($error);
        }
        foreach ((array) (($schema['tables'] ?? null) ?: []) as $table) {
            $physical = self::physicalName($kind, $name, (string) $table['name']);
            $columns = [];
            foreach ($table['columns'] as $column) {
                $definition = '`' . $column['name'] . '` ' . self::TYPES[$column['type']];
                $definition .= !empty($column['nullable']) ? ' NULL' : ' NOT NULL';
                if (!empty($column['autoIncrement'])) {
                    $definition .= ' AUTO_INCREMENT';
                }
                if (array_key_exists('default', $column)) {
                    $default = $column['default'];
                    if ($default === null) {
                        $definition .= ' DEFAULT NULL';
                    } elseif (is_scalar($default) && preg_match('/^(CURRENT_TIMESTAMP|0)$/', (string) $default) === 1) {
                        $definition .= ' DEFAULT ' . $default;
                    } elseif (is_scalar($default)) {
                        $definition .= ' DEFAULT ' . DB::pdo()->quote((string) $default);
                    }
                }
                $columns[] = $definition;
            }
            $primary = array_values(array_filter((array) ($table['primary'] ?? []), static fn ($v): bool => is_string($v)));
            if ($primary !== []) {
                $columns[] = 'PRIMARY KEY (' . implode(', ', array_map(static fn (string $v): string => '`' . $v . '`', $primary)) . ')';
            }
            $pdo = DB::pdo();
            $exists = self::tableExists($physical);
            self::ddl($pdo, 'CREATE TABLE IF NOT EXISTS `' . $physical . '` (' . implode(', ', $columns) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            if ($exists) {
                foreach ($table['columns'] as $column) {
                    if (self::columnExists($physical, (string) $column['name'])) {
                        continue;
                    }
                    $definition = '`' . $column['name'] . '` ' . self::TYPES[$column['type']];
                    $definition .= !empty($column['nullable']) ? ' NULL' : ' NOT NULL';
                    if (array_key_exists('default', $column)) {
                        $default = $column['default'];
                        if ($default === null) {
                            $definition .= ' DEFAULT NULL';
                        } elseif (is_scalar($default) && preg_match('/^(CURRENT_TIMESTAMP|0)$/', (string) $default) === 1) {
                            $definition .= ' DEFAULT ' . $default;
                        } elseif (is_scalar($default)) {
                            $definition .= ' DEFAULT ' . $pdo->quote((string) $default);
                        }
                    }
                    self::ddl($pdo, 'ALTER TABLE `' . $physical . '` ADD COLUMN ' . $definition);
                }
            }
            foreach ((array) ($table['indexes'] ?? []) as $index) {
                $indexName = (string) $index['name'];
                $columnsSql = implode(', ', array_map(static fn (string $v): string => '`' . $v . '`', $index['columns']));
                // 索引必须走 CREATE INDEX：`INDEX name ON table (...)` 不是合法的独立语句。
                $type = !empty($index['unique']) ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
                if (!self::indexExists($physical, $indexName)) {
                    self::ddl($pdo, $type . ' `' . $indexName . '` ON `' . $physical . '` (' . $columnsSql . ')');
                }
            }
        }
    }

    /** 已启用扩展的最后同步版本，供显式 onUpgrade 使用。 */
    public static function installedVersion(string $kind, string $name): string
    {
        return (string) Settings::get('extension_version:' . $kind . ':' . $name, '');
    }

    /** schema 声明指纹；用于请求级廉价变更检测，避免每次请求查询 INFORMATION_SCHEMA。 */
    public static function fingerprint(mixed $schema): string
    {
        return sha1(serialize($schema));
    }

    public static function installedFingerprint(string $kind, string $name): string
    {
        return (string) Settings::get('extension_schema_fingerprint:' . $kind . ':' . $name, '');
    }

    public static function recordVersion(string $kind, string $name, string $version): void
    {
        Settings::set('extension_version:' . $kind . ':' . $name, $version);
    }

    public static function recordFingerprint(string $kind, string $name, mixed $schema): void
    {
        Settings::set('extension_schema_fingerprint:' . $kind . ':' . $name, self::fingerprint($schema));
    }

    /** 显式数据清理接口；调用方必须先取得管理员确认。 */
    public static function drop(string $kind, string $name, mixed $schema): void
    {
        $error = self::validateDeclaration($schema, $kind, $name);
        if ($error !== null) {
            throw new \RuntimeException($error);
        }
        foreach ((array) ($schema['tables'] ?? []) as $table) {
            self::ddl(DB::pdo(), 'DROP TABLE IF EXISTS `' . self::physicalName($kind, $name, (string) $table['name']) . '`');
        }
    }

    /** 删除扩展登记的版本元数据；仅在管理员明确选择删除数据时调用。 */
    public static function forget(string $kind, string $name): void
    {
        Settings::remove('extension_version:' . $kind . ':' . $name);
        Settings::remove('extension_schema_fingerprint:' . $kind . ':' . $name);
    }

    public static function physicalName(string $kind, string $name, string $table): string
    {
        if (!in_array($kind, ['plugin', 'theme'], true) || preg_match('/^[a-z0-9_-]{1,50}$/', $name) !== 1 || preg_match(self::NAME, $table) !== 1) {
            throw new \RuntimeException('扩展表名不合法');
        }
        return 'pf_' . $kind . '_' . str_replace('-', '_', $name) . '_' . $table;
    }

    private static function indexExists(string $table, string $index): bool
    {
        $stmt = DB::pdo()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function tableExists(string $table): bool
    {
        $stmt = DB::pdo()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function columnExists(string $table, string $column): bool
    {
        $stmt = DB::pdo()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** DDL 也通过 PDO prepared statement 执行，兼容禁用 PDO::exec 的共享主机策略。 */
    private static function ddl(\PDO $pdo, string $sql): void
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }
}
