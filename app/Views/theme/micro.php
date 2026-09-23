<?php
/** 微语列表（主题可覆盖；默认仅依赖核心数据）。 */
$items = $items ?? [];
get_header();
?>
<div class="container">
  <header class="listing-head"><p class="eyebrow">动态</p><h1 class="editorial listing-title">微语</h1><p class="listing-count">共 <?= (int) ($total ?? count($items)) ?> 条</p></header>
  <?php if ($items === []): ?><div class="empty-box"><p>还没有公开微语</p></div><?php else: ?><div class="micro-list"><?php foreach ($items as $item): ?><?= render_partial('micro-card', ['micro' => $item]) ?><?php endforeach; ?></div><?php endif; ?>
  <?= render_partial('pagination', ['page' => (int) ($pageNum ?? 1), 'totalPages' => (int) ($totalPages ?? 1), 'baseUrl' => $listBaseUrl ?? url_to('/micro')]) ?>
</div>
<?php get_footer(); ?>
