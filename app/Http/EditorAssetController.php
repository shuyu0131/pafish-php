<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Services\Editor;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class EditorAssetController
{
    public function serve(Request $request, Response $response, array $args): Response
    {
        $file = Editor::assetFile((string) ($args['plugin'] ?? ''), (string) ($args['path'] ?? ''));
        if ($file === null) {
            $response->getBody()->write('404 Not Found');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
        $types = [
            'js' => 'application/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'svg' => 'image/svg+xml', 'png' => 'image/png',
        ];
        $response->getBody()->write((string) file_get_contents($file));
        return $response->withHeader('Content-Type', $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))])
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'public, max-age=86400');
    }
}
