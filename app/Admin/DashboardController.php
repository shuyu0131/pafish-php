<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Core\Version;
use Pafish\Services\Upgrade;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 工作台：
 * 管理员：内容概览、快捷入口与软件信息。
 */
final class DashboardController extends AdminController
{
    public function dashboard(Request $request, Response $response): Response
    {
        Auth::requireLogin();
        $user = Auth::user();
        if (!Auth::isAdmin() && !Auth::isEditor()) {
            return (new AccountController())->index($request, $response);
        }
        if (Auth::isEditor()) {
            return $this->editorDashboard($response, $user ?? []);
        }

        // 管理员概览；评论统计排除已删除文章。
        $stats = [
            ['label' => t('common.post'), 'value' => (int) DB::value('SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL'),
                'icon' => 'file-text', 'url' => '/admin/posts'],
            ['label' => t('admin.pending_submissions'), 'value' => (int) DB::value("SELECT COUNT(*) FROM posts WHERE status='PENDING' AND deleted_at IS NULL"),
                'icon' => 'file-text', 'url' => '/admin/posts?status=PENDING'],
            ['label' => t('common.user'), 'value' => (int) DB::value('SELECT COUNT(*) FROM users'),
                'icon' => 'users', 'url' => '/admin/users'],
            ['label' => t('common.comments'), 'value' => (int) DB::value('SELECT COUNT(*) FROM comments c JOIN posts p ON p.id = c.post_id WHERE p.deleted_at IS NULL'),
                'icon' => 'message', 'url' => '/admin/comments?status=ALL'],
            ['label' => t('admin.total_views'), 'value' => (int) DB::value('SELECT COALESCE(SUM(view_count), 0) FROM posts WHERE deleted_at IS NULL'),
                'icon' => 'eye', 'url' => null],
        ];
        $software = [
            'PHP' => PHP_VERSION,
            t('admin.database') => (string) DB::value('SELECT VERSION()'),
            t('admin.web_server') => (string) ($request->getServerParams()['SERVER_SOFTWARE'] ?? t('admin.unknown')),
            t('admin.operating_system') => PHP_OS_FAMILY,
            t('admin.time_zone') => date_default_timezone_get(),
        ];

        $html = $this->render('dashboard', [
            'username' => (string) ($user['username'] ?? ''),
            'stats' => $stats,
            'software' => $software,
            'current' => Version::current(),
            'canUpgrade' => (string) ($user['role'] ?? '') === 'ADMIN',
            'upgradeInfo' => Upgrade::cached(),
        ], t('admin.dashboard'));

        $response->getBody()->write($html);
        return $response;
    }

    /** Editors share the current dashboard components; all counts stay author-scoped. */
    private function editorDashboard(Response $response, array $user): Response
    {
        $userId = (int) ($user['id'] ?? 0);
        $stats = [
            ['label' => t('admin.my_posts'), 'value' => (int) DB::value('SELECT COUNT(*) FROM posts WHERE author_id = ? AND deleted_at IS NULL', [$userId]), 'icon' => 'file-text', 'url' => '/admin/posts'],
            ['label' => t('admin.draft'), 'value' => (int) DB::value("SELECT COUNT(*) FROM posts WHERE author_id = ? AND status = 'DRAFT' AND deleted_at IS NULL", [$userId]), 'icon' => 'pen', 'url' => '/admin/posts'],
            ['label' => t('admin.pending_comments'), 'value' => (int) DB::value("SELECT COUNT(*) FROM comments c JOIN posts p ON p.id=c.post_id WHERE p.author_id=? AND p.deleted_at IS NULL AND c.status='PENDING'", [$userId]), 'icon' => 'message', 'url' => '/admin/comments'],
            ['label' => t('admin.post_views'), 'value' => (int) DB::value('SELECT COALESCE(SUM(view_count), 0) FROM posts WHERE author_id = ? AND deleted_at IS NULL', [$userId]), 'icon' => 'eye', 'url' => null],
        ];
        $response->getBody()->write($this->render('editor-dashboard', [
            'username' => (string) ($user['nickname'] ?: $user['username']),
            'stats' => $stats,
        ], t('admin.dashboard')));
        return $response;
    }
}
