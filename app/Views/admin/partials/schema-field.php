<?php
/**
 * 共享字段渲染器：主题设置页与插件设置页共用同一份实现。
 *
 * 这两个页面原本各写一份类型分支（主题 13 种 / 插件 9 种），已经分叉：
 * 插件页不支持 desc、section、字段级校验与折叠容器，插件作者写的新类型
 * 会被静默丢弃。规则与渲染都必须只有一份。
 *
 * 用法：
 *   $renderField = require __DIR__ . '/partials/schema-field.php';
 *   $renderField($field, $values, $lastSection);
 *
 * @return Closure(array<string,mixed>, array<string,mixed>, ?string, bool): void
 */
$renderField = null;
$renderField = function (array $field, array $values, ?string &$lastSection, bool $nested = false) use (&$renderField): void {
    $key = (string) $field['key'];
    $label = (string) $field['label'];
    $type = (string) ($field['type'] ?? 'text');
    $value = array_key_exists($key, $values) ? (string) $values[$key] : (string) ($field['default'] ?? '');
    // 密钥类字段只显示配置状态，不把旧密钥送回浏览器。
    $displayValue = $type === 'password' ? '' : $value;
    $placeholder = is_string($field['placeholder'] ?? null) ? $field['placeholder'] : '';
    $desc = is_string($field['desc'] ?? null) ? $field['desc'] : '';
    // help：常驻说明之外的补充细节，默认收起成 ? 气泡，避免长表单被文字淹没
    $help = is_string($field['help'] ?? null) ? $field['help'] : '';
    $options = is_array($field['options'] ?? null) ? $field['options'] : [];
    $showIf = is_array($field['show_if'] ?? null) ? $field['show_if'] : null;
    $rules = is_array($field['rules'] ?? null) ? $field['rules'] : [];
    $section = is_string($rules['section'] ?? null) ? $rules['section'] : null;

    // 原生约束：让浏览器先行拦截，服务端仍会二次校验
    $native = '';
    if (isset($rules['maxlength'])) { $native .= ' maxlength="' . (int) $rules['maxlength'] . '"'; }
    if (isset($rules['minlength'])) { $native .= ' minlength="' . (int) $rules['minlength'] . '"'; }
    if (isset($rules['pattern'])) { $native .= ' pattern="' . e((string) $rules['pattern']) . '"'; }
    if (($rules['required'] ?? false) === true) { $native .= ' required'; }

    if ($section !== null && $section !== $lastSection) {
        $lastSection = $section;
        echo '<h3 class="admin-sf-section">' . e($section) . '</h3>';
    }

    // show_if：新结构统一走 data-show-if（relation + rules 的 JSON）。
    // 前端只认这一种；旧的 data-show-if-key/-value/-op/-values 仍照旧输出，
    // 万一有第三方脚本按老属性选择，不至于当场失效。
    $showIfAttr = '';
    if ($showIf !== null) {
        $ruleList = is_array($showIf['rules'] ?? null) ? $showIf['rules'] : [];
        $first = $ruleList[0] ?? null;
        $showIfAttr = ' data-show-if="'
            . e(json_encode([
                'relation' => (string) ($showIf['relation'] ?? 'and'),
                'rules' => $ruleList,
            ], JSON_UNESCAPED_UNICODE))
            . '"';
        if (is_array($first)) {
            $showIfAttr .= ' data-show-if-key="' . e((string) ($first['key'] ?? '')) . '"'
                . ' data-show-if-value="' . e((string) ($first['value'] ?? '')) . '"'
                . ' data-show-if-op="' . e((string) ($first['op'] ?? 'eq')) . '"'
                . ' data-show-if-values="' . e(json_encode($first['values'] ?? [], JSON_UNESCAPED_UNICODE)) . '"';
        }
    }

    // 展示型字段：不产生设置值，也不参与提交
    if ($type === 'heading' || $type === 'subheading') {
        $tag = $type === 'heading' ? 'h3' : 'h4';
        echo '<div class="admin-sf-deco admin-sf-deco-' . e($type) . '"' . $showIfAttr . '>';
        echo '<' . $tag . ' class="admin-sf-deco-title">' . e($label) . '</' . $tag . '>';
        if ($desc !== '') { echo '<p class="admin-sf-desc">' . e($desc) . '</p>'; }
        echo '</div>';
        return;
    }

    // 布局分两类：
    //  - 行式（admin-sf-row）：标签在左列、控件在右列。表单更短、右侧不再大片留白，
    //    也是 emlog / WordPress 设置页的通行形态。
    //  - 整块（admin-sf-block）：group / repeater / backup 自身是复合区块，占满整行。
    $isBlock = in_array($type, ['group', 'repeater', 'backup'], true);
    $wrapClass = 'admin-sf-field'
        . ($nested ? ' admin-sf-field-nested' : '')
        . ($isBlock ? ' admin-sf-block' : ' admin-sf-row');
    echo '<div class="' . $wrapClass . '" data-sf-field-type="' . e($type) . '"' . $showIfAttr . '>';

    $requiredMark = ($rules['required'] ?? false) === true
        ? '<span class="admin-sf-required" title="必填">*</span>'
        : '';

    // 一组控件（单选组、可重复行、四向间距、字体组合、按钮组、复合视觉型）
    // 没有唯一的"主控件"，用 <label for> 只能指向一个不存在的 id ——
    // 点标签不会有任何反应。这类改用 <span>，样式一致，但不再谎称有关联。
    // backup 同理：它只有一排动作按钮，没有输入控件可关联。
    $isGroupControl = in_array($type, [
        'radio', 'checkboxes', 'spacing', 'typography', 'repeater',
        'button_set', 'image_select', 'palette', 'background', 'border', 'backup',
    ], true);
    $labelHtml = $isGroupControl
        ? '<span class="admin-sf-label">' . e($label) . $requiredMark . '</span>'
        : '<label class="admin-sf-label" for="sf-' . e($key) . '">' . e($label) . $requiredMark . '</label>';

    // 帮助提示：挂在**标签末尾的 ? 图标**上。
    // 触发方式是 hover（或键盘聚焦），浮层由 admin-schema-form.js 统一挂到 body 上定位——
    // 不放在标签内部，是因为字段行 / 折叠块常有 overflow，内嵌浮层会被裁掉。
    // 文案同时写进 title 与 aria-label，JS 不可用时浏览器原生提示仍能兜底。
    $helpText = $help !== '' ? e($help) : '';
    if ($help === '' && $desc !== '') {
        // 没写 help 但有 desc 时，让 ? 图标显示 desc 的完整内容：
        // desc 会被截断显示，站长想知道全文时有个入口。
        $helpText = e($desc);
    }
    $tip = $helpText !== ''
        ? '<span class="admin-sf-hint" data-sf-tip="' . $helpText . '"'
            . ' tabindex="0" role="note" aria-label="说明：' . $helpText . '"'
            . ' title="' . $helpText . '">?</span>'
        : '';

    // 提示图标插在标签文字之后（闭标签之前）
    if ($tip !== '') {
        $labelHtml = str_replace(
            $isGroupControl ? '</span>' : '</label>',
            $tip . ($isGroupControl ? '</span>' : '</label>'),
            $labelHtml
        );
    }

    if (!$isBlock) {
        // 所有非整块字段都把标签放左列——包括 checkbox / switcher。
        // 两列布局里若这两种留空左列，会露出一大块空白，看起来像渲染坏了。
        echo $labelHtml;
        // 右列包一层：颜色 / 滑块 / 间距 / 图片都是多个同级控件
        echo '<div class="admin-sf-control">';
    } else {
        // 整块类型：标签在上；group 的标题由折叠头自己渲染，不再重复
        if ($type !== 'group') {
            echo $labelHtml;
        }
        if ($desc !== '' && $type !== 'group') {
            echo '<p class="admin-sf-desc">' . e($desc) . '</p>';
        }
    }

    switch ($type) {
        case 'text':
            echo '<input type="text" class="input" id="sf-' . e($key) . '" data-sf-key="' . e($key) . '" data-sf-type="text"'
                . ' value="' . e($value) . '"' . ($placeholder !== '' ? ' placeholder="' . e($placeholder) . '"' : '')
                . $native . ' autocomplete="off">';
            break;

        case 'textarea':
            echo '<textarea class="input admin-sf-textarea" id="sf-' . e($key) . '" rows="4" data-sf-key="' . e($key)
                . '" data-sf-type="textarea" placeholder="' . e($placeholder) . '"' . $native . '>' . e($value) . '</textarea>';
            break;

        case 'password':
            // 仅插件可用（主题侧 normalizeSetting 会拒绝该类型）
            echo '<input type="password" class="input" id="sf-' . e($key) . '" data-sf-key="' . e($key)
                . '" data-sf-type="password" value="' . e($displayValue) . '"'
                . ($placeholder !== '' ? ' placeholder="' . e($placeholder) . '"' : '')
                . $native . ' autocomplete="new-password">'
                . ($value !== '' ? '<p class="admin-field-hint">已配置，留空则保持不变</p>' : '');
            break;

        case 'checkbox':
            // 标签在左列，这里只放勾选框；id 让左侧 <label for> 能关联并点击切换
            echo '<label class="admin-sf-check"><input type="checkbox" id="sf-' . e($key) . '" data-sf-key="' . e($key)
                . '" data-sf-type="checkbox"' . ($value === '1' ? ' checked' : '') . '></label>';
            break;

        case 'checkboxes':
            $picked = json_decode($value !== '' ? $value : '[]', true);
            $picked = is_array($picked) ? array_map('strval', $picked) : [];
            echo '<div class="admin-sf-checks' . (!empty($field['inline']) ? ' is-inline' : '') . '">';
            foreach ($options as $optValue => $optLabel) {
                $on = in_array((string) $optValue, $picked, true);
                echo '<label class="admin-sf-check"><input type="checkbox" data-sf-key="' . e($key)
                    . '" data-sf-type="checkboxes" data-sf-value="' . e((string) $optValue) . '"'
                    . ($on ? ' checked' : '') . '><span>' . e((string) $optLabel) . '</span></label>';
            }
            echo '<input type="hidden" data-sf-key="' . e($key) . '" data-sf-type="checkboxes-value" value="'
                . e(json_encode($picked, JSON_UNESCAPED_UNICODE)) . '">';
            echo '</div>';
            break;

        case 'switcher':
            // 标签在左列；id 让左侧 <label for> 能关联并点击切换
            echo '<label class="admin-sf-switch"><input type="checkbox" id="sf-' . e($key)
                . '" class="admin-sf-switch-input" data-sf-key="'
                . e($key) . '" data-sf-type="switcher"' . ($value === '1' ? ' checked' : '')
                . '><span class="admin-sf-switch-track"></span></label>';
            break;

        case 'select':
            echo '<select class="input" id="sf-' . e($key) . '" data-sf-key="' . e($key) . '" data-sf-type="select">';
            foreach ($options as $optValue => $optLabel) {
                echo '<option value="' . e((string) $optValue) . '"'
                    . ((string) $optValue === $value ? ' selected' : '') . '>' . e((string) $optLabel) . '</option>';
            }
            echo '</select>';
            break;

        case 'radio':
            echo '<div class="admin-sf-radio-group">';
            foreach ($options as $optValue => $optLabel) {
                echo '<label class="admin-sf-radio"><input type="radio" name="sf-radio-' . e($key)
                    . '" data-sf-key="' . e($key) . '" data-sf-type="radio" value="' . e((string) $optValue) . '"'
                    . ((string) $optValue === $value ? ' checked' : '') . '><span>' . e((string) $optLabel) . '</span></label>';
            }
            echo '</div>';
            break;

        // ---- 视觉选择型：把选项画出来，让站长"看图选"而不是读懂值再想象 ----
        case 'button_set':
            echo '<div class="admin-sf-btns' . (!empty($inline) ? ' is-inline' : '') . '" role="radiogroup">';
            foreach ($options as $optValue => $optLabel) {
                $on = (string) $optValue === $value;
                echo '<button type="button" class="admin-sf-btn' . ($on ? ' is-on' : '') . '"'
                    . ' data-sf-key="' . e($key) . '" data-sf-type="button_set"'
                    . ' data-sf-value="' . e((string) $optValue) . '"'
                    . ' role="radio" aria-checked="' . ($on ? 'true' : 'false') . '">'
                    . e((string) $optLabel) . '</button>';
            }
            echo '<input type="hidden" data-sf-key="' . e($key) . '" data-sf-type="button_set-value" value="' . e($value) . '">';
            echo '</div>';
            break;

        case 'image_select':
            $multiple = ($field['multiple'] ?? false) === true;
            $picked = $multiple ? array_filter(array_map('strval', (array) json_decode($value !== '' ? $value : '[]', true))) : [];
            $pw = isset($field['preview_width']) ? ' style="max-width:' . (int) $field['preview_width'] . 'px"' : '';
            echo '<div class="admin-sf-imgs' . (!empty($inline) ? ' is-inline' : '') . '" data-multiple="' . ($multiple ? '1' : '0') . '">';
            foreach ($options as $optValue => $optLabel) {
                $on = $multiple ? in_array((string) $optValue, $picked, true) : ((string) $optValue === $value);
                echo '<button type="button" class="admin-sf-img' . ($on ? ' is-on' : '') . '"'
                    . ' data-sf-key="' . e($key) . '" data-sf-type="image_select"'
                    . ' data-sf-value="' . e((string) $optValue) . '"'
                    . ' role="' . ($multiple ? 'checkbox' : 'radio') . '" aria-checked="' . ($on ? 'true' : 'false') . '">'
                    . '<img src="' . e((string) $optLabel) . '" alt=""' . $pw . ' loading="lazy">'
                    . '<span class="admin-sf-img-key">' . e((string) $optValue) . '</span></button>';
            }
            // multiple 时真实值放隐藏域，收集时合成 JSON 数组
            echo '<input type="hidden" data-sf-key="' . e($key) . '" data-sf-type="'
                . ($multiple ? 'image_select-multi' : 'image_select-value') . '" value="' . e($value) . '">';
            echo '</div>';
            break;

        case 'palette':
            echo '<div class="admin-sf-palette">';
            foreach ($options as $optValue => $optLabel) {
                $on = (string) $optValue === $value;
                echo '<button type="button" class="admin-sf-swatch' . ($on ? ' is-on' : '') . '"'
                    . ' data-sf-key="' . e($key) . '" data-sf-type="palette"'
                    . ' data-sf-value="' . e((string) $optValue) . '"'
                    . ' style="background:' . e((string) $optValue) . '"'
                    . ' title="' . e((string) $optLabel) . '" aria-label="' . e((string) $optLabel) . '"'
                    . ' aria-checked="' . ($on ? 'true' : 'false') . '"></button>';
            }
            echo '<input type="hidden" data-sf-key="' . e($key) . '" data-sf-type="palette-value" value="' . e($value) . '">';
            echo '</div>';
            break;

        case 'color':
            echo '<div class="admin-sf-color">'
                . '<input type="color" id="sf-' . e($key) . '" class="admin-sf-color-picker" data-sf-key="' . e($key) . '" data-sf-type="color"'
                . ' value="' . e($value !== '' ? $value : '#000000') . '">'
                . '<input type="text" class="input admin-sf-hex" data-sf-key="' . e($key) . '" data-sf-type="hex"'
                . ' placeholder="#rrggbb" value="' . e($value) . '" autocomplete="off"></div>';
            break;

        case 'image':
            $ph = $placeholder !== '' ? $placeholder : '/uploads/xxx 或外部图片 URL';
            echo '<div class="admin-sf-image">'
                . '<input type="text" class="input" id="sf-' . e($key) . '" data-sf-key="' . e($key) . '" data-sf-type="image"'
                . ' value="' . e($value) . '" placeholder="' . e($ph) . '" autocomplete="off">'
                . '<button type="button" class="btn btn-outline btn-sm admin-sf-lib" data-target="' . e($key) . '">从媒体库选择</button>'
                . '<button type="button" class="btn btn-outline btn-sm admin-sf-upload" data-target="' . e($key) . '">上传图片</button>'
                . '<input type="file" id="sf-upload-' . e($key) . '" class="admin-sf-upload-input" data-target="' . e($key) . '" accept="image/*,.svg" hidden>'
                . '<button type="button" class="btn btn-ghost btn-sm admin-sf-image-clear" data-target="' . e($key) . '">清除</button>'
                . '<p class="admin-field-hint">' . ($value === '' ? '无图片' : '已设置') . '</p></div>';
            break;

        case 'slider':
            $sMin = (float) ($field['min'] ?? 0);
            $sMax = (float) ($field['max'] ?? 100);
            $sStep = (float) ($field['step'] ?? 1);
            $sUnit = is_string($field['unit'] ?? null) ? (string) $field['unit'] : '';
            $sVal = $value !== '' ? $value : (string) ($field['default'] ?? $sMin);
            echo '<div class="admin-sf-slider" data-sf-slider-wrap="' . e($key) . '">'
                . '<input type="range" id="sf-' . e($key) . '" class="admin-sf-range" data-sf-key="' . e($key) . '" data-sf-type="slider"'
                . ' min="' . e((string) $sMin) . '" max="' . e((string) $sMax) . '" step="' . e((string) $sStep) . '"'
                . ' value="' . e($sVal) . '">'
                . '<output class="admin-sf-range-val" data-sf-range-out="' . e($key) . '">'
                . e($sVal . $sUnit) . '</output>'
                /* 数值框只认 min/max：声明的 step 决定滑杆粒度，但把它也写在数值框上，
                   凡是没落在格子上的值（含主题自己给的默认值）都会被浏览器判成非法，
                   整张表单就再也保存不了。粒度由滑杆负责，输入框交给服务端做区间校验。 */
                . '<input type="number" class="input admin-sf-range-num" data-sf-key="' . e($key) . '" data-sf-type="slider-num"'
                . ' min="' . e((string) $sMin) . '" max="' . e((string) $sMax) . '" step="any"'
                . ' value="' . e($sVal) . '" aria-label="' . e($label) . ' 数值"></div>';
            break;

        case 'spacing':
            $sides = is_array($field['sides'] ?? null) ? $field['sides'] : ['top', 'right', 'bottom', 'left'];
            $sideLabels = ['top' => '上', 'right' => '右', 'bottom' => '下', 'left' => '左'];
            $unit = (string) ($field['unit'] ?? 'px');
            $spacingData = json_decode($value !== '' ? $value : (string) ($field['default'] ?? '{}'), true);
            if (!is_array($spacingData)) { $spacingData = []; }
            echo '<div class="admin-sf-spacing" data-sf-spacing-wrap="' . e($key) . '">';
            foreach ($sides as $side) {
                echo '<label class="admin-sf-spacing-cell"><span class="admin-sf-spacing-lab">'
                    . e($sideLabels[$side] ?? $side) . '</span>'
                    . '<input type="number" class="input" data-sf-key="' . e($key) . '" data-sf-type="spacing"'
                    . ' data-sf-side="' . e($side) . '" data-sf-unit="' . e($unit) . '"'
                    . ' value="' . e((string) ($spacingData[$side] ?? '')) . '" placeholder="0" step="any"></label>';
            }
            echo '<span class="admin-sf-spacing-unit">' . e($unit) . '</span></div>';
            break;

        case 'typography':
            $parts = is_array($field['fields'] ?? null) ? $field['fields'] : [];
            $weights = is_array($field['weight_options'] ?? null) ? $field['weight_options'] : [];
            $typoData = json_decode($value !== '' ? $value : (string) ($field['default'] ?? '{}'), true);
            if (!is_array($typoData)) { $typoData = []; }
            $partLabels = ['family' => '字体', 'size' => '字号', 'weight' => '字重',
                           'line_height' => '行高', 'letter_spacing' => '字距',
                           'style' => '样式', 'transform' => '大小写', 'color' => '颜色'];
            $selects = [
                'weight' => $weights,
                'style' => ['' => '默认', 'normal' => '正常', 'italic' => '斜体'],
                'transform' => ['' => '默认', 'none' => '常规', 'uppercase' => '全大写',
                                'lowercase' => '全小写', 'capitalize' => '首字母大写'],
            ];
            echo '<div class="admin-sf-typo" data-sf-typo-wrap="' . e($key) . '">';
            foreach ($parts as $part) {
                $cur = (string) ($typoData[$part] ?? '');
                echo '<label class="admin-sf-typo-cell"><span class="admin-sf-typo-lab">'
                    . e($partLabels[$part] ?? $part) . '</span>';
                if (isset($selects[$part])) {
                    echo '<select class="input" data-sf-key="' . e($key) . '" data-sf-type="typography" data-sf-part="' . e($part) . '">';
                    // 字重没有"空"选项以外，其余都补一个"默认"
                    if ($part !== 'weight') { echo '<option value="">默认</option>'; }
                    elseif (!array_key_exists('', $selects[$part])) { echo '<option value="">默认</option>'; }
                    foreach ($selects[$part] as $ov => $ol) {
                        if ((string) $ov === '') { continue; }
                        echo '<option value="' . e((string) $ov) . '"' . ($cur === (string) $ov ? ' selected' : '')
                            . '>' . e((string) $ol) . '</option>';
                    }
                    echo '</select>';
                } else {
                    $ph = $part === 'family' ? '如 sans-serif' : ($part === 'size' ? '16' : ($part === 'color' ? '#rrggbb' : ''));
                    echo '<input type="text" class="input" data-sf-key="' . e($key) . '" data-sf-type="typography"'
                        . ' data-sf-part="' . e($part) . '" value="' . e($cur) . '" placeholder="' . e($ph) . '" autocomplete="off">';
                }
                echo '</label>';
            }
            echo '</div>';
            break;

        case 'repeater':
            $subFields = is_array($field['fields'] ?? null) ? $field['fields'] : [];
            $rows = json_decode($value !== '' ? $value : '[]', true);
            if (!is_array($rows) || !array_is_list($rows)) { $rows = []; }
            $titleKey = (string) ($field['title_key'] ?? ($subFields[0]['key'] ?? ''));
            $meta = array_map(static fn (array $f): array => [
                'key' => (string) $f['key'],
                'label' => (string) $f['label'],
                'type' => (string) ($f['type'] ?? 'text'),
            ], $subFields);
            echo '<div class="admin-sf-repeater" data-sf-repeater="' . e($key) . '"'
                . ' data-sf-max="' . (int) ($field['max_items'] ?? 20) . '"'
                . ' data-sf-min="' . (int) ($field['min_items'] ?? 0) . '"'
                . ' data-sf-title-key="' . e($titleKey) . '"'
                . ' data-sf-sub-fields="' . e(json_encode($meta, JSON_UNESCAPED_UNICODE)) . '">'
                . '<input type="hidden" data-sf-key="' . e($key) . '" data-sf-type="repeater" value="'
                . e(json_encode($rows, JSON_UNESCAPED_UNICODE)) . '">'
                /* 行是脚本生成的，图标没法在脚本里画：模板里带一份，脚本克隆进每行的手柄。
                   这样图标仍只有 helpers.php 一个出处。 */
                . '<span class="admin-sf-rep-tpl" hidden>' . admin_icon('grip', 14) . '</span>'
                . '<div class="admin-sf-rep-rows"></div>'
                . '<button type="button" class="btn btn-outline btn-sm admin-sf-rep-add">'
                . e((string) ($field['add_label'] ?? '添加一行')) . '</button>'
                . '<span class="admin-field-hint admin-sf-rep-count"></span></div>';
            break;

        // ---- 复合视觉型：background / border ----
        // 一组相关的 CSS 属性合成一个设置项，避免主题里散落 "bg_color / bg_image /
        // bg_repeat / bg_position" 四五个键。取值域固定的子项做成下拉。
        case 'background':
        case 'border':
            $isBg = $type === 'background';
            $data = json_decode($value !== '' ? $value : '{}', true);
            if (!is_array($data)) { $data = []; }
            $choices = is_array($field['choices'] ?? null) ? $field['choices'] : [];
            $parts = is_array($field['fields'] ?? null) ? $field['fields'] : [];
            $unit = $isBg ? '' : 'px';
            echo '<div class="admin-sf-comp" data-sf-comp-wrap="' . e($key) . '" data-sf-comp-type="' . e($type) . '">';
            foreach ($parts as $part) {
                $cur = isset($data[$part]) ? (string) $data[$part] : '';
                $plabel = ['color' => '颜色', 'image' => '图片', 'repeat' => '重复方式',
                           'position' => '位置', 'size' => '尺寸', 'attachment' => '附着方式',
                           'width' => '宽度', 'style' => '线型'][$part] ?? $part;
                echo '<label class="admin-sf-comp-cell"><span class="admin-sf-comp-lab">' . e($plabel) . '</span>';
                if ($part === 'color') {
                    $hex = $cur !== '' ? $cur : '#000000';
                    echo '<span class="admin-sf-comp-color">'
                        . '<input type="color" class="admin-sf-color-picker" data-sf-key="' . e($key)
                        . '" data-sf-type="comp-color" data-sf-part="color" value="' . e($hex) . '">'
                        . '<input type="text" class="input admin-sf-hex" data-sf-key="' . e($key)
                        . '" data-sf-type="comp-hex" data-sf-part="color" placeholder="#rrggbb" value="' . e($cur)
                        . '" autocomplete="off"></span>';
                } elseif ($part === 'image') {
                    echo '<span class="admin-sf-comp-image">'
                        . '<input type="text" class="input" data-sf-key="' . e($key)
                        . '" data-sf-type="comp-part" data-sf-part="image" value="' . e($cur)
                        . '" placeholder="/uploads/xxx 或图片 URL" autocomplete="off">'
                        . '<button type="button" class="btn btn-ghost btn-sm admin-sf-comp-clear">清除</button></span>';
                } elseif (isset($choices[$part])) {
                    echo '<select class="input" data-sf-key="' . e($key)
                        . '" data-sf-type="comp-part" data-sf-part="' . e($part) . '">';
                    foreach ((array) $choices[$part] as $cv => $cl) {
                        echo '<option value="' . e((string) $cv) . '"' . ((string) $cv === $cur ? ' selected' : '') . '>'
                            . e((string) $cl) . '</option>';
                    }
                    echo '</select>';
                } else {
                    echo '<span class="admin-sf-comp-num">'
                        . '<input type="number" class="input" data-sf-key="' . e($key)
                        . '" data-sf-type="comp-part" data-sf-part="' . e($part) . '" value="' . e($cur) . '"'
                        . ' min="0" step="1" autocomplete="off">'
                        . ($unit !== '' ? '<span class="admin-sf-comp-unit">' . e($unit) . '</span>' : '')
                        . '</span>';
                }
                echo '</label>';
            }
            echo '<input type="hidden" data-sf-key="' . e($key) . '" data-sf-type="' . e($type) . '" value="'
                . e(json_encode($data, JSON_UNESCAPED_UNICODE)) . '"></div>';
            break;

        // ---- 代码型：等宽字体 + 自动缩进，不引第三方编辑器 ----
        case 'code':
            $rows = (int) ($field['rows'] ?? 8);
            $lang = (string) ($field['language'] ?? 'css');
            echo '<div class="admin-sf-code" data-sf-code-wrap="' . e($key) . '">'
                . '<div class="admin-sf-code-bar"><span class="admin-sf-code-lang">' . e($lang) . '</span>'
                . '<button type="button" class="btn btn-ghost btn-sm admin-sf-code-tidy" title="重新缩进">整理缩进</button></div>'
                . '<textarea class="input admin-sf-code-area" id="sf-' . e($key) . '" rows="' . $rows . '"'
                . ' data-sf-key="' . e($key) . '" data-sf-type="code" spellcheck="false"'
                . ' placeholder="' . e($placeholder) . '"' . $native . '>' . e($value) . '</textarea></div>';
            break;

        case 'group':
            $subFields = is_array($field['fields'] ?? null) ? $field['fields'] : [];
            $collapsed = ($field['collapsed'] ?? false) === true;
            $icon = is_string($field['icon'] ?? null) ? (string) $field['icon'] : '';
            echo '<div class="admin-sf-groupwrap' . ($collapsed ? ' is-collapsed' : '') . '" data-sf-collapse="' . e($key) . '">';
            echo '<button type="button" class="admin-sf-grouphead" aria-expanded="' . ($collapsed ? 'false' : 'true') . '">'
                . '<span class="admin-sf-groupcaret" aria-hidden="true"></span>';
            if ($icon !== '') { echo '<span class="admin-sf-groupicon">' . e($icon) . '</span>'; }
            echo '<span class="admin-sf-grouptitle">' . e($label) . '</span>'
                . '<span class="admin-shadow-hint admin-sf-groupcount">' . count($subFields) . ' 项</span></button>';
            echo '<div class="admin-sf-groupbody">';
            // 分组说明放进折叠区域内：它描述的是组内内容，放在组外会像是上一个字段的说明
            if ($desc !== '') {
                echo '<p class="admin-sf-desc admin-sf-groupdesc">' . e($desc) . '</p>';
            }
            $innerSection = null;
            foreach ($subFields as $subField) {
                if (is_array($subField) && isset($subField['key'])) {
                    $renderField($subField, $values, $innerSection, true);
                }
            }
            echo '</div></div>';
            break;

        // ---- 备份与恢复：只有动作，没有值 ----
        // 不产生任何设置值（不参与 collectTo 序列化，data-sf-type 也不是输入控件）。
        // 端点只有承载它的那个页面知道，所以这里只输出语义化的 data-sf-backup-act，
        // 由页面脚本绑定——partial 保持无业务逻辑，插件页若要用可不带端点直接复用。
        case 'backup':
            echo '<div class="admin-sf-backup" data-sf-backup>'
                . '<div class="admin-sf-backup-acts">'
                . '<button type="button" class="btn btn-success" data-sf-backup-act="export">导出设置</button>'
                . '<button type="button" class="btn btn-success" data-sf-backup-act="import">导入设置</button>'
                . '<button type="button" class="btn admin-sf-backup-reset" data-sf-backup-act="reset">恢复默认</button>'
                . '</div>'
                . '<input type="file" accept="application/json,.json" hidden data-sf-backup-file>'
                . '<p class="admin-field-hint admin-sf-backup-msg" data-sf-backup-msg hidden></p>'
                . '</div>';
            break;

        default:
            // 理论上不可达（normalizeSetting 已做类型白名单）；留痕便于排查
            echo '<p class="admin-field-hint">不支持的字段类型：' . e($type) . '</p>';
    }

    if (!$isBlock) {
        echo '</div>';   // 关闭 admin-sf-control
        if ($desc !== '') {
            // 说明文字放在控件下方、与控件同列对齐（不进左列，避免被挤成窄条）
            echo '<p class="admin-sf-desc">' . e($desc) . '</p>';
        }
    }
    if (isset($rules['pattern_message']) && $desc === '') {
        echo '<p class="admin-sf-desc">' . e((string) $rules['pattern_message']) . '</p>';
    }
    echo '</div>';
};

return $renderField;
