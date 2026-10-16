<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Hooks;
use Pafish\Core\Logger;

/**
 * 统一扩展运行时。
 *
 * 主题和插件只通过 extension.php，并使用 ExtensionContext 注册能力。
 */
final class ExtensionKernel
{
    private const SETTING_TYPES = [
        'text', 'textarea', 'checkbox', 'select', 'color', 'switcher', 'radio', 'image', 'password',
        // 展示型：不产生设置值，只用于排版说明（CSF 的 heading / subheading）
        'heading', 'subheading',
        // 数值型
        'slider', 'spacing',
        // 复合型
        'typography', 'repeater',
        // 容器型：可折叠区块，内含子字段（CSF 的 group）
        'group',
        // 视觉选择型：让站长「看图选」，而不是读懂值再想象效果
        'button_set', 'image_select', 'palette',
        // 复合视觉型：把一组相关 CSS 属性合成一个值
        'background', 'border',
        // 代码型
        'code',
    ];

    /**
     * 不产生设置值的展示型类型。
     *
     * 它们只做排版，保存时应跳过、也不参与必填/校验统计。
     */
    private const DECORATIVE_TYPES = ['heading', 'subheading'];

    /** 值为 JSON 的复合类型，保存前按类型做结构化校验。 */
    private const JSON_TYPES = ['spacing', 'typography', 'repeater', 'background', 'border'];

    /** 不含值、只做容器的类型（其子字段才是真实设置项）。 */
    private const CONTAINER_TYPES = ['group'];

    /**
     * 复合视觉型的子键白名单（保存时拒绝未声明的键）。
     *
     * 与 spacing / typography 一样存 JSON；键名统一小写下划线，
     * 写成驼峰不会报错但也不生效，所以这里显式白名单化。
     */
    private const COMPOSITE_KEYS = [
        'background' => ['color', 'image', 'repeat', 'position', 'size', 'attachment'],
        'border' => ['width', 'style', 'color'],
    ];

    /**
     * 复合型里「值单一、可内联渲染」的类型：允许作为 group 的子字段。
     *
     * repeater 行内仍不放行——行内再嵌 JSON 复合值会让表格布局失控。
     */
    private const COMPOUND_INLINE_TYPES = ['spacing', 'typography', 'background', 'border'];

    /**
     * 可安全放进容器/repeater 子字段的类型。
     *
     * 排除容器自身与复合类型，避免无限递归和难以渲染的深嵌套；
     * group 子字段另见 COMPOUND_INLINE_TYPES。
     */
    private const LEAF_TYPES = [
        'text', 'textarea', 'checkbox', 'select', 'color', 'switcher',
        'radio', 'image', 'password', 'slider',
        'button_set', 'image_select', 'palette', 'code',
    ];

    /** @var array<string, true> */
    private static array $booted = [];

    /** @var array<string, array<string, callable>> */
    private static array $handlers = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private static array $routes = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private static array $settings = [];

    /** @var array<string, array<string, array<int, callable>>> */
    private static array $injections = [];

    /** @var array<string, array<string, array<int, callable>>> */
    private static array $lifecycle = [];
    /** @var array<string, array<string, callable>> */
    private static array $providers = [];

    private static int $handlerSequence = 0;

    /** 加载扩展的统一 PHP 入口；每个请求和扩展只执行一次。 */
    public static function boot(string $kind, string $name, string $root, ExtensionContext $context): bool
    {
        $key = self::key($kind, $name);
        if (isset(self::$booted[$key])) {
            return true;
        }
        $file = rtrim($root, '/\\') . '/' . $name . '/extension.php';
        if (!is_file($file)) {
            return false;
        }

        try {
            $definition = require $file;
            $register = is_callable($definition)
                ? $definition
                : (is_array($definition) && is_callable($definition['register'] ?? null)
                    ? $definition['register']
                    : null);
            if ($register === null) {
                throw new \RuntimeException('extension.php 必须返回注册函数或包含 register 的数组');
            }
            $register($context);
            self::$booted[$key] = true;
            return true;
        } catch (\Throwable $e) {
            Logger::error('[pafish-extension] 加载 ' . $key . ' 失败：' . $e->getMessage());
            self::reset($kind, $name);
            return false;
        }
    }

