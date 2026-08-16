<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\Auth;
use Pafish\Core\Session;
use Pafish\Services\LuminaRedPacket;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LuminaRedPacketController
{
    public function claim(Request $request, Response $response, array $args): Response
    {
        $user = Auth::user();
        if (!$user) {
            return $this->json($response, ['error' => '请先登录', 'login_url' => \url_to('/login')], 401);
        }
        $body = $request->getParsedBody() ?? [];
        if (!Session::verifyCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->json($response, ['error' => '会话已过期，请刷新页面重试'], 419);
        }
        try {
            return $this->json($response, ['ok' => true] + LuminaRedPacket::claim((int) ($args['postId'] ?? 0), (int) $user['id']));
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response = $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response;
    }
}
