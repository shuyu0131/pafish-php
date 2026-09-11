<?php
/**
 * 分类管理：
 * 左侧树形列表（└ 缩进、文章数、同级上移/下移/编辑/删除），右侧新建/编辑表单
 * 变量：$tree $counts $flatForSelect $disabledMap $role
 * 防自引用：编辑时父级下拉禁用"自身+后代"（disabledMap），后端 BFS 双保险
 */
$canEdit = in_array($role ?? '', ['ADMIN', 'EDITOR'], true);
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">分类管理</h1>
      <p class="admin-page-sub">共 <?= count($tree) ?> 个分类 · 支持父子层级与同级排序</p>
    </div>
  </div>

  <div class="admin-cat-layout">
    <!-- 左：树形列表 -->
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
            <?php if ($tree === []): ?>
              <tr><td colspan="3">
                <div class="admin-empty-list">
                  <?= admin_icon('folder', 32) ?>
                  <p>还没有分类，在右侧创建第一个</p>
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
                    data-parent="<?= $c['parent_id'] ? (int) $c['parent_id'] : '' ?>">
                  <td>
                    <div class="admin-cat-name">
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

    <!-- 右：新建/编辑表单 -->
    <div class="admin-cat-form card admin-form-card">
      <h2 class="admin-card-title" id="catFormTitle">新建分类</h2>
      <form id="catForm" method="post" action="<?= e(url_to('/admin/categories/save')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="fCatId" value="">
        <div class="admin-field">
          <span class="label">名称 *</span>
          <input class="input" type="text" id="fCatName" name="name" placeholder="分类名称" maxlength="100">
        </div>
        <div class="admin-field">
          <span class="label">地址</span>
          <input class="input" type="text" id="fCatSlug" name="slug" placeholder="自动根据名称生成" maxlength="100">
        </div>
        <div class="admin-field">
          <span class="label">描述</span>
          <textarea class="input" id="fCatDesc" name="description" rows="2" maxlength="500"></textarea>
        </div>
        <div class="admin-field">
          <span class="label">父分类</span>
          <select class="input" id="fCatParent" name="parent_id">
            <option value="">（无，作为顶级分类）</option>
            <?php foreach ($flatForSelect as $oc): ?>
              <option value="<?= (int) $oc['id'] ?>"><?= str_repeat('　', (int) $oc['depth']) . e($oc['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-cat-form-actions">
          <button type="submit" class="btn btn-primary">创建分类</button>
          <button type="button" class="btn btn-ghost" id="catFormCancel" hidden>取消</button>
        </div>
        <div class="admin-editor-error" hidden></div>
      </form>
    </div>
  </div>
</div>

<?php $catJs = [
    'csrf' => csrf_token(),
    'saveUrl' => url_to('/admin/categories/save'),
    'moveUrl' => url_to('/admin/categories'),
    'deleteUrl' => url_to('/admin/categories'),
    'disabledMap' => $disabledMap,
]; ?>
<script>
window.PAFISH_CAT_DATA = <?= json_encode($catJs) ?>;
(function () {
  "use strict";
  var D = window.PAFISH_CAT_DATA;
  var form = document.getElementById("catForm");
  var fId = document.getElementById("fCatId");
  var fName = document.getElementById("fCatName");
  var fSlug = document.getElementById("fCatSlug");
  var fDesc = document.getElementById("fCatDesc");
  var fParent = document.getElementById("fCatParent");
  var titleEl = document.getElementById("catFormTitle");
  var cancelBtn = document.getElementById("catFormCancel");
  var errorBox = document.querySelector(".admin-editor-error");

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
      fId.value = row.getAttribute("data-id");
      fName.value = row.getAttribute("data-name");
      fSlug.value = row.getAttribute("data-slug");
      fDesc.value = row.getAttribute("data-desc");
      var parentVal = row.getAttribute("data-parent") || "";
      Array.prototype.forEach.call(fParent.options, function (opt) {
        var id = opt.value;
        opt.disabled = id !== "" && (D.disabledMap[fId.value] || []).indexOf(Number(id)) !== -1;
      });
      fParent.value = parentVal;
      form.action = D.saveUrl.replace(/\/save$/, "/" + fId.value + "/save");
      titleEl.textContent = "编辑分类";
      cancelBtn.hidden = false;
      document.querySelector(".admin-cat-form-actions .btn-primary").textContent = "保存修改";
      form.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  });

  // 取消编辑：回到新建模式
  cancelBtn.addEventListener("click", function () {
    form.reset();
    fId.value = "";
    form.action = D.saveUrl;
    titleEl.textContent = "新建分类";
    cancelBtn.hidden = true;
    document.querySelector(".admin-cat-form-actions .btn-primary").textContent = "创建分类";
    Array.prototype.forEach.call(fParent.options, function (opt) { opt.disabled = false; });
  });

  // 新建/编辑提交（保持当前页，错误内联展示）
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    errorBox.hidden = true;
    if (!fName.value.trim()) { errorBox.textContent = "请填写分类名称"; errorBox.hidden = false; return; }
    var fd = new FormData(form);
    fd.set("_csrf", D.csrf);
    fetch(form.action, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) location.reload();
        else { errorBox.textContent = (j && j.error) || "保存失败"; errorBox.hidden = false; }
      })
      .catch(function () { errorBox.textContent = "网络错误"; errorBox.hidden = false; });
  });

  // 上移/下移（同级交换 sortOrder）
  document.querySelectorAll("[data-move-cat]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      post(D.moveUrl + "/" + btn.getAttribute("data-move-cat") + "/move", { dir: btn.getAttribute("data-dir") })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) location.reload(); else pafishNotify((j && j.error) || "操作失败"); })
        .catch(function () { pafishNotify("网络错误"); });
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
          if (j && j.ok) location.reload();
          else pafishNotify((j && j.error) || "删除失败");
        })
        .catch(function () { pafishNotify("网络错误"); });
      });
    });
  });
})();
</script>
