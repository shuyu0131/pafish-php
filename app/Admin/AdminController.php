<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Core\Session;
use Pafish\Core\Url;
use Pafish\Http\Comments;
use Pafish\Services\Settings;
use Pafish\Services\Upgrade;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台控制器基类：导航过滤、布局渲染和权限守卫。
 * ADMIN 可管理全部功能；EDITOR 仅可管理内容与互动；
 * 外观（导航/组件/主题）、站点设置、商店/插件/用户/备份仅 ADMIN
 */
abstract class AdminController
{
    /** 置顶导航项（不分组） */
    protected const TOP_ITEMS = [
        ['href' => '/admin', 'label' => '工作台', 'icon' => 'dashboard', 'exact' => true],
    ];

    /** 分组导航（require: 缺省=所有登录者 / edit=ADMIN+EDITOR / admin=仅 ADMIN） */
    protected const NAV_GROUPS = [
        [
            'id' => 'content',
            'label' => '内容',
            'items' => [
                ['href' => '/admin/posts', 'label' => '文章管理', 'icon' => 'file-text', 'capability' => 'posts.manage'],
                ['href' => '/admin/pages', 'label' => '页面管理', 'icon' => 'file-plus', 'capability' => 'pages.manage'],
                ['href' => '/admin/categories', 'label' => '分类管理', 'icon' => 'folder', 'capability' => 'taxonomy.manage'],
                ['href' => '/admin/tags', 'label' => '标签管理', 'icon' => 'tags', 'capability' => 'taxonomy.manage'],
                ['href' => '/admin/uploads', 'label' => '媒体库', 'icon' => 'image', 'capability' => 'media.manage'],
            ],
        ],
        [
            'id' => 'interaction',
            'label' => '互动',
            'items' => [
                ['href' => '/admin/comments', 'label' => '评论审核', 'icon' => 'message', 'capability' => 'comments.manage'],
                ['href' => '/admin/notifications', 'label' => '通知', 'icon' => 'bell', 'capability' => 'comments.manage'],
                ['href' => '/admin/links', 'label' => '友情链接', 'icon' => 'link', 'capability' => 'links.manage'],
            ],
        ],
        [
            'id' => 'appearance',
            'label' => '外观',
            'items' => [
                ['href' => '/admin/nav', 'label' => '导航菜单', 'icon' => 'menu', 'capability' => 'appearance.manage'],
                ['href' => '/admin/widgets', 'label' => '侧边栏组件', 'icon' => 'layout', 'capability' => 'appearance.manage'],
                ['href' => '/admin/appearance', 'label' => '主题与外观', 'icon' => 'palette', 'capability' => 'appearance.manage'],
            ],
        ],
        [
            'id' => 'system',
            'label' => '系统',
            'items' => [
                ['href' => '/admin/settings', 'label' => '站点设置', 'icon' => 'settings', 'capability' => 'settings.manage'],
                ['href' => '/admin/store', 'label' => '应用商店', 'icon' => 'store', 'capability' => 'store.manage'],
                ['href' => '/admin/plugins', 'label' => '插件管理', 'icon' => 'puzzle', 'capability' => 'plugins.manage'],
                ['href' => '/admin/upgrade', 'label' => '系统更新', 'icon' => 'refresh', 'capability' => 'upgrade.manage'],
                ['href' => '/admin/users', 'label' => '用户管理', 'icon' => 'users', 'capability' => 'users.manage'],
                ['href' => '/admin/backup', 'label' => '数据备份', 'icon' => 'database', 'capability' => 'backup.manage'],
                ['href' => '/admin/tools/transfer', 'label' => '内容迁移', 'icon' => 'download', 'capability' => 'transfer.manage'],
            ],
        ],
    ];

