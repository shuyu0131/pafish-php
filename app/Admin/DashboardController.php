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
        $user = Auth::user();
        if (!Auth::isAdmin() && !Auth::isEditor()) {
            return $this->userDashboard($response, $user ?? []);
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

    /** 普通用户工作台：复用后台公共布局，只展示自己的内容与互动。 */
    private function userDashboard(Response $response, array $user): Response
    {
        $userId = (int) ($user['id'] ?? 0);
        $stats = [
            ['label' => '我的文章', 'value' => (int) DB::value('SELECT COUNT(*) FROM posts WHERE author_id = ? AND deleted_at IS NULL', [$userId]), 'icon' => 'file-text', 'color' => '#4786d6'],
            ['label' => '文章浏览', 'value' => (int) DB::value('SELECT COALESCE(SUM(view_count), 0) FROM posts WHERE author_id = ? AND deleted_at IS NULL', [$userId]), 'icon' => 'eye', 'color' => '#2f9e63'],
            ['label' => '我的评论', 'value' => (int) DB::value('SELECT COUNT(*) FROM comments WHERE user_id = ? AND status <> \'SPAM\'', [$userId]), 'icon' => 'message', 'color' => '#ca8a04'],
            ['label' => '点赞收藏', 'value' => (int) DB::value('SELECT COUNT(*) FROM post_reactions WHERE user_id = ?', [$userId]), 'icon' => 'heart', 'color' => '#8b5cf6'],
        ];
        $latest = DB::fetchAll(
            "SELECT id, title, status, published_at, updated_at FROM posts WHERE author_id = ? AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 8",
            [$userId]
        );
        $comments = DB::fetchAll(
            "SELECT c.content, c.status, c.created_at, p.title AS post_title, p.slug AS post_slug
             FROM comments c JOIN posts p ON p.id = c.post_id
             WHERE c.user_id = ? AND p.deleted_at IS NULL ORDER BY c.created_at DESC LIMIT 5",
            [$userId]
        );
        $response->getBody()->write($this->render('user-dashboard', [
            'username' => (string) ($user['username'] ?? ''),
            'stats' => $stats,
            'latest' => $latest,
            'comments' => $comments,
        ], '我的工作台'));
        return $response;
    }

    /** 编辑写作台：仅汇总本人内容、互动和待处理评论。 */
    private function editorDashboard(Response $response, array $user): Response
    {
        $userId = (int) ($user['id'] ?? 0);
        $stats = [
            ['label' => '我的文章', 'value' => (int) DB::value('SELECT COUNT(*) FROM posts WHERE author_id = ? AND deleted_at IS NULL', [$userId]), 'icon' => 'file-text', 'color' => '#4786d6'],
            ['label' => '已发布', 'value' => (int) DB::value("SELECT COUNT(*) FROM posts WHERE author_id = ? AND status = 'PUBLISHED' AND deleted_at IS NULL", [$userId]), 'icon' => 'eye', 'color' => '#2f9e63'],
            ['label' => '草稿与定时', 'value' => (int) DB::value("SELECT COUNT(*) FROM posts WHERE author_id = ? AND status IN ('DRAFT', 'SCHEDULED') AND deleted_at IS NULL", [$userId]), 'icon' => 'pen', 'color' => '#d9822b'],
            ['label' => '待审评论', 'value' => (int) DB::value("SELECT COUNT(*) FROM comments c JOIN posts p ON p.id = c.post_id WHERE p.author_id = ? AND p.deleted_at IS NULL AND c.status = 'PENDING'", [$userId]), 'icon' => 'message', 'color' => '#ca8a04'],
            ['label' => '总浏览量', 'value' => (int) DB::value('SELECT COALESCE(SUM(view_count), 0) FROM posts WHERE author_id = ? AND deleted_at IS NULL', [$userId]), 'icon' => 'trend', 'color' => '#0891b2'],
            ['label' => '收到互动', 'value' => (int) DB::value('SELECT COALESCE(SUM(like_count + favorite_count), 0) FROM posts WHERE author_id = ? AND deleted_at IS NULL', [$userId]), 'icon' => 'heart', 'color' => '#8b5cf6'],
        ];
        $latest = DB::fetchAll(
            'SELECT id, title, status, published_at, updated_at FROM posts WHERE author_id = ? AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 8',
            [$userId]
        );
        $pendingComments = DB::fetchAll(
            "SELECT c.id, c.author_name, c.content, c.created_at, p.title AS post_title
             FROM comments c JOIN posts p ON p.id = c.post_id
             WHERE p.author_id = ? AND p.deleted_at IS NULL AND c.status = 'PENDING'
             ORDER BY c.created_at DESC LIMIT 5",
            [$userId]
        );
        $response->getBody()->write($this->render('editor-dashboard', [
            'username' => (string) ($user['username'] ?? ''),
            'stats' => $stats,
            'latest' => $latest,
            'pendingComments' => $pendingComments,
        ], '写作台'));
        return $response;
    }
}
