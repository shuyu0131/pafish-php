<?php
/** 归档数据：$groups 是按月份分组的文章数组；$total 为文章总数。 */
$groups = is_array($groups ?? null) ? $groups : [];
get_header();
?>
<header class="starter-page-head"><p class="starter-kicker">按时间浏览</p><h1 class="starter-title">文章归档</h1><p class="starter-description">共 <?= (int) ($total ?? 0) ?> 篇</p></header>
<?php if ($groups === []): ?><p class="starter-empty">还没有可归档的文章。</p><?php else: ?>
  <?php foreach ($groups as $group): ?><section class="starter-archive-month"><h2><?= e($group['month'] ?? '') ?></h2><ul><?php foreach (($group['posts'] ?? []) as $post): ?><li><a href="<?= e(url_to('/post/' . rawurlencode((string) ($post['slug'] ?? '')))) ?>"><?= e($post['title'] ?? '') ?></a><time class="starter-meta"><?= e(format_date($post['published_at'] ?? null, 'MM-dd')) ?></time></li><?php endforeach; ?></ul></section><?php endforeach; ?>
<?php endif; ?>
<?php get_footer(); ?>
