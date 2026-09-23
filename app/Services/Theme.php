<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Hooks;

/** 主题清单、设置、模板覆盖和安装生命周期管理。 */
final class Theme
{
    private const NAME_PATTERN = '/^[a-z0-9_-]{1,50}$/';
    private const TEMPLATE_NAME_PATTERN = '/^[a-z0-9-]{1,40}$/';
    private const FIELD_TYPES = ['text', 'textarea', 'checkbox', 'select', 'color', 'switcher', 'radio', 'image'];
    private const ASSET_EXTENSIONS = [
        'css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico',
        'woff', 'woff2', 'ttf', 'eot', 'otf', 'mp3', 'mp4', 'webm', 'ogg', 'wav',
        'json', 'map', 'txt', 'xml', 'webmanifest', 'wasm',
    ];
    private const MAX_ZIP_BYTES = 10 * 1024 * 1024;
    private const BACKUP_FORMAT = 'blogcms-theme-settings';

    private static ?array $manifests = [];
    private static ?array $modules = [];
    private static ?array $contexts = [];
    private static ?array $values = null;
    private static ?string $bootedTheme = null;
    private static ?string $schemaBootKey = null;

    public static function root(): string
    {
        return dirname(__DIR__, 2) . '/themes';
    }

    /** 当前激活主题名（默认 default；非法值回退 default） */
    public static function active(): string
    {
        $name = (string) Settings::get('active_theme', 'default');
        return self::isValidName($name) ? $name : 'default';
    }

    /** 加载当前主题的 PHP 扩展入口；每个请求只加载一次。 */
    public static function boot(): void
    {
        $name = self::active();
        // schema 兜底必须先于主题早退判断；按 manifest 版本/schema 记忆，避免一次请求内重复查库。
        $schemaKey = self::schemaBootKey($name);
        $schemaReady = true;
        if (self::$schemaBootKey !== $schemaKey) {
            $schemaReady = self::syncActiveSchema($name);
            if ($schemaReady) {
                self::$schemaBootKey = $schemaKey;
            }
        }
        if (!$schemaReady) {
            if (self::$bootedTheme !== null) {
                Hooks::removeByTag('theme:' . self::$bootedTheme);
            }
            // 允许同一长驻进程在下一次调用时重试失败的迁移。
            self::$bootedTheme = null;
            return;
        }
        if (self::$bootedTheme === $name) {
            return;
        }
        if (self::$bootedTheme !== null) {
            Hooks::removeByTag('theme:' . self::$bootedTheme);
        }
        self::$bootedTheme = $name;
        $file = self::root() . '/' . $name . '/helpers.php';
        if (is_file($file)) {
            // 主题 helper 只能影响主题钩子；加载失败时记录日志并继续使用核心页面。
            // 使用 require 仍支持同一长驻进程内切换主题后重新注册钩子。
            try {
                require $file;
            } catch (\Throwable $e) {
                error_log('[pafish-theme] 加载 ' . $name . ' helpers.php 失败：' . $e->getMessage());
                Hooks::removeByTag('theme:' . $name);
            }
        }
        self::registerHooks($name);
    }

