<?php

declare(strict_types=1);

/**
 * 构建内置应用商店源（对齐 Node scripts/build-store.cjs 协议）：
 * 把内置主题/插件打包为 public/store/*.zip，并生成 themes.json / plugins.json。
 * 幂等，可重复执行。zip 顶层目录 = 包名（validateZip 要求唯一顶层目录 = 名称）。
 * 用法：php scripts/build-store.php
 */

$root = dirname(__DIR__);
$storeDir = $root . '/public/store';
$manifestFile = ['theme' => 'theme.json', 'plugin' => 'plugin.json'];

// [kind, 源目录, 包名]
$packs = [
    ['plugin', $root . '/plugins/hello-pafish', 'hello-pafish'],
];

if (!extension_loaded('zip')) {
    fwrite(STDERR, "需要 PHP zip 扩展\n");
    exit(1);
}

function readManifest(string $dir, string $kind, string $manifestFile): array
{
    $raw = (string) file_get_contents($dir . '/' . $manifestFile);
    $m = json_decode($raw, true);
    if (!is_array($m) || !isset($m['name'], $m['title'], $m['version'])) {
        throw new RuntimeException("manifest 缺少字段：{$dir}/{$manifestFile}");
    }
    return $m;
}

/** 目录 → zip（顶层目录 = 包名），返回绝对路径 */
function packDir(string $dir, string $name, string $outPath): string
{
    if (!is_dir($dir)) {
        throw new RuntimeException("源目录不存在：{$dir}");
    }
    $zip = new ZipArchive();
    if ($zip->open($outPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("无法写入 {$outPath}");
    }
    $base = rtrim(str_replace('\\', '/', $dir), '/');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $count = 0;
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        $rel = substr(str_replace('\\', '/', $file->getPathname()), strlen($base) + 1);
        $entry = $name . '/' . $rel;
        if ($file->isDir()) {
            $zip->addEmptyDir($entry);
        } else {
            $zip->addFile($file->getPathname(), $entry);
        }
        $count++;
    }
    $zip->close();
    if ($count === 0) {
        throw new RuntimeException("目录为空：{$dir}");
    }
    return $outPath;
}

// 幂等：清理旧产物
if (!is_dir($storeDir)) {
    mkdir($storeDir, 0755, true);
}
foreach (glob($storeDir . '/*.{zip,json}', GLOB_BRACE) ?: [] as $f) {
    @unlink($f);
}

$catalogs = ['theme' => [], 'plugin' => []];

foreach ($packs as [$kind, $dir, $name]) {
    $m = readManifest($dir, $kind, $manifestFile[$kind]);
    if (($m['name'] ?? '') !== $name) {
        throw new RuntimeException("manifest name({$m['name']}) 与目录名({$name})不一致");
    }
    $zipPath = $storeDir . '/' . $name . '.zip';
    packDir($dir, $name, $zipPath);

    $catalogs[$kind][] = [
        'name' => $name,
        'title' => (string) ($m['title'] ?? ''),
        'version' => (string) ($m['version'] ?? ''),
        'description' => (string) ($m['description'] ?? ''),
        'author' => (string) ($m['author'] ?? ''),
        'zip' => '/store/' . $name . '.zip',
        'sha256' => hash_file('sha256', $zipPath),
        // preview: 可放 /store/{name}.png 作为缩略图（暂无资源则不输出）
    ];
    echo "[ok] {$kind} {$name} v{$m['version']} → " . ($zipPath) . "\n";
}

foreach (['theme', 'plugin'] as $kind) {
    $jsonPath = $storeDir . '/' . $kind . 's.json';
    file_put_contents($jsonPath, json_encode($catalogs[$kind], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo "[ok] {$jsonPath}（" . count($catalogs[$kind]) . " 条）\n";
}

echo "内置商店构建完成。\n";
