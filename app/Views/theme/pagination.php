<?php
/**
 * 分页（系统 fallback 模板；主题可覆盖 themes/{active}/pagination.php）
 * 可用数据：$page（当前页）、$totalPages、$baseUrl（列表页基础 URL，自动附加 ?page=N / &page=N）
 */
$page = max(1, (int) ($page ?? 1));
$totalPages = max(1, (int) ($totalPages ?? 1));
$baseUrl = $baseUrl ?? url_to('/');
$sep = str_contains($baseUrl, '?') ? '&' : '?';

// 页码窗口：当前页 ±2，始终含首尾
$pages = [];
for ($i = 1; $i <= $totalPages; $i++) {
    if ($i === 1 || $i === $totalPages || abs($i - $page) <= 2) {
        $pages[] = $i;
    }
}
$pages = array_values(array_unique($pages));
?>
<?php if ($totalPages > 1): ?>
<nav class="pagination" aria-label="分页">
  <?php if ($page > 1): ?>
    <a class="page-link" href="<?= e($baseUrl . $sep . 'page=' . ($page - 1)) ?>" aria-label="上一页">‹</a>
  <?php else: ?>
    <span class="page-link disabled">‹</span>
  <?php endif; ?>

  <?php
  $prev = 0;
  foreach ($pages as $p):
      if ($p - $prev > 1): ?>
        <span class="page-ellipsis">…</span>
      <?php endif; ?>
      <a class="page-link<?= $p === $page ? ' active' : '' ?>" href="<?= e($baseUrl . $sep . 'page=' . $p) ?>"><?= $p ?></a>
    <?php
      $prev = $p;
  endforeach;
  ?>

  <?php if ($page < $totalPages): ?>
    <a class="page-link" href="<?= e($baseUrl . $sep . 'page=' . ($page + 1)) ?>" aria-label="下一页">›</a>
  <?php else: ?>
    <span class="page-link disabled">›</span>
  <?php endif; ?>
</nav>
<?php endif; ?>
