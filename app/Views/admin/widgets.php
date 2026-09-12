<?php
/**
 * 侧边栏组件管理：
 * - 顶部新建表单：类型下拉（6 种）+ 标题（留空用默认）+ content（仅 custom 显示）
 * - 列表行：标题（无标题显示类型名）+ 类型徽标 + 「已隐藏」badge + custom 预览首行
 * - 操作：↑/↓ 上下移动（边界禁用）、显隐、编辑（行内展开）、删除（两步确认）
 * 变量：$items $types $typeLabels $defaultTitles $role
 */
$count = count($items);
$hidden = 0;
foreach ($items as $w) {
    if ((int) $w['visible'] === 0) {
        $hidden++;
    }
}
$typeJson = json_encode($defaultTitles, JSON_UNESCAPED_UNICODE);
$labelJson = json_encode($typeLabels, JSON_UNESCAPED_UNICODE);
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">侧边栏组件</h1>
      <p class="admin-page-sub">共 <?= $count ?> 个（含隐藏 <?= $hidden ?> 个）· 组件按顺序显示在左侧栏</p>
    </div>
  </div>

  <!-- 新建表单 -->
  <div class="card admin-form-card">
    <h2 class="admin-card-title">添加组件</h2>
    <form id="createForm" method="post" action="<?= e(url_to('/admin/widgets/save')) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="admin-form-grid">
        <div class="admin-field">
          <span class="label">类型 *</span>
          <select class="input" name="type" id="newType">
            <?php foreach ($types as $t): ?>
              <option value="<?= e($t) ?>" data-default="<?= e($defaultTitles[$t]) ?>"><?= e($typeLabels[$t]) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-field">
          <span class="label">标题（留空用默认）</span>
          <input class="input" type="text" name="title" id="newTitle" placeholder="分类" maxlength="100">
        </div>
      </div>
      <div class="admin-field" id="newContentField">
        <span class="label">内容 *</span>
        <textarea class="input" name="content" rows="4" maxlength="5000"
                  placeholder="每行一段，支持 [文字](https://链接) 格式"></textarea>
      </div>
      <p class="admin-field-hint">分类 / 标签 / 最新文章 / 热门文章 / 最新评论为自动内容，自定义文本由你填写。</p>
      <div class="admin-cat-form-actions">
        <button type="submit" class="btn btn-primary">添加组件</button>
      </div>
      <div class="admin-editor-error" hidden></div>
    </form>
  </div>

  <!-- 列表 -->
  <?php if ($count === 0): ?>
    <div class="admin-empty-list card">
      <?= admin_icon('layout', 32) ?>
      <p>还没有组件，在上方添加第一个</p>
    </div>
  <?php else: ?>
    <div class="admin-list">
      <?php foreach ($items as $i => $w): ?>
        <?php
        $wTitle = trim((string) ($w['title'] ?? ''));
        $showTitle = $wTitle !== '' ? $wTitle : $defaultTitles[$w['type']] ?? $w['type'];
        ?>
        <div class="admin-list-row card" data-id="<?= (int) $w['id'] ?>"
             data-type="<?= e($w['type']) ?>" data-title="<?= e($wTitle) ?>"
             data-content="<?= e((string) ($w['content'] ?? '')) ?>">
          <div class="admin-list-main">
            <span class="admin-list-name"><?= e($showTitle) ?></span>
            <span class="badge badge-accent"><?= e($typeLabels[$w['type']] ?? $w['type']) ?></span>
            <?php if ((int) $w['visible'] === 0): ?>
              <span class="badge badge-danger">已隐藏</span>
            <?php endif; ?>
            <?php if ($w['type'] === 'custom' && $wTitle === ''): ?>
              <span class="admin-muted">（自定义文本）</span>
            <?php endif; ?>
            <?php if ($w['type'] === 'custom' && trim((string) $w['content']) !== ''): ?>
              <div class="admin-muted"><?= e(mb_strimwidth(str_replace("\n", ' ', trim((string) $w['content'])), 0, 60, '…')) ?></div>
            <?php endif; ?>
          </div>
          <div class="admin-list-ops">
            <button type="button" class="admin-icon-btn" data-move="up" title="上移" <?= $i === 0 ? 'disabled' : '' ?>><?= admin_icon('chevron-up', 15) ?></button>
            <button type="button" class="admin-icon-btn" data-move="down" title="下移" <?= $i === $count - 1 ? 'disabled' : '' ?>><?= admin_icon('chevron-down', 15) ?></button>
            <button type="button" class="admin-icon-btn" data-toggle title="<?= (int) $w['visible'] === 1 ? '隐藏' : '显示' ?>">
              <?= admin_icon((int) $w['visible'] === 1 ? 'eye' : 'eye-off', 15) ?>
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
                  <span class="label">类型 *</span>
                  <select class="input" name="type">
                    <?php foreach ($types as $t): ?>
                      <option value="<?= e($t) ?>" data-default="<?= e($defaultTitles[$t]) ?>"><?= e($typeLabels[$t]) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="admin-field">
                  <span class="label">标题（留空用默认）</span>
                  <input class="input" type="text" name="title" maxlength="100">
                </div>
              </div>
              <div class="admin-field">
                <span class="label">内容</span>
                <textarea class="input" name="content" rows="4" maxlength="5000"
                          placeholder="每行一段，支持 [文字](https://链接) 格式"></textarea>
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

