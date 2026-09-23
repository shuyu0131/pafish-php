<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Auth;
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
        'comment_form', 'login_form', 'register_form', 'post_editor', 'micro_editor',
        // 插件自己的设置页底部：给插件放它独有的管理区块（原 emlog 插件用设置页 Tab 做分类管理等）。
        'plugin_setting',
    ];
    private const SETTING_TYPES = ['text', 'textarea', 'checkbox', 'select', 'color', 'switcher', 'radio', 'image', 'password'];
    private const MAX_ZIP_BYTES = 10 * 1024 * 1024;
    private const MAX_LOGS = 50;
    private const ADMIN_MENU_GROUPS = ['content', 'interaction', 'appearance', 'system'];
    private const ADMIN_ICON_PATTERN = '/^[a-z0-9-]{1,30}$/';
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
        $names = array_values(array_unique($names));
        if ($names !== (is_array($list) ? array_values(array_filter($list, static fn ($n): bool => is_string($n))) : [])) {
            Settings::set('active_plugins', json_encode($names, JSON_UNESCAPED_UNICODE));
        }
        return self::$activeCache = $names;
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
        // 插件名称即目录名，不在核心维护具体插件的别名或迁移规则。
        $name = (string) $name;
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
        $routesError = ExtensionRoutes::validateDeclaration($json['routes'] ?? null, 'plugin', $dirName);
        if ($routesError !== null) {
            return $routesError;
        }
        $adminError = self::validateAdminDeclaration($json, $dirName);
        if ($adminError !== null) {
            return $adminError;
        }
        $schemaError = ExtensionSchema::validateDeclaration($json['schema'] ?? null, 'plugin', $dirName);
        if ($schemaError !== null) {
            return $schemaError;
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

        $routes = ExtensionRoutes::fromManifest($json, 'plugin', (string) $json['name']);
        $schema = is_array($json['schema'] ?? null) ? $json['schema'] : null;

        $admin = null;
        if (is_array($json['admin'] ?? null)) {
            $menu = [];
            foreach ((array) ($json['admin']['menu'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $menu[] = [
                    'route' => (string) ($item['route'] ?? ''),
                    'label' => trim((string) ($item['label'] ?? '')),
                    'icon' => (string) ($item['icon'] ?? 'puzzle'),
                    'group' => (string) ($item['group'] ?? 'system'),
                    'capability' => (string) ($item['capability'] ?? ''),
                    'order' => (int) ($item['order'] ?? 100),
                ];
            }
            $admin = ['menu' => $menu];
        }

        $storage = null;
        if (is_array($json['storage'] ?? null) && is_string($json['storage']['title'] ?? null)
            && $json['storage']['title'] !== '') {
            $storage = ['title' => $json['storage']['title']];
        }

        $frontendUrl = '';
        if (is_string($json['frontendUrl'] ?? null) && preg_match('#^/[A-Za-z0-9_./?=&-]{1,200}$#', trim($json['frontendUrl'])) === 1) {
            $frontendUrl = trim($json['frontendUrl']);
        }

        return [
            'name' => $json['name'],
            'title' => $json['title'],
            'version' => $json['version'],
            // 是否内置由插件清单声明，核心不维护具体插件名单。
            'builtin' => ($json['builtin'] ?? false) === true,
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
            'routes' => $routes,
            'admin' => $admin,
            'schema' => $schema,
            'storage' => $storage,
            'frontendUrl' => $frontendUrl,
        ];
    }

    /** 校验声明式后台菜单：只能挂载已有 capability，并且必须指向 admin GET 路由。 */
    private static function validateAdminDeclaration(array $json, string $name): ?string
    {
        if (!array_key_exists('admin', $json)) {
            return null;
        }
        if (!is_array($json['admin']) || !is_array($json['admin']['menu'] ?? null)) {
            return 'admin.menu 必须是数组';
        }
        $routes = ExtensionRoutes::fromManifest($json, 'plugin', $name);
        $seen = [];
        foreach ($json['admin']['menu'] as $item) {
            if (!is_array($item)) {
                return 'admin.menu 包含无效项';
            }
            $path = $item['route'] ?? null;
            $label = trim((string) ($item['label'] ?? ''));
            $icon = (string) ($item['icon'] ?? 'puzzle');
            $group = (string) ($item['group'] ?? 'system');
            $capability = $item['capability'] ?? null;
            $order = $item['order'] ?? 100;
            if (!is_string($path) || ($path !== '/' && preg_match('#^/[a-z0-9_-]{1,50}(?:/[a-z0-9_-]{1,50})*$#', $path) !== 1)
                || $label === '' || strlen($label) > 80
                || preg_match(self::ADMIN_ICON_PATTERN, $icon) !== 1
                || !in_array($group, self::ADMIN_MENU_GROUPS, true)
                || !is_string($capability) || !in_array($capability, Auth::CAPABILITIES, true)
                || (!is_int($order) && !(is_string($order) && ctype_digit($order)))
                || (int) $order < 0 || (int) $order > 10000) {
                return 'admin.menu 包含无效项';
            }
            if (isset($seen[$path])) {
                return 'admin.menu 包含重复路由';
            }
            $seen[$path] = true;
            $route = null;
            foreach ($routes as $candidate) {
                if ($candidate['admin'] && $candidate['method'] === 'GET' && $candidate['path'] === $path) {
                    $route = $candidate;
                    break;
                }
            }
            if ($route === null || $route['capability'] !== $capability) {
                return 'admin.menu.route 必须对应同 capability 的 admin GET 路由';
            }
        }
        return null;
    }

    /** 当前激活插件声明的后台菜单，按分组和顺序返回给 AdminController。 */
    public static function adminMenu(): array
    {
        $items = [];
        foreach (self::activeNames() as $name) {
            $desc = self::describe($name);
            $manifest = $desc['manifest'];
            if ($manifest === null) {
                continue;
            }
            $routes = is_array($manifest['routes'] ?? null) ? $manifest['routes'] : [];
            foreach (($manifest['admin']['menu'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach ($routes as $route) {
                    if (($route['admin'] ?? false) && $route['method'] === 'GET' && $route['path'] === $item['route']) {
                        $items[] = [
                            'href' => $route['fullPath'],
                            'label' => $item['label'],
                            'icon' => $item['icon'],
                            'group' => $item['group'],
                            'capability' => $item['capability'],
                            'order' => $item['order'],
                            '_plugin' => $name,
                        ];
                        break;
                    }
                }
            }
        }
        $groupOrder = array_flip(self::ADMIN_MENU_GROUPS);
        usort($items, static function (array $a, array $b) use ($groupOrder): int {
            $group = ($groupOrder[(string) $a['group']] ?? 99) <=> ($groupOrder[(string) $b['group']] ?? 99);
            if ($group !== 0) {
                return $group;
            }
            $order = ((int) $a['order']) <=> ((int) $b['order']);
            return $order !== 0 ? $order : strcmp((string) $a['_plugin'], (string) $b['_plugin']);
        });
        foreach ($items as &$item) {
            unset($item['_plugin'], $item['order']);
        }
        unset($item);
        return $items;
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
        $name = (string) $name;
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
    public static function context(string $name): ExtensionContext
    {
        $name = (string) $name;
        if (isset(self::$contexts[$name])) {
            return self::$contexts[$name];
        }
        $apiVersion = (int) (self::manifest($name)['apiVersion'] ?? 1);
        return self::$contexts[$name] = new ExtensionContext('plugin', $name, $apiVersion);
    }

    /** 插件数据（plugin_data:{name} JSON 对象；损坏/缺失返回空数组） */
    public static function data(string $name): array
    {
        $v = json_decode((string) Settings::get('plugin_data:' . $name, ''), true);
        return is_array($v) ? $v : [];
    }

    public static function setData(string $name, array $data): void
    {
        $name = (string) $name;
        Settings::set('plugin_data:' . $name, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /** 插件设置（plugin_settings:{name}） */
    public static function settings(string $name): array
    {
        $name = (string) $name;
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

    /** 版本变化时运行显式数据迁移回调，旧版本为空表示首次启用。 */
    private static function runUpgrade(string $name, string $fromVersion, string $toVersion): void
    {
        if ($fromVersion === '' || $fromVersion === $toVersion) {
            return;
        }
        $mod = self::module($name);
        if ($mod === null || !is_callable($mod['onUpgrade'] ?? null)) {
            return;
        }
        try {
            $mod['onUpgrade'](self::context($name), $fromVersion, $toVersion);
        } catch (\Throwable $e) {
            throw new \RuntimeException('插件升级迁移失败：' . $e->getMessage(), 0, $e);
        }
    }

    /** 执行插件声明的后台测试操作；插件自行决定测试内容。 */
    public static function testNotification(string $name): array
    {
        if (!self::isActive($name)) {
            throw new \RuntimeException('请先启用插件');
        }
        $mod = self::module($name);
        if ($mod === null || !is_callable($mod['testNotification'] ?? null)) {
            throw new \RuntimeException('该插件不支持发送测试通知');
        }
        $result = $mod['testNotification'](self::context($name));
        if (!is_array($result)) {
            throw new \RuntimeException('插件返回的测试结果无效');
        }
        return $result;
    }

    /** 是否支持插件自带的通知测试入口。 */
    public static function supportsNotificationTest(string $name): bool
    {
        $mod = self::module($name);
        return $mod !== null && is_callable($mod['testNotification'] ?? null);
    }

    public static function testStorage(string $name): array
    {
        if (!self::isActive($name)) throw new \RuntimeException('请先启用插件');
        $mod = self::module($name);
        if ($mod === null || !is_callable($mod['testStorage'] ?? null)) throw new \RuntimeException('该插件不支持存储连接测试');
        $result = $mod['testStorage'](self::context($name));
        if (!is_array($result)) throw new \RuntimeException('插件返回的测试结果无效');
        return $result;
    }

    public static function supportsStorageTest(string $name): bool
    {
        $mod = self::module($name);
        return $mod !== null && is_callable($mod['testStorage'] ?? null);
    }

    /** 启用插件。 */
    public static function activate(string $name): void
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            throw new \RuntimeException('插件"' . $name . '"不可用：' . $desc['error']);
        }
        ExtensionRoutes::assertCanActivate('plugin', $name, $desc['manifest']);
        $version = (string) $desc['manifest']['version'];
        $previousVersion = ExtensionSchema::installedVersion('plugin', $name);
        $schema = $desc['manifest']['schema'] ?? null;
        ExtensionSchema::sync('plugin', $name, $schema);
        ExtensionSchema::recordFingerprint('plugin', $name, $schema);
        self::runUpgrade($name, $previousVersion, $version);
        ExtensionSchema::recordVersion('plugin', $name, $version);
        $list = self::activeNames();
        $wasActive = in_array($name, $list, true);
        if (!$wasActive) {
            $list[] = $name;
            self::setActivePlugins($list);
        }
        if (!$wasActive) {
            self::registerHooks($name);
            self::runLifecycle($name, 'onActivate');
        }
    }

    /** 停用插件。 */
    public static function deactivate(string $name): void
    {
        if (!self::isActive($name)) {
            return;
        }
        $list = array_values(array_filter(self::activeNames(), static fn (string $n): bool => $n !== $name));
        self::setActivePlugins($list);
        self::unregisterHooks($name);
        self::runLifecycle($name, 'onDeactivate');
    }

    /** 卸载插件：默认保留自有设置、数据和表，避免主题/插件切换误伤业务数据。 */
    public static function uninstall(string $name, bool $deleteData = false): void
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            throw new \RuntimeException('无法卸载：' . $desc['error']);
        }
        if (self::isActive($name)) {
            self::deactivate($name);
        }
        self::runLifecycle($name, 'onUninstall');
        if ($deleteData) {
            ExtensionSchema::drop('plugin', $name, $desc['manifest']['schema'] ?? null);
            Settings::remove('plugin_settings:' . $name);
            Settings::remove('plugin_data:' . $name);
            ExtensionSchema::forget('plugin', $name);
        }
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
     * 站点地图输出扩展点：首个返回有效 XML 的已启用插件接管 /sitemap.xml。
     * 插件不可用或发生异常时返回 null，由核心控制器使用兼容输出。
     */
    public static function renderSitemap(): ?string
    {
        foreach (self::activeNames() as $name) {
            $mod = self::module($name);
            if ($mod === null || !is_callable($mod['renderSitemap'] ?? null)) {
                continue;
            }
            try {
                $xml = $mod['renderSitemap'](self::context($name));
                if (is_string($xml) && trim($xml) !== '') {
                    return trim($xml);
                }
            } catch (\Throwable $e) {
                error_log("[pafish-plugin] {$name} renderSitemap 失败：" . $e->getMessage());
            }
        }
        return null;
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
                // 每次请求做廉价版本/schema 指纹检查；只有变化时才访问 INFORMATION_SCHEMA 并执行 DDL。
                if (self::syncActiveSchema($name)) {
                    self::registerHooks($name);
                }
            }
        } catch (\Throwable $e) {
            error_log('[pafish-plugin] boot 失败：' . $e->getMessage());
        }
    }

    /** 同步激活插件的表结构和版本迁移；失败时隔离该插件，不能影响其他插件。 */
    private static function syncActiveSchema(string $name): bool
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null || $desc['manifest'] === null) {
            error_log('[pafish-plugin] ' . $name . ' schema 跳过：' . (string) ($desc['error'] ?? 'manifest 不可用'));
            return false;
        }
        try {
            $version = (string) ($desc['manifest']['version'] ?? '');
            $schema = $desc['manifest']['schema'] ?? null;
            $fromVersion = ExtensionSchema::installedVersion('plugin', $name);
            $fingerprint = ExtensionSchema::fingerprint($schema);
            if (ExtensionSchema::installedFingerprint('plugin', $name) !== $fingerprint) {
                ExtensionSchema::sync('plugin', $name, $schema);
                ExtensionSchema::recordFingerprint('plugin', $name, $schema);
            }
            self::runUpgrade($name, $fromVersion, $version);
            if ($fromVersion !== $version) {
                ExtensionSchema::recordVersion('plugin', $name, $version);
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[pafish-plugin] ' . $name . ' schema/upgrade 失败：' . $e->getMessage());
            return false;
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
            $target = self::root() . '/' . $top;
            $stage = self::root() . '/.' . $top . '.install-' . bin2hex(random_bytes(4));
            $backup = null;
            if (@mkdir($stage, 0755, true) === false) {
                throw new \RuntimeException('无法创建插件临时目录');
            }
            $extracted = @$zip->extractTo($stage);
            if (!$extracted) {
                $zip->close();
                self::rmDir($stage);
                throw new \RuntimeException('解压失败（已回滚）');
            }
            $zip->close();
            $stagedTarget = $stage . '/' . $top;
            if (!is_file($stagedTarget . '/plugin.json')) {
                self::rmDir($stage);
                throw new \RuntimeException('安装失败：缺少 plugin.json（已回滚）');
            }
            $json = json_decode((string) file_get_contents($stagedTarget . '/plugin.json'), true);
            $error = is_array($json) ? self::validateManifest($json, (string) $top) : 'plugin.json 不是合法 JSON';
            if ($error !== null) {
                self::rmDir($stage);
                throw new \RuntimeException('安装失败：' . $error . '（已回滚）');
            }
            if (is_dir($target)) {
                $backup = self::root() . '/.' . $top . '.backup-' . bin2hex(random_bytes(4));
                if (!@rename($target, $backup)) {
                    self::rmDir($stage);
                    throw new \RuntimeException('无法替换已安装插件，请检查目录权限');
                }
            }
            if (!@rename($stagedTarget, $target)) {
                if ($backup !== null) @rename($backup, $target);
                self::rmDir($stage);
                throw new \RuntimeException('插件更新失败（已回滚）');
            }
            self::rmDir($stage);
            if ($backup !== null) self::rmDir($backup);
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
        return self::installFromBuffer(OutboundHttp::get($url, self::MAX_ZIP_BYTES), $url);
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
