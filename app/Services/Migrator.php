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
     * 目录不存在视为无迁移；失败抛 RuntimeException（本迁移未记录，下次可重试）。
     */
    public static function run(\PDO $pdo, string $migrationsDir): array
    {
        if (!is_dir($migrationsDir)) {
            return ['applied' => []];
        }
        self::ensureTable($pdo);

        // 存量库（已有业务表且缺少 baseline 记录）→ baseline 视为已应用基线。
        // 早期测试库可能已经登记过某些增量迁移，但仍没有正式 baseline；
        // 此时不能再次执行含全文索引 DDL 的基线文件。
        $recorded = self::appliedVersions($pdo);
        if (!in_array('baseline', $recorded, true) && self::tableExists($pdo, 'users')) {
            self::markApplied($pdo, 'baseline');
            $recorded = self::appliedVersions($pdo);
        }

        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
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
            $pdo->beginTransaction();
            try {
                foreach (self::splitStatements((string) file_get_contents($file)) as $stmt) {
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
                    $pdo->query($stmt);
                }
                $pdo->prepare('INSERT INTO ' . self::TABLE . ' (version) VALUES (?)')->execute([$version]);
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
                $applied[] = $version;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw new \RuntimeException("迁移 {$version} 失败：{$e->getMessage()}");
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
            'media_capabilities' => self::tableHasColumns($pdo, 'uploads', ['usage_count', 'last_used_at']),
            'widget_areas' => self::tableHasColumns($pdo, 'widgets', ['area']),
            'post_reactions' => self::tableHasColumns($pdo, 'post_reactions', ['user_id', 'post_id', 'kind']),
            'user_points' => self::tableHasColumns($pdo, 'user_points', ['user_id', 'balance'])
                && self::tableHasColumns($pdo, 'point_transactions', ['id', 'user_id', 'amount']),
            'user_management' => self::tableHasColumns($pdo, 'users', ['description', 'last_login_ip', 'last_active_at'])
                && self::indexExists($pdo, 'users', 'idx_users_last_active'),
            default => false,
        };
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
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?');
            $stmt->execute([$table, $constraint, 'FOREIGN KEY']);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** SQL 内容 → 语句数组（去掉 -- 行注释，按分号拆分；无存储过程，安全） */
    private static function splitStatements(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $lines[] = $line;
        }
        $stmts = [];
        foreach (explode(';', implode("\n", $lines)) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $stmts[] = $stmt;
            }
        }
        return $stmts;
    }
}
