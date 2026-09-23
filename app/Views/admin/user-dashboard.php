<?php
/** @var array $stats $latest $comments */
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">我的工作台</h1>
      <p class="admin-page-sub">你好，<?= e($username) ?> · 查看自己的内容与互动</p>
    </div>
  </div>

  <div class="admin-stats">
    <?php foreach ($stats as $stat): ?>
      <div class="card admin-stat-card">
        <div class="admin-stat-head"><span class="admin-stat-icon" style="color: <?= e($stat['color']) ?>; background-color: <?= e($stat['color']) ?>1f"><?= admin_icon($stat['icon'], 16) ?></span><span class="admin-stat-label"><?= e($stat['label']) ?></span></div>
        <p class="admin-stat-value"><?= number_format((int) $stat['value']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <section>
    <div class="editor-dashboard-section-head"><h2 class="admin-chart-title">我的文章</h2><a href="<?= e(url_to('/')) ?>">查看前台</a></div>
    <div class="card admin-latest">
      <?php if ($latest === []): ?><p class="admin-empty">还没有文章。</p><?php endif; ?>
      <?php foreach ($latest as $post): ?>
        <?php $status = ['DRAFT' => '草稿', 'PUBLISHED' => '已发布', 'SCHEDULED' => '定时'][$post['status']] ?? $post['status']; ?>
        <a class="admin-latest-item" href="<?= e(url_to('/admin/posts/' . $post['id'] . '/edit')) ?>"><div class="admin-latest-main"><p class="admin-latest-title"><?= e($post['title']) ?></p><p class="admin-latest-sub">更新于 <?= e(format_date($post['updated_at'], 'yyyy-MM-dd HH:mm')) ?></p></div><span class="badge"><?= e($status) ?></span></a>
      <?php endforeach; ?>
    </div>
  </section>

  <section>
    <div class="editor-dashboard-section-head"><h2 class="admin-chart-title">我的评论</h2><a href="<?= e(url_to('/profile')) ?>">个人中心</a></div>
    <div class="card admin-latest">
      <?php if ($comments === []): ?><p class="admin-empty">还没有发表评论。</p><?php endif; ?>
      <?php foreach ($comments as $comment): ?>
        <div class="admin-latest-item"><div class="admin-latest-main"><p class="admin-latest-title"><?= e($comment['post_title']) ?></p><p class="admin-latest-sub"><?= e(mb_strimwidth((string) $comment['content'], 0, 90, '…')) ?></p></div><span class="admin-muted"><?= e($comment['status'] === 'APPROVED' ? '已显示' : '待审核') ?></span></div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
