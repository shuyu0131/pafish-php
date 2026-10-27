<?php
/** 首页数据：$posts、$pageNum、$totalPages、$listBaseUrl、$emptyText。 */
$posts = is_array($posts ?? null) ? $posts : [];
get_header();
?>
<header class="starter-page-head">
  <p class="starter-kicker">最新发布</p>
  <h1 class="starter-title"><?= e((string) ($title ?? site_name())) ?></h1>
  <?php if (!empty($description)): ?><p class="starter-description"><?= e($description) ?></p><?php endif; ?>
</header>
<?php if ($posts === []): ?>
  <p class="starter-empty"><?= e($emptyText ?? '这里还没有文章。') ?></p>
<?php else: ?>
  <div class="starter-post-list">
    <?php foreach ($posts as $post): ?><?= render_partial('entry-card', ['post' => $post]) ?><?php endforeach; ?>
  </div>
<?php endif; ?>
<?= render_partial('pagination', ['page' => $pageNum ?? 1, 'totalPages' => $totalPages ?? 1, 'baseUrl' => $listBaseUrl ?? url_to('/')]) ?>
<?php get_footer(); ?>
