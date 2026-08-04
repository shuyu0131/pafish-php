<?php

declare(strict_types=1);

use Pafish\Core\Config;
use Pafish\Core\ErrorHandler;
use Pafish\Core\Session;
use Pafish\Core\Url;
use Slim\Factory\AppFactory;

/**
 * 应用装配：配置 → 自动加载 → 会话 → Slim 应用 → 中间件 → 路由
 * 返回 Slim\App 实例
 */

define('PAFISH_ROOT', dirname(__DIR__));

// 1. Composer 自动加载（发布包已包含 vendor）
require PAFISH_ROOT . '/vendor/autoload.php';

// 2. 配置：未安装（无 config.php）时引导到安装向导
$configFile = PAFISH_ROOT . '/config.php';
if (!is_file($configFile)) {
    header('Location: ' . ($_SERVER['SCRIPT_NAME'] ? rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/install.php' : 'install.php'));
    exit;
}
Config::load($configFile);

// 3. 运行环境
date_default_timezone_set((string) Config::get('timezone', 'Asia/Shanghai'));
mb_internal_encoding('UTF-8');
Url::init($_SERVER['SCRIPT_NAME'] ?? '/index.php');

// 4. 会话与全局辅助函数
Session::start();
require __DIR__ . '/Core/helpers.php';

// 5. Slim 应用（PHP-DI 容器，控制器自动装配）
$container = new DI\Container();
AppFactory::setContainer($container);
$app = AppFactory::create();
$app->setBasePath(Url::base());

// 6. 中间件：JSON/表单请求体解析（Slim 4 默认不解析，需显式启用）+ ?p= 查询串兜底路由
$app->addBodyParsingMiddleware();
$app->add(function ($request, $handler) {
    $p = $_GET['p'] ?? null;
    if (is_string($p) && $p !== '') {
        $uri = $request->getUri()->withPath('/' . ltrim($p, '/'));
        $request = $request->withUri($uri);
    }
    return $handler->handle($request);
});

// 7. 错误处理：自定义 404/500（不输出异常详情，规避 Slim 默认 HtmlErrorRenderer）
$errorMiddleware = $app->addErrorMiddleware((bool) Config::get('debug', false), true, true);
$errorMiddleware->setDefaultErrorHandler(
    new ErrorHandler($app->getCallableResolver(), $app->getResponseFactory())
);

// 7.5 插件系统启动：注册激活插件的钩子 + 系统注入渲染器 + 云存储管线
//（PHP 每请求新进程，天然无缓存/节流问题；boot 内部 try/catch，DB 不可用时不阻断前台）
\Pafish\Services\Plugin::boot();

// 7.6 定时发布兜底：无 cron 环境时由请求低频触发（runtime/scheduler.lock 60s 限频），
// 让后台文章状态及时同步；有 cron 时该调用几乎不执行查询。失败不阻断请求。
try {
    \Pafish\Services\Scheduler::maybeRun();
} catch (\Throwable $e) {
    // 定时发布兜底失败不影响本次请求
}

// 8. 路由
require __DIR__ . '/Http/routes.php';

return $app;
