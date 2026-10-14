<?php
/**
 * 分组构建器：把顶层字段按 `group` 声明组织成**两级**导航结构。
 *
 * 两级导航的 section 模型：
 *   sections 是一张扁平表，每项可带 `parent` 指向父级；
 *   有 parent 的挂到父级 subs 下，没有 parent 的成为一级；
 *   内容区按 "父/子" 作为 section id。
 *
 * pafish 没有独立的 section 声明，分组是靠字段的 `group` 推导出来的，
 * 所以用**路径写法**表达层级：
 *
 *   "group": "内容"              → 一级：内容
 *   "group": "外观设置/颜色"      → 二级：外观设置 › 颜色
 *
 * 这样保持向后兼容（老主题写的一级分组照常工作），
 * 也不用在每个字段上重复声明父子关系。
 *
 * 一条规则要说明：某父级**既有直属字段又有子分组**时，
 * 直属字段会归入名为「常规」的子项，而不是被丢掉——
 * 常见做法是父级当纯容器（自身字段不渲染），但那需要显式声明容器；
 * 我们这里是推导出来的，静默丢字段会更糟。
 *
 * 用法：
 *   $tree = require __DIR__ . '/partials/schema-groups.php';
 *   $built = $tree($fields);
 *   // $built['tabs']   → 导航用（一级数组，每项可能有 children）
 *   // $built['panes']  → 内容区用（叶子面板，含 fields）
 *   // $built['hasNav'] → 是否需要显示导航
 */
return static function (array $fields): array {
    // 分组图标：从组内**第一个声明了 nav_icon 的字段**推导。
    //
    // 为什么用独立的 `nav_icon` 而不是复用字段的 `icon`：
    //   `icon` 在字段上的语义是"这个字段自己的装饰图标"（如 group 折叠区块标题前的 ⚙），
    //   它与导航无关。若拿 `icon` 当导航图标，`group` 内那个 ⚙ 会变成整组的导航图标，
    //   而作者从没这么声明过——是**猜错语义**，不是少写一个字段。
    //
    // 若分组有独立的声明表，图标就能写在分组级；
    // pafish 的分组从字段推导、没有分组声明，所以把 nav_icon 写在组内任意字段上，
    // 取该组第一个声明的值。只在组内查，避免"某组的图标来自另一个组"。
    $iconOf = static function (array $groupFields): string {
        foreach ($groupFields as $f) {
            if (is_array($f) && is_string($f['nav_icon'] ?? null) && trim($f['nav_icon']) !== '') {
                return trim($f['nav_icon']);
            }
        }
        return '';
    };

    // 1) 解析路径 → [一级名, 二级名|null]
    $parsed = [];
    foreach ($fields as $field) {
        if (!is_array($field) || !isset($field['key'])) {
            continue;
        }
        $raw = (string) ($field['group'] ?? '');
        $raw = trim($raw) !== '' ? trim($raw) : '常规';
        // 只切一层：'a/b/c' → ['a', 'b/c']，保持无损
        $parts = explode('/', $raw, 2);
        $parent = trim($parts[0]) !== '' ? trim($parts[0]) : '常规';
        $child = isset($parts[1]) ? trim($parts[1]) : '';
        $parsed[] = [$parent, $child !== '' ? $child : null, $field];
    }

    // 2) 按首次出现顺序收集：一级 → 二级（'' 表示直属字段）
    $order = [];          // 一级顺序
    $bucket = [];         // parent => [ childKey => [fields] ]，childKey '' = 直属
    foreach ($parsed as [$parent, $child, $field]) {
        if (!isset($bucket[$parent])) {
            $bucket[$parent] = [];
            $order[] = $parent;
        }
        $ck = $child ?? '';
        if (!isset($bucket[$parent][$ck])) {
            $bucket[$parent][$ck] = [];
        }
        $bucket[$parent][$ck][] = $field;
    }

    // 3) 组装：有子分组的一级才展开成两级
    $tabs = [];
    $panes = [];
    foreach ($order as $parent) {
        $children = $bucket[$parent];
        $hasSub = count(array_filter(array_keys($children), static fn ($k) => $k !== '')) > 0;

        if (!$hasSub) {
            // 没有子分组：一级自身就是叶子
            $own = $children[''] ?? [];
            $tabs[] = ['name' => $parent, 'label' => $parent, 'icon' => $iconOf($own), 'children' => []];
            $panes[] = ['name' => $parent, 'label' => $parent, 'icon' => $iconOf($own), 'fields' => $own];
            continue;
        }

        $kids = [];
        // 直属字段归入「常规」子项（放在最前，保持"先写的先显示"）
        if (isset($children['']) && $children[''] !== []) {
            $name = $parent . '/常规';
            $kids[] = ['name' => $name, 'label' => '常规', 'icon' => $iconOf($children['']), 'fields' => $children['']];
            $panes[] = ['name' => $name, 'label' => '常规', 'icon' => $iconOf($children['']), 'fields' => $children['']];
        }
        foreach ($children as $ck => $groupFields) {
            if ($ck === '') {
                continue;
            }
            $name = $parent . '/' . $ck;
            $kids[] = ['name' => $name, 'label' => $ck, 'icon' => $iconOf($groupFields), 'fields' => $groupFields];
            $panes[] = ['name' => $name, 'label' => $ck, 'icon' => $iconOf($groupFields), 'fields' => $groupFields];
        }
        // 父级是纯容器、自身不渲染字段，所以它的图标取自名下的子分组
        $parentIcon = '';
        foreach ($kids as $kid) {
            if (($kid['icon'] ?? '') !== '') { $parentIcon = (string) $kid['icon']; break; }
        }
        $tabs[] = ['name' => $parent, 'label' => $parent, 'icon' => $parentIcon, 'children' => $kids];
    }

    // 导航栏只在"分组确实不止一个去处"时才有意义
    $leafCount = count($panes);
    $hasNav = $leafCount > 1 || count($tabs) > 1;

    return [
        'tabs' => $tabs,
        'panes' => $panes,
        'hasNav' => $hasNav,
        'leafCount' => $leafCount,
    ];
};
