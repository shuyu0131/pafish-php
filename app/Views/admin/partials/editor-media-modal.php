<?php /** 文章与页面编辑器共用媒体选择弹窗。 */ ?>
<div class="admin-modal-backdrop" id="mediaModal" hidden>
  <div class="admin-modal admin-editor-media-modal" role="dialog" aria-modal="true" aria-labelledby="editorMediaTitle">
    <div class="admin-modal-head">
      <h2 class="admin-modal-title" id="editorMediaTitle"><?= e(t('admin.insert_media')) ?></h2>
      <button type="button" class="admin-icon-btn" data-close-modal aria-label="<?= e(t('admin.close_media_dialog')) ?>"><?= admin_icon('x', 16) ?></button>
    </div>
    <div class="admin-modal-body">
      <div class="admin-modal-tabs" aria-label="<?= e(t('admin.media_source')) ?>">
        <button type="button" class="admin-modal-tab active" data-mtab="upload" aria-pressed="true"><?= admin_icon('upload', 14) ?><?= e(t('admin.upload_files')) ?></button>
        <button type="button" class="admin-modal-tab" data-mtab="library" aria-pressed="false"><?= admin_icon('image', 14) ?><?= e(t('admin.media_library')) ?></button>
        <button type="button" class="admin-modal-tab" data-mtab="url" aria-pressed="false"><?= admin_icon('link', 14) ?><?= e(t('js.image_url')) ?></button>
      </div>
      <div class="admin-media-upload-panel" data-mpanel="upload">
        <div class="admin-media-upload-icon" aria-hidden="true"><?= admin_icon('upload', 24) ?></div>
        <span><?= e(t('admin.drop_files_here_or_choose_files')) ?></span>
        <button type="button" class="btn btn-primary admin-media-upload-trigger" data-media-browse><?= e(t('admin.choose_files')) ?></button>
        <input type="file" class="admin-media-file-input" id="mediaFile" hidden
               accept="image/*,.svg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.md,.csv,.zip,.rar,.7z,.tar,.gz,.mp3,.wav,.ogg,.m4a,.flac,.mp4,.webm,.mov,.mkv">
        <p class="admin-field-hint admin-modal-hint" data-mupload-hint role="status"><?= e(t('admin.images_are_inserted_as_images_other_files_as')) ?></p>
        <div class="admin-modal-error" data-mupload-error role="alert" hidden></div>
      </div>
      <div data-mpanel="library" hidden>
        <input class="input admin-lib-search" type="search" placeholder="<?= e(t('admin.search_filenames')) ?>" aria-label="<?= e(t('admin.search_media_files')) ?>" data-lib-q>
        <div class="admin-lib-grid" data-lib-grid aria-live="polite"></div>
        <div class="admin-pager admin-lib-pager" data-lib-pager hidden></div>
      </div>
      <form class="admin-media-url-form" data-mpanel="url" data-media-url-form hidden novalidate>
        <label class="label" for="editorImageUrl"><?= e(t('js.image_url')) ?></label>
        <input class="input" type="text" inputmode="url" id="editorImageUrl" name="url" placeholder="https://" autocomplete="off" spellcheck="false" maxlength="2048" aria-describedby="editorImageUrlHint editorImageUrlError" required>
        <p class="admin-field-hint" id="editorImageUrlHint"><?= e(t('js.enter_an_http_https_image_url_or_a')) ?></p>
        <label class="label" for="editorImageAlt"><?= e(t('js.alternative_text')) ?></label>
        <input class="input" type="text" id="editorImageAlt" name="alt" maxlength="500">
        <p class="admin-modal-error" id="editorImageUrlError" data-media-url-error role="alert" hidden></p>
        <div class="admin-media-url-actions"><button type="submit" class="btn btn-primary"><?= e(t('js.insert')) ?></button></div>
      </form>
    </div>
  </div>
</div>
