<?php
$page = max(1, (int) ($page ?? 1));
$totalPages = max(1, (int) ($totalPages ?? 1));
$page = min($page, $totalPages);
$baseUrl = (string) ($baseUrl ?? url_to('/'));
$separator = str_contains($baseUrl, '?') ? '&' : '?';
if ($totalPages > 1):
  $numbers = range(max(1, $page - 2), min($totalPages, $page + 2));
?>
<nav class="starter-pagination" aria-label="分页">
  <?php if ($page > 1): ?><a href="<?= e($baseUrl . $separator . 'page=' . ($page - 1)) ?>" rel="prev" aria-label="上一页">‹</a><?php endif; ?>
  <?php if ($numbers[0] > 1): ?><a href="<?= e($baseUrl . $separator . 'page=1') ?>">1</a><?php if ($numbers[0] > 2): ?><span aria-hidden="true">…</span><?php endif; ?><?php endif; ?>
  <?php foreach ($numbers as $number): if ($number === $page): ?><span class="is-current" aria-current="page"><?= $number ?></span><?php else: ?><a href="<?= e($baseUrl . $separator . 'page=' . $number) ?>"><?= $number ?></a><?php endif; endforeach; ?>
  <?php if (end($numbers) < $totalPages): ?><?php if (end($numbers) < $totalPages - 1): ?><span aria-hidden="true">…</span><?php endif; ?><a href="<?= e($baseUrl . $separator . 'page=' . $totalPages) ?>"><?= $totalPages ?></a><?php endif; ?>
  <?php if ($page < $totalPages): ?><a href="<?= e($baseUrl . $separator . 'page=' . ($page + 1)) ?>" rel="next" aria-label="下一页">›</a><?php endif; ?>
</nav>
<?php endif; ?>
