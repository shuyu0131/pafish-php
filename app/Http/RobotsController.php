<?php

declare(strict_types=1);

namespace Pafish\Http;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * robots.txt（放行全站 + 指向 sitemap）
 * robots.txt 输出
 */
final class RobotsController
{
    public function index(Request $request, Response $response): Response
    {
        $body = "User-agent: *\nAllow: /\n\nSitemap: " . \absolute_url('/sitemap.xml');
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
