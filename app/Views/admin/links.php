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
      <h1 class="admin-h1"><?= e(t('admin.friend_links')) ?></h1>
      <p class="admin-page-sub"><?= e(t('admin.shown_at_the_bottom_of_the_homepage_total')) ?> <?= $count ?> <?= e(t('admin.items_including_hidden')) ?> <?= $hidden ?> <?= e(t('admin.items_21c203')) ?></p>
    </div>
    <button type="button" class="btn btn-primary" data-entity-new="linksModal"><?= admin_icon('plus', 15) ?><?= e(t('admin.add_link')) ?></button>
  </div>

  <!-- 列表 -->
  <?php if ($count === 0): ?>
    <div class="admin-empty-list card">
      <?= admin_icon('link', 32) ?>
      <p><?= e(t('admin.no_friend_links_yet')) ?></p>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap admin-order-table-wrap">
      <table class="admin-table admin-order-table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('admin.url')) ?></th><th><?= e(t('common.status')) ?></th><th class="admin-col-ops"><?= e(t('common.actions')) ?></th></tr></thead>
        <tbody data-admin-sort="<?= e(url_to('/admin/links/reorder')) ?>" data-sort-csrf="<?= e(csrf_token()) ?>">
      <?php foreach ($items as $i => $l): ?>
        <tr class="admin-list-row" data-id="<?= (int) $l['id'] ?>"
             data-name="<?= e($l['name']) ?>" data-url="<?= e($l['url']) ?>"
             data-description="<?= e($l['description'] ?? '') ?>" data-icon="<?= e($l['icon_url'] ?? '') ?>">
          <td data-label="<?= e(t('common.name')) ?>"><button type="button" class="admin-icon-btn admin-sort-handle" aria-label="<?= e(t('admin.drag_to_reorder')) ?>" title="<?= e(t('admin.drag_to_reorder')) ?>"><svg width="14" height="18" viewBox="0 0 14 18" aria-hidden="true" fill="currentColor"><circle cx="4" cy="4" r="1.2"/><circle cx="10" cy="4" r="1.2"/><circle cx="4" cy="9" r="1.2"/><circle cx="10" cy="9" r="1.2"/><circle cx="4" cy="14" r="1.2"/><circle cx="10" cy="14" r="1.2"/></svg></button><span class="admin-list-name"><?= e($l['name']) ?></span></td>
          <td data-label="<?= e(t('admin.url')) ?>"><div class="admin-muted"><?= e($l['url']) ?><?= ($l['description'] ?? '') !== '' ? ' · ' . e($l['description']) : '' ?></div></td>
          <td data-label="<?= e(t('common.status')) ?>">
            <?php if ((int) $l['visible'] === 0): ?>
              <span class="badge badge-danger"><?= e(t('admin.hidden')) ?></span>
            <?php endif; ?>
            <?php if ((int) $l['visible'] === 1): ?><span class="badge"><?= e(t('admin.visible')) ?></span><?php endif; ?>
          </td>
          <td data-label="<?= e(t('common.actions')) ?>" class="admin-col-ops"><div class="admin-list-ops">
            <button type="button" class="admin-icon-btn" data-move="up" title="<?= e(t('admin.move_up')) ?>" <?= $i === 0 ? 'disabled' : '' ?>><?= admin_icon('chevron-up', 15) ?></button>
            <button type="button" class="admin-icon-btn" data-move="down" title="<?= e(t('admin.move_down')) ?>" <?= $i === $count - 1 ? 'disabled' : '' ?>><?= admin_icon('chevron-down', 15) ?></button>
            <button type="button" class="admin-icon-btn" data-toggle title="<?= (int) $l['visible'] === 1 ? t('admin.hide') : t('admin.show') ?>">
              <?= admin_icon((int) $l['visible'] === 1 ? 'eye' : 'eye-off', 15) ?>
            </button>
            <button type="button" class="admin-icon-btn" data-entity-edit="linksModal" title="<?= e(t('common.edit')) ?>"><?= admin_icon('edit', 15) ?></button>
            <button type="button" class="admin-icon-btn admin-icon-danger" data-delete title="<?= e(t('common.delete')) ?>"><?= admin_icon('trash', 15) ?></button>
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
          <label class="label" for="linksName"><?= e(t('admin.site_name')) ?></label>
          <input class="input" type="text" name="name" placeholder="<?= e(t('admin.site_name_e72ec8')) ?>" maxlength="100" id="linksName" required>
        </div>
        <div class="admin-field">
          <label class="label" for="linksUrl"><?= e(t('admin.url_7c05d9')) ?></label>
          <input class="input" type="text" name="url" placeholder="https://example.com" maxlength="500" id="linksUrl" required>
        </div>
        <div class="admin-field">
          <label class="label" for="linksDescription"><?= e(t('admin.description_optional')) ?></label>
          <input class="input" type="text" name="description" placeholder="<?= e(t('admin.short_bio')) ?>" maxlength="255" id="linksDescription">
        </div>
        <div class="admin-field">
          <label class="label" for="linkIcon"><?= e(t('admin.site_icon')) ?></label>
          <?php $imageId = 'linkIcon'; $imageName = 'icon_url'; $imageValue = ''; require __DIR__ . '/partials/image-field.php'; ?>
        </div>
      </div>
<?php
$entityFields = (string) ob_get_clean();
$entityModalId = 'linksModal';
$entityFormId = 'createForm';
$entityConfig = ['createTitle'=>t('admin.add_link'), 'editTitle'=>t('admin.edit_link'), 'savedMessage'=>t('admin.link_saved'),
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
          if (j && j.ok) pafishToastReload(window.pafishT("js.link_visibility_updated"), "success");
          else pafishNotify((j && j.error) || window.pafishT("common.operation_failed"), true);
        })
        .catch(function () { pafishNotify(window.pafishT("js.network_error"), true); });
    });
  });

  // 上下移动
  document.querySelectorAll("[data-move]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      post(base + "/" + row.getAttribute("data-id") + "/move", { dir: btn.getAttribute("data-move") })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) pafishToastReload(window.pafishT("js.link_order_updated"), "success"); else pafishNotify((j && j.error) || window.pafishT("common.operation_failed"), true); })
        .catch(function () { pafishNotify(window.pafishT("js.network_error"), true); });
    });
  });

  // 删除确认
  document.querySelectorAll("[data-delete]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      var name = row.getAttribute("data-name");
      (window.pafishConfirm ? window.pafishConfirm(window.pafishT("js.confirm_delete_link", {name: name}), { title: window.pafishT("js.delete_link_5f8cb7") }) : Promise.resolve(window.confirm(window.pafishT("js.confirm_delete_link", {name: name})))).then(function (ok) {
        if (!ok) return;
        return post(base + "/" + row.getAttribute("data-id") + "/delete")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) pafishToastReload(window.pafishT("js.link_deleted"), "success");
          else pafishNotify((j && j.error) || window.pafishT("js.delete_failed"), true);
        })
        .catch(function () { pafishNotify(window.pafishT("js.network_error"), true); });
      });
    });
  });
})();
</script>

<script src="<?= e(asset_url('/js/admin-sort.js')) ?>" defer></script>
<script src="<?= e(asset_url('/js/admin-image-fields.js')) ?>" defer></script>
