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
      <h1 class="admin-h1"><?= e(t('admin.import_markdown')) ?></h1>
      <p class="admin-page-sub"><?= e(t('admin.import_md_files_as_posts_yaml_frontmatter_supports')) ?></p>
    </div>
  </div>

  <div class="admin-import-card">
    <form id="importForm" method="post" action="<?= e($uploadUrl) ?>" novalidate>
      <?= csrf_field() ?>

      <!-- 拖放/点击选择区 -->
      <div class="admin-import-drop" data-dropzone>
        <div class="admin-import-drop-icon"><?= admin_icon('upload', 28) ?></div>
        <p class="admin-import-drop-main"><?= e(t('admin.choose_or_drop_md_files')) ?></p>
        <p class="admin-import-drop-hint"><?= e(t('admin.select_up_to_50_files_each_no_larger')) ?></p>
        <input type="file" id="importFile" accept=".md,text/markdown,text/plain" multiple hidden>
      </div>

      <!-- 已选文件列表 -->
      <ul class="admin-import-files" data-filelist hidden></ul>
      <p class="admin-import-error" data-error hidden></p>

      <!-- 导入方式 -->
      <div class="admin-import-radio">
        <p class="admin-import-radio-title"><?= e(t('admin.import_as')) ?></p>
        <label class="admin-check">
          <input type="radio" name="status" value="draft" checked>
          <span><?= e(t('admin.draft_recommended')) ?></span>
        </label>
        <label class="admin-check">
          <input type="radio" name="status" value="publish">
          <span><?= e(t('admin.publish_immediately_frontmatter_date_is_preserved_as_the')) ?></span>
        </label>
      </div>

      <!-- 操作 -->
      <div class="admin-import-actions">
        <button type="button" class="btn btn-ghost" data-import-clear disabled><?= e(t('admin.clear_all')) ?></button>
        <button type="submit" class="btn btn-primary" data-import-run disabled><?= e(t('admin.start_import_0_posts')) ?></button>
      </div>
    </form>

    <!-- 结果 -->
    <div class="admin-import-result" data-result hidden></div>
  </div>

  <a class="admin-back-link" href="<?= e(url_to('/admin/posts')) ?>"><?= e(t('admin.back_to_posts')) ?></a>
</div>

<script>
  window.PAFISH_IMPORT_CSRF = <?= json_encode($csrf) ?>;
</script>
<script src="<?= e(asset_url('/js/admin-import.js')) ?>"></script>
