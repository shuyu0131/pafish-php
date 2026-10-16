<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Services\Points;
use Pafish\Services\Theme;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Theme-owned front-end user center. The default account center remains /admin. */
final class UserController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        if (!Theme::hasTemplate('user')) {
            return Listings::notFound($response, '页面不存在');
        }

        $user = Auth::user() ?? [];
        $userId = (int) ($user['id'] ?? 0);
        $manifest = Theme::manifest(Theme::active()) ?? [];
        $needed = $userId > 0 ? ($manifest['userData'] ?? []) : [];
        $posts = in_array('posts', $needed, true) ? DB::fetchAll(
            "SELECT id, title, slug, excerpt, cover_url, published_at, view_count, like_count, favorite_count
             FROM posts
             WHERE author_id = ? AND status = 'PUBLISHED' AND deleted_at IS NULL
               AND (published_at IS NULL OR published_at <= NOW())
             ORDER BY published_at DESC, id DESC LIMIT 30",
            [$userId]
        ) : [];
        $stats = in_array('stats', $needed, true) ? [
            'posts' => (int) DB::value("SELECT COUNT(*) FROM posts WHERE author_id = ? AND status = 'PUBLISHED' AND deleted_at IS NULL", [$userId]),
            'views' => (int) DB::value("SELECT COALESCE(SUM(view_count), 0) FROM posts WHERE author_id = ? AND status = 'PUBLISHED' AND deleted_at IS NULL", [$userId]),
            'comments' => (int) DB::value("SELECT COUNT(*) FROM comments WHERE user_id = ? AND status <> 'SPAM'", [$userId]),
        ] : ['posts' => 0, 'views' => 0, 'comments' => 0];
        $reactions = in_array('reactions', $needed, true) ? DB::fetchAll(
            "SELECT r.kind, p.title, p.slug, p.published_at, p.like_count, p.favorite_count
             FROM post_reactions r JOIN posts p ON p.id = r.post_id
             WHERE r.user_id = ? AND p.status = 'PUBLISHED' AND p.deleted_at IS NULL
               AND (p.published_at IS NULL OR p.published_at <= NOW())
             ORDER BY r.created_at DESC LIMIT 60",
            [$userId]
        ) : [];
        $comments = in_array('comments', $needed, true) ? DB::fetchAll(
            "SELECT c.content, c.status, c.created_at, p.title AS post_title, p.slug AS post_slug
             FROM comments c
             JOIN posts p ON p.id = c.post_id
             WHERE c.user_id = ? AND c.status <> 'SPAM' AND p.status = 'PUBLISHED' AND p.deleted_at IS NULL
               AND (p.published_at IS NULL OR p.published_at <= NOW())
             ORDER BY c.created_at DESC LIMIT 30",
            [$userId]
        ) : [];
        $pointsEnabled = in_array('points', $needed, true) && Points::available();
        $response->getBody()->write(\render('user', [
            'title' => '个人中心',
            'description' => '主题用户中心',
            'userRoute' => (string) ($args['route'] ?? ''),
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
