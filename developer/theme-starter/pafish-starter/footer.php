<?php
$footerText = trim((string) theme_value('footer_text'));
$icp = (string) settings('site_icp', '');
?>
  </main>
  <footer class="starter-footer">
    <p><?= e($footerText !== '' ? $footerText : '© ' . date('Y') . ' ' . site_name()) ?></p>
    <?php if ($icp !== ''): ?><p><?= e($icp) ?></p><?php endif; ?>
    <?php $footerInjectContext = ['template' => $GLOBALS['pafish_tpl_ctx'] ?? []]; ?>
    <?= \Pafish\Services\Theme::renderInjection('footer', $footerInjectContext) ?>
    <?php do_action('footer_inject', $footerInjectContext); ?>
  </footer>
</div>
<script src="<?= e(asset_url('/js/theme.js')) ?>" defer></script>
</body>
</html>
