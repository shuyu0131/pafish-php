<?php

declare(strict_types=1);

namespace Pafish\Core;

use Slim\App;

/** Install the shared request middleware stack used by production and integration tests. */
final class HttpMiddleware
{
    public static function install(App $app, callable $normalizePath, bool $debug): void
    {
        $app->addBodyParsingMiddleware();
        $app->add(function ($request, $handler) use ($normalizePath) {
            $query = $request->getQueryParams();
            $queryPath = $query['p'] ?? ($_GET['p'] ?? null);
            $normalizedPath = $normalizePath($request->getUri()->getPath(), is_string($queryPath) ? $queryPath : null);
            $canonical = (string) ($normalizedPath[0] ?? '/');
            $routePath = (string) ($normalizedPath[1] ?? $canonical);
            if (!isset($normalizedPath[1])) {
                $basePath = rtrim(Url::base(), '/');
                if ($basePath !== '' && ($routePath === $basePath || str_starts_with($routePath, $basePath . '/'))) {
                    $routePath = substr($routePath, strlen($basePath)) ?: '/';
                }
            }
            $uri = $request->getUri();
            if ($canonical !== $uri->getPath()) {
                $request = $request->withUri($uri->withPath($canonical));
            }
            if (in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) {
                $target = \Pafish\Services\PermalinkRedirects::targetFor((string) $routePath);
                if ($target !== null) {
                    $location = Url::to($target);
                    $queryString = \Pafish\Services\PermalinkRedirects::queryWithoutRouteParam($request->getUri()->getQuery());
                    if ($queryString !== '') $location .= (str_contains($location, '?') ? '&' : '?') . $queryString;
                    return (new \Slim\Psr7\Response(301))->withHeader('Location', $location);
                }
            }
            return $handler->handle($request);
        });
        $app->add(function ($request, $handler) {
            try {
                return $handler->handle($request);
            } catch (RedirectException $e) {
                return (new \Slim\Psr7\Response(302))->withHeader('Location', $e->target);
            }
        });
        $errorMiddleware = $app->addErrorMiddleware($debug, true, true);
        $errorMiddleware->setDefaultErrorHandler(new ErrorHandler($app->getCallableResolver(), $app->getResponseFactory()));
    }
}
