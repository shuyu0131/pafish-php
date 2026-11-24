<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\{Auth, Session, Url};
use Psr\Http\Message\ResponseInterface;

/** A private, expiring theme selection owned by the current administrator session. */
final class ThemePreview
{
    private const SESSION_KEY = 'theme_preview';
    private static ?string $name = null;
    private static bool $sessionActive = false;

    public static function start(string $name): void
    {
        if (!Auth::isAdmin() || Auth::isBearerAuthenticated()) throw new \DomainException('无权预览主题');
        $desc = Theme::describe($name);
        if ($desc['error'] !== null) throw new \InvalidArgumentException($desc['error']);
        Theme::assertDependencies($name);
        Session::set(self::SESSION_KEY, ['name' => $name, 'user_id' => Auth::id(), 'expires' => time() + 3600]);
    }

    public static function stop(): void
    {
        Session::remove(self::SESSION_KEY);
        self::$name = null;
        self::$sessionActive = false;
        Theme::reset();
    }

    /** Called before Theme::boot, using a base/PATH_INFO/query-mode normalized path. */
    public static function prepare(string $path): void
    {
        $previous = self::$name;
        self::$name = null;
        self::$sessionActive = false;
        $selection = Session::get(self::SESSION_KEY);
        if (is_array($selection)) {
            if (($selection['expires'] ?? 0) <= time() || !Auth::isAdmin() || Auth::isBearerAuthenticated()
                || ($selection['user_id'] ?? null) !== Auth::id()
                || !is_string($selection['name'] ?? null) || Theme::describe($selection['name'])['error'] !== null) {
                Session::remove(self::SESSION_KEY);
            } else {
                self::$sessionActive = true;
                if (self::isFrontend($path)) self::$name = $selection['name'];
            }
        }
        if ($previous !== self::$name) Theme::reset();
    }

    public static function isFrontend(string $path): bool
    {
        return preg_match('~^/(?:admin|api|plugin|login|register|forgot-password|reset-password|logout|rss\.xml|sitemap\.xml|robots\.txt)(?:/|$)~i', $path) !== 1;
    }

    public static function name(): ?string { return self::$name; }

    public static function blocksWrite(string $path): bool
    {
        return self::$sessionActive
            && preg_match('~^/(?:admin(?:/|$)|(?:login|logout|register|forgot-password|reset-password)$|api/(?:upload(?:/chunk)?|uploads|md-preview|auth/[a-z-]+)$)~i', $path) !== 1;
    }

    /** Preview pages (including redirects/errors) must never enter shared caches. */
    public static function decorate(ResponseInterface $response, string $method): ResponseInterface
    {
        if (!self::$sessionActive) return $response;
        $response = $response->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')->withHeader('Pragma', 'no-cache')
            ->withoutHeader('ETag')->withoutHeader('Last-Modified');
        $vary = array_filter(array_map('trim', explode(',', $response->getHeaderLine('Vary'))));
        if (!in_array('cookie', array_map('strtolower', $vary), true)) $vary[] = 'Cookie';
        $response = $response->withHeader('Vary', implode(', ', $vary));
        if (self::$name === null || $method === 'HEAD' || $response->getStatusCode() !== 200) return $response;
        $type = $response->getHeaderLine('Content-Type');
        if ($type !== '' && stripos($type, 'text/html') === false) return $response;
        $html = (string) $response->getBody();
        if (stripos($html, '</body>') === false) return $response;
        $manifest = Theme::manifest(self::$name);
        $banner = '<link rel="stylesheet" href="' . \e(Url::asset('/css/theme-preview.css')) . '">'
            . '<aside class="pafish-theme-preview-bar" aria-label="' . \e(\t('admin.theme_preview')) . '">'
            . '<span><strong>' . \e(\t('admin.theme_preview')) . ': ' . \e($manifest['title'] ?? self::$name) . '</strong> '
            . \e(\t('admin.theme_preview_notice')) . '</span>'
            . '<a href="' . \e(Url::to('/admin/appearance/' . self::$name)) . '">' . \e(\t('admin.settings')) . '</a>'
            . '<form method="post" action="' . \e(Url::to('/admin/appearance/preview/stop')) . '">'
            . \csrf_field() . '<button type="submit">' . \e(\t('admin.exit_theme_preview')) . '</button></form></aside>';
        $position = strripos($html, '</body>');
        $body = new \Slim\Psr7\Stream(fopen('php://temp', 'r+'));
        $body->write(substr($html, 0, $position) . $banner . substr($html, $position));
        return $response->withBody($body)->withoutHeader('Content-Length')->withoutHeader('ETag')->withoutHeader('Last-Modified');
    }
}
