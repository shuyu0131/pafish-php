<?php
$post = is_array($post ?? null) ? $post : [];
?>
<article class="starter-post-card">
  <p class="starter-kicker"><?php if (!empty($post['category_slug'])): ?><a href="<?= e(url_to('/category/' . rawurlencode((string) $post['category_slug']))) ?>"><?= e($post['category_name'] ?? '文章') ?></a><?php else: ?>文章<?php endif; ?><?php if (!empty($post['is_pinned'])): ?> · 置顶<?php endif; ?></p>
  <h2><a href="<?= e(url_to('/post/' . rawurlencode((string) ($post['slug'] ?? '')))) ?>"><?= e($post['title'] ?? '') ?></a></h2>
  <?php if (!empty($post['excerpt']) && empty($post['is_password_protected'])): ?><p><?= e($post['excerpt']) ?></p><?php endif; ?>
  <div class="starter-meta"><time><?= e(format_date($post['published_at'] ?? null)) ?></time><?php if (!empty($post['author_name'])): ?><span><?= e($post['author_name']) ?></span><?php endif; ?><?php if (isset($post['view_count'])): ?><span><?= (int) $post['view_count'] ?> 次浏览</span><?php endif; ?><?php foreach (($post['tags'] ?? []) as $tag): ?><a href="<?= e(url_to('/tag/' . rawurlencode((string) ($tag['slug'] ?? '')))) ?>">#<?= e($tag['name'] ?? '') ?></a><?php endforeach; ?></div>
</article>
