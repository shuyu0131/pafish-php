<?php

declare(strict_types=1);

$page = $page ?? [];
$contentHtml = $contentHtml ?? md((string) ($page['content'] ?? ''));
$pluginHtml = $pluginHtml ?? \Pafish\Services\Plugin::renderPageTemplate($page);
get_header();
?>
<main class="centent lumina-layout lumina-layout-single" data-lumina-pjax-container><div class="lumina-layout-wrap"><section class="sh-main">
  <?= lumina_profile_header(true) ?>
  <div class="sh-nrbk"><article class="lumina-article">
  <header class="lumina-article-head"><h1><?= e((string) ($page['title'] ?? '')) ?></h1><div class="lumina-article-meta"><span><?= e(site_name()) ?></span><?php if (!empty($page['updated_at'])): ?><span>更新于 <?= e(format_date($page['updated_at'], 'Y-m-d')) ?></span><?php endif; ?></div></header>
  <?php if ($pluginHtml !== ''): ?><div class="plugin-page"><?= $pluginHtml ?></div><?php else: ?><div class="md-content"><?= $contentHtml ?></div><?php endif; ?>
</article></div>
  <footer class="sh-footer"><span class="sh-copyright"><?= e(trim(theme_value('footer_text')) ?: ('© ' . date('Y') . ' ' . site_name())) ?></span></footer>
</section></div></main>
<?php get_footer();