    /** 主题清单签名；schema 或版本变化时，即使主题名未变也要重新同步。 */
    private static function schemaBootKey(string $name): string
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null || $desc['manifest'] === null) {
            return $name . ':invalid:' . (string) ($desc['error'] ?? 'manifest');
        }
        return $name . ':' . sha1(serialize([
            (string) ($desc['manifest']['version'] ?? ''),
            $desc['manifest']['schema'] ?? null,
        ]));
    }

    /** 渲染当前主题注册的后台/前台扩展注入。 */
    public static function renderInjection(string $hook, array $context = []): string
    {
        self::boot();
        $result = Hooks::applyFilters('theme_' . $hook, '', $context);
        return is_string($result) ? $result : '';
    }

    /** 当前主题编辑器专属样式；主题未提供时不输出任何内容。 */
    public static function editorHeadExtra(): string
    {
        self::boot();
        if (self::assetFile(self::active(), 'editor.css') === null) {
            return '';
        }
        $url = function_exists('theme_asset_url')
            ? theme_asset_url(self::active(), 'editor.css')
            : \Pafish\Core\Url::themeAsset(self::active(), 'editor.css');
        return '<link rel="stylesheet" href="' . e($url) . '">';
    }

    /** 解析主题静态文件的相对路径：优先主题根目录，兼容现有 assets/ 目录。 */
    public static function resolveAssetPath(string $name, string $path): ?string
    {
        if (!self::isValidName($name)) {
            return null;
        }
        $path = trim(str_replace('\\', '/', $path), '/');
        $explicitAssets = str_starts_with($path, 'assets/');
        if ($explicitAssets) {
            $path = substr($path, 7);
        }
        if (!self::isSafeAssetPath($path)) {
            return null;
        }

        $candidates = $explicitAssets
            ? ['assets/' . $path, $path]
            : [$path, 'assets/' . $path];
        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (is_file(self::root() . '/' . $name . '/' . $candidate)) {
                return $candidate;
            }
        }
        // 文件尚不存在时仍返回稳定的主题路径。
        return $candidates[0];
    }

    /** 返回主题静态文件的真实路径；仅允许浏览器静态资源扩展名。 */
    public static function assetFile(string $name, string $path): ?string
    {
        $relative = self::resolveAssetPath($name, $path);
        if ($relative === null) {
            return null;
        }
        $file = self::root() . '/' . $name . '/' . $relative;
        return is_file($file) ? $file : null;
    }

    private static function isSafeAssetPath(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")
            || str_contains($path, '\\') || str_contains($path, '//')) {
            return false;
        }
        return in_array(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)), self::ASSET_EXTENSIONS, true);
    }

    /** 扫描 themes/ 目录下的主题名。 */
    public static function list(): array
    {
        $names = [];
        foreach (glob(self::root() . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            if (str_starts_with($name, '.')) {
                continue;
            }
            if (self::isValidName($name)) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    /** 读取主题 manifest（theme.json）；无效返回 null（仅校验 name 一致性） */
    public static function manifest(string $name): ?array
    {
        if (!self::isValidName($name)) {
            return null;
        }
        if (array_key_exists($name, self::$manifests)) {
            return self::$manifests[$name];
        }
        $file = self::root() . '/' . $name . '/theme.json';
        if (!is_file($file)) {
            return self::$manifests[$name] = null;
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (!is_array($json) || ($json['name'] ?? null) !== $name) {
            return self::$manifests[$name] = null;
        }
        return self::$manifests[$name] = $json;
    }

    /**
     * 主题描述（列表页用）：返回 ['manifest' => ?array, 'error' => ?string]
     * 错误文案保持统一
     */
    public static function describe(string $name): array
    {
        if (!self::isValidName($name)) {
            return ['manifest' => null, 'error' => '主题名不合法'];
        }
        $file = self::root() . '/' . $name . '/theme.json';
        if (!is_file($file)) {
            return ['manifest' => null, 'error' => '缺少 theme.json'];
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (!is_array($json)) {
            return ['manifest' => null, 'error' => 'theme.json 不是合法 JSON'];
        }
        $error = self::validateManifest($json, $name);
        if ($error !== null) {
            return ['manifest' => null, 'error' => $error];
        }
        return ['manifest' => $json, 'error' => null];
    }

    /**
     * 校验 manifest；返回错误消息或 null。
     * 校验 name 一致、title/version 存在，并过滤 pageTemplates（全非法才报错）
     */
    public static function validateManifest(array $json, string $dirName): ?string
    {
        if (($json['name'] ?? null) !== $dirName) {
            return 'name 缺失或与目录名不一致';
        }
        if (!is_string($json['title'] ?? null) || $json['title'] === ''
            || !is_string($json['version'] ?? null) || $json['version'] === '') {
            return '缺少 title 或 version';
        }
        if (array_key_exists('pageTemplates', $json)) {
            if (!is_array($json['pageTemplates'])) {
                return 'pageTemplates 必须是数组';
            }
            $decls = [];
            foreach ($json['pageTemplates'] as $decl) {
                if (!is_array($decl)) {
                    continue;
                }
                $tplName = (string) ($decl['name'] ?? '');
                if ($tplName === '' || $tplName === 'default' || preg_match(self::TEMPLATE_NAME_PATTERN, $tplName) !== 1) {
                    continue;
                }
                if (!is_string($decl['title'] ?? null) || $decl['title'] === '') {
                    continue;
                }
                $decls[] = [
                    'name' => $tplName,
                    'title' => $decl['title'],
                    'description' => is_string($decl['description'] ?? null) ? $decl['description'] : '',
                ];
            }
            if ($json['pageTemplates'] !== [] && $decls === []) {
                return 'pageTemplates 声明无效';
            }
            $json['pageTemplates'] = $decls;
        }
        if (array_key_exists('settings', $json) && !is_array($json['settings'])) {
            return 'settings 必须是数组';
        }
        $routesError = ExtensionRoutes::validateDeclaration($json['routes'] ?? null, 'theme', $dirName);
        if ($routesError !== null) {
            return $routesError;
        }
        $schemaError = ExtensionSchema::validateDeclaration($json['schema'] ?? null, 'theme', $dirName);
        if ($schemaError !== null) {
            return $schemaError;
        }
        return null;
    }

    /** 加载主题业务模块；theme.php 可选，主题静态模板不受影响。 */
    public static function module(string $name): ?array
    {
        if (!self::isValidName($name)) {
            return null;
        }
        if (array_key_exists($name, self::$modules)) {
            return self::$modules[$name];
        }
        $file = self::root() . '/' . $name . '/theme.php';
        if (!is_file($file)) {
            return self::$modules[$name] = [];
        }
        try {
            $module = require $file;
            return self::$modules[$name] = is_array($module) ? $module : [];
        } catch (\Throwable $e) {
            error_log('[pafish-theme] 加载 ' . $name . ' theme.php 失败：' . $e->getMessage());
            return self::$modules[$name] = [];
        }
    }

    /** 当前主题业务上下文。 */
    public static function context(string $name): ExtensionContext
    {
        if (!self::isValidName($name)) {
            throw new \RuntimeException('主题名不合法');
        }
        if (isset(self::$contexts[$name])) {
            return self::$contexts[$name];
        }
        return self::$contexts[$name] = new ExtensionContext('theme', $name, 1);
    }

    /** 注册主题业务模块的钩子。 */
    private static function registerHooks(string $name): void
    {
        $module = self::module($name);
        if ($module === null || !is_callable($module['registerHooks'] ?? null)) {
            return;
        }
        try {
            $module['registerHooks'](self::context($name));
        } catch (\Throwable $e) {
            error_log('[pafish-theme] ' . $name . ' registerHooks 失败：' . $e->getMessage());
        }
    }

    /** 同步当前主题的表结构和版本迁移；失败时只隔离主题业务迁移。 */
    private static function syncActiveSchema(string $name): bool
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null || $desc['manifest'] === null) {
            error_log('[pafish-theme] ' . $name . ' schema 跳过：' . (string) ($desc['error'] ?? 'manifest 不可用'));
            return false;
        }
        try {
            $version = (string) ($desc['manifest']['version'] ?? '');
            $schema = $desc['manifest']['schema'] ?? null;
            $fromVersion = ExtensionSchema::installedVersion('theme', $name);
            $fingerprint = ExtensionSchema::fingerprint($schema);
            if (ExtensionSchema::installedFingerprint('theme', $name) !== $fingerprint) {
                ExtensionSchema::sync('theme', $name, $schema);
                ExtensionSchema::recordFingerprint('theme', $name, $schema);
            }
            self::runUpgrade($name, $fromVersion, $version);
            if ($fromVersion !== $version) {
                ExtensionSchema::recordVersion('theme', $name, $version);
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[pafish-theme] ' . $name . ' schema/upgrade 失败：' . $e->getMessage());
            return false;
        }
    }

    /** 主题业务生命周期回调，异常隔离，不能影响主题和核心页面渲染。 */
    private static function runLifecycle(string $name, string $phase, ?string $fromVersion = null, ?string $toVersion = null): void
    {
        $module = self::module($name);
        if ($module === null || !is_callable($module[$phase] ?? null)) {
            return;
        }
        try {
            if ($phase === 'onUpgrade') {
                $module[$phase](self::context($name), $fromVersion ?? '', $toVersion ?? '');
            } else {
                $module[$phase](self::context($name));
            }
        } catch (\Throwable $e) {
            error_log('[pafish-theme] ' . $name . ' ' . $phase . ' 失败：' . $e->getMessage());
        }
    }

    private static function runUpgrade(string $name, string $fromVersion, string $toVersion): void
    {
        if ($fromVersion === '' || $fromVersion === $toVersion) {
            return;
        }
        $module = self::module($name);
        if ($module === null || !is_callable($module['onUpgrade'] ?? null)) {
            return;
        }
        try {
            $module['onUpgrade'](self::context($name), $fromVersion, $toVersion);
        } catch (\Throwable $e) {
            throw new \RuntimeException('主题升级迁移失败：' . $e->getMessage(), 0, $e);
        }
    }

    /** 主题的私有 JSON 数据，按 theme_data:{name} 隔离。 */
    public static function data(string $name): array
    {
        if (!self::isValidName($name)) {
            return [];
        }
        $data = json_decode((string) Settings::get('theme_data:' . $name, ''), true);
        return is_array($data) ? $data : [];
    }

    public static function setData(string $name, array $data): void
    {
        if (!self::isValidName($name)) {
            throw new \RuntimeException('主题名不合法');
        }
        Settings::set('theme_data:' . $name, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /** 主题上下文保存设置时仅接纳自己的 schema 键。 */
    public static function setExtensionSettings(string $name, array $partial): void
    {
        $keys = array_flip(self::schemaKeys($name));
        foreach ($partial as $key => $value) {
            if (!isset($keys[$key]) || !is_scalar($value)) {
                continue;
            }
            Settings::set('theme:' . $key, (string) $value);
        }
        self::resetValues();
    }

    /** 主题运行日志保存在主题私有数据中，最多 50 条。 */
    public static function log(string $name, string $message): void
    {
        $data = self::data($name);
        $logs = is_array($data['logs'] ?? null) ? $data['logs'] : [];
        $logs[] = ['time' => date('Y-m-d H:i:s'), 'message' => $message];
        $data['logs'] = array_slice($logs, -50);
        self::setData($name, $data);
    }

    /** 主题设置的 schema 键清单 */
    public static function schemaKeys(string $name): array
    {
        $manifest = self::manifest($name);
        $keys = [];
        foreach (($manifest['settings'] ?? []) as $field) {
            if (is_array($field) && isset($field['key'])) {
                $keys[] = (string) $field['key'];
            }
        }
        return $keys;
    }

    /** 主题专属文章字段；主题未启用时由编辑器隐藏并原样保留。 */
    public static function editorFieldKeys(string $name): array
    {
        $manifest = self::manifest($name);
        $raw = is_array($manifest['editorFields'] ?? null) ? $manifest['editorFields'] : [];
        $keys = [];
        foreach ($raw as $key) {
            if (is_string($key) && preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/', $key) === 1) {
                $keys[] = $key;
            }
        }
        return array_values(array_unique($keys));
    }

    /** 所有非当前主题的专属文章字段，供编辑器隐藏但保留数据。 */
    public static function inactiveEditorFieldKeys(): array
    {
        $keys = [];
        foreach (self::list() as $name) {
            if ($name === self::active()) {
                continue;
            }
            $keys = array_merge($keys, self::editorFieldKeys($name));
        }
        return array_values(array_unique($keys));
    }

    /** 主题声明的页面模板（过滤后，不含 default） */
    public static function pageTemplates(string $name): array
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            return [];
        }
        return $desc['manifest']['pageTemplates'] ?? [];
    }

    /** 当前主题可用的组件区域；主题未声明时保留通用区域。 */
    public static function widgetAreas(string $name): array
    {
        $manifest = self::manifest($name);
        $areas = ['sidebar' => '侧边栏'];
        $declared = is_array($manifest['widgetAreas'] ?? null) ? $manifest['widgetAreas'] : [];
        foreach ($declared as $area) {
            if (!is_array($area)) {
                continue;
            }
            $key = (string) ($area['key'] ?? '');
            $label = trim((string) ($area['label'] ?? ''));
            if ($key !== '' && preg_match('/^[a-z0-9_-]{1,50}$/', $key) === 1 && $label !== '') {
                $areas[$key] = mb_substr($label, 0, 50);
            }
        }
        return $areas;
    }

    /** 主题 CSS（theme.css 内容，前台 <style> 注入）；无文件返回 null */
    public static function css(string $name): ?string
    {
        if (!self::isValidName($name)) {
            return null;
        }
        $file = self::root() . '/' . $name . '/theme.css';
        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /**
     * 主题布局样式（style.css 内容，前台 <style> 内联注入）；无文件返回 null。
     * 第三方主题未提供 style.css 时回退默认主题 default 的布局，保证始终有样式。
     */
    public static function layoutCss(string $name): ?string
    {
        if (!self::isValidName($name)) {
            $name = 'default';
        }
        $file = self::root() . '/' . $name . '/style.css';
        if (!is_file($file)) {
            $file = self::root() . '/default/style.css';
        }
        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /**
     * 指定主题的设置值：schema 默认值 + 已保存的 theme:{key} 覆盖
     */
    public static function valuesFor(string $name): array
    {
        $manifest = self::manifest($name);
        $values = [];
        foreach (($manifest['settings'] ?? []) as $field) {
            if (is_array($field) && isset($field['key'])) {
                $type = (string) ($field['type'] ?? '');
                $values[$field['key']] = (string) ($field['default'] ?? (($type === 'checkbox' || $type === 'switcher') ? '0' : ''));
            }
        }
        // 覆盖已保存值
        $all = Settings::all();
        foreach ($all as $key => $value) {
            if (str_starts_with($key, 'theme:') && isset($values[substr($key, 6)])) {
                $values[substr($key, 6)] = $value;
            }
        }
        return $values;
    }

    /** 当前激活主题的设置值（请求内缓存） */
    public static function values(): array
    {
        if (self::$values === null) {
            self::$values = self::valuesFor(self::active());
        }
        return self::$values;
    }

    public static function value(string $key, string $default = ''): string
    {
        $values = self::values();
        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    /**
     * 模板文件解析：主题覆盖优先，系统模板兜底
     * 模板名白名单防路径穿越
     */
    public static function template(string $name, array $context = []): string
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new \RuntimeException('非法的模板名：' . $name);
        }

        $baseCandidates = [$name];
        if ($name === 'post') {
            $categorySlug = $context['post']['category_slug'] ?? null;
            if (is_string($categorySlug) && preg_match('/^[a-z0-9_-]{1,50}$/', $categorySlug) === 1) {
                array_unshift($baseCandidates, 'post-' . $categorySlug);
            }
        }

        $filtered = Hooks::applyFilters('theme_template_candidates', $baseCandidates, [
            'template' => $name,
            'context' => $context,
            'theme' => self::active(),
        ]);
        $candidates = [];
        if (is_array($filtered)) {
            foreach ($filtered as $candidate) {
                if (is_string($candidate) && preg_match(self::NAME_PATTERN, $candidate) === 1 && !in_array($candidate, $candidates, true)) {
                    $candidates[] = $candidate;
                }
            }
        }
        if ($candidates === []) {
            $candidates = $baseCandidates;
        }

        foreach ($candidates as $candidate) {
            $themeFile = self::root() . '/' . self::active() . '/' . $candidate . '.php';
            if (is_file($themeFile)) {
                return $themeFile;
            }
        }
        foreach ($candidates as $candidate) {
            $systemFile = dirname(__DIR__) . '/Views/theme/' . $candidate . '.php';
            if (is_file($systemFile)) {
                return $systemFile;
            }
        }
        throw new \RuntimeException('模板不存在：' . $name);
    }

    /** 判断当前主题是否提供指定模板。 */
    public static function hasTemplate(string $name): bool
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return false;
        }
        return is_file(self::root() . '/' . self::active() . '/' . $name . '.php');
    }

    /** 切换激活主题 */
    public static function setActive(string $name): void
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            throw new \RuntimeException($desc['error'] ?? '主题不存在');
        }
        ExtensionRoutes::assertCanActivate('theme', $name, $desc['manifest']);
        $version = (string) $desc['manifest']['version'];
        $previousVersion = ExtensionSchema::installedVersion('theme', $name);
        $schema = $desc['manifest']['schema'] ?? null;
        ExtensionSchema::sync('theme', $name, $schema);
        ExtensionSchema::recordFingerprint('theme', $name, $schema);
        self::runUpgrade($name, $previousVersion, $version);
        ExtensionSchema::recordVersion('theme', $name, $version);
        $previous = self::active();
        if ($previous !== $name) {
            self::runLifecycle($previous, 'onDeactivate');
            self::runLifecycle($name, 'onActivate');
        }
        Settings::set('active_theme', $name);
        self::reset();
        self::boot();
    }

    /** 显式停用当前主题；系统回退到 default，主题数据保持不变。 */
    public static function deactivate(string $name): void
    {
        if (!self::isValidName($name) || self::active() !== $name) {
            throw new \RuntimeException('只能停用当前正在使用的主题');
        }
        if ($name === 'default') {
            throw new \RuntimeException('默认主题不能停用');
        }
        self::setActive('default');
    }

    /** 从 zip 缓冲安装主题；返回 name/title/version/updated。校验失败抛 RuntimeException（已回滚） */
    public static function installFromBuffer(string $buffer): array
    {
        $len = strlen($buffer);
        if ($len <= 0 || $len > self::MAX_ZIP_BYTES) {
            throw new \RuntimeException('zip 包大小不合法（上限 10MB）');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pfzip');
        if ($tmp === false || @file_put_contents($tmp, $buffer) === false) {
            throw new \RuntimeException('zip 包大小不合法（上限 10MB）');
        }
        $zip = new \ZipArchive();
        try {
            if ($zip->open($tmp) !== true) {
                throw new \RuntimeException('不是有效的 zip 压缩包');
            }
            $count = $zip->numFiles;
            if ($count <= 0) {
                throw new \RuntimeException('zip 包为空');
            }
            // 唯一顶层目录 + 穿越防护
            $top = null;
            for ($i = 0; $i < $count; $i++) {
                $entryName = (string) $zip->getNameIndex($i);
                $normalized = str_replace('\\', '/', $entryName);
                $trimmed = rtrim($normalized, '/');
                if ($trimmed === '') {
                    throw new \RuntimeException('主题包含意外路径：' . $entryName);
                }
                $parts = explode('/', $trimmed);
                if ($top === null) {
                    $top = $parts[0];
                } elseif ($top !== $parts[0]) {
                    throw new \RuntimeException('主题包必须只含一个顶层目录（主题名）');
                }
                foreach ($parts as $seg) {
                    if ($seg === '..' || $seg === '') {
                        throw new \RuntimeException('主题包含意外路径：' . $entryName);
                    }
                    if (str_contains($seg, ':')) {
                        throw new \RuntimeException('主题包含非法路径：' . $entryName);
                    }
                }
            }
            if (!self::isValidName((string) $top)) {
                throw new \RuntimeException('主题名不合法（仅限小写字母/数字/下划线/连字符）');
            }
            $target = self::root() . '/' . $top;
            $stage = self::root() . '/.' . $top . '.install-' . bin2hex(random_bytes(4));
            $backup = null;
            if (@mkdir($stage, 0755, true) === false) {
                throw new \RuntimeException('无法创建主题临时目录');
            }
            $extracted = @$zip->extractTo($stage);
            if (!$extracted) {
                $zip->close();
                self::rmDir($stage);
                throw new \RuntimeException('解压失败（已回滚）');
            }
            $zip->close();
            $stagedTarget = $stage . '/' . $top;
            if (!is_file($stagedTarget . '/theme.json')) {
                self::rmDir($stage);
                throw new \RuntimeException('安装失败：缺少 theme.json（已回滚）');
            }
            $json = json_decode((string) file_get_contents($stagedTarget . '/theme.json'), true);
            $error = is_array($json) ? self::validateManifest($json, (string) $top) : 'theme.json 不是合法 JSON';
            if ($error !== null) {
                self::rmDir($stage);
                throw new \RuntimeException('安装失败：' . $error . '（已回滚）');
            }
            $updated = is_dir($target);
            if ($updated) {
                $backup = self::root() . '/.' . $top . '.backup-' . bin2hex(random_bytes(4));
                if (!@rename($target, $backup)) {
                    self::rmDir($stage);
                    throw new \RuntimeException('无法替换已安装主题，请检查目录权限');
                }
            }
            if (!@rename($stagedTarget, $target)) {
                if ($backup !== null) {
                    @rename($backup, $target);
                }
                self::rmDir($stage);
                throw new \RuntimeException('主题更新失败（已回滚）');
            }
            self::rmDir($stage);
            if ($backup !== null) {
                self::rmDir($backup);
            }
            self::reset();
            return [
                'name' => (string) $top,
                'title' => (string) $json['title'],
                'version' => (string) $json['version'],
                'updated' => $updated,
            ];
        } finally {
            if ($zip->status !== \ZipArchive::ER_OK) {
                @$zip->close();
            }
            @unlink($tmp);
        }
    }

    /** 从 URL 下载并安装（30s 超时；仅 https，防中间人篡改） */
    public static function installFromUrl(string $url): array
    {
        if (preg_match('#^https://#', $url) !== 1) {
            throw new \RuntimeException('仅支持 https 下载地址');
        }
        return self::installFromBuffer(OutboundHttp::get($url, self::MAX_ZIP_BYTES));
    }

    /** 卸载主题：拒绝当前主题；默认保留主题设置和私有数据。 */
    public static function uninstall(string $name, bool $deleteData = false): void
    {
        if (!self::isValidName($name)) {
            throw new \RuntimeException('主题名不合法');
        }
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            throw new \RuntimeException('无法卸载：' . ($desc['error'] ?? '主题不存在'));
        }
        if (self::active() === $name) {
            throw new \RuntimeException('不能卸载当前正在使用的主题，请先切换到其他主题');
        }
        self::runLifecycle($name, 'onUninstall');
        if ($deleteData) {
            ExtensionSchema::drop('theme', $name, $desc['manifest']['schema'] ?? null);
            Settings::remove('theme_data:' . $name);
            ExtensionSchema::forget('theme', $name);
            $sharedKeys = array_flip(self::sharedSchemaKeys($name));
            foreach (self::schemaKeys($name) as $key) {
                if (!isset($sharedKeys[$key])) {
                    Settings::remove('theme:' . $key);
                }
            }
        }
        Hooks::removeByTag('theme:' . $name);
        if (!@self::rmDir(self::root() . '/' . $name)) {
            throw new \RuntimeException('删除主题目录失败');
        }
        self::reset();
    }

    /** 导出主题设置备份（JSON 数组） */
    public static function exportValues(string $name): array
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            throw new \RuntimeException($desc['error'] ?? '主题不存在');
        }
        // 全量导出（schema 默认 + 已保存覆盖）
        return [
            'format' => self::BACKUP_FORMAT,
            'theme' => $name,
            'exportedAt' => date('c'),
            'values' => self::valuesFor($name),
        ];
    }

    /**
     * 导入主题设置备份：只接受当前主题 schema 声明的键，其他自动忽略
     * 返回 ['imported' => 实际导入数, 'total' => 备份总键数]
     */
    public static function importValues(string $name, array $json): array
    {
        if (($json['format'] ?? null) !== self::BACKUP_FORMAT) {
            throw new \RuntimeException('不是本系统的主题设置备份文件');
        }
        if (($json['theme'] ?? null) !== $name) {
            throw new \RuntimeException('备份属于主题"' . (string) ($json['theme'] ?? '') . '"，与当前主题"' . $name . '"不一致');
        }
        if (!isset($json['values']) || !is_array($json['values'])) {
            throw new \RuntimeException('备份缺少 values 字段');
        }
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            throw new \RuntimeException($desc['error'] ?? '主题不存在');
        }
        $keys = array_flip(self::schemaKeys($name));
        $imported = 0;
        $total = count($json['values']);
        foreach ($json['values'] as $k => $v) {
            $k = (string) $k;
            if (!isset($keys[$k])) {
                continue;
            }
            Settings::set('theme:' . $k, is_scalar($v) ? (string) $v : '');
            $imported++;
        }
        self::resetValues();
        return ['imported' => $imported, 'total' => $total];
    }

    /** 清除静态缓存（安装/卸载/激活/保存设置后调用） */
    public static function reset(): void
    {
        self::$manifests = [];
        self::$modules = [];
        self::$contexts = [];
        self::resetValues();
    }

    public static function resetValues(): void
    {
        self::$values = null;
    }

    /** 其他主题共用的 schema key 集合（卸载时保护） */
    private static function sharedSchemaKeys(string $except): array
    {
        $shared = [];
        foreach (self::list() as $name) {
            if ($name === $except) {
                continue;
            }
            foreach (self::schemaKeys($name) as $key) {
                $shared[$key] = true;
            }
        }
        return $shared;
    }

    /** 递归删除目录（SPL 迭代器：避免 Windows 上 PHP 8.5 中 stat 句柄导致 rmdir「拒绝访问」） */
    private static function rmDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }
        $paths = [];
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $paths[] = [$item->getPathname(), $item->isDir()];
            }
        } catch (\UnexpectedValueException $e) {
            return !is_dir($dir);
        }
        foreach ($paths as [$path, $isDir]) {
            if (!self::rmRemove($path, $isDir)) {
                return false;
            }
        }
        return self::rmRemove($dir, true);
    }

    /** 删除文件/空目录；失败时换名重删（同 Plugin::rmRemove，见其注释） */
    private static function rmRemove(string $path, bool $isDir): bool
    {
        if ($isDir ? @rmdir($path) : @unlink($path)) {
            return true;
        }
        $tmp = dirname($path) . '/.' . basename($path) . '.del' . bin2hex(random_bytes(3));
        if (!@rename($path, $tmp)) {
            return false;
        }
        return $isDir ? @rmdir($tmp) : @unlink($tmp);
    }

    private static function isValidName(string $name): bool
    {
        return $name !== '' && preg_match(self::NAME_PATTERN, $name) === 1;
    }
}