    /** 注册扩展路由；handler 可以是可调用对象，也可以是入口模块中的方法名。 */
    public static function route(
        string $kind,
        string $name,
        string $method,
        string $path,
        callable|string $handler,
        array $options = []
    ): string {
        $method = strtoupper($method);
        $handlerName = is_string($handler) ? $handler : self::handlerName($kind, $name);

        $route = [
            'method' => $method,
            'path' => $path,
            'handler' => $handlerName,
            'auth' => (string) ($options['auth'] ?? 'guest'),
            'response' => (string) ($options['response'] ?? ($method === 'POST' ? 'json' : 'html')),
            'csrf' => array_key_exists('csrf', $options) ? (bool) $options['csrf'] : $method === 'POST',
        ];
        foreach (['public', 'admin'] as $flag) {
            if (array_key_exists($flag, $options)) {
                $route[$flag] = (bool) $options[$flag];
            }
        }
        if (array_key_exists('capability', $options)) {
            $route['capability'] = $options['capability'];
        }

        $normalized = ExtensionRoutes::normalizeRuntimeRoute($route, $kind, $name);
        if ($normalized === null) {
            throw new \InvalidArgumentException('扩展路由声明无效：' . $method . ' ' . $path);
        }
        $manifest = $kind === 'theme' ? Theme::manifest($name) : Plugin::manifest($name);
        if (is_array($manifest)) {
            foreach (ExtensionRoutes::fromManifest($manifest, $kind, $name) as $existing) {
                if ($existing['method'] === $normalized['method']
                    && $existing['fullPath'] === $normalized['fullPath']) {
                    // manifest 描述路由元数据，extension.php 绑定实际处理器；
                    // 两处声明同一路径是统一入口的正常用法，不应被当成重复路由。
                    if ($existing['handler'] !== $normalized['handler'] && !is_callable($handler)) {
                        throw new \InvalidArgumentException('扩展路由处理器与清单不一致：' . $method . ' ' . $path);
                    }
                    if (is_callable($handler)) {
                        self::$handlers[self::key($kind, $name)][$existing['handler']] = $handler;
                    }
                    return $existing['handler'];
                }
            }
        }
        foreach (self::$routes[self::key($kind, $name)] ?? [] as $existing) {
            if ($existing['method'] === $normalized['method']
                && $existing['fullPath'] === $normalized['fullPath']) {
                throw new \InvalidArgumentException('扩展路由重复：' . $method . ' ' . $path);
            }
        }
        if (is_callable($handler)) {
            self::$handlers[self::key($kind, $name)][$handlerName] = $handler;
        }
        self::$routes[self::key($kind, $name)][] = $normalized;
        return $handlerName;
    }

