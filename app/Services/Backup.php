<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Config;
use Pafish\Core\DB;
use PDO;

/**
 * 数据备份（对齐 Node src/lib/backup.ts + 超集）：
 * - 目录：项目根 backups/，文件名 backup-YYYYMMDD-HHmmss.sql / upload-YYYYMMDD-HHmmss.sql
 * - 导出：优先 mysqldump CLI（--single-transaction --routines --triggers，MYSQL_PWD 传密码）；
 *   不可用（虚拟主机无 shell）时纯 PHP 逐表导出兜底（SET FOREIGN_KEY_CHECKS=0 + DROP + CREATE + INSERT）
 * - 恢复：优先 mysql CLI 管道喂入；否则纯 PHP 逐语句解析（支持 DELIMITER 块）
 * - 上传：≤200MB，仅 .sql，头部 4096 字节须含 CREATE TABLE|INSERT INTO|mysqldump（对齐 Node）
 * - upload-* 文件不可删除（对齐 Node）
 */
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

    /** 列表（mtime 倒序，对齐 Node listBackups） */
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

    /** 生成不冲突的文件名（同分钟多次创建时追加 -N，避免安全备份覆盖原备份） */
    private static function uniqueName(string $prefix): string
    {
        $base = $prefix . self::stamp();
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
        if (self::mysqlDumpAvailable()) {
            $dump = self::dumpCommand();
            $cmd = $dump . ' --single-transaction --routines --triggers'
                . ' --default-character-set=utf8mb4 --result-file=' . escapeshellarg($path)
                . ' ' . escapeshellarg(self::dbName());
            self::run($cmd, '备份失败');
        } else {
            $sql = self::exportPhp();
            if (@file_put_contents($path, $sql) === false) {
                throw new \RuntimeException('无法写入备份文件（请检查 backups 目录权限）');
            }
        }
        if (!is_file($path) || (int) @filesize($path) === 0) {
            throw new \RuntimeException('备份失败：未生成有效文件');
        }
        return $file;
    }

    /** 恢复备份（覆盖当前数据）；恢复前自动创建安全备份；返回 [safety] */
    public static function restore(string $file): string
    {
        $path = self::resolve($file);
        $safety = self::create(); // 先留底（对齐 Node restoreBackup）
        if (self::mysqlCliAvailable()) {
            self::runWithInput(self::cliCommand(), $path, '恢复失败');
        } else {
            self::restorePhp($path);
        }
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

    // ---------- mysqldump / mysql CLI ----------

    /** 探测 mysqldump 可执行文件（PATH + Windows 常见安装目录） */
    public static function dumpCommand(): string
    {
        $candidates = ['mysqldump', 'mysqldump.exe'];
        $paths = ['C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin', 'C:\\Program Files\\MySQL\\MySQL Server 8.4\\bin'];
        foreach ($candidates as $name) {
            $found = self::findBin($name, $paths);
            if ($found !== null) {
                return $found;
            }
        }
        return 'mysqldump';
    }

    private static function cliCommand(): string
    {
        $candidates = ['mysql', 'mysql.exe'];
        $paths = ['C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin', 'C:\\Program Files\\MySQL\\MySQL Server 8.4\\bin'];
        foreach ($candidates as $name) {
            $found = self::findBin($name, $paths);
            if ($found !== null) {
                return $found;
            }
        }
        return 'mysql';
    }

    private static function findBin(string $name, array $extraPaths): ?string
    {
        $check = [$name];
        foreach ($extraPaths as $p) {
            $check[] = rtrim($p, '\\/') . '\\' . $name;
        }
        foreach ($check as $cand) {
            $out = [];
            $code = 1;
            @exec(escapeshellarg($cand) . ' --version 2>&1', $out, $code);
            if ($code === 0) {
                return $cand;
            }
        }
        return null;
    }

    private static function mysqlDumpAvailable(): bool
    {
        return self::findBin('mysqldump', ['C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin', 'C:\\Program Files\\MySQL\\MySQL Server 8.4\\bin']) !== null;
    }

    private static function mysqlCliAvailable(): bool
    {
        return self::findBin('mysql', ['C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin', 'C:\\Program Files\\MySQL\\MySQL Server 8.4\\bin']) !== null;
    }

    /** 执行命令；失败抛异常（stderr 截断 200 字符，对齐 Node） */
    private static function run(string $cmd, string $failMsg): void
    {
        $c = Config::get('db', []);
        putenv('MYSQL_PWD=' . (string) ($c['password'] ?? ''));
        $cmd = sprintf(
            '%s --host=%s --port=%d --user=%s --default-character-set=utf8mb4',
            $cmd,
            escapeshellarg($c['host'] ?? '127.0.0.1'),
            (int) ($c['port'] ?? 3306),
            escapeshellarg($c['username'] ?? 'root')
        );
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new \RuntimeException($failMsg . '（退出码 ' . $code . '）：' . mb_substr(implode("\n", $out), 0, 200));
        }
    }

    /** 执行命令并从文件喂 stdin（mysql 恢复）。
     * Windows 上 proc_open 不经过 cmd.exe、对 escapeshellarg 引号解析有 bug（尾参数引号丢失），
     * 统一用 shell 重定向 < file 喂入（cmd.exe 与 Linux sh 均支持）。 */
    private static function runWithInput(string $cmd, string $inputFile, string $failMsg): void
    {
        $c = Config::get('db', []);
        putenv('MYSQL_PWD=' . (string) ($c['password'] ?? ''));
        $cmd = sprintf(
            '%s --host=%s --port=%d --user=%s --default-character-set=utf8mb4 %s < %s',
            $cmd,
            escapeshellarg($c['host'] ?? '127.0.0.1'),
            (int) ($c['port'] ?? 3306),
            escapeshellarg($c['username'] ?? 'root'),
            escapeshellarg(self::dbName()),
            escapeshellarg($inputFile)
        );
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new \RuntimeException($failMsg . '（退出码 ' . $code . '）：' . mb_substr(implode("\n", $out), 0, 200));
        }
    }

    private static function dbName(): string
    {
        return (string) (Config::get('db', [])['database'] ?? '');
    }

    // ---------- 纯 PHP 兜底 ----------

    /** 逐表导出为 SQL（无 mysqldump 环境时的兜底） */
    private static function exportPhp(): string
    {
        $pdo = DB::pdo();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $out = "-- pafish 数据备份（纯 PHP 导出）\n";
        $out .= "-- 生成时间：" . date('Y-m-d H:i:s') . "\n\n";
        $out .= "SET NAMES utf8mb4;\n";
        $out .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        foreach ($tables as $table) {
            $out .= "-- ---- 表：{$table} ----\n";
            $out .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            $out .= ($create['Create Table'] ?? '') . ";\n\n";
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                continue;
            }
            $cols = array_keys($rows[0]);
            $quoted = array_map(static fn (string $c): string => '`' . $c . '`', $cols);
            $out .= 'INSERT INTO `' . $table . '` (' . implode(', ', $quoted) . ") VALUES\n";
            $lines = [];
            foreach ($rows as $row) {
                $vals = [];
                foreach ($cols as $col) {
                    $vals[] = self::quote($row[$col]);
                }
                $lines[] = '(' . implode(', ', $vals) . ')';
            }
            $out .= implode(",\n", $lines) . ";\n\n";
        }
        $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        return $out;
    }

    private static function quote(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        return "'" . str_replace(["\\", "'", "\0"], ["\\\\", "\\'", ''], (string) $v) . "'";
    }

    /** 逐语句导入（无 mysql CLI 时的兜底；支持 DELIMITER 块与行注释）。
     * 语句结束判定需跟踪引号状态——内容里的分号（如 Markdown 代码块）不能提前拆句。 */
    private static function restorePhp(string $file): void
    {
        $pdo = DB::pdo();
        $fp = @fopen($file, 'r');
        if ($fp === false) {
            throw new \RuntimeException('恢复失败：无法读取备份文件');
        }
        $delimiter = ';';
        $stmt = '';
        $count = 0;
        while (($line = fgets($fp)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue; // 空行与行注释
            }
            if (str_starts_with($trimmed, '/*') && !str_starts_with($trimmed, '/*!')) {
                continue; // 块注释；/*! 是版本条件注释，内容需执行（routines/triggers 依赖）
            }
            if (preg_match('/^DELIMITER\s+(\S+)/i', $trimmed, $m)) {
                if (trim($stmt) !== '') {
                    $pdo->exec(trim($stmt));
                    $count++;
                }
                $delimiter = $m[1];
                $stmt = '';
                continue;
            }
            $stmt .= $line;
            if (self::endsWithDelimiter($stmt, $delimiter)) {
                // 精确截除分隔符；rtrim(..., $delimiter) 会把多字符分隔符当字符集
                // 逐字符误裁（如内容以 $ 结尾的 DELIMITER $$ 语句）
                $sql = substr(trim($stmt), 0, -strlen($delimiter));
                if ($sql !== '') {
                    $pdo->exec($sql);
                    $count++;
                }
                $stmt = '';
            }
        }
        if (trim($stmt) !== '') {
            $pdo->exec(trim($stmt));
            $count++;
        }
        fclose($fp);
    }

    /** 判断语句是否以分隔符结束（扫描引号状态，字符串内的分号不拆句） */
    private static function endsWithDelimiter(string $sql, string $delimiter): bool
    {
        $inSingle = $inDouble = $inBacktick = false;
        $len = strlen($sql);
        $escaped = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($ch === '\\') {
                $escaped = true;
                continue;
            }
            if ($inSingle) {
                if ($ch === "'") {
                    $inSingle = false;
                }
                continue;
            }
            if ($inDouble) {
                if ($ch === '"') {
                    $inDouble = false;
                }
                continue;
            }
            if ($inBacktick) {
                if ($ch === '`') {
                    $inBacktick = false;
                }
                continue;
            }
            if ($ch === "'") {
                $inSingle = true;
            } elseif ($ch === '"') {
                $inDouble = true;
            } elseif ($ch === '`') {
                $inBacktick = true;
            }
        }
        if ($inSingle || $inDouble || $inBacktick) {
            return false; // 字符串未闭合，语句未结束
        }
        return substr(rtrim($sql), -strlen($delimiter)) === $delimiter;
    }
}
