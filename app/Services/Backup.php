<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;
use PDO;

/** 纯 PHP 数据库备份、上传、恢复和文件生命周期管理。 */
final class Backup
{
    private const PREFIX_BACKUP = 'backup-';
    private const PREFIX_UPLOAD = 'upload-';
    private const NAME_RE = '/^(backup|upload)-.*\.sql$/';
    private const MAX_UPLOAD = 200 * 1024 * 1024;

    /** 备份目录（不存在则创建） */
    public static function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** 列表（mtime 倒序） */
    public static function list(): array
    {
        $dir = self::dir();
        $items = [];
        foreach ((array) @scandir($dir) as $f) {
            if ($f === '.' || $f === '..' || !preg_match(self::NAME_RE, $f)) {
                continue;
            }
            $path = $dir . '/' . $f;
            $items[] = [
                'file' => $f,
                'mtime' => (int) @filemtime($path),
                'size' => (int) @filesize($path),
            ];
        }
        usort($items, fn (array $a, array $b): int => $b['mtime'] - $a['mtime']);
        return $items;
    }

    /** 生成时间戳（backup-YYYYMMDD-HHmmss） */
    public static function stamp(): string
    {
        return date('Ymd-His');
    }

    /** Timestamp plus random suffix avoids concurrent requests choosing the same target. */
    private static function uniqueName(string $prefix): string
    {
        $base = $prefix . self::stamp() . '-' . bin2hex(random_bytes(6));
        $file = $base . '.sql';
        $i = 1;
        while (is_file(self::dir() . '/' . $file)) {
            $file = $base . '-' . $i . '.sql';
            $i++;
        }
        return $file;
    }

    /** 创建备份；返回文件名；失败抛异常 */
    public static function create(): string
    {
        $file = self::uniqueName(self::PREFIX_BACKUP);
        $path = self::dir() . '/' . $file;
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $stream = @fopen($temp, 'x+b');
        if ($stream === false) throw new \RuntimeException('无法写入备份文件（请检查 backups 目录权限）');
        try {
            self::exportTo($stream);
            if (!fflush($stream)) throw new \RuntimeException('备份文件刷新失败');
            fclose($stream);
            $stream = null;
            if (is_file($path) || !@rename($temp, $path)) throw new \RuntimeException('无法完成备份文件，请重试');
        } finally {
            if (is_resource($stream)) fclose($stream);
            if (is_file($temp)) @unlink($temp);
        }
        return $file;
    }

    /** 恢复备份（覆盖当前数据）；恢复前自动创建安全备份；返回 [safety] */
    public static function restore(string $file): string
    {
        $path = self::resolve($file);
        $safety = self::create(); // 先留底
        // 与 create() 保持一致，恢复也不能依赖 mysql CLI 或 exec()。
        self::restorePhp($path);
        return $safety;
    }

    /** 删除备份文件（upload-* 不可删） */
    public static function delete(string $file): void
    {
        $path = self::resolve($file);
        if (str_starts_with($file, self::PREFIX_UPLOAD)) {
            throw new \RuntimeException('上传的恢复文件不可直接删除，请恢复后手动清理');
        }
        if (!@unlink($path)) {
            throw new \RuntimeException('删除失败：文件不存在或不可写');
        }
    }

    /** 保存上传的 SQL 文件（upload-*.sql） */
    public static function saveUploaded(string $tmpPath, string $originalName): string
    {
        if (!preg_match('/\.sql$/i', $originalName)) {
            throw new \RuntimeException('仅支持 .sql 文件');
        }
        if ((int) @filesize($tmpPath) > self::MAX_UPLOAD) {
            throw new \RuntimeException('文件超过 200MB 限制');
        }
        $head = (string) @file_get_contents($tmpPath, false, null, 0, 4096);
        if ($head === '' || !preg_match('/CREATE TABLE|INSERT INTO|mysqldump/i', $head)) {
            throw new \RuntimeException('文件内容不是有效的 SQL 备份');
        }
        $file = self::uniqueName(self::PREFIX_UPLOAD);
        if (!@rename($tmpPath, self::dir() . '/' . $file)) {
            if (!@copy($tmpPath, self::dir() . '/' . $file)) {
                throw new \RuntimeException('保存上传文件失败');
            }
        }
        return $file;
    }

    /** 解析文件名（防目录穿越 + 白名单前缀）；不存在抛「备份文件不存在」 */
    public static function resolve(string $file): string
    {
        $name = basename(trim($file));
        if (!preg_match(self::NAME_RE, $name)) {
            throw new \RuntimeException('非法的备份文件名');
        }
        $path = self::dir() . '/' . $name;
        if (!is_file($path)) {
            throw new \RuntimeException('备份文件不存在');
        }
        return $path;
    }

