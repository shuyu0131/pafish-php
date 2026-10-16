<?php /** Shared image address, upload and library field. */ ?>
<div class="admin-sf-image" data-image-field>
  <input class="input" id="<?= e($imageId) ?>" name="<?= e($imageName) ?>" maxlength="500"
         value="<?= e($imageValue ?? '') ?>" placeholder="/uploads/… 或 https://…" data-image-value>
  <button type="button" class="btn btn-outline btn-sm" data-image-library>媒体库</button>
  <button type="button" class="btn btn-outline btn-sm" data-image-upload>上传图片</button>
  <input type="file" accept="image/*,.svg" data-image-file hidden>
  <button type="button" class="btn btn-ghost btn-sm" data-image-clear>清除</button>
  <p class="admin-field-hint" data-image-status role="status"></p>
</div>
