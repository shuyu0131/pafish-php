<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Hooks;

/** 插件清单、生命周期、钩子注入和扩展存储管理。 */
final class Plugin
{
    public const API_VERSION = 2;

    private const NAME_PATTERN = '/^[a-z0-9_-]{1,50}$/';
    private const TEMPLATE_NAME_PATTERN = '/^[a-z0-9-]{1,40}$/';
    private const PAGE_PATH_PATTERN = '/^[a-z0-9_-]{1,50}$/';
    private const INJECT_TARGETS = [
        'head', 'footer', 'sidebar',
        'comment_form', 'login_form', 'register_form', 'post_editor',
    ];
    private const SETTING_TYPES = ['text', 'textarea', 'checkbox', 'select', 'color', 'switcher', 'radio', 'image', 'password'];
    private const MAX_ZIP_BYTES = 10 * 1024 * 1024;
    private const MAX_LOGS = 50;

    private static ?array $manifests = [];
    private static ?array $modules = [];
    private static ?array $contexts = [];
    private static ?array $activeCache = null;

    public static function root(): string
    {
        return dirname(__DIR__, 2) . '/plugins';
    }

    /** 扫描 plugins/ 目录下的全部插件名（同 Theme::list：点前缀目录忽略） */
    public static function list(): array
    {
        $names = [];
        if (!is_dir(self::root())) {
            return [];
        }
        foreach (glob(self::root() . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            if (str_starts_with($name, '.')) {
                continue;
            }
            if (preg_match(self::NAME_PATTERN, $name) === 1) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    /** 读取激活插件名列表。 */
    public static function activeNames(): array
    {
        if (self::$activeCache !== null) {
            return self::$activeCache;
        }
        $raw = (string) Settings::get('active_plugins', '');
        $list = json_decode($raw, true);
        $names = [];
        if (is_array($list)) {
            foreach ($list as $n) {
                if (is_string($n) && preg_match(self::NAME_PATTERN, $n) === 1) {
                    $names[] = $n;
                }
            }
        }
        return self::$activeCache = array_values(array_unique($names));
    }

    public static function isActive(string $name): bool
    {
        return in_array($name, self::activeNames(), true);
    }

    /** 读取插件 manifest。 */
    public static function manifest(string $name): ?array
    {
        $desc = self::describe($name);
        return $desc['manifest'];
    }

    /**
     * 插件描述：['manifest' => ?array, 'error' => ?string]
     * Manifest 校验顺序：名称 → 缺少 plugin.json → JSON → 格式 → name → title/version
     */
    public static function describe(string $name): array
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return ['manifest' => null, 'error' => '插件名不合法'];
        }
        if (array_key_exists($name, self::$manifests)) {
            $cached = self::$manifests[$name];
            return ['manifest' => $cached['m'], 'error' => $cached['e']];
        }
        $file = self::root() . '/' . $name . '/plugin.json';
        if (!is_file($file)) {
            self::$manifests[$name] = ['m' => null, 'e' => '缺少 plugin.json'];
            return ['manifest' => null, 'error' => '缺少 plugin.json'];
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (!is_array($json)) {
            self::$manifests[$name] = ['m' => null, 'e' => 'plugin.json 不是合法 JSON'];
            return ['manifest' => null, 'error' => 'plugin.json 不是合法 JSON'];
        }
        $error = self::validateManifest($json, $name);
        if ($error !== null) {
            self::$manifests[$name] = ['m' => null, 'e' => $error];
            return ['manifest' => null, 'error' => $error];
        }
        $manifest = self::normalize($json);
        self::$manifests[$name] = ['m' => $manifest, 'e' => null];
        return ['manifest' => $manifest, 'error' => null];
    }

    /** manifest 校验（仅返回错误；合法时由 normalize 产出规范化 manifest） */
    public static function validateManifest(array $json, string $dirName): ?string
    {
        if (!is_string($json['name'] ?? null) || preg_match(self::NAME_PATTERN, (string) $json['name']) !== 1
            || $json['name'] !== $dirName) {
            return 'name 缺失或与目录名不一致';
        }
        if (!is_string($json['title'] ?? null) || !is_string($json['version'] ?? null)) {
            return '缺少 title 或 version';
        }
        if (array_key_exists('apiVersion', $json)) {
            if (!is_int($json['apiVersion']) || $json['apiVersion'] < 1 || $json['apiVersion'] > self::API_VERSION) {
                return 'apiVersion 不受支持';
            }
        }
        if (array_key_exists('pageTemplates', $json)) {
            if (!is_array($json['pageTemplates'])) {
                return 'pageTemplates 必须是数组';
            }
            $decls = [];
            foreach ($json['pageTemplates'] as $t) {
                if (!is_array($t) || !is_string($t['name'] ?? null) || !is_string($t['title'] ?? null)) {
                    continue;
                }
                if (preg_match(self::TEMPLATE_NAME_PATTERN, $t['name']) !== 1 || $t['name'] === 'default') {
                    continue;
                }
                $decls[] = ['name' => $t['name'], 'title' => $t['title']];
            }
            if ($decls === []) {
                return 'pageTemplates 声明无效';
            }
        }
        if (array_key_exists('pages', $json)) {
            if (!is_array($json['pages'])) {
                return 'pages 必须是数组';
            }
            $decls = [];
            foreach ($json['pages'] as $p) {
                if (!is_array($p) || !is_string($p['path'] ?? null) || !is_string($p['title'] ?? null)) {
                    continue;
                }
                if (preg_match(self::PAGE_PATH_PATTERN, $p['path']) !== 1) {
                    continue;
                }
                $decls[] = ['path' => $p['path'], 'title' => $p['title']];
            }
            if ($decls === []) {
                return 'pages 声明无效';
            }
        }
        return null;
    }

    /** 规范化 manifest（过滤 settings/injects/pageTemplates/pages/storage） */
    private static function normalize(array $json): array
    {
        $settings = [];
        foreach ((array) ($json['settings'] ?? []) as $s) {
            if (!is_array($s) || !is_string($s['key'] ?? null) || !is_string($s['label'] ?? null)
                || !is_string($s['type'] ?? null) || !in_array($s['type'], self::SETTING_TYPES, true)) {
                continue;
            }
            $field = ['key' => $s['key'], 'label' => $s['label'], 'type' => $s['type']];
            if (is_array($s['options'] ?? null)) {
                $field['options'] = $s['options'];
            }
            foreach (['default', 'placeholder', 'group'] as $k) {
                if (is_string($s[$k] ?? null)) {
                    $field[$k] = $s[$k];
                }
            }
            if (is_array($s['show_if'] ?? null) && is_string($s['show_if']['key'] ?? null)
                && is_string($s['show_if']['value'] ?? null)) {
                $field['show_if'] = ['key' => $s['show_if']['key'], 'value' => $s['show_if']['value']];
            }
            $settings[] = $field;
        }

        $injects = [];
        if (is_array($json['injects'] ?? null)) {
            foreach ($json['injects'] as $t) {
                if (is_string($t) && in_array($t, self::INJECT_TARGETS, true)) {
                    $injects[] = $t;
                }
            }
        }

        $pageTemplates = null;
        if (is_array($json['pageTemplates'] ?? null)) {
            $pageTemplates = [];
            foreach ($json['pageTemplates'] as $t) {
                if (!is_array($t) || !is_string($t['name'] ?? null) || !is_string($t['title'] ?? null)) {
                    continue;
                }
                if (preg_match(self::TEMPLATE_NAME_PATTERN, $t['name']) !== 1 || $t['name'] === 'default') {
                    continue;
                }
                $pageTemplates[] = ['name' => $t['name'], 'title' => $t['title']];
            }
        }

        $pages = null;
        if (is_array($json['pages'] ?? null)) {
            $pages = [];
            foreach ($json['pages'] as $p) {
                if (!is_array($p) || !is_string($p['path'] ?? null) || !is_string($p['title'] ?? null)) {
                    continue;
                }
                if (preg_match(self::PAGE_PATH_PATTERN, $p['path']) !== 1) {
                    continue;
                }
                $pages[] = ['path' => $p['path'], 'title' => $p['title']];
            }
        }

        $storage = null;
        if (is_array($json['storage'] ?? null) && is_string($json['storage']['title'] ?? null)
            && $json['storage']['title'] !== '') {
            $storage = ['title' => $json['storage']['title']];
        }

        return [
            'name' => $json['name'],
            'title' => $json['title'],
            'version' => $json['version'],
            'apiVersion' => is_int($json['apiVersion'] ?? null) ? $json['apiVersion'] : 1,
            'description' => is_string($json['description'] ?? null) ? $json['description'] : '',
            'author' => is_string($json['author'] ?? null) ? $json['author'] : '',
            'authorUrl' => self::manifestUrl($json, 'authorUrl'),
            'homepage' => self::manifestUrl($json, 'homepage'),
            'requires' => is_array($json['requires'] ?? null) ? $json['requires'] : [],
            'settings' => $settings,
            'injects' => $injects,
            'pageTemplates' => $pageTemplates,
            'pages' => $pages,
            'storage' => $storage,
        ];
    }

    /** 兼容 author_url/url 旧字段，过滤非网页地址。 */
    private static function manifestUrl(array $json, string $key): string
    {
        $value = $json[$key] ?? ($key === 'homepage' ? ($json['url'] ?? '') : ($json['author_url'] ?? ''));
        return is_string($value) && preg_match('/^https?:\\/\\//i', $value) === 1 ? $value : '';
    }

    /** 加载插件模块；入口缺失或加载失败时返回空结果。 */
    public static function module(string $name): ?array
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }
        if (array_key_exists($name, self::$modules)) {
            return self::$modules[$name];
        }
        $file = self::root() . '/' . $name . '/index.php';
        if (!is_file($file)) {
            return self::$modules[$name] = null;
        }
        try {
            $mod = require $file;
            return self::$modules[$name] = is_array($mod) ? $mod : [];
        } catch (\Throwable $e) {
            error_log('[pafish-plugin] 加载 ' . $name . ' 失败：' . $e->getMessage());
            return self::$modules[$name] = [];
        }
    }

