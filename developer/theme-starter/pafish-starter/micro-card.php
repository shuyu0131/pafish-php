<?php
$micro = is_array($micro ?? null) ? $micro : [];
$text = \Pafish\Services\MicroStatuses::contentWithMedia((string) ($micro['content'] ?? ''), is_array($micro['media'] ?? null) ? $micro['media'] : [], (string) ($micro['contentFormat'] ?? 'markdown'));
?>
<article class="micro-card"><header class="micro-card-head"><strong><?= e($micro['author']['name'] ?? '') ?></strong><?php if (!empty($micro['publishedAt'])): ?><time datetime="<?= e($micro['publishedAt']) ?>"><?= e(format_date($micro['publishedAt'])) ?></time><?php endif; ?><?php if (!empty($micro['isPinned'])): ?><span class="post-badge">置顶</span><?php endif; ?></header><div class="micro-card-content md-content pafish-content"><?= content_html($text, (string) ($micro['contentFormat'] ?? 'markdown')) ?></div></article>
