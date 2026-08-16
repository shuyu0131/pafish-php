<?php

declare(strict_types=1);

if (!function_exists('lumina_asset_url')) {
    function lumina_asset_url(string $path): string
    {
        $path = ltrim($path, '/');
        $file = __DIR__ . '/assets/' . $path;
        $url = url_to('/theme-assets/lumina/' . $path);
        return is_file($file) ? $url . '?v=' . (string) filemtime($file) : $url;
    }
}

if (!function_exists('lumina_theme_image')) {
    function lumina_theme_image(string $key, string $fallback): string
    {
        $value = trim(theme_value($key));
        return $value !== '' ? $value : $fallback;
    }
}

if (!function_exists('lumina_post_link')) {
    function lumina_post_link(array $post): string
    {
        $external = trim((string) ($post['external_url'] ?? ''));
        return $external !== '' ? $external : url_to('/post/' . rawurlencode((string) ($post['slug'] ?? '')));
    }
}
