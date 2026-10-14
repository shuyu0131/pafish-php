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

// 1.0.9 起主题必须声明统一 extension.php 入口。在线更新保留已安装
// themes/，旧站点上随旧包安装的 default 没有该文件，升级后会因
// Theme::manifest() 返回 null 而直接失去前台主题；这里只给 default
// 补一个空入口，不触碰其他主题。
$defaultThemeDir = PAFISH_ROOT . '/themes/default';
if (is_dir($defaultThemeDir) && !is_file($defaultThemeDir . '/extension.php')) {
    $stub = <<<'PHP'
<?php

declare(strict_types=1);

use Pafish\Services\ExtensionContext;

// 默认主题无额外业务逻辑；此文件仅满足主题加载器对统一扩展入口的要求。
return static function (ExtensionContext $ctx): void {
};

PHP;
    if (@file_put_contents($defaultThemeDir . '/extension.php', $stub) === false) {
        throw new \RuntimeException('无法写入 default 主题 extension.php');
    }
}

// 核心结构统一由版本化迁移维护；保留本入口兼容旧在线更新流程。
$pdo = $pdo ?? \Pafish\Core\DB::pdo();
$migrationRoot = defined('PAFISH_ROOT') ? PAFISH_ROOT : __DIR__;
require_once $migrationRoot . '/app/Services/Migrator.php';
\Pafish\Services\Migrator::run($pdo, $migrationRoot . '/migrations');
