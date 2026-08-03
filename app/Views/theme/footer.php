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
<script src="<?= e(url_to('/js/theme.js')) ?>" defer></script>
</body>
</html>
