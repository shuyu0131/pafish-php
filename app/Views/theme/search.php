<?php
/**
 * 搜索页（系统 fallback 模板；主题可覆盖 themes/{active}/search.php）
 * 可用数据：$keyword、$posts、$total、$pageNum、$totalPages、$listBaseUrl
 */
$keyword = (string) ($keyword ?? '');
$posts = $posts ?? [];
$total = (int) ($total ?? 0);
$pageNum = (int) ($pageNum ?? 1);
$totalPages = (int) ($totalPages ?? 1);
get_header();
?>
<div class="container">
  <header class="listing-head">
    <p class="eyebrow">搜索</p>
    <h1 class="editorial listing-title">
      <?php if ($keyword !== ''): ?>“<?= e($keyword) ?>” 的搜索结果<?php else: ?>搜索<?php endif; ?>
    </h1>
    <?php if ($keyword !== ''): ?>
      <p class="listing-count">共找到 <?= $total ?> 篇文章</p>
    <?php endif; ?>
  </header>

  <?php if ($keyword === ''): ?>
    <div class="empty-box">
      <p>输入关键词开始搜索</p>
    </div>
  <?php elseif ($posts === []): ?>
    <div class="empty-box">
      <p>没有找到相关文章</p>
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
