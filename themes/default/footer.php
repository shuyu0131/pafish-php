<?php
/**
 * 前台页脚。
 * 主题目录的 footer.php 完整替换系统模板，get_footer() 渲染本文件
 */
$siteName = site_name();
$footerText = (string) theme_value('footer_text');
$footerDefault = '© ' . date('Y') . ' ' . $siteName;
$icp = (string) settings('site_icp', '');
?>
  </main>

</div>
</div>

<?php /* 页脚放在 .app-shell 外：侧栏吸顶的活动范围到正文结束为止，滚到底部时不会滑进页脚 */ ?>
<footer class="app-footer" data-theme-footer="default">
    <div class="app-footer-inner">
      <p><?= e($footerText !== '' ? $footerText : $footerDefault) ?></p>
      <?php if ($icp !== ''): ?>
        <p class="app-footer-icp"><?= e($icp) ?></p>
      <?php endif; ?>
    </div>
    <?php $footerInjectContext = ['template' => $GLOBALS['pafish_tpl_ctx'] ?? []]; ?>
    <?= \Pafish\Services\Theme::renderInjection('footer', $footerInjectContext) ?>
    <?php do_action('footer_inject', $footerInjectContext); ?>
</footer>
<script src="<?= e(asset_url('/js/highlight.min.js')) ?>" defer></script>
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
