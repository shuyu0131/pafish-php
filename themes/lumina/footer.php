<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$footerText = trim(theme_value('footer_text'));
$icp = (string) settings('site_icp', '');
$showSearch = theme_value('show_search', '1') !== '0';
$showBackToTop = theme_value('show_back_to_top', '1') !== '0';
?>
  </main>
  <footer class="lumina-footer">
    <p><?= e($footerText !== '' ? $footerText : '© ' . date('Y') . ' ' . site_name()) ?></p>
    <?php if ($icp !== ''): ?><p><?= e($icp) ?></p><?php endif; ?>
    <?php do_action('footer_inject', ['template' => $GLOBALS['pafish_tpl_ctx'] ?? []]); ?>
  </footer>
</div>

<?php if ($showSearch): ?>
<div class="lumina-search-dialog" data-lumina-search-dialog hidden>
  <form class="lumina-search-box" action="<?= e(url_to('/search')) ?>" method="get" role="search">
    <label for="lumina-search-input">搜索</label>
    <div><input id="lumina-search-input" name="q" type="search" placeholder="搜索文章、标签或关键词" autocomplete="off"><button type="submit">搜索</button></div>
    <button type="button" class="lumina-search-close" data-lumina-search-close aria-label="关闭搜索">&times;</button>
  </form>
</div>
<?php endif; ?>

<div class="lumina-float-actions">
  <?php if ($showSearch): ?><button type="button" class="lumina-float-button" data-lumina-search-open aria-label="搜索"><?= admin_icon('search', 18) ?></button><?php endif; ?>
  <?php if ($showBackToTop): ?><button type="button" class="lumina-float-button" data-lumina-back-top aria-label="返回顶部"><?= admin_icon('chevron-up', 18) ?></button><?php endif; ?>
</div>
<script src="<?= e(lumina_asset_url('lumina.js')) ?>" defer></script>
<script src="<?= e(asset_url('/js/highlight.min.js')) ?>" defer></script>
<script src="<?= e(asset_url('/js/theme.js')) ?>" defer></script>
</body>
</html>
