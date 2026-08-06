<?php
/**
 * 文章归档页（系统 fallback 模板；主题可覆盖 themes/{active}/archives.php）
 * 可用数据：$groups（[['month' => '2026年08月', 'posts' => [...]]]）、$total
 */
$groups = $groups ?? [];
$total = (int) ($total ?? 0);
get_header();
?>
<div class="container container-narrow">
  <header class="archive-head">
    <h1 class="archive-title">文章归档</h1>
    <p class="archive-sub">共 <?= $total ?> 篇文章</p>
  </header>

  <?php if ($groups === []): ?>
    <p class="archive-empty">还没有文章</p>
  <?php else: ?>
    <div class="archive-groups">
      <?php foreach ($groups as $g): ?>
        <section class="archive-group">
          <h2 class="archive-month">
            <?= e($g['month']) ?>
            <span class="archive-month-count">(<?= count($g['posts']) ?>)</span>
          </h2>
          <ul class="archive-list">
            <?php foreach ($g['posts'] as $p): ?>
              <li>
                <a href="<?= e(url_to('/post/' . rawurlencode((string) $p['slug']))) ?>" class="archive-item">
                  <span class="archive-item-title"><?= e($p['title']) ?></span>
                  <span class="archive-item-date"><?= e(format_date($p['published_at'] ?? null, 'MM-dd')) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
get_footer();
