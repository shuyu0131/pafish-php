<?php
/**
 * 友情链接（首页底部；系统默认模板）
 */
$links = friend_links();
?>
<?php if ($links): ?>
<div class="friend-links">
  <?php foreach ($links as $link): ?>
    <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener noreferrer" class="friend-link"
       <?php if (!empty($link['description'])): ?>title="<?= e($link['description']) ?>"<?php endif; ?>>
      <?php if (!empty($link['icon_url'])): ?><img src="<?= e($link['icon_url']) ?>" alt="" width="20" height="20" loading="lazy"><?php endif; ?>
      <?= e($link['name']) ?>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
