<?php
/**
 * Markdown 批量导入
 * 变量：$uploadUrl（/api/import-markdown） $csrf
 * 多选 .md 文件（最多 50 个、单文件 1MB），frontmatter 仅支持 title / date / tags
 */
?>
<div class="admin-import">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">导入 Markdown</h1>
    </div>
  </div>

  <div class="admin-import-card">
    <form id="importForm" method="post" action="<?= e($uploadUrl) ?>" novalidate>
      <?= csrf_field() ?>

      <!-- 拖放/点击选择区 -->
      <div class="admin-import-drop" data-dropzone>
        <div class="admin-import-drop-icon"><?= admin_icon('upload', 28) ?></div>
        <p class="admin-import-drop-main">点击选择或拖入 .md 文件</p>
        <p class="admin-import-drop-hint">支持多选，一次最多 50 个，单文件 1MB</p>
        <input type="file" id="importFile" accept=".md,text/markdown,text/plain" multiple hidden>
      </div>

      <!-- 已选文件列表 -->
      <ul class="admin-import-files" data-filelist hidden></ul>
      <p class="admin-import-error" data-error hidden></p>

      <!-- 导入方式 -->
      <div class="admin-import-radio">
        <p class="admin-import-radio-title">导入为</p>
        <label class="admin-check">
          <input type="radio" name="status" value="draft" checked>
          <span>草稿（推荐）</span>
        </label>
        <label class="admin-check">
          <input type="radio" name="status" value="publish">
          <span>直接发布（frontmatter 的 date 保留为发布时间）</span>
        </label>
      </div>

      <!-- 操作 -->
      <div class="admin-import-actions">
        <button type="button" class="btn btn-ghost" data-import-clear disabled>清空</button>
        <button type="submit" class="btn btn-primary" data-import-run disabled>开始导入（0 篇）</button>
      </div>
    </form>

    <!-- 结果 -->
    <div class="admin-import-result" data-result hidden></div>
  </div>

  <a class="admin-back-link" href="<?= e(url_to('/admin/posts')) ?>">← 返回文章列表</a>
</div>

<script>
  window.PAFISH_IMPORT_CSRF = <?= json_encode($csrf) ?>;
</script>
<script src="<?= e(asset_url('/js/admin-import.js')) ?>"></script>
