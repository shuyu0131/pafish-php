<?php
/**
 * 相关推荐（系统默认模板；主题可覆盖 themes/{active}/related-posts.php）
 * 可用数据：$posts（同分类或共享标签的文章）
 */
$posts = $posts ?? [];
if (!$posts) {
    return;
}
?>
<section class="related-posts">
  <h2 class="related-posts-title">相关推荐</h2>
  <ul class="related-posts-list">
    <?php foreach ($posts as $rp): ?>
      <li>
        <a href="<?= e(url_to('/post/' . rawurlencode((string) $rp['slug']))) ?>" class="related-post-link">
          <span class="related-post-title"><?= e($rp['title']) ?></span>
          <span class="related-post-date"><?= e(format_date($rp['published_at'] ?? null)) ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
