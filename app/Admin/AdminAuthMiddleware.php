<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\RateLimiter;
use Pafish\Core\RedirectException;
use Pafish\Core\Session;
use Pafish\Core\Url;
use Pafish\Services\AppTokens;
use Pafish\Http\Responses;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * 后台守卫中间件：统一处理后台权限和 CSRF 校验。
 * - 未登录 → 302 /login?from=<原地址>（登录后跳回）
 * - /admin 下所有 POST 请求校验 CSRF 令牌
 */
final class AdminAuthMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
        $authorization = trim($request->getHeaderLine('Authorization'));
        if (preg_match('/^Bearer\b/i', $authorization) === 1) {
            $auth = AppTokens::authenticate($request);
            if ($auth === null) {
                $response = new \Slim\Psr7\Response(401);
                $response->getBody()->write(json_encode(['error' => t('admin.the_access_token_is_invalid_expired_or_revoked')], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
            }
            $path = $request->getUri()->getPath();
            $allowedPath = preg_match('~(?:^|/)admin/posts/(?:save|batch|[0-9]+/save)$~D', $path) === 1
                || preg_match('~(?:^|/)admin/comments/(?:batch|[0-9]+/(?:status|reply|pin|delete))$~D', $path) === 1;
            $body = $request->getParsedBody();
            $batchOperation = is_array($body) ? (string) ($body['op'] ?? '') : '';
            $safeBatchOperation = !str_ends_with($path, '/batch')
                || (str_contains($path, '/posts/')
                    ? in_array($batchOperation, ['publish', 'draft', 'pin', 'unpin'], true)
                    : in_array($batchOperation, ['approve', 'spam', 'delete'], true));
            if (!$allowedPath || $request->getMethod() !== 'POST'
                || strtolower($request->getHeaderLine('X-Requested-With')) !== 'xmlhttprequest') {
                $response = new \Slim\Psr7\Response(405);
                $response->getBody()->write(json_encode(['error' => t('admin.bearer_tokens_cannot_access_this_admin_endpoint')], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
            }
            if (!$safeBatchOperation) {
                $response = new \Slim\Psr7\Response(403);
                $response->getBody()->write(json_encode(['error' => t('admin.bearer_tokens_cannot_perform_this_batch_operation')], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
            }
            if (!RateLimiter::allow('app-token-request', (string) $auth['tokenId'], 120, 60)) {
                $response = new \Slim\Psr7\Response(429);
                $response->getBody()->write(json_encode(['error' => t('admin.too_many_requests_please_try_again_later')], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
            }
            return Auth::withBearerUser((int) $auth['user']['id'], static fn (): Response => $handler->handle($request));
        }

        if (!Auth::check()) {
            if ($this->expectsJson($request)) {
                return Responses::json(new \Slim\Psr7\Response(), [
                    'error' => t('js.session_expired_message'), 'code' => 'AUTH_REQUIRED',
                ], 401)->withHeader('Cache-Control', 'no-store');
            }
            $uri = $request->getUri();
            $from = $uri->getPath();
            if ($uri->getQuery() !== '') {
                $from .= '?' . $uri->getQuery();
            }
            $from = Url::localPathOr($from, '/admin');
            $login = Url::to('/login');
            throw new RedirectException($login . (str_contains($login, '?') ? '&' : '?') . 'from=' . rawurlencode($from));
        }

        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $token = is_array($body) ? (string) ($body['_csrf'] ?? '') : '';
            if (!Session::verifyCsrf($token)) {
                if ($this->expectsJson($request)) {
                    return Responses::json(new \Slim\Psr7\Response(), [
                        'error' => t('js.session_page_expired_message'), 'code' => 'CSRF_EXPIRED',
                    ], 419)->withHeader('Cache-Control', 'no-store');
                }
                $home = e(Url::to('/admin'));
                $html = '<!doctype html><html lang="' . e(\Pafish\Core\I18n::locale()) . '"><meta charset="utf-8">'
                    . ('<title>' . e(t('admin.csrf_verification_failed')) . '</title>')
                    . '<body style="font-family:system-ui, sans-serif;display:grid;place-items:center;'
                    . 'min-height:100vh;margin:0;background:#f6f7f9;color:#333">'
                    . '<div style="text-align:center;max-width:420px;padding:24px">'
                    . '<p style="font-size:44px;margin:0">' . admin_icon('shield', 44) . '</p>'
                    . ('<h1 style="font-size:18px;margin:14px 0 8px">' . e(t('admin.csrf_verification_failed')) . '</h1>')
                    . ('<p style="color:#71717a;font-size:14px;line-height:1.7">' . e(t('admin.csrf_expired_explanation')) . '</p>')
                    . '<a href="' . $home . '" style="display:inline-block;margin-top:14px;'
                    . 'padding:8px 20px;border-radius:8px;background:#4786d6;color:#fff;'
                    . ('text-decoration:none;font-size:14px">' . e(t('admin.back_to_dashboard')) . '</a>')
                    . '</div></body></html>';
                $response = new \Slim\Psr7\Response(419);
                $response->getBody()->write($html);
                return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
            }
        }

        return $handler->handle($request);
    }

    private function expectsJson(Request $request): bool
    {
        return strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest'
            || str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }
}
