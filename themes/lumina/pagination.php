<?php

declare(strict_types=1);

$page = max(1, (int) ($page ?? 1));
$totalPages = max(1, (int) ($totalPages ?? 1));
$baseUrl = (string) ($baseUrl ?? url_to('/'));
$sep = str_contains($baseUrl, '?') ? '&' : '?';
?>
<?php if ($totalPages > 1): ?>
<nav class="lumina-pagination" aria-label="分页">
  <?php if ($page > 1): ?><a class="lumina-page-link" href="<?= e($baseUrl . $sep . 'page=' . ($page - 1)) ?>" aria-label="上一页">‹</a><?php else: ?><span class="lumina-page-link disabled">‹</span><?php endif; ?>
  <?php for ($i = 1; $i <= $totalPages; $i++): ?>
    <?php if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1): ?><a class="lumina-page-link<?= $i === $page ? ' active' : '' ?>" href="<?= e($baseUrl . $sep . 'page=' . $i) ?>"><?= $i ?></a><?php elseif ($i === 2 || $i === $totalPages - 1): ?><span class="lumina-page-ellipsis">…</span><?php endif; ?>
  <?php endfor; ?>
  <?php if ($page < $totalPages): ?><a class="lumina-page-link" href="<?= e($baseUrl . $sep . 'page=' . ($page + 1)) ?>" aria-label="下一页">›</a><?php else: ?><span class="lumina-page-link disabled">›</span><?php endif; ?>
</nav>
<?php endif; ?>