    /** 渲染后台页面（内容视图 + 布局，视图位于 app/Views/admin/） */
    protected function render(string $view, array $data = [], string $title = '后台'): string
    {
        $user = Auth::user();
        $role = (string) ($user['role'] ?? '');
        $ctx = [
            'user' => $user,
            'role' => $role,
            'siteName' => (string) (Settings::get('site_name', '') ?: '纸鱼博客'),
            'nav' => $this->navForRole($role),
            'unreadNotifications' => (int) DB::value('SELECT COUNT(*) FROM notifications WHERE `read` = 0'),
            // 系统更新红点（仅读 24h 缓存，不触网零延迟；实际检查由布局内静默 fetch 完成）
            'upgradeAvailable' => $role === 'ADMIN' && (bool) (Upgrade::cached()['hasUpdate'] ?? false),
            'currentPath' => $this->currentPath(),
            'title' => $title,
            'flash' => $this->takeFlash(),
        ];
        $content = self::renderView($view, array_merge($ctx, $data));
        // headExtra（编辑器 CSS 等）随布局注入 <head>
        if (isset($data['headExtra'])) {
            $ctx['headExtra'] = $data['headExtra'];
        }
        return self::renderView('layout', array_merge($ctx, ['content' => $content]));
    }

    /** 设置一条后台 flash 消息（重定向后显示一次） */
    protected function flash(string $type, string $message): void
    {
        Session::set('admin_flash', ['type' => $type, 'message' => $message]);
    }

    /** 内容管理守卫（ADMIN+EDITOR；无权限回工作台） */
    protected function guardCanManage(): void
    {
        Auth::requireLogin();
        if (!Auth::can('posts.manage')) {
            header('Location: ' . Url::to('/admin'));
            exit;
        }
    }

    /** 是否 AJAX 请求（写操作按此返回 JSON 或重定向） */
    protected function isAjax(Request $request): bool
    {
        return strtoupper((string) $request->getHeaderLine('X-Requested-With')) === 'XMLHTTPREQUEST';
    }

    /** 统一 JSON 响应。 */
    protected function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    /** 读取并清除 flash */
    private function takeFlash(): ?array
    {
        $flash = Session::get('admin_flash');
        if (is_array($flash)) {
            Session::remove('admin_flash');
            return $flash;
        }
        return null;
    }

    /** 重定向响应（子控制器共用） */
    protected function redirect(Response $response, string $path, int $status = 302): Response
    {
        return $response->withStatus($status)->withHeader('Location', Url::to($path));
    }

    /** 仅管理员页守卫（非 ADMIN 重定向回工作台） */
    protected function guardAdmin(): void
    {
        Auth::requireLogin();
        if (!Auth::isAdmin()) {
            header('Location: ' . Url::to('/admin'));
            exit;
        }
    }

    protected function guardCapability(string $capability): void
    {
        Auth::requireCapability($capability);
    }

    /** 角色中文标签 */
    protected function roleLabel(string $role): string
    {
        return match ($role) {
            'ADMIN' => '管理员',
            'EDITOR' => '编辑',
            default => '用户',
        };
    }

    /** 当前登录者头像（user.avatarUrl 优先，否则 cravatar） */
    protected function avatarSrc(array $user, int $size = 72): string
    {
        return Comments::avatarUrl($user['avatar_url'] ?? null, (string) ($user['email'] ?? ''));
    }

    /** 按角色过滤导航（空分组隐藏） */
    private function navForRole(string $role): array
    {
        $ok = fn (array $item): bool => empty($item['capability']) || Auth::can((string) $item['capability']);
        $top = array_values(array_filter(static::TOP_ITEMS, $ok));
        $groups = [];
        foreach (static::NAV_GROUPS as $group) {
            $items = array_values(array_filter($group['items'], $ok));
            if ($items !== []) {
                $groups[] = ['id' => $group['id'], 'label' => $group['label'], 'items' => $items];
            }
        }
        return ['top' => $top, 'groups' => $groups];
    }

    /** 当前请求路径（已去 base 前缀；查询串兜底模式下取 ?p=） */
    private function currentPath(): string
    {
        $p = $_GET['p'] ?? null;
        if (is_string($p) && $p !== '') {
            return '/' . ltrim($p, '/');
        }
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/';
        $base = Url::base();
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
        return $path === '' ? '/' : $path;
    }

    /** 渲染 admin 视图（不受主题覆盖影响） */
    private static function renderView(string $name, array $data = []): string
    {
        if (!preg_match('/^[a-z0-9_-]{1,60}$/', $name)) {
            throw new \RuntimeException('非法的后台视图名：' . $name);
        }
        $file = dirname(__DIR__) . '/Views/admin/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('后台视图不存在：' . $name);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
}
