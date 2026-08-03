<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Hooks;

/**
 * 插件系统（对标 Node 版 src/lib/plugin-loader.ts + plugin-injections.ts + plugin-pages.ts + plugin-storage.ts + admin/plugins/actions.ts）：
 * - 目录约定：plugins/{name}/plugin.json（manifest + 设置 schema）+ index.php（返回约定函数数组的 PHP 文件）
 * - manifest 校验：名称白名单 / title+version 必填 / settings 9 类型过滤（非法字段忽略）
 *   / injects 白名单 / pageTemplates·pages 白名单（声明但过滤后为空 = 声明无效）/ storage 非法忽略
 * - 生命周期：activate（写列表 + 注册钩子 + onActivate）→ deactivate（移除 + 注销钩子 + onDeactivate）
 *   → uninstall（停用 + 删数据键 + onUninstall + 删目录）
 * - 注入：head（白名单标签过滤）/ footer / sidebar 即时渲染（PHP 每请求新进程，无缓存/节流问题）
 * - 云存储：激活插件首个声明 storage 且实现 storeFile 者生效（Upload 经 apply_filters 接入），失败回退本地
 */
final class Plugin
{
    private const NAME_PATTERN = '/^[a-z0-9_-]{1,50}$/';
    private const TEMPLATE_NAME_PATTERN = '/^[a-z0-9-]{1,40}$/';
    private const PAGE_PATH_PATTERN = '/^[a-z0-9_-]{1,50}$/';
    private const INJECT_TARGETS = ['head', 'footer', 'sidebar'];
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

    /** 激活插件名列表（settings active_plugins JSON；非法名过滤，对齐 getActivePlugins） */
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

    /** 读取插件 manifest（plugin.json 已校验缓存）；无效返回 null */
    public static function manifest(string $name): ?array
    {
        $desc = self::describe($name);
        return $desc['manifest'];
    }

