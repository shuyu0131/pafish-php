<?php

declare(strict_types=1);

/**
 * 定时发布 CLI 入口（对齐 Node src/lib/scheduler.ts，node-cron 每分钟执行 publishScheduledPosts）
 *
 * 用法：
 *   宝塔/系统计划任务：php /path/to/pafish/cron.php（每分钟一次）
 *   或计划任务选「URL 访问」直接请求 https://你的域名/cron.php
 *
 * 无论哪种方式：把已到发布时间的 SCHEDULED 文章转为 PUBLISHED 并输出数量。
 * 即使没有配置计划任务也不会提前泄露定时文章（查询层兜底：前台只显示
 * PUBLISHED 且 published_at <= NOW()；另由前台请求低频触发 Scheduler::maybeRun）。
 */

define('PAFISH_ROOT', __DIR__);

require PAFISH_ROOT . '/vendor/autoload.php';

use Pafish\Core\Config;
use Pafish\Core\DB;
use Pafish\Services\Plugin;
use Pafish\Services\Scheduler;

$out = static function (string $msg): void {
    echo $msg . PHP_EOL;
};

$configFile = PAFISH_ROOT . '/config.php';
if (!is_file($configFile)) {
    $out('[定时发布] 未检测到 config.php，博客尚未安装，跳过本次执行');
    exit(0);
}

Config::load($configFile);
date_default_timezone_set((string) Config::get('timezone', 'Asia/Shanghai'));
mb_internal_encoding('UTF-8');
require PAFISH_ROOT . '/app/Core/helpers.php';

try {
    DB::pdo();
    Plugin::boot();
} catch (Throwable $e) {
    $out('[定时发布] 数据库连接失败：' . $e->getMessage());
    exit(1);
}

try {
    $count = Scheduler::publishDue();
    $out("[定时发布] {$count} 篇文章已发布");
} catch (Throwable $e) {
    $out('[定时发布] 执行失败：' . $e->getMessage());
    exit(1);
}
