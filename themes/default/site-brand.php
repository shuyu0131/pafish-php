<?php
/**
 * 默认主题站点标识（header.php 调用，子主题可覆盖或委托本模板）
 *
 * 主题设置「品牌」分组：
 * - logo_type：text 文字（默认）/ image 图片 / image_text 图片 + 文字
 * - logo_image：Logo 图片；logo_image_dark：暗色模式用的 Logo（可选，留空沿用前者）
 * - logo_height：电脑端显示高度（px），移动端最高 30px
 * 选了图片却没上传、或地址无效时，自动退回文字，不会出现空白。
 *
 * 可用数据：$brandVariant（top 桌面顶栏 / mobile 移动端顶栏）
 */
use Pafish\Services\Content;

$variant = ($brandVariant ?? 'top') === 'mobile' ? 'mobile' : 'top';
$siteName = site_name();
$logoType = theme_value('logo_type', 'text');
if (!in_array($logoType, ['text', 'image', 'image_text'], true)) {
    $logoType = 'text';
}
$logoLight = Content::safeImageUrl(theme_value('logo_image', ''));
$logoDark = Content::safeImageUrl(theme_value('logo_image_dark', ''));
if ($logoLight === null && $logoDark !== null) {
    $logoLight = $logoDark;
    $logoDark = null;
}
if ($logoType !== 'text' && $logoLight === null) {
    $logoType = 'text';
}
$logoHeight = (int) theme_value('logo_height', '32');
$logoHeight = max(20, min(56, $logoHeight > 0 ? $logoHeight : 32));
$imageOnly = $logoType === 'image';
?>
<a href="<?= e(url_to('/')) ?>" class="<?= $variant === 'mobile' ? 'app-brand-mobile' : 'app-brand-top' ?> site-brand is-<?= e($logoType) ?>"<?= $logoType !== 'text' ? ' style="--logo-h:' . $logoHeight . 'px"' : '' ?><?= $imageOnly ? ' title="' . e($siteName) . '"' : '' ?>>
  <?php if ($logoType !== 'text'): ?>
    <img class="site-brand-logo<?= $logoDark !== null ? ' is-light' : '' ?>" src="<?= e($logoLight) ?>" alt="<?= $imageOnly ? e($siteName) : '' ?>" height="<?= $logoHeight ?>" decoding="async">
    <?php if ($logoDark !== null): ?>
      <img class="site-brand-logo is-dark" src="<?= e($logoDark) ?>" alt="<?= $imageOnly ? e($siteName) : '' ?>" height="<?= $logoHeight ?>" decoding="async">
    <?php endif; ?>
  <?php endif; ?>
  <?php if (!$imageOnly): ?>
    <span class="site-brand-name"><?= e($siteName) ?></span>
  <?php endif; ?>
</a>
