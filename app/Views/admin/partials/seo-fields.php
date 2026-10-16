<?php
/** Shared optional metadata fields. $seoValues and $seoPrefix belong to the caller. */
$seoValues = $seoValues ?? [];
$seoPrefix = $seoPrefix ?? 'content';
?>
<details class="admin-advanced">
  <summary><?= admin_icon('chevron-right', 14) ?>搜索引擎设置</summary>
  <div class="admin-advanced-body">
    <div class="admin-field">
      <label class="label" for="<?= e($seoPrefix) ?>SeoTitle">SEO 标题</label>
      <input class="input" id="<?= e($seoPrefix) ?>SeoTitle" name="seo_title" maxlength="255"
             value="<?= e($seoValues['seo_title'] ?? '') ?>" placeholder="留空使用原标题">
    </div>
    <div class="admin-field">
      <label class="label" for="<?= e($seoPrefix) ?>MetaDescription">SEO 描述</label>
      <textarea class="input" id="<?= e($seoPrefix) ?>MetaDescription" name="meta_description" rows="2"
                maxlength="500" placeholder="留空自动生成"><?= e($seoValues['meta_description'] ?? '') ?></textarea>
    </div>
  </div>
</details>
