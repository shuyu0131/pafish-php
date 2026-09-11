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

// 入口路径归一化：支持根目录/子目录、index.php?p=、index.php/路径三种形式。
$__scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
$__scriptPath = parse_url($__scriptName, PHP_URL_PATH) ?: '/index.php';
$__scriptPath = '/' . ltrim(str_replace('\\', '/', (string) $__scriptPath), '/');
$__base = '';
if (preg_match('#^(.*?)/index\\.php(?:/.*)?$#i', $__scriptPath, $__scriptMatch) === 1) {
    $__base = rtrim((string) ($__scriptMatch[1] ?? ''), '/');
}
$__normalizePath = static function (string $path, ?string $queryPath = null) use ($__base): array {
    $path = rawurldecode(parse_url($path, PHP_URL_PATH) ?: '/');
    $path = '/' . ltrim($path, '/');

    $relative = $path;
    if ($__base !== '' && ($relative === $__base || str_starts_with($relative, $__base . '/'))) {
        $relative = substr($relative, strlen($__base));
        $relative = $relative === '' ? '/' : $relative;
    }
    $relative = '/' . ltrim($relative, '/');
    $frontController = $relative === '/' || $relative === '/index.php';

    if ($relative === '/index.php') {
        $pathInfo = $_SERVER['PATH_INFO'] ?? $_SERVER['ORIG_PATH_INFO'] ?? '';
        if (is_string($pathInfo) && $pathInfo !== '' && $pathInfo !== '/index.php') {
            $pathInfo = rawurldecode(parse_url($pathInfo, PHP_URL_PATH) ?: '');
            $relative = str_starts_with($pathInfo, '/index.php/')
                ? substr($pathInfo, strlen('/index.php'))
                : '/' . ltrim($pathInfo, '/');
            $relative = $relative === '' ? '/' : $relative;
            $frontController = false;
        } else {
            $relative = '/';
        }
    } elseif (str_starts_with($relative, '/index.php/')) {
        $relative = substr($relative, strlen('/index.php')) ?: '/';
        $frontController = false;
    }

    if ($frontController && is_string($queryPath) && $queryPath !== '') {
        $relative = '/' . ltrim(rawurldecode($queryPath), '/');
        $relative = $relative === '' ? '/' : $relative;
    }

    $canonical = $__base . ($relative === '/' ? '/' : $relative);
    return [$canonical === '' ? '/' : $canonical, $relative];
};

// 静态资源在框架启动前直出；子目录部署时匹配已去掉站点前缀。
$__requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$__queryPath = $_GET['p'] ?? null;
[, $__path] = $__normalizePath(
    (string) $__requestPath,
    is_string($__queryPath) ? $__queryPath : null
);
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
    // 某些 Nginx 配置通过 error_page 404 回退到 index.php，
    // 此时 PHP 进程会继承上游 404；文件已找到时必须明确恢复成功状态。
    http_response_code(200);
    $__types = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
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
        'otf' => 'font/otf',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
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

// 主题资源直出，并兼容旧资源地址。
if (str_starts_with($__path, '/themes/') || str_starts_with($__path, '/theme-assets/')) {
    $__prefix = str_starts_with($__path, '/theme-assets/') ? '/theme-assets/' : '/themes/';
    $__assetPath = trim(substr($__path, strlen($__prefix)), '/');
    [$__theme, $__requested] = array_pad(explode('/', $__assetPath, 2), 2, '');
    $__requested = trim((string) $__requested, '/');
    $__relative = str_starts_with($__requested, 'assets/')
        ? substr($__requested, 7)
        : $__requested;
    $__types = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
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
        'otf' => 'font/otf',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'json' => 'application/json; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml',
        'webmanifest' => 'application/manifest+json; charset=utf-8',
        'wasm' => 'application/wasm',
    ];
    $__ext = strtolower(pathinfo($__relative, PATHINFO_EXTENSION));
    if (
        preg_match('/^[a-z0-9_-]{1,50}$/', $__theme) !== 1
        || $__relative === ''
        || !isset($__types[$__ext])
        || str_contains($__relative, '..')
        || str_contains($__relative, "\0")
        || str_contains($__relative, '\\')
        || str_contains($__relative, '//')
    ) {
        http_response_code(404);
        exit;
    }
    $__candidates = str_starts_with($__requested, 'assets/')
        ? ['assets/' . $__relative, $__relative]
        : [$__relative, 'assets/' . $__relative];
    $__file = null;
    foreach (array_values(array_unique($__candidates)) as $__candidate) {
        $__candidateFile = PAFISH_ROOT . '/themes/' . $__theme . '/' . $__candidate;
        if (is_file($__candidateFile)) {
            $__file = $__candidateFile;
            break;
        }
    }
    if ($__file === null) {
        http_response_code(404);
        exit;
    }
    // 兼容 error_page 404 → index.php 的主机配置，避免资源正文带着 404 状态返回。
    http_response_code(200);
    header('Content-Type: ' . $__types[$__ext]);
    header('Content-Length: ' . (string) filesize($__file));
    header('Cache-Control: public, max-age=86400');
    readfile($__file);
    exit;
}

// 1. Composer 自动加载（发布包已包含 vendor）
require PAFISH_ROOT . '/vendor/autoload.php';

// 2. 配置：未安装（无 config.php）时引导到安装向导
$configFile = PAFISH_ROOT . '/config.php';
if (!is_file($configFile)) {
    header('Location: ' . ($__base !== '' ? $__base : '') . '/install.php');
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

// 6. 中间件：请求体解析 + 入口路径归一化
$app->addBodyParsingMiddleware();
$app->add(function ($request, $handler) use ($__normalizePath) {
    $uri = $request->getUri();
    [$canonical] = $__normalizePath(
        $uri->getPath(),
        is_string($_GET['p'] ?? null) ? $_GET['p'] : null
    );
    if ($canonical !== $uri->getPath()) {
        $request = $request->withUri($uri->withPath($canonical));
    }
    return $handler->handle($request);
});

// 7. 错误处理：自定义 404/500（不输出异常详情，规避 Slim 默认 HtmlErrorRenderer）
$errorMiddleware = $app->addErrorMiddleware((bool) Config::get('debug', false), true, true);
$errorMiddleware->setDefaultErrorHandler(
    new ErrorHandler($app->getCallableResolver(), $app->getResponseFactory())
);

// 7.5 插件系统启动：注册激活插件的钩子 + 系统注入渲染器 + 云存储管线
// 定时任务失败不阻断前台请求。
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
