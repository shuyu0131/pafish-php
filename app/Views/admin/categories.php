<?php
/**
 * 分类管理：
 * 树形列表保留层级和排序，新建与编辑复用同一个弹窗表单。
 * 变量：$tree $counts $flatForSelect $disabledMap $role
 * 防自引用：编辑时父级下拉禁用"自身+后代"（disabledMap），后端 BFS 双保险
 */
$canEdit = in_array($role ?? '', ['ADMIN', 'EDITOR'], true);
?>
<div class="admin-stack admin-detail-page admin-categories-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">分类管理</h1>
      <p class="admin-page-sub">共 <?= count($tree) ?> 个分类</p>
    </div>
    <?php if ($canEdit): ?><button type="button" class="btn btn-primary" id="btnNewCategory"><?= admin_icon('plus', 15) ?>新建分类</button><?php endif; ?>
  </div>

  <div class="admin-category-list">
    <!-- 树形列表 -->
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
          <tbody data-admin-sort="<?= e(url_to('/admin/categories/reorder')) ?>" data-sort-csrf="<?= e(csrf_token()) ?>">
            <?php if ($tree === []): ?>
              <tr><td colspan="3">
                <div class="admin-empty-list">
                  <?= admin_icon('folder', 32) ?>
                  <p>还没有分类</p>
                </div>
              </td></tr>
            <?php else: ?>
              <?php foreach ($tree as $c): ?>
                <?php $depth = (int) $c['depth']; ?>
                <tr data-cat-row="<?= (int) $c['id'] ?>"
                    data-id="<?= (int) $c['id'] ?>"
                    data-name="<?= e($c['name']) ?>"
                    data-slug="<?= e($c['slug']) ?>"
                    data-desc="<?= e($c['description'] ?? '') ?>"
                    data-seo-title="<?= e($c['seo_title'] ?? '') ?>"
                    data-meta-description="<?= e($c['meta_description'] ?? '') ?>"
                    data-cover="<?= e($c['cover_url'] ?? '') ?>"
                    data-parent="<?= $c['parent_id'] ? (int) $c['parent_id'] : '' ?>">
                  <td>
                    <div class="admin-cat-name">
                      <button type="button" class="admin-icon-btn admin-sort-handle" aria-label="拖动排序" title="拖动排序"><svg width="14" height="18" viewBox="0 0 14 18" aria-hidden="true" fill="currentColor"><circle cx="4" cy="4" r="1.2"/><circle cx="10" cy="4" r="1.2"/><circle cx="4" cy="9" r="1.2"/><circle cx="10" cy="9" r="1.2"/><circle cx="4" cy="14" r="1.2"/><circle cx="10" cy="14" r="1.2"/></svg></button>
                      <?php if ($depth > 0): ?>
                        <span class="admin-tree-indent" style="width:<?= $depth * 18 ?>px"></span>
                        <span class="admin-tree-branch">└</span>
                      <?php endif; ?>
                      <span class="admin-cat-name-text"><?= e($c['name']) ?></span>
                      <span class="admin-muted">/<?= e($c['slug']) ?></span>
                    </div>
                    <?php if (!empty($c['description'])): ?>
                      <div class="admin-post-meta"><?= e($c['description']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="admin-col-sm">
                    <span class="badge"><?= (int) ($counts[(int) $c['id']] ?? 0) ?></span>
                  </td>
                  <td class="admin-col-ops">
                    <div class="admin-row-ops">
                      <button type="button" class="admin-icon-btn" data-move-cat="<?= (int) $c['id'] ?>" data-dir="up" title="上移"><?= admin_icon('chevron-up', 15) ?></button>
                      <button type="button" class="admin-icon-btn" data-move-cat="<?= (int) $c['id'] ?>" data-dir="down" title="下移"><?= admin_icon('chevron-down', 15) ?></button>
                      <button type="button" class="admin-icon-btn" data-edit-cat="<?= (int) $c['id'] ?>" title="编辑"><?= admin_icon('edit', 15) ?></button>
                      <button type="button" class="admin-icon-btn admin-icon-danger" data-delete-cat="<?= (int) $c['id'] ?>"
                              data-name="<?= e($c['name']) ?>" title="删除"><?= admin_icon('trash', 15) ?></button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<div class="admin-modal-backdrop" id="categoryModal" hidden>
  <div class="admin-modal admin-entity-modal" role="dialog" aria-modal="true" aria-labelledby="catFormTitle">
    <div class="admin-modal-head">
      <h2 class="admin-modal-title" id="catFormTitle">新建分类</h2>
      <button type="button" class="admin-icon-btn" data-modal-close aria-label="关闭分类窗口"><?= admin_icon('x', 16) ?></button>
    </div>
      <form id="catForm" method="post" action="<?= e(url_to('/admin/categories/save')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="fCatId" value="">
        <div class="admin-modal-body">
        <div class="admin-field">
          <label class="label" for="fCatName">名称 <span aria-hidden="true">*</span></label>
          <input class="input" type="text" id="fCatName" name="name" placeholder="分类名称" maxlength="100" required>
        </div>
        <div class="admin-field">
          <label class="label" for="fCatSlug">地址</label>
          <input class="input" type="text" id="fCatSlug" name="slug" placeholder="自动根据名称生成" maxlength="100">
        </div>
        <div class="admin-field">
          <label class="label" for="fCatDesc">描述</label>
          <textarea class="input" id="fCatDesc" name="description" rows="2" maxlength="500"></textarea>
        </div>
        <div class="admin-field">
          <label class="label" for="categoryCover">分类封面</label>
          <?php $imageId = 'categoryCover'; $imageName = 'cover_url'; $imageValue = ''; require __DIR__ . '/partials/image-field.php'; ?>
        </div>
        <div class="admin-field">
          <label class="label" for="fCatParent">父分类</label>
          <select class="input" id="fCatParent" name="parent_id">
            <option value="">（无，作为顶级分类）</option>
            <?php foreach ($flatForSelect as $oc): ?>
              <option value="<?= (int) $oc['id'] ?>"><?= str_repeat('　', (int) $oc['depth']) . e($oc['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php $seoValues = []; $seoPrefix = 'category'; require __DIR__ . '/partials/seo-fields.php'; ?>
        <div class="admin-editor-error" role="alert" hidden></div>
        </div>
        <div class="admin-modal-actions admin-cat-form-actions">
          <button type="button" class="btn btn-ghost" id="catFormCancel" data-modal-close>取消</button>
          <button type="submit" class="btn btn-primary">创建分类</button>
        </div>
      </form>
  </div>
</div>

<?php $catJs = [
    'csrf' => csrf_token(),
    'saveUrl' => url_to('/admin/categories/save'),
    'moveUrl' => url_to('/admin/categories/0/move'),
    'deleteUrl' => url_to('/admin/categories'),
    'disabledMap' => $disabledMap,
]; ?>
<script>
window.PAFISH_CAT_DATA = <?= json_encode($catJs) ?>;
(function () {
  "use strict";
  var D = window.PAFISH_CAT_DATA;
  var form = document.getElementById("catForm");
  var categoryModal = document.getElementById("categoryModal");
  var fId = document.getElementById("fCatId");
  var fName = document.getElementById("fCatName");
  var fSlug = document.getElementById("fCatSlug");
  var fDesc = document.getElementById("fCatDesc");
  var fParent = document.getElementById("fCatParent");
  var titleEl = document.getElementById("catFormTitle");
  var cancelBtn = document.getElementById("catFormCancel");
  var errorBox = form.querySelector(".admin-editor-error");
  var saving = false;

  function resetForm() {
    form.reset();
    fId.value = "";
    form.action = D.saveUrl;
    titleEl.textContent = "新建分类";
    errorBox.hidden = true;
    form.querySelectorAll('details').forEach(function (details) { details.open = false; });
    form.querySelector(".btn-primary").textContent = "创建分类";
    Array.prototype.forEach.call(fParent.options, function (opt) { opt.disabled = false; });
    fParent.dispatchEvent(new Event("change", { bubbles: true }));
  }
  var createButton = document.getElementById("btnNewCategory");
  if (createButton) createButton.addEventListener("click", function () {
    if (saving) return;
    resetForm();
    categoryModal.hidden = false;
  });

  function showError(message) {
    errorBox.textContent = message;
    errorBox.hidden = false;
  }

  function post(url, body) {
    var fd = new FormData();
    Object.keys(body || {}).forEach(function (k) { fd.append(k, body[k]); });
    fd.append("_csrf", D.csrf);
    return fetch(url, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } });
  }

  // 编辑：填充表单 + 切换 action + 禁用自身/后代父级选项
  document.querySelectorAll("[data-edit-cat]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest("[data-cat-row]");
      if (saving) return;
      resetForm();
      fId.value = row.getAttribute("data-id");
      fName.value = row.getAttribute("data-name");
      fSlug.value = row.getAttribute("data-slug");
      fDesc.value = row.getAttribute("data-desc");
      form.elements.seo_title.value = row.getAttribute("data-seo-title") || "";
      form.elements.meta_description.value = row.getAttribute("data-meta-description") || "";
      form.elements.cover_url.value = row.getAttribute("data-cover") || "";
      form.elements.cover_url.dispatchEvent(new Event("input", { bubbles: true }));
      var parentVal = row.getAttribute("data-parent") || "";
      Array.prototype.forEach.call(fParent.options, function (opt) {
        var id = opt.value;
        opt.disabled = id !== "" && (D.disabledMap[fId.value] || []).indexOf(Number(id)) !== -1;
      });
      fParent.value = parentVal;
      fParent.dispatchEvent(new Event("change", { bubbles: true }));
      form.action = D.saveUrl.replace(/\/save$/, "/" + fId.value + "/save");
      titleEl.textContent = "编辑分类";
      document.querySelector(".admin-cat-form-actions .btn-primary").textContent = "保存修改";
      categoryModal.hidden = false;
    });
  });

  // 取消编辑，下次打开时重新读取对应分类。
  cancelBtn.addEventListener("click", function () {
    if (!saving) resetForm();
  });

  // 新建/编辑提交（保持当前页，错误内联展示）
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    if (saving) return;
    errorBox.hidden = true;
    if (!fName.value.trim()) { showError("请填写分类名称"); fName.focus(); return; }
    var fd = new FormData(form);
    fd.set("_csrf", D.csrf);
    saving = true;
    categoryModal.setAttribute("data-modal-static", "true");
    form.querySelector('[type="submit"]').disabled = true;
    categoryModal.querySelectorAll("[data-modal-close]").forEach(function (button) { button.disabled = true; });
    form.setAttribute("aria-busy", "true");
    fetch(form.action, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) {
          pafishToastReload(fId.value ? "分类已更新" : "分类已创建", "success");
        } else { showError((j && j.error) || "保存失败"); }
      })
      .catch(function () { showError("网络错误，请重试"); })
      .finally(function () {
        saving = false;
        categoryModal.removeAttribute("data-modal-static");
        form.querySelector('[type="submit"]').disabled = false;
        categoryModal.querySelectorAll("[data-modal-close]").forEach(function (button) { button.disabled = false; });
        form.removeAttribute("aria-busy");
      });
  });

  // 上移/下移（同级交换 sortOrder）
  document.querySelectorAll("[data-move-cat]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var moveUrl = D.moveUrl.replace("/0/move", "/" + btn.getAttribute("data-move-cat") + "/move");
      post(moveUrl, { dir: btn.getAttribute("data-dir") })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) pafishToastReload("分类顺序已更新", "success"); else pafishNotify((j && j.error) || "操作失败", true); })
        .catch(function () { pafishNotify("网络错误", true); });
    });
  });

  // 删除（子分类与文章自动置空）
  document.querySelectorAll("[data-delete-cat]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var name = btn.getAttribute("data-name") || "";
      (window.pafishConfirm ? window.pafishConfirm("确定删除分类「" + name + "」？其子分类将变为顶级分类，文章将变为未分类。", { title: "删除分类" }) : Promise.resolve(window.confirm("确定删除分类「" + name + "」？"))).then(function (ok) {
        if (!ok) return;
        return post(D.deleteUrl + "/" + btn.getAttribute("data-delete-cat") + "/delete")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) pafishToastReload("分类已删除", "success");
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
