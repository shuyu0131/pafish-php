<?php

declare(strict_types=1);

namespace Pafish\Http;

use Psr\Http\Message\ResponseInterface;

/** HTTP 响应的公共构造器，避免各控制器重复实现 JSON 编码和响应头。 */
final class Responses
{
    public static function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
