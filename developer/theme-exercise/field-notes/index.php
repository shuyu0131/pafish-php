<?php

declare(strict_types=1);

$posts = is_array($posts ?? null) ? $posts : [];
get_header();
?>
<section class="fn-hero">
  <p class="fn-eyebrow"><?= e(theme_value('home_kicker', 'FIELD NOTES / 最新记录')) ?></p>
  <h1><?= e($title ?? site_name()) ?></h1>
  <p class="fn-hero-intro"><?= e(theme_value('home_intro', '把值得记下来的想法，留给下一次阅读。')) ?></p>
</section>

<?php if ($posts === []): ?>
  <section class="fn-empty"><p><?= e($emptyText ?? '还没有文章，欢迎稍后再来。') ?></p></section>
<?php else: ?>
  <section class="fn-feed" aria-label="文章列表">
    <?php foreach ($posts as $index => $post): ?>
      <?= render_partial('entry-card', ['post' => $post, 'loopIndex' => $index + 1]) ?>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<?php if (($totalPages ?? 1) > 1): ?>
  <?= render_partial('pagination', [
      'page' => $pageNum ?? 1,
      'totalPages' => $totalPages,
      'baseUrl' => $listBaseUrl ?? url_to('/'),
  ]) ?>
<?php endif; ?>
<?php get_footer(); ?>
