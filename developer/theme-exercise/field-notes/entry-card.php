<?php

declare(strict_types=1);

$post = is_array($post ?? null) ? $post : [];
$externalUrl = trim((string) ($post['external_url'] ?? ''));
$postUrl = $externalUrl !== '' ? $externalUrl : url_to('/post/' . rawurlencode((string) ($post['slug'] ?? '')));
$coverUrl = \Pafish\Services\Content::safeImageUrl($post['cover_url'] ?? null);
$isProtected = (bool) ($post['is_password_protected'] ?? false);
?>
<article class="fn-entry">
  <span class="fn-entry-index" aria-hidden="true"><?= e(str_pad((string) ($loopIndex ?? ''), 2, '0', STR_PAD_LEFT)) ?></span>
  <div class="fn-entry-body">
    <p class="fn-entry-meta">
      <?php if (!empty($post['category_name'])): ?><span><?= e($post['category_name']) ?></span><span aria-hidden="true">·</span><?php endif; ?>
      <time><?= e(format_date($post['published_at'] ?? null)) ?></time>
      <?php if ($isProtected): ?><span class="fn-entry-badge">需密码</span><?php endif; ?>
    </p>
    <h2><a href="<?= e($postUrl) ?>"<?= $externalUrl !== '' ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= e($post['title'] ?? '') ?></a></h2>
    <?php if ($isProtected): ?>
      <p class="fn-entry-excerpt">这篇文章设有访问密码，打开后输入密码即可继续阅读。</p>
    <?php elseif (!empty($post['excerpt'])): ?>
      <p class="fn-entry-excerpt"><?= e($post['excerpt']) ?></p>
    <?php endif; ?>
    <?php if ($coverUrl !== null): ?><a class="fn-entry-cover" href="<?= e($postUrl) ?>"<?= $externalUrl !== '' ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><img src="<?= e($coverUrl) ?>" alt="" loading="lazy" decoding="async"></a><?php endif; ?>
  </div>
</article>
