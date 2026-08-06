<?php
/**
 * 友情链接（首页底部；系统 fallback 模板）
 */
$links = friend_links();
?>
<?php if ($links): ?>
<div class="friend-links">
  <?php foreach ($links as $link): ?>
    <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener noreferrer" class="friend-link"
       <?php if (!empty($link['description'])): ?>title="<?= e($link['description']) ?>"<?php endif; ?>>
      <?= e($link['name']) ?>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
