<?php
/**
 * 页面编辑器（对齐 Node page-editor.tsx）：
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
        <input class="admin-editor-title" type="text" id="fTitle" name="title"
               value="<?= e($title) ?>" placeholder="页面标题" maxlength="100">

        <!-- Markdown 编辑器（@uiw/react-md-editor：工具栏/分栏预览/全屏/拖拽粘贴上传，对齐 Node 版） -->
        <div class="admin-md-editor" data-color-mode="light">
          <div id="mdEditorMount"></div>
          <textarea id="fContent" name="content" hidden
                    maxlength="16000000"><?= e($content) ?></textarea>
        </div>
      </div>

      <!-- 右列：设置 -->
      <div class="admin-editor-side">
        <div class="card admin-form-card admin-publish-card">
          <div class="admin-publish-btns">
            <button type="submit" class="btn btn-outline" data-save="draft">存为草稿</button>
            <button type="submit" class="btn btn-primary" data-save="publish">发布</button>
          </div>
          <div class="admin-editor-error" hidden></div>
        </div>

        <div class="card admin-form-card admin-settings-card">
          <div class="admin-field">
            <span class="label">状态</span>
            <select id="fStatus" name="status" class="input">
              <option value="DRAFT" <?= $status === 'DRAFT' ? 'selected' : '' ?>>草稿</option>
              <option value="PUBLISHED" <?= $status === 'PUBLISHED' ? 'selected' : '' ?>>发布</option>
            </select>
          </div>
          <div class="admin-field">
            <span class="label">页面地址</span>
            <input class="input" id="fSlug" name="slug" value="<?= e($slug) ?>"
                   placeholder="自动根据标题生成" maxlength="100">
            <span class="admin-field-hint">访问路径 /pages/地址；留空自动生成</span>
          </div>
          <?php if (count($templateOptions) > 1): ?>
            <div class="admin-field">
              <span class="label">模板</span>
              <select name="template" class="input">
                <?php foreach ($templateOptions as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $template === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="admin-field-hint">由当前主题提供；「default」为系统默认模板</span>
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

<!-- 媒体选择弹窗（对齐 Node MediaPicker） -->
<div class="admin-modal-backdrop" id="mediaModal" hidden>
  <div class="admin-modal" role="dialog" aria-modal="true" aria-label="插入媒体">
    <div class="admin-modal-head">
      <div class="admin-modal-tabs">
        <button type="button" class="admin-modal-tab active" data-mtab="upload">本地上传</button>
        <button type="button" class="admin-modal-tab" data-mtab="library">媒体库</button>
      </div>
      <button type="button" class="admin-icon-btn" data-close-modal aria-label="关闭"><?= admin_icon('x', 16) ?></button>
    </div>
    <div class="admin-modal-body">
      <div data-mpanel="upload">
        <input type="file" class="input" id="mediaFile"
               accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.md,.csv,.zip,.rar,.7z,.tar,.gz,.mp3,.wav,.ogg,.m4a,.flac,.mp4,.webm,.mov,.mkv">
        <p class="admin-field-hint admin-modal-hint" data-mupload-hint>图片插入为 Markdown 图片，其他文件插入为下载链接</p>
        <div class="admin-modal-error" data-mupload-error hidden></div>
      </div>
      <div data-mpanel="library" hidden>
        <input class="input admin-lib-search" type="search" placeholder="搜索媒体…" data-lib-q>
        <div class="admin-lib-grid" data-lib-grid></div>
        <div class="admin-pager admin-lib-pager" data-lib-pager hidden></div>
      </div>
    </div>
  </div>
</div>

<script>
window.PAFISH_PAGE_EDITOR = {
  saveUrl: <?= json_encode(url_to($isEdit ? '/admin/pages/' . $pageId . '/save' : '/admin/pages/save')) ?>,
  listUrl: <?= json_encode(url_to('/admin/pages')) ?>,
  previewUrl: <?= json_encode(url_to('/api/md-preview')) ?>,
  uploadUrl: <?= json_encode(url_to('/api/upload')) ?>,
  uploadsUrl: <?= json_encode(url_to('/api/uploads')) ?>,
  isEdit: <?= $isEdit ? 'true' : 'false' ?>,
  initialSlug: <?= json_encode($slug) ?>,
  csrf: <?= json_encode(csrf_token()) ?>
};
</script>
<script src="<?= e(asset_url('/vendor/md-editor/pafish-md-editor.min.js')) ?>"></script>
<script src="<?= e(asset_url('/js/admin-page-editor.js')) ?>"></script>
