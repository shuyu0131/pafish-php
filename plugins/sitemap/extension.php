<?php

declare(strict_types=1);

use Pafish\Services\ExtensionContext;

return static function (ExtensionContext $ctx): void {
    $module = require __DIR__ . '/index.php';
    if (!is_array($module) || !is_callable($module['renderSitemap'] ?? null)) {
        throw new RuntimeException('sitemap index.php 必须返回 renderSitemap');
    }
    $ctx->provider('renderSitemap', $module['renderSitemap']);
};
