<?php
/** 按字段 group 值构建设置页导航和内容分组。 */
return static function (array $fields): array {
    // 使用组内首个 nav_icon 作为分组图标。
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
