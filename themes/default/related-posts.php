<?php
/**
 * 默认主题相关推荐（覆盖系统 app/Views/theme/related-posts.php，只对默认主题生效）
 * 与系统模板的区别：只显示标题，不显示发布时间。
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
        <a href="<?= e(content_url('post', $rp)) ?>" class="related-post-link">
          <span class="related-post-title"><?= e($rp['title']) ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
