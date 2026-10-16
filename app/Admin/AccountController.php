<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Core\Url;
use Pafish\Services\Points;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Core-owned account center; uses the same controls and layout as account settings. */
final class AccountController extends AdminController
{
    public function index(Request $request, Response $response): Response
    {
        Auth::requireLogin();
        $user = Auth::user() ?? [];
        $userId = (int) ($user['id'] ?? 0);
        $query = $request->getQueryParams();
        $section = is_string($query['section'] ?? null) ? $query['section'] : 'overview';
        $pointsEnabled = Points::available();
        $sections = ['overview' => '概览', 'comments' => '我的评论', 'favorites' => '我的收藏', 'likes' => '我的点赞'];
        if ($pointsEnabled) $sections['points'] = '积分记录';
        if (!isset($sections[$section])) $section = 'overview';
        $public = "p.status = 'PUBLISHED' AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= NOW())";
        $base = Url::to('/admin');
        $sectionUrl = static fn (string $section, int $page = 1, int $per = 10): string => $base . (str_contains($base, '?') ? '&' : '?') . http_build_query(compact('section', 'page', 'per'));
        $stats = [
            ['label' => '我的评论', 'value' => (int) DB::value("SELECT COUNT(*) FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.user_id=? AND c.status <> 'SPAM' AND {$public}", [$userId]), 'icon' => 'message', 'url' => $sectionUrl('comments')],
            ['label' => '我的收藏', 'value' => (int) DB::value("SELECT COUNT(*) FROM post_reactions r JOIN posts p ON p.id=r.post_id WHERE r.user_id=? AND r.kind='favorite' AND {$public}", [$userId]), 'icon' => 'star', 'url' => $sectionUrl('favorites')],
            ['label' => '我的点赞', 'value' => (int) DB::value("SELECT COUNT(*) FROM post_reactions r JOIN posts p ON p.id=r.post_id WHERE r.user_id=? AND r.kind='like' AND {$public}", [$userId]), 'icon' => 'heart', 'url' => $sectionUrl('likes')],
        ];
        if ($pointsEnabled) $stats[] = ['label' => '我的积分', 'value' => Points::balance($userId), 'icon' => 'star', 'url' => $sectionUrl('points')];
        $perOptions = [10, 20, 50];
        $per = is_scalar($query['per'] ?? null) ? (int) $query['per'] : 10;
        if (!in_array($per, $perOptions, true)) $per = 10;
        $page = max(1, is_scalar($query['page'] ?? null) ? (int) $query['page'] : 1);
        $total = match ($section) {
            'comments' => $stats[0]['value'], 'favorites' => $stats[1]['value'], 'likes' => $stats[2]['value'],
            'points' => (int) DB::value('SELECT COUNT(*) FROM point_transactions WHERE user_id=?', [$userId]),
            default => 0,
        };
        $pages = max(1, (int) ceil($total / $per));
        $page = min($page, $pages);
        $offset = ($page - 1) * $per;
        $items = match ($section) {
            'comments' => DB::fetchAll("SELECT c.id,c.content,c.status,c.created_at,p.title,p.slug FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.user_id=? AND c.status <> 'SPAM' AND {$public} ORDER BY c.created_at DESC,c.id DESC LIMIT {$per} OFFSET {$offset}", [$userId]),
            'favorites', 'likes' => DB::fetchAll("SELECT p.title,p.slug,r.created_at FROM post_reactions r JOIN posts p ON p.id=r.post_id WHERE r.user_id=? AND r.kind=? AND {$public} ORDER BY r.created_at DESC,p.id DESC LIMIT {$per} OFFSET {$offset}", [$userId, $section === 'favorites' ? 'favorite' : 'like']),
            'points' => DB::fetchAll("SELECT amount,reason,created_at FROM point_transactions WHERE user_id=? ORDER BY id DESC LIMIT {$per} OFFSET {$offset}", [$userId]),
            default => [],
        };
        $response->getBody()->write($this->render('user-dashboard', compact('stats', 'section', 'sections', 'items', 'page', 'pages', 'total', 'per', 'perOptions', 'sectionUrl'), '个人中心'));
        return $response;
    }
}