    /** 当前插件上下文。 */
    public static function context(string $name): object
    {
        if (isset(self::$contexts[$name])) {
            return self::$contexts[$name];
        }
        $apiVersion = (int) (self::manifest($name)['apiVersion'] ?? 1);
        return self::$contexts[$name] = new class ($name, $apiVersion) {
            public readonly string $name;
            public readonly int $apiVersion;

            public function __construct(string $name, int $apiVersion)
            {
                $this->name = $name;
                $this->apiVersion = $apiVersion;
            }

            /** 注册事件钩子，返回注销函数（tag 归入 plugin:{name}，停用时整批移除） */
            public function on(string $hook, callable $fn, int $priority = 10): callable
            {
                return Hooks::addAction($hook, $fn, $priority, 'plugin:' . $this->name);
            }

            /** 注册过滤器，停用插件时与 action 一起按 tag 批量注销（API v2） */
            public function filter(string $hook, callable $fn, int $priority = 10): callable
            {
                return Hooks::addFilter($hook, $fn, $priority, 'plugin:' . $this->name);
            }

            /** 读写插件自有数据（JSON 对象，settings plugin_data:{name}） */
            public function getData(): array
            {
                return Plugin::data($this->name);
            }

            public function setData(array $data): void
            {
                Plugin::setData($this->name, $data);
            }

            /** 读写插件设置（settings plugin_settings:{name}，partial 合并） */
            public function getSettings(): array
            {
                return Plugin::settings($this->name);
            }

            public function setSettings(array $partial): void
            {
                Plugin::setSettings($this->name, $partial);
            }

            /** 追加一行日志（logs 数组最多 50 条） */
            public function log(string $message): void
            {
                Plugin::log($this->name, $message);
            }

            /** PHP 版即时渲染，无需缓存刷新 */
            public function refreshInjections(): void
            {
            }
        };
    }

