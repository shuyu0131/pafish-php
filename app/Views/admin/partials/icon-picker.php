<?php
/**
 * 主题/插件设置共用的图标选择器。
 *
 * 图标值保存为 class 字符串；弹窗只负责浏览与选择，不绑定具体主题或插件。
 * catalog 由 public/js/admin-icon-picker.js 提供，未选择时仍可手写自定义 class。
 */
?>
<div class="admin-icon-picker-backdrop" data-icon-picker-modal hidden aria-hidden="true">
  <div class="admin-icon-picker" role="dialog" aria-modal="true" aria-labelledby="adminIconPickerTitle" tabindex="-1">
    <div class="admin-icon-picker-head">
      <div>
        <h2 id="adminIconPickerTitle">选择图标</h2>
        <p>搜索名称或 class，点击图标即可应用</p>
      </div>
      <button type="button" class="admin-icon-btn" data-icon-picker-close aria-label="关闭"><?= admin_icon('x', 16) ?></button>
    </div>
    <div class="admin-icon-picker-toolbar">
      <div class="admin-icon-picker-tabs" role="tablist" aria-label="图标库">
        <button type="button" class="admin-icon-picker-tab is-active" data-icon-library="remixicon" role="tab" aria-selected="true">Remix Icon</button>
        <button type="button" class="admin-icon-picker-tab" data-icon-library="fontawesome" role="tab" aria-selected="false">Font Awesome 4</button>
        <button type="button" class="admin-icon-picker-tab" data-icon-library="both" role="tab" aria-selected="false">全部图标</button>
        <button type="button" class="admin-icon-picker-tab" data-icon-library="custom" role="tab" aria-selected="false" hidden>主题自定义</button>
      </div>
      <label class="admin-icon-picker-search">
        <span class="sr-only">搜索图标</span>
        <?= admin_icon('search', 15) ?>
        <input type="search" class="input" data-icon-picker-search placeholder="搜索图标，例如 home、arrow…" autocomplete="off">
      </label>
    </div>
    <div class="admin-icon-picker-meta" data-icon-picker-meta></div>
    <button type="button" class="admin-icon-picker-browse" data-icon-picker-browse>浏览全部图标</button>
    <div class="admin-icon-picker-grid" data-icon-picker-grid role="listbox" aria-label="图标列表"></div>
  </div>
</div>
<script src="<?= e(asset_url('/js/admin-icon-catalog.js')) ?>"></script>
<script src="<?= e(asset_url('/js/admin-icon-picker.js')) ?>"></script>
