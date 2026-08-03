<?php

declare(strict_types=1);

use Pafish\Core\Url;

/**
 * 前端控制器：装配应用并运行
 * 伪静态（.htaccess / nginx try_files）与查询串（index.php?p=xxx）两种模式共用
 */

require __DIR__ . '/app/bootstrap.php';
$app->run();
