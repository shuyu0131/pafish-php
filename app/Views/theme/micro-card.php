<?php
$micro = $micro ?? [];
$content = \Pafish\Services\MicroStatuses::contentWithMedia(
    (string) ($micro['content'] ?? ''),
    is_array($micro['media'] ?? null) ? $micro['media'] : [],
    (string) ($micro['contentFormat'] ?? 'markdown')
);
?>
<article class="micro-card"><div class="micro-card-head"><strong><?= e((string) ($micro['author']['name'] ?? '')) ?></strong><time datetime="<?= e((string) ($micro['publishedAt'] ?? '')) ?>"><?= e(format_date($micro['publishedAt'] ?? null)) ?></time><?php if (!empty($micro['isPinned'])): ?><span class="post-badge">置顶</span><?php endif; ?><?php if (!empty($micro['isPrivate'])): ?><span class="post-badge micro-badge-private">私密</span><?php endif; ?></div><div class="micro-card-content md-content pafish-content"><?= content_html($content, (string) ($micro['contentFormat'] ?? 'markdown')) ?></div></article>
