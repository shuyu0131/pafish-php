<?php
/**
 * 首页（系统默认模板；主题可覆盖 themes/{active}/index.php）
 * 可用数据：$posts、$pageNum、$totalPages、$listBaseUrl、$emptyText
 */
get_header();
?>
<div class="container">
  <?php if (!$posts): ?>
    <div class="empty-box"><p><?= e($emptyText ?? '还没有文章，敬请期待') ?></p></div>
  <?php else: ?>
    <div class="post-list">
      <?php foreach ($posts as $post): ?>
        <?= render_partial('post-card', ['post' => $post]) ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (($totalPages ?? 1) > 1): ?>
    <?= render_partial('pagination', [
        'page' => $pageNum,
        'totalPages' => $totalPages,
        'baseUrl' => $listBaseUrl ?? url_to('/'),
    ]) ?>
  <?php endif; ?>

  <?= render_partial('friend-links') ?>
</div>
<?php
get_footer();
