<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Services\Theme;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Serves theme static assets without exposing PHP templates. */
final class ThemeAssetController
{
    private const TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'otf' => 'font/otf',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'json' => 'application/json; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml',
        'webmanifest' => 'application/manifest+json; charset=utf-8',
        'wasm' => 'application/wasm',
    ];

    public function serve(Request $request, Response $response, array $args): Response
    {
        $theme = (string) ($args['theme'] ?? '');
        $path = ltrim((string) ($args['path'] ?? ''), '/');
        if (
            preg_match('/^[a-z0-9_-]{1,50}$/', $theme) !== 1
            || $path === ''
            || str_contains($path, '..')
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_contains($path, '//')
        ) {
            return $this->notFound($response);
        }

        $resolved = Theme::resolveAssetPath($theme, $path);
        if ($resolved === null) {
            return $this->notFound($response);
        }
        $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            return $this->notFound($response);
        }

        $file = Theme::assetFile($theme, $path);
        if ($file === null) {
            return $this->notFound($response);
        }

        $response->getBody()->write((string) file_get_contents($file));
        return $response
            // 某些服务器通过 error_page 404 转发到入口，明确设置成功状态。
            ->withStatus(200)
            ->withHeader('Content-Type', self::TYPES[$ext])
            ->withHeader('Content-Length', (string) filesize($file))
            ->withHeader('Cache-Control', 'public, max-age=86400');
    }

    private function notFound(Response $response): Response
    {
        $response->getBody()->write('404 Not Found');
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
