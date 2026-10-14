<?php
/**
 * 两级分组导航 + 内容面板（主题设置页与插件设置页共用）。
 *
 * 页面形态：
 *   一级：分组入口；有子级时带展开箭头，点击展开/收起
 *   二级：缩进的小号条目，点击切换内容
 *   内容：每个叶子分组一个 section，同一时刻只显示一个
 *
 * 期望变量：
 *   $built    schema-groups.php 的返回值
 *   $values   当前设置值
 *   $renderField 字段渲染闭包
 */
$tabs = $built['tabs'];
$panes = $built['panes'];
$hasNav = $built['hasNav'];
// 平铺模式（emlog 式单栏）：把分组导航降级成"内联的分组标题"。
// 由调用方通过 $flat 传入；默认关闭，主题设置页仍是左侧两级导航。
$flat = !empty($flat);
$useNav = $hasNav && !$flat;
// 图标来自分组内首个声明 icon 的字段（见 schema-groups.php）。
// 名字不认识时 admin_icon() 会回退到默认字形，所以这里无需白名单。
// 槽位**无条件**输出：一级父级、有图标的一级叶子、二级条目都在同一条竖排轴线上，
// 只要有一条没声明图标就少一个槽宽，文字会各自错开（主题设置页尤其明显）。
$navIcon = static function (array $item): string {
    $name = (string) ($item['icon'] ?? '');
    return '<span class="admin-sf-navico" aria-hidden="true">'
        . ($name === '' ? '' : admin_icon($name, 14)) . '</span>';
};
// 分组名 → 显示名。平铺模式靠它渲染小节标题。
$labelOf = [];
foreach ($tabs as $tab) {
    $labelOf[$tab['name']] = $tab['label'];
    foreach ($tab['children'] as $kid) {
        $labelOf[$kid['name']] = $kid['label'];
    }
}
?>
<div class="admin-sf-layout<?= $useNav ? '' : ' admin-sf-layout-single' ?><?= $flat ? ' admin-sf-layout-flat' : '' ?>">
  <?php if ($useNav): ?>
    <?php /* 窄屏遮罩：点它关闭抽屉。桌面端由 CSS 收掉。 */ ?>
    <div class="admin-sf-navbackdrop" data-sf-navbackdrop hidden></div>
  <?php endif; ?>

  <div class="admin-sf-panes">
    <?php if ($useNav): ?>
      <?php /* 窄屏唤出导航的按钮。桌面端由 CSS 收掉。
             放在内容区顶部而不是头部：头部在手机上已经很挤，
             再塞一个按钮会把站点名挤没。 */ ?>
      <button type="button" class="admin-sf-navtrigger" data-sf-navtrigger
              aria-expanded="false" aria-controls="admin-sf-nav">
        <span aria-hidden="true">☰</span>
        <span>设置分组</span>
      </button>
    <?php endif; ?>
    <?php foreach ($panes as $i => $pane): ?>
      <?php /* 平铺模式：所有分组都显示，各自带一条小节标题（emlog 式）。
             非平铺模式：只显示首个分组，其余靠导航切换。 */ ?>
      <section class="admin-sf-group<?= (!$flat && $i > 0) ? ' hidden' : '' ?>"
               data-sf-group="<?= e($pane['name']) ?>" aria-label="<?= e($pane['name']) ?>">
        <?php
        // 分组标题两种模式都输出：平铺模式直接显示；带导航的模式平时由样式收掉
        // （"当前在哪个分组"由左侧导航表达），搜索时用不到导航，就靠它给命中项标注来源。
        // 只有一个分组、且组名是自动兜底的「常规」时不画——顶上多一条「常规」只是噪声。
        $groupLabel = $labelOf[$pane['name']] ?? '';
        $showGroupTitle = $groupLabel !== ''
            && !(count($panes) === 1 && $groupLabel === '常规');
        ?>
        <?php if ($showGroupTitle): ?>
          <h2 class="admin-sf-group-title"><?= e($groupLabel) ?></h2>
        <?php endif; ?>
        <?php $lastSection = null; ?>
        <?php foreach ($pane['fields'] as $field): ?>
          <?php if (is_array($field) && isset($field['key'])) { $renderField($field, $values, $lastSection); } ?>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </div>

  <?php if ($useNav): ?>
    <nav class="admin-sf-side" id="admin-sf-nav" aria-label="设置分组">
      <?php /* 抽屉标题栏（窄屏显示）：给一个明确的关闭入口，
              否则只能靠点遮罩，触屏上容易误操作。 */ ?>
      <div class="admin-sf-side-head">
        <span>设置分组</span>
        <button type="button" class="admin-sf-navclose" data-sf-navclose
                aria-label="关闭分组导航">×</button>
      </div>
      <?php foreach ($tabs as $tab): ?>
        <?php if ($tab['children'] === []): ?>
          <?php /* 一级叶子：直接就是可点条目 */ ?>
          <button type="button" class="admin-sf-sidenav<?= $tab['name'] === $panes[0]['name'] ? ' active' : '' ?>"
                  data-sf-tab="<?= e($tab['name']) ?>" aria-current="<?= $tab['name'] === $panes[0]['name'] ? 'true' : 'false' ?>"><?= $navIcon($tab) ?><?= e($tab['label']) ?></button>
        <?php else: ?>
          <?php /* 一级父级：可展开，自身不承载内容 */ ?>
          <?php
          $childNames = array_column($tab['children'], 'name');
          $childActive = in_array($panes[0]['name'], $childNames, true);
          ?>
          <div class="admin-sf-navgrp<?= $childActive ? ' is-open' : '' ?>" data-sf-navgrp="<?= e($tab['name']) ?>">
            <button type="button" class="admin-sf-sidenav admin-sf-sidenav-parent"
                    data-sf-toggle="<?= e($tab['name']) ?>" aria-expanded="<?= $childActive ? 'true' : 'false' ?>">
              <span class="admin-sf-navcaret" aria-hidden="true"></span>
              <span class="admin-sf-navlabel"><?= $navIcon($tab) ?><?= e($tab['label']) ?></span>
            </button>
            <div class="admin-sf-navsubs">
              <?php foreach ($tab['children'] as $kid): ?>
                <button type="button" class="admin-sf-sidenav admin-sf-sidenav-sub<?= $kid['name'] === $panes[0]['name'] ? ' active' : '' ?>"
                        data-sf-tab="<?= e($kid['name']) ?>" aria-current="<?= $kid['name'] === $panes[0]['name'] ? 'true' : 'false' ?>"><?= $navIcon($kid) ?><?= e($kid['label']) ?></button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
</div>
