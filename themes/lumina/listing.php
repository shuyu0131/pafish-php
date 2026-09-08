<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$posts = $luminaPosts ?? [];
$heading = (string) ($luminaHeading ?? '最新动态');
$countLabel = (string) ($luminaCountLabel ?? '');
$descriptionText = (string) ($luminaDescription ?? '');
$emptyText = (string) ($luminaEmptyText ?? '还没有文章，敬请期待');
$page = (int) ($luminaPage ?? 1);
$totalPages = (int) ($luminaTotalPages ?? 1);
$baseUrl = (string) ($luminaBaseUrl ?? url_to('/'));
$isHome = $heading === '最新动态';
get_header();
?>
<main class="centent lumina-layout lumina-layout-double lumina-layout-pc-double" data-lumina-pjax-container data-lumina-desktop-layout="double" data-lumina-smooth-pagination="<?= theme_value('smooth_pagination', '1') !== '0' ? '1' : '0' ?>">
  <div class="lumina-layout-wrap">
    <section class="sh-main" data-lumina-feed aria-busy="false">
      <?= lumina_profile_header(!$isHome) ?>
      <div class="sh-nrbk" id="sh-nrbk">
        <?php if (!$isHome): ?><header class="lumina-feed-heading"><h1><?= e($heading) ?></h1><?php if ($countLabel !== ''): ?><p><?= e($countLabel) ?></p><?php endif; ?><?php if ($descriptionText !== ''): ?><p><?= e($descriptionText) ?></p><?php endif; ?></header><?php endif; ?>
        <?php if ($posts === []): ?>
          <div class="lumina-empty"><p><?= e($emptyText) ?></p></div>
        <?php else: ?>
          <?php foreach ($posts as $post): ?><?= render_partial('post-card', ['post' => $post]) ?><?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?= render_partial('pagination', ['page' => $page, 'totalPages' => $totalPages, 'baseUrl' => $baseUrl]) ?>
      <p class="lumina-feed-status" data-lumina-feed-status aria-live="polite"></p>
      <footer class="sh-footer"><span class="sh-copyright"><?= e(trim(theme_value('footer_text')) ?: ('© ' . date('Y') . ' ' . site_name())) ?></span></footer>
    </section>
    <aside class="lumina-aside"><?= render_partial('sidebar-widgets') ?></aside>
  </div>
</main>
<?php get_footer();
