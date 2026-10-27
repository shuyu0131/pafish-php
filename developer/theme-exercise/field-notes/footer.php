<?php

declare(strict_types=1);

$icp = (string) settings('site_icp', '');
$footerContext = ['template' => $GLOBALS['pafish_tpl_ctx'] ?? []];
?>
    </main>
    <footer class="fn-footer">
      <div>
        <a class="fn-footer-brand" href="<?= e(url_to('/')) ?>"><?= e(site_name()) ?></a>
        <p><?= e(theme_value('footer_note', '')) ?></p>
        <?php if ($icp !== ''): ?><small><?= e($icp) ?></small><?php endif; ?>
      </div>
      <div class="fn-footer-aside">
        <span>写下，读过，再出发。</span>
        <?= \Pafish\Services\Theme::renderInjection('footer', $footerContext) ?>
        <?php do_action('footer_inject', $footerContext); ?>
      </div>
    </footer>
  </div>
  <script src="<?= e(asset_url('/js/theme.js')) ?>" defer></script>
</body>
</html>