    /** 插件数据（plugin_data:{name} JSON 对象；损坏/缺失返回空数组） */
    public static function data(string $name): array
    {
        $v = json_decode((string) Settings::get('plugin_data:' . $name, ''), true);
        return is_array($v) ? $v : [];
    }

    public static function setData(string $name, array $data): void
    {
        Settings::set('plugin_data:' . $name, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /** 插件设置（plugin_settings:{name}） */
    public static function settings(string $name): array
    {
        $v = json_decode((string) Settings::get('plugin_settings:' . $name, ''), true);
        return is_array($v) ? $v : [];
    }

    /** partial 合并写回 */
    public static function setSettings(string $name, array $partial): void
    {
        Settings::set('plugin_settings:' . $name, json_encode(array_merge(self::settings($name), $partial), JSON_UNESCAPED_UNICODE));
    }

    /** 追加插件日志。 */
    public static function log(string $name, string $message): void
    {
        $data = self::data($name);
        $logs = is_array($data['logs'] ?? null) ? $data['logs'] : [];
        $logs[] = ['time' => date('Y-m-d H:i:s'), 'message' => $message];
        $data['logs'] = array_slice($logs, -self::MAX_LOGS);
        self::setData($name, $data);
    }

    // ---------- 生命周期 ----------

    /** 注册插件钩子（约定函数 registerHooks(ctx)） */
    public static function registerHooks(string $name): void
    {
        $mod = self::module($name);
        if ($mod === null || !is_callable($mod['registerHooks'] ?? null)) {
            return;
        }
        try {
            $mod['registerHooks'](self::context($name));
        } catch (\Throwable $e) {
            error_log("[pafish-plugin] {$name} registerHooks 失败：" . $e->getMessage());
        }
    }

    /** 注销插件全部钩子（tag=plugin:{name}，含 ctx.on 注册的） */
    public static function unregisterHooks(string $name): void
    {
        Hooks::removeByTag('plugin:' . $name);
    }

    /** 生命周期回调（onActivate/onDeactivate/onUninstall），异常隔离 */
    public static function runLifecycle(string $name, string $phase): void
    {
        $mod = self::module($name);
        if ($mod === null || !is_callable($mod[$phase] ?? null)) {
            return;
        }
        try {
            $mod[$phase](self::context($name));
        } catch (\Throwable $e) {
            error_log("[pafish-plugin] {$name} {$phase} 失败：" . $e->getMessage());
        }
    }

    /** 启用插件。 */
    public static function activate(string $name): void
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            throw new \RuntimeException('插件"' . $name . '"不可用：' . $desc['error']);
        }
        $list = self::activeNames();
        if (!in_array($name, $list, true)) {
            $list[] = $name;
            self::setActivePlugins($list);
        }
        self::registerHooks($name);
        self::runLifecycle($name, 'onActivate');
    }

