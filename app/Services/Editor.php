<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Hooks;
use Pafish\Core\Url;

/** 编辑器扩展替换编辑界面；核心负责持久化与渲染正文格式。 */
final class Editor
{
    /**
     * 返回当前全站生效的编辑器。
     * 编辑器插件在激活时互斥，编辑页不再让用户逐篇切换；没有可用插件时回退核心编辑器。
     */
    public static function configured(string $type): array
    {
        $providers = self::providers($type);
        if ($providers !== []) return $providers[0];
        return ['id' => 'default', 'label' => '系统编辑器（Markdown）', 'format' => 'markdown', 'scripts' => [], 'styles' => []];
    }

    public static function providers(string $type): array
    {
        $providers = Hooks::applyFilters('admin_editors', [], ['type' => $type]);
        $result = [];
        foreach (is_array($providers) ? $providers : [] as $id => $provider) {
            if (!is_string($id) || preg_match('/^[a-z0-9_-]{1,50}$/', $id) !== 1
                || $id === 'default' || !is_array($provider)) {
                continue;
            }
            $plugin = (string) ($provider['plugin'] ?? '');
            $label = trim((string) ($provider['label'] ?? ''));
            if ($label === '' || !in_array($plugin, Plugin::activeNames(), true)) {
                continue;
            }
            $assets = ['scripts' => [], 'styles' => []];
            foreach ($assets as $kind => $_) {
                foreach ((array) ($provider[$kind] ?? []) as $path) {
                    if (!is_string($path) || self::assetFile($plugin, $path) === null) {
                        continue 3;
                    }
                    $assets[$kind][] = self::assetUrl($plugin, $path);
                }
            }
            if ($assets['scripts'] !== []) {
                $format = $provider['format'] ?? 'markdown';
                if (!in_array($format, ['markdown', 'html'], true)) {
                    continue;
                }
                $result[] = ['id' => $id, 'label' => $label, 'format' => $format] + $assets;
            }
        }
        return $result;
    }

    public static function assetUrl(string $plugin, string $path): string
    {
        $file = self::assetFile($plugin, $path);
        if ($file === null) {
            throw new \InvalidArgumentException('编辑器资源不存在');
        }
        $url = Url::to('/editor-assets/' . $plugin . '/' . implode('/', array_map('rawurlencode', explode('/', $path))));
        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . filemtime($file);
    }

    /** 仅公开启用插件 assets/ 下的静态文件，真实路径也必须留在该目录内。 */
    public static function assetFile(string $plugin, string $path): ?string
    {
        if (preg_match('/^[a-z0-9_-]{1,50}$/', $plugin) !== 1
            || !in_array($plugin, Plugin::activeNames(), true)
            || !str_starts_with($path, 'assets/')
            || preg_match('#^[a-zA-Z0-9_./-]+$#D', $path) !== 1
            || str_contains($path, '..') || str_contains($path, '//')
            || !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['js', 'css', 'woff', 'woff2', 'svg', 'png'], true)) {
            return null;
        }
        $root = realpath(Plugin::root() . '/' . $plugin . '/assets');
        $file = realpath(Plugin::root() . '/' . $plugin . '/' . $path);
        if ($root === false || $file === false || !is_file($file)) {
            return null;
        }
        $prefix = $root . DIRECTORY_SEPARATOR;
        $inside = DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($file, $prefix, strlen($prefix)) === 0
            : str_starts_with($file, $prefix);
        return $inside ? $file : null;
    }
}
