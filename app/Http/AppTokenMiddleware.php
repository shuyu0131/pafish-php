<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\Auth;
use Pafish\Core\RateLimiter;
use Pafish\Services\AppTokens;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/** Adds verified app-token identity to selected existing JSON APIs. */
final class AppTokenMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
        $authorization = trim($request->getHeaderLine('Authorization'));
        if (preg_match('/^Bearer\b/i', $authorization) !== 1) {
            return $handler->handle($request);
        }

        $auth = AppTokens::authenticate($request);
        if ($auth === null) {
            return $this->json(401, '访问令牌无效、已过期或已撤销');
        }
        if (!RateLimiter::allow('app-token-request', (string) $auth['tokenId'], 120, 60)) {
            return $this->json(429, '请求过于频繁，请稍后重试');
        }

        return Auth::withBearerUser((int) $auth['user']['id'], static fn (): Response => $handler->handle($request));
    }

    private function json(int $status, string $message): Response
    {
        $response = new \Slim\Psr7\Response($status);
        $response->getBody()->write(json_encode(['error' => $message], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
