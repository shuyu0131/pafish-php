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

        <!-- Markdown 编辑器（复用文章编辑器工具栏/预览，精简版 JS） -->
        <div class="admin-md-editor">
          <div class="admin-md-toolbar">
            <button type="button" class="admin-md-btn" data-md="bold" title="加粗 (Ctrl+B)"><b>B</b></button>
            <button type="button" class="admin-md-btn" data-md="italic" title="斜体 (Ctrl+I)"><i>I</i></button>
            <button type="button" class="admin-md-btn" data-md="strike" title="删除线">S̶</button>
            <span class="admin-md-sep"></span>
            <select class="admin-md-select" data-md="heading" title="标题">
              <option value="h2">标题 2</option>
              <option value="h1">标题 1</option>
              <option value="h3">标题 3</option>
              <option value="h4">标题 4</option>
              <option value="h5">标题 5</option>
              <option value="h6">标题 6</option>
              <option value="p">正文</option>
            </select>
            <span class="admin-md-sep"></span>
            <button type="button" class="admin-md-btn" data-md="quote" title="引用">❝</button>
            <button type="button" class="admin-md-btn" data-md="code" title="代码块">&lt;/&gt;</button>
            <button type="button" class="admin-md-btn" data-md="inline-code" title="行内代码">`code`</button>
            <button type="button" class="admin-md-btn" data-md="ul" title="无序列表">• 列表</button>
            <button type="button" class="admin-md-btn" data-md="ol" title="有序列表">1. 列表</button>
            <span class="admin-md-sep"></span>
            <button type="button" class="admin-md-btn" data-md="link" title="链接"><?= admin_icon('link', 15) ?></button>
            <button type="button" class="admin-md-btn" data-md="image" title="图片"><?= admin_icon('image', 15) ?></button>
            <button type="button" class="admin-md-btn" data-md="table" title="表格">⊞</button>
            <button type="button" class="admin-md-btn" data-md="hr" title="分隔线">―</button>
            <span class="admin-md-spacer"></span>
            <span class="admin-md-mode admin-md-mode-group" role="group" aria-label="编辑模式">
              <button type="button" class="admin-md-btn" data-mode="edit" title="编辑模式">编辑</button>
              <button type="button" class="admin-md-btn admin-md-mode-active" data-mode="live" title="分栏预览">分栏</button>
              <button type="button" class="admin-md-btn" data-mode="preview" title="预览">预览</button>
            </span>
          </div>
          <div class="admin-md-body">
            <textarea class="admin-md-textarea" id="fContent" name="content"
                      placeholder="页面内容（Markdown）…" maxlength="16000000"><?= e($content) ?></textarea>
            <div class="admin-md-preview md-content" hidden></div>
          </div>
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

<script>
window.PAFISH_PAGE_EDITOR = {
  saveUrl: <?= json_encode(url_to($isEdit ? '/admin/pages/' . $pageId . '/save' : '/admin/pages/save')) ?>,
  listUrl: <?= json_encode(url_to('/admin/pages')) ?>,
  previewUrl: <?= json_encode(url_to('/api/md-preview')) ?>,
  isEdit: <?= $isEdit ? 'true' : 'false' ?>,
  initialSlug: <?= json_encode($slug) ?>,
  csrf: <?= json_encode(csrf_token()) ?>
};
</script>
<script src="<?= e(url_to('/js/admin-page-editor.js')) ?>"></script>