<?php $widgetsCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($widgetsCsrf) ?>;
  var base = <?= json_encode(url_to('/admin/widgets')) ?>;
  var defaults = <?= $typeJson ?>;
  var labels = <?= $labelJson ?>;

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
        if (j && j.ok) pafishToastReload("组件已保存", "success");
        else { err.textContent = (j && j.error) || "保存失败"; err.hidden = false; pafishNotify((j && j.error) || "保存失败", true); }
      })
      .catch(function () { err.textContent = "网络错误"; err.hidden = false; pafishNotify("网络错误", true); });
  }

  // 类型切换：custom 才显示内容框；切换时标题为空自动填默认标题
  function syncType(select, titleInput, contentField, isEdit, fillTitle) {
    var t = select.value;
    var custom = t === "custom";
    if (fillTitle && !isEdit && titleInput && !titleInput.value.trim()) {
      titleInput.value = defaults[t] || "";
      titleInput.placeholder = defaults[t] || "";
    } else if (titleInput) {
      titleInput.placeholder = defaults[t] || "";
    }
    if (contentField) contentField.style.display = custom ? "" : "none";
    var label = contentField ? contentField.querySelector(".label") : null;
    if (label) label.textContent = "内容" + (custom ? " *" : "");
  }
  function wireForm(form, isEdit) {
    var select = form.querySelector('[name="type"]');
    var title = form.querySelector('[name="title"]');
    var contentField = form.querySelector(".admin-field > textarea[name=content]").closest(".admin-field");
    select.addEventListener("change", function () {
      syncType(select, title, contentField, isEdit, true);
    });
    syncType(select, title, contentField, isEdit, false);
  }
  wireForm(document.getElementById("createForm"), false);

  document.getElementById("createForm").addEventListener("submit", function (e) {
    e.preventDefault();
    submitForm(e.target);
  });

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
      f.querySelector('[name="type"]').value = row.getAttribute("data-type");
      f.querySelector('[name="title"]').value = row.getAttribute("data-title");
      f.querySelector('[name="content"]').value = row.getAttribute("data-content");
      wireForm(f, true);
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

  document.querySelectorAll("[data-toggle]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      post(base + "/" + row.getAttribute("data-id") + "/toggle")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) pafishToastReload("组件状态已更新", "success");
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
        .then(function (j) { if (j && j.ok) pafishToastReload("组件顺序已更新", "success"); else pafishNotify((j && j.error) || "操作失败", true); })
        .catch(function () { pafishNotify("网络错误", true); });
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
      var name = row.querySelector(".admin-list-name").textContent.trim();
      (window.pafishConfirm ? window.pafishConfirm("确定删除组件「" + name + "」？", { title: "删除组件" }) : Promise.resolve(window.confirm("确定删除组件「" + name + "」？"))).then(function (ok) {
        if (!ok) return;
        return post(base + "/" + row.getAttribute("data-id") + "/delete")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) pafishToastReload("组件已删除", "success");
          else pafishNotify((j && j.error) || "删除失败", true);
        })
        .catch(function () { pafishNotify("网络错误", true); });
      });
    });
  });
})();
</script>
