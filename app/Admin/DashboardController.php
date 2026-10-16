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
            ['label' => '文章', 'value' => (int) DB::value('SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL'),
                'icon' => 'file-text', 'url' => '/admin/posts'],
            ['label' => '用户', 'value' => (int) DB::value('SELECT COUNT(*) FROM users'),
                'icon' => 'users', 'url' => '/admin/users'],
            ['label' => '评论', 'value' => (int) DB::value('SELECT COUNT(*) FROM comments c JOIN posts p ON p.id = c.post_id WHERE p.deleted_at IS NULL'),
                'icon' => 'message', 'url' => '/admin/comments?status=ALL'],
            ['label' => '累计浏览', 'value' => (int) DB::value('SELECT COALESCE(SUM(view_count), 0) FROM posts WHERE deleted_at IS NULL'),
                'icon' => 'eye', 'url' => null],
        ];
        $software = [
            'PHP' => PHP_VERSION,
            '数据库' => (string) DB::value('SELECT VERSION()'),
            'Web 服务' => (string) ($request->getServerParams()['SERVER_SOFTWARE'] ?? '未知'),
            '操作系统' => PHP_OS_FAMILY,
            '时区' => date_default_timezone_get(),
        ];

        $html = $this->render('dashboard', [
            'username' => (string) ($user['username'] ?? ''),
            'stats' => $stats,
            'software' => $software,
            'current' => Version::current(),
            'canUpgrade' => (string) ($user['role'] ?? '') === 'ADMIN',
            'upgradeInfo' => Upgrade::cached(),
        ], '工作台');

        $response->getBody()->write($html);
        return $response;
    }

    /** Editors share the current dashboard components; all counts stay author-scoped. */
    private function editorDashboard(Response $response, array $user): Response
    {
        $userId = (int) ($user['id'] ?? 0);
        $stats = [
            ['label' => '我的文章', 'value' => (int) DB::value('SELECT COUNT(*) FROM posts WHERE author_id = ? AND deleted_at IS NULL', [$userId]), 'icon' => 'file-text', 'url' => '/admin/posts'],
            ['label' => '草稿', 'value' => (int) DB::value("SELECT COUNT(*) FROM posts WHERE author_id = ? AND status = 'DRAFT' AND deleted_at IS NULL", [$userId]), 'icon' => 'pen', 'url' => '/admin/posts'],
            ['label' => '待审评论', 'value' => (int) DB::value("SELECT COUNT(*) FROM comments c JOIN posts p ON p.id=c.post_id WHERE p.author_id=? AND p.deleted_at IS NULL AND c.status='PENDING'", [$userId]), 'icon' => 'message', 'url' => '/admin/comments'],
            ['label' => '文章浏览', 'value' => (int) DB::value('SELECT COALESCE(SUM(view_count), 0) FROM posts WHERE author_id = ? AND deleted_at IS NULL', [$userId]), 'icon' => 'eye', 'url' => null],
        ];
        $response->getBody()->write($this->render('editor-dashboard', [
            'username' => (string) ($user['nickname'] ?: $user['username']),
            'stats' => $stats,
        ], '工作台'));
        return $response;
    }
}
