<?php
/**
 * 默认主题文章卡片（覆盖系统 app/Views/theme/post-card.php）
 * 与系统模板的数据和链接完全一致，只补了几个样式钩子：
 * - article 带封面时加 has-cover（不依赖 CSS :has()）
 * - 日期 post-meta-date、分类 post-meta-cat、标签 post-meta-tag
 * - 摘要去掉 Markdown 语法，只显示纯文字
 * 可用数据：$post（含 title/slug/excerpt/cover_url/published_at/is_pinned/category_pinned/
 *               is_password_protected/external_url/view_count/author_name/category_name/category_slug/tags[]）
 */
$post = $post ?? [];
$isExternal = \Pafish\Services\Content::safeLinkUrl($post['external_url'] ?? null, false) ?? '';
$isPinned = (bool) ($post['is_pinned'] ?? false);
$isCategoryPinned = (bool) ($post['category_pinned'] ?? false);
$hasPassword = (bool) ($post['is_password_protected'] ?? $post['password'] ?? false);
$coverUrl = \Pafish\Services\Content::safeImageUrl($post['cover_url'] ?? null);
// 摘要只显示纯文字：手写或导入的摘要里可能带 Markdown（**粗体**、[链接](…)、`代码`、# 标题），先渲染再去掉标签
$excerptText = trim((string) ($post['excerpt'] ?? ''));
if ($excerptText !== '') {
    $excerptText = \Pafish\Services\Content::plain($excerptText, 'markdown');
}
$postUrl = $isExternal !== '' ? $isExternal : content_url('post', $post);
$linkAttrs = $isExternal !== '' ? ' target="_blank" rel="noopener noreferrer"' : '';
?>
<article class="post-card<?= $coverUrl !== null ? ' has-cover' : '' ?>">
  <?php if ($coverUrl !== null): ?>
  <a class="post-card-cover" href="<?= e($postUrl) ?>"<?= $linkAttrs ?> aria-label="<?= e($post['title']) ?>" tabindex="-1">
    <img src="<?= e($coverUrl) ?>" alt="" loading="lazy" decoding="async">
  </a>
  <?php endif; ?>
  <h2 class="post-card-title">
    <?php if ($isPinned): ?><span class="post-badge">置顶</span><?php endif; ?>
    <?php if ($isCategoryPinned): ?><span class="post-badge post-badge-dim">分类置顶</span><?php endif; ?>
    <?php if ($hasPassword): ?><span class="post-lock" title="该文章需要密码访问"><?= admin_icon('lock', 14) ?></span><?php endif; ?>
    <a href="<?= e($postUrl) ?>"<?= $linkAttrs ?> class="post-card-link"><?= e($post['title']) ?><?php if ($isExternal !== ''): ?><span class="post-external"><?= admin_icon('external-link', 13) ?></span><?php endif; ?></a>
  </h2>

  <?php if ($hasPassword): ?>
    <p class="post-card-excerpt post-card-password-note">该文章已加密，请点击标题输入密码访问。</p>
  <?php elseif ($excerptText !== ''): ?>
    <p class="post-card-excerpt"><?= e($excerptText) ?></p>
  <?php endif; ?>

  <div class="post-card-meta">
    <?php if (!empty($post['category_slug'])): ?>
      <a href="<?= e(content_url('category', ['id' => $post['category_id'] ?? 0, 'slug' => $post['category_slug']])) ?>" class="post-meta-link post-meta-cat"><?= e($post['category_name']) ?></a>
    <?php endif; ?>
    <time class="post-meta-date"><?= e(format_date($post['published_at'] ?? null)) ?></time>
    <?php foreach (($post['tags'] ?? []) as $tag): ?>
      <a href="<?= e(content_url('tag', $tag)) ?>" class="post-meta-link post-meta-tag">#<?= e($tag['name']) ?></a>
    <?php endforeach; ?>
    <span class="post-meta-views"><?= (int) ($post['view_count'] ?? 0) ?> 次浏览</span>
  </div>
</article>
