<?php
/**
 * 文章列表卡片（系统默认模板；主题可覆盖 themes/{active}/post-card.php）
 * 可用数据：$post（含 title/slug/excerpt/cover_url/published_at/is_pinned/category_pinned/
 *               password/external_url/view_count/author_name/category_name/category_slug/tags[]）
 */
$post = $post ?? [];
$isExternal = trim((string) ($post['external_url'] ?? ''));
$isPinned = (bool) ($post['is_pinned'] ?? false);
$isCategoryPinned = (bool) ($post['category_pinned'] ?? false);
$hasPassword = (bool) ($post['password'] ?? false);
?>
<article class="post-card">
  <h2 class="post-card-title">
    <?php if ($isPinned): ?><span class="post-badge">置顶</span><?php endif; ?>
    <?php if ($isCategoryPinned): ?><span class="post-badge post-badge-dim">分类置顶</span><?php endif; ?>
    <?php if ($hasPassword): ?><span class="post-lock" title="该文章需要密码访问"><?= admin_icon('lock', 13) ?></span><?php endif; ?>
    <?php if ($isExternal !== ''): ?>
      <a href="<?= e($isExternal) ?>" target="_blank" rel="noopener noreferrer" class="post-card-link"><?= e($post['title']) ?><span class="post-external"><?= admin_icon('external-link', 13) ?></span></a>
    <?php else: ?>
      <a href="<?= e(url_to('/post/' . rawurlencode((string) $post['slug']))) ?>" class="post-card-link"><?= e($post['title']) ?></a>
    <?php endif; ?>
  </h2>

  <?php if (!empty($post['excerpt'])): ?>
    <p class="post-card-excerpt"><?= e($post['excerpt']) ?></p>
  <?php endif; ?>

  <div class="post-card-meta">
    <span><?= e(format_date($post['published_at'] ?? null)) ?></span>
    <?php if (!empty($post['category_slug'])): ?>
      <a href="<?= e(url_to('/category/' . rawurlencode((string) $post['category_slug']))) ?>" class="post-meta-link"><?= e($post['category_name']) ?></a>
    <?php endif; ?>
    <?php foreach (($post['tags'] ?? []) as $tag): ?>
      <a href="<?= e(url_to('/tag/' . rawurlencode((string) $tag['slug']))) ?>" class="post-meta-link">#<?= e($tag['name']) ?></a>
    <?php endforeach; ?>
    <span class="post-meta-views"><?= (int) ($post['view_count'] ?? 0) ?> 次浏览</span>
  </div>
</article>
