<?php

declare(strict_types=1);

$links = friend_links();
?>
<?php if ($links): ?>
<section class="lumina-sidecard lumina-friend-links"><h2 class="lumina-sidecard-title">友情链接</h2><ul class="lumina-side-list"><?php foreach ($links as $link): ?><li><a href="<?= e((string) $link['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $link['name']) ?></a></li><?php endforeach; ?></ul></section>
<?php endif; ?>
