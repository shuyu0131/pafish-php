<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$post = $post ?? [];
$id = (int) ($post['id'] ?? 0);
$link = lumina_post_link($post);
$external = trim((string) ($post['external_url'] ?? '')) !== '';
$avatar = trim((string) ($post['author_avatar'] ?? '')) ?: lumina_theme_image('avatar_image', lumina_asset_url('img/tx.png'));
$media = lumina_media($post);
$text = trim((string) ($post['excerpt'] ?? ''));
if ($text === '') {
    $text = trim(strip_tags((string) ($post['content'] ?? '')));
}
$textLimit = 220;
$textLength = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
$hasMoreText = $textLength > $textLimit;
$previewText = $hasMoreText
    ? (function_exists('mb_substr') ? mb_substr($text, 0, $textLimit, 'UTF-8') : substr($text, 0, $textLimit)) . '...'
    : $text;
$comments = is_array($post['recent_comments'] ?? null) ? $post['recent_comments'] : [];
$liked = in_array((string) $id, array_filter(explode(',', (string) ($_COOKIE['liked_posts'] ?? ''))), true);
?>
<article class="sh-content" id="sh-content-<?= $id ?>">
  <div class="sh-content-left"><a href="<?= e($link) ?>"><img src="<?= e($avatar) ?>" alt=""></a></div>
  <div class="sh-content-right">
    <div class="sh-content-right-head">
      <div class="sh-content-right-head-title"><p><a class="sh-author-link" href="<?= e($link) ?>"><?= e((string) ($post['author_name'] ?? theme_value('profile_name', site_name()))) ?></a><?php if (!empty($post['is_pinned'])): ?> <span class="lumina-post-status">置顶</span><?php endif; ?><?php if (!empty($post['password'])): ?> <span class="lumina-post-status">私密</span><?php endif; ?></p></div>
      <?php if ($text !== '' || !empty($post['title'])): ?><div class="sh-content-right-article"><span><?php if (!empty($post['title'])): ?><a href="<?= e($link) ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><strong><?= e((string) $post['title']) ?></strong></a><?php endif; ?><?php if ($text !== ''): ?><?= !empty($post['title']) ? '<br>' : '' ?><span class="lumina-text-preview"<?= $hasMoreText ? '' : ' style="display:none"' ?>><?= nl2br(e($previewText)) ?></span><?php if ($hasMoreText): ?><span class="lumina-text-full" style="display:none"><?= nl2br(e($text)) ?></span><a href="#" class="sh-content-quanwenan" data-lumina-expand>全文</a><?php else: ?><?= nl2br(e($text)) ?><?php endif; ?><?php endif; ?></span></div><?php endif; ?>
      <?= lumina_render_media($media, $id) ?>
      <?php if ($media['type'] === 'only' && !empty($post['cover_url'])): ?><div class="sh-content-right-img"><a class="sh-content-right-img-pic" href="<?= e((string) $post['cover_url']) ?>" data-lumina-image="<?= e((string) $post['cover_url']) ?>"><img src="<?= e((string) $post['cover_url']) ?>" alt="" loading="lazy"></a></div><?php endif; ?>
      <?php if ($media['location'] !== ''): ?><div class="sh-content-right-gps"><?php if (($location = lumina_location_link($media)) !== ''): ?><a href="<?= e($location) ?>" target="_blank" rel="noopener noreferrer"><?= e($media['location']) ?></a><?php else: ?><a><?= e($media['location']) ?></a><?php endif; ?></div><?php endif; ?>
    </div>
    <div class="sh-content-right-time">
      <div class="sh-content-right-time-left"><span><?= e(format_date($post['published_at'] ?? null, 'Y-m-d')) ?></span><?php if (!empty($post['category_slug'])): ?><a class="lumina-card-tag" href="<?= e(url_to('/category/' . rawurlencode((string) $post['category_slug']))) ?>">#<?= e((string) $post['category_name']) ?></a><?php endif; ?></div>
      <div class="sh-content-right-time-right">
        <div class="sh-content-right-time-right-left" data-lumina-action-menu>
          <button type="button" class="sh-content-right-time-right-left-z<?= $liked ? ' is-active' : '' ?>" data-action="like" data-id="<?= $id ?>" data-active="<?= $liked ? '1' : '0' ?>"><i class="iconfont icon-aixin"></i><span>赞 <b class="lumina-action-count"><?= (int) ($post['like_count'] ?? 0) ?></b></span></button>
          <p></p><a class="sh-content-right-time-right-left-y" href="<?= e($link) ?>#comments"><i class="iconfont icon-pinglun2"></i><span>评论 <?= (int) ($post['comment_count'] ?? 0) ?></span></a>
        </div>
        <button type="button" class="sh-content-right-time-right-right" data-lumina-action-toggle aria-label="打开操作菜单"><p class="zp1"></p><p></p></button>
      </div>
    </div>
    <?php if ($comments !== []): ?><div class="sh-zanp"><ul class="sh-zanp-pl"><?php foreach ($comments as $comment): ?><li><b><?= e((string) ($comment['author_name'] ?? '访客')) ?>：</b><?= e((string) ($comment['content'] ?? '')) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  </div>
</article>
