<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$post = $post ?? [];
$link = lumina_post_link($post);
$external = trim((string) ($post['external_url'] ?? '')) !== '';
$cover = trim((string) ($post['cover_url'] ?? ''));
$avatar = lumina_theme_image('avatar_image', lumina_asset_url('img/tx.png'));
?>
<article class="lumina-moment">
  <img class="lumina-moment-avatar" src="<?= e($avatar) ?>" alt="">
  <div class="lumina-moment-main">
    <div class="lumina-moment-author"><?= e((string) ($post['author_name'] ?? theme_value('profile_name', site_name()))) ?></div>
    <h2 class="lumina-moment-title">
      <?php if (!empty($post['is_pinned'])): ?><span class="lumina-moment-badge">置顶</span><?php endif; ?>
      <?php if (!empty($post['password'])): ?><span title="该文章需要密码访问"><?= admin_icon('lock', 14) ?></span><?php endif; ?>
      <a href="<?= e($link) ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= e((string) ($post['title'] ?? '')) ?><?= $external ? ' ' . admin_icon('external-link', 14) : '' ?></a>
    </h2>
    <?php if (!empty($post['excerpt'])): ?><p class="lumina-moment-excerpt"><?= e((string) $post['excerpt']) ?></p><?php endif; ?>
    <?php if ($cover !== ''): ?><a class="lumina-moment-cover" href="<?= e($link) ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><img src="<?= e($cover) ?>" alt="<?= e((string) ($post['title'] ?? '')) ?>" loading="lazy"></a><?php endif; ?>
    <footer class="lumina-moment-meta">
      <span><?= e(format_date($post['published_at'] ?? null, 'Y-m-d')) ?></span>
      <?php if (!empty($post['category_slug'])): ?><a href="<?= e(url_to('/category/' . rawurlencode((string) $post['category_slug']))) ?>">#<?= e((string) $post['category_name']) ?></a><?php endif; ?>
      <?php foreach (($post['tags'] ?? []) as $tag): ?><a href="<?= e(url_to('/tag/' . rawurlencode((string) $tag['slug']))) ?>">#<?= e((string) $tag['name']) ?></a><?php endforeach; ?>
      <span class="lumina-moment-actions"><span class="lumina-moment-action"><?= admin_icon('eye', 13) ?><?= (int) ($post['view_count'] ?? 0) ?></span></span>
    </footer>
  </div>
</article>
