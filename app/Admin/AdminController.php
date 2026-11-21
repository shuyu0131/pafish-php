<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Core\Session;
use Pafish\Core\Url;
use Pafish\Http\Comments;
use Pafish\Http\Responses;
use Pafish\Services\Plugin;
use Pafish\Services\Points;
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
        ['href' => '/admin', 'label' => 'admin.dashboard', 'icon' => 'dashboard', 'exact' => true],
    ];

    /** 分组导航（require: 缺省=所有登录者 / edit=ADMIN+EDITOR / admin=仅 ADMIN） */
    protected const NAV_GROUPS = [
        [
            'id' => 'content',
            'label' => 'admin.content',
            'items' => [
                ['href' => '/admin/posts', 'label' => 'admin.posts', 'icon' => 'file-text', 'capability' => 'posts.manage'],
                ['href' => '/admin/micro', 'label' => 'admin.notes', 'icon' => 'message', 'capability' => 'posts.manage'],
                ['href' => '/admin/pages', 'label' => 'admin.pages', 'icon' => 'file-plus', 'capability' => 'pages.manage'],
                ['href' => '/admin/categories', 'label' => 'admin.categories', 'icon' => 'folder', 'capability' => 'taxonomy.manage'],
                ['href' => '/admin/tags', 'label' => 'admin.tags', 'icon' => 'tags', 'capability' => 'taxonomy.manage'],
                ['href' => '/admin/uploads', 'label' => 'admin.media_library', 'icon' => 'image', 'capability' => 'media.manage'],
            ],
        ],
        [
            'id' => 'interaction',
            'label' => 'admin.interaction',
            'items' => [
                ['href' => '/admin/comments', 'label' => 'admin.comment_moderation', 'navLabel' => 'admin.nav_comments', 'icon' => 'message', 'capability' => 'comments.manage'],
                ['href' => '/admin/links', 'label' => 'admin.friend_links', 'navLabel' => 'admin.nav_links', 'icon' => 'link', 'capability' => 'links.manage'],
            ],
        ],
        [
            'id' => 'appearance',
            'label' => 'admin.appearance',
            'items' => [
                ['href' => '/admin/nav', 'label' => 'admin.navigation_menus', 'navLabel' => 'admin.nav_menus', 'icon' => 'menu', 'capability' => 'appearance.manage'],
                ['href' => '/admin/widgets', 'label' => 'admin.sidebar_widgets', 'navLabel' => 'admin.nav_widgets', 'icon' => 'layout', 'capability' => 'appearance.manage'],
                ['href' => '/admin/appearance', 'label' => 'admin.themes_and_appearance', 'navLabel' => 'admin.nav_themes', 'icon' => 'palette', 'capability' => 'appearance.manage'],
            ],
        ],
        [
            'id' => 'system',
            'label' => 'admin.system',
            'items' => [
                ['href' => '/admin/settings', 'label' => 'admin.site_settings', 'navLabel' => 'admin.nav_settings', 'icon' => 'settings', 'capability' => 'settings.manage'],
                ['href' => '/admin/store', 'label' => 'admin.app_store', 'icon' => 'store', 'capability' => 'store.manage'],
                ['href' => '/admin/plugins', 'label' => 'admin.plugins', 'icon' => 'puzzle', 'capability' => 'plugins.manage'],
                ['href' => '/admin/users', 'label' => 'admin.users', 'icon' => 'users', 'capability' => 'users.manage'],
                ['href' => '/admin/backup', 'label' => 'admin.backups', 'icon' => 'database', 'capability' => 'backup.manage'],
                ['href' => '/admin/tools/transfer', 'label' => 'admin.content_migration', 'navLabel' => 'admin.nav_migration', 'icon' => 'download', 'capability' => 'transfer.manage'],
            ],
        ],
    ];

    /** 渲染后台页面（内容视图 + 布局，视图位于 app/Views/admin/） */
    protected function render(string $view, array $data = [], string $title = 'admin.admin'): string
    {
        $user = Auth::user();
        $role = (string) ($user['role'] ?? '');
        $ctx = [
            'user' => $user,
            'role' => $role,
            'siteName' => \site_name(),
            'nav' => $this->navWithBadges($this->navForRole($role), $role),
            'currentPath' => $this->currentPath(),
            'title' => t($title),
            'flash' => $this->takeFlash(),
        ];
        $content = self::renderView($view, array_merge($ctx, $data));
        // headExtra（编辑器 CSS 等）随布局注入 <head>
        if (isset($data['headExtra'])) {
            $ctx['headExtra'] = $data['headExtra'];
        }
        return self::renderView('layout', array_merge($ctx, ['content' => $content]));
    }

    /** 排序入口仍经由后台权限和 CSRF 中间件。 */
    protected function reorderList(Request $request, Response $response, string $scope): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        try {
            $ids = json_decode(is_string($body['ids'] ?? null) ? $body['ids'] : '', true, 512, JSON_THROW_ON_ERROR);
            $expected = json_decode(is_string($body['expected'] ?? null) ? $body['expected'] : '', true, 512, JSON_THROW_ON_ERROR);
            \Pafish\Services\AdminOrdering::reorder($scope, $ids, $expected);
        } catch (\JsonException | \InvalidArgumentException $e) {
            return $this->json($response, ['error' => t('admin.invalid_sort_data_refresh_the_page_and_try')], 400);
        } catch (\RuntimeException $e) {
            if ($e instanceof \PDOException) {
                throw $e;
            }
            return $this->json($response, ['error' => $e->getMessage()], 409);
        }
        return $this->json($response, ['ok' => true]);
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
            Auth::redirect('/admin');
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
        return Responses::json($response, $data, $status);
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
            Auth::redirect('/admin');
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
            'ADMIN' => t('admin.administrator'),
            'EDITOR' => t('common.editor'),
            default => t('common.user'),
        };
    }

    /** 当前登录者头像，未设置时使用本地默认图。 */
    protected function avatarSrc(array $user, int $size = 72): string
    {
        return Comments::avatarUrl($user['avatar_url'] ?? null, (string) ($user['email'] ?? ''));
    }

    /** 按角色过滤导航（空分组隐藏） */
    private function navForRole(string $role): array
    {
        $ok = fn (array $item): bool => empty($item['capability']) || Auth::can((string) $item['capability']);
        $top = array_values(array_filter(static::TOP_ITEMS, $ok));
        if ($role === 'USER') {
            $top = [['href' => '/admin', 'query' => ['section' => 'overview'], 'label' => t('admin.account'), 'icon' => 'dashboard', 'exact' => true]];
        }
        $groups = [];
        $allExtensionItems = Plugin::adminMenu();
        foreach (static::NAV_GROUPS as $group) {
            $items = array_values(array_filter($group['items'], $ok));
            $extensionItems = array_values(array_filter(
                $allExtensionItems,
                static fn (array $item): bool => ($item['group'] ?? '') === $group['id']
            ));
            $items = array_merge($items, array_values(array_filter($extensionItems, $ok)));
            if ($items !== []) {
                $groups[] = ['id' => $group['id'], 'label' => $group['label'], 'items' => $items];
            }
        }
        if ($role === 'USER') {
            $accountItems = [
                ['href' => '/admin', 'query' => ['section' => 'posts'], 'label' => t('admin.my_submissions'), 'icon' => 'file-text'],
                ['href' => '/admin', 'query' => ['section' => 'comments'], 'label' => t('admin.my_comments'), 'icon' => 'message'],
                ['href' => '/admin', 'query' => ['section' => 'favorites'], 'label' => t('admin.my_bookmarks'), 'icon' => 'star'],
                ['href' => '/admin', 'query' => ['section' => 'likes'], 'label' => t('admin.my_likes'), 'icon' => 'heart'],
            ];
            if (Points::available()) {
                $accountItems[] = ['href' => '/admin', 'query' => ['section' => 'points'], 'label' => t('admin.points_history'), 'icon' => 'star'];
            }
            $accountItems[] = ['href' => '/admin/profile', 'label' => t('admin.profile'), 'icon' => 'user'];
            $groups[] = ['id' => 'account', 'label' => t('admin.account_c3d92b'), 'items' => $accountItems];
        }
        return ['top' => $top, 'groups' => $groups];
    }

    /** 注入待审评论计数；编辑角色只统计自己可管理的内容。 */
    private function navWithBadges(array $nav, string $role): array
    {
        $userId = (int) (Auth::id() ?? 0);
        $postScope = $role === 'EDITOR' ? ' AND p.author_id = ?' : '';
        $postParams = $role === 'EDITOR' ? [$userId] : [];
        $pendingComments = (int) DB::value(
            "SELECT COUNT(*) FROM comments c JOIN posts p ON p.id = c.post_id WHERE c.status = 'PENDING' AND p.deleted_at IS NULL{$postScope}",
            $postParams
        );
        $pendingPosts = (int) DB::value("SELECT COUNT(*) FROM posts WHERE status='PENDING' AND deleted_at IS NULL");
        $badges = [
            '/admin/comments' => $pendingComments,
            '/admin/posts' => Auth::isAdmin() ? $pendingPosts : 0,
        ];
        foreach ($nav['top'] as &$item) {
            $item['badge'] = (int) ($badges[$item['href']] ?? 0);
        }
        unset($item);
        foreach ($nav['groups'] as &$group) {
            foreach ($group['items'] as &$item) {
                $item['badge'] = (int) ($badges[$item['href']] ?? 0);
            }
            unset($item);
        }
        unset($group);
        return $nav;
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
            throw new \RuntimeException(t('admin.invalid_admin_view_name') . $name);
        }
        $file = dirname(__DIR__) . '/Views/admin/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException(t('admin.admin_view_not_found') . $name);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
}