    /** 停用插件。 */
    public static function deactivate(string $name): void
    {
        $list = array_values(array_filter(self::activeNames(), static fn (string $n): bool => $n !== $name));
        self::setActivePlugins($list);
        self::unregisterHooks($name);
        self::runLifecycle($name, 'onDeactivate');
    }

    /** 卸载插件：先执行 onUninstall，使清理逻辑仍能读取插件设置/数据，再删除持久化数据和目录。 */
    public static function uninstall(string $name): void
    {
        if (self::isActive($name)) {
            self::deactivate($name);
        }
        self::runLifecycle($name, 'onUninstall');
        Settings::remove('plugin_data:' . $name);
        Settings::remove('plugin_settings:' . $name);
        if (!self::rmDir(self::root() . '/' . $name)) {
            throw new \RuntimeException('删除插件目录失败');
        }
        unset(self::$manifests[$name], self::$modules[$name], self::$contexts[$name]);
    }

    /** 按 manifest schema 保存插件设置。 */
    public static function saveSettings(string $name, array $body): array
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            throw new \RuntimeException($desc['error']);
        }
        $clean = [];
        foreach (($desc['manifest']['settings'] ?? []) as $field) {
            $key = (string) $field['key'];
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $raw = $body[$key];
            $value = is_scalar($raw) ? (string) $raw : '';
            if ($field['type'] === 'checkbox' || $field['type'] === 'switcher') {
                $value = $value === '1' ? '1' : '0';
            }
            if ($field['type'] === 'color' && $value !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
                throw new \RuntimeException('颜色值 ' . $value . ' 不合法（需 #rrggbb）');
            }
            $clean[$key] = $value;
        }
        self::setSettings($name, $clean);
        return $clean;
    }

    // ---------- 注入管线 ----------

    /**
     * 渲染指定注入点 HTML：激活插件 renderInjection(target, ctx) 依次拼接
     * head 走白名单标签过滤（script/meta/link/style）；footer/sidebar 原样
     */
    public static function renderInjection(string $target, array $context = []): string
    {
        if (!in_array($target, self::INJECT_TARGETS, true)) {
            return '';
        }
        $parts = [];
        foreach (self::activeNames() as $name) {
            $manifest = self::manifest($name);
            if ($manifest === null) {
                continue;
            }
            // v1 插件保持历史行为；v2 插件必须显式声明 injects，避免越权注入未声明位置。
            if ((int) ($manifest['apiVersion'] ?? 1) >= 2
                && !in_array($target, (array) ($manifest['injects'] ?? []), true)) {
                continue;
            }
            $mod = self::module($name);
            if ($mod === null || !is_callable($mod['renderInjection'] ?? null)) {
                continue;
            }
            try {
                $html = $mod['renderInjection']($target, self::context($name), $context);
                if (is_string($html) && trim($html) !== '') {
                    $parts[] = $html;
                }
            } catch (\Throwable $e) {
                error_log("[pafish-plugin] {$name} renderInjection({$target}) 失败：" . $e->getMessage());
            }
        }
        $joined = implode("\n", $parts);
        return $target === 'head' ? self::parseInjectionTags($joined) : $joined;
    }

    /** 白名单标签提取（按 script/meta/link/style 顺序收集） */
    private static function parseInjectionTags(string $html): string
    {
        $out = '';
        foreach ([
            '/<script\b[^>]*>[\s\S]*?<\/script>/i',
            '/<meta\b[^>]*>/i',
            '/<link\b[^>]*>/i',
            '/<style\b[^>]*>[\s\S]*?<\/style>/i',
        ] as $re) {
            if (preg_match_all($re, $html, $m) !== false) {
                $out .= implode("\n", $m[0]);
            }
        }
        return $out;
    }

    /** 分发激活插件的前台页面模板。 */
    public static function renderPageTemplate(array $page): string
    {
        foreach (self::activeNames() as $name) {
            $mod = self::module($name);
            if ($mod === null || !is_callable($mod['renderPageTemplate'] ?? null)) {
                continue;
            }
            try {
                $html = $mod['renderPageTemplate']((string) ($page['template'] ?? 'default'), $page, self::context($name));
                if (is_string($html) && trim($html) !== '') {
                    return $html;
                }
            } catch (\Throwable $e) {
                error_log("[pafish-plugin] {$name} renderPageTemplate 失败：" . $e->getMessage());
            }
        }
        return '';
    }

    /**
     * 插件前台页：返回 ['html' => string, 'title' => string]；任一条件不满足返回 null
     * 插件页面：激活 + 声明该 path + renderPluginPage 函数 + 非空输出
     */
    public static function renderPluginPage(string $name, string $pagePath): ?array
    {
        if (!self::isActive($name)) {
            return null;
        }
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            return null;
        }
        $decl = null;
        foreach (($desc['manifest']['pages'] ?? []) as $p) {
            if ($p['path'] === $pagePath) {
                $decl = $p;
                break;
            }
        }
        if ($decl === null) {
            return null;
        }
        $mod = self::module($name);
        if ($mod === null || !is_callable($mod['renderPluginPage'] ?? null)) {
            return null;
        }
        try {
            $html = $mod['renderPluginPage']($pagePath, self::context($name));
        } catch (\Throwable $e) {
            error_log("[pafish-plugin] {$name} renderPluginPage({$pagePath}) 失败：" . $e->getMessage());
            return null;
        }
        if (!is_string($html) || trim($html) === '') {
            return null;
        }
        return ['html' => trim($html), 'title' => (string) $decl['title']];
    }

    // ---------- 云存储管线 ----------

    /** 获取当前云存储后端。 */
    public static function activeStorage(): ?array
    {
        foreach (self::activeNames() as $name) {
            $desc = self::describe($name);
            if ($desc['error'] !== null || $desc['manifest']['storage'] === null) {
                continue;
            }
            $mod = self::module($name);
            if ($mod === null || !is_callable($mod['storeFile'] ?? null)) {
                continue;
            }
            return [
                'name' => $name,
                'title' => (string) $desc['manifest']['storage']['title'],
                'module' => $mod,
            ];
        }
        return null;
    }

    /** 上传到云存储：成功返回 URL（string 或 {url} 均收），否则 null（调用方回退本地） */
    public static function storeToCloud(array $file): ?string
    {
        try {
            $backend = self::activeStorage();
            if ($backend === null) {
                return null;
            }
            $res = $backend['module']['storeFile']($file, self::context($backend['name']));
            $url = is_string($res) ? $res : (is_array($res) ? ($res['url'] ?? null) : null);
            if (!is_string($url) || trim($url) === '') {
                return null;
            }
            return trim($url);
        } catch (\Throwable $e) {
            error_log('[pafish-plugin] 云存储上传失败，回退本地：' . $e->getMessage());
            return null;
        }
    }

    /** 删除云端文件。 */
    public static function deleteFromCloud(string $url): void
    {
        if (preg_match('/^https?:\/\//i', $url) !== 1) {
            return;
        }
        try {
            $backend = self::activeStorage();
            if ($backend === null || !is_callable($backend['module']['deleteFile'] ?? null)) {
                return;
            }
            $backend['module']['deleteFile']($url, self::context($backend['name']));
        } catch (\Throwable $e) {
            error_log('[pafish-plugin] 云端删除失败（忽略）：' . $url);
        }
    }

    // ---------- 安装 / 启动 ----------

    /**
     * 每次请求启动（bootstrap 调用）：
     * - 注册系统注入渲染器（主题模板里 do_action('head_inject'/'sidebar_inject'/'footer_inject') 输出插件注入）
     * - 接入云存储管线（Upload/MediaController 的 apply_filters 调用点）
     * - 注册全部激活插件的钩子（PHP 每请求新进程）
     */
    public static function boot(): void
    {
        try {
            add_action('head_inject', static fn (array $context = []) => print self::renderInjection('head', $context), 0, 'core');
            add_action('sidebar_inject', static fn (array $context = []) => print self::renderInjection('sidebar', $context), 0, 'core');
            add_action('footer_inject', static fn (array $context = []) => print self::renderInjection('footer', $context), 0, 'core');
            add_filter('upload_store_to_cloud', static fn (mixed $prev, array $file) => self::storeToCloud($file) ?? $prev, 10, 'core');
            add_filter('upload_delete_from_cloud', static function (mixed $prev, array $args) {
                self::deleteFromCloud((string) ($args['url'] ?? ''));
                return $prev;
            }, 10, 'core');
            foreach (self::activeNames() as $name) {
                self::registerHooks($name);
            }
        } catch (\Throwable $e) {
            error_log('[pafish-plugin] boot 失败：' . $e->getMessage());
        }
    }

    /** zip 安装：大小 → 顶层目录 → 穿越防护 → plugin.json 校验 → 原子 rename */
    public static function installFromBuffer(string $buffer, string $label): array
    {
        $len = strlen($buffer);
        if ($len <= 0 || $len > self::MAX_ZIP_BYTES) {
            throw new \RuntimeException('插件包大小需在 10MB 以内（来源：' . $label . '）');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pfplug');
        if ($tmp === false || @file_put_contents($tmp, $buffer) === false) {
            throw new \RuntimeException('插件包大小需在 10MB 以内（来源：' . $label . '）');
        }
        $zip = new \ZipArchive();
        try {
            if ($zip->open($tmp) !== true) {
                throw new \RuntimeException('解压失败：无法打开 zip 压缩包（已回滚）');
            }
            $count = $zip->numFiles;
            if ($count <= 0) {
                throw new \RuntimeException('解压失败：zip 包为空（已回滚）');
            }
            $top = null;
            for ($i = 0; $i < $count; $i++) {
                $entryName = (string) $zip->getNameIndex($i);
                $normalized = str_replace('\\', '/', $entryName);
                $trimmed = rtrim($normalized, '/');
                if ($trimmed === '') {
                    throw new \RuntimeException('插件包含意外路径：' . $entryName);
                }
                $parts = explode('/', $trimmed);
                // 先做逐段安全校验（穿越/空段/非法字符优先于顶层唯一性）
                foreach ($parts as $seg) {
                    if ($seg === '..' || $seg === '') {
                        throw new \RuntimeException('插件包含意外路径：' . $entryName);
                    }
                    if (str_contains($seg, ':')) {
                        throw new \RuntimeException('插件包含非法路径：' . $entryName);
                    }
                }
                if ($top === null) {
                    $top = $parts[0];
                } elseif ($top !== $parts[0]) {
                    throw new \RuntimeException('插件包必须只含一个顶层目录（插件名）');
                }
            }
            if (preg_match(self::NAME_PATTERN, (string) $top) !== 1) {
                throw new \RuntimeException('插件名不合法（仅限小写字母/数字/下划线/连字符）');
            }
            if (is_dir(self::root() . '/' . $top)) {
                throw new \RuntimeException('插件"' . $top . '"已存在，请先在插件列表卸载后再安装');
            }
            // 直接解压到插件目录，失败或校验不通过时清理残留。
            $target = self::root() . '/' . $top;
            $extracted = @$zip->extractTo(self::root());
            if (!$extracted) {
                $zip->close();
                self::rmDir($target);
                throw new \RuntimeException('解压失败（已回滚）');
            }
            $zip->close();
            if (!is_file($target . '/plugin.json')) {
                self::rmDir($target);
                throw new \RuntimeException('安装失败：缺少 plugin.json（已回滚）');
            }
            $json = json_decode((string) file_get_contents($target . '/plugin.json'), true);
            $error = is_array($json) ? self::validateManifest($json, (string) $top) : 'plugin.json 不是合法 JSON';
            if ($error !== null) {
                self::rmDir($target);
                throw new \RuntimeException('安装失败：' . $error . '（已回滚）');
            }
            self::reset();
            return [
                'name' => (string) $top,
                'title' => (string) $json['title'],
                'version' => (string) $json['version'],
            ];
        } finally {
            if ($zip->status !== \ZipArchive::ER_OK) {
                @$zip->close();
            }
            @unlink($tmp);
        }
    }

    /** URL 安装：仅 https（防中间人篡改）；下载失败返回状态码 */
    public static function installFromUrl(string $url): array
    {
        if (preg_match('/^https:\/\//i', $url) !== 1) {
            throw new \RuntimeException('URL 需以 https:// 开头');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'pafish-plugin-installer/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // PHP 8.0+ 无需显式关闭（curl_close 已无效果，且 8.5 起调用会触发 Deprecated 警告污染 JSON 响应）
        if ($body === false || $status !== 200) {
            throw new \RuntimeException('下载失败（HTTP ' . $status . '）');
        }
        return self::installFromBuffer((string) $body, $url);
    }

    private static function setActivePlugins(array $list): void
    {
        Settings::set('active_plugins', json_encode($list));
        self::$activeCache = $list;
    }

    /** 递归删除目录。 */
    private static function rmDir(string $dir): bool
    {
        return self::rmRecursive($dir);
    }

    private static function rmRecursive(string $dir): bool
    {
        $items = @scandir($dir);
        if ($items === false) {
            return !is_dir($dir);
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path)) {
                if (!self::rmRecursive($path)) {
                    return false;
                }
            } elseif (!self::rmRemove($path, false)) {
                return false;
            }
        }
        return self::rmRemove($dir, true);
    }

    /** 删除文件或空目录。Windows 环境先换名再删除，降低文件占用导致的失败概率。 */
    private static function rmRemove(string $path, bool $isDir): bool
    {
        // 清除只读属性，避免删除失败。
        if (!$isDir) {
            @chmod($path, 0666);
        }
        $tmp = dirname($path) . '/.' . basename($path) . '.del' . bin2hex(random_bytes(3));
        for ($i = 0; $i < 3; $i++) {
            if (@rename($path, $tmp)) {
                if ($isDir) {
                    return @rmdir($tmp) || !is_dir($tmp);
                }
                return @unlink($tmp) || !is_file($tmp);
            }
            if ($i < 2) {
                usleep(150000);
            }
        }
        return $isDir ? @rmdir($path) : @unlink($path);
    }

    /** 清空静态缓存。 */
    public static function reset(): void
    {
        self::$manifests = [];
        self::$modules = [];
        self::$contexts = [];
        self::$activeCache = null;
    }
}
