<?php

declare(strict_types=1);

namespace Pafish\Services;

/**
 * 版本化数据库迁移（v0.1.6+）：
 * - migrations/*.sql 按文件名排序逐个应用，已应用的记录在 schema_migrations 表
 * - 安装路径：install.php 仍执行 app/install/schema.sql（宽松、已验证），完成后
 *   把 0001_initial 标记为已应用基线；此后结构演进一律新增 migrations/0002_*.sql 增量
 * - 存量库升级：检测到已有业务表（users）且无迁移记录时，自动把 0001_initial
 *   视为已应用基线，只应用其后增量——新装/老装同一条演进路径
 * - 全新库也可直接导入 migrations/0001_initial.sql 建表（与 schema.sql 内容一致，
 *   由 build-release.php 校验二者同步）
 * - 失败语义：单个迁移内逐语句执行，失败即抛异常中止。注意 MySQL DDL 隐式提交、
 *   无法整体回滚，因此每个迁移文件应只包含一个目的；升级整体回滚由在线更新的
 *   整站备份机制负责（见 docs/migrations.md）
 */
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
            $pdo->beginTransaction();
            try {
                foreach (self::splitStatements((string) file_get_contents($file)) as $stmt) {
                    $pdo->exec($stmt);
                }
                $pdo->prepare('INSERT INTO ' . self::TABLE . ' (version) VALUES (?)')->execute([$version]);
                $pdo->commit();
                $applied[] = $version;
            } catch (\Throwable $e) {
                $pdo->rollBack();
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
