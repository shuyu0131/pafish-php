<?php

declare(strict_types=1);

/**
 * 构建发布 zip（预打包 vendor，用户零命令行安装）：
 *   php scripts/build-release.php [--tag=v0.1.0]
 * 产物：dist/pafish-php-{tag}.zip，顶层目录 pafish/（WordPress 式，解压后把 pafish/
 * 内容上传到网站根目录即可）。
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
    'index.php', 'install.php', 'cron.php', 'router.php', '.htaccess',
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
