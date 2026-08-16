<?php

declare(strict_types=1);

$page = $page ?? [];
$contentHtml = $contentHtml ?? md((string) ($page['content'] ?? ''));
$pluginHtml = $pluginHtml ?? \Pafish\Services\Plugin::renderPageTemplate($page);
get_header();
?>
<article class="lumina-article">
  <header class="lumina-article-head"><h1><?= e((string) ($page['title'] ?? '')) ?></h1><div class="lumina-article-meta"><span><?= e(site_name()) ?></span><?php if (!empty($page['updated_at'])): ?><span>更新于 <?= e(format_date($page['updated_at'], 'Y-m-d')) ?></span><?php endif; ?></div></header>
  <?php if ($pluginHtml !== ''): ?><div class="plugin-page"><?= $pluginHtml ?></div><?php else: ?><div class="md-content"><?= $contentHtml ?></div><?php endif; ?>
</article>
<?php get_footer();
