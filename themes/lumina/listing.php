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
get_header();
?>
<div class="lumina-layout with-side">
  <section class="lumina-feed" data-lumina-feed aria-busy="false">
    <header class="lumina-listing-head">
      <h1><?= e($heading) ?></h1>
      <?php if ($countLabel !== ''): ?><p><?= e($countLabel) ?></p><?php endif; ?>
    </header>
    <?php if ($descriptionText !== ''): ?><p class="lumina-listing-desc"><?= e($descriptionText) ?></p><?php endif; ?>
    <?php if ($posts === []): ?>
      <div class="lumina-empty"><p><?= e($emptyText) ?></p></div>
    <?php else: ?>
      <?php foreach ($posts as $post): ?><?= render_partial('post-card', ['post' => $post]) ?><?php endforeach; ?>
    <?php endif; ?>
    <?= render_partial('pagination', ['page' => $page, 'totalPages' => $totalPages, 'baseUrl' => $baseUrl]) ?>
    <p class="lumina-feed-status" data-lumina-feed-status aria-live="polite" aria-atomic="true"></p>
    <?= render_partial('friend-links') ?>
  </section>
  <aside class="lumina-aside"><?= render_partial('sidebar-widgets') ?></aside>
</div>
<?php get_footer();
