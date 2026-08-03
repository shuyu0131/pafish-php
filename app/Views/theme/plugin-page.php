<?php
/**
 * 插件前台页面（系统 fallback 模板；主题可覆盖 themes/{active}/plugin-page.php）
 * 可用数据：$title、$pluginName、$pluginPageHtml
 * 对齐 Node renderPublicShell(html, title)：侧边栏/导航/主题 CSS/注入全部走系统布局，
 * 正文区输出插件 renderPluginPage 渲染的 HTML（.plugin-page 容器）
 */
get_header();
?>
<div class="container container-narrow">
  <div class="plugin-page"><?= $pluginPageHtml ?></div>
</div>
<?php
get_footer();