    /** 注册后台设置声明；设置 UI 可由核心或扩展自定义渲染。 */
    public static function settings(string $kind, string $name, array $fields): void
    {
        $key = self::key($kind, $name);
        $current = self::$settings[$key] ?? [];
        $byKey = [];
        foreach ($current as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) {
                $byKey[$field['key']] = $field;
            }
        }
        foreach ($fields as $field) {
            $normalized = self::normalizeSetting($field, $kind);
            if ($normalized !== null) {
                $byKey[$normalized['key']] = $normalized;
            }
        }
        self::$settings[$key] = array_values($byKey);
    }

    /** 返回统一运行时注册的路由。 */
    public static function routesFor(string $kind, string $name): array
    {
        return self::$routes[self::key($kind, $name)] ?? [];
    }

    /** 返回统一运行时注册的设置声明。 */
    public static function settingsFor(string $kind, string $name): array
    {
        return self::$settings[self::key($kind, $name)] ?? [];
    }

    /** 注册前台注入处理器；具体注入点仍由 manifest injects 白名单控制。 */
    public static function inject(string $kind, string $name, string $target, callable $handler): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,40}$/', $target) !== 1) {
            throw new \InvalidArgumentException('扩展注入点不合法：' . $target);
        }
        self::$injections[self::key($kind, $name)][$target][] = $handler;
    }

    /** 返回扩展注册的注入处理器。 */
    public static function injectionsFor(string $kind, string $name, string $target): array
    {
        return self::$injections[self::key($kind, $name)][$target] ?? [];
    }

    public static function hasInjection(string $kind, string $name, string $target): bool
    {
        return self::injectionsFor($kind, $name, $target) !== [];
    }

    /** 注册生命周期回调：onActivate/onDeactivate/onUninstall/onUpgrade。 */
    public static function lifecycle(string $kind, string $name, string $phase, callable $handler): void
    {
        if (!in_array($phase, ['onActivate', 'onDeactivate', 'onUninstall', 'onUpgrade'], true)) {
            throw new \InvalidArgumentException('扩展生命周期阶段不合法：' . $phase);
        }
        self::$lifecycle[self::key($kind, $name)][$phase][] = $handler;
    }

    /** 执行生命周期回调；返回 true 表示扩展已接管该阶段。 */
    public static function runLifecycle(string $kind, string $name, string $phase, array $args = [], bool $throwOnError = false): bool
    {
        $handlers = self::$lifecycle[self::key($kind, $name)][$phase] ?? [];
        if ($handlers === []) {
            return false;
        }
        $context = $kind === 'plugin' ? Plugin::context($name) : Theme::context($name);
        foreach ($handlers as $handler) {
            try {
                $handler($context, ...$args);
            } catch (\Throwable $e) {
                if ($throwOnError) {
                    throw new \RuntimeException('[pafish-extension] ' . $kind . ':' . $name . ' ' . $phase . ' 失败：' . $e->getMessage(), 0, $e);
                }
                Logger::error('[pafish-extension] ' . $kind . ':' . $name . ' ' . $phase . ' 失败：' . $e->getMessage());
            }
        }
        return true;
    }

    public static function isBooted(string $kind, string $name): bool
    {
        return isset(self::$booted[self::key($kind, $name)]);
    }

    /** 返回统一入口注册的 handler。 */
    public static function handler(string $kind, string $name, string $handler): ?callable
    {
        return self::$handlers[self::key($kind, $name)][$handler] ?? null;
    }

    /** 注册统一入口提供的旧能力名，供核心服务消费。 */
    public static function provider(string $kind, string $name, string $key, callable $handler): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $key) !== 1) {
            throw new \InvalidArgumentException('扩展能力名不合法：' . $key);
        }
        $extensionKey = self::key($kind, $name);
        self::$providers[$extensionKey][$key] = $handler;

        // 清单负责路由元数据，入口只提供同名实现。
        // API v2 以 manifest 维护路由元数据，provider 只绑定同名 handler；
        // API v1 仍兼容 ctx->route()，但不会再生成第二套路由声明。
        $manifest = $kind === 'plugin' ? Plugin::manifest($name) : Theme::manifest($name);
        foreach (is_array($manifest) ? ExtensionRoutes::fromManifest($manifest, $kind, $name) : [] as $route) {
            if (($route['handler'] ?? '') === $key) {
                self::$handlers[$extensionKey][$key] = $handler;
            }
        }
    }

    public static function providerFor(string $kind, string $name, string $key): ?callable
    {
        return self::$providers[self::key($kind, $name)][$key] ?? null;
    }

    /** 主题切换或插件停用时清理请求内注册状态。 */
    public static function reset(string $kind, string $name): void
    {
        $key = self::key($kind, $name);
        Hooks::removeByTag($key);
        unset(self::$booted[$key], self::$handlers[$key], self::$routes[$key], self::$settings[$key], self::$injections[$key], self::$lifecycle[$key], self::$providers[$key]);
    }

    private static function handlerName(string $kind, string $name): string
    {
        self::$handlerSequence++;
        return 'extension_' . $kind . '_' . substr(sha1($name), 0, 10) . '_' . self::$handlerSequence;
    }

    private static function key(string $kind, string $name): string
    {
        return $kind . ':' . $name;
    }

    /** 运行时设置与 manifest 使用同一套安全字段结构。 */
    private static function normalizeSetting(mixed $field, string $kind): ?array
    {
        if (!is_array($field)
            || !is_string($field['key'] ?? null)
            || preg_match('/^[a-zA-Z][a-zA-Z0-9_.:-]{0,79}$/', $field['key']) !== 1
            || !is_string($field['label'] ?? null)
            || trim($field['label']) === ''
            || !is_string($field['type'] ?? null)
            || !in_array($field['type'], self::SETTING_TYPES, true)
            || ($kind === 'theme' && $field['type'] === 'password')) {
            // 丢弃非法字段是必要的（不能把未校验的 schema 交给渲染层），
            // 但静默丢弃会让开发者「设置项凭空消失」而无从排查——必须留痕。
            self::logRejectedSetting($field, $kind);
            return null;
        }
        $out = [
            'key' => $field['key'],
            'label' => trim($field['label']),
            'type' => $field['type'],
        ];
        if (is_array($field['options'] ?? null)) {
            $out['options'] = [];
            foreach ($field['options'] as $optionKey => $optionLabel) {
                if (is_scalar($optionKey) && is_scalar($optionLabel)) {
                    $out['options'][(string) $optionKey] = (string) $optionLabel;
                }
            }
        }
        foreach (['default', 'placeholder', 'group', 'desc', 'help'] as $key) {
            if (is_scalar($field[$key] ?? null)) {
                $out[$key] = (string) $field[$key];
            }
        }
        // 早期插件清单使用 description 表达字段说明；保留它作为 desc 的兼容别名，
        // 这样旧扩展不会在统一 schema 规范化时静默丢失帮助文案。
        if (!isset($out['desc']) && is_scalar($field['description'] ?? null)) {
            $description = trim((string) $field['description']);
            if ($description !== '') {
                $out['desc'] = $description;
            }
        }
        // nav_icon：设置页左侧导航的分组图标，取自组内首个声明它的字段。
        // 与字段自己的 `icon` 分开：后者是字段装饰（如 group 标题前的 ⚙），
        // 语义不同，混用会让某个 ⚙ 变成整组的导航图标。
        if (is_string($field['nav_icon'] ?? null) && trim($field['nav_icon']) !== '') {
            $out['nav_icon'] = trim($field['nav_icon']);
        }

        // 字段级校验约束（主题作者声明，服务端与前端共用）。
        // 原来只有 color 在保存时硬编码校验，其余类型写什么都收；
        // 现在由 schema 声明，保存时统一执行，前端同步加原生约束。
        $rules = [];
        if (is_int($field['maxlength'] ?? null) && $field['maxlength'] > 0) {
            $rules['maxlength'] = $field['maxlength'];
        }
        if (is_int($field['minlength'] ?? null) && $field['minlength'] > 0) {
            $rules['minlength'] = $field['minlength'];
        }
        if (is_string($field['pattern'] ?? null) && $field['pattern'] !== '') {
            // 只接受能编译的正则，避免把坏 pattern 交给前端导致整个表单静默失效
            if (@preg_match('/' . str_replace('/', '\/', $field['pattern']) . '/u', '') !== false) {
                $rules['pattern'] = $field['pattern'];
            }
        }
        if (is_string($field['pattern_message'] ?? null) && $field['pattern_message'] !== '') {
            $rules['pattern_message'] = $field['pattern_message'];
        }
        if (($field['required'] ?? false) === true) {
            $rules['required'] = true;
        }
        if (is_string($field['section'] ?? null) && trim($field['section']) !== '') {
            $rules['section'] = trim($field['section']);
        }
        if ($rules !== []) {
            $out['rules'] = $rules;
        }

        // ---- 数值型：slider ----
        if ($out['type'] === 'slider') {
            $min = is_numeric($field['min'] ?? null) ? (float) $field['min'] : 0.0;
            $max = is_numeric($field['max'] ?? null) ? (float) $field['max'] : 100.0;
            if ($max <= $min) {
                $max = $min + 1.0;   // 防止声明颠倒导致控件不可用
            }
            $step = is_numeric($field['step'] ?? null) && (float) $field['step'] > 0
                ? (float) $field['step'] : 1.0;
            $out['min'] = $min;
            $out['max'] = $max;
            $out['step'] = $step;
            if (is_string($field['unit'] ?? null)) {
                $out['unit'] = (string) $field['unit'];
            }
        }

        // ---- 复合型：spacing（四向间距）----
        if ($out['type'] === 'spacing') {
            $sides = ['top', 'right', 'bottom', 'left'];
            $declared = is_array($field['sides'] ?? null) ? $field['sides'] : $sides;
            $picked = [];
            foreach ($declared as $side) {
                if (is_string($side) && in_array($side, $sides, true) && !in_array($side, $picked, true)) {
                    $picked[] = $side;
                }
            }
            $out['sides'] = $picked !== [] ? $picked : $sides;
            $out['unit'] = is_string($field['unit'] ?? null) ? (string) $field['unit'] : 'px';
            $out['numeric_limit'] = is_numeric($field['numeric_limit'] ?? null)
                ? (float) $field['numeric_limit'] : 999.0;
        }

        // ---- 复合型：typography（字体组合）----
        if ($out['type'] === 'typography') {
            $allowed = ['family', 'size', 'weight', 'line_height', 'letter_spacing', 'style', 'transform', 'color'];
            $declared = is_array($field['fields'] ?? null) ? $field['fields'] : $allowed;
            $picked = [];
            foreach ($declared as $part) {
                if (is_string($part) && in_array($part, $allowed, true) && !in_array($part, $picked, true)) {
                    $picked[] = $part;
                }
            }
            $out['fields'] = $picked !== [] ? $picked : $allowed;
            $out['font_size_limit'] = is_numeric($field['font_size_limit'] ?? null)
                ? (float) $field['font_size_limit'] : 200.0;
            $out['weight_options'] = is_array($field['weight_options'] ?? null)
                ? $field['weight_options']
                : ['300' => '细', '400' => '常规', '500' => '中等', '600' => '半粗', '700' => '粗'];
        }

        // ---- 复合型：repeater（可重复行）----
        if ($out['type'] === 'repeater') {
            $sub = self::normalizeSubFields($field, $kind, ['heading', 'subheading'], 'repeater');
            if ($sub === []) {
                self::logRejectedSetting($field, $kind, 'repeater 的 fields 为空或子字段全部非法');
                return null;
            }
            $out['fields'] = $sub;
            $out['max_items'] = is_int($field['max_items'] ?? null) && $field['max_items'] > 0
                ? min($field['max_items'], 100) : 20;
            $out['min_items'] = is_int($field['min_items'] ?? null) && $field['min_items'] >= 0
                ? max(0, $field['min_items']) : 0;
            if (is_string($field['add_label'] ?? null) && trim($field['add_label']) !== '') {
                $out['add_label'] = trim($field['add_label']);
            }
            if (is_string($field['title_key'] ?? null) && trim($field['title_key']) !== '') {
                $out['title_key'] = trim($field['title_key']);
            } elseif (isset($out['fields'][0]['key'])) {
                $out['title_key'] = (string) $out['fields'][0]['key'];   // 默认用第一个子字段作行标题
            }
        }

        // ---- 视觉选择型：button_set / image_select / palette ----
        // 三者共用同一份 options 语义（key => 显示内容），只是渲染方式不同：
        // button_set → 分段按钮，image_select → 图片缩略图，palette → 色块。
        if (in_array($out['type'], ['button_set', 'image_select', 'palette'], true)) {
            if (!is_array($field['options'] ?? null) || $field['options'] === []) {
                self::logRejectedSetting($field, $kind, $out['type'] . ' 的 options 为空');
                return null;
            }
            $out['options'] = [];
            foreach ($field['options'] as $ok => $ol) {
                if (is_scalar($ok) && is_scalar($ol)) {
                    $out['options'][(string) $ok] = (string) $ol;
                }
            }
            if ($out['options'] === []) {
                self::logRejectedSetting($field, $kind, $out['type'] . ' 的 options 无有效项');
                return null;
            }
            // multiple 只对 image_select 有意义：多选布局（如"首页显示哪些区块"）
            $out['multiple'] = $out['type'] === 'image_select' && ($field['multiple'] ?? false) === true;
            // inline：选项是否横向排布
            $out['inline'] = ($field['inline'] ?? ($out['type'] === 'button_set')) === true;
            if (is_numeric($field['preview_width'] ?? null)) {
                $out['preview_width'] = (int) $field['preview_width'];
            }
            if (is_numeric($field['preview_height'] ?? null)) {
                $out['preview_height'] = (int) $field['preview_height'];
            }
        }

        // ---- 复合视觉型：background / border ----
        // 存 JSON；子键白名单化，保存时拒绝未声明的键。
        if (isset(self::COMPOSITE_KEYS[$out['type']])) {
            $allowed = self::COMPOSITE_KEYS[$out['type']];
            $declared = is_array($field['fields'] ?? null) ? $field['fields'] : $allowed;
            $picked = [];
            foreach ($declared as $part) {
                if (is_string($part) && in_array($part, $allowed, true) && !in_array($part, $picked, true)) {
                    $picked[] = $part;
                }
            }
            $out['fields'] = $picked !== [] ? $picked : $allowed;
            // 下拉选项：让"平铺/重复/位置"这类值用选的而不是手填
            $out['choices'] = self::compositeChoices($out['type']);
        }

        // ---- 代码型：code ----
        if ($out['type'] === 'code') {
            $out['language'] = is_string($field['language'] ?? null) && trim($field['language']) !== ''
                ? trim($field['language']) : 'css';
            $out['rows'] = is_int($field['rows'] ?? null) && $field['rows'] > 0
                ? min($field['rows'], 40) : 8;
        }

        // ---- 容器型：group（可折叠区块）----
        if ($out['type'] === 'group') {
            // group 内允许 heading/subheading 做小节标题（它们是排版，不产生值）
            $sub = self::normalizeSubFields($field, $kind, ['heading', 'subheading'], 'group', true);
            if ($sub === []) {
                self::logRejectedSetting($field, $kind, 'group 的 fields 为空或子字段全部非法');
                return null;
            }
            $out['fields'] = $sub;
            // 默认展开：折叠区块默认收起的最大问题是"用户以为设置没了"
            $out['collapsed'] = ($field['collapsed'] ?? false) === true;
            if (is_string($field['icon'] ?? null) && trim($field['icon']) !== '') {
                $out['icon'] = trim($field['icon']);
            }
        }

        $showIf = self::normalizeShowIf($field['show_if'] ?? null);
        if ($showIf !== null) {
            $out['show_if'] = $showIf;
        }
        return $out;
    }

    /**
     * 规范化 show_if —— 单条件与**多条件组合**。
     *
     * 单条件（原有写法，保持兼容）：
     *   "show_if": { "key": "dark_mode", "value": "1", "op": "eq" }
     *
     * 多条件，两种写法都接受：
     *
     *   1) 显式数组 + 关系词（推荐，可读性最好）
     *      "show_if": { "relation": "or", "rules": [
     *          { "key": "layout", "value": "grid" },
     *          { "key": "layout", "value": "masonry" }
     *      ]}
     *
     *   2) 竖线简写（对齐参考实现的 `keys|ops|values` 形式）
     *      "show_if": { "key": "a|b", "op": "eq|ne", "value": "1|2" }
     *      —— 三段各自按 `|` 切开后**逐位对应**；长度不齐的位按缺省补齐。
     *
     * 返回值统一为 ['relation' => 'and'|'or', 'rules' => [ {key,value,op,values}, … ]]，
     * 前端只认这一种结构，避免两个设置页再次分叉。
     */
    private static function normalizeShowIf(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        // ---- 形式 1：显式 rules 数组 ----
        if (is_array($raw['rules'] ?? null)) {
            $rules = [];
            foreach ($raw['rules'] as $one) {
                $rule = self::normalizeShowIfRule($one);
                if ($rule !== null) {
                    $rules[] = $rule;
                }
            }
            if ($rules === []) {
                return null;
            }
            $relation = strtolower((string) ($raw['relation'] ?? $raw['logic'] ?? 'and'));
            return [
                'relation' => $relation === 'or' ? 'or' : 'and',
                'rules' => $rules,
            ];
        }

        // ---- 形式 2：竖线简写（仅当三段里真的出现了 `|`）----
        $key = $raw['key'] ?? null;
        if (is_string($key) && str_contains($key, '|')) {
            $keys = array_map('trim', explode('|', $key));
            $ops = array_map('trim', explode('|', (string) ($raw['op'] ?? 'eq')));
            $values = array_map('trim', explode('|', (string) ($raw['value'] ?? '')));
            $rules = [];
            foreach ($keys as $i => $k) {
                if ($k === '') {
                    continue;
                }
                $rule = self::normalizeShowIfRule([
                    'key' => $k,
                    'op' => $ops[$i] ?? ($ops[0] ?? 'eq'),
                    'value' => $values[$i] ?? ($values[0] ?? ''),
                ]);
                if ($rule !== null) {
                    $rules[] = $rule;
                }
            }
            if ($rules === []) {
                return null;
            }
            $relation = strtolower((string) ($raw['relation'] ?? $raw['logic'] ?? 'and'));
            return [
                'relation' => $relation === 'or' ? 'or' : 'and',
                'rules' => $rules,
            ];
        }

        // ---- 形式 3：单条件（原有写法）----
        $rule = self::normalizeShowIfRule($raw);
        if ($rule === null) {
            return null;
        }
        return ['relation' => 'and', 'rules' => [$rule]];
    }

    /** 规范化单条 show_if 规则；key/value 必须都是字符串（沿用原有严格性） */
    private static function normalizeShowIfRule(mixed $one): ?array
    {
        if (!is_array($one) || !is_string($one['key'] ?? null) || !is_string($one['value'] ?? null)) {
            return null;
        }
        $op = $one['op'] ?? 'eq';
        return [
            'key' => $one['key'],
            'value' => $one['value'],
            'op' => in_array($op, ['eq', 'ne', 'in', 'notin', 'truthy'], true) ? (string) $op : 'eq',
            // op=in / notin 时用 values 数组
            'values' => is_array($one['values'] ?? null)
                ? array_values(array_filter(array_map(
                    static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                    $one['values']
                ), static fn (string $v): bool => $v !== ''))
                : [],
        ];
    }

    /**
     * 复合视觉型各子项的可选值。
     *
     * 这些值的取值域是固定的（CSS 关键字），做成下拉让站长选，
     * 而不是让他手填 `no-repeat` / `center center` 这种记不住的串。
     *
     * @return array<string, array<string, string>>
     */
    private static function compositeChoices(string $type): array
    {
        if ($type === 'background') {
            return [
                'repeat' => [
                    '' => '默认', 'no-repeat' => '不重复', 'repeat' => '平铺',
                    'repeat-x' => '横向平铺', 'repeat-y' => '纵向平铺',
                ],
                'position' => [
                    '' => '默认', 'left top' => '左上', 'center top' => '上中', 'right top' => '右上',
                    'left center' => '左中', 'center center' => '居中', 'right center' => '右中',
                    'left bottom' => '左下', 'center bottom' => '下中', 'right bottom' => '右下',
                ],
                'size' => [
                    '' => '默认', 'auto' => '原始尺寸', 'cover' => '覆盖', 'contain' => '完整显示',
                ],
                'attachment' => [
                    '' => '默认', 'scroll' => '随页面滚动', 'fixed' => '固定',
                ],
            ];
        }
        if ($type === 'border') {
            return [
                'style' => [
                    '' => '默认', 'none' => '无', 'solid' => '实线', 'dashed' => '虚线',
                    'dotted' => '点线', 'double' => '双线',
                ],
            ];
        }
        return [];
    }

    /**
     * 规范化容器 / repeater 的子字段。
     *
     * 子字段复用顶层完全相同的规则（类型白名单、key 格式、校验规则），
     * 这样行内 `required` / `maxlength` / `pattern` 才会真正生效。
     *
     * @param array<string, mixed> $parent        父字段定义
     * @param string[]             $dropTypes     额外要丢弃的类型（如 repeater 不要纯标题）
     * @param bool                 $allowCompound 是否放行单值复合型作子字段（group 放行，repeater 不放行）
     * @return array<int, array<string, mixed>> 规范化后的子字段列表
     */
    private static function normalizeSubFields(array $parent, string $kind, array $dropTypes, string $for, bool $allowCompound = false): array
    {
        $sub = [];
        foreach ((array) ($parent['fields'] ?? []) as $subField) {
            $normalized = self::normalizeSetting($subField, $kind);
            if ($normalized === null) {
                continue;
            }
            // 容器内不放行嵌套容器：否则折叠区块会层层套娃，既难渲染也难解释
            $allowed = in_array($normalized['type'], self::LEAF_TYPES, true)
                || in_array($normalized['type'], $dropTypes, true)
                || ($allowCompound && in_array($normalized['type'], self::COMPOUND_INLINE_TYPES, true));
            if (!$allowed) {
                // 被丢掉就必须留下痕迹，否则主题作者只会看到字段凭空消失
                $hint = in_array($normalized['type'], self::CONTAINER_TYPES, true)
                    ? '（不允许嵌套容器）'
                    : '（不允许在 ' . $for . ' 的子字段里使用）';
                self::logRejectedSetting($subField, $kind,
                    $for . ' 的子字段不支持的「' . $normalized['type'] . '」类型' . $hint);
                continue;
            }
            $sub[(string) $normalized['key']] = $normalized;
        }
        return array_values($sub);
    }

    /**
     * 公开入口：规范化单个设置字段。
     *
     * 供主题/插件作者自测 schema（也可用于外部工具校验 theme.json / plugin.json）。
     *
     * @return array<string, mixed>|null null 表示该字段结构非法、已被丢弃
     */
    public static function describeSetting(mixed $field, string $kind = 'theme'): ?array
    {
        return self::normalizeSetting($field, $kind);
    }

    /** 是否为展示型字段（heading / subheading）：不产生设置值。 */
    public static function isDecorativeType(string $type): bool
    {
        return in_array($type, self::DECORATIVE_TYPES, true);
    }

    /** 是否为容器型字段（group）：自身无值，子字段才是设置项。 */
    public static function isContainerType(string $type): bool
    {
        return in_array($type, self::CONTAINER_TYPES, true);
    }

    /**
     * 展平容器字段，返回其中全部真实设置项。
     *
     * `group` 自身不存值，因此保存与导出必须下钻到子字段；
     * 否则容器里的设置会被整块忽略。
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    public static function flattenFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $type = (string) ($field['type'] ?? '');
            if (self::isContainerType($type) && is_array($field['fields'] ?? null)) {
                foreach (self::flattenFields($field['fields']) as $inner) {
                    $out[] = $inner;
                }
                continue;
            }
            if (!self::isDecorativeType($type)) {
                $out[] = $field;
            }
        }
        return $out;
    }

    /** 全部可用字段类型（供写作工具与文档使用）。 */
    public static function settingTypes(): array
    {
        return self::SETTING_TYPES;
    }

    /**
     * 按 schema 声明的约束校验一个设置值。
     *
     * 与后台设置页共用同一套语义；主题与插件都走这里，避免各自实现漂移。
     *
     * @param array<string, mixed> $field 规范化后的字段定义
     * @return string|null 错误文案；null 表示通过
     */
    public static function validateSettingValue(array $field, string $value): ?string
    {
        $label = (string) ($field['label'] ?? $field['key'] ?? '');
        $type = (string) ($field['type'] ?? 'text');
        $rules = is_array($field['rules'] ?? null) ? $field['rules'] : [];

        if ($type === 'color' && $value !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
            return $label . ' 需为 #rrggbb 格式的颜色值';
        }

        if (($rules['required'] ?? false) === true && trim($value) === '') {
            return $label . ' 为必填项';
        }

        // 长度按字符计（中文按 1 个字符），与前端 maxlength 语义一致
        if (isset($rules['maxlength']) && mb_strlen($value) > (int) $rules['maxlength']) {
            return $label . ' 不能超过 ' . (int) $rules['maxlength'] . ' 个字符（当前 '
                . mb_strlen($value) . '）';
        }
        if (isset($rules['minlength']) && $value !== '' && mb_strlen($value) < (int) $rules['minlength']) {
            return $label . ' 至少需要 ' . (int) $rules['minlength'] . ' 个字符';
        }
        if (isset($rules['pattern']) && $value !== '') {
            if (@preg_match('/' . str_replace('/', '\/', (string) $rules['pattern']) . '/u', $value) !== 1) {
                return $label . ' ' . (string) ($rules['pattern_message']
                    ?? '格式不正确（需匹配 ' . $rules['pattern'] . '）');
            }
        }

        // 选项类字段：拒绝不在 options 里的值，避免手改表单写入脏数据
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        if ($options !== [] && in_array($type, ['select', 'radio'], true) && $value !== ''
            && !array_key_exists($value, $options)) {
            return $label . ' 的取值不在候选项中';
        }

        // ---- 数值型 ----
        if ($type === 'slider' && $value !== '') {
            if (!is_numeric($value)) {
                return $label . ' 必须是数字';
            }
            $num = (float) $value;
            $min = (float) ($field['min'] ?? 0);
            $max = (float) ($field['max'] ?? 100);
            if ($num < $min || $num > $max) {
                return $label . ' 需在 ' . self::fmtNum($min) . ' ~ ' . self::fmtNum($max) . ' 之间';
            }
        }

        // ---- 复合型：值必须是合法 JSON 且结构正确 ----
        if (in_array($type, self::JSON_TYPES, true)) {
            return self::validateComposite($field, $value, $label);
        }

        return null;
    }

    /** 数字去掉多余小数尾巴（60.0 → 60）。 */
    private static function fmtNum(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }

    /**
     * 复合类型（spacing / typography / repeater）的结构校验。
     *
     * 它们以 JSON 字符串存库，因此必须防住：非法 JSON、类型不符、
     * 未声明的键、以及数值越界——否则脏数据会直接进模板输出。
     */
    private static function validateComposite(array $field, string $value, string $label): ?string
    {
        $type = (string) $field['type'];

        if (trim($value) === '') {
            return null;   // 空值交给 required 规则判定
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return $label . ' 的数据格式不正确（需为 JSON）';
        }

        if ($type === 'spacing') {
            $limit = (float) ($field['numeric_limit'] ?? 999);
            foreach ((array) ($field['sides'] ?? []) as $side) {
                if (!array_key_exists($side, $decoded)) {
                    continue;
                }
                $v = $decoded[$side];
                if ($v === '' || $v === null) {
                    continue;
                }
                if (!is_numeric($v)) {
                    return $label . ' 的「' . $side . '」必须是数字或留空';
                }
                if (abs((float) $v) > $limit) {
                    return $label . ' 的「' . $side . '」超出允许范围（±' . self::fmtNum($limit) . '）';
                }
            }
            return null;
        }

        if ($type === 'typography') {
            $allowed = (array) ($field['fields'] ?? []);
            $sizeLimit = (float) ($field['font_size_limit'] ?? 200);
            foreach ($decoded as $part => $v) {
                if (!in_array($part, $allowed, true)) {
                    return $label . ' 含未声明的属性「' . (string) $part . '」';
                }
                if ($v === '' || $v === null) {
                    continue;
                }
                if ($part === 'size' && (!is_numeric($v) || (float) $v < 0 || (float) $v > $sizeLimit)) {
                    return $label . ' 的字号需为 0 ~ ' . self::fmtNum($sizeLimit) . ' 之间的数字';
                }
                if ($part === 'weight' && !array_key_exists((string) $v, (array) ($field['weight_options'] ?? []))) {
                    return $label . ' 的字重取值不在候选中';
                }
                if ($part === 'color' && !is_string($v)) {
                    return $label . ' 的颜色取值不合法';
                }
                if ($part === 'line_height' && (!is_numeric($v) || (float) $v <= 0)) {
                    return $label . ' 的行高需为正数';
                }
            }
            return null;
        }

        if ($type === 'background' || $type === 'border') {
            $allowed = (array) ($field['fields'] ?? []);
            $choices = (array) ($field['choices'] ?? []);
            foreach ($decoded as $part => $v) {
                if (!in_array($part, $allowed, true)) {
                    return $label . ' 含未声明的属性「' . (string) $part . '」';
                }
                if ($v === '' || $v === null) {
                    continue;
                }
                if (!is_scalar($v)) {
                    return $label . ' 的「' . (string) $part . '」取值不合法';
                }
                $v = (string) $v;
                // 固定取值域的子项必须是候选项之一：手填的拼写错误在这里被挡住
                if (isset($choices[$part]) && !array_key_exists($v, (array) $choices[$part])) {
                    return $label . ' 的「' . (string) $part . '」取值不在候选中';
                }
                if ($type === 'border' && $part === 'width' && (!is_numeric($v) || (float) $v < 0 || (float) $v > 50)) {
                    return $label . ' 的边框宽度需为 0 ~ 50 之间的数字';
                }
                if ($type === 'border' && $part === 'color'
                    && preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) !== 1) {
                    return $label . ' 的边框颜色需为 #rgb / #rrggbb 形式';
                }
            }
            return null;
        }

        // repeater：数组，逐行逐字段按子 schema 校验
        if (!array_is_list($decoded)) {
            return $label . ' 的数据格式不正确（需为数组）';
        }
        $maxItems = (int) ($field['max_items'] ?? 20);
        $minItems = (int) ($field['min_items'] ?? 0);
        if (count($decoded) > $maxItems) {
            return $label . ' 最多 ' . $maxItems . ' 行（当前 ' . count($decoded) . '）';
        }
        if (count($decoded) < $minItems) {
            return $label . ' 至少需要 ' . $minItems . ' 行';
        }
        $subFields = [];
        foreach ((array) ($field['fields'] ?? []) as $sub) {
            if (is_array($sub) && isset($sub['key'])) {
                $subFields[(string) $sub['key']] = $sub;
            }
        }
        foreach ($decoded as $i => $row) {
            if (!is_array($row)) {
                return $label . ' 第 ' . ($i + 1) . ' 行格式不正确';
            }
            foreach ($row as $k => $v) {
                if (!isset($subFields[(string) $k])) {
                    return $label . ' 第 ' . ($i + 1) . ' 行含未声明的字段「' . (string) $k . '」';
                }
                $subErr = self::validateSettingValue($subFields[(string) $k], is_scalar($v) ? (string) $v : '');
                if ($subErr !== null) {
                    return '第 ' . ($i + 1) . ' 行：' . $subErr;
                }
            }
        }
        return null;
    }

    /**
     * 记录被拒绝的设置字段。
     *
     * 原来这里是纯 `return null`：主题作者把 `type` 写成 `"textfield"`、
     * 或把 `key` 写成 `"1abc"`，设置项会从后台凭空消失，日志里一行都没有，
     * 只能读源码排查。这里给出具体原因，让问题在日志里可见。
     */
    private static function logRejectedSetting(mixed $field, string $kind, ?string $override = null): void
    {
        $reason = $override ?? '结构不合法';
        if ($override === null) {
            if (!is_array($field)) {
                $reason = '字段不是对象';
            } else {
                $key = $field['key'] ?? null;
                if (!is_string($key) || preg_match('/^[a-zA-Z][a-zA-Z0-9_.:-]{0,79}$/', $key) !== 1) {
                    $reason = 'key 缺失或格式非法（需以字母开头，仅含字母/数字/_.:-，≤80 字符）：'
                        . (is_scalar($key) ? (string) $key : gettype($key));
                } elseif (!is_string($field['label'] ?? null) || trim((string) $field['label']) === '') {
                    $reason = 'label 缺失或为空';
                } elseif (!is_string($field['type'] ?? null)) {
                    $reason = 'type 缺失或不是字符串';
                } elseif (!in_array($field['type'], self::SETTING_TYPES, true)) {
                    $reason = 'type 不受支持：「' . $field['type'] . '」，可用值：'
                        . implode('、', self::SETTING_TYPES);
                } elseif ($kind === 'theme' && $field['type'] === 'password') {
                    $reason = '主题设置不支持 password 类型（密码字段仅限插件）';
                }
            }
        }
        \Pafish\Core\Logger::error(
            '[pafish-extension] 已忽略一个无效的 ' . $kind . ' 设置项：' . $reason
        );
    }
}
