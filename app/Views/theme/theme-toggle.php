<?php
/**
 * 亮暗切换按钮（系统 fallback 模板；主题可覆盖 themes/{active}/theme-toggle.php）
 * 交互由 public/js/theme.js 驱动（localStorage 记忆 + 跟随系统）
 */
?>
<button type="button" class="btn btn-ghost theme-toggle" aria-label="切换到暗色" title="切换到暗色" hidden>
  <span class="theme-toggle-icon"><?= admin_icon('moon', 17) ?></span>
</button>
