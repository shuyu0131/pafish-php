<?php
/** Default sidebar content shown until the site configures sidebar widgets. */
use Pafish\Core\DB;

if (!empty($hasCustomWidgets)) {
    return;
}

$recentPosts = DB::fetchAll(
    "SELECT title, slug, published_at, created_at
     FROM posts
     WHERE status = 'PUBLISHED' AND deleted_at IS NULL
       AND (published_at IS NULL OR published_at <= NOW())
     ORDER BY published_at DESC, created_at DESC
     LIMIT 5"
);
?>
<?php /* 分类：与后台配置的分类组件同一套渲染（层级、当前分类高亮、只显示有文章的分类），最多 8 个 */ ?>
<?= render_partial('sidebar-widgets', [
    'area' => 'sidebar',
    'defaultSidebarWidgets' => [['type' => 'categories', 'title' => '分类', 'limit' => 8]],
]) ?>
<?php if ($recentPosts !== []): ?>
<section class="sidebar-widget">
  <h2 class="sidebar-widget-title">最近文章</h2>
  <ul class="widget-list widget-recent-posts">
    <?php foreach ($recentPosts as $post): ?>
      <?php $publishedAt = $post['published_at'] ?: $post['created_at']; ?>
      <li>
        <a href="<?= e(content_url('post', $post)) ?>">
          <?= e($post['title']) ?>
          <time datetime="<?= e(format_date($publishedAt, 'Y-m-d')) ?>">
            <?= e(format_date($publishedAt, 'Y-m-d')) ?>
          </time>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
