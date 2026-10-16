<div class="admin-dashboard-stats">
  <?php foreach ($stats as $stat): ?>
    <?php $tag = $stat['url'] ? 'a' : 'div'; ?>
    <<?= $tag ?> class="card admin-overview-card"<?php if ($stat['url']): ?> href="<?= e(!empty($statsUrlsResolved) ? $stat['url'] : url_to($stat['url'])) ?>"<?php endif; ?>>
      <div class="admin-overview-label"><span class="admin-overview-icon"><?= admin_icon($stat['icon'], 18) ?></span><span><?= e($stat['label']) ?></span></div>
      <p class="admin-overview-value"><?= number_format((int) $stat['value']) ?></p>
    </<?= $tag ?>>
  <?php endforeach; ?>
</div>
