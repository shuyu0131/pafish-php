<?php

declare(strict_types=1);

namespace Pafish\Services;

/** 运行按文件名排序的增量迁移，并记录已应用版本。 */
final class Migrator
{
    private const TABLE = 'schema_migrations';

    /** 建迁移记录表（幂等） */
    public static function ensureTable(\PDO $pdo): void
    {
        $pdo->query('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            version     VARCHAR(64) NOT NULL,
            applied_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (version)
        ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci');
    }

    /** 标记某迁移已应用（幂等，重复执行安全） */
    public static function markApplied(\PDO $pdo, string $version): void
    {
        self::ensureTable($pdo);
        $stmt = $pdo->prepare('INSERT IGNORE INTO ' . self::TABLE . ' (version) VALUES (?)');
        $stmt->execute([$version]);
    }

    /** 已应用版本列表（表不存在时返回空数组） */
    public static function appliedVersions(\PDO $pdo): array
    {
        try {
            $rows = $pdo->query('SELECT version FROM ' . self::TABLE)->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            return [];
        }
        return array_map('strval', $rows);
    }

    /**
     * 运行 migrations/ 下未应用的迁移，返回 ['applied' => [版本...]]。
     * DML 分段事务，DDL 单独执行；DDL 无法回滚，迁移本身必须支持中断重试。
     * 不得在业务事务内运行；同一个数据库的迁移由连接级锁串行执行。
     */
    public static function run(\PDO $pdo, string $migrationsDir): array
    {
        if (!is_dir($migrationsDir)) {
            return ['applied' => []];
        }
        if ($pdo->inTransaction()) {
            throw new \RuntimeException('不能在业务事务中执行数据库迁移');
        }
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $lock = 'pafish:migrate:' . substr(hash('sha256', $database), 0, 40);
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $stmt->execute([$lock]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new \RuntimeException('另一进程正在执行数据库迁移，请稍后重试');
        }
        $foreignKeys = null;
        try {
            $foreignKeys = (int) $pdo->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS')->fetchColumn();
            return self::runLocked($pdo, $migrationsDir);
        } finally {
            try {
                if ($foreignKeys !== null) {
                    $pdo->query('SET FOREIGN_KEY_CHECKS = ' . $foreignKeys);
                }
            } finally {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lock]);
            }
        }
    }

    private static function runLocked(\PDO $pdo, string $migrationsDir): array
    {
        self::ensureTable($pdo);

        // 存量库（已有业务表且缺少 baseline 记录）→ baseline 视为已应用基线。
        // 早期测试库可能已经登记过某些增量迁移，但仍没有正式 baseline；
        // 此时不能再次执行含全文索引 DDL 的基线文件。
        $recorded = self::appliedVersions($pdo);
        if (!in_array('baseline', $recorded, true) && self::tableExists($pdo, 'users')) {
            self::markApplied($pdo, 'baseline');
            $recorded = self::appliedVersions($pdo);
        }

        $files = array_merge(glob($migrationsDir . '/*.sql') ?: [], glob($migrationsDir . '/*.php') ?: []);
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $version = pathinfo($file, PATHINFO_FILENAME);
            if (in_array($version, $recorded, true)) {
                continue;
            }
            // 新安装的基线已包含全部当前结构；存量迁移若目标结构已完整存在，
            // 只登记版本，避免对同一列、索引或表重复执行 DDL。
            if (self::migrationTargetExists($pdo, $version)) {
                self::markApplied($pdo, $version);
                $recorded[] = $version;
                continue;
            }
            $isPhp = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php';
            $sql = $isPhp ? null : file_get_contents($file);
            if (!$isPhp && $sql === false) throw new \RuntimeException('无法读取迁移：' . $version);
            // 整个文件先解析，再写入；语法不完整时不能先执行前半部分。
            $modes = $isPhp ? [] : explode(',', strtoupper((string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()));
            $statements = $isPhp ? [] : self::splitStatements((string) $sql, in_array('NO_BACKSLASH_ESCAPES', $modes, true), in_array('ANSI_QUOTES', $modes, true));
            foreach ($statements as $stmt) {
                if (preg_match('/^(?:CREATE\s+(?:TABLE|(?:UNIQUE\s+)?INDEX)|ALTER\s+TABLE|DROP\s+(?:TABLE|INDEX)|TRUNCATE\s+TABLE|RENAME\s+TABLE|SET\s|INSERT\s|UPDATE\s|DELETE\s|REPLACE\s|SELECT\s|START\s+TRANSACTION\s*$|BEGIN\s*$|COMMIT\s*$)/i', $stmt) !== 1) {
                    throw new \RuntimeException('迁移 ' . $version . ' 包含不支持的语句；只支持表结构和普通数据迁移');
                }
            }
            try {
                if ($isPhp) {
                    $migration = require $file;
                    if (!is_callable($migration)) throw new \RuntimeException('PHP 迁移必须返回可调用函数');
                    $migration($pdo);
                }
                foreach ($statements as $stmt) {
                    // 验证码已转为会话存储；保留历史迁移文件，不在新安装重建退役表。
                    if ($version === 'zzzz_core_comments' && preg_match('/^ALTER\s+TABLE\s+`?email_codes`?\s/i', $stmt) === 1) continue;
                    // 旧 SQL 文件中的事务标记由执行器统一接管。
                    if (preg_match('/^(?:START\s+TRANSACTION|BEGIN|COMMIT)\s*$/i', $stmt) === 1) {
                        continue;
                    }
                    // ALTER TABLE 新增列在 MySQL DDL 隐式提交后不可整体回滚；逐条检查列，
                    // 使中断重试和部分完成的迁移保持幂等。
                    if (preg_match('/^ALTER\s+TABLE\s+`?([a-zA-Z0-9_]+)`?\s+ADD\s+COLUMN\s+`?([a-zA-Z0-9_]+)`?/i', $stmt, $m) === 1
                        && self::columnExists($pdo, (string)$m[1], (string)$m[2])) {
                        continue;
                    }
                    if (preg_match('/^ALTER\s+TABLE\s+`?([a-zA-Z0-9_]+)`?\s+ADD\s+(?:KEY|INDEX)\s+`?([a-zA-Z0-9_]+)`?/is', $stmt, $m) === 1
                        && self::indexExists($pdo, (string)$m[1], (string)$m[2])) {
                        continue;
                    }
                    if (preg_match('/^ALTER\s+TABLE\s+`?([a-zA-Z0-9_]+)`?\s+ADD\s+CONSTRAINT\s+`?([a-zA-Z0-9_]+)`?\s+FOREIGN\s+KEY/is', $stmt, $m) === 1
                        && self::foreignKeyExists($pdo, (string)$m[1], (string)$m[2])) {
                        continue;
                    }
                    $ddl = preg_match('/^(?:CREATE|ALTER|DROP|TRUNCATE|RENAME|SET)\b/i', $stmt) === 1;
                    if ($ddl && $pdo->inTransaction()) {
                        $pdo->commit();
                    } elseif (!$ddl && !$pdo->inTransaction()) {
                        $pdo->beginTransaction();
                    }
                    $pdo->query($stmt);
                }
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                }
                $pdo->prepare('INSERT INTO ' . self::TABLE . ' (version) VALUES (?)')->execute([$version]);
                $pdo->commit();
                $applied[] = $version;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw new \RuntimeException("迁移 {$version} 失败；已完成的 DDL 不能回滚，修复后可重试：{$e->getMessage()}", 0, $e);
            }
        }
        return ['applied' => $applied];
    }

    /** 表是否存在（宽松探测：能 SELECT 即存在） */
    private static function tableExists(\PDO $pdo, string $name): bool
    {
        try {
            $pdo->prepare('SELECT 1 FROM `' . str_replace('`', '', $name) . '` LIMIT 1')->execute();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** 已有部分结构的迁移重新执行时，按完整目标结构判断是否只需补登记。 */
    private static function migrationTargetExists(\PDO $pdo, string $version): bool
    {
        return match ($version) {
            'category_query_index' => self::categoryQueryIndexReady($pdo),
            'query_indexes' => self::queryIndexesReady($pdo),
            'content_format' => self::tableHasColumns($pdo, 'posts', ['content_format'])
                && self::tableHasColumns($pdo, 'pages', ['content_format']),
            'pages_deleted_at' => self::tableHasColumns($pdo, 'pages', ['deleted_at']),
            'post_templates' => self::tableHasColumns($pdo, 'posts', ['template']),
            'micro_statuses_content_format' => self::tableHasColumns($pdo, 'micro_statuses', ['content_format']),
            'micro_statuses_content_capacity' => in_array(self::columnType($pdo, 'micro_statuses', 'content'), ['mediumtext', 'longtext'], true),
            'entity_meta' => self::tableExists($pdo, 'entity_meta'),
            'zzzz_extension_records' => self::extensionRecordsReady($pdo),
            'email_code_security' => true, // 退役迁移；最后由 zzzzz_email_codes_session 清理旧表。
            'zzzzz_email_codes_session' => !self::tableExists($pdo, 'email_codes'),
            'media_capabilities' => self::tableHasColumns($pdo, 'uploads', ['usage_count', 'last_used_at']),
            'widget_areas' => self::tableHasColumns($pdo, 'widgets', ['area']),
            'post_reactions' => self::tableHasColumns($pdo, 'post_reactions', ['user_id', 'post_id', 'kind']),
            'permalink_redirects' => self::tableHasColumns($pdo, 'permalink_redirects', ['old_path', 'old_path_hash', 'target_path', 'content_type', 'created_at'])
                && self::indexExists($pdo, 'permalink_redirects', 'idx_permalink_redirect_target'),
            'user_points' => self::tableHasColumns($pdo, 'user_points', ['user_id', 'balance'])
                && self::tableHasColumns($pdo, 'point_transactions', ['id', 'user_id', 'amount']),
            'user_management' => self::tableHasColumns($pdo, 'users', ['description', 'last_login_ip', 'last_active_at'])
                 && self::indexExists($pdo, 'users', 'idx_users_last_active'),
            'submission_workflow' => self::tableHasColumns($pdo, 'posts', ['review_note', 'reviewed_by', 'reviewed_at']),
            'zzzzzzzz_nav_menu' => self::tableHasColumns($pdo, 'nav_items', ['parent_id', 'category_id'])
                && self::indexExists($pdo, 'nav_items', 'idx_nav_items_parent_sort')
                && self::indexExists($pdo, 'nav_items', 'idx_nav_items_category')
                && self::foreignKeyExists($pdo, 'nav_items', 'fk_nav_items_parent')
                && self::foreignKeyExists($pdo, 'nav_items', 'fk_nav_items_category'),
            'zzzzzzzz_nav_menu_groups' => self::tableExists($pdo, 'nav_menus')
                && self::tableHasColumns($pdo, 'nav_items', ['menu_id'])
                && self::indexExists($pdo, 'nav_items', 'idx_nav_items_menu_parent_sort')
                && self::foreignKeyExists($pdo, 'nav_items', 'fk_nav_items_menu'),
            'zzzzzzzz_nav_targets' => self::tableHasColumns($pdo, 'nav_items', ['item_type', 'page_id', 'post_id', 'tag_id'])
                && self::indexExists($pdo, 'nav_items', 'idx_nav_items_page')
                && self::indexExists($pdo, 'nav_items', 'idx_nav_items_post')
                && self::indexExists($pdo, 'nav_items', 'idx_nav_items_tag')
                && self::foreignKeyExists($pdo, 'nav_items', 'fk_nav_items_page')
                && self::foreignKeyExists($pdo, 'nav_items', 'fk_nav_items_post')
                && self::foreignKeyExists($pdo, 'nav_items', 'fk_nav_items_tag'),
            default => false,
        };
    }

    private static function columnType(\PDO $pdo, string $table, string $column): ?string
    {
        $stmt = $pdo->prepare('SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        $type = $stmt->fetchColumn();
        return $type === false ? null : strtolower((string) $type);
    }

    private static function indexColumns(\PDO $pdo, string $table, string $index): array
    {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX');
        $stmt->execute([$table, $index]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private static function categoryQueryIndexReady(\PDO $pdo): bool
    {
        $actual = self::indexColumns($pdo, 'posts', 'idx_posts_category');
        if ($actual === ['category_id', 'status', 'deleted_at', 'is_pinned', 'category_pinned', 'published_at', 'id']) return true;
        if ($actual === ['category_id']) return false;
        throw new \RuntimeException('索引 posts.idx_posts_category 与已知结构不同，请先核查；未自动替换未知索引');
    }

    private static function queryIndexesReady(\PDO $pdo): bool
    {
        $ready = true;
        foreach ([
            ['posts', 'idx_posts_public', ['status', 'deleted_at', 'is_pinned', 'published_at', 'id']],
            ['posts', 'idx_posts_list', ['deleted_at', 'published_at', 'id']],
            ['comments', 'idx_comments_post_status', ['post_id', 'status', 'parent_id', 'created_at', 'id']],
        ] as [$table, $index, $expected]) {
            $actual = self::indexColumns($pdo, $table, $index);
            if ($actual === $expected) continue;
            if (($table === 'posts' && $actual === []) || ($table === 'comments' && $actual === ['post_id', 'status'])) {
                $ready = false;
                continue;
            }
            throw new \RuntimeException('索引 ' . $table . '.' . $index . ' 与已知结构不同，请先核查；未自动替换未知索引');
        }
        return $ready;
    }

    private static function extensionRecordsReady(\PDO $pdo): bool
    {
        if (!self::tableExists($pdo, 'extension_records')) return false;
        $engine = $pdo->prepare('SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $engine->execute(['extension_records']);
        if (strcasecmp((string) $engine->fetchColumn(), 'InnoDB') !== 0) {
            throw new \RuntimeException('extension_records 必须使用 InnoDB；未自动替换已有表');
        }
        $expectedColumns = [
            'id' => ['bigint unsigned', null, null, null, 'auto_increment'],
            'extension_type' => ['varchar(8)', 'ascii', 'ascii_bin', null, null],
            'extension_name' => ['varchar(50)', 'ascii', 'ascii_bin', null, null],
            'model_name' => ['varchar(64)', 'ascii', 'ascii_bin', null, null],
            'record_key' => ['varchar(191)', 'ascii', 'ascii_bin', null, null],
            'payload' => ['mediumtext', 'utf8mb4', 'utf8mb4_unicode_ci', null, null],
            'revision' => ['bigint unsigned', null, null, '1', null],
            'created_at' => ['datetime', null, null, 'current_timestamp', null],
            'updated_at' => ['datetime', null, null, 'current_timestamp', 'on update current_timestamp'],
        ];
        $column = $pdo->prepare('SELECT COLUMN_TYPE, IS_NULLABLE, EXTRA, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        foreach ($expectedColumns as $name => [$type, $charset, $collation, $default, $requiredExtra]) {
            $column->execute(['extension_records', $name]);
            $actual = $column->fetch(\PDO::FETCH_ASSOC);
            $extra = strtolower((string) ($actual['EXTRA'] ?? ''));
            if (!$actual || strtolower((string) $actual['COLUMN_TYPE']) !== $type
                || $actual['IS_NULLABLE'] !== 'NO'
                || strtolower((string) ($actual['CHARACTER_SET_NAME'] ?? '')) !== (string) $charset
                || strtolower((string) ($actual['COLLATION_NAME'] ?? '')) !== (string) $collation
                || !self::sameColumnDefault($actual['COLUMN_DEFAULT'], $default)
                || ($requiredExtra !== null && !str_contains($extra, $requiredExtra))
                || ($name !== 'id' && str_contains($extra, 'auto_increment'))) {
                throw new \RuntimeException('extension_records 表的 ' . $name . ' 列结构缺失或不匹配；未自动替换已有表');
            }
        }
        if (self::indexColumns($pdo, 'extension_records', 'PRIMARY') !== ['id']) {
            throw new \RuntimeException('extension_records 表主键缺失或结构不同，请先核查数据库');
        }
        foreach ([
            ['uk_extension_records_identity', ['extension_type', 'extension_name', 'model_name', 'record_key'], true],
            ['idx_extension_records_page', ['extension_type', 'extension_name', 'model_name', 'id'], false],
            ['idx_extension_records_owner', ['extension_type', 'extension_name', 'id'], false],
        ] as [$name, $columns, $unique]) {
            if (self::indexColumns($pdo, 'extension_records', $name) !== $columns
                || self::indexIsUnique($pdo, 'extension_records', $name) !== $unique) {
                throw new \RuntimeException('extension_records 表索引 ' . $name . ' 缺失或结构不同，请先核查数据库');
            }
        }
        return true;
    }

    private static function sameColumnDefault(mixed $actual, ?string $expected): bool
    {
        if ($expected === null) return $actual === null;
        $normalize = static function (string $value): string {
            $value = strtolower(trim($value));
            return preg_match('/^current_timestamp(?:\(\s*0?\s*\))?$/D', $value) === 1
                ? 'current_timestamp' : $value;
        };
        return $normalize((string) $actual) === $normalize($expected);
    }

    private static function indexIsUnique(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare('SELECT MIN(NON_UNIQUE) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $stmt->execute([$table, $index]);
        $unique = $stmt->fetchColumn();
        return $unique !== false && $unique !== null && (int) $unique === 0;
    }

    /** @param list<string> $columns */
    private static function tableHasColumns(\PDO $pdo, string $table, array $columns): bool
    {
        if (!self::tableExists($pdo, $table)) {
            return false;
        }
        foreach ($columns as $column) {
            if (!self::columnExists($pdo, $table, $column)) {
                return false;
            }
        }
        return true;
    }

    private static function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $stmt->execute([$table, $index]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function foreignKeyExists(\PDO $pdo, string $table, string $constraint): bool
    {
        try {
            // Some isolated-schema runners prefix constraint names along with table
            // names. Match the declared name either exactly (normal installs) or as
            // the suffix after that runner-specific prefix.
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND (CONSTRAINT_NAME = ? OR RIGHT(CONSTRAINT_NAME, CHAR_LENGTH(?)) = ?) AND CONSTRAINT_TYPE = ?');
            $stmt->execute([$table, $constraint, $constraint, $constraint, 'FOREIGN KEY']);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** 普通 MySQL 脚本分句；保留引号内容，不支持存储程序或自定义分隔符。 */
    private static function splitStatements(string $sql, bool $noBackslashEscapes = false, bool $ansiQuotes = false): array
    {
        return \Pafish\Core\SqlScript::split($sql, $noBackslashEscapes, $ansiQuotes);
    }
}
