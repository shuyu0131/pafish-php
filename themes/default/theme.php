<?php

declare(strict_types=1);

return [
    'registerCsfOptions' => static function (): void {
        \PafishCsf\CSF::createOptions('default_options', [
            'plugin_title' => '默认主题设置',
            'footer_text' => '与默认主题现有设置项对应；CSF 保存值优先，未配置时沿用主题设置。',
        ]);

        \PafishCsf\CSF::createSection('default_options', [
            'title' => '内容',
            'icon' => 'file-text',
            'fields' => [
                [
                    'id' => 'footer_text',
                    'type' => 'text',
                    'title' => '页脚文案',
                    'default' => \Pafish\Services\Theme::value('footer_text', ''),
                    'placeholder' => '留空使用默认（© 年份 站点名 · 用 PHP 构建）',
                ],
                [
                    'id' => 'show_custom_fields',
                    'type' => 'checkbox',
                    'title' => '文章页显示自定义字段',
                    'default' => \Pafish\Services\Theme::value('show_custom_fields', '1'),
                ],
            ],
        ]);

        \PafishCsf\CSF::createSection('default_options', [
            'title' => '布局',
            'icon' => 'layout',
            'fields' => [
                [
                    'id' => 'sidebar_enabled',
                    'type' => 'checkbox',
                    'title' => '显示桌面侧边栏',
                    'default' => \Pafish\Services\Theme::value('sidebar_enabled', '1'),
                ],
                [
                    'id' => 'show_related_posts',
                    'type' => 'checkbox',
                    'title' => '文章页显示相关推荐',
                    'default' => \Pafish\Services\Theme::value('show_related_posts', '1'),
                ],
            ],
        ]);
    },

    'registerHooks' => static function (\Pafish\Services\ExtensionContext $context): void {
        $context->filter('theme_setting_value', static function (mixed $value, string $key): mixed {
            if (!function_exists('_csf') || !in_array($key, [
                'footer_text',
                'show_custom_fields',
                'sidebar_enabled',
                'show_related_posts',
            ], true)) {
                return $value;
            }

            $configured = _csf('default_options', $key, $value);
            return is_scalar($configured) ? (string) $configured : $value;
        });
    },
];
