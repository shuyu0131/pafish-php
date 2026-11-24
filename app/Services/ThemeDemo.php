<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Session;
use Psr\Http\Message\ResponseInterface;

/** Public demo selection belongs to one visitor, never to the published site. */
final class ThemeDemo
{
    private const SESSION_KEY = 'theme_demo';
    private static ?string $name = null;
    private static bool $vary = false;
    private static bool $private = false;

    /** Called after administrator preview preparation and before routes are registered. */
    public static function prepare(string $path, array $query, string $method): void
    {
        $previous = self::$name;
        self::$name = null;
        self::$vary = false;
        self::$private = false;
        if ((string) Settings::get('theme_demo_enabled', 'false') !== 'true') {
            Session::remove(self::SESSION_KEY);
            if ($previous !== null) Theme::reset();
            return;
        }
        if (!ThemePreview::isFrontend($path)) {
            if ($previous !== null) Theme::reset();
            return;
        }
        self::$vary = true;
        // Private administrator preview wins; a public link must not replace it.
        if (ThemePreview::name() !== null) {
            if ($previous !== null) Theme::reset();
            return;
        }
        if (in_array(strtoupper($method), ['GET', 'HEAD'], true) && array_key_exists('theme', $query)) {
            self::$private = true;
            Session::remove(self::SESSION_KEY);
            if (is_string($query['theme']) && $query['theme'] !== '') {
                Session::set(self::SESSION_KEY, $query['theme']);
            }
        }
        $selection = Session::get(self::SESSION_KEY);
        if ($selection !== null) {
            self::$private = true;
            if (is_string($selection) && Theme::describe($selection)['error'] === null) {
                try {
                    Theme::assertDependencies($selection);
                    self::$name = $selection;
                } catch (\Throwable) {
                    Session::remove(self::SESSION_KEY);
                }
            } else {
                Session::remove(self::SESSION_KEY);
            }
        }
        if ($previous !== self::$name) Theme::reset();
        if (self::$name !== null) {
            try {
                // Load the entry without activation or schema sync.
                if (!Theme::boot()) throw new \RuntimeException('主题入口加载失败');
            } catch (\Throwable $e) {
                self::fallback($e);
            }
        }
    }

    /** Plugins must be booted so their dynamic routes participate in conflict checks. */
    public static function validateRoutes(): void
    {
        if (self::$name === null) return;
        try {
            ExtensionRoutes::assertCanActivate('theme', self::$name, Theme::manifest(self::$name));
        } catch (\Throwable $e) {
            self::fallback($e);
            Theme::boot();
        }
    }

    private static function fallback(\Throwable $error): void
    {
        \Pafish\Core\Logger::error('[pafish-theme-demo] ' . self::$name . ' 演示不可用：' . $error->getMessage());
        Session::remove(self::SESSION_KEY);
        self::$name = null;
        Theme::reset();
    }

    public static function name(): ?string { return self::$name; }

    public static function isPrivate(): bool { return self::$private; }

    /** Also covers selection/reset links, redirects, HEAD and error responses. */
    public static function decorate(ResponseInterface $response): ResponseInterface
    {
        if (self::$vary) {
            $vary = array_filter(array_map('trim', explode(',', $response->getHeaderLine('Vary'))));
            if (!in_array('cookie', array_map('strtolower', $vary), true)) $vary[] = 'Cookie';
            $response = $response->withHeader('Vary', implode(', ', $vary));
        }
        if (!self::$private) return $response;
        return $response->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')->withHeader('Pragma', 'no-cache')
            ->withoutHeader('ETag')->withoutHeader('Last-Modified');
    }
}
