<?php
/**
 * 标签页（系统默认模板；主题可覆盖 themes/{active}/tag.php）
 * 可用数据：$tag(name/slug)、$posts、$total、$pageNum、$totalPages、$listBaseUrl
 */
$tag = $tag ?? [];
$posts = $posts ?? [];
$total = (int) ($total ?? 0);
$pageNum = (int) ($pageNum ?? 1);
$totalPages = (int) ($totalPages ?? 1);
get_header();
?>
<div class="container">
  <header class="listing-head">
    <p class="eyebrow">标签</p>
    <h1 class="editorial listing-title"><?= e((string) ($tag['name'] ?? '')) ?></h1>
    <p class="listing-count">共 <?= $total ?> 篇文章</p>
  </header>

  <?php if ($posts === []): ?>
    <div class="empty-box">
      <p>该标签下还没有文章</p>
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
