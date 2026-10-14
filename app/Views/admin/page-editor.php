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
<div class="admin-editor">
  <form id="pageForm" method="post"
        action="<?= e(url_to($isEdit ? '/admin/pages/' . $pageId . '/save' : '/admin/pages/save')) ?>" novalidate>
    <?= csrf_field() ?>

    <div class="admin-editor-grid">
      <!-- 左列 -->
      <div class="admin-editor-main">
        <div class="admin-editor-topline">
          <a class="btn btn-ghost" href="<?= e(url_to('/admin/pages')) ?>"><?= admin_icon('arrow-left', 15) ?>返回</a>
          <span class="admin-editor-topline-title"><?= $isEdit ? '编辑页面' : '新建页面' ?></span>
        </div>
        <input class="admin-editor-title" type="text" id="fTitle" name="title" aria-label="标题"
               value="<?= e($title) ?>" placeholder="页面标题" maxlength="100">

        <!-- 轻量 Markdown 源码编辑器 -->
        <div class="admin-md-editor">
          <div id="markdownMount"></div>
          <textarea id="fContent" name="content" hidden
                    maxlength="16000000"><?= e($content) ?></textarea>
        </div>
      </div>

      <!-- 右列：设置 -->
      <div class="admin-editor-side">
        <div class="card admin-form-card admin-publish-card">
          <div class="admin-publish-btns">
            <button type="submit" class="btn btn-primary admin-publish-primary" data-save="publish">发布</button>
            <button type="submit" class="btn btn-outline" data-save="draft">存为草稿</button>
          </div>
          <div class="admin-editor-error" hidden></div>
        </div>

        <div class="card admin-form-card admin-settings-card">
          <div class="admin-editor-card-heading">
            <strong>页面设置</strong>
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
          <?php if (count($templateOptions) > 1): ?>
            <div class="admin-field">
              <label class="label" for="pageTemplate">模板</label>
              <select id="pageTemplate" name="template" class="input">
                <?php foreach ($templateOptions as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $template === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="admin-field-hint">模板只负责呈现页面内容；停用提供方后将回退到系统默认模板。</span>
            </div>
          <?php else: ?>
            <input type="hidden" name="template" value="default">
          <?php endif; ?>
          <?php if ($isEdit && $page['published_at']): ?>
            <p class="admin-field-hint">发布于 <?= e(format_date($page['published_at'], 'yyyy-MM-dd HH:mm')) ?></p>
          <?php endif; ?>
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
<script src="<?= e(asset_url('/js/admin-page-editor.js')) ?>"></script>