    /** Core + declared/registered extension tables; excludes unrelated shared-database tables. */
    private static function tableNames(): array
    {
        preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)/', (string) file_get_contents(dirname(__DIR__) . '/install/schema.sql'), $matches);
        $names = array_fill_keys(array_merge($matches[1], ['schema_migrations']), true);
        foreach (DB::fetchAll("SELECT data_key FROM extension_storage WHERE extension_type='system' AND extension_name='table_owners'") as $row) $names[(string) $row['data_key']] = true;
        // Disabled extensions and legacy installs may not yet have table_owners entries.
        foreach (['plugin'=>'plugins', 'theme'=>'themes'] as $kind=>$dir) {
            foreach (glob(dirname(__DIR__, 2) . '/' . $dir . '/*/' . $kind . '.json') ?: [] as $file) {
                $name = basename(dirname($file));
                $manifest = json_decode((string) file_get_contents($file), true);
                if (!is_array($manifest) || ExtensionSchema::validateDeclaration($manifest['schema'] ?? null, $kind, $name) !== null) continue;
                foreach ($manifest['schema']['tables'] ?? [] as $table) $names[ExtensionSchema::physicalName($kind, $name, $table['name'])] = true;
            }
        }
        return array_keys($names);
    }

    /** @param resource $stream */
    private static function exportTo($stream, ?array $names = null): void
    {
        $pdo = DB::pdo();
        if ($pdo->inTransaction()) throw new \RuntimeException('不能在业务事务内创建数据库备份');
        $tables = [];
        foreach ($names ?? self::tableNames() as $table) {
            if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $table)) throw new \RuntimeException('备份表名无效');
            $stmt = $pdo->prepare("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE='BASE TABLE'");
            $stmt->execute([$table]);
            $engine = $stmt->fetchColumn();
            $stmt->closeCursor();
            if ($engine === false) continue;
            if (strcasecmp((string) $engine, 'InnoDB') !== 0) throw new \RuntimeException('一致性备份要求 InnoDB 表：' . $table);
            $tables[] = $table;
        }
        $write = static function (string $value) use ($stream): void {
            for ($offset=0,$length=strlen($value);$offset<$length;) {
                $n = fwrite($stream, substr($value, $offset));
                if ($n === false || $n === 0) throw new \RuntimeException('写入备份失败（磁盘满或不可写）');
                $offset += $n;
            }
        };
        $bufferAttribute = defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY')
            ? constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY')
            : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
        $buffered = (bool) $pdo->getAttribute($bufferAttribute);
        try {
            $pdo->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            $write("-- pafish 本站核心及已登记/声明扩展表备份\n-- 生成时间：" . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET @PAFISH_OLD_SQL_MODE=@@SESSION.sql_mode;\nSET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO';\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            foreach ($tables as $table) {
                $ident = chr(96) . $table . chr(96);
                $create = $pdo->query('SHOW CREATE TABLE ' . $ident)->fetch(PDO::FETCH_ASSOC);
                if (!isset($create['Create Table'])) throw new \RuntimeException('无法读取表结构：' . $table);
                $columns = $pdo->query('SHOW COLUMNS FROM ' . $ident)->fetchAll(PDO::FETCH_ASSOC);
                $columns = array_values(array_filter($columns, static fn (array $c): bool => preg_match('/(?:VIRTUAL|STORED) GENERATED/i', $c['Extra']) !== 1));
                $cols = array_column($columns, 'Field');
                $binary = [];
                foreach ($columns as $column) if (preg_match('/^(?:binary|varbinary|tinyblob|blob|mediumblob|longblob|bit)\b/i', $column['Type'])) $binary[$column['Field']] = true;
                $list = implode(', ', array_map(static fn (string $c): string => chr(96) . str_replace(chr(96), chr(96).chr(96), $c) . chr(96), $cols));
                $write('-- ---- 表：' . $table . " ----\nDROP TABLE IF EXISTS " . $ident . ";\n" . $create['Create Table'] . ";\n");
                $pdo->setAttribute($bufferAttribute, false);
                $rows = $pdo->query('SELECT ' . $list . ' FROM ' . $ident);
                try {
                    while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
                        $vals = array_map(static fn (string $col): string => self::quote($row[$col], isset($binary[$col])), $cols);
                        $write('INSERT INTO ' . $ident . ' (' . $list . ') VALUES (' . implode(', ', $vals) . ");\n");
                    }
                } finally {
                    $rows->closeCursor();
                    $pdo->setAttribute($bufferAttribute, $buffered);
                }
                $write("\n");
            }
            $write("SET FOREIGN_KEY_CHECKS=1;\nSET SESSION sql_mode=@PAFISH_OLD_SQL_MODE;\n");
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally { $pdo->setAttribute($bufferAttribute, $buffered); }
    }

    private static function quote(mixed $v, bool $binary = false): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if ($binary || str_contains((string) $v, "\0")) {
            return "X'" . bin2hex((string) $v) . "'";
        }
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $v) . "'";
    }

    /** DDL restoration is not atomic; the caller retains a safety backup. */
    private static function restorePhp(string $file): void
    {
        $pdo = DB::pdo();
        if ($pdo->inTransaction()) throw new \RuntimeException('不能在业务事务内恢复数据库');
        $fp = @fopen($file, 'rb');
        if ($fp === false) throw new \RuntimeException('恢复失败：无法读取备份文件');
        $state = null;
        $modes = static function () use ($pdo): array {
            $mode = explode(',', strtoupper((string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()));
            return [in_array('NO_BACKSLASH_ESCAPES', $mode, true), in_array('ANSI_QUOTES', $mode, true)];
        };
        try {
            $state = $pdo->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS AS fk, @@SESSION.sql_mode AS mode, @@SESSION.character_set_client AS client, @@SESSION.character_set_results AS results, @@SESSION.collation_connection AS collation')->fetch(PDO::FETCH_ASSOC);
            foreach (\Pafish\Core\SqlScript::read($fp, true, $modes) as $sql) $pdo->query($sql);
        } finally {
            fclose($fp);
            try {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if (is_array($state)) {
                    $pdo->query('SET SESSION FOREIGN_KEY_CHECKS=' . (int) $state['fk']);
                    foreach (['sql_mode'=>'mode', 'character_set_client'=>'client', 'character_set_results'=>'results', 'collation_connection'=>'collation'] as $var=>$key) {
                        $pdo->query('SET SESSION ' . $var . '=' . ($state[$key] === null ? 'NULL' : $pdo->quote((string) $state[$key])));
                    }
                }
            } finally {
                Settings::reset();
                \Pafish\Core\Cache::clear();
            }
        }
    }
}
