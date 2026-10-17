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

    <header class="admin-editor-header">
      <div class="admin-editor-heading">
        <h1 class="admin-h1"><?= $isEdit ? '编辑页面' : '新建页面' ?></h1>
      </div>
      <div class="admin-editor-header-actions">
        <button type="submit" class="btn btn-outline" data-save="draft">存为草稿</button>
        <button type="submit" class="btn btn-primary admin-publish-primary" data-save="publish"><?= $status === 'PUBLISHED' ? '保存修改' : '发布' ?></button>
      </div>
    </header>
    <div class="admin-editor-error" role="alert" hidden></div>
    <div class="admin-editor-grid">
      <div class="admin-editor-main">
        <div class="admin-editor-writing">
        <div class="admin-editor-title-wrap">
          <input class="admin-editor-title" type="text" id="fTitle" name="title" aria-label="页面标题"
                 value="<?= e($title) ?>" placeholder="页面标题" maxlength="100">
        </div>

        <?php $editorType = 'page'; require __DIR__ . '/partials/editor-controls.php'; ?>
        <!-- 正文编辑器 -->
        <div class="admin-md-editor">
          <div id="markdownMount"></div>
          <input type="hidden" id="fContentFormat" name="content_format" value="<?= e($page['content_format'] ?? 'markdown') ?>">
          <textarea id="fContent" name="content" hidden
                    maxlength="16000000"><?= e($content) ?></textarea>
        </div>
        </div>
      </div>

      <!-- 右列：设置 -->
      <div class="admin-editor-side">
        <div class="card admin-form-card admin-settings-card">
          <div class="admin-editor-card-heading">
            <strong>发布设置</strong>
          </div>
          <div class="admin-field">
            <label class="label" for="fStatus">状态</label>
            <select id="fStatus" name="status" class="input">
              <option value="DRAFT" <?= $status === 'DRAFT' ? 'selected' : '' ?>>草稿</option>
              <option value="PUBLISHED" <?= $status === 'PUBLISHED' ? 'selected' : '' ?>>发布</option>
            </select>
          </div>
          <div class="admin-field">
            <label class="label" for="fSlug">页面地址</label>
            <input class="input" id="fSlug" name="slug" value="<?= e($slug) ?>"
                   placeholder="自动根据标题生成" maxlength="100">
            <span class="admin-field-hint">访问路径 /pages/地址；留空自动生成</span>
          </div>
          <?php $templateUnavailable = !array_key_exists($template, $templateOptions); ?>
          <?php if (count($templateOptions) > 1 || $templateUnavailable): ?>
            <div class="admin-field">
              <label class="label" for="pageTemplate">模板</label>
              <select id="pageTemplate" name="template" class="input">
                <?php if ($templateUnavailable): ?>
                  <option value="<?= e($template) ?>" selected><?= e($template) ?>（暂不可用）</option>
                <?php endif; ?>
                <?php foreach ($templateOptions as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $template === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($templateUnavailable): ?>
                <span class="admin-field-hint">原模板暂不可用，前台使用默认布局；保留此选项可在恢复主题或插件后继续使用。</span>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <input type="hidden" name="template" value="default">
          <?php endif; ?>
          <?php if ($isEdit && $page['published_at']): ?>
            <p class="admin-field-hint">发布于 <?= e(format_date($page['published_at'], 'yyyy-MM-dd HH:mm')) ?></p>
          <?php endif; ?>
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
