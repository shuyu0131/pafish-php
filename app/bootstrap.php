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

// 1.5 静态资源直出：public/ 下的 css/ js/ uploads/ vendor/ 对外保持根路径，
// 由 PHP 直接输出（Web 服务器静态规则未命中时兜底，功能不受影响）。
// - Apache：.htaccess 的 RewriteRule ^(css|js|uploads)… 已映射到 public/，不会进到这里；
// - Nginx：只需一条 try_files $uri $uri/ /index.php;，静态请求进入框架后由此输出；
// - 本地 php -S：router.php 自带静态映射，同样不会进到这里。
// 放在框架/会话启动之前：静态请求零框架开销（不建 session、不触发插件钩子）。
// 静态文件一律按原内容直出，不会执行其中的 PHP。
$__path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
// 子目录部署（如 /blog/）时资源路径带站点前缀，剥掉后匹配，与 Url::asset() 一致。
// 仅当 SCRIPT_NAME 是入口 index.php 时才计算前缀：php -S 对「目录存在但文件不存在」
// 的路径会把 URI 塞进 SCRIPT_NAME（如 /vendor/xxx.js），此时必须视为根部署，否则会误剥路径。
$__scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$__base = basename($__scriptName) === 'index.php'
    ? (($__d = dirname($__scriptName)) === '/' || $__d === '\\' || $__d === '.' ? '' : rtrim($__d, '/\\'))
    : '';
if ($__base !== '' && str_starts_with($__path, $__base . '/')) {
    $__path = substr($__path, strlen($__base));
}
// asset() 输出 /public/ 前缀：剥掉前缀后匹配（兼容 /css/ 直链与 /public/css/ 两种）
if (str_starts_with($__path, '/public/')) {
    $__path = substr($__path, strlen('/public'));
}
$__dir = null;
foreach (['css', 'js', 'uploads', 'vendor'] as $__candidate) {
    if (str_starts_with($__path, '/' . $__candidate . '/')) {
        $__dir = $__candidate;
        break;
    }
}
if ($__dir !== null) {
    if (str_contains($__path, '..')) {
        http_response_code(400);
        exit;
    }
    $__file = PAFISH_ROOT . '/public' . $__path;
    if (!is_file($__file)) {
        http_response_code(404);
        exit;
    }
    $__types = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'zip' => 'application/zip',
        'txt' => 'text/plain; charset=utf-8',
        'md' => 'text/markdown; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8',
    ];
    $__ext = strtolower(pathinfo($__file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($__types[$__ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($__file));
    header('Cache-Control: ' . ($__dir === 'uploads' ? 'no-cache' : 'public, max-age=86400'));
    readfile($__file);
    exit;
}

// 1. Composer 自动加载（发布包已包含 vendor）
require PAFISH_ROOT . '/vendor/autoload.php';

// 2. 配置：未安装（无 config.php）时引导到安装向导
$configFile = PAFISH_ROOT . '/config.php';
if (!is_file($configFile)) {
    header('Location: ' . ($_SERVER['SCRIPT_NAME'] ? rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/install.php' : 'install.php'));
    exit;
}
Config::load($configFile);

// Apply small, idempotent schema migrations before controllers query new fields.
try {
    \Pafish\Services\Migrator::run(\Pafish\Core\DB::pdo(), PAFISH_ROOT . '/migrations');
} catch (\Throwable $e) {
    // Keep the normal error handler in charge of the request; migration errors are not hidden.
    throw $e;
}

// 3. 运行环境
date_default_timezone_set((string) Config::get('timezone', 'Asia/Shanghai'));
mb_internal_encoding('UTF-8');
Url::init($_SERVER['SCRIPT_NAME'] ?? '/index.php');

// 4. 会话与全局辅助函数
Session::start();
require __DIR__ . '/Core/helpers.php';
\Pafish\Services\Theme::boot();

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
