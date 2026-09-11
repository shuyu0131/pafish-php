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
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
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

        // 存量库（已有业务表且无任何迁移记录）→ 0001_initial 视为已应用基线
        $recorded = self::appliedVersions($pdo);
        if ($recorded === [] && self::tableExists($pdo, 'users')) {
            self::markApplied($pdo, '0001_initial');
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
            // Some release baselines include a column before the incremental migration
            // is first seen. Mark that migration applied when its target shape already exists.
            if ($version === '0002_capabilities_media' && self::columnExists($pdo, 'uploads', 'usage_count') && self::columnExists($pdo, 'uploads', 'last_used_at')) {
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
                    $pdo->exec($stmt);
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
