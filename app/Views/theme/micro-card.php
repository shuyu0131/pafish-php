<?php
$micro = $micro ?? [];
$content = (string) ($micro['content'] ?? '');
?>
<article class="micro-card"><div class="micro-card-head"><strong><?= e((string) ($micro['author']['name'] ?? '')) ?></strong><time datetime="<?= e((string) ($micro['publishedAt'] ?? '')) ?>"><?= e(format_date($micro['publishedAt'] ?? null)) ?></time><?php if (!empty($micro['isPinned'])): ?><span class="post-badge">置顶</span><?php endif; ?><?php if (!empty($micro['isPrivate'])): ?><span class="post-badge micro-badge-private">私密</span><?php endif; ?></div><div class="micro-card-content md-content"><?= md($content) ?></div><?php if (!empty($micro['media'])): ?><div class="micro-card-media"><?php foreach ($micro['media'] as $url): ?><a href="<?= e((string) $url) ?>" target="_blank" rel="noopener"><img src="<?= e((string) $url) ?>" alt="" loading="lazy"></a><?php endforeach; ?></div><?php endif; ?></article>
