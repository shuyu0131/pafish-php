<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$showSearch = theme_value('show_search', '1') !== '0';
$showBackToTop = theme_value('show_back_to_top', '1') !== '0';
?>
<?php if ($showSearch): ?>
<div class="lumina-search-dialog" data-lumina-search-dialog hidden>
  <form class="lumina-search-box" action="<?= e(url_to('/search')) ?>" method="get" role="search">
    <label for="lumina-search-input">搜索</label>
    <div><input id="lumina-search-input" name="q" type="search" placeholder="搜索文章、标签或关键词" autocomplete="off"><button type="submit">搜索</button></div>
    <button type="button" class="lumina-search-close" data-lumina-search-close aria-label="关闭搜索">&times;</button>
  </form>
</div>
<?php endif; ?>
<div class="lumina-lightbox" data-lumina-lightbox hidden role="dialog" aria-modal="true" aria-label="图片预览"><img alt="图片预览"><button type="button" data-lumina-lightbox-close aria-label="关闭预览">&times;</button></div>
<?php do_action('footer_inject', ['template' => $GLOBALS['pafish_tpl_ctx'] ?? []]); ?>
<script src="<?= e(lumina_asset_url('lumina.js')) ?>" defer></script>
<script src="<?= e(asset_url('/js/highlight.min.js')) ?>" defer></script>
<script src="<?= e(asset_url('/js/theme.js')) ?>" defer></script>
</body>
</html>
