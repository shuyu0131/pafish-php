<?php /** Account overview and paginated personal activity; independent of the front-end theme. */ ?>
<div class="admin-stack admin-dashboard admin-account">
  <header class="admin-page-head">
    <div><h1 class="admin-h1">个人中心</h1><p class="admin-page-sub"><?= e((string) ($user['nickname'] ?: $user['username'])) ?></p></div>
  </header>
  <?php if ($section === 'overview'): ?>
    <?php $statsUrlsResolved = true; require __DIR__ . '/partials/dashboard-stats.php'; ?>
  <?php else: ?>
    <section class="card admin-dashboard-panel">
      <div class="admin-dashboard-panel-head"><h2><?= e($sections[$section]) ?></h2><span class="admin-muted">共 <?= (int) $total ?> 条</span></div>
      <?php if ($items === []): ?><p class="admin-empty">暂无记录。</p><?php else: ?>
        <ul class="admin-account-list">
          <?php foreach ($items as $item): ?>
            <li>
              <div class="admin-account-list-main">
                <?php if ($section === 'points'): ?>
                  <span class="admin-account-amount"><?= (int) $item['amount'] > 0 ? '+' : '' ?><?= (int) $item['amount'] ?></span><span><?= e($item['reason']) ?></span>
                <?php else: ?>
                  <a href="<?= e(url_to('/post/' . rawurlencode((string) $item['slug'])) . ($section === 'comments' ? '#comment-' . (int) $item['id'] : '')) ?>"><?= e($item['title']) ?></a>
                  <?php if ($section === 'comments'): ?><p><?= e($item['content']) ?></p><span class="badge<?= $item['status'] === 'APPROVED' ? ' badge-success' : '' ?>"><?= e(['APPROVED' => '已显示', 'PENDING' => '待审核', 'REJECTED' => '未通过', 'TRASH' => '已删除'][$item['status']] ?? '未显示') ?></span><?php endif; ?>
                <?php endif; ?>
              </div>
              <time><?= e(format_date($item['created_at'], 'yyyy-MM-dd HH:mm')) ?></time>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <?= admin_pagination($page, $pages, $total, fn ($p, $n) => $sectionUrl($section, $p, $n), $per, $perOptions) ?>
  <?php endif; ?>
</div>
