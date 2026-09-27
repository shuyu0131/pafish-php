<?php

declare(strict_types=1);

use Pafish\Core\Url;

/**
 * 前端控制器：装配应用并运行
 * 伪静态（.htaccess / nginx try_files）与查询串（index.php?p=xxx）两种模式共用
 */

ini_set('display_errors', '0');

try {
    require __DIR__ . '/app/bootstrap.php';
    $app->run();
} catch (Throwable $e) {
    // bootstrap 尚未完成时错误中间件还不存在，仍不能把绝对路径回显给访客。
    if (class_exists(\Pafish\Core\Logger::class)) {
        \Pafish\Core\Logger::exception('front controller failure', $e);
    } else {
        $runtime = __DIR__ . '/runtime';
        if (is_dir($runtime) || @mkdir($runtime, 0750, true)) {
            @file_put_contents(
                $runtime . '/error.log',
                '[' . date('Y-m-d H:i:s') . '] front controller failure: ' . $e->getMessage() . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo '服务器内部错误';
}
