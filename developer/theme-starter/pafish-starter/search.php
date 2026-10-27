<?php
/** 搜索数据：$keyword、$posts、$total、$pageNum、$totalPages、$listBaseUrl。 */
$posts = is_array($posts ?? null) ? $posts : [];
$keyword = (string) ($keyword ?? '');
get_header();
?>
<header class="starter-page-head"><p class="starter-kicker">站内搜索</p><h1 class="starter-title"><?= $keyword !== '' ? '“' . e($keyword) . '”' : '搜索' ?></h1><?php if ($keyword !== ''): ?><p class="starter-description">找到 <?= (int) ($total ?? 0) ?> 篇文章</p><?php endif; ?></header>
<?php if ($keyword === ''): ?><p class="starter-empty">输入关键词后开始搜索。</p><?php elseif ($posts === []): ?><p class="starter-empty">没有找到相关文章。</p><?php else: ?><div class="starter-post-list"><?php foreach ($posts as $post): ?><?= render_partial('entry-card', ['post' => $post]) ?><?php endforeach; ?></div><?php endif; ?>
<?= render_partial('pagination', ['page' => $pageNum ?? 1, 'totalPages' => $totalPages ?? 1, 'baseUrl' => $listBaseUrl ?? url_to('/search')]) ?>
<?php get_footer(); ?>
