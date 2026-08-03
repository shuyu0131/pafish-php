<?php
/**
 * 前台页脚（系统 fallback 模板；主题可覆盖 themes/{active}/footer.php）
 */
$siteName = site_name();
$footerText = (string) theme_value('footer_text');
$footerDefault = '© ' . date('Y') . ' ' . $siteName . ' · 用 PHP 构建';
?>
  </main>

  <footer class="app-footer">
    <p><?= e($footerText !== '' ? $footerText : $footerDefault) ?></p>
    <?php /* 插件页脚注入（M5） */ do_action('footer_inject'); ?>
  </footer>

</div>
</div>
<script src="<?= e(url_to('/js/highlight.min.js')) ?>" defer></script>
<script>
/* 代码高亮（hljs 类已在 style.css 定义配色） */
document.addEventListener('DOMContentLoaded', function () {
  if (window.hljs && document.querySelector('.md-content pre code')) {
    hljs.highlightAll();
  }
});
</script>
<script src="<?= e(url_to('/js/theme.js')) ?>" defer></script>
</body>
</html>
