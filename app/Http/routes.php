<?php

declare(strict_types=1);

use Pafish\Http\HomeController;
use Pafish\Http\PostController;
use Pafish\Http\CategoryController;
use Pafish\Http\TagController;
use Pafish\Http\ArchiveController;
use Pafish\Http\SearchController;
use Pafish\Http\PageController;
use Pafish\Http\RssController;
use Pafish\Http\SitemapController;
use Pafish\Http\RobotsController;
use Pafish\Http\AuthPageController;
use Pafish\Http\AuthApiController;
use Pafish\Http\CommentApiController;
use Pafish\Admin\AdminAuthMiddleware;
use Pafish\Admin\DashboardController;

/**
 * 路由注册（$app 来自 bootstrap.php include 上下文）
 * 前台路由随里程碑扩展；后台走 /admin 前缀（M3）
 */

$app->get('/', [HomeController::class, 'index']);

// ---- M2：文章详情与互动 ----
$app->get('/post/{slug}', [PostController::class, 'show']);
$app->post('/api/post/{id}/{kind}', [PostController::class, 'toggle']); // kind: like | favorite

// ---- M2：分类 / 标签 / 归档 / 搜索 ----
$app->get('/category/{slug}', [CategoryController::class, 'show']);
$app->get('/tag/{slug}', [TagController::class, 'show']);
$app->get('/archives', [ArchiveController::class, 'index']);
$app->get('/search', [SearchController::class, 'index']);

// ---- M2：独立页面 / RSS / sitemap / robots ----
$app->get('/pages/{slug}', [PageController::class, 'show']);
$app->get('/rss.xml', [RssController::class, 'index']);
$app->get('/sitemap.xml', [SitemapController::class, 'index']);
$app->get('/robots.txt', [RobotsController::class, 'index']);

// ---- M2：登录 / 注册 / 找回密码 ----
$app->get('/login', [AuthPageController::class, 'login']);
$app->get('/register', [AuthPageController::class, 'register']);
$app->get('/forgot-password', [AuthPageController::class, 'forgot']);
$app->get('/reset-password', [AuthPageController::class, 'reset']);
$app->post('/api/auth/login', [AuthApiController::class, 'login']);
$app->post('/api/auth/logout', [AuthApiController::class, 'logout']);
$app->post('/api/auth/send-code', [AuthApiController::class, 'sendCode']);
$app->post('/api/auth/register', [AuthApiController::class, 'register']);
$app->post('/api/auth/reset-by-code', [AuthApiController::class, 'resetByCode']);
$app->post('/api/auth/reset', [AuthApiController::class, 'reset']);
$app->post('/api/auth/forgot', [AuthApiController::class, 'forgot']);

// ---- M2：评论（验证码 / 提交 / 点赞） ----
$app->get('/api/captcha', [CommentApiController::class, 'captcha']);
$app->post('/api/comments', [CommentApiController::class, 'create']);
$app->post('/api/comments/like', [CommentApiController::class, 'like']);

// ---- M3：后台（守卫中间件：未登录跳 /login?from=，POST 校验 CSRF） ----
$app->group('/admin', function ($group) {
    $group->get('', [DashboardController::class, 'dashboard']);
    $group->get('/', [DashboardController::class, 'dashboard']);
    // 后续页面随 M3/M4 里程碑注册
})->add(AdminAuthMiddleware::class);
