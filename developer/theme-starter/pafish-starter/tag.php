<?php
/** 标签数据：$tag、$posts、$total、$pageNum、$totalPages、$listBaseUrl。 */
$tag = is_array($tag ?? null) ? $tag : [];
$posts = is_array($posts ?? null) ? $posts : [];
get_header();
?>
<header class="starter-page-head"><p class="starter-kicker">标签 · <?= (int) ($total ?? 0) ?> 篇</p><h1 class="starter-title">#<?= e($tag['name'] ?? '') ?></h1></header>
<?php if ($posts === []): ?><p class="starter-empty">这个标签还没有文章。</p><?php else: ?><div class="starter-post-list"><?php foreach ($posts as $post): ?><?= render_partial('entry-card', ['post' => $post]) ?><?php endforeach; ?></div><?php endif; ?>
<?= render_partial('pagination', ['page' => $pageNum ?? 1, 'totalPages' => $totalPages ?? 1, 'baseUrl' => $listBaseUrl ?? url_to('/tag/' . rawurlencode((string) ($tag['slug'] ?? '')))]) ?>
<?php get_footer(); ?>
