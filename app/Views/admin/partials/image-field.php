<?php /** Shared image address, upload and library field. */ ?>
<div class="admin-sf-image" data-image-field>
  <input class="input" id="<?= e($imageId) ?>" name="<?= e($imageName) ?>" maxlength="500"
         value="<?= e($imageValue ?? '') ?>" placeholder="<?= e(t('admin.uploads_or_https')) ?>" data-image-value>
  <button type="button" class="btn btn-outline btn-sm" data-image-library><?= e(t('admin.media_library')) ?></button>
  <button type="button" class="btn btn-outline btn-sm" data-image-upload><?= e(t('admin.upload_image')) ?></button>
  <input type="file" accept="image/*,.svg" data-image-file hidden>
  <button type="button" class="btn btn-ghost btn-sm" data-image-clear><?= e(t('common.clear')) ?></button>
  <p class="admin-field-hint" data-image-status role="status"></p>
</div>
