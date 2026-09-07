<?php

declare(strict_types=1);

/**
 * 构建发布 zip（预打包 vendor，用户零命令行安装）：
 *   php scripts/build-release.php [--tag=v0.1.0] [--notes=...] [--min=0.1.0]
 * 产物：
 *   dist/pafish-php-{tag}.zip            发布包，顶层目录 pafish/（WordPress 式）
 *   dist/store-php/pafish-php.json       在线更新元数据（version/notes/zip/min_version）
 *   dist/store-php/pafish-php-{tag}.zip  更新包（与发布包同一份）
 * 部署：把 store-php/ 下两个文件上传到官网 public/pafish-php/（www.pafish.cn），
 * 更新系统按元数据目录解析 zip 相对路径。notes 默认取自 CHANGELOG.md 的「## {tag}」小节。
 *
 * 排除：.git、本地 config.php、运行时产物（runtime/*、public/uploads/*、backups/*）、
 * 测试文件、编辑器文件。vendor/ 完整打包。
 */

$root = dirname(__DIR__);

$tag = 'v0.1.0';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--tag=')) {
        $tag = substr($arg, 6);
    }
}

if (!extension_loaded('zip')) {
    fwrite(STDERR, "需要 PHP zip 扩展\n");
    exit(1);
}

// ---- 版本同步：唯一维护点 app/Core/Version.php 常量，自动写回 composer.json（防元数据漂移） ----
$versionSrc = (string) file_get_contents($root . '/app/Core/Version.php');
if (preg_match("/VERSION\s*=\s*'([^']+)'/", $versionSrc, $m) !== 1 || $m[1] === '') {
    fwrite(STDERR, "无法读取 app/Core/Version.php 的 VERSION 常量\n");
    exit(1);
}
$codeVersion = $m[1];
$composerPath = $root . '/composer.json';
$composer = json_decode((string) file_get_contents($composerPath), true);
if (!is_array($composer)) {
    fwrite(STDERR, "composer.json 解析失败\n");
    exit(1);
}
if (($composer['version'] ?? '') !== $codeVersion) {
    $composer['version'] = $codeVersion;
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo "[ok] composer.json version 同步为 {$codeVersion}\n";
} else {
    echo "[ok] composer.json version 已一致（{$codeVersion}）\n";
}

// ---- 迁移基线一致性：migrations/0001_initial.sql 与 app/install/schema.sql 必须同步 ----
// （忽略注释行/空行后比较结构——0001 文件头允许附迁移机制说明注释）
$migrationBase = $root . '/migrations/0001_initial.sql';
$schemaFile = $root . '/app/install/schema.sql';
if (is_file($migrationBase) && is_file($schemaFile)) {
    $stripSql = static fn (string $sql): string => implode("\n", array_filter(
        preg_split('/\r?\n/', $sql) ?: [],
        static fn (string $line): bool => trim($line) !== '' && !str_starts_with(ltrim($line), '--')
    ));
    if ($stripSql((string) file_get_contents($migrationBase)) !== $stripSql((string) file_get_contents($schemaFile))) {
        fwrite(STDERR, "migrations/0001_initial.sql 与 app/install/schema.sql 结构不一致，请先同步\n");
        exit(1);
    }
}

/** 需要保留的空目录（zip 内建立空目录，运行时自动写入） */
$keepDirs = ['runtime', 'backups', 'public/uploads'];

/** 顶层排除 */
$excludeTops = ['.git', '.gitignore', '.gitattributes', 'config.php', 'dbg_jar.txt'];

/** 任意层级排除的文件名/目录名 */
function isExcluded(string $rel): bool
{
    foreach (['.DS_Store', 'Thumbs.db', '*.log'] as $p) {
        if (fnmatch($p, basename($rel))) {
            return true;
        }
    }
    // runtime 内测试文件与运行期产物
    if (preg_match('#^runtime/#', $rel)) {
        return true;
    }
    if (preg_match('#^public/uploads/#', $rel) || preg_match('#^backups/#', $rel)) {
        return true;
    }
    // 自产调试/测试脚本（scripts/ 下 test*、*_test*、*_dbg*）
    if (preg_match('#scripts/(test|.*_test|.*_dbg)[^/]*\.php$#i', $rel)) {
        return true;
    }
    // 第三方依赖自带的测试目录（tests/）与其配置文件
    if (preg_match('#vendor/[^/]+/[^/]+/tests(/|$)|vendor/[^/]+/[^/]+/phpunit\.xml(\.dist)?$#i', $rel)) {
        return true;
    }
    return false;
}

$outDir = $root . '/dist';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
$outPath = $outDir . '/pafish-php-' . $tag . '.zip';
@unlink($outPath);

$zip = new ZipArchive();
if ($zip->open($outPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "无法写入 {$outPath}\n");
    exit(1);
}

$base = rtrim(str_replace('\\', '/', $root), '/');
$prefix = 'pafish/';
$count = 0;

