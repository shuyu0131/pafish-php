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
use Pafish\Http\PluginPageController;
use Pafish\Admin\AdminAuthMiddleware;
use Pafish\Admin\DashboardController;
use Pafish\Admin\PostsController;
use Pafish\Admin\PagesController;
use Pafish\Admin\CategoriesController;
use Pafish\Admin\TagsController;
use Pafish\Admin\MediaController;
use Pafish\Admin\CommentsController;
use Pafish\Admin\NotificationsController;
use Pafish\Admin\LinksController;
use Pafish\Admin\NavController;
use Pafish\Admin\WidgetsController;
use Pafish\Admin\SettingsController;
use Pafish\Admin\UsersController;
use Pafish\Admin\ProfileController;
use Pafish\Admin\BackupController;
use Pafish\Admin\AppearanceController;
use Pafish\Admin\PluginsController;
use Pafish\Admin\ApiController;

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

// ---- M5：插件前台页面（/plugin/{name}/{path}，path 缺省 index） ----
$app->get('/plugin/{name}/{path:.*}', [PluginPageController::class, 'show']);

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

    // 文章管理（列表 / 编辑器 / 导入 / 保存 / 单行操作 / 批量）
    $group->get('/posts', [PostsController::class, 'index']);
    $group->get('/posts/import', [PostsController::class, 'importPage']);
    $group->get('/posts/new', [PostsController::class, 'createEditor']);
    $group->get('/posts/{id}/edit', [PostsController::class, 'editEditor']);
    $group->post('/posts/save', [PostsController::class, 'save']);
    $group->post('/posts/{id}/save', [PostsController::class, 'save']);
    $group->post('/posts/{id}/delete', [PostsController::class, 'delete']);
    $group->post('/posts/{id}/restore', [PostsController::class, 'restore']);
    $group->post('/posts/{id}/purge', [PostsController::class, 'purge']);
    $group->post('/posts/batch', [PostsController::class, 'batch']);

    // 页面管理（列表 / 编辑器 / 保存 / 删除 / 设首页）
    $group->get('/pages', [PagesController::class, 'index']);
    $group->get('/pages/new', [PagesController::class, 'createEditor']);
    $group->get('/pages/{id}/edit', [PagesController::class, 'editEditor']);
    $group->post('/pages/save', [PagesController::class, 'save']);
    $group->post('/pages/{id}/save', [PagesController::class, 'save']);
    $group->post('/pages/{id}/delete', [PagesController::class, 'delete']);
    $group->post('/pages/set-home', [PagesController::class, 'setHome']);

    // 分类管理（树列表 / 保存 / 同级移动 / 删除）
    $group->get('/categories', [CategoriesController::class, 'index']);
    $group->post('/categories/save', [CategoriesController::class, 'save']);
    $group->post('/categories/{id}/save', [CategoriesController::class, 'save']);
    $group->post('/categories/{id}/move', [CategoriesController::class, 'move']);
    $group->post('/categories/{id}/delete', [CategoriesController::class, 'delete']);

    // 标签管理（列表 / 保存 / 删除）
    $group->get('/tags', [TagsController::class, 'index']);
    $group->post('/tags/save', [TagsController::class, 'save']);
    $group->post('/tags/{id}/save', [TagsController::class, 'save']);
    $group->post('/tags/{id}/delete', [TagsController::class, 'delete']);

    // 媒体库（列表 48/页 / 删除 / 外部资源）
    $group->get('/uploads', [MediaController::class, 'index']);
    $group->post('/uploads/{id}/delete', [MediaController::class, 'delete']);
    $group->post('/uploads/external', [MediaController::class, 'external']);

    // 评论审核（4 Tab 20/页 / 状态流转 / 管理员回复 / 置顶 / 按 IP 删 / 拉黑）
    $group->get('/comments', [CommentsController::class, 'index']);
    $group->post('/comments/{id}/status', [CommentsController::class, 'status']);
    $group->post('/comments/{id}/reply', [CommentsController::class, 'reply']);
    $group->post('/comments/{id}/pin', [CommentsController::class, 'pin']);
    $group->post('/comments/{id}/delete', [CommentsController::class, 'delete']);
    $group->post('/comments/delete-by-ip', [CommentsController::class, 'deleteByIp']);
    $group->post('/comments/block-ip', [CommentsController::class, 'blockIp']);

    // 通知（20/页 / 全部已读）
    $group->get('/notifications', [NotificationsController::class, 'index']);
    $group->post('/notifications/read-all', [NotificationsController::class, 'readAll']);

    // 友情链接（列表 / 保存 / 删除 / 显隐 / 上下移动）
    $group->get('/links', [LinksController::class, 'index']);
    $group->post('/links/save', [LinksController::class, 'save']);
    $group->post('/links/{id}/save', [LinksController::class, 'save']);
    $group->post('/links/{id}/delete', [LinksController::class, 'delete']);
    $group->post('/links/{id}/toggle', [LinksController::class, 'toggle']);
    $group->post('/links/{id}/move', [LinksController::class, 'move']);

    // 导航菜单（列表 / 保存 / 删除 / 显隐 / 上下移动）
    $group->get('/nav', [NavController::class, 'index']);
    $group->post('/nav/save', [NavController::class, 'save']);
    $group->post('/nav/{id}/save', [NavController::class, 'save']);
    $group->post('/nav/{id}/delete', [NavController::class, 'delete']);
    $group->post('/nav/{id}/toggle', [NavController::class, 'toggle']);
    $group->post('/nav/{id}/move', [NavController::class, 'move']);

    // 侧边栏组件（列表 / 保存 / 删除 / 显隐 / 上下移动）
    $group->get('/widgets', [WidgetsController::class, 'index']);
    $group->post('/widgets/save', [WidgetsController::class, 'save']);
    $group->post('/widgets/{id}/save', [WidgetsController::class, 'save']);
    $group->post('/widgets/{id}/delete', [WidgetsController::class, 'delete']);
    $group->post('/widgets/{id}/toggle', [WidgetsController::class, 'toggle']);
    $group->post('/widgets/{id}/move', [WidgetsController::class, 'move']);

    // 站点设置（7 卡片表单 / 保存 / SMTP 测试 / API Key 重新生成）
    $group->get('/settings', [SettingsController::class, 'index']);
    $group->post('/settings/save', [SettingsController::class, 'save']);
    $group->post('/settings/test-smtp', [SettingsController::class, 'testSmtp']);
    $group->post('/settings/regenerate-key', [SettingsController::class, 'regenerateApiKey']);

    // 用户管理（仅 ADMIN：角色/禁用/重置密码）
    $group->get('/users', [UsersController::class, 'index']);
    $group->post('/users/{id}/role', [UsersController::class, 'updateRole']);
    $group->post('/users/{id}/toggle', [UsersController::class, 'toggleDisabled']);
    $group->post('/users/{id}/reset-password', [UsersController::class, 'resetPassword']);

    // 个人资料（任何登录用户）
    $group->get('/profile', [ProfileController::class, 'index']);
    $group->post('/profile/save', [ProfileController::class, 'save']);
    $group->post('/profile/password', [ProfileController::class, 'changePassword']);

    // 数据备份（仅 ADMIN：创建/上传/下载/恢复/删除）
    $group->get('/backup', [BackupController::class, 'index']);
    $group->get('/backup/download', [BackupController::class, 'download']);
    $group->post('/backup/create', [BackupController::class, 'create']);
    $group->post('/backup/upload', [BackupController::class, 'upload']);
    $group->post('/backup/restore', [BackupController::class, 'restore']);
    $group->post('/backup/delete', [BackupController::class, 'delete']);

    // 主题与外观（ADMIN+EDITOR：列表/启用/保存设置/安装 zip/卸载/导入导出）
    $group->get('/appearance', [AppearanceController::class, 'index']);
    $group->get('/appearance/export', [AppearanceController::class, 'export']);
    $group->get('/appearance/{name}', [AppearanceController::class, 'settings']);
    $group->post('/appearance/save', [AppearanceController::class, 'save']);
    $group->post('/appearance/activate', [AppearanceController::class, 'activate']);
    $group->post('/appearance/uninstall', [AppearanceController::class, 'uninstall']);
    $group->post('/appearance/install', [AppearanceController::class, 'install']);
    $group->post('/appearance/import', [AppearanceController::class, 'import']);

    // 插件管理（仅 ADMIN：列表/设置页/启停/卸载/保存设置/安装 zip·URL）
    $group->get('/plugins', [PluginsController::class, 'index']);
    $group->get('/plugins/{name}', [PluginsController::class, 'settings']);
    $group->post('/plugins/activate', [PluginsController::class, 'activate']);
    $group->post('/plugins/deactivate', [PluginsController::class, 'deactivate']);
    $group->post('/plugins/uninstall', [PluginsController::class, 'uninstall']);
    $group->post('/plugins/save-settings', [PluginsController::class, 'saveSettings']);
    $group->post('/plugins/install', [PluginsController::class, 'install']);
})->add(AdminAuthMiddleware::class);

// ---- M3：编辑器配套 API（登录 + 内容权限 + CSRF，控制器内自检） ----
$app->post('/api/upload', [ApiController::class, 'upload']);
$app->get('/api/uploads', [ApiController::class, 'uploads']);
$app->post('/api/md-preview', [ApiController::class, 'mdPreview']);
$app->post('/api/import-markdown', [ApiController::class, 'importMarkdown']);
