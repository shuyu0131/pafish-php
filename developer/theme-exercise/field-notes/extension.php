<?php

declare(strict_types=1);

return static function (\Pafish\Services\ExtensionContext $ctx): void {
    $ctx->inject('footer', static function (array $view, \Pafish\Services\ExtensionContext $ctx): string {
        $label = trim((string) ($ctx->getSettings()['studio_label'] ?? ''));
        if ($label === '') {
            return '';
        }

        return '<span class="fn-extension-note">' . e($label) . '</span>';
    });
};
