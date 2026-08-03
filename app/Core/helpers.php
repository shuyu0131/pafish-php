<?php

declare(strict_types=1);

use Pafish\Core\Auth;
use Pafish\Core\Config;
use Pafish\Core\DB;
use Pafish\Core\Hooks;
use Pafish\Core\Session;
use Pafish\Core\Url;
use Pafish\Services\Settings;
use Pafish\Services\Theme;

/* ============ 全局辅助函数（前台/后台模板通用） ============ */

/** HTML 转义 */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** 站点设置（全部缓存于单请求内） */
function settings(?string $key = null, mixed $default = null): mixed
{
    return $key === null ? Settings::all() : Settings::get($key, $default);
}

/** 当前激活主题的某个设置值（theme:{key}，缺省取 theme.json 默认值） */
function theme_value(string $key, string $default = ''): string
{
    return Theme::value($key, $default);
}

/** 站点名称 */
function site_name(): string
{
    return (string) (Settings::get('site_name', '') ?: '纸鱼博客');
}

/** 站内链接（伪静态/查询串自动适配） */
function url_to(string $path): string
{
    return Url::to($path);
}

/** 当前登录用户（数组或 null） */
function current_user(): ?array
{
    return Auth::user();
}

function is_logged_in(): bool
{
    return Auth::check();
}

function is_admin(): bool
{
    return Auth::isAdmin();
}

/** 中文日期（Node 版 date-fns zhCN 风格） */
function format_date(mixed $date, string $fmt = 'Y年n月j日'): string
{
    if (!$date) {
        return '';
    }
    $ts = $date instanceof DateTimeInterface ? $date->getTimestamp() : strtotime((string) $date);
    return $ts ? date($fmt, $ts) : '';
}

/** 渲染主题模板（主题覆盖 → 系统 fallback）并返回 HTML */
function render(string $template, array $data = []): string
{
    $file = Theme::template($template);
    extract($data, EXTR_SKIP);
    ob_start();
    include $file;
    return (string) ob_get_clean();
}

/** 渲染局部模板（partials/）并返回 HTML */
function render_partial(string $name, array $data = []): string
{
    return render($name, $data);
}

/** 输出 CSRF 隐藏域 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Session::csrfToken()) . '">';
}

/** 站点绝对 URL */
function absolute_url(string $path = ''): string
{
    return Url::absolute($path);
}

/** 前台可见导航项（排序升序） */
function nav_items(): array
{
    return DB::fetchAll(
        "SELECT id, label, url, is_external FROM nav_items WHERE visible = 1 ORDER BY sort_order ASC, id ASC"
    );
}

/** 前台可见侧边栏组件（排序升序） */
function widget_items(): array
{
    return DB::fetchAll(
        "SELECT * FROM widgets WHERE visible = 1 ORDER BY sort_order ASC, id ASC"
    );
}

/** 前台可见友情链接（排序升序） */
function friend_links(): array
{
    return DB::fetchAll(
        "SELECT * FROM links WHERE visible = 1 ORDER BY sort_order ASC, id ASC"
    );
}

/** 渲染主题 header/footer（WordPress 式，主题可覆盖 header.php / footer.php） */
function get_header(): void
{
    echo render('header');
}

function get_footer(): void
{
    echo render('footer');
}

/** 当前导航是否高亮（对比请求路径） */
function nav_is_active(string $navUrl, string $currentPath): bool
{
    if ($navUrl === '/') {
        return $currentPath === '/' || $currentPath === '';
    }
    return $currentPath === $navUrl || str_starts_with($currentPath, rtrim($navUrl, '/') . '/');
}

/* ============ 钩子全局函数（模板 / 插件直接可用） ============ */

function add_action(string $name, callable $fn, int $priority = 10, ?string $tag = null): void
{
    Hooks::addAction($name, $fn, $priority, $tag);
}

function do_action(string $name, mixed ...$args): void
{
    Hooks::doAction($name, ...$args);
}

function add_filter(string $name, callable $fn, int $priority = 10, ?string $tag = null): void
{
    Hooks::addFilter($name, $fn, $priority, $tag);
}

function apply_filters(string $name, mixed $value, mixed ...$args): mixed
{
    return Hooks::applyFilters($name, $value, ...$args);
}

/* ============ 模板类别名 ============
   主题模板 / 系统 fallback 模板均为全局命名空间 PHP 文件，
   提供 DB / Theme / Hooks 三个全局类别名，模板中可直接调用 */

class_alias(\Pafish\Core\DB::class, 'DB');
class_alias(\Pafish\Core\Url::class, 'Url');
class_alias(\Pafish\Services\Theme::class, 'Theme');
class_alias(\Pafish\Core\Hooks::class, 'Hooks');
