<?php
/** Shared optional metadata fields. $seoValues and $seoPrefix belong to the caller. */
$seoValues = $seoValues ?? [];
$seoPrefix = $seoPrefix ?? 'content';
?>
<details class="admin-advanced">
  <summary><?= admin_icon('chevron-right', 14) ?><?= e(t('admin.search_engine_settings')) ?></summary>
  <div class="admin-advanced-body">
    <div class="admin-field">
      <label class="label" for="<?= e($seoPrefix) ?>SeoTitle"><?= e(t('admin.seo_title')) ?></label>
      <input class="input" id="<?= e($seoPrefix) ?>SeoTitle" name="seo_title" maxlength="255"
             value="<?= e($seoValues['seo_title'] ?? '') ?>" placeholder="<?= e(t('admin.leave_blank_to_use_the_original_title')) ?>">
    </div>
    <div class="admin-field">
      <label class="label" for="<?= e($seoPrefix) ?>MetaDescription"><?= e(t('admin.seo_description')) ?></label>
      <textarea class="input" id="<?= e($seoPrefix) ?>MetaDescription" name="meta_description" rows="2"
                maxlength="500" placeholder="<?= e(t('admin.leave_blank_to_generate_automatically')) ?>"><?= e($seoValues['meta_description'] ?? '') ?></textarea>
    </div>
  </div>
</details>
