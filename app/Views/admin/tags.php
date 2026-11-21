<?php
/**
 * 标签管理：
 * 标签列表；新建与编辑复用弹窗
 * 变量：$tags $role
 */
?>
<div class="admin-stack admin-list-page admin-detail-page admin-tags-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e(t('admin.tags')) ?></h1>
      <p class="admin-page-sub"><?= e(t('admin.total')) ?> <?= count($tags) ?> <?= e(t('admin.tags_sorted_by_name')) ?></p>
    </div>
    <button type="button" class="btn btn-primary" data-entity-new="tagsModal"><?= admin_icon('plus', 15) ?><?= e(t('admin.new_tag')) ?></button>
  </div>

  <div class="admin-category-list">
    <!-- 左：列表 -->
    <div class="admin-cat-tree">
      <div class="admin-table-wrap">
        <table class="admin-table admin-tags-table">
          <thead>
            <tr>
              <th><?= e(t('common.name')) ?></th>
              <th class="admin-col-sm"><?= e(t('common.post')) ?></th>
              <th class="admin-col-ops"><?= e(t('common.actions')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($tags === []): ?>
              <tr><td colspan="3">
                <div class="admin-empty-list">
                  <?= admin_icon('tags', 32) ?>
                  <p><?= e(t('admin.no_tags_yet')) ?></p>
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
                      <button type="button" class="admin-icon-btn" data-entity-edit="tagsModal" title="<?= e(t('common.edit')) ?>"><?= admin_icon('edit', 15) ?></button>
                      <button type="button" class="admin-icon-btn admin-icon-danger" data-delete-tag="<?= (int) $t['id'] ?>"
                              data-name="<?= e($t['name']) ?>" title="<?= e(t('common.delete')) ?>"><?= admin_icon('trash', 15) ?></button>
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
          <label class="label" for="fTagName"><?= e(t('admin.name')) ?></label>
          <input class="input" type="text" id="fTagName" name="name" placeholder="<?= e(t('admin.tag_name')) ?>" maxlength="100" required>
        </div>
        <div class="admin-field">
          <label class="label" for="fTagSlug"><?= e(t('admin.url')) ?></label>
          <input class="input" type="text" id="fTagSlug" name="slug" placeholder="<?= e(t('admin.leave_blank_to_generate_an_english_or_pinyin')) ?>" maxlength="100">
        </div>
        <div class="admin-field">
          <label class="label" for="tagDescription"><?= e(t('admin.description')) ?></label>
          <textarea class="input" id="tagDescription" name="description" rows="2" maxlength="500"></textarea>
        </div>
        <?php $seoValues = []; $seoPrefix = 'tag'; require __DIR__ . '/partials/seo-fields.php'; ?>
<?php
$entityFields = (string) ob_get_clean();
$entityModalId = 'tagsModal';
$entityFormId = 'tagForm';
$entityConfig = ['createTitle'=>t('admin.new_tag'), 'editTitle'=>t('admin.edit_tag'), 'savedMessage'=>t('admin.tag_saved'),
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
      (window.pafishConfirm ? window.pafishConfirm(window.pafishT("js.confirm_delete_tag", {name: name}), { title: window.pafishT("js.delete_tag_ff6fe4") }) : Promise.resolve(window.confirm(window.pafishT("js.confirm_delete_tag", {name: name})))).then(function (ok) {
        if (!ok) return;
        return post(<?= json_encode(url_to('/admin/tags')) ?> + "/" + btn.getAttribute("data-delete-tag") + "/delete")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) pafishToastReload(window.pafishT("js.tag_deleted"), "success");
          else pafishNotify((j && j.error) || window.pafishT("js.delete_failed"), true);
        })
        .catch(function () { pafishNotify(window.pafishT("js.network_error"), true); });
      });
    });
  });
})();
</script>
