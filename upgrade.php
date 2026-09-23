<?php

declare(strict_types=1);

// 兼容旧版本升级时的主题恢复包。
$luminaRecovery = PAFISH_ROOT . '/runtime/.pafish-lumina-recovery.zip';
if (is_file($luminaRecovery) && !is_dir(PAFISH_ROOT . '/themes/lumina')) {
    $activeTheme = (string) \Pafish\Services\Settings::get('active_theme', 'default');
    if ($activeTheme === 'lumina') {
        $zip = new \ZipArchive();
        if ($zip->open($luminaRecovery) !== true || !$zip->extractTo(PAFISH_ROOT . '/themes')) {
            throw new \RuntimeException('主题恢复失败');
        }
        $zip->close();
        if (!is_file(PAFISH_ROOT . '/themes/lumina/theme.json')) {
            throw new \RuntimeException('主题恢复包不完整');
        }
    }
}
@unlink($luminaRecovery);

// 核心结构统一由版本化迁移维护；保留本入口兼容旧在线更新流程。
$pdo = $pdo ?? \Pafish\Core\DB::pdo();
$migrationRoot = defined('PAFISH_ROOT') ? PAFISH_ROOT : __DIR__;
require_once $migrationRoot . '/app/Services/Migrator.php';
\Pafish\Services\Migrator::run($pdo, $migrationRoot . '/migrations');
