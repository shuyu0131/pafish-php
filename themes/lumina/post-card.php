<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$post = $post ?? [];
$link = lumina_post_link($post);
$external = trim((string) ($post['external_url'] ?? '')) !== '';
$cover = trim((string) ($post['cover_url'] ?? ''));
$avatar = lumina_theme_image('avatar_image', lumina_asset_url('img/tx.png'));
$media = lumina_media($post);
// 点赞状态与 PostController 一致：cookie 列表（liked_posts）记录当前浏览器已赞文章的 id
$cardLiked = in_array((string) ($post['id'] ?? ''), array_filter(explode(',', (string) ($_COOKIE['liked_posts'] ?? ''))), true);
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
      <?= lumina_render_media($media, (int) ($post['id'] ?? 0)) ?>
      <?php if ($media['type'] === 'only' && $cover !== ''): ?><a class="lumina-moment-cover" href="<?= e($link) ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><img src="<?= e($cover) ?>" alt="<?= e((string) ($post['title'] ?? '')) ?>" loading="lazy"></a><?php endif; ?>
      <?php if ($media['location'] !== ''): ?><div class="lumina-location"><?= admin_icon('pin', 14) ?><?php if (($locationLink = lumina_location_link($media)) !== ''): ?><a href="<?= e($locationLink) ?>" target="_blank" rel="noopener noreferrer"><?= e($media['location']) ?></a><?php else: ?><span><?= e($media['location']) ?></span><?php endif; ?><?php if ($media['locationAddress'] !== ''): ?><small><?= e($media['locationAddress']) ?></small><?php endif; ?></div><?php endif; ?>
    <footer class="lumina-moment-meta">
      <span><?= e(format_date($post['published_at'] ?? null, 'Y-m-d')) ?></span>
      <?php if ($media['private']): ?><span class="lumina-private-badge"><?= admin_icon('lock', 12) ?> 仅自己可见</span><?php endif; ?>
      <?php if (!empty($post['category_slug'])): ?><a href="<?= e(url_to('/category/' . rawurlencode((string) $post['category_slug']))) ?>">#<?= e((string) $post['category_name']) ?></a><?php endif; ?>
      <?php foreach (($post['tags'] ?? []) as $tag): ?><a href="<?= e(url_to('/tag/' . rawurlencode((string) $tag['slug']))) ?>">#<?= e((string) $tag['name']) ?></a><?php endforeach; ?>
      <span class="lumina-moment-actions">
        <button type="button" class="lumina-moment-action lumina-action-like<?= $cardLiked ? ' is-active' : '' ?>" data-action="like" data-id="<?= (int) ($post['id'] ?? 0) ?>" data-active="<?= $cardLiked ? '1' : '0' ?>" title="点赞"><?= admin_icon('heart', 13, $cardLiked) ?><b class="lumina-action-count"><?= (int) ($post['like_count'] ?? 0) ?></b></button>
        <a class="lumina-moment-action" href="<?= e($link) ?>#comments" title="评论"><?= admin_icon('message', 13) ?><b><?= (int) ($post['comment_count'] ?? 0) ?></b></a>
        <span class="lumina-moment-action"><?= admin_icon('eye', 13) ?><?= (int) ($post['view_count'] ?? 0) ?></span>
      </span>
    </footer>
  </div>
</article>
