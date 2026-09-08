<?php
/**
 * 前台页脚（系统 fallback 模板；主题可覆盖 themes/{active}/footer.php）
 */
$siteName = site_name();
$footerText = (string) theme_value('footer_text');
$footerDefault = '© ' . date('Y') . ' ' . $siteName . ' · 用 PHP 构建';
$icp = (string) settings('site_icp', '');
?>
  </main>

  <footer class="app-footer">
    <p><?= e($footerText !== '' ? $footerText : $footerDefault) ?></p>
    <?php if ($icp !== ''): ?>
      <p class="app-footer-icp"><?= e($icp) ?></p>
    <?php endif; ?>
    <?php /* 插件页脚注入 */ do_action('footer_inject', ['template' => $GLOBALS['pafish_tpl_ctx'] ?? []]); ?>
  </footer>

</div>
</div>
<script src="<?= e(asset_url('/js/highlight.min.js')) ?>" defer></script>
<script src="<?= e(asset_url('/vendor/vditor/dist/js/katex/katex.min.js')) ?>" defer></script>
<script src="<?= e(asset_url('/vendor/vditor/dist/js/mermaid/mermaid.min.js')) ?>" defer></script>
<script>window.PAFISH_KATEX_CSS = <?= json_encode(asset_url('/vendor/vditor/dist/js/katex/katex.min.css')) ?>;</script>
<script src="<?= e(asset_url('/js/frontend-render.js')) ?>" defer></script>
<script>
/* 代码高亮（hljs 类已在 style.css 定义配色） */
document.addEventListener('DOMContentLoaded', function () {
  if (window.hljs && document.querySelector('.md-content pre code')) {
    hljs.highlightAll();
  }
});
</script>
<script src="<?= e(asset_url('/js/theme.js')) ?>" defer></script>
</body>
</html>
