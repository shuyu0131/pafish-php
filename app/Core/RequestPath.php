<?php

declare(strict_types=1);

namespace Pafish\Core;

/** Canonicalize front-controller, subdirectory and query-string route forms. */
final class RequestPath
{
    /** @return array{string,string} canonical path and route-relative path */
    public static function normalize(string $path, string $base, ?string $queryPath = null, ?string $pathInfo = null): array
    {
        $path = rawurldecode(parse_url($path, PHP_URL_PATH) ?: '/');
        $path = '/' . ltrim($path, '/');

        $relative = $path;
        if ($base !== '' && ($relative === $base || str_starts_with($relative, $base . '/'))) {
            $relative = substr($relative, strlen($base));
            $relative = $relative === '' ? '/' : $relative;
        }
        $relative = '/' . ltrim($relative, '/');
        $frontController = $relative === '/' || $relative === '/index.php';

        if ($relative === '/index.php') {
            if (is_string($pathInfo) && $pathInfo !== '' && $pathInfo !== '/index.php') {
                $pathInfo = rawurldecode(parse_url($pathInfo, PHP_URL_PATH) ?: '');
                $relative = str_starts_with($pathInfo, '/index.php/')
                    ? substr($pathInfo, strlen('/index.php'))
                    : '/' . ltrim($pathInfo, '/');
                $relative = $relative === '' ? '/' : $relative;
                $frontController = false;
            } else {
                $relative = '/';
            }
        } elseif (str_starts_with($relative, '/index.php/')) {
            $relative = substr($relative, strlen('/index.php')) ?: '/';
            $frontController = false;
        }

        if ($frontController && is_string($queryPath) && $queryPath !== '') {
            $relative = '/' . ltrim(rawurldecode($queryPath), '/');
            $relative = $relative === '' ? '/' : $relative;
        }

        $canonical = $base . ($relative === '/' ? '/' : $relative);
        return [$canonical === '' ? '/' : $canonical, $relative];
    }
}
