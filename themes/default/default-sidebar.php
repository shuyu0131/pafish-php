<?php
/** Default sidebar content shown until the site configures sidebar widgets. */
use Pafish\Core\DB;

if (!empty($hasCustomWidgets)) {
    return;
}

$categories = DB::fetchAll(
    "SELECT c.name, c.slug,
            (SELECT COUNT(*) FROM posts p
             WHERE p.category_id = c.id AND p.status = 'PUBLISHED'
               AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= NOW())) AS post_count
     FROM categories c
     WHERE EXISTS (
         SELECT 1 FROM posts p
         WHERE p.category_id = c.id AND p.status = 'PUBLISHED'
           AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= NOW())
     )
     ORDER BY c.sort_order ASC, c.id ASC
     LIMIT 8"
);
$recentPosts = DB::fetchAll(
    "SELECT title, slug, published_at, created_at
     FROM posts
     WHERE status = 'PUBLISHED' AND deleted_at IS NULL
       AND (published_at IS NULL OR published_at <= NOW())
     ORDER BY published_at DESC, created_at DESC
     LIMIT 5"
);
?>
<?php if ($categories !== []): ?>
<section class="sidebar-widget">
  <h2 class="sidebar-widget-title">分类</h2>
  <ul class="widget-list">
    <?php foreach ($categories as $category): ?>
      <li>
        <a href="<?= e(content_url('category', $category)) ?>"><?= e($category['name']) ?></a>
        <span class="widget-count"><?= (int) $category['post_count'] ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
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
