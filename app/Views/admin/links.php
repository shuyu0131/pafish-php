<?php
/**
 * 友情链接管理（对齐 Node app/admin/links/）：
 * - 顶部新建表单；列表行：名称 + 「已隐藏」badge + url · description
 * - 操作：↑/↓ 上下移动（边界禁用）、显隐、编辑（行内展开）、删除（两步确认）
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
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">友情链接</h1>
      <p class="admin-page-sub">展示在首页底部，共 <?= $count ?> 个（含隐藏 <?= $hidden ?> 个）</p>
    </div>
  </div>

  <!-- 新建表单 -->
  <div class="card admin-form-card">
    <h2 class="admin-card-title">添加链接</h2>
    <form id="createForm" method="post" action="<?= e(url_to('/admin/links/save')) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="admin-form-grid">
        <div class="admin-field">
          <span class="label">站点名称 *</span>
          <input class="input" type="text" name="name" placeholder="站点名称" maxlength="100">
        </div>
        <div class="admin-field">
          <span class="label">地址 *</span>
          <input class="input" type="text" name="url" placeholder="https://example.com" maxlength="500">
        </div>
        <div class="admin-field">
          <span class="label">简介（可选）</span>
          <input class="input" type="text" name="description" placeholder="一句话介绍" maxlength="255">
        </div>
      </div>
      <div class="admin-cat-form-actions">
        <button type="submit" class="btn btn-primary">添加链接</button>
      </div>
      <div class="admin-editor-error" hidden></div>
    </form>
  </div>

  <!-- 列表 -->
  <?php if ($count === 0): ?>
    <div class="admin-empty-list card">
      <?= admin_icon('link', 32) ?>
      <p>还没有友情链接，在上方添加第一个</p>
    </div>
  <?php else: ?>
    <div class="admin-list">
      <?php foreach ($items as $i => $l): ?>
        <div class="admin-list-row card" data-id="<?= (int) $l['id'] ?>"
             data-name="<?= e($l['name']) ?>" data-url="<?= e($l['url']) ?>"
             data-description="<?= e($l['description'] ?? '') ?>">
          <div class="admin-list-main">
            <span class="admin-list-name"><?= e($l['name']) ?></span>
            <?php if ((int) $l['visible'] === 0): ?>
              <span class="badge badge-danger">已隐藏</span>
            <?php endif; ?>
            <div class="admin-muted">
              <?= e($l['url']) ?><?= ($l['description'] ?? '') !== '' ? ' · ' . e($l['description']) : '' ?>
            </div>
          </div>
          <div class="admin-list-ops">
            <button type="button" class="admin-icon-btn" data-move="up" title="上移" <?= $i === 0 ? 'disabled' : '' ?>><?= admin_icon('chevron-up', 15) ?></button>
            <button type="button" class="admin-icon-btn" data-move="down" title="下移" <?= $i === $count - 1 ? 'disabled' : '' ?>><?= admin_icon('chevron-down', 15) ?></button>
            <button type="button" class="admin-icon-btn" data-toggle title="<?= (int) $l['visible'] === 1 ? '隐藏' : '显示' ?>">
              <?= admin_icon((int) $l['visible'] === 1 ? 'eye' : 'eye-off', 15) ?>
            </button>
            <button type="button" class="admin-icon-btn" data-edit title="编辑"><?= admin_icon('edit', 15) ?></button>
            <button type="button" class="admin-icon-btn admin-icon-danger" data-delete title="删除"><?= admin_icon('trash', 15) ?></button>
          </div>
          <!-- 行内编辑表单 -->
          <div class="admin-list-edit" hidden>
            <form class="admin-inline-edit" method="post" novalidate>
              <?= csrf_field() ?>
              <div class="admin-form-grid">
                <div class="admin-field">
                  <span class="label">站点名称 *</span>
                  <input class="input" type="text" name="name" maxlength="100">
                </div>
                <div class="admin-field">
                  <span class="label">地址 *</span>
                  <input class="input" type="text" name="url" maxlength="500">
                </div>
                <div class="admin-field">
                  <span class="label">简介（可选）</span>
                  <input class="input" type="text" name="description" maxlength="255">
                </div>
              </div>
              <div class="admin-cat-form-actions">
                <button type="submit" class="btn btn-primary">保存修改</button>
                <button type="button" class="btn btn-ghost" data-edit-cancel>取消</button>
              </div>
              <div class="admin-editor-error" hidden></div>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

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
  function submitForm(form) {
    var err = form.querySelector(".admin-editor-error");
    err.hidden = true;
    var fd = new FormData(form);
    fd.set("_csrf", CSRF);
    fetch(form.action, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) location.reload();
        else { err.textContent = (j && j.error) || "保存失败"; err.hidden = false; }
      })
      .catch(function () { err.textContent = "网络错误"; err.hidden = false; });
  }

  // 新建
  document.getElementById("createForm").addEventListener("submit", function (e) {
    e.preventDefault();
    submitForm(e.target);
  });

  // 行内编辑：展开/收起
  document.querySelectorAll("[data-edit]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      var box = row.querySelector(".admin-list-edit");
      var open = !box.hidden;
      document.querySelectorAll(".admin-list-edit").forEach(function (b) { b.hidden = true; });
      if (open) return;
      box.hidden = false;
      var f = box.querySelector("form");
      f.action = base + "/" + row.getAttribute("data-id") + "/save";
      f.querySelector('[name="name"]').value = row.getAttribute("data-name");
      f.querySelector('[name="url"]').value = row.getAttribute("data-url");
      f.querySelector('[name="description"]').value = row.getAttribute("data-description");
      box.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  });
  document.querySelectorAll("[data-edit-cancel]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      btn.closest(".admin-list-edit").hidden = true;
    });
  });
  document.querySelectorAll(".admin-inline-edit").forEach(function (form) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      submitForm(e.target);
    });
  });

  // 显隐
  document.querySelectorAll("[data-toggle]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      post(base + "/" + row.getAttribute("data-id") + "/toggle")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) location.reload();
          else pafishNotify((j && j.error) || "操作失败");
        })
        .catch(function () { pafishNotify("网络错误"); });
    });
  });

  // 上下移动
  document.querySelectorAll("[data-move]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      post(base + "/" + row.getAttribute("data-id") + "/move", { dir: btn.getAttribute("data-move") })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) location.reload(); })
        .catch(function () { pafishNotify("网络错误"); });
    });
  });

  // 删除（两步确认）
  document.querySelectorAll("[data-delete]").forEach(function (btn) {
    var armed = false;
    var original = btn.innerHTML;
    btn.addEventListener("click", function () {
      if (!armed) {
        armed = true;
        btn.textContent = "确认？";
        setTimeout(function () { armed = false; btn.innerHTML = original; }, 2500);
        return;
      }
      var row = btn.closest(".admin-list-row");
      var name = row.getAttribute("data-name");
      if (!confirm("确定删除链接「" + name + "」？")) return;
      post(base + "/" + row.getAttribute("data-id") + "/delete")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) location.reload();
          else pafishNotify((j && j.error) || "删除失败");
        })
        .catch(function () { pafishNotify("网络错误"); });
    });
  });
})();
</script>