    /**
     * 插件描述：['manifest' => ?array, 'error' => ?string]
     * 错误文案对齐 Node readManifest（校验顺序：名称 → 缺少 plugin.json → JSON → 格式 → name → title/version）
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

    /** 规范化 manifest（过滤 settings/injects/pageTemplates/pages/storage，对齐 Node readManifest 返回结构） */
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
            'description' => is_string($json['description'] ?? null) ? $json['description'] : '',
            'author' => is_string($json['author'] ?? null) ? $json['author'] : '',
            'settings' => $settings,
            'injects' => $injects,
            'pageTemplates' => $pageTemplates,
            'pages' => $pages,
            'storage' => $storage,
        ];
    }

    /**
     * 加载插件模块：require plugins/{name}/index.php，约定返回约定函数数组
     * 无 index.php 返回 null（插件仍可启用，只是无能力）；require 失败记日志返回 []（对齐 loadPluginModule）
     */
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

    /** 插件上下文（ctx API 对齐 Node createPluginContext；PHP 版全部同步） */
    public static function context(string $name): object
    {
        if (isset(self::$contexts[$name])) {
            return self::$contexts[$name];
        }
        return self::$contexts[$name] = new class ($name) {
            private string $name;

            public function __construct(string $name)
            {
                $this->name = $name;
            }

            /** 注册事件钩子，返回注销函数（tag 归入 plugin:{name}，停用时整批移除） */
            public function on(string $hook, callable $fn, int $priority = 10): callable
            {
                return Hooks::addAction($hook, $fn, $priority, 'plugin:' . $this->name);
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

    /** partial 合并写回（对齐 Node setPluginSettings：{ ...cur, ...partial }） */
    public static function setSettings(string $name, array $partial): void
    {
        Settings::set('plugin_settings:' . $name, json_encode(array_merge(self::settings($name), $partial), JSON_UNESCAPED_UNICODE));
    }

    /** 追加日志（对齐 ctx.log：logs 数组，最多 50 条） */
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

    /** 启用插件（对齐 activatePlugin：写列表 + 注册钩子 + onActivate）；manifest 无效抛异常 */
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

    /** 停用插件（对齐 deactivatePlugin：移除列表 + 注销钩子 + onDeactivate） */
    public static function deactivate(string $name): void
    {
        $list = array_values(array_filter(self::activeNames(), static fn (string $n): bool => $n !== $name));
        self::setActivePlugins($list);
        self::unregisterHooks($name);
        self::runLifecycle($name, 'onDeactivate');
    }

    /** 卸载插件（对齐 uninstallPlugin：停用（若激活）→ 删数据/设置键 → onUninstall → 删目录） */
    public static function uninstall(string $name): void
    {
        if (self::isActive($name)) {
            self::deactivate($name);
        }
        Settings::remove('plugin_data:' . $name);
        Settings::remove('plugin_settings:' . $name);
        self::runLifecycle($name, 'onUninstall');
        if (!self::rmDir(self::root() . '/' . $name)) {
            throw new \RuntimeException('删除插件目录失败');
        }
        unset(self::$manifests[$name], self::$modules[$name], self::$contexts[$name]);
    }

    /** 保存插件设置（对齐 savePluginSettings：schema 白名单 + 仅收提交键 + 字符串化 + 合并写回） */
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
     * head 走白名单标签过滤（script/meta/link/style，对齐 Node parseInjectionTags）；footer/sidebar 原样
     */
    public static function renderInjection(string $target): string
    {
        if (!in_array($target, self::INJECT_TARGETS, true)) {
            return '';
        }
        $parts = [];
        foreach (self::activeNames() as $name) {
            $mod = self::module($name);
            if ($mod === null || !is_callable($mod['renderInjection'] ?? null)) {
                continue;
            }
            try {
                $html = $mod['renderInjection']($target, self::context($name));
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

    /** 白名单标签提取（对齐 Node parseInjectionTags：script/meta/link/style 顺序收集） */
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

    /** 前台页面模板分发：激活插件 renderPageTemplate(template, page, ctx) 首个非空输出（对齐 renderPageTemplateHtml） */
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
     * 对齐 Node /plugin/[name]/[[...path]]：激活 + 声明该 path + renderPluginPage 函数 + 非空输出
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

    /** 当前云存储后端：激活插件首个声明 storage 且实现 storeFile 者（对齐 getActiveStorage） */
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

    /** 删除云端文件（仅完整 http(s) URL 时调用；失败静默并记日志，对齐 deleteFromCloud） */
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
     * - 注册全部激活插件的钩子（PHP 每请求新进程，无需 Node 的 5s 节流 ensurePluginHooks）
     */
    public static function boot(): void
    {
        try {
            add_action('head_inject', static fn () => print self::renderInjection('head'), 0, 'core');
            add_action('sidebar_inject', static fn () => print self::renderInjection('sidebar'), 0, 'core');
            add_action('footer_inject', static fn () => print self::renderInjection('footer'), 0, 'core');
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

    /** zip 安装（对齐 Node installFromBuffer）：大小 → 顶层目录 → 穿越防护 → plugin.json 校验 → 原子 rename */
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
                // 先做逐段安全校验（穿越/空段/非法字符优先于顶层唯一性，对齐 Node 校验顺序）
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
            // Windows 兼容：不采用「临时目录 + rename」的原子方案——PHP 8.5 + Windows 下
            // 任何 stat 调用（is_dir/is_file 等）会打开目录/文件句柄，导致随后 rename 返回
            // 「拒绝访问」且时好时坏；改为 extractTo 直接解压到 plugins/ 根（顶层目录名即
            // 插件名，已在上面校验），失败/校验不过时用 SPL rmDir 递归清理残留
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

    /** URL 安装（对齐 Node installFromUrl）：仅 http(s)；下载失败返回状态码 */
    public static function installFromUrl(string $url): array
    {
        if (preg_match('/^https?:\/\//i', $url) !== 1) {
            throw new \RuntimeException('URL 需以 http:// 或 https:// 开头');
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

    // ---------- 内部 ----------

    private static function setActivePlugins(array $list): void
    {
        Settings::set('active_plugins', json_encode($list));
        self::$activeCache = $list;
    }

    /** 递归删除目录（SPL 迭代器：避免 Windows 上 PHP 8.5 中 stat 句柄导致 rename/rmdir「拒绝访问」） */
    private static function rmDir(string $dir): bool
    {
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                if ($item->isDir()) {
                    if (!@rmdir($item->getPathname())) {
                        return false;
                    }
                } elseif (!@unlink($item->getPathname())) {
                    return false;
                }
            }
        } catch (\UnexpectedValueException $e) {
            return !is_dir($dir);
        }
        return @rmdir($dir);
    }

    /** 清空全部静态缓存（安装/卸载后调用，保持测试与请求内一致性） */
    public static function reset(): void
    {
        self::$manifests = [];
        self::$modules = [];
        self::$contexts = [];
        self::$activeCache = null;
    }
}
