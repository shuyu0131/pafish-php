<?php
/**
 * 分类页（系统 fallback 模板；主题可覆盖 themes/{active}/category.php）
 * 可用数据：$category(name/slug/description)、$posts、$total、$pageNum、$totalPages、$listBaseUrl
 */
$category = $category ?? [];
$posts = $posts ?? [];
$total = (int) ($total ?? 0);
$pageNum = (int) ($pageNum ?? 1);
$totalPages = (int) ($totalPages ?? 1);
get_header();
?>
<div class="container">
  <header class="listing-head">
    <p class="eyebrow">分类</p>
    <h1 class="editorial listing-title"><?= e((string) ($category['name'] ?? '')) ?></h1>
    <?php if (!empty($category['description'])): ?>
      <p class="listing-desc"><?= e((string) $category['description']) ?></p>
    <?php endif; ?>
    <p class="listing-count">共 <?= $total ?> 篇文章</p>
  </header>

  <?php if ($posts === []): ?>
    <div class="empty-box">
      <p>该分类下还没有文章</p>
    </div>
  <?php else: ?>
    <div class="post-list">
      <?php foreach ($posts as $p): ?>
        <?= render_partial('post-card', ['post' => $p]) ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?= render_partial('pagination', [
      'page' => $pageNum,
      'totalPages' => $totalPages,
      'baseUrl' => $listBaseUrl ?? '',
  ]) ?>
</div>
<?php
get_footer();
