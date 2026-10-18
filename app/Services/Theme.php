<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Hooks;

/** 主题清单、设置、模板覆盖和安装生命周期管理。 */
final class Theme
{
    public const API_VERSION = 2;
    private const NAME_PATTERN = '/^[a-z0-9_-]{1,50}$/';
    private const TEMPLATE_NAME_PATTERN = '/^[a-z0-9-]{1,40}$/';
    private const ASSET_EXTENSIONS = [
        'css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico',
        'woff', 'woff2', 'ttf', 'eot', 'otf', 'mp3', 'mp4', 'webm', 'ogg', 'wav',
        'json', 'map', 'txt', 'xml', 'webmanifest', 'wasm',
    ];
    private const MAX_ZIP_BYTES = 10 * 1024 * 1024;
    private const BACKUP_FORMAT = 'blogcms-theme-settings';
    private const DEFAULT_VERSION = '1.0.0';
    private const INJECT_TARGETS = [
        'head', 'footer', 'sidebar',
        'comment_form', 'login_form', 'register_form', 'post_editor', 'micro_editor',
    ];
    private const USER_DATA = ['posts', 'stats', 'reactions', 'comments', 'points'];
    private const PAGE_TEMPLATES = ['index', 'post', 'page', 'category', 'tag', 'search', 'archives', 'micro', 'user'];

