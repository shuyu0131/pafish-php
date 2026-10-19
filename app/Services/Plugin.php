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
    private const INJECT_TARGETS = [
        'head', 'footer', 'sidebar',
        'comment_form', 'login_form', 'register_form', 'post_editor', 'micro_editor',
        // 插件自己的设置页底部：给插件放置独有的管理区块。
        'plugin_setting',
    ];
    private const MAX_ZIP_BYTES = 10 * 1024 * 1024;
    private const MAX_LOGS = 50;
    private const ADMIN_MENU_GROUPS = ['content', 'interaction', 'appearance', 'system'];
    private const ADMIN_ICON_PATTERN = '/^[a-z0-9-]{1,30}$/';
    private const EDITOR_SCOPES = ['post', 'page', 'micro'];
    private static ?array $manifests = [];
    private static ?array $contexts = [];
    private static ?array $activeCache = null;
    private static array $registeredHooks = [];

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
        // 旧版本升级时可能没有 active_plugins 设置；首次补齐所有声明为内置的插件。
        // 只有设置完全缺失时才补齐，保留管理员对已存在配置的停用选择。
        if (trim($raw) === '') {
            foreach (self::list() as $candidate) {
                $manifest = self::manifest($candidate);
                if (($manifest['builtin'] ?? false) === true) {
                    $names[] = $candidate;
                }
            }
            $names = array_values(array_unique($names));
        }
        if ($names !== (is_array($list) ? array_values(array_filter($list, static fn ($n): bool => is_string($n))) : [])) {
            Settings::set('active_plugins', json_encode($names, JSON_UNESCAPED_UNICODE));
        }
        return self::$activeCache = $names;
    }

    public static function isActive(string $name): bool
    {
        return in_array($name, self::activeNames(), true);
    }

    /** 内置插件由发行版提供，允许停用但不能从程序目录卸载。 */
    public static function isBuiltin(string $name): bool
    {
        $manifest = self::manifest($name);
        return ($manifest['builtin'] ?? false) === true;
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
        if (!is_file(self::root() . '/' . $name . '/extension.php')) {
            self::$manifests[$name] = ['m' => null, 'e' => '缺少 extension.php'];
            return ['manifest' => null, 'error' => '缺少 extension.php'];
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
        $dependencyError = ExtensionDependencies::validate($json['requires'] ?? null);
        if ($dependencyError !== null) {
            return $dependencyError;
        }
        $metaError = EntityMetaSchema::validate($json['meta'] ?? null);
        if ($metaError !== null) return $metaError;
        if (array_key_exists('editorScopes', $json)) {
            if (($json['category'] ?? '') !== 'editor' || !is_array($json['editorScopes'])) {
                return 'editorScopes 仅适用于 editor 插件且必须是数组';
            }
            if ($json['editorScopes'] === [] || array_filter($json['editorScopes'], static fn ($scope): bool => !is_string($scope) || !in_array($scope, self::EDITOR_SCOPES, true)) !== []) {
                return 'editorScopes 只能包含 post、page、micro';
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

    /** 规范化 manifest（过滤 settings/injects/pageTemplates/storage） */
    private static function normalize(array $json): array
    {
        // 设置项一律委托 ExtensionKernel 规范化，不再自建一份。
        //
        // 这里原来有一套独立的类型白名单与字段拷贝逻辑，只放行 9 种类型和
        // 7 个键——于是插件写 desc / section / maxlength / pattern / fields
        // 会被**静默丢弃**，插件作者在后台看不到自己的设置项，日志里也没有任何提示。
        // 规则必须只有一份，否则一定会随内核演进而分叉。
        $settings = [];
        foreach ((array) ($json['settings'] ?? []) as $s) {
            $field = ExtensionKernel::describeSetting($s, 'plugin');
            if ($field !== null) {
                $settings[] = $field;
            }
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
                    'activePath' => (string) ($item['activePath'] ?? ''),
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
            'category' => is_string($json['category'] ?? null) ? $json['category'] : '',
            'editorScopes' => (($json['category'] ?? '') === 'editor' && is_array($json['editorScopes'] ?? null))
                ? array_values(array_unique(array_intersect(self::EDITOR_SCOPES, $json['editorScopes'])))
                : (($json['category'] ?? '') === 'editor' ? self::EDITOR_SCOPES : []),
            'author' => is_string($json['author'] ?? null) ? $json['author'] : '',
            'authorUrl' => self::manifestUrl($json, 'authorUrl'),
            'homepage' => self::manifestUrl($json, 'homepage'),
            'requires' => ExtensionDependencies::normalize($json['requires'] ?? null),
            'settings' => $settings,
            'meta' => EntityMetaSchema::normalize($json['meta'] ?? null),
            'injects' => $injects,
            'pageTemplates' => $pageTemplates,
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
            $activePath = $item['activePath'] ?? '';
            $label = trim((string) ($item['label'] ?? ''));
            $icon = (string) ($item['icon'] ?? 'puzzle');
            $group = (string) ($item['group'] ?? 'system');
            $capability = $item['capability'] ?? null;
            $order = $item['order'] ?? 100;
            if (!is_string($path) || ($path !== '/' && preg_match('#^/[a-z0-9_-]{1,50}(?:/[a-z0-9_-]{1,50})*$#', $path) !== 1)
                || (!is_string($activePath) || ($activePath !== '' && preg_match('#^/admin/[a-z0-9_-]{1,50}(?:/[a-z0-9_-]{1,50})*$#', $activePath) !== 1))
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
                            'activePath' => (string) ($item['activePath'] ?? ''),
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
        return ExtensionLinks::manifestUrl($json, $key);
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
        ExtensionStorage::updateJson('plugin_data:' . $name, static function (array $current) use ($data): array {
            if (is_array($current['logs'] ?? null)) {
                $data['logs'] = $current['logs'];
            } else {
                unset($data['logs']);
            }
            return $data;
        });
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
        $fields = [];
        foreach (ExtensionKernel::flattenFields(self::settingsFields($name)) as $field) {
            if (isset($field['key'])) {
                $fields[(string) $field['key']] = $field;
            }
        }
        $clean = [];
        foreach ($partial as $key => $value) {
            $key = (string) $key;
            if (!isset($fields[$key]) || !is_scalar($value)) {
                continue;
            }
            $value = (string) $value;
            if (($fields[$key]['type'] ?? '') === 'password' && trim($value) === '') {
                continue;
            }
            $error = ExtensionKernel::validateSettingValue($fields[$key], $value);
            if ($error !== null) {
                throw new PluginSettingException([$key => $error]);
            }
            $clean[$key] = $value;
        }
        ExtensionStorage::updateJson('plugin_settings:' . $name, static fn (array $current): array => array_merge($current, $clean));
    }

    /** 追加插件日志。 */
    public static function log(string $name, string $message): void
    {
        ExtensionStorage::updateJson('plugin_data:' . $name, static function (array $data) use ($message): array {
            $logs = is_array($data['logs'] ?? null) ? $data['logs'] : [];
            $logs[] = ['time' => date('Y-m-d H:i:s'), 'message' => $message];
            $data['logs'] = array_slice($logs, -self::MAX_LOGS);
            return $data;
        });
    }

    // ---------- 生命周期 ----------

    /** 注册插件钩子（约定函数 registerHooks(ctx)） */
    public static function registerHooks(string $name): void
    {
        if (isset(self::$registeredHooks[$name])) {
            return;
        }
        $handler = ExtensionKernel::providerFor('plugin', $name, 'registerHooks');
        if ($handler === null) {
            return;
        }
        try {
            $handler(self::context($name));
            self::$registeredHooks[$name] = true;
        } catch (\Throwable $e) {
            \Pafish\Core\Logger::error("[pafish-plugin] {$name} registerHooks 失败：" . $e->getMessage());
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
        if (ExtensionKernel::runLifecycle('plugin', $name, $phase)) {
            return;
        }
        $handler = ExtensionKernel::providerFor('plugin', $name, $phase);
        if ($handler === null) {
            return;
        }
        try {
            $handler(self::context($name));
        } catch (\Throwable $e) {
            \Pafish\Core\Logger::error("[pafish-plugin] {$name} {$phase} 失败：" . $e->getMessage());
        }
    }

    /** 版本变化时运行显式数据迁移回调，旧版本为空表示首次启用。 */
    private static function runUpgrade(string $name, string $fromVersion, string $toVersion): void
    {
        if ($fromVersion === '' || $fromVersion === $toVersion) {
            return;
        }
        if (ExtensionKernel::runLifecycle('plugin', $name, 'onUpgrade', [$fromVersion, $toVersion], true)) {
            return;
        }
        $handler = ExtensionKernel::providerFor('plugin', $name, 'onUpgrade');
        if ($handler === null) {
            return;
        }
        try {
            $handler(self::context($name), $fromVersion, $toVersion);
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
        $handler = ExtensionKernel::providerFor('plugin', $name, 'testNotification');
        if ($handler === null) {
            throw new \RuntimeException('该插件不支持发送测试通知');
        }
        $result = $handler(self::context($name));
        if (!is_array($result)) {
            throw new \RuntimeException('插件返回的测试结果无效');
        }
        return $result;
    }

    /** 是否支持插件自带的通知测试入口。 */
    public static function supportsNotificationTest(string $name): bool
    {
        return ExtensionKernel::providerFor('plugin', $name, 'testNotification') !== null;
    }

    public static function testStorage(string $name): array
    {
        if (!self::isActive($name)) throw new \RuntimeException('请先启用插件');
        $handler = ExtensionKernel::providerFor('plugin', $name, 'testStorage');
        if ($handler === null) throw new \RuntimeException('该插件不支持存储连接测试');
        $result = $handler(self::context($name));
        if (!is_array($result)) throw new \RuntimeException('插件返回的测试结果无效');
        return $result;
    }

    public static function supportsStorageTest(string $name): bool
    {
        return ExtensionKernel::providerFor('plugin', $name, 'testStorage') !== null;
    }

    /** 启用插件。 */
    public static function activate(string $name): void
    {
        $previousActive = self::activeNames();
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            throw new \RuntimeException('插件"' . $name . '"不可用：' . $desc['error']);
        }
        ExtensionDependencies::assertSatisfied('plugin', $name, $desc['manifest']['requires'] ?? []);
        if (!ExtensionKernel::boot('plugin', $name, self::root(), self::context($name))) {
            throw new \RuntimeException('插件入口加载失败');
        }
        try {
            ExtensionRoutes::assertCanActivate('plugin', $name, $desc['manifest']);
            $version = (string) $desc['manifest']['version'];
            $previousVersion = ExtensionSchema::installedVersion('plugin', $name);
            $schema = $desc['manifest']['schema'] ?? null;
            ExtensionSchema::sync('plugin', $name, $schema);
            ExtensionSchema::recordFingerprint('plugin', $name, $schema);
            self::runUpgrade($name, $previousVersion, $version);
            ExtensionSchema::recordVersion('plugin', $name, $version);
            // 编辑器是全站唯一能力。启用新的编辑器插件时自动停用已启用的同类插件，
            // 让“启用即接管”保持确定性，不再额外增加站点级选择配置。
            $list = self::activeNames();
            $wasActive = in_array($name, $list, true);
            $replacedEditors = [];
            if (($desc['manifest']['category'] ?? '') === 'editor') {
                foreach ($list as $active) {
                    if ($active === $name) {
                        continue;
                    }
                    $activeManifest = self::manifest($active);
                    if (($activeManifest['category'] ?? '') === 'editor') {
                        $replacedEditors[] = $active;
                        $list = array_values(array_filter($list, static fn (string $item): bool => $item !== $active));
                    }
                }
            }
            if (!$wasActive) {
                $list[] = $name;
            }
            if (!$wasActive || $replacedEditors !== []) {
                self::setActivePlugins($list);
            }
            foreach ($replacedEditors as $active) {
                self::unregisterHooks($active);
                unset(self::$registeredHooks[$active]);
                self::runLifecycle($active, 'onDeactivate');
                ExtensionKernel::reset('plugin', $active);
            }
            if (!$wasActive) {
                self::registerHooks($name);
                self::runLifecycle($name, 'onActivate');
            }
        } catch (\Throwable $e) {
            // 启用失败不能留下新插件的路由、provider、设置声明或生命周期回调。
            ExtensionKernel::reset('plugin', $name);
            // 编辑器切换是一个整体操作：新插件任一步失败都恢复原激活列表，
            // 并重新启动之前的编辑器，避免站点进入“无编辑器”状态。
            try {
                self::setActivePlugins($previousActive);
                foreach ($previousActive as $active) {
                    $manifest = self::manifest($active);
                    if (($manifest['category'] ?? '') !== 'editor') {
                        continue;
                    }
                    if (ExtensionKernel::boot('plugin', $active, self::root(), self::context($active))) {
                        self::registerHooks($active);
                    }
                }
            } catch (\Throwable $restoreError) {
                \Pafish\Core\Logger::error('[pafish-plugin] 恢复激活插件失败：' . $restoreError->getMessage());
            }
            throw $e;
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
        unset(self::$registeredHooks[$name]);
        self::runLifecycle($name, 'onDeactivate');
        ExtensionKernel::reset('plugin', $name);
    }

    /** 卸载插件：默认保留自有设置、数据和表，避免主题/插件切换误伤业务数据。 */
    public static function uninstall(string $name, bool $deleteData = false): void
    {
        $desc = self::describe($name);
        if (($desc['error'] ?? null) === '缺少 extension.php') {
            $desc = self::describeForUninstall($name);
        }
        if ($desc['error'] !== null) {
            throw new \RuntimeException('无法卸载：' . $desc['error']);
        }
        if (($desc['manifest']['builtin'] ?? false) === true) {
            throw new \RuntimeException('内置插件不能卸载，可停用其功能');
        }
        if (self::isActive($name)) {
            self::deactivate($name);
        }
        self::runLifecycle($name, 'onUninstall');
        // 先删除代码目录；目录权限失败时保留设置和业务表，避免半卸载造成不可恢复的数据损失。
        if (!self::rmDir(self::root() . '/' . $name)) {
            throw new \RuntimeException('删除插件目录失败');
        }
        if ($deleteData) {
            EntityMeta::deleteOwner('plugin', $name);
            ExtensionSchema::drop('plugin', $name, $desc['manifest']['schema'] ?? null);
            Settings::remove('plugin_settings:' . $name);
            Settings::remove('plugin_data:' . $name);
            ExtensionSchema::forget('plugin', $name);
        }
        unset(self::$manifests[$name], self::$contexts[$name], self::$registeredHooks[$name]);
    }

    /** A valid manifest is sufficient to safely remove an orphaned code directory. */
    private static function describeForUninstall(string $name): array
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) return ['manifest' => null, 'error' => '插件名不合法'];
        $dir = self::root() . '/' . $name;
        if (!is_dir($dir)) return ['manifest' => null, 'error' => '插件目录不存在'];
        $file = $dir . '/plugin.json';
        if (!is_file($file)) return ['manifest' => null, 'error' => '缺少 plugin.json'];
        $json = json_decode((string) file_get_contents($file), true);
        if (!is_array($json)) return ['manifest' => null, 'error' => 'plugin.json 不是合法 JSON'];
        $error = self::validateManifest($json, $name);
        if ($error !== null) return ['manifest' => null, 'error' => $error];
        return ['manifest' => self::normalize($json), 'error' => null];
    }

    /**
     * 按 manifest schema 保存插件设置。
     *
     * 校验走 ExtensionKernel::validateSettingValue，与主题侧同一套规则；
     * 原来这里只硬编码了颜色格式检查，插件声明的 maxlength / pattern /
     * 数值区间 / 复合结构全部不生效。
     *
     * @return array<string, string>
     */
    public static function saveSettings(string $name, array $body): array
    {
        if (!self::isActive($name)) {
            throw new \RuntimeException('请先启用插件再配置');
        }
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            throw new \RuntimeException($desc['error']);
        }
        $clean = [];
        $errors = [];
        // 展平 group 容器：容器自身不存值，子字段才是真实设置项
        foreach (ExtensionKernel::flattenFields(self::settingsFields($name)) as $field) {
            $key = (string) $field['key'];
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $raw = $body[$key];
            $value = is_scalar($raw) ? (string) $raw : '';
            if ($field['type'] === 'password' && trim($value) === '') {
                continue;
            }
            if ($field['type'] === 'checkbox' || $field['type'] === 'switcher') {
                $value = $value === '1' ? '1' : '0';
            }
            $error = ExtensionKernel::validateSettingValue($field, $value);
            if ($error !== null) {
                $errors[$key] = $error;
                continue;
            }
            $clean[$key] = $value;
        }
        if ($errors !== []) {
            throw new PluginSettingException($errors);
        }
        self::setSettings($name, $clean);
        return $clean;
    }

    /**
     * 插件设置字段（合并 plugin.json 声明与运行时 $ctx->settings() 注册）。
     *
     * 注意：`manifest()` 已经过一次 normalizeSetting，字段结构里带 `rules`；
     * 若这里再规范化一遍，`normalizeSetting` 会去读顶层的 maxlength/required
     * （它们已经被折叠进 rules），第二次规范化反而会把约束**丢掉**。
     * 因此只在字段尚未规范化（缺 rules）时才补一次。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function settingsFields(string $name): array
    {
        // 判断字段是否**已经**规范化过。容器与复合类型没有顶层 `rules`，
        // 不能只看 rules——否则 group 会被二次规范化，子字段的 rules 会被重建掉。
        $alreadyNormalized = static function (array $field): bool {
            $type = (string) ($field['type'] ?? '');
            return isset($field['rules'])
                || ExtensionKernel::isDecorativeType($type)
                || ExtensionKernel::isContainerType($type)
                || in_array($type, ['repeater', 'spacing', 'typography'], true);
        };

        $manifest = self::manifest($name);
        $fields = [];
        foreach ((array) ($manifest['settings'] ?? []) as $field) {
            if (!is_array($field) || !isset($field['key'])) {
                continue;
            }
            $normalized = $alreadyNormalized($field)
                ? $field
                : ExtensionKernel::describeSetting($field, 'plugin');
            if ($normalized !== null) {
                $fields[(string) $normalized['key']] = $normalized;
            }
        }
        foreach (ExtensionKernel::settingsFor('plugin', $name) as $field) {
            if (is_array($field) && isset($field['key'])) {
                $normalized = $alreadyNormalized($field)
                    ? $field
                    : ExtensionKernel::describeSetting($field, 'plugin');
                if ($normalized !== null) {
                    $fields[(string) $normalized['key']] = $normalized;
                }
            }
        }
        return array_values($fields);
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
            $handlers = ExtensionKernel::injectionsFor('plugin', $name, $target);
            if ($handlers !== []) {
                foreach ($handlers as $handler) {
                    try {
                        $html = $handler($context, self::context($name));
                        if (is_string($html) && trim($html) !== '') {
                            $parts[] = $html;
                        }
                    } catch (\Throwable $e) {
                        \Pafish\Core\Logger::error("[pafish-plugin] {$name} renderInjection({$target}) 失败：" . $e->getMessage());
                    }
                }
                continue;
            }
            $handler = ExtensionKernel::providerFor('plugin', $name, 'renderInjection');
            if ($handler === null) {
                continue;
            }
            try {
                $html = $handler($target, self::context($name), $context);
                if (is_string($html) && trim($html) !== '') {
                    $parts[] = $html;
                }
            } catch (\Throwable $e) {
                \Pafish\Core\Logger::error("[pafish-plugin] {$name} renderInjection({$target}) 失败：" . $e->getMessage());
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

    /** 分发声明了当前模板的激活插件，返回动态正文，由主题承载布局。 */
    public static function renderPageTemplate(array $page): string
    {
        $template = (string) ($page['template'] ?? 'default');
        if ($template === 'default' || preg_match(self::TEMPLATE_NAME_PATTERN, $template) !== 1) return '';
        // 同名时与后台选项保持一致，当前主题文件优先。
        if (Theme::pageTemplateFile(Theme::active(), $template) !== null) return '';
        foreach (self::activeNames() as $name) {
            $manifest = self::describe($name);
            if ($manifest['error'] !== null
                || !in_array($template, array_column($manifest['manifest']['pageTemplates'] ?? [], 'name'), true)) continue;
            $handler = ExtensionKernel::providerFor('plugin', $name, 'renderPageTemplate');
            if ($handler === null) {
                continue;
            }
            try {
                $html = $handler($template, $page, self::context($name));
                if (is_string($html) && trim($html) !== '') {
                    return $html;
                }
            } catch (\Throwable $e) {
                \Pafish\Core\Logger::error("[pafish-plugin] {$name} renderPageTemplate 失败：" . $e->getMessage());
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
            $handler = ExtensionKernel::providerFor('plugin', $name, 'renderSitemap');
            if ($handler === null) {
                continue;
            }
            try {
                $xml = $handler(self::context($name));
                if (is_string($xml) && trim($xml) !== '') {
                    return trim($xml);
                }
            } catch (\Throwable $e) {
                \Pafish\Core\Logger::error("[pafish-plugin] {$name} renderSitemap 失败：" . $e->getMessage());
            }
        }
        return null;
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
            $store = ExtensionKernel::providerFor('plugin', $name, 'storeFile');
            if ($store === null) {
                continue;
            }
            return [
                'name' => $name,
                'title' => (string) $desc['manifest']['storage']['title'],
                'storeFile' => $store,
                'deleteFile' => ExtensionKernel::providerFor('plugin', $name, 'deleteFile'),
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
            $res = $backend['storeFile']($file, self::context($backend['name']));
            $url = is_string($res) ? $res : (is_array($res) ? ($res['url'] ?? null) : null);
            if (!is_string($url) || trim($url) === '') {
                return null;
            }
            return trim($url);
        } catch (\Throwable $e) {
            \Pafish\Core\Logger::error('[pafish-plugin] 云存储上传失败，回退本地：' . $e->getMessage());
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
            if ($backend === null || !is_callable($backend['deleteFile'] ?? null)) {
                return;
            }
            $backend['deleteFile']($url, self::context($backend['name']));
        } catch (\Throwable $e) {
            \Pafish\Core\Logger::error('[pafish-plugin] 云端删除失败（忽略）：' . $url);
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
                try {
                    $manifest = self::manifest($name);
                    if ($manifest === null) {
                        throw new \RuntimeException('插件清单不可用');
                    }
                    ExtensionDependencies::assertSatisfied('plugin', $name, $manifest['requires'] ?? []);
                    // 每次请求做廉价版本/schema 指纹检查；只有变化时才访问 INFORMATION_SCHEMA 并执行 DDL。
                    if (self::syncActiveSchema($name)) {
                        if (!ExtensionKernel::boot('plugin', $name, self::root(), self::context($name))) {
                            throw new \RuntimeException('插件入口加载失败');
                        }
                        self::registerHooks($name);
                    } else {
                        // schema/迁移失败的插件不能把本次请求里提前注册的能力留在运行时。
                        ExtensionKernel::reset('plugin', $name);
                    }
                } catch (\Throwable $e) {
                    // 单个扩展损坏不能阻断其他插件和核心请求；清掉它可能已经注册的钩子、路由和 provider。
                    ExtensionKernel::reset('plugin', $name);
                    \Pafish\Core\Logger::error('[pafish-plugin] ' . $name . ' 启动失败：' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            \Pafish\Core\Logger::error('[pafish-plugin] boot 失败：' . $e->getMessage());
        }
    }

    /** 同步激活插件的表结构和版本迁移；失败时隔离该插件，不能影响其他插件。 */
    private static function syncActiveSchema(string $name): bool
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null || $desc['manifest'] === null) {
            \Pafish\Core\Logger::error('[pafish-plugin] ' . $name . ' schema 跳过：' . (string) ($desc['error'] ?? 'manifest 不可用'));
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
            // 版本迁移可能由 extension.php 注册，先加载统一入口再执行 onUpgrade。
            if (!ExtensionKernel::boot('plugin', $name, self::root(), self::context($name))) {
                throw new \RuntimeException('插件入口加载失败');
            }
            self::runUpgrade($name, $fromVersion, $version);
            if ($fromVersion !== $version) {
                ExtensionSchema::recordVersion('plugin', $name, $version);
            }
            return true;
        } catch (\Throwable $e) {
            \Pafish\Core\Logger::error('[pafish-plugin] ' . $name . ' schema/upgrade 失败：' . $e->getMessage());
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
        foreach (self::activeNames() as $name) {
            ExtensionKernel::reset('plugin', $name);
        }
        self::$manifests = [];
        self::$registeredHooks = [];
        self::$contexts = [];
        self::$activeCache = null;
    }
}
