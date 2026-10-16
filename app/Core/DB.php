<?php

declare(strict_types=1);

namespace Pafish\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * PDO 单例与常用查询助手（全站唯一数据访问入口）
 */
final class DB
{
    private static ?PDO $pdo = null;
    private static int $savepointSequence = 0;
    /** 每个受管事务/savepoint 独立排队；回滚的层不会发布副作用。 */
    private static array $commitCallbacks = [];
    private static bool $ownsTransaction = false;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::get('db', []);
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'] ?? '127.0.0.1',
                (int) ($c['port'] ?? 3306),
                $c['database'] ?? '',
                $c['charset'] ?? 'utf8mb4'
            );
            try {
                self::$pdo = new PDO($dsn, $c['username'] ?? 'root', $c['password'] ?? '', [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                throw new \RuntimeException('数据库连接失败：' . $e->getMessage());
            }
            // 会话时区对齐应用配置（NOW() 与 PHP date() 一致；虚拟主机 MySQL 常为 UTC，
            // 不设置会导致"今天发布的文章"被 NOW() 判定为未来而隐藏）
            try {
                $offset = (new \DateTimeZone((string) Config::get('timezone', 'Asia/Shanghai')))
                    ->getOffset(new \DateTimeImmutable());
                $sign = $offset >= 0 ? '+' : '-';
                $abs = abs($offset);
                self::$pdo->query(sprintf(
                    "SET time_zone = '%s%02d:%02d'",
                    $sign,
                    intdiv($abs, 3600),
                    intdiv($abs % 3600, 60)
                ));
            } catch (\Throwable $e) {
                // 时区设置失败不影响连接使用
            }
        }
        return self::$pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** 多行结果 */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** 单行结果 */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** 单值结果 */
    public static function value(string $sql, array $params = []): mixed
    {
        $row = self::query($sql, $params)->fetch();
        if ($row === false) {
            return null;
        }
        return reset($row);
    }

    /** 执行写操作，返回受影响行数 */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /** 不为检查事务状态额外建立数据库连接。 */
    public static function inTransaction(): bool
    {
        return self::$pdo !== null && self::$pdo->inTransaction();
    }

    /** 提交后副作用必须使用 DB::transaction 管理最外层提交。 */
    public static function afterCommit(callable $callback): void
    {
        if (!self::inTransaction()) {
            if (self::$commitCallbacks !== []) {
                throw new \LogicException('业务事务已被提前结束，不能执行提交后操作');
            }
            $callback();
            return;
        }
        if (!self::$ownsTransaction || self::$commitCallbacks === []) {
            throw new \LogicException('提交后操作要求最外层使用 DB::transaction，不能使用 PDO 手工事务');
        }
        self::$commitCallbacks[array_key_last(self::$commitCallbacks)][] = $callback;
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $savepoint = $pdo->inTransaction() ? 'pafish_tx_' . ++self::$savepointSequence : null;
        if ($savepoint !== null) {
            $pdo->query('SAVEPOINT ' . $savepoint);
        } else {
            $pdo->beginTransaction();
        }
        if (self::$commitCallbacks === []) self::$ownsTransaction = $savepoint === null;
        self::$commitCallbacks[] = [];
        try {
            $result = $fn();
            if (!$pdo->inTransaction()) {
                throw new \RuntimeException('业务事务已被提前结束；事务内不能执行 DDL 或手工提交');
            }
            if ($savepoint !== null) {
                $pdo->query('RELEASE SAVEPOINT ' . $savepoint);
            } else {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            array_pop(self::$commitCallbacks);
            if (self::$commitCallbacks === []) self::$ownsTransaction = false;
            try {
                if ($pdo->inTransaction()) {
                    if ($savepoint !== null) {
                        $pdo->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
                        $pdo->query('RELEASE SAVEPOINT ' . $savepoint);
                    } else {
                        $pdo->rollBack();
                    }
                }
            } catch (\Throwable) {
                // InnoDB can already have rolled back the whole transaction (deadlock).
                // Preserve the actual failure rather than replacing it with missing SAVEPOINT.
            }
            throw $e;
        }
        $callbacks = array_pop(self::$commitCallbacks);
        if (self::$commitCallbacks !== []) {
            $parent = array_key_last(self::$commitCallbacks);
            array_push(self::$commitCallbacks[$parent], ...$callbacks);
        } else {
            self::$ownsTransaction = false;
            // 提交已成功，回调失败不能谎报业务回滚，也不能阻止后续失效回调。
            foreach ($callbacks as $callback) {
                try {
                    $callback();
                } catch (\Throwable $e) {
                    try {
                        Logger::error('[pafish-db] 提交后操作失败：' . $e->getMessage());
                    } catch (\Throwable) {
                        // 日志故障也不能改变已经完成的提交或阻断其他回调。
                    }
                }
            }
        }
        return $result;
    }
}
