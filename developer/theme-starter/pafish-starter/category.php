<?php
/** 分类数据：$category、$posts、$total、$pageNum、$totalPages、$listBaseUrl。 */
$category = is_array($category ?? null) ? $category : [];
$posts = is_array($posts ?? null) ? $posts : [];
get_header();
?>
<header class="starter-page-head"><p class="starter-kicker">分类 · <?= (int) ($total ?? 0) ?> 篇</p><h1 class="starter-title"><?= e($category['name'] ?? '') ?></h1><?php if (!empty($category['description'])): ?><p class="starter-description"><?= e($category['description']) ?></p><?php endif; ?></header>
<?php if ($posts === []): ?><p class="starter-empty">这个分类还没有文章。</p><?php else: ?><div class="starter-post-list"><?php foreach ($posts as $post): ?><?= render_partial('entry-card', ['post' => $post]) ?><?php endforeach; ?></div><?php endif; ?>
<?= render_partial('pagination', ['page' => $pageNum ?? 1, 'totalPages' => $totalPages ?? 1, 'baseUrl' => $listBaseUrl ?? url_to('/category/' . rawurlencode((string) ($category['slug'] ?? '')))]) ?>
<?php get_footer(); ?>
