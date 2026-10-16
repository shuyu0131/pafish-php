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
    /** 只支持单目标真实外键；不接受任意表名或多态引用。 */
    private const REFERENCE_TABLES = ['users', 'posts', 'pages', 'micro_statuses', 'categories', 'tags', 'comments', 'uploads'];

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
            try {
                self::physicalName($kind, $name, $table['name']);
            } catch (\RuntimeException $e) {
                return $e->getMessage();
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
                if (array_key_exists('unsigned', $column)
                    && (!is_bool($column['unsigned']) || ($column['unsigned']
                        && !in_array($column['type'], ['int', 'bigint', 'smallint'], true)))) {
                    return 'schema unsigned 只能用于整数列，且必须为布尔值';
                }
                if (!empty($column['unsigned']) && isset($column['default'])
                    && is_numeric($column['default']) && (float) $column['default'] < 0) {
                    return 'schema 无符号列不能使用负数默认值';
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
                if (array_key_exists('references', $column)) {
                    $ref = $column['references'];
                    if (!is_array($ref) || !in_array($ref['table'] ?? null, self::REFERENCE_TABLES, true)
                        || ($ref['column'] ?? 'id') !== 'id'
                        || array_diff(array_keys($ref), ['table', 'column', 'onDelete', 'onUpdate']) !== []) {
                        return 'schema references 必须指向允许的核心表 id';
                    }
                    if ($column['type'] !== 'bigint' || ($column['unsigned'] ?? false) !== true || !empty($column['autoIncrement'])) {
                        return 'schema 引用列必须声明 bigint、unsigned:true 且不能自增';
                    }
                    foreach (['onDelete', 'onUpdate'] as $rule) {
                        $action = $ref[$rule] ?? 'RESTRICT';
                        if (!in_array($action, ['RESTRICT', 'CASCADE', 'SET NULL'], true)
                            || ($action === 'SET NULL' && empty($column['nullable']))) {
                            return 'schema 外键规则无效；SET NULL 要求 nullable:true';
                        }
                    }
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
        self::claimTables($kind, $name, $schema);
        foreach ((array) (($schema['tables'] ?? null) ?: []) as $table) {
            $physical = self::physicalName($kind, $name, (string) $table['name']);
            $columns = [];
            foreach ($table['columns'] as $column) {
                $definition = '`' . $column['name'] . '` ' . self::columnType($column);
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
                        if (array_key_exists('unsigned', $column)) {
                            $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
                            $stmt->execute([$physical, $column['name']]);
                            $unsigned = str_contains(strtolower((string) $stmt->fetchColumn()), 'unsigned');
                            if ($unsigned !== $column['unsigned']) {
                                throw new \RuntimeException('字段 ' . $physical . '.' . $column['name'] . ' 的符号类型不同，请通过显式迁移调整');
                            }
                        }
                        continue;
                    }
                    $definition = '`' . $column['name'] . '` ' . self::columnType($column);
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
        // 全部列和索引完成后再加约束；历史孤儿数据必须显式修复，不静默删除或改写。
        foreach ((array) ($schema['tables'] ?? []) as $table) {
            $physical = self::physicalName($kind, $name, (string) $table['name']);
            foreach ($table['columns'] as $column) {
                if (isset($column['references'])) self::syncReference($physical, $column);
            }
        }
        ExtensionStorage::set($kind, $name, 'table_ownership_version', '1');
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
        // 首次升级必须登记现有业务表的归属，即使原 schema 指纹没有变化。
        if (ExtensionStorage::get($kind, $name, 'table_ownership_version') !== '1') return '';
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
        self::claimTables($kind, $name, $schema);
        foreach ((array) ($schema['tables'] ?? []) as $table) {
            $logical = (string) $table['name'];
            $physical = self::physicalName($kind, $name, $logical);
            self::ddl(DB::pdo(), 'DROP TABLE IF EXISTS `' . $physical . '`');
            ExtensionStorage::releaseTable($physical, $kind, $name, $logical);
        }
    }

    /** 删除扩展登记的版本元数据；仅在管理员明确选择删除数据时调用。 */
    public static function forget(string $kind, string $name): void
    {
        Settings::remove('extension_version:' . $kind . ':' . $name);
        Settings::remove('extension_schema_fingerprint:' . $kind . ':' . $name);
        ExtensionStorage::remove($kind, $name, 'table_ownership_version');
    }

    public static function physicalName(string $kind, string $name, string $table): string
    {
        if (!in_array($kind, ['plugin', 'theme'], true) || preg_match('/^[a-z0-9_-]{1,50}$/', $name) !== 1 || preg_match(self::NAME, $table) !== 1) {
            throw new \RuntimeException('扩展表名不合法');
        }
        $physical = 'pf_' . $kind . '_' . str_replace('-', '_', $name) . '_' . $table;
        if (strlen($physical) > 64) throw new \RuntimeException('扩展物理表名超过 MySQL 的 64 字符限制');
        return $physical;
    }

    public static function ownedName(string $kind, string $name, string $table): string
    {
        $physical = self::physicalName($kind, $name, $table);
        if (ExtensionStorage::getTableOwner($physical) !== json_encode([$kind, $name, $table], JSON_THROW_ON_ERROR)) {
            throw new \RuntimeException('扩展数据表未登记归属或存在冲突：' . $physical);
        }
        return $physical;
    }

    private static function claimTables(string $kind, string $name, mixed $schema): void
    {
        $tables = (array) ($schema['tables'] ?? []);
        if ($tables === []) return;
        // 初次接管旧表前检查所有已安装清单；停用的扩展也保留其数据归属。
        [, $declarations] = self::readDeclarations();
        foreach ($tables as $table) {
            $physical = self::physicalName($kind, $name, (string) $table['name']);
            foreach ($declarations[$physical] ?? [] as $declaration) {
                if ($declaration['kind'] !== $kind || $declaration['name'] !== $name) {
                    throw new \RuntimeException('已安装扩展声明了相同的数据表：' . $physical);
                }
            }
        }
        foreach ($tables as $table) {
            ExtensionStorage::claimTable(self::physicalName($kind, $name, $table['name']), $kind, $name, $table['name']);
        }
    }

    /** 只读盘点：不加载扩展代码、不认领旧表、不同步结构、不读取业务行。 */
    public static function inventory(): array
    {
        [$extensions, $declarations] = self::readDeclarations();
        $owners = DB::fetchAll("SELECT data_key, value FROM extension_storage WHERE extension_type='system' AND extension_name='table_owners'");
        $tables = DB::fetchAll('SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
            FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE=?', ['BASE TABLE']);
        return self::assembleInventory($extensions, $declarations, $owners, $tables);
    }

    /** 无效清单中的合法表名仍参与冲突检查；但不据此授权 DDL。 */
    private static function readDeclarations(): array
    {
        $extensions = $declarations = [];
        foreach (['plugin' => Plugin::root(), 'theme' => Theme::root()] as $kind => $root) {
            foreach (glob($root . '/*/' . $kind . '.json') ?: [] as $file) {
                $name = basename(dirname($file));
                if (preg_match('/^[a-z0-9_-]{1,50}$/D', $name) !== 1) continue;
                $manifest = json_decode((string) @file_get_contents($file), true);
                $description = $kind === 'plugin' ? Plugin::describe($name) : Theme::describe($name);
                $extensions[$kind . ':' . $name] = [
                    'title' => is_string($manifest['title'] ?? null) ? $manifest['title'] : $name,
                    'valid' => $description['error'] === null,
                ];
                $tables = $manifest['schema']['tables'] ?? [];
                if (!is_array($tables)) continue;
                foreach ($tables as $table) {
                    if (!is_array($table) || !is_string($table['name'] ?? null)) continue;
                    try {
                        $physical = self::physicalName($kind, $name, $table['name']);
                        $declarations[$physical][] = [
                            'kind' => $kind, 'name' => $name, 'logical' => $table['name'],
                            'valid' => $description['error'] === null,
                        ];
                    } catch (\RuntimeException) {
                        // 不猜测无效名字对应的物理表。
                    }
                }
            }
        }
        return [$extensions, $declarations];
    }

    /** 分离快照组装，便于验证缺表、保留数据、冲突及损坏登记。 */
    private static function assembleInventory(array $extensions, array $declarations, array $owners, array $tables): array
    {
        $physical = $registry = $names = [];
        foreach ($tables as $table) {
            $physical[$table['TABLE_NAME']] = $table;
            if (str_starts_with($table['TABLE_NAME'], 'pf_plugin_') || str_starts_with($table['TABLE_NAME'], 'pf_theme_')) {
                $names[$table['TABLE_NAME']] = true;
            }
        }
        foreach ($owners as $owner) {
            $registry[$owner['data_key']] = $owner['value'];
            $names[$owner['data_key']] = true;
        }
        foreach ($declarations as $name => $_) $names[$name] = true;
        ksort($names, SORT_STRING);
        $result = [];
        foreach ($names as $name => $_) {
            $claims = $declarations[$name] ?? [];
            $registered = array_key_exists($name, $registry);
            $owner = $registered ? json_decode($registry[$name], true) : null;
            $validOwner = false;
            if (is_array($owner) && array_is_list($owner) && count($owner) === 3
                && is_string($owner[0]) && is_string($owner[1]) && is_string($owner[2])) {
                try {
                    $validOwner = self::physicalName(...$owner) === $name
                        && $registry[$name] === json_encode($owner, JSON_THROW_ON_ERROR);
                } catch (\RuntimeException) {}
            }
            $identity = $validOwner
                ? ['kind' => $owner[0], 'name' => $owner[1], 'logical' => $owner[2]]
                : (!$registered && count($claims) === 1 ? $claims[0] : null);
            $matching = $identity !== null && count($claims) === 1
                && $claims[0]['kind'] === $identity['kind'] && $claims[0]['name'] === $identity['name']
                && $claims[0]['logical'] === $identity['logical'];
            $extension = $identity !== null ? ($extensions[$identity['kind'] . ':' . $identity['name']] ?? null) : null;
            $exists = isset($physical[$name]);
            $statuses = [];
            if ($registered && !$validOwner) $statuses[] = 'invalid_owner';
            if (count($claims) > 1 || ($validOwner && $claims !== [] && !$matching)) $statuses[] = 'conflict';
            if (!$exists) $statuses[] = $registered ? 'missing' : 'pending';
            if (!$registered && $exists) $statuses[] = 'unregistered';
            if ($validOwner && $extension === null) $statuses[] = 'retained';
            if ($validOwner && $extension !== null && $claims === []) $statuses[] = 'undeclared';
            if (($matching && !$claims[0]['valid']) || ($extension !== null && !$extension['valid'])) $statuses[] = 'invalid_manifest';
            if ($statuses === []) $statuses[] = 'registered';
            $result[] = [
                'table' => $name, 'identity' => $identity,
                'title' => $extension['title'] ?? ($identity['name'] ?? ''),
                'statuses' => $statuses, 'exists' => $exists,
                'rows' => $exists && $physical[$name]['TABLE_ROWS'] !== null ? (int) $physical[$name]['TABLE_ROWS'] : null,
                'bytes' => $exists ? (int) $physical[$name]['DATA_LENGTH'] + (int) $physical[$name]['INDEX_LENGTH'] : null,
                // 当前清单中有效且无冲突的表，才可能由核心卸载流程清理。
                'cleanup' => (!$registered || $validOwner) && $matching && $claims[0]['valid'],
                'attention' => array_diff($statuses, ['registered', 'pending']) !== [],
            ];
        }
        return $result;
    }

    private static function columnType(array $column): string
    {
        return self::TYPES[$column['type']] . (!empty($column['unsigned']) ? ' UNSIGNED' : '');
    }

    private static function indexExists(string $table, string $index): bool
    {
        $stmt = DB::pdo()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function syncReference(string $physical, array $column): void
    {
        $ref = $column['references'];
        $target = $ref['table'];
        $name = $column['name'];
        $pdo = DB::pdo();
        // 不自动 MODIFY 老列：符号、宽度或 NULL 规则漂移都需要显式迁移。
        foreach ([[$physical, $name, !empty($column['nullable'])], [$target, 'id', false]] as [$table, $field, $nullable]) {
            $stmt = $pdo->prepare('SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
            $stmt->execute([$table, $field]);
            $actual = $stmt->fetch();
            if (!$actual || $actual['DATA_TYPE'] !== 'bigint' || !str_contains(strtolower($actual['COLUMN_TYPE']), 'unsigned')
                || ($actual['IS_NULLABLE'] === 'YES') !== $nullable) {
                throw new \RuntimeException('引用字段 ' . $table . '.' . $field . ' 类型或可空规则不同，请通过显式迁移调整');
            }
        }
        $delete = $ref['onDelete'] ?? 'RESTRICT';
        $update = $ref['onUpdate'] ?? 'RESTRICT';
        $stmt = $pdo->prepare('SELECT k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME=? AS target_matches,
            k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE,
            (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE x
             WHERE x.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND x.TABLE_NAME=k.TABLE_NAME AND x.CONSTRAINT_NAME=k.CONSTRAINT_NAME) AS column_count
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
            WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.COLUMN_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL');
        $stmt->execute([$target, $physical, $name]);
        $existing = $stmt->fetchAll();
        if ($existing !== []) {
            $fk = $existing[0];
            if (count($existing) !== 1 || (int) $fk['target_matches'] !== 1 || $fk['REFERENCED_COLUMN_NAME'] !== 'id'
                || (int) $fk['column_count'] !== 1 || $fk['DELETE_RULE'] !== $delete || $fk['UPDATE_RULE'] !== $update) {
                throw new \RuntimeException('外键 ' . $physical . '.' . $name . ' 与声明不同，请通过显式迁移调整');
            }
            return;
        }
        $constraint = 'fk_ext_' . substr(hash('sha256', $physical . '.' . $name), 0, 24);
        $q = chr(96);
        try {
            self::ddl($pdo, "ALTER TABLE {$q}{$physical}{$q} ADD CONSTRAINT {$q}{$constraint}{$q}
                FOREIGN KEY ({$q}{$name}{$q}) REFERENCES {$q}{$target}{$q} ({$q}id{$q}) ON DELETE $delete ON UPDATE $update");
        } catch (\PDOException $e) {
            throw new \RuntimeException('无法建立外键 ' . $physical . '.' . $name . '，请检查历史孤儿数据、目标索引及表引擎；未删除或改写已有数据', 0, $e);
        }
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
