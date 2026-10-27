<?php /** Account overview and paginated personal activity; independent of the front-end theme. */ ?>
<div class="admin-stack admin-dashboard admin-account">
  <header class="admin-page-head">
    <div><h1 class="admin-h1">个人中心</h1><p class="admin-page-sub"><?= e((string) ($user['nickname'] ?: $user['username'])) ?></p></div>
    <?php if ($section === 'posts' && $canSubmit): ?><a class="btn btn-primary" href="<?= e(url_to('/admin/submissions/new')) ?>"><?= admin_icon('plus', 15) ?>撰写投稿</a><?php endif; ?>
  </header>
  <?php if ($section === 'overview'): ?>
    <?php $statsUrlsResolved = true; require __DIR__ . '/partials/dashboard-stats.php'; ?>
  <?php else: ?>
    <section class="card admin-dashboard-panel">
      <div class="admin-dashboard-panel-head"><h2><?= e($sections[$section]) ?></h2><span class="admin-muted">共 <?= (int) $total ?> 条</span></div>
      <?php if ($section === 'posts'): ?>
        <nav class="admin-tabs admin-status-tabs admin-submission-filters" aria-label="投稿状态">
          <?php foreach (['' => '全部', 'DRAFT' => '草稿', 'PENDING' => '待审核', 'CHANGES_REQUESTED' => '退回修改', 'PUBLISHED' => '已发布'] as $key => $label): ?>
            <a class="admin-tab<?= $submissionStatus === $key ? ' is-active' : '' ?>" <?= $submissionStatus === $key ? 'aria-current="page"' : '' ?> href="<?= e($sectionUrl('posts', 1, $per, $key)) ?>"><?= e($label) ?><?php if ($key !== ''): ?> <span class="admin-tab-count">(<?= (int) ($submissionCounts[$key] ?? 0) ?>)</span><?php endif; ?></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
      <?php if ($items === []): ?><p class="admin-empty"><?= $section === 'posts' && !$canSubmit ? '站点暂未开放用户投稿。' : '暂无记录。' ?></p><?php else: ?>
        <ul class="admin-account-list">
          <?php foreach ($items as $item): ?>
            <li>
              <div class="admin-account-list-main">
                <?php if ($section === 'points'): ?>
                  <span class="admin-account-amount"><?= (int) $item['amount'] > 0 ? '+' : '' ?><?= (int) $item['amount'] ?></span><span><?= e($item['reason']) ?></span>
                <?php elseif ($section === 'posts'): ?>
                  <div class="admin-account-post-title"><a href="<?= e(url_to('/admin/submissions/' . (int) $item['id'] . '/edit')) ?>"><?= e($item['title']) ?></a><span class="badge<?= $item['status'] === 'PUBLISHED' ? ' badge-success' : ($item['status'] === 'CHANGES_REQUESTED' ? ' badge-warning' : '') ?>"><?= e(['DRAFT' => '草稿', 'PENDING' => '待审核', 'CHANGES_REQUESTED' => '退回修改', 'PUBLISHED' => '已发布', 'SCHEDULED' => '定时'][$item['status']] ?? $item['status']) ?></span></div>
                  <?php if ($item['status'] === 'CHANGES_REQUESTED' && trim((string) ($item['review_note'] ?? '')) !== ''): ?><p class="admin-account-review-note"><strong>审核意见：</strong><?= e($item['review_note']) ?></p><?php endif; ?>
                  <?php if ($item['reviewed_at'] && !empty($item['reviewer_username'])): ?><p class="admin-account-review-meta">最近审核：<?= e((string) (($item['reviewer_nickname'] ?? '') ?: $item['reviewer_username'])) ?> · <?= e(format_date($item['reviewed_at'], 'yyyy-MM-dd HH:mm')) ?></p><?php endif; ?>
                  <div class="admin-account-post-actions"><a href="<?= e(url_to('/admin/submissions/' . (int) $item['id'] . '/edit')) ?>">编辑</a><?php if ($item['status'] === 'PUBLISHED'): ?><a href="<?= e(url_to('/post/' . rawurlencode((string) $item['slug']))) ?>" target="_blank" rel="noopener">查看文章</a><?php endif; ?><form method="post" action="<?= e(url_to('/admin/submissions/' . (int) $item['id'] . '/delete')) ?>" data-confirm="确定撤回《<?= e($item['title']) ?>》吗？"><?= csrf_field() ?><button class="admin-link-button" type="submit">撤回</button></form></div>
                <?php else: ?>
                  <a href="<?= e(url_to('/post/' . rawurlencode((string) $item['slug'])) . ($section === 'comments' ? '#comment-' . (int) $item['id'] : '')) ?>"><?= e($item['title']) ?></a>
                  <?php if ($section === 'comments'): ?><p><?= e($item['content']) ?></p><span class="badge<?= $item['status'] === 'APPROVED' ? ' badge-success' : '' ?>"><?= e(['APPROVED' => '已显示', 'PENDING' => '待审核', 'REJECTED' => '未通过', 'TRASH' => '已删除'][$item['status']] ?? '未显示') ?></span><?php endif; ?>
                <?php endif; ?>
              </div>
              <time><?= e(format_date($item[$section === 'posts' ? 'updated_at' : 'created_at'], 'yyyy-MM-dd HH:mm')) ?></time>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <?= admin_pagination($page, $pages, $total, fn ($p, $n) => $sectionUrl($section, $p, $n, $section === 'posts' ? $submissionStatus : ''), $per, $perOptions) ?>
  <?php endif; ?>
</div>
