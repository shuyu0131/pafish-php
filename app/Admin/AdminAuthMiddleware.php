<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\RateLimiter;
use Pafish\Core\RedirectException;
use Pafish\Core\Session;
use Pafish\Core\Url;
use Pafish\Services\AppTokens;
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
                $response->getBody()->write(json_encode(['error' => '访问令牌无效、已过期或已撤销'], JSON_UNESCAPED_UNICODE));
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
                $response->getBody()->write(json_encode(['error' => 'Bearer 令牌不允许调用此后台接口'], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
            }
            if (!$safeBatchOperation) {
                $response = new \Slim\Psr7\Response(403);
                $response->getBody()->write(json_encode(['error' => 'Bearer 令牌不允许执行此批量操作'], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
            }
            if (!RateLimiter::allow('app-token-request', (string) $auth['tokenId'], 120, 60)) {
                $response = new \Slim\Psr7\Response(429);
                $response->getBody()->write(json_encode(['error' => '请求过于频繁，请稍后重试'], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
            }
            return Auth::withBearerUser((int) $auth['user']['id'], static fn (): Response => $handler->handle($request));
        }

        if (!Auth::check()) {
            $uri = $request->getUri();
            $from = $uri->getPath();
            if ($uri->getQuery() !== '') {
                $from .= '?' . $uri->getQuery();
            }
            $from = Url::localPathOr($from, '/admin');
            throw new RedirectException(Url::to('/login') . '?from=' . rawurlencode($from));
        }

        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $token = is_array($body) ? (string) ($body['_csrf'] ?? '') : '';
            if (!Session::verifyCsrf($token)) {
                $home = e(Url::to('/admin'));
                $html = '<!doctype html><html lang="zh-CN"><meta charset="utf-8">'
                    . '<title>CSRF 校验失败</title>'
                    . '<body style="font-family:system-ui, sans-serif;display:grid;place-items:center;'
                    . 'min-height:100vh;margin:0;background:#f6f7f9;color:#333">'
                    . '<div style="text-align:center;max-width:420px;padding:24px">'
                    . '<p style="font-size:44px;margin:0">' . admin_icon('shield', 44) . '</p>'
                    . '<h1 style="font-size:18px;margin:14px 0 8px">CSRF 校验失败</h1>'
                    . '<p style="color:#71717a;font-size:14px;line-height:1.7">表单已过期或令牌无效，'
                    . '请返回上一页刷新后重试。</p>'
                    . '<a href="' . $home . '" style="display:inline-block;margin-top:14px;'
                    . 'padding:8px 20px;border-radius:8px;background:#4786d6;color:#fff;'
                    . 'text-decoration:none;font-size:14px">返回工作台</a>'
                    . '</div></body></html>';
                $response = new \Slim\Psr7\Response(419);
                $response->getBody()->write($html);
                return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
            }
        }

        return $handler->handle($request);
    }
}
