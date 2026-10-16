<?php
/**
 * 标签管理：
 * 标签列表；新建与编辑复用弹窗
 * 变量：$tags $role
 */
?>
<div class="admin-stack admin-detail-page admin-tags-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">标签管理</h1>
      <p class="admin-page-sub">共 <?= count($tags) ?> 个标签 · 按名称排序</p>
    </div>
    <button type="button" class="btn btn-primary" data-entity-new="tagsModal"><?= admin_icon('plus', 15) ?>新建标签</button>
  </div>

  <div class="admin-category-list">
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
                  <p>还没有标签</p>
                </div>
              </td></tr>
            <?php else: ?>
              <?php foreach ($tags as $t): ?>
                <tr data-tag-row="<?= (int) $t['id'] ?>"
                    data-id="<?= (int) $t['id'] ?>"
                    data-name="<?= e($t['name']) ?>"
                    data-slug="<?= e($t['slug']) ?>"
                    data-desc="<?= e($t['description'] ?? '') ?>"
                    data-seo-title="<?= e($t['seo_title'] ?? '') ?>"
                    data-meta-description="<?= e($t['meta_description'] ?? '') ?>">
                  <td>
                    <span class="admin-cat-name-text"><?= e($t['name']) ?></span>
                    <span class="admin-muted">/<?= e($t['slug']) ?></span>
                  </td>
                  <td class="admin-col-sm"><span class="badge"><?= (int) $t['post_count'] ?></span></td>
                  <td class="admin-col-ops">
                    <div class="admin-row-ops">
                      <button type="button" class="admin-icon-btn" data-entity-edit="tagsModal" title="编辑"><?= admin_icon('edit', 15) ?></button>
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

  </div>
</div>

<?php ob_start(); ?>

        <div class="admin-field">
          <label class="label" for="fTagName">名称 *</label>
          <input class="input" type="text" id="fTagName" name="name" placeholder="标签名称" maxlength="100" required>
        </div>
        <div class="admin-field">
          <label class="label" for="fTagSlug">地址</label>
          <input class="input" type="text" id="fTagSlug" name="slug" placeholder="自动根据名称生成" maxlength="100">
        </div>
        <div class="admin-field">
          <label class="label" for="tagDescription">描述</label>
          <textarea class="input" id="tagDescription" name="description" rows="2" maxlength="500"></textarea>
        </div>
        <?php $seoValues = []; $seoPrefix = 'tag'; require __DIR__ . '/partials/seo-fields.php'; ?>
<?php
$entityFields = (string) ob_get_clean();
$entityModalId = 'tagsModal';
$entityFormId = 'tagForm';
$entityConfig = ['createTitle'=>'新建标签', 'editTitle'=>'编辑标签', 'savedMessage'=>'标签已保存',
    'savePath'=>'/admin/tags/save', 'basePath'=>'/admin/tags', 'fields'=>['name'=>'name','slug'=>'slug','description'=>'desc','seo_title'=>'seo-title','meta_description'=>'meta-description']];
require __DIR__ . '/partials/entity-modal.php';
?>
<script src="<?= e(asset_url('/js/admin-entity-forms.js')) ?>" defer></script>

<?php $tagCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($tagCsrf) ?>;
  function post(url, body) {
    var fd = new FormData();
    Object.keys(body || {}).forEach(function (k) { fd.append(k, body[k]); });
    fd.append("_csrf", CSRF);
    return fetch(url, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } });
  }

  document.querySelectorAll("[data-delete-tag]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var name = btn.getAttribute("data-name") || "";
      (window.pafishConfirm ? window.pafishConfirm("确定删除标签「" + name + "」？", { title: "删除标签" }) : Promise.resolve(window.confirm("确定删除标签「" + name + "」？"))).then(function (ok) {
        if (!ok) return;
        return post(<?= json_encode(url_to('/admin/tags')) ?> + "/" + btn.getAttribute("data-delete-tag") + "/delete")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) pafishToastReload("标签已删除", "success");
          else pafishNotify((j && j.error) || "删除失败", true);
        })
        .catch(function () { pafishNotify("网络错误", true); });
      });
    });
  });
})();
</script>
