<?php
/** 微语数据：$items、$pageNum、$totalPages、$total、$listBaseUrl。 */
$items = is_array($items ?? null) ? $items : [];
get_header();
?>
<header class="starter-page-head"><p class="starter-kicker">短记与近况</p><h1 class="starter-title">微语</h1><p class="starter-description">共 <?= (int) ($total ?? count($items)) ?> 条</p></header>
<?php if ($items === []): ?><p class="starter-empty">还没有公开微语。</p><?php else: ?><div class="starter-micro-list"><?php foreach ($items as $micro): ?><?= render_partial('micro-card', ['micro' => $micro]) ?><?php endforeach; ?></div><?php endif; ?>
<?= render_partial('pagination', ['page' => $pageNum ?? 1, 'totalPages' => $totalPages ?? 1, 'baseUrl' => $listBaseUrl ?? url_to('/micro')]) ?>
<?php get_footer(); ?>
