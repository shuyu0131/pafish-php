<?php
/**
 * 导航菜单管理：
 * - 顶部新建表单（含「外部链接（新窗口打开）」复选框）
 * - 列表行：名称 + 「已隐藏」badge + 「外部」badge + url 可点击预览
 * - 操作：↑/↓ 上下移动（边界禁用）、显隐、编辑（行内展开）、删除（两步确认）
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
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">导航菜单</h1>
    </div>
  </div>

  <!-- 新建表单 -->
  <div class="card admin-form-card">
    <h2 class="admin-card-title">添加导航项</h2>
    <form id="createForm" method="post" action="<?= e(url_to('/admin/nav/save')) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="admin-form-grid">
        <div class="admin-field">
          <span class="label">名称 *</span>
          <input class="input" type="text" name="label" placeholder="如：首页 / 关于" maxlength="100">
        </div>
        <div class="admin-field">
          <span class="label">地址 *</span>
          <input class="input" type="text" name="url" placeholder="/archives 或 https://…" maxlength="500">
        </div>
      </div>
      <label class="admin-check-row">
        <input type="checkbox" name="is_external" value="1" class="admin-check">
        <span>外部链接（新窗口打开）</span>
      </label>
      <p class="admin-field-hint">地址填 / 开头为站内页面，填 http(s):// 为外部链接。菜单按顺序显示在顶部导航与移动端菜单。</p>
      <div class="admin-cat-form-actions">
        <button type="submit" class="btn btn-primary">添加导航项</button>
      </div>
      <div class="admin-editor-error" hidden></div>
    </form>
  </div>

  <!-- 列表 -->
  <?php if ($count === 0): ?>
    <div class="admin-empty-list card">
      <?= admin_icon('menu', 32) ?>
      <p>还没有导航项，在上方添加第一个</p>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap admin-order-table-wrap">
      <table class="admin-table admin-order-table">
        <thead><tr><th>名称</th><th>地址</th><th>状态</th><th class="admin-col-ops">操作</th></tr></thead>
        <tbody>
      <?php foreach ($items as $i => $n): ?>
        <tr class="admin-list-row" data-id="<?= (int) $n['id'] ?>"
             data-label="<?= e($n['label']) ?>" data-url="<?= e($n['url']) ?>"
             data-external="<?= (int) $n['is_external'] ?>">
          <td data-label="名称"><span class="admin-list-name"><?= e($n['label']) ?></span></td>
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
            <button type="button" class="admin-icon-btn" data-edit title="编辑"><?= admin_icon('edit', 15) ?></button>
            <button type="button" class="admin-icon-btn admin-icon-danger" data-delete title="删除"><?= admin_icon('trash', 15) ?></button>
          </div></td>
        </tr>
        <tr class="admin-list-edit-row" hidden><td colspan="4"><div class="admin-list-edit">
            <form class="admin-inline-edit" method="post" novalidate>
              <?= csrf_field() ?>
              <div class="admin-form-grid">
                <div class="admin-field">
                  <span class="label">名称 *</span>
                  <input class="input" type="text" name="label" maxlength="100">
                </div>
                <div class="admin-field">
                  <span class="label">地址 *</span>
                  <input class="input" type="text" name="url" maxlength="500">
                </div>
              </div>
              <label class="admin-check-row">
                <input type="checkbox" name="is_external" value="1" class="admin-check">
                <span>外部链接（新窗口打开）</span>
              </label>
              <div class="admin-cat-form-actions">
                <button type="submit" class="btn btn-primary">保存修改</button>
                <button type="button" class="btn btn-ghost" data-edit-cancel>取消</button>
              </div>
              <div class="admin-editor-error" hidden></div>
            </form>
          </div></td></tr>
      <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

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

  document.getElementById("createForm").addEventListener("submit", function (e) {
    e.preventDefault();
    submitForm(e.target);
  });

  document.querySelectorAll("[data-edit]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      var editRow = row.nextElementSibling;
      var box = editRow.querySelector(".admin-list-edit");
      var open = !editRow.hidden;
      document.querySelectorAll(".admin-list-edit-row").forEach(function (b) { b.hidden = true; });
      if (open) return;
      editRow.hidden = false;
      var f = box.querySelector("form");
      f.action = base + "/" + row.getAttribute("data-id") + "/save";
      f.querySelector('[name="label"]').value = row.getAttribute("data-label");
      f.querySelector('[name="url"]').value = row.getAttribute("data-url");
      f.querySelector('[name="is_external"]').checked = row.getAttribute("data-external") === "1";
      box.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  });
  document.querySelectorAll("[data-edit-cancel]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      btn.closest(".admin-list-edit-row").hidden = true;
    });
  });
  document.querySelectorAll(".admin-inline-edit").forEach(function (form) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      submitForm(e.target);
    });
  });

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

  document.querySelectorAll("[data-move]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      post(base + "/" + row.getAttribute("data-id") + "/move", { dir: btn.getAttribute("data-move") })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) location.reload(); })
        .catch(function () { pafishNotify("网络错误"); });
    });
  });

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
      var label = row.getAttribute("data-label");
      (window.pafishConfirm ? window.pafishConfirm("确定删除导航项「" + label + "」？", { title: "删除导航项" }) : Promise.resolve(window.confirm("确定删除导航项「" + label + "」？"))).then(function (ok) {
        if (!ok) return;
        return post(base + "/" + row.getAttribute("data-id") + "/delete")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) location.reload();
          else pafishNotify((j && j.error) || "删除失败");
        })
        .catch(function () { pafishNotify("网络错误"); });
      });
    });
  });
})();
</script>
