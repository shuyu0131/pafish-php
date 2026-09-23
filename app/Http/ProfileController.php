<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Core\Url;
use Pafish\Services\Points;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** 公共用户中心；资料写入复用 Admin\\ProfileController 的校验接口。 */
final class ProfileController
{
    public function index(Request $request, Response $response): Response
    {
        if (!Auth::check()) {
            return $response->withHeader('Location', Url::to('/login') . '?from=%2Fprofile')->withStatus(302);
        }
        $user = Auth::user() ?? [];
        $userId = (int) ($user['id'] ?? 0);
        $posts = DB::fetchAll(
            "SELECT id, title, slug, excerpt, cover_url, published_at, view_count, like_count, favorite_count
             FROM posts
             WHERE author_id = ? AND status = 'PUBLISHED' AND deleted_at IS NULL
               AND (published_at IS NULL OR published_at <= NOW())
             ORDER BY published_at DESC, id DESC LIMIT 30",
            [$userId]
        );
        $stats = [
            'posts' => (int) DB::value("SELECT COUNT(*) FROM posts WHERE author_id = ? AND status = 'PUBLISHED' AND deleted_at IS NULL", [$userId]),
            'views' => (int) DB::value("SELECT COALESCE(SUM(view_count), 0) FROM posts WHERE author_id = ? AND status = 'PUBLISHED' AND deleted_at IS NULL", [$userId]),
            'comments' => (int) DB::value("SELECT COUNT(*) FROM comments WHERE user_id = ? AND status <> 'SPAM'", [$userId]),
        ];
        $reactions = DB::fetchAll(
            "SELECT r.kind, p.title, p.slug, p.published_at, p.like_count, p.favorite_count
             FROM post_reactions r JOIN posts p ON p.id = r.post_id
             WHERE r.user_id = ? AND p.deleted_at IS NULL
             ORDER BY r.created_at DESC LIMIT 60",
            [$userId]
        );
        $comments = DB::fetchAll(
            "SELECT c.content, c.status, c.created_at, p.title AS post_title, p.slug AS post_slug
             FROM comments c
             JOIN posts p ON p.id = c.post_id
             WHERE c.user_id = ? AND p.deleted_at IS NULL
             ORDER BY c.created_at DESC LIMIT 30",
            [$userId]
        );
        $pointsEnabled = Points::available();
        $response->getBody()->write(\render('profile', [
            'title' => '个人中心',
            'description' => '管理个人资料并查看自己的动态',
            'user' => $user,
            'posts' => $posts,
            'stats' => $stats,
            'reactions' => $reactions,
            'comments' => $comments,
            'pointsEnabled' => $pointsEnabled,
            'pointsBalance' => $pointsEnabled ? Points::balance($userId) : 0,
            'pointTransactions' => $pointsEnabled ? Points::transactions($userId) : [],
        ]));
        return $response;
    }
}
