<?php
/**
 * 标签管理（对齐 Node app/admin/tags/ 列表 + 表单）：
 * 左侧列表（name ASC + 文章数 + 编辑/删除），右侧新建/编辑表单
 * 变量：$tags $role
 */
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">标签管理</h1>
    </div>
  </div>

  <div class="admin-cat-layout">
    <!-- 左：列表 -->
    <div class="admin-cat-tree">
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>名称</th>
              <th class="admin-col-sm">文章</th>
              <th class="admin-col-ops">操作</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($tags === []): ?>
              <tr><td colspan="3">
                <div class="admin-empty-list">
                  <?= admin_icon('tags', 32) ?>
                  <p>还没有标签，在右侧创建第一个</p>
                </div>
              </td></tr>
            <?php else: ?>
              <?php foreach ($tags as $t): ?>
                <tr data-tag-row="<?= (int) $t['id'] ?>"
                    data-id="<?= (int) $t['id'] ?>"
                    data-name="<?= e($t['name']) ?>"
                    data-slug="<?= e($t['slug']) ?>">
                  <td>
                    <span class="admin-cat-name-text"><?= e($t['name']) ?></span>
                    <span class="admin-muted">/<?= e($t['slug']) ?></span>
                  </td>
                  <td class="admin-col-sm"><span class="badge"><?= (int) $t['post_count'] ?></span></td>
                  <td class="admin-col-ops">
                    <div class="admin-row-ops">
                      <button type="button" class="admin-icon-btn" data-edit-tag="<?= (int) $t['id'] ?>" title="编辑"><?= admin_icon('edit', 15) ?></button>
                      <button type="button" class="admin-icon-btn admin-icon-danger" data-delete-tag="<?= (int) $t['id'] ?>"
                              data-name="<?= e($t['name']) ?>" title="删除"><?= admin_icon('trash', 15) ?></button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 右：新建/编辑表单 -->
    <div class="admin-cat-form card admin-form-card">
      <h2 class="admin-card-title" id="tagFormTitle">新建标签</h2>
      <form id="tagForm" method="post" action="<?= e(url_to('/admin/tags/save')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="fTagId" value="">
        <div class="admin-field">
          <span class="label">名称 *</span>
          <input class="input" type="text" id="fTagName" name="name" placeholder="标签名称" maxlength="100">
        </div>
        <div class="admin-field">
          <span class="label">地址</span>
          <input class="input" type="text" id="fTagSlug" name="slug" placeholder="自动根据名称生成" maxlength="100">
        </div>
        <div class="admin-cat-form-actions">
          <button type="submit" class="btn btn-primary">创建标签</button>
          <button type="button" class="btn btn-ghost" id="tagFormCancel" hidden>取消</button>
        </div>
        <div class="admin-editor-error" hidden></div>
      </form>
    </div>
  </div>
</div>

<?php $tagCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($tagCsrf) ?>;
  var form = document.getElementById("tagForm");
  var fId = document.getElementById("fTagId");
  var fName = document.getElementById("fTagName");
  var fSlug = document.getElementById("fTagSlug");
  var titleEl = document.getElementById("tagFormTitle");
  var cancelBtn = document.getElementById("tagFormCancel");
  var errorBox = document.querySelector(".admin-editor-error");
  var submitBtn = document.querySelector("#tagForm .btn-primary");

  function post(url, body) {
    var fd = new FormData();
    Object.keys(body || {}).forEach(function (k) { fd.append(k, body[k]); });
    fd.append("_csrf", CSRF);
    return fetch(url, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } });
  }

  document.querySelectorAll("[data-edit-tag]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest("[data-tag-row]");
      fId.value = row.getAttribute("data-id");
      fName.value = row.getAttribute("data-name");
      fSlug.value = row.getAttribute("data-slug");
      form.action = <?= json_encode(url_to('/admin/tags')) ?> + "/" + fId.value + "/save";
      titleEl.textContent = "编辑标签";
      submitBtn.textContent = "保存修改";
      cancelBtn.hidden = false;
      form.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  });

  cancelBtn.addEventListener("click", function () {
    form.reset();
    fId.value = "";
    form.action = <?= json_encode(url_to('/admin/tags/save')) ?>;
    titleEl.textContent = "新建标签";
    submitBtn.textContent = "创建标签";
    cancelBtn.hidden = true;
  });

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    errorBox.hidden = true;
    if (!fName.value.trim()) { errorBox.textContent = "请填写标签名称"; errorBox.hidden = false; return; }
    var fd = new FormData(form);
    fd.set("_csrf", CSRF);
    fetch(form.action, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) location.reload();
        else { errorBox.textContent = (j && j.error) || "保存失败"; errorBox.hidden = false; }
      })
      .catch(function () { errorBox.textContent = "网络错误"; errorBox.hidden = false; });
  });

  document.querySelectorAll("[data-delete-tag]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var name = btn.getAttribute("data-name") || "";
      if (!confirm("确定删除标签「" + name + "」？")) return;
      post(<?= json_encode(url_to('/admin/tags')) ?> + "/" + btn.getAttribute("data-delete-tag") + "/delete")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) location.reload();
          else alert((j && j.error) || "删除失败");
        })
        .catch(function () { alert("网络错误"); });
    });
  });
})();
</script>
