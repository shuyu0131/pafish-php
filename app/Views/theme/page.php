<?php
/**
 * 独立页面（系统 fallback 模板；主题可覆盖 themes/{active}/page.php）
 * 可用数据：$page（pages 表行）、$title、$description
 * Markdown 渲染在 M2 接入（Parsedown）；当前为预格式化文本
 */
get_header();
?>
<div class="container">
  <article class="page-article">
    <header class="page-header">
      <h1><?= e($page['title']) ?></h1>
    </header>
    <div class="md-content"><?= nl2br(e($page['content'])) ?></div>
  </article>
</div>
<?php
get_footer();
