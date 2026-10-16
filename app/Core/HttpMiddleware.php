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
            [$canonical] = $normalizePath($request->getUri()->getPath(), is_string($queryPath) ? $queryPath : null);
            $uri = $request->getUri();
            if ($canonical !== $uri->getPath()) {
                $request = $request->withUri($uri->withPath($canonical));
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