/** 递归打包（跳过排除项与需要保留空目录的内容） */
$packDir = static function (string $dir, string $zipPrefix) use (&$packDir, $zip, $keepDirs, &$count): void {
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $full = $dir . '/' . $name;
        $rel = $zipPrefix . $name;
        if (in_array($name, ['node_modules', '.next'], true)) {
            continue;
        }
        if (is_dir($full)) {
            if (isExcluded($rel)) {
                continue;
            }
            // 保留空目录结构（运行时写入），但打包空目录本身
            $zip->addEmptyDir($rel);
            $count++;
            $packDir($full, $rel . '/');
        } else {
            if (isExcluded($rel)) {
                continue;
            }
            $zip->addFile($full, $rel);
            $count++;
        }
    }
};

// 顶层文件与目录（vendor 在列表内，完整打包）
$topItems = [
    'app', 'admin', 'themes', 'plugins', 'public', 'migrations', 'docs', 'scripts',
    'vendor', 'backups', 'runtime',
    'index.php', 'install.php', 'cron.php', 'router.php', 'upgrade.php', '.htaccess',
    'composer.json', 'composer.lock', 'config.example.php', 'README.md', 'CHANGELOG.md',
];
$zip->addEmptyDir('pafish/');
$count++;
foreach ($topItems as $item) {
    $full = $root . '/' . $item;
    if (in_array($item, $excludeTops, true)) {
        continue;
    }
    if (is_dir($full)) {
        if (in_array($item, $keepDirs, true)) {
            // 保留空目录（不打包其中内容）
            $zip->addEmptyDir($prefix . $item);
            $count++;
        } else {
            $zip->addEmptyDir($prefix . $item);
            $count++;
            $packDir($full, $prefix . $item . '/');
        }
    } elseif (is_file($full)) {
        $zip->addFile($full, $prefix . $item);
        $count++;
    }
}

// CHANGELOG 可能不存在：补一个占位说明
if (!is_file($root . '/CHANGELOG.md')) {
    $zip->addFromString($prefix . 'CHANGELOG.md', "# 变更日志\n\n## {$tag}\n\n- pafish（PHP 版）发布。\n");
}

$zip->close();

$sizeMb = round(filesize($outPath) / 1024 / 1024, 2);
echo "[ok] {$outPath}（{$count} 个条目，{$sizeMb} MB）\n";

// 摘要：vendor 是否完整、有无敏感文件泄漏
$z = new ZipArchive();
$z->open($outPath);
$entries = [];
for ($i = 0; $i < $z->numFiles; $i++) {
    $entries[] = $z->getNameIndex($i);
}
$z->close();
$leaks = array_values(array_filter($entries, static fn (string $e): bool =>
    str_contains($e, 'config.php') || str_contains($e, '.git/') || str_contains($e, 'dbg_jar')
));
echo "vendor 条目数：" . count(array_filter($entries, static fn (string $e): bool => str_contains($e, 'pafish/vendor/'))) . "\n";
echo $leaks === [] ? "敏感文件检查：无泄漏\n" : "警告：发现疑似泄漏条目：\n" . implode("\n", array_slice($leaks, 0, 10)) . "\n";

// ---- 更新元数据（托管到官网 www.pafish.cn/pafish-php/，静态文件，不改官网代码） ----
// 在线更新协议：GET {base}/pafish-php/pafish-php.json → { version, notes, zip, min_version }
// zip 为相对路径，基于元数据 URL 目录解析；zip 与发布包同一份（顶层 pafish/）
$notes = '';
if (is_file($root . '/CHANGELOG.md')) {
    $changelog = (string) file_get_contents($root . '/CHANGELOG.md');
    // 提取「## {tag}」小节正文（标题行可带日期，到下一个 ## 或文末）
    if (preg_match('/^##\s+' . preg_quote($tag, '/') . '[^\n]*\n(.*?)(?=^##\s|\z)/ms', $changelog, $m)) {
        $notes = trim($m[1]);
    }
}
$minVer = '0.1.0';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--notes=')) {
        $notes = substr($arg, 8);
    } elseif (str_starts_with($arg, '--min=')) {
        $minVer = substr($arg, 6);
    }
}
$storeDir = $outDir . '/store-php';
if (!is_dir($storeDir)) {
    mkdir($storeDir, 0755, true);
}
$meta = [
    'version' => ltrim($tag, 'v'),
    'notes' => $notes,
    'zip' => 'pafish-php-' . $tag . '.zip',
    'sha256' => hash_file('sha256', $outPath),
    'min_version' => $minVer,
];
file_put_contents(
    $storeDir . '/pafish-php.json',
    json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);
copy($outPath, $storeDir . '/' . $meta['zip']);
echo "[ok] {$storeDir}/pafish-php.json（version {$meta['version']}，min {$minVer}）\n";
echo "[ok] {$storeDir}/{$meta['zip']}（" . round(filesize($storeDir . '/' . $meta['zip']) / 1024 / 1024, 2) . " MB）\n";
echo "部署：将 store-php/ 下两个文件上传到官网 public/pafish-php/ 目录\n";
