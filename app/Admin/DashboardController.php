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
 * 6 统计卡 + 近 14 天发文趋势（SVG 柱状图）+ 分类分布（横向条形）+ 最近更新 5 篇
 */
final class DashboardController extends AdminController
{
    public function dashboard(Request $request, Response $response): Response
    {
        $user = Auth::user();
        $canManage = in_array((string) ($user['role'] ?? ''), ['ADMIN', 'EDITOR'], true);

        // 统计卡片
        $stats = [
            ['label' => '全部文章', 'value' => (int) DB::value('SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL'),
                'icon' => 'file-text', 'color' => '#4786d6'],
            ['label' => '已发布', 'value' => (int) DB::value("SELECT COUNT(*) FROM posts WHERE status = 'PUBLISHED' AND deleted_at IS NULL"),
                'icon' => 'eye', 'color' => '#2f9e63'],
            ['label' => '草稿', 'value' => (int) DB::value("SELECT COUNT(*) FROM posts WHERE status = 'DRAFT' AND deleted_at IS NULL"),
                'icon' => 'pen', 'color' => '#d9822b'],
            ['label' => '定时发布', 'value' => (int) DB::value("SELECT COUNT(*) FROM posts WHERE status = 'SCHEDULED' AND deleted_at IS NULL"),
                'icon' => 'clock', 'color' => '#8b5cf6'],
            ['label' => '待审评论', 'value' => (int) DB::value("SELECT COUNT(*) FROM comments WHERE status = 'PENDING'"),
                'icon' => 'message', 'color' => '#ca8a04'],
            ['label' => '总浏览量', 'value' => (int) DB::value('SELECT COALESCE(SUM(view_count), 0) FROM posts WHERE deleted_at IS NULL'),
                'icon' => 'trend', 'color' => '#0891b2'],
        ];

        // 近 14 天发文趋势（按发布日分组，PHP 侧补齐空天）
        $since = strtotime(date('Y-m-d') . ' -13 days');
        $trendMap = [];
        foreach (DB::fetchAll(
            "SELECT DATE(published_at) AS d, COUNT(*) AS c FROM posts
             WHERE status = 'PUBLISHED' AND deleted_at IS NULL AND published_at >= ?
             GROUP BY DATE(published_at)",
            [date('Y-m-d', $since) . ' 00:00:00']
        ) as $row) {
            $trendMap[$row['d']] = (int) $row['c'];
        }
        $trend = [];
        for ($i = 0; $i < 14; $i++) {
            $day = date('Y-m-d', strtotime("+{$i} days", $since));
            $trend[] = ['label' => date('n/j', strtotime($day)), 'count' => $trendMap[$day] ?? 0];
        }

        // 分类分布（已发布文章数 + 浏览量，count 降序）
        $catRows = [];
        $catName = [];
        foreach (DB::fetchAll('SELECT id, name FROM categories') as $row) {
            $catName[(int) $row['id']] = $row['name'];
        }
        foreach (DB::fetchAll(
            "SELECT category_id, COUNT(*) AS c, COALESCE(SUM(view_count), 0) AS v
             FROM posts WHERE status = 'PUBLISHED' AND deleted_at IS NULL
             GROUP BY category_id"
        ) as $row) {
            $id = (int) $row['category_id'];
            $catRows[] = [
                'name' => $id ? ($catName[$id] ?? '未分类') : '未分类',
                'count' => (int) $row['c'],
                'views' => (int) $row['v'],
            ];
        }
        usort($catRows, fn ($a, $b) => $b['count'] <=> $a['count']);

        // 最近更新（updatedAt desc，前 5 篇）
        $latest = DB::fetchAll(
            'SELECT id, title, status, published_at, updated_at FROM posts
             WHERE deleted_at IS NULL ORDER BY updated_at DESC LIMIT 5'
        );

        $html = $this->render('dashboard', [
            'username' => (string) ($user['username'] ?? ''),
            'canManage' => $canManage,
            'stats' => $stats,
            'trend' => $trend,
            'catRows' => $catRows,
            'latest' => $latest,
            'totalTrend' => array_sum(array_column($trend, 'count')),
            'current' => Version::current(),
            'canUpgrade' => (string) ($user['role'] ?? '') === 'ADMIN',
            'upgradeInfo' => Upgrade::cached(),
        ], '工作台');

        $response->getBody()->write($html);
        return $response;
    }
}
