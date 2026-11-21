<?php
/**
 * 页面编辑器：
 * 左侧标题 + Markdown 编辑器（工具栏/分栏预览/插入图片），右侧设置栏（slug/模板/状态）
 * 变量：$isEdit $page $templateOptions
 */
$pageId = $isEdit ? (int) $page['id'] : 0;
$status = $isEdit ? (string) $page['status'] : 'DRAFT';
$slug = $isEdit ? (string) $page['slug'] : '';
$title = $isEdit ? (string) $page['title'] : '';
$content = $isEdit ? (string) $page['content'] : '';
$template = $isEdit ? (string) $page['template'] : 'default';
?>
<div class="admin-editor admin-editor-workspace">
  <form id="pageForm" method="post"
        action="<?= e(url_to($isEdit ? '/admin/pages/' . $pageId . '/save' : '/admin/pages/save')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="slug_auto" id="fSlugAuto" value="<?= $isEdit ? '0' : '1' ?>">

    <header class="admin-editor-header">
      <div class="admin-editor-heading">
        <h1 class="admin-h1"><?= $isEdit ? t('admin.edit_page') : t('admin.new_page') ?></h1>
      </div>
      <div class="admin-editor-header-actions">
        <button type="submit" class="btn btn-outline" data-save="draft"><?= e(t('admin.save_draft')) ?></button>
        <button type="submit" class="btn btn-primary admin-publish-primary" data-save="publish"><?= $status === 'PUBLISHED' ? t('admin.save_changes') : t('admin.publish') ?></button>
      </div>
    </header>
    <div class="admin-editor-error" role="alert" hidden></div>
    <div class="admin-editor-grid">
      <div class="admin-editor-main">
        <div class="admin-editor-writing">
        <div class="admin-editor-title-wrap">
          <input class="admin-editor-title" type="text" id="fTitle" name="title" aria-label="<?= e(t('admin.page_title')) ?>"
                 value="<?= e($title) ?>" placeholder="<?= e(t('admin.page_title')) ?>" maxlength="100">
        </div>

        <?php $editorType = 'page'; require __DIR__ . '/partials/editor-controls.php'; ?>
        <!-- 正文编辑器 -->
        <div class="admin-md-editor">
          <div id="markdownMount"></div>
          <input type="hidden" id="fContentFormat" name="content_format" data-editor-new="<?= $isEdit ? '0' : '1' ?>" value="<?= e($page['content_format'] ?? 'markdown') ?>">
          <textarea id="fContent" name="content" hidden
                    maxlength="16000000"><?= e($content) ?></textarea>
        </div>
        </div>
      </div>

      <!-- 右列：设置 -->
      <div class="admin-editor-side">
        <div class="card admin-form-card admin-settings-card">
          <div class="admin-editor-card-heading">
            <strong><?= e(t('admin.publishing_settings')) ?></strong>
          </div>
          <div class="admin-field">
            <label class="label" for="fStatus"><?= e(t('common.status')) ?></label>
            <select id="fStatus" name="status" class="input">
              <option value="DRAFT" <?= $status === 'DRAFT' ? 'selected' : '' ?>><?= e(t('admin.draft')) ?></option>
              <option value="PUBLISHED" <?= $status === 'PUBLISHED' ? 'selected' : '' ?>><?= e(t('admin.publish')) ?></option>
            </select>
          </div>
          <div class="admin-field">
            <label class="label" for="fSlug"><?= e(t('admin.page_url')) ?></label>
            <input class="input" id="fSlug" name="slug" value="<?= e($slug) ?>"
                   placeholder="<?= e(t('admin.leave_blank_to_generate_an_english_or_pinyin')) ?>" maxlength="100">
            <span class="admin-field-hint"><?= e(t('admin.new_slugs_default_to_lowercase_english_or_pinyin')) ?></span>
          </div>
          <input type="hidden" name="allow_comments" value="0">
          <label class="admin-check">
            <input type="checkbox" id="fAllowComments" name="allow_comments" value="1" <?= ($page['allow_comments'] ?? 1) ? 'checked' : '' ?>>
            <span><?= e(t('admin.allow_comments')) ?></span>
          </label>
          <?php $templateUnavailable = !array_key_exists($template, $templateOptions); ?>
          <?php if (count($templateOptions) > 1 || $templateUnavailable): ?>
            <div class="admin-field">
              <label class="label" for="pageTemplate"><?= e(t('admin.template')) ?></label>
              <select id="pageTemplate" name="template" class="input">
                <?php if ($templateUnavailable): ?>
                  <option value="<?= e($template) ?>" selected><?= e($template) ?><?= e(t('admin.unavailable')) ?></option>
                <?php endif; ?>
                <?php foreach ($templateOptions as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $template === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($templateUnavailable): ?>
                <span class="admin-field-hint"><?= e(t('admin.the_original_template_is_unavailable_the_default_layout')) ?></span>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <input type="hidden" name="template" value="default">
          <?php endif; ?>
          <?php if ($isEdit && $page['published_at']): ?>
            <p class="admin-field-hint"><?= e(t('admin.published_0f0d2b')) ?> <?= e(format_date($page['published_at'], 'yyyy-MM-dd HH:mm')) ?></p>
          <?php endif; ?>
          <?= \Pafish\Services\Plugin::renderInjection('page_editor', [
              'page' => $page,
              'isEdit' => $isEdit,
              'pageId' => $pageId,
          ]) ?>
          <?= \Pafish\Services\Theme::renderInjection('page_editor', [
              'page' => $page,
              'isEdit' => $isEdit,
              'pageId' => $pageId,
          ]) ?>
        </div>
        <div class="card admin-form-card admin-editor-advanced-card">
          <?php $seoValues = $page ?? []; $seoPrefix = 'page'; require __DIR__ . '/partials/seo-fields.php'; ?>
        </div>
      </div>
    </div>
  </form>
</div>

<?php require __DIR__ . '/partials/editor-media-modal.php'; ?>

<script>
window.PAFISH_PAGE_EDITOR = {
  saveUrl: <?= json_encode(url_to($isEdit ? '/admin/pages/' . $pageId . '/save' : '/admin/pages/save')) ?>,
  listUrl: <?= json_encode(url_to('/admin/pages')) ?>,
  previewUrl: <?= json_encode(url_to('/api/md-preview')) ?>,
  uploadUrl: <?= json_encode(url_to('/api/upload')) ?>,
  uploadsUrl: <?= json_encode(url_to('/api/uploads')) ?>,
  assetBase: <?= json_encode(rtrim(\Pafish\Core\Url::base(), '/') . '/public') ?>,
  isEdit: <?= $isEdit ? 'true' : 'false' ?>,
  initialSlug: <?= json_encode($slug) ?>,
  csrf: <?= json_encode(csrf_token()) ?>
};
</script>
<script src="<?= e(asset_url('/js/admin-markdown-editor.js')) ?>"></script>
<script src="<?= e(asset_url('/js/admin-editor-host.js')) ?>"></script>
<script src="<?= e(asset_url('/js/admin-page-editor.js')) ?>"></script>
