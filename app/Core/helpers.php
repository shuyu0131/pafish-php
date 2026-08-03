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

/** 中文日期（Node 版 date-fns zhCN 风格；兼容 yyyy/MM/dd 等 date-fns token） */
function format_date(mixed $date, string $fmt = 'Y年n月j日'): string
{
    if (!$date) {
        return '';
    }
    $ts = $date instanceof DateTimeInterface ? $date->getTimestamp() : strtotime((string) $date);
    if (!$ts) {
        return '';
    }
    static $tokens = ['yyyy' => 'Y', 'MM' => 'm', 'dd' => 'd', 'HH' => 'H', 'mm' => 'i', 'ss' => 's'];
    return date(strtr($fmt, $tokens), $ts);
}

/** 渲染主题模板（主题覆盖 → 系统 fallback）并返回 HTML
 *  数据通过全局上下文在 header/footer/partial 之间共享：
 *  控制器传的 $title/$og/$description 等在 get_header() 中同样可见，
 *  局部模板传入的数据优先于上下文 */
function render(string $template, array $data = []): string
{
    $context = $GLOBALS['pafish_tpl_ctx'] ?? [];
    $vars = array_merge($context, $data);
    $GLOBALS['pafish_tpl_ctx'] = $vars;
    $file = Theme::template($template);
    extract($vars, EXTR_SKIP);
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

/** Markdown → HTML（ParsedownExtra，主题模板可直接调用） */
function md(string $markdown): string
{
    return \Pafish\Services\Markdown::render($markdown);
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

/** 默认头像（无 avatarUrl 时的 cravatar，对齐 Node avatarSrc） */
function admin_gravatar(string $email): string
{
    return \Pafish\Http\Comments::avatarUrl(null, $email);
}

/** 后台内联 SVG 图标（lucide 风格：24 视口 stroke 线条，currentColor 继承） */
function admin_icon(string $name, int $size = 16): string
{
    static $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'file-text' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'file-plus' => '<path d="M14.5 22H18a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v4"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M12 18v-6"/><path d="M9 15h6"/>',
        'folder' => '<path d="m6 14 1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2"/>',
        'tags' => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
        'image' => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
        'message' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'link' => '<path d="M9 17H7A5 5 0 0 1 7 7h2"/><path d="M15 7h2a5 5 0 1 1 0 10h-2"/><line x1="8" x2="16" y1="12" y2="12"/>',
        'menu' => '<line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="18" y2="18"/>',
        'layout' => '<rect width="18" height="7" x="3" y="3" rx="1"/><rect width="9" height="7" x="3" y="14" rx="1"/><rect width="5" height="7" x="16" y="14" rx="1"/>',
        'palette' => '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
        'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'store' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'puzzle' => '<path d="M19.439 7.85c-.049.322.059.648.289.878l1.568 1.568c.47.47.706 1.087.706 1.704s-.235 1.233-.706 1.704l-1.611 1.611a.98.98 0 0 1-.837.276c-.47-.07-.802-.48-.968-.925a2.501 2.501 0 1 0-3.214 3.214c.446.166.855.497.925.968a.979.979 0 0 1-.276.837l-1.61 1.61a2.404 2.404 0 0 1-1.705.707 2.402 2.402 0 0 1-1.704-.706l-1.568-1.568a1.026 1.026 0 0 0-.877-.29c-.493.074-.84.504-1.02.968a2.5 2.5 0 1 1-3.237-3.237c.464-.18.894-.527.967-1.02a1.026 1.026 0 0 0-.289-.877l-1.568-1.568A2.402 2.402 0 0 1 1.998 12c0-.617.236-1.234.706-1.704L4.23 8.77c.24-.24.581-.353.917-.303.515.077.877.528 1.073 1.01a2.5 2.5 0 1 0 3.259-3.259c-.482-.196-.933-.558-1.01-1.073-.05-.336.062-.676.303-.917l1.525-1.525A2.402 2.402 0 0 1 12 1.998c.617 0 1.234.236 1.704.706l1.568 1.568c.23.23.556.338.877.29.493-.074.84-.504 1.02-.968a2.5 2.5 0 1 1 3.237 3.237c-.464.18-.894.527-.967 1.02Z"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
        'home' => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'pen' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'trend' => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'arrow-left' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'edit' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
    ];
    $inner = $paths[$name] ?? $paths['file-text'];
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size
        . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

/* ============ 模板类别名 ============
   主题模板 / 系统 fallback 模板均为全局命名空间 PHP 文件，
   提供 DB / Theme / Hooks 三个全局类别名，模板中可直接调用 */

class_alias(\Pafish\Core\DB::class, 'DB');
class_alias(\Pafish\Core\Url::class, 'Url');
class_alias(\Pafish\Services\Theme::class, 'Theme');
class_alias(\Pafish\Core\Hooks::class, 'Hooks');
