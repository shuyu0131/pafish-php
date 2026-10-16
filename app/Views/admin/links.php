<?php
/**
 * 友情链接管理：
 * - 新建与编辑复用弹窗；列表保留名称、状态、地址和简介
 * - 操作：↑/↓ 上下移动（边界禁用）、显隐、编辑（弹窗）、删除（确认弹窗）
 * 变量：$items $role
 */
$count = count($items);
$hidden = 0;
foreach ($items as $l) {
    if ((int) $l['visible'] === 0) {
        $hidden++;
    }
}
?>
<div class="admin-stack admin-detail-page admin-links-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">友情链接</h1>
      <p class="admin-page-sub">展示在首页底部，共 <?= $count ?> 个（含隐藏 <?= $hidden ?> 个）</p>
    </div>
    <button type="button" class="btn btn-primary" data-entity-new="linksModal"><?= admin_icon('plus', 15) ?>添加链接</button>
  </div>

  <!-- 列表 -->
  <?php if ($count === 0): ?>
    <div class="admin-empty-list card">
      <?= admin_icon('link', 32) ?>
      <p>还没有友情链接</p>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap admin-order-table-wrap">
      <table class="admin-table admin-order-table">
        <thead><tr><th>名称</th><th>地址</th><th>状态</th><th class="admin-col-ops">操作</th></tr></thead>
        <tbody data-admin-sort="<?= e(url_to('/admin/links/reorder')) ?>" data-sort-csrf="<?= e(csrf_token()) ?>">
      <?php foreach ($items as $i => $l): ?>
        <tr class="admin-list-row" data-id="<?= (int) $l['id'] ?>"
             data-name="<?= e($l['name']) ?>" data-url="<?= e($l['url']) ?>"
             data-description="<?= e($l['description'] ?? '') ?>" data-icon="<?= e($l['icon_url'] ?? '') ?>">
          <td data-label="名称"><button type="button" class="admin-icon-btn admin-sort-handle" aria-label="拖动排序" title="拖动排序"><svg width="14" height="18" viewBox="0 0 14 18" aria-hidden="true" fill="currentColor"><circle cx="4" cy="4" r="1.2"/><circle cx="10" cy="4" r="1.2"/><circle cx="4" cy="9" r="1.2"/><circle cx="10" cy="9" r="1.2"/><circle cx="4" cy="14" r="1.2"/><circle cx="10" cy="14" r="1.2"/></svg></button><span class="admin-list-name"><?= e($l['name']) ?></span></td>
          <td data-label="地址"><div class="admin-muted"><?= e($l['url']) ?><?= ($l['description'] ?? '') !== '' ? ' · ' . e($l['description']) : '' ?></div></td>
          <td data-label="状态">
            <?php if ((int) $l['visible'] === 0): ?>
              <span class="badge badge-danger">已隐藏</span>
            <?php endif; ?>
            <?php if ((int) $l['visible'] === 1): ?><span class="badge">已显示</span><?php endif; ?>
          </td>
          <td data-label="操作" class="admin-col-ops"><div class="admin-list-ops">
            <button type="button" class="admin-icon-btn" data-move="up" title="上移" <?= $i === 0 ? 'disabled' : '' ?>><?= admin_icon('chevron-up', 15) ?></button>
            <button type="button" class="admin-icon-btn" data-move="down" title="下移" <?= $i === $count - 1 ? 'disabled' : '' ?>><?= admin_icon('chevron-down', 15) ?></button>
            <button type="button" class="admin-icon-btn" data-toggle title="<?= (int) $l['visible'] === 1 ? '隐藏' : '显示' ?>">
              <?= admin_icon((int) $l['visible'] === 1 ? 'eye' : 'eye-off', 15) ?>
            </button>
            <button type="button" class="admin-icon-btn" data-entity-edit="linksModal" title="编辑"><?= admin_icon('edit', 15) ?></button>
            <button type="button" class="admin-icon-btn admin-icon-danger" data-delete title="删除"><?= admin_icon('trash', 15) ?></button>
          </div></td>
        </tr>
      <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php ob_start(); ?>
<div class="admin-form-grid">
        <div class="admin-field">
          <label class="label" for="linksName">站点名称 *</label>
          <input class="input" type="text" name="name" placeholder="站点名称" maxlength="100" id="linksName" required>
        </div>
        <div class="admin-field">
          <label class="label" for="linksUrl">地址 *</label>
          <input class="input" type="text" name="url" placeholder="https://example.com" maxlength="500" id="linksUrl" required>
        </div>
        <div class="admin-field">
          <label class="label" for="linksDescription">简介（可选）</label>
          <input class="input" type="text" name="description" placeholder="一句话介绍" maxlength="255" id="linksDescription">
        </div>
        <div class="admin-field">
          <label class="label" for="linkIcon">站点图标</label>
          <?php $imageId = 'linkIcon'; $imageName = 'icon_url'; $imageValue = ''; require __DIR__ . '/partials/image-field.php'; ?>
        </div>
      </div>
<?php
$entityFields = (string) ob_get_clean();
$entityModalId = 'linksModal';
$entityFormId = 'createForm';
$entityConfig = ['createTitle'=>'添加链接', 'editTitle'=>'编辑链接', 'savedMessage'=>'链接已保存',
    'savePath'=>'/admin/links/save', 'basePath'=>'/admin/links', 'fields'=>['name'=>'name','url'=>'url','description'=>'description','icon_url'=>'icon']];
require __DIR__ . '/partials/entity-modal.php';
?>
<script src="<?= e(asset_url('/js/admin-entity-forms.js')) ?>" defer></script>

<?php $linksCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($linksCsrf) ?>;
  var base = <?= json_encode(url_to('/admin/links')) ?>;

  function post(url, body) {
    var fd = new FormData();
    Object.keys(body || {}).forEach(function (k) { fd.append(k, body[k]); });
    fd.append("_csrf", CSRF);
    return fetch(url, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } });
  }
  document.querySelectorAll("[data-toggle]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      post(base + "/" + row.getAttribute("data-id") + "/toggle")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) pafishToastReload("链接状态已更新", "success");
          else pafishNotify((j && j.error) || "操作失败", true);
        })
        .catch(function () { pafishNotify("网络错误", true); });
    });
  });

  // 上下移动
  document.querySelectorAll("[data-move]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      post(base + "/" + row.getAttribute("data-id") + "/move", { dir: btn.getAttribute("data-move") })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) pafishToastReload("链接顺序已更新", "success"); else pafishNotify((j && j.error) || "操作失败", true); })
        .catch(function () { pafishNotify("网络错误", true); });
    });
  });

  // 删除确认
  document.querySelectorAll("[data-delete]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      var name = row.getAttribute("data-name");
      (window.pafishConfirm ? window.pafishConfirm("确定删除链接「" + name + "」？", { title: "删除链接" }) : Promise.resolve(window.confirm("确定删除链接「" + name + "」？"))).then(function (ok) {
        if (!ok) return;
        return post(base + "/" + row.getAttribute("data-id") + "/delete")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) pafishToastReload("链接已删除", "success");
          else pafishNotify((j && j.error) || "删除失败", true);
        })
        .catch(function () { pafishNotify("网络错误", true); });
      });
    });
  });
})();
</script>

<script src="<?= e(asset_url('/js/admin-sort.js')) ?>" defer></script>
<script src="<?= e(asset_url('/js/admin-image-fields.js')) ?>" defer></script>
