<?php $links = friend_links(); if ($links !== []): ?>
<nav aria-label="友情链接" class="starter-meta"><?php foreach ($links as $link): ?><a href="<?= e($link['url'] ?? '#') ?>" target="_blank" rel="noopener noreferrer"><?= e($link['name'] ?? '') ?></a><?php endforeach; ?></nav>
<?php endif; ?>
