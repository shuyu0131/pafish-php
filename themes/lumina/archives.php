<?php

declare(strict_types=1);

$groups = $groups ?? [];
$total = (int) ($total ?? 0);
get_header();
?>
<section class="lumina-panel lumina-archive">
  <header class="lumina-listing-head"><h1>文章归档</h1><p>共 <?= $total ?> 篇文章</p></header>
  <?php if ($groups === []): ?><div class="lumina-empty">还没有文章</div><?php endif; ?>
  <?php foreach ($groups as $group): ?><section class="lumina-archive-group"><h2><?= e((string) $group['month']) ?> <small><?= count($group['posts']) ?></small></h2><ul><?php foreach ($group['posts'] as $post): ?><li><a href="<?= e(url_to('/post/' . rawurlencode((string) $post['slug']))) ?>"><?= e((string) $post['title']) ?></a><time><?= e(format_date($post['published_at'] ?? null, 'm-d')) ?></time></li><?php endforeach; ?></ul></section><?php endforeach; ?>
</section>
<?php get_footer();
