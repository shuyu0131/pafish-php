<?php
/** 微语列表（主题可覆盖；默认仅依赖核心数据）。 */
$items = $items ?? [];
get_header();
?>
<div class="container micro-page">
  <header class="micro-page-head"><p>动态</p><h1>微语</h1><span>共 <?= (int) ($total ?? count($items)) ?> 条</span></header>
  <?php if ($items === []): ?><div class="micro-page-empty">还没有公开微语</div><?php else: ?><div class="micro-list"><?php foreach ($items as $item): ?><?= render_partial('micro-card', ['micro' => $item]) ?><?php endforeach; ?></div><?php endif; ?>
  <?= render_partial('pagination', ['page' => (int) ($pageNum ?? 1), 'totalPages' => (int) ($totalPages ?? 1), 'baseUrl' => $listBaseUrl ?? url_to('/micro')]) ?>
</div>
<?php get_footer(); ?>