    private static ?array $manifests = [];
    private static ?array $contexts = [];
    private static ?array $values = null;
    private static array $changedCache = [];
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
        if (!self::isValidName($name) || !self::isThemeDirectory(self::root() . '/' . $name)) {
            return 'default';
        }
        return self::manifest($name) !== null ? $name : 'default';
    }

    /** 加载当前主题的 PHP 扩展入口；每个请求只加载一次。 */
    public static function boot(): bool
    {
        $name = self::active();
        $manifest = self::manifest($name);
        if ($manifest === null) {
            ExtensionKernel::reset('theme', $name);
            self::$bootedTheme = null;
            return false;
        }
        try {
            ExtensionDependencies::assertSatisfied('theme', $name, $manifest['requires'] ?? []);
        } catch (\Throwable $e) {
            \Pafish\Core\Logger::error('[pafish-theme] ' . $name . ' 依赖检查失败：' . $e->getMessage());
            ExtensionKernel::reset('theme', $name);
            self::$bootedTheme = null;
            return false;
        }
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
            ExtensionKernel::reset('theme', $name);
            if (self::$bootedTheme !== null) {
                ExtensionKernel::reset('theme', self::$bootedTheme);
            }
            // 允许同一长驻进程在下一次调用时重试失败的迁移。
            self::$bootedTheme = null;
            return false;
        }
        if (self::$bootedTheme === $name) {
            return true;
        }
        if (self::$bootedTheme !== null) {
            ExtensionKernel::reset('theme', self::$bootedTheme);
        }
        if (is_file(self::root() . '/' . $name . '/extension.php')
            && !ExtensionKernel::boot('theme', $name, self::root(), self::context($name))) {
            // 有动态入口但注册失败时，不让主题的业务钩子继续进入请求。
            // 下次请求仍会重试，核心页面保持可用。
            self::$bootedTheme = null;
            ExtensionKernel::reset('theme', $name);
            return false;
        }
        self::$bootedTheme = $name;
        self::registerHooks($name);
        return true;
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
        if (!in_array($hook, self::INJECT_TARGETS, true)) {
            return '';
        }
        $parts = [];
        $name = self::active();
        $manifest = self::manifest($name);
        if (is_array($manifest)
            && ((int) ($manifest['apiVersion'] ?? 1) < 2
                || in_array($hook, (array) ($manifest['injects'] ?? []), true))) {
            foreach (ExtensionKernel::injectionsFor('theme', $name, $hook) as $handler) {
                try {
                    $html = $handler($context, self::context($name));
                    if (is_string($html) && trim($html) !== '') {
                        $parts[] = $html;
                    }
                } catch (\Throwable $e) {
                    \Pafish\Core\Logger::error('[pafish-theme] ' . $name . ' renderInjection(' . $hook . ') 失败：' . $e->getMessage());
                }
            }
            $provider = ExtensionKernel::providerFor('theme', $name, 'renderInjection');
            if ($provider !== null && ExtensionKernel::injectionsFor('theme', $name, $hook) === []) {
                try {
                    $html = $provider($hook, self::context($name), $context);
                    if (is_string($html) && trim($html) !== '') {
                        $parts[] = $html;
                    }
                } catch (\Throwable $e) {
                    \Pafish\Core\Logger::error('[pafish-theme] ' . $name . ' renderInjection(' . $hook . ') 失败：' . $e->getMessage());
                }
            }
        }
        $result = Hooks::applyFilters('theme_' . $hook, implode("\n", $parts), $context);
        $html = is_string($result) ? $result : '';
        return $hook === 'head' ? self::parseInjectionTags($html) : $html;
    }

    /** 主题 head 注入与插件使用同一套标签白名单。 */
    private static function parseInjectionTags(string $html): string
    {
        $out = '';
        foreach ([
            '/<script\\b[^>]*>[\\s\\S]*?<\\/script>/i',
            '/<meta\\b[^>]*>/i',
            '/<link\\b[^>]*>/i',
            '/<style\\b[^>]*>[\\s\\S]*?<\\/style>/i',
        ] as $pattern) {
            if (preg_match_all($pattern, $html, $matches) !== false) {
                $out .= implode("\n", $matches[0]);
            }
        }
        return $out;
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

    /** 扫描 themes/ 目录下的主题名；PHP 模板主题无需 theme.json。 */
    public static function list(): array
    {
        $names = [];
        foreach (glob(self::root() . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            if (str_starts_with($name, '.')) {
                continue;
            }
            if (self::isValidName($name) && self::isThemeDirectory($dir)) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    /** 读取主题清单；纯模板主题可以没有 extension.php。 */
    public static function manifest(string $name): ?array
    {
        if (!self::isValidName($name)) {
            return null;
        }
        if (array_key_exists($name, self::$manifests)) {
            return self::$manifests[$name];
        }
        $description = self::describe($name);
        return self::$manifests[$name] = $description['manifest'];
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
        $dir = self::root() . '/' . $name;
        if (!is_dir($dir) || !self::isThemeDirectory($dir)) {
            return ['manifest' => null, 'error' => '主题目录不存在或缺少模板文件'];
        }
        $file = $dir . '/theme.json';
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
        return ['manifest' => self::normalizeManifest($json, $name), 'error' => null];
    }

    /**
     * 校验 manifest；返回错误消息或 null。
     * 校验 name 一致，并过滤可选的 pageTemplates（全非法才报错）。
     * title/version 缺省时由 normalizeManifest() 提供默认值。
     */
    public static function validateManifest(array $json, string $dirName): ?string
    {
        if (($json['name'] ?? null) !== $dirName) {
            return 'name 缺失或与目录名不一致';
        }
        if (array_key_exists('title', $json) && (!is_string($json['title']) || trim($json['title']) === '')) {
            return 'title 必须是非空字符串';
        }
        if (array_key_exists('version', $json) && (!is_string($json['version']) || trim($json['version']) === '')) {
            return 'version 必须是非空字符串';
        }
        if (array_key_exists('apiVersion', $json)
            && (!is_int($json['apiVersion']) || $json['apiVersion'] < 1 || $json['apiVersion'] > self::API_VERSION)) {
            return 'apiVersion 不受支持';
        }
        $dependencyError = ExtensionDependencies::validate($json['requires'] ?? null);
        if ($dependencyError !== null) {
            return $dependencyError;
        }
        $metaError = EntityMetaSchema::validate($json['meta'] ?? null);
        if ($metaError !== null) return $metaError;
        if (array_key_exists('injects', $json)) {
            if (!is_array($json['injects'])
                || array_filter($json['injects'], static fn ($target): bool => !is_string($target) || !in_array($target, self::INJECT_TARGETS, true)) !== []) {
                return 'injects 包含无效注入点';
            }
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
        if (array_key_exists('userData', $json)
            && (!is_array($json['userData'])
                || array_filter($json['userData'], static fn ($key): bool => !is_string($key) || !in_array($key, self::USER_DATA, true)) !== [])) {
            return 'userData 包含无效数据项';
        }
        if (array_key_exists('systemTemplateFallback', $json)
            && (!is_array($json['systemTemplateFallback'])
                || array_filter($json['systemTemplateFallback'], static fn ($key): bool => !is_string($key) || !in_array($key, self::PAGE_TEMPLATES, true)) !== [])) {
            return 'systemTemplateFallback 包含无效模板';
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

    /** 将可选清单补齐为核心内部使用的稳定结构。 */
    private static function normalizeManifest(array $json, string $name): array
    {
        $json['name'] = $name;
        $json['title'] = trim((string) ($json['title'] ?? $name)) ?: $name;
        $json['version'] = trim((string) ($json['version'] ?? self::DEFAULT_VERSION)) ?: self::DEFAULT_VERSION;
        $json['apiVersion'] = is_int($json['apiVersion'] ?? null) ? $json['apiVersion'] : 1;
        $json['requires'] = ExtensionDependencies::normalize($json['requires'] ?? null);
        $json['meta'] = EntityMetaSchema::normalize($json['meta'] ?? null);
        $json['injects'] = is_array($json['injects'] ?? null)
            ? array_values(array_unique(array_intersect(self::INJECT_TARGETS, $json['injects'])))
            : [];
        $json['userData'] = is_array($json['userData'] ?? null)
            ? array_values(array_unique(array_intersect(self::USER_DATA, $json['userData'])))
            : [];
        if (is_array($json['systemTemplateFallback'] ?? null)) {
            $json['systemTemplateFallback'] = array_values(array_unique(array_intersect(self::PAGE_TEMPLATES, $json['systemTemplateFallback'])));
        } else {
            $json['systemTemplateFallback'] = null;
        }
        foreach (['description', 'author', 'authorUrl', 'homepage'] as $key) {
            if (!isset($json[$key]) || !is_string($json[$key])) {
                $json[$key] = '';
            }
        }
        foreach (['settings', 'pageTemplates', 'widgetAreas', 'editorFields'] as $key) {
            if (!isset($json[$key]) || !is_array($json[$key])) {
                $json[$key] = [];
            }
        }
        return $json;
    }

    /**
     * 主题至少提供一个实际的模板或样式/封面文件。
     *
     * theme.json 和 extension.php 只是描述/业务入口，不能单独把一个
     * 没有任何展示层的目录识别为主题，否则激活后会静默落到系统模板。
     */
    private static function isThemeDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        foreach (['header.php', 'footer.php', 'index.php', 'post.php', 'page.php', 'category.php', 'tag.php', 'search.php', 'archives.php', 'micro.php', 'user.php', 'style.css', 'theme.css', 'preview.jpg', 'preview.png', 'assets/css/style.css', 'assets/css/theme.css', 'assets/preview.jpg', 'assets/preview.png'] as $file) {
            if (is_file($dir . '/' . $file)) {
                return true;
            }
        }
        $directory = realpath($dir);
        foreach (scandir($dir) ?: [] as $entry) {
            if (preg_match('/^(?:page-|post-template-)([a-z0-9-]{1,40})\.php$/D', $entry, $match) !== 1 || $match[1] === 'default') continue;
            $file = realpath($dir . '/' . $entry);
            if ($file !== false && dirname($file) === $directory && is_file($file) && is_readable($file)) return true;
        }
        return false;
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
        $apiVersion = (int) (self::manifest($name)['apiVersion'] ?? 1);
        return self::$contexts[$name] = new ExtensionContext('theme', $name, $apiVersion);
    }

    /** 注册主题业务模块的钩子。 */
    private static function registerHooks(string $name): void
    {
        $handler = ExtensionKernel::providerFor('theme', $name, 'registerHooks');
        if ($handler === null) {
            return;
        }
        try {
            $handler(self::context($name));
        } catch (\Throwable $e) {
            \Pafish\Core\Logger::error('[pafish-theme] ' . $name . ' registerHooks 失败：' . $e->getMessage());
        }
    }

    /** 同步当前主题的表结构和版本迁移；失败时只隔离主题业务迁移。 */
    private static function syncActiveSchema(string $name): bool
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null || $desc['manifest'] === null) {
            \Pafish\Core\Logger::error('[pafish-theme] ' . $name . ' schema 跳过：' . (string) ($desc['error'] ?? 'manifest 不可用'));
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
            // 版本迁移可能由 extension.php 注册，先加载统一入口再执行 onUpgrade。
            if (is_file(self::root() . '/' . $name . '/extension.php')
                && !ExtensionKernel::boot('theme', $name, self::root(), self::context($name))) {
                throw new \RuntimeException('主题入口加载失败');
            }
            self::runUpgrade($name, $fromVersion, $version);
            if ($fromVersion !== $version) {
                ExtensionSchema::recordVersion('theme', $name, $version);
            }
            return true;
        } catch (\Throwable $e) {
            \Pafish\Core\Logger::error('[pafish-theme] ' . $name . ' schema/upgrade 失败：' . $e->getMessage());
            return false;
        }
    }

    /** 主题业务生命周期回调，异常隔离，不能影响主题和核心页面渲染。 */
    private static function runLifecycle(string $name, string $phase, ?string $fromVersion = null, ?string $toVersion = null): void
    {
        $args = $phase === 'onUpgrade'
            ? [$fromVersion ?? '', $toVersion ?? '']
            : [];
        if (ExtensionKernel::runLifecycle('theme', $name, $phase, $args, $phase === 'onUpgrade')) {
            return;
        }
        $handler = ExtensionKernel::providerFor('theme', $name, $phase);
        if ($handler === null) {
            return;
        }
        try {
            if ($phase === 'onUpgrade') {
                $handler(self::context($name), $fromVersion ?? '', $toVersion ?? '');
            } else {
                $handler(self::context($name));
            }
        } catch (\Throwable $e) {
            \Pafish\Core\Logger::error('[pafish-theme] ' . $name . ' ' . $phase . ' 失败：' . $e->getMessage());
        }
    }

    private static function runUpgrade(string $name, string $fromVersion, string $toVersion): void
    {
        if ($fromVersion === '' || $fromVersion === $toVersion) {
            return;
        }
        if (ExtensionKernel::runLifecycle('theme', $name, 'onUpgrade', [$fromVersion, $toVersion], true)) {
            return;
        }
        $handler = ExtensionKernel::providerFor('theme', $name, 'onUpgrade');
        if ($handler === null) {
            return;
        }
        try {
            $handler(self::context($name), $fromVersion, $toVersion);
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
        ExtensionStorage::updateJson('theme_data:' . $name, static function (array $current) use ($data): array {
            if (is_array($current['logs'] ?? null)) {
                $data['logs'] = $current['logs'];
            } else {
                unset($data['logs']);
            }
            return $data;
        });
    }

    /** 主题上下文保存设置时仅接纳自己的 schema 键。 */
    public static function setExtensionSettings(string $name, array $partial): void
    {
        $prefix = self::settingsPrefix($name);
        $fields = [];
        foreach (ExtensionKernel::flattenFields(self::settingsFields($name)) as $field) {
            if (isset($field['key']) && !ExtensionKernel::isDecorativeType((string) ($field['type'] ?? ''))) {
                $fields[(string) $field['key']] = $field;
            }
        }
        $pairs = [];
        foreach ($partial as $key => $value) {
            $key = (string) $key;
            if (!isset($fields[$key]) || !is_scalar($value)) {
                continue;
            }
            $value = (string) $value;
            $error = ExtensionKernel::validateSettingValue($fields[$key], $value);
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
            $pairs[$prefix . $key] = $value;
        }
        Settings::setMany($pairs);
        self::resetValues();
    }

    /** 主题运行日志保存在主题私有数据中，最多 50 条。 */
    public static function log(string $name, string $message): void
    {
        ExtensionStorage::updateJson('theme_data:' . $name, static function (array $data) use ($message): array {
            $logs = is_array($data['logs'] ?? null) ? $data['logs'] : [];
            $logs[] = ['time' => date('Y-m-d H:i:s'), 'message' => $message];
            $data['logs'] = array_slice($logs, -50);
            return $data;
        });
    }

    /**
     * 主题设置的 schema 键清单（真实存库的设置项）。
     *
     * 必须展平 `group` 容器：容器自身不存值，里面的子字段才是设置项。
     * 否则导出会漏掉容器内的设置，卸载主题时也会留下孤儿键。
     */
    public static function schemaKeys(string $name): array
    {
        $keys = [];
        foreach (ExtensionKernel::flattenFields(self::settingsFields($name)) as $field) {
            if (isset($field['key']) && !ExtensionKernel::isDecorativeType((string) ($field['type'] ?? ''))) {
                $keys[] = (string) $field['key'];
            }
        }
        return $keys;
    }

    /**
     * 主题设置字段（合并 theme.json 声明与运行时 $ctx->settings() 注册）。
     *
     * 两条来源都必须经过 ExtensionKernel 规范化：
     * 否则 theme.json 里声明的 desc / section / maxlength / pattern 等约束会原样透传
     * （键名不在统一的 rules 结构里），渲染层读不到、保存校验也拿不到——
     * 表现为"主题里写了校验，但后台既不显示提示也不拦截"。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function settingsFields(string $name): array
    {
        $manifest = self::manifest($name);
        $fields = [];
        foreach ((array) ($manifest['settings'] ?? []) as $field) {
            $normalized = ExtensionKernel::describeSetting($field, 'theme');
            if ($normalized !== null) {
                $fields[(string) $normalized['key']] = $normalized;
            }
        }
        foreach (ExtensionKernel::settingsFor('theme', $name) as $field) {
            if (is_array($field) && isset($field['key'])) {
                $fields[(string) $field['key']] = $field;
            }
        }
        return array_values($fields);
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

    /** 当前主题根目录中的 page-*.php；清单仅提供可选显示名。 */
    public static function pageTemplates(string $name): array
    {
        return self::customTemplates($name, 'page-');
    }

    /** 文章自定义布局与分类覆盖文件分开命名，不要求清单声明。 */
    public static function postTemplates(string $name): array
    {
        return self::customTemplates($name, 'post-template-');
    }

    private static function customTemplates(string $name, string $prefix): array
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            return [];
        }
        $labels = [];
        foreach (($prefix === 'page-' ? ($desc['manifest']['pageTemplates'] ?? []) : []) as $decl) {
            if (is_array($decl) && is_string($decl['name'] ?? null) && is_string($decl['title'] ?? null)) {
                $labels[$decl['name']] = $decl['title'];
            }
        }
        $templates = [];
        foreach (scandir(self::root() . '/' . $name) ?: [] as $file) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '([a-z0-9-]{1,40})\.php$/D', $file, $match) !== 1) continue;
            $key = $match[1];
            $path = self::customTemplateFile($name, $key, $prefix);
            if ($path === null) continue;
            $title = $labels[$key] ?? '';
            if ($title === '') {
                $header = file_get_contents($path, false, null, 0, 8192);
                // 只读取 PHP 文件头注释，不执行模板，也不从正文 HTML 提取标题。
                if (is_string($header) && preg_match('/\A\s*<\?php\s*(?:declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*)?\/\*([\s\S]*?)\*\//', $header, $comment) === 1
                    && preg_match('/@name\h+([^\r\n*]+)/u', $comment[1], $label) === 1) {
                    $title = trim($label[1]);
                }
            }
            $templates[] = ['name' => $key, 'title' => mb_substr($title !== '' ? $title : $key, 0, 100)];
        }
        return $templates;
    }

    /** 只接受当前主题根目录的真实文件，拒绝目录穿越及指向目录外的链接。 */
    public static function pageTemplateFile(string $theme, string $template): ?string
    {
        return self::customTemplateFile($theme, $template, 'page-');
    }

    public static function postTemplateFile(string $theme, string $template): ?string
    {
        return self::customTemplateFile($theme, $template, 'post-template-');
    }

    private static function customTemplateFile(string $theme, string $template, string $prefix): ?string
    {
        if (!self::isValidName($theme) || $template === 'default'
            || preg_match(self::TEMPLATE_NAME_PATTERN, $template) !== 1) return null;
        $directory = realpath(self::root() . '/' . $theme);
        if ($directory === false) return null;
        $file = realpath($directory . '/' . $prefix . $template . '.php');
        return $file !== false && dirname($file) === $directory && is_file($file) && is_readable($file) ? $file : null;
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

    /** 主题样式文件定位：优先 assets/css/ 子目录，根目录位置保留兼容旧主题。 */
    private static function findAsset(string $name, string $file): ?string
    {
        foreach (['assets/css/', ''] as $dir) {
            $path = self::root() . '/' . $name . '/' . $dir . $file;
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /** 主题 CSS（theme.css 内容，前台 <style> 注入）；无文件返回 null */
    public static function css(string $name): ?string
    {
        if (!self::isValidName($name)) {
            return null;
        }
        $file = self::findAsset($name, 'theme.css');
        return $file !== null ? (string) file_get_contents($file) : null;
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
        $file = self::findAsset($name, 'style.css') ?? self::findAsset('default', 'style.css');
        return $file !== null ? (string) file_get_contents($file) : null;
    }

    /**
     * 本主题相对 schema 默认值真正被改过的设置项。
     *
     * 只认本主题 schema 里声明过的键：值直接透传等于允许往页面上写任意样式
     * （连 </style> 都能闭合出去），所以一切输出都以这张表为准。
     * 空值视为「没设置」，不参与输出——默认值本身由 style.css 负责，
     * 没改过的主题因此不产生任何额外 CSS。
     */
    public static function changedOptions(string $name): array
    {
        if (isset(self::$changedCache[$name])) {
            return self::$changedCache[$name];
        }
        $defaults = [];
        foreach (ExtensionKernel::flattenFields(self::settingsFields($name)) as $field) {
            if (is_array($field) && isset($field['key'])) {
                $defaults[(string) $field['key']] = (string) ($field['default'] ?? '');
            }
        }

        $changed = [];
        foreach (self::valuesFor($name) as $key => $value) {
            $key = (string) $key;
            $value = (string) $value;
            if ($value !== '' && $value !== ($defaults[$key] ?? '')) {
                $changed[$key] = $value;
            }
        }

        return self::$changedCache[$name] = $changed;
    }

    /**
     * 由主题设置值生成的补充样式，追加在主题自带 style.css 之后。
     *
     * 正文尺寸落在 .md-content 而不是 body：rem 以根元素为基准，
     * 改 body 的 font-size 影响不到正文（正文尺寸写死在 .md-content 上）。
     */
    public static function optionsCss(string $name): string
    {
        $changed = self::changedOptions($name);
        if ($changed === []) {
            return '';
        }

        $num = static function (string $key, float $min, float $max) use ($changed): ?float {
            $v = $changed[$key] ?? '';
            if ($v === '' || !is_numeric($v)) {
                return null;
            }
            $f = (float) $v;
            return ($f >= $min && $f <= $max) ? $f : null;
        };
        $fmt = static fn (float $v): string => ($v == (int) $v) ? (string) (int) $v : (string) round($v, 2);
        $hex = static function (string $key) use ($changed): string {
            $v = trim($changed[$key] ?? '');
            if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v, $m) !== 1) {
                return '';
            }
            $h = $m[1];
            if (strlen($h) === 3) {
                $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
            }
            return '#' . strtolower($h);
        };
        $json = static function (string $key) use ($changed): array {
            $d = json_decode($changed[$key] ?? '', true);
            return is_array($d) ? $d : [];
        };
        $in = static fn (string $v, array $allowed): string => in_array($v, $allowed, true) ? $v : '';

        $root = [];
        $dark = [];
        $body = [];
        $darkBody = [];
        $rules = [];

        // 主色：单色值优先于预设，预设只是把常用色填进单色字段。
        $accent = $hex('accent_color') !== '' ? $hex('accent_color') : $hex('accent_preset');
        if ($accent !== '') {
            $rgb = sscanf($accent, '#%02x%02x%02x');
            $r = (int) ($rgb[0] ?? 0);
            $g = (int) ($rgb[1] ?? 0);
            $b = (int) ($rgb[2] ?? 0);
            $root[] = '--accent:' . $accent;
            $root[] = sprintf('--accent-soft:rgba(%d,%d,%d,.1)', $r, $g, $b);
            $lift = static fn (int $c): int => (int) round($c + (255 - $c) * 0.55);
            $dark[] = sprintf('--accent:#%02x%02x%02x', $lift($r), $lift($g), $lift($b));
            $dark[] = sprintf('--accent-soft:rgba(%d,%d,%d,.16)', $lift($r), $lift($g), $lift($b));
        }

        $fontPreset = $in((string) ($changed['font_preset'] ?? ''), ['system', 'serif', 'mono']);
        $fontStack = [
            'serif' => 'Georgia,"Songti SC","SimSun",serif',
            'mono' => '"JetBrains Mono",Consolas,"Courier New",monospace',
        ][$fontPreset] ?? '';
        if ($fontStack !== '') {
            $root[] = '--font-sans:' . $fontStack;
        }

        $bodySize = $num('body_size', 12, 24);
        if ($bodySize !== null) {
            $rules[] = '.md-content{font-size:' . $fmt($bodySize) . 'px}';
        }

        $width = $num('content_width', 480, 2000);
        if ($width !== null) {
            $rules[] = '.container,.app-footer-inner{max-width:' . $fmt($width) . 'px}';
        }

        // 背景：图片只放行 http(s) 与站内绝对路径，挡掉 javascript: 之类；
        // 尺寸必须跟位置写在一起，否则 background-size 会被简写整个覆盖掉。
        $bg = $json('page_bg');
        if ($bg !== []) {
            $parts = [];
            $bgColor = '';
            $c = trim((string) ($bg['color'] ?? ''));
            if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $c) === 1) {
                $hc = ltrim($c, '#');
                if (strlen($hc) === 3) {
                    $hc = $hc[0] . $hc[0] . $hc[1] . $hc[1] . $hc[2] . $hc[2];
                }
                $bgColor = '#' . strtolower($hc);
            }
            $imgUrl = '';
            $img = trim((string) ($bg['image'] ?? ''));
            if ($img !== '' && preg_match('~^(https?://|/)[\w\-./%?=&:@!\~+]*$~', $img) === 1) {
                $imgUrl = $img;
            }
            if ($imgUrl !== '') {
                $parts[] = 'url("' . $imgUrl . '")';
            }
            if ($bgColor !== '') {
                $parts[] = $bgColor;
            }
            if ($parts !== []) {
                if ($imgUrl !== '') {
                    $repeat = $in((string) ($bg['repeat'] ?? ''), ['no-repeat', 'repeat', 'repeat-x', 'repeat-y']);
                    $position = $in((string) ($bg['position'] ?? ''), ['left top', 'left center', 'left bottom', 'center top', 'center center', 'center bottom', 'right top', 'right center', 'right bottom']);
                    $size = $in((string) ($bg['size'] ?? ''), ['auto', 'cover', 'contain']);
                    $attach = $in((string) ($bg['attachment'] ?? ''), ['scroll', 'fixed']);
                    $parts[] = $repeat !== '' ? $repeat : 'no-repeat';
                    $parts[] = ($position !== '' ? $position : 'center center') . '/' . ($size !== '' ? $size : 'auto');
                    if ($attach !== '') {
                        $parts[] = $attach;
                    }
                }
                $body[] = 'background:' . implode(' ', $parts);
                // 深色模式靠 style.css 的 .dark body 底色压住背景，这里补回同样效果。
                if ($imgUrl === '' && $bgColor !== '') {
                    $darkBody[] = 'background:var(--bg)';
                }
            }
        }

        $head = $json('heading_type');
        if ($head !== []) {
            $h = [];
            $family = isset($head['family']) ? self::cssFontFamily((string) $head['family']) : '';
            if ($family !== '') {
                $h[] = 'font-family:' . $family . ',var(--font-sans)';
            }
            $size = isset($head['size']) && is_numeric($head['size']) ? (float) $head['size'] : null;
            if ($size !== null && $size >= 12 && $size <= 80) {
                $h[] = 'font-size:' . $fmt($size) . 'px';
            }
            $weight = isset($head['weight']) ? (string) $head['weight'] : '';
            if (preg_match('/^[1-9]00$/', $weight) === 1) {
                $h[] = 'font-weight:' . $weight;
            }
            $lh = isset($head['line_height']) && is_numeric($head['line_height']) ? (float) $head['line_height'] : null;
            if ($lh !== null && $lh > 0 && $lh <= 4) {
                $h[] = 'line-height:' . $fmt($lh);
            }
            $ls = isset($head['letter_spacing']) && is_numeric($head['letter_spacing']) ? (float) $head['letter_spacing'] : null;
            if ($ls !== null && abs($ls) <= 20) {
                $h[] = 'letter-spacing:' . $fmt($ls) . 'px';
            }
            if ($h !== []) {
                $rules[] = '.post-card-title,.post-title{' . implode(';', $h) . '}';
            }
        }

        if (($changed['content_align'] ?? '') === 'justify') {
            $rules[] = '.md-content p,.md-content li{text-align:justify}';
        }

        // 列表形态走 body 类名，避免与 style.css 里的选择器权重打架。
        $listStyle = $in((string) ($changed['list_style'] ?? ''), ['list', 'card', 'compact']);
        if ($listStyle === 'card') {
            $rules[] = 'body.ts-list-card .post-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(16rem,1fr));gap:1.25rem}';
            $rules[] = 'body.ts-list-card .post-card{padding-left:1.5rem;padding-right:1.5rem;border:1px solid var(--card-border-color,var(--border));border-radius:var(--card-radius,8px)}';
            $rules[] = 'body.ts-list-card .post-card-excerpt{-webkit-line-clamp:3}';
        } elseif ($listStyle === 'compact') {
            $rules[] = 'body.ts-list-compact .post-card{padding:.875rem 0}';
            $rules[] = 'body.ts-list-compact .post-card-title{font-size:1.125rem;font-weight:normal}';
            $rules[] = 'body.ts-list-compact .post-card-excerpt{display:none}';
        }

        $radius = $num('card_radius', 0, 14);
        if ($radius !== null) {
            $root[] = '--card-radius:' . $fmt($radius) . 'px';
        }

        // 边框同时承担「列表分隔线」与「卡片描边」两种角色；
        // 宽度为 0 时必须显式关掉，否则列表模式下仍有分隔线残留。
        $border = $json('card_border');
        if ($border !== []) {
            $bw = isset($border['width']) && is_numeric($border['width']) ? (float) $border['width'] : null;
            $bs = $in((string) ($border['style'] ?? ''), ['solid', 'dashed', 'dotted']);
            $bc = '';
            $bcRaw = trim((string) ($border['color'] ?? ''));
            if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $bcRaw) === 1) {
                $hb = ltrim($bcRaw, '#');
                if (strlen($hb) === 3) {
                    $hb = $hb[0] . $hb[0] . $hb[1] . $hb[1] . $hb[2] . $hb[2];
                }
                $bc = '#' . strtolower($hb);
            }
            if ($bw !== null && $bw >= 0 && $bw <= 50) {
                if ($bw == 0) {
                    $rules[] = '.post-card{border-bottom:0}';
                    $rules[] = 'body.ts-list-card .post-card{border:0}';
                } else {
                    $spec = $fmt($bw) . 'px ' . ($bs !== '' ? $bs : 'solid') . ' ' . ($bc !== '' ? $bc : 'var(--border)');
                    if ($bc !== '') {
                        $root[] = '--card-border-color:' . $bc;
                    }
                    $rules[] = '.post-card{border-bottom:' . $spec . '}';
                    $rules[] = 'body.ts-list-card .post-card{border:' . $spec . '}';
                }
            }
        }

        // 间距放最后：要让 .post-list .post-card 压过上面的卡片内边距。
        $gap = $json('list_gap');
        if ($gap !== []) {
            $g = [];
            $gt = isset($gap['top']) && is_numeric($gap['top']) ? (float) $gap['top'] : null;
            $gb = isset($gap['bottom']) && is_numeric($gap['bottom']) ? (float) $gap['bottom'] : null;
            if ($gt !== null && $gt >= 0 && $gt <= 200) {
                $g[] = 'padding-top:' . $fmt($gt) . 'px';
            }
            if ($gb !== null && $gb >= 0 && $gb <= 200) {
                $g[] = 'padding-bottom:' . $fmt($gb) . 'px';
            }
            if ($g !== []) {
                $rules[] = '.post-list .post-card{' . implode(';', $g) . '}';
            }
        }

        $css = '';
        if ($root !== []) {
            $css .= ':root{' . implode(';', $root) . "}\n";
        }
        if ($dark !== []) {
            $css .= '.dark{' . implode(';', $dark) . "}\n";
        }
        if ($body !== []) {
            $css .= 'body{' . implode(';', $body) . "}\n";
        }
        if ($darkBody !== []) {
            $css .= '.dark body{' . implode(';', $darkBody) . "}\n";
        }
        if ($rules !== []) {
            $css .= implode("\n", $rules) . "\n";
        }

        // 自定义 CSS 最后一条，允许覆盖上面全部生成规则。
        $custom = trim((string) ($changed['custom_css'] ?? ''));
        if ($custom !== '') {
            $css .= str_ireplace('</style', '<\\/style', $custom) . "\n";
        }

        return $css;
    }

    /** 列表形态对应的 body 类名，供前台模板挂到 <body> 上。 */
    public static function optionsBodyClass(string $name): string
    {
        $style = self::changedOptions($name)['list_style'] ?? '';
        return match ($style) {
            'card' => 'ts-list-card',
            'compact' => 'ts-list-compact',
            default => '',
        };
    }

    /**
     * 字体名清洗：只留字母、数字、汉字与空格逗号连字符引号，最长 80 字符。
     * 字体名会直接进 CSS，必须挡掉 } { ; 等能让规则逃逸的字符。
     */
    private static function cssFontFamily(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9\x{4e00}-\x{9fa5}\s,\-\'"]/u', '', $value) ?? '';
        return mb_substr(trim($clean), 0, 80);
    }

    /**
     * 指定主题的设置值：schema 默认值 + theme_settings:{name}:{key} 覆盖。
     *
     * 必须展平 `group` 容器：容器自身不存值，其子字段才是真实设置项。
     * 只遍历顶层会让容器内的设置**永远读不到**（`theme_value()` 返回默认值、
     * 导出也漏掉），即使保存接口报告成功。
     */
    public static function valuesFor(string $name): array
    {
        if (!self::isValidName($name)) {
            return [];
        }
        $values = [];
        foreach (ExtensionKernel::flattenFields(self::settingsFields($name)) as $field) {
            if (is_array($field) && isset($field['key']) && !ExtensionKernel::isDecorativeType((string) ($field['type'] ?? ''))) {
                $type = (string) ($field['type'] ?? '');
                $values[$field['key']] = (string) ($field['default'] ?? (($type === 'checkbox' || $type === 'switcher') ? '0' : ''));
            }
        }
        // 覆盖已保存值
        $prefix = self::settingsPrefix($name);
        $all = Settings::core();
        foreach ($all as $key => $value) {
            if (str_starts_with($key, $prefix) && isset($values[substr($key, strlen($prefix))])) {
                $values[substr($key, strlen($prefix))] = $value;
            }
        }
        foreach (ExtensionStorage::values('theme', $name) as $key => $value) {
            if (str_starts_with($key, 'setting:') && isset($values[substr($key, 8)])) {
                $values[substr($key, 8)] = $value;
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
        if ($name === 'page') {
            $template = $context['page']['template'] ?? 'default';
            if (is_string($template) && self::pageTemplateFile(self::active(), $template) !== null) {
                array_unshift($baseCandidates, 'page-' . $template);
            }
        }
        if ($name === 'post') {
            $categorySlug = $context['post']['category_slug'] ?? null;
            if (is_string($categorySlug) && preg_match('/^[a-z0-9_-]{1,50}$/', $categorySlug) === 1) {
                array_unshift($baseCandidates, 'post-' . $categorySlug);
            }
            $template = $context['post']['template'] ?? 'default';
            if (is_string($template) && self::postTemplateFile(self::active(), $template) !== null) {
                array_unshift($baseCandidates, 'post-template-' . $template);
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
                if (is_string($candidate) && preg_match('/^[a-z0-9_-]{1,64}$/D', $candidate) === 1 && !in_array($candidate, $candidates, true)) {
                    $candidates[] = $candidate;
                }
            }
        }
        if ($candidates === []) {
            $candidates = $baseCandidates;
        }

        $directory = realpath(self::root() . '/' . self::active());
        foreach ($candidates as $candidate) {
            $themeFile = $directory === false ? false : realpath($directory . '/' . $candidate . '.php');
            if ($themeFile !== false && dirname($themeFile) === $directory && is_file($themeFile) && is_readable($themeFile)) {
                return $themeFile;
            }
        }
        $manifest = self::manifest(self::active());
        $fallbacks = $manifest['systemTemplateFallback'] ?? null;
        if (in_array($name, self::PAGE_TEMPLATES, true) && is_array($fallbacks) && !in_array($name, $fallbacks, true)) {
            throw new \RuntimeException('当前主题未提供页面模板：' . $name);
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
        ExtensionDependencies::assertSatisfied('theme', $name, $desc['manifest']['requires'] ?? []);
        if (is_file(self::root() . '/' . $name . '/extension.php')
            && !ExtensionKernel::boot('theme', $name, self::root(), self::context($name))) {
            throw new \RuntimeException('主题入口加载失败');
        }
        $previous = self::active();
        try {
            ExtensionRoutes::assertCanActivate('theme', $name, $desc['manifest']);
            $version = (string) $desc['manifest']['version'];
            $previousVersion = ExtensionSchema::installedVersion('theme', $name);
            $schema = $desc['manifest']['schema'] ?? null;
            ExtensionSchema::sync('theme', $name, $schema);
            ExtensionSchema::recordFingerprint('theme', $name, $schema);
            self::runUpgrade($name, $previousVersion, $version);
            ExtensionSchema::recordVersion('theme', $name, $version);
            if ($previous !== $name) {
                self::runLifecycle($previous, 'onDeactivate');
                self::runLifecycle($name, 'onActivate');
            }
            Settings::set('active_theme', $name);
            self::reset();
            if (!self::boot()) {
                throw new \RuntimeException('主题入口加载失败');
            }
        } catch (\Throwable $e) {
            // 路由、schema 或迁移失败后，不能把新主题已注册的能力留在长驻进程中。
            ExtensionKernel::reset('theme', $name);
            if ($previous !== $name && self::active() === $name) {
                try {
                    Settings::set('active_theme', $previous);
                } catch (\Throwable) {
                    // 保留原始切换错误；下一次请求会按主题清单回退到 default。
                }
            }
            self::reset();
            throw $e;
        }
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
            if (!self::isThemeDirectory($stagedTarget)) {
                self::rmDir($stage);
                throw new \RuntimeException('安装失败：主题包缺少可识别的 PHP 模板或资源（已回滚）');
            }
            if (is_file($stagedTarget . '/theme.json')) {
                $json = json_decode((string) file_get_contents($stagedTarget . '/theme.json'), true);
                $error = is_array($json) ? self::validateManifest($json, (string) $top) : 'theme.json 不是合法 JSON';
                if ($error !== null) {
                    self::rmDir($stage);
                    throw new \RuntimeException('安装失败：' . $error . '（已回滚）');
                }
                $json = self::normalizeManifest($json, (string) $top);
            } else {
                throw new \RuntimeException('安装失败：主题包缺少 theme.json（已回滚）');
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
        // 先删除代码目录；目录权限失败时保留主题设置和数据，便于修复后重试。
        if (!@self::rmDir(self::root() . '/' . $name)) {
            throw new \RuntimeException('删除主题目录失败');
        }
        if ($deleteData) {
            EntityMeta::deleteOwner('theme', $name);
            ExtensionSchema::drop('theme', $name, $desc['manifest']['schema'] ?? null);
            Settings::remove('theme_data:' . $name);
            ExtensionSchema::forget('theme', $name);
            Settings::deletePrefix(self::settingsPrefix($name));
        }
        ExtensionKernel::reset('theme', $name);
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
        $pairs = [];
        $total = count($json['values']);
        foreach ($json['values'] as $k => $v) {
            $k = (string) $k;
            if (!isset($keys[$k])) {
                continue;
            }
            $pairs[$k] = is_scalar($v) ? (string) $v : '';
        }
        self::setExtensionSettings($name, $pairs);
        return ['imported' => count($pairs), 'total' => $total];
    }

    /**
     * 恢复默认设置：清空本主题已保存的设置值，读取时回落到 theme.json 声明的默认值。
     * 按主题名清理，包含 schema 已删除的旧字段，不影响其他主题。
     * 返回实际清除的键数。
     */
    public static function resetToDefaults(string $name): int
    {
        if (!self::isValidName($name)) {
            throw new \RuntimeException('主题名不合法');
        }
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            throw new \RuntimeException($desc['error'] ?? '主题不存在');
        }
        $cleared = Settings::deletePrefix(self::settingsPrefix($name));
        self::resetValues();
        return $cleared;
    }

    /** 清除静态缓存（安装/卸载/激活/保存设置后调用） */
    public static function reset(): void
    {
        if (self::$bootedTheme !== null) {
            ExtensionKernel::reset('theme', self::$bootedTheme);
        }
        self::$manifests = [];
        self::$contexts = [];
        self::resetValues();
        self::$bootedTheme = null;
        self::$schemaBootKey = null;
    }

    public static function resetValues(): void
    {
        self::$values = null;
        self::$changedCache = [];
    }

    /** 设置与钩子的 theme:{name} 标记分开；末尾冒号隔离名称前缀相同的主题。 */
    private static function settingsPrefix(string $name): string
    {
        if (!self::isValidName($name)) {
            throw new \InvalidArgumentException('主题名不合法');
        }
        return 'theme_settings:' . $name . ':';
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
