<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\Auth;
use Pafish\Services\AppTokens;
use Pafish\Services\LoginService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Authentication and device-token lifecycle for native management clients. */
final class AppAuthController
{
    public function login(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $attempt = LoginService::attempt(
            $request,
            trim((string) ($body['username'] ?? '')),
            (string) ($body['password'] ?? '')
        );
        if (!isset($attempt['user'])) {
            return $this->json($response, ['error' => $attempt['error'] ?? '登录失败'], (int) ($attempt['status'] ?? 401));
        }

        $user = $attempt['user'];
        try {
            $issued = AppTokens::issue((int) $user['id'], (string) ($body['deviceName'] ?? ''));
        } catch (\RuntimeException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 409);
        }
        LoginService::succeeded($user);

        return $this->json($response, [
            'ok' => true,
            'tokenType' => 'Bearer',
            'accessToken' => $issued['token'],
            'expiresAt' => $issued['expiresAt'],
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request, Response $response): Response
    {
        $auth = AppTokens::authenticate($request);
        if ($auth === null) return $this->unauthorized($response);
        return $this->json($response, ['user' => $this->userPayload($auth['user'])]);
    }

    public function tokens(Request $request, Response $response): Response
    {
        $auth = AppTokens::authenticate($request);
        if ($auth === null) return $this->unauthorized($response);
        $tokens = AppTokens::listForUser((int) $auth['user']['id']);
        foreach ($tokens as &$token) {
            $token['id'] = (int) $token['id'];
            $token['current'] = $token['id'] === $auth['tokenId'];
        }
        unset($token);
        return $this->json($response, ['tokens' => $tokens]);
    }

    public function revoke(Request $request, Response $response, array $args): Response
    {
        $auth = AppTokens::authenticate($request);
        if ($auth === null) return $this->unauthorized($response);
        $tokenId = (int) ($args['id'] ?? 0);
        if ($tokenId < 1 || !AppTokens::revoke((int) $auth['user']['id'], $tokenId)) {
            return $this->json($response, ['error' => '设备授权不存在或已撤销'], 404);
        }
        return $this->json($response, ['ok' => true]);
    }

    public function logout(Request $request, Response $response): Response
    {
        $auth = AppTokens::authenticate($request);
        if ($auth === null) return $this->unauthorized($response);
        AppTokens::revoke((int) $auth['user']['id'], $auth['tokenId']);
        return $this->json($response, ['ok' => true]);
    }

    private function userPayload(array $user): array
    {
        $role = (string) ($user['role'] ?? '');
        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'nickname' => (string) ($user['nickname'] ?? ''),
            'role' => $role,
            'avatarUrl' => $user['avatarUrl'] ?? $user['avatar_url'] ?? null,
            'capabilities' => Auth::capabilities($role),
        ];
    }

    private function unauthorized(Response $response): Response
    {
        return $this->json($response, ['error' => '访问令牌无效、已过期或已撤销'], 401);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        return Responses::json($response, $data, $status);
    }
}
