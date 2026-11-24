<?php
/**
 * 默认主题首页（覆盖系统 app/Views/theme/index.php）
 * 与系统模板相比，只在第 1 页顶部多一块自定义幻灯片（home-carousel.php，见主题设置「首页幻灯片」）；
 * 没有幻灯片内容时直接显示文章列表，置顶文章始终留在列表中。
 * 可用数据：$posts、$pageNum、$totalPages、$listBaseUrl、$emptyText
 */
$isFirstPage = (int) ($pageNum ?? 1) <= 1;
$carouselHtml = $isFirstPage ? trim(render_partial('home-carousel')) : '';
get_header();
?>
<div class="container<?= $carouselHtml !== '' ? ' has-carousel' : '' ?>">
  <?= $carouselHtml ?>

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
