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
                self::$pdo->exec(sprintf(
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

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
