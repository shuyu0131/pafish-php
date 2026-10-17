<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Core\Session;
use Pafish\Services\AppTokens;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 个人资料：任何登录用户可访问
 * - 资料表单：头像（上传/粘贴地址）、昵称、用户名、邮箱
 * - 修改密码：需当前密码；成功提示「密码已修改，下次登录请使用新密码」
 */
final class ProfileController extends AdminController
{
    /** GET /admin/profile */
    public function index(Request $request, Response $response): Response
    {
        Auth::requireLogin();
        $response->getBody()->write($this->render('profile', [
            'me' => Auth::user(),
            'appTokens' => AppTokens::listForUser((int) Auth::id()),
        ], '个人资料'));
        return $response;
    }

    /** POST /admin/profile/app-token/create: plaintext is returned only at issuance. */
    public function createAppToken(Request $request, Response $response): Response
    {
        $response = $response->withHeader('Cache-Control', 'no-store');
        if (Auth::isBearerAuthenticated() || $request->hasHeader('Authorization')) {
            return $this->json($response, ['error' => '请在网页个人资料中创建令牌'], 403);
        }
        Auth::requireLogin();
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $csrf = $body['_csrf'] ?? null;
        if (!is_string($csrf) || !Session::verifyCsrf($csrf)) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        $deviceName = $body['device_name'] ?? null;
        if (!is_string($deviceName) || trim($deviceName) === '' || mb_strlen($deviceName) > 100
            || preg_match('/[\x00-\x1f\x7f]/u', $deviceName) !== 0) {
            return $this->json($response, ['error' => '请输入 1-100 字的设备名称，不含控制字符'], 400);
        }
        $userId = (int) Auth::id();
        if (!\Pafish\Core\RateLimiter::allow('profile-app-token', (string) $userId, 10, 600)) {
            return $this->json($response, ['error' => '创建令牌过于频繁，请稍后重试'], 429);
        }
        $password = $body['current_password'] ?? null;
        if (!is_string($password) || !password_verify($password, (string) (Auth::user()['password_hash'] ?? ''))) {
            return $this->json($response, ['error' => '当前密码不正确'], 400);
        }
        try {
            $issued = AppTokens::issue($userId, trim($deviceName));
        } catch (\PDOException $e) {
            error_log('Profile app token creation failed: ' . $e->getMessage());
            return $this->json($response, ['error' => '创建失败，请稍后重试'], 500);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
        return $this->json($response, [
            'ok' => true,
            'id' => $issued['id'],
            'accessToken' => $issued['token'],
            'expiresAt' => $issued['expiresAt'],
        ]);
    }

    /** POST /admin/profile/app-token/{id}/revoke */
    public function revokeAppToken(Request $request, Response $response, array $args): Response
    {
        Auth::requireLogin();
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        $tokenId = (int) ($args['id'] ?? 0);
        if ($tokenId < 1 || !AppTokens::revoke((int) Auth::id(), $tokenId)) {
            return $this->json($response, ['error' => '设备授权不存在或已撤销'], 404);
        }
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/profile/app-token/{id}/delete */
    public function deleteAppToken(Request $request, Response $response, array $args): Response
    {
        Auth::requireLogin();
        $body = $request->getParsedBody();
        $csrf = is_array($body) ? ($body['_csrf'] ?? null) : null;
        if (!is_string($csrf) || !Session::verifyCsrf($csrf)) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        $tokenId = (int) ($args['id'] ?? 0);
        if ($tokenId < 1 || !AppTokens::deleteRevoked((int) Auth::id(), $tokenId)) {
            return $this->json($response, ['error' => '令牌记录不存在或尚未撤销'], 404);
        }
        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/profile/save：保存头像/昵称/用户名/邮箱 */
    public function save(Request $request, Response $response): Response
    {
        Auth::requireLogin();
        $me = Auth::user();
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        $nickname = mb_substr(trim((string) ($body['nickname'] ?? '')), 0, 50);
        $username = mb_substr(trim((string) ($body['username'] ?? '')), 0, 50);
        $email = mb_substr(trim((string) ($body['email'] ?? '')), 0, 255);
        try {
            $avatarUrl = (string) \Pafish\Services\Content::imageUrl($body['avatar_url'] ?? '');
        } catch (\RuntimeException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }

        if (!preg_match('/^[\w\x{4e00}-\x{9fa5}-]{2,50}$/u', $username)) {
            return $this->json($response, ['error' => '用户名需 2-50 位，仅限中文、字母、数字、下划线和连字符'], 400);
        }
        if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            return $this->json($response, ['error' => '邮箱格式不正确'], 400);
        }
        $dup = DB::fetchOne('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, (int) $me['id']]);
        if ($dup) {
            return $this->json($response, ['error' => '用户名已被占用'], 409);
        }
        $dup = DB::fetchOne('SELECT id FROM users WHERE email = ? AND id <> ?', [strtolower($email), (int) $me['id']]);
        if ($dup) {
            return $this->json($response, ['error' => '邮箱已被注册'], 409);
        }

        \Pafish\Services\MediaReferences::transaction(static function () use ($nickname, $username, $email, $avatarUrl, $me): void {
            DB::execute(
                'UPDATE users SET nickname = ?, username = ?, email = ?, avatar_url = ? WHERE id = ?',
                [$nickname, $username, strtolower($email), $avatarUrl, (int) $me['id']]
            );
            \Pafish\Services\MediaReferences::sync('user', (int) $me['id']);
            \Pafish\Core\Cache::clearPrefix('micro.latest.');
        });
        return $this->json($response, ['ok' => true]);
    }

    /** Image-only avatar uploads are available to members without granting media-library access. */
    public function uploadAvatar(Request $request, Response $response): Response
    {
        Auth::requireLogin();
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        if (!\Pafish\Core\RateLimiter::allow('profile-avatar', (string) Auth::id(), 20, 3600)) {
            return $this->json($response, ['error' => '头像上传过于频繁，请稍后重试'], 429);
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => '请选择头像图片'], 400);
        }
        $stream = $file->getStream();
        if ($stream->isSeekable()) $stream->rewind();
        $data = $stream->read(2097153);
        if ($data === '' || strlen($data) > 2097152) {
            return $this->json($response, ['error' => '头像图片不能超过 2MB'], 400);
        }
        $image = @getimagesizefromstring($data);
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $ext = $extensions[$image['mime'] ?? ''] ?? null;
        if ($ext === null || $image[0] > 4096 || $image[1] > 4096) {
            return $this->json($response, ['error' => '请选择 PNG、JPG、GIF 或 WebP 图片，边长不超过 4096 像素'], 400);
        }
        try {
            $uploaded = \Pafish\Services\Upload::handleStream($data, 'avatar.' . $ext);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
        return $this->json($response, ['ok' => true, 'url' => $uploaded['url']]);
    }

    /** POST /admin/profile/password：修改当前用户密码（需当前密码） */
    public function changePassword(Request $request, Response $response): Response
    {
        Auth::requireLogin();
        $me = Auth::user();
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        $current = (string) ($body['current_password'] ?? '');
        $new = (string) ($body['new_password'] ?? '');
        if ($current === '') {
            return $this->json($response, ['error' => '请输入当前密码'], 400);
        }
        if (!password_verify($current, (string) ($me['password_hash'] ?? ''))) {
            return $this->json($response, ['error' => '当前密码不正确'], 400);
        }
        if (!Auth::validPassword($new)) {
            return $this->json($response, ['error' => Auth::PASSWORD_RULE], 400);
        }
        $changed = DB::transaction(static function () use ($new, $me): int {
            $changed = DB::execute(
                'UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL
                 WHERE id = ? AND password_hash = ? AND disabled = 0',
                [password_hash($new, PASSWORD_BCRYPT, ['cost' => 10]), (int) $me['id'], $me['password_hash']]
            );
            if ($changed === 1) \Pafish\Services\AppTokens::revokeAll((int) $me['id']);
            return $changed;
        });
        if ($changed !== 1) return $this->json($response, ['error' => '账号状态已变化，请重新登录后再试'], 409);
        return $this->json($response, ['ok' => true]);
    }
}
