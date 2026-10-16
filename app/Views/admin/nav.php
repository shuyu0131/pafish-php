<?php
/**
 * 导航菜单管理：
 * - 新建与编辑复用弹窗（含「外部链接（新窗口打开）」复选框）
 * - 列表行：名称 + 「已隐藏」badge + 「外部」badge + url 可点击预览
 * - 操作：↑/↓ 上下移动（边界禁用）、显隐、编辑（弹窗）、删除（确认弹窗）
 * 变量：$items $role
 */
$count = count($items);
$hidden = 0;
foreach ($items as $n) {
    if ((int) $n['visible'] === 0) {
        $hidden++;
    }
}
?>
<div class="admin-stack admin-detail-page admin-nav-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">导航菜单</h1>
      <p class="admin-page-sub">配置顶部导航与移动端菜单（共 <?= $count ?> 项，含隐藏 <?= $hidden ?> 项）</p>
    </div>
    <button type="button" class="btn btn-primary" data-entity-new="navModal"><?= admin_icon('plus', 15) ?>添加导航项</button>
  </div>

  <!-- 列表 -->
  <?php if ($count === 0): ?>
    <div class="admin-empty-list card">
      <?= admin_icon('menu', 32) ?>
      <p>还没有导航项</p>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap admin-order-table-wrap">
      <table class="admin-table admin-order-table">
        <thead><tr><th>名称</th><th>地址</th><th>状态</th><th class="admin-col-ops">操作</th></tr></thead>
        <tbody data-admin-sort="<?= e(url_to('/admin/nav/reorder')) ?>" data-sort-csrf="<?= e(csrf_token()) ?>">
      <?php foreach ($items as $i => $n): ?>
        <tr class="admin-list-row" data-id="<?= (int) $n['id'] ?>"
             data-label="<?= e($n['label']) ?>" data-url="<?= e($n['url']) ?>"
             data-external="<?= (int) $n['is_external'] ?>">
          <td data-label="名称"><button type="button" class="admin-icon-btn admin-sort-handle" aria-label="拖动排序" title="拖动排序"><svg width="14" height="18" viewBox="0 0 14 18" aria-hidden="true" fill="currentColor"><circle cx="4" cy="4" r="1.2"/><circle cx="10" cy="4" r="1.2"/><circle cx="4" cy="9" r="1.2"/><circle cx="10" cy="9" r="1.2"/><circle cx="4" cy="14" r="1.2"/><circle cx="10" cy="14" r="1.2"/></svg></button><span class="admin-list-name"><?= e($n['label']) ?></span></td>
          <td data-label="地址"><div class="admin-muted"><a href="<?= e($n['url']) ?>" <?= (int) $n['is_external'] === 1 ? 'target="_blank" rel="noopener"' : '' ?>><?= e($n['url']) ?></a></div></td>
          <td data-label="状态">
            <?php if ((int) $n['visible'] === 0): ?>
              <span class="badge badge-danger">已隐藏</span>
            <?php else: ?>
              <span class="badge">已显示</span>
            <?php endif; ?>
            <?php if ((int) $n['is_external'] === 1): ?>
              <span class="badge badge-accent"><?= admin_icon('external-link', 11) ?> 外部</span>
            <?php endif; ?>
          </td>
          <td data-label="操作" class="admin-col-ops"><div class="admin-list-ops">
            <button type="button" class="admin-icon-btn" data-move="up" title="上移" <?= $i === 0 ? 'disabled' : '' ?>><?= admin_icon('chevron-up', 15) ?></button>
            <button type="button" class="admin-icon-btn" data-move="down" title="下移" <?= $i === $count - 1 ? 'disabled' : '' ?>><?= admin_icon('chevron-down', 15) ?></button>
            <button type="button" class="admin-icon-btn" data-toggle title="<?= (int) $n['visible'] === 1 ? '隐藏' : '显示' ?>">
              <?= admin_icon((int) $n['visible'] === 1 ? 'eye' : 'eye-off', 15) ?>
            </button>
            <button type="button" class="admin-icon-btn" data-entity-edit="navModal" title="编辑"><?= admin_icon('edit', 15) ?></button>
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
          <label class="label" for="navLabel">名称 *</label>
          <input class="input" type="text" name="label" placeholder="如：首页 / 关于" maxlength="100" id="navLabel" required>
        </div>
        <div class="admin-field">
          <label class="label" for="navUrl">地址 *</label>
          <input class="input" type="text" name="url" placeholder="/archives 或 https://…" maxlength="500" id="navUrl" required>
        </div>
      </div>
      <label class="admin-check-row">
        <input type="checkbox" name="is_external" value="1" class="admin-check">
        <span>外部链接（新窗口打开）</span>
      </label>
<?php
$entityFields = (string) ob_get_clean();
$entityModalId = 'navModal';
$entityFormId = 'createForm';
$entityConfig = ['createTitle'=>'添加导航项', 'editTitle'=>'编辑导航项', 'savedMessage'=>'导航项已保存',
    'savePath'=>'/admin/nav/save', 'basePath'=>'/admin/nav', 'fields'=>['label'=>'label','url'=>'url','is_external'=>'external']];
require __DIR__ . '/partials/entity-modal.php';
?>
<script src="<?= e(asset_url('/js/admin-entity-forms.js')) ?>" defer></script>

<?php $navCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($navCsrf) ?>;
  var base = <?= json_encode(url_to('/admin/nav')) ?>;

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
          if (j && j.ok) pafishToastReload("导航项状态已更新", "success");
          else pafishNotify((j && j.error) || "操作失败", true);
        })
        .catch(function () { pafishNotify("网络错误", true); });
    });
  });

  document.querySelectorAll("[data-move]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      post(base + "/" + row.getAttribute("data-id") + "/move", { dir: btn.getAttribute("data-move") })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) pafishToastReload("导航顺序已更新", "success"); else pafishNotify((j && j.error) || "操作失败", true); })
        .catch(function () { pafishNotify("网络错误", true); });
    });
  });

  document.querySelectorAll("[data-delete]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      var label = row.getAttribute("data-label");
      (window.pafishConfirm ? window.pafishConfirm("确定删除导航项「" + label + "」？", { title: "删除导航项" }) : Promise.resolve(window.confirm("确定删除导航项「" + label + "」？"))).then(function (ok) {
        if (!ok) return;
        return post(base + "/" + row.getAttribute("data-id") + "/delete")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) pafishToastReload("导航项已删除", "success");
          else pafishNotify((j && j.error) || "删除失败", true);
        })
        .catch(function () { pafishNotify("网络错误", true); });
      });
    });
  });
})();
</script>

<script src="<?= e(asset_url('/js/admin-sort.js')) ?>" defer></script>
