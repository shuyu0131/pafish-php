<?php
/**
 * 工作台（对齐 Node app/admin/page.tsx）
 * 变量：$username $canManage $stats $trend $catRows $latest $totalTrend
 */
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">工作台</h1>
      <p class="admin-page-sub">你好，<?= e($username) ?> · 欢迎回来</p>
    </div>
    <?php if ($canManage): ?>
      <a class="btn btn-primary" href="<?= e(url_to('/admin/posts/new')) ?>">
        <?= admin_icon('pen', 15) ?>
        写文章
      </a>
    <?php endif; ?>
  </div>

  <!-- 统计卡片 -->
  <div class="admin-stats">
    <?php foreach ($stats as $s): ?>
      <div class="card admin-stat-card">
        <div class="admin-stat-head">
          <span class="admin-stat-icon" style="color: <?= e($s['color']) ?>; background-color: <?= e($s['color']) ?>1f">
            <?= admin_icon($s['icon'], 16) ?>
          </span>
          <span class="admin-stat-label"><?= e($s['label']) ?></span>
        </div>
        <p class="admin-stat-value"><?= number_format($s['value']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- 图表 -->
  <div class="admin-charts">
    <div class="card admin-chart-card">
      <h2 class="admin-chart-title">近 14 天发文趋势</h2>
      <?php
      // 趋势柱状图（对齐 Node PublishTrendChart：560x190、4 条网格线、柱宽 50%、隔一标一）
      $W = 560; $H = 190; $padL = 8; $padB = 22; $padT = 12;
      $chartH = $H - $padT - $padB;
      $max = max(1, ...array_column($trend, 'count'));
      $bw = ($W - $padL * 2) / max(1, count($trend));
      $n = count($trend);
      ?>
      <svg viewBox="0 0 <?= $W ?> <?= $H ?>" class="admin-chart-svg" role="img" aria-label="近 14 天发文趋势">
        <?php foreach ([0.25, 0.5, 0.75, 1] as $r): ?>
          <line x1="<?= $padL ?>" x2="<?= $W - $padL ?>" y1="<?= $padT + $chartH * (1 - $r) ?>" y2="<?= $padT + $chartH * (1 - $r) ?>"
                stroke="var(--border)" stroke-width="1" stroke-dasharray="<?= $r === 1.0 ? '' : '3 4' ?>"/>
        <?php endforeach; ?>
        <?php foreach ($trend as $i => $d): ?>
          <?php $h = max($d['count'] > 0 ? 3 : 1, ($d['count'] / $max) * $chartH); ?>
          <?php $x = $padL + $i * $bw + $bw * 0.25; ?>
          <?php $y = $padT + $chartH - $h; ?>
          <g>
            <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $bw * 0.5 ?>" height="<?= $h ?>" rx="2"
                  fill="var(--accent)" opacity="<?= $d['count'] > 0 ? 0.9 : 0.18 ?>">
              <title><?= e($d['label']) ?>：发布 <?= $d['count'] ?> 篇</title>
            </rect>
            <?php if ($i % 2 === 0 || $i === $n - 1): ?>
              <text x="<?= $x + $bw * 0.25 ?>" y="<?= $H - 6 ?>" text-anchor="middle" font-size="9" fill="var(--muted)"><?= e($d['label']) ?></text>
            <?php endif; ?>
          </g>
        <?php endforeach; ?>
      </svg>
      <p class="admin-chart-foot">最近 14 天共发布 <?= $totalTrend ?> 篇</p>
    </div>

    <div class="card admin-chart-card">
      <h2 class="admin-chart-title">分类分布（已发布）</h2>
      <?php if ($catRows === []): ?>
        <p class="admin-empty-sm">暂无分类数据</p>
      <?php else: ?>
        <?php $maxCat = max(1, ...array_column($catRows, 'count')); ?>
        <div class="admin-cat-list">
          <?php foreach ($catRows as $row): ?>
            <div>
              <div class="admin-cat-head">
                <span><?= e($row['name']) ?></span>
                <span class="admin-cat-meta"><?= $row['count'] ?> 篇 · <?= number_format($row['views']) ?> 次浏览</span>
              </div>
              <div class="admin-cat-bar"><div class="admin-cat-bar-fill" style="width: <?= round(($row['count'] / $maxCat) * 100, 2) ?>%"></div></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- 最近更新 -->
  <div>
    <h2 class="admin-chart-title">最近更新</h2>
    <div class="card admin-latest">
      <?php if ($latest === []): ?>
        <p class="admin-empty">还没有文章，点击右上角「写文章」开始创作。</p>
      <?php endif; ?>
      <?php foreach ($latest as $p): ?>
        <a class="admin-latest-item" href="<?= e(url_to('/admin/posts/' . $p['id'] . '/edit')) ?>">
          <div class="admin-latest-main">
            <p class="admin-latest-title"><?= e($p['title']) ?></p>
            <p class="admin-latest-sub">更新于 <?= e(format_date($p['updated_at'], 'yyyy-MM-dd HH:mm')) ?></p>
          </div>
          <?php
          $statusClass = $p['status'] === 'PUBLISHED' ? 'badge-success'
              : ($p['status'] === 'SCHEDULED' ? 'badge-warning' : '');
          $statusLabel = ['DRAFT' => '草稿', 'PUBLISHED' => '已发布', 'SCHEDULED' => '定时'][$p['status']] ?? $p['status'];
          ?>
          <span class="badge <?= $statusClass ?>"><?= e($statusLabel) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- 系统版本 -->
  <div class="card admin-version-row">
    <div class="admin-version-main">
      <span class="badge badge-primary">v<?= e($current) ?></span>
      <span class="admin-muted">纸鱼博客系统版本</span>
    </div>
    <?php if (!empty($canUpgrade)): ?>
      <?php if (!empty($upgradeInfo['hasUpdate'])): ?>
        <a class="btn btn-primary btn-sm" href="<?= e(url_to('/admin/upgrade')) ?>">发现新版本 v<?= e($upgradeInfo['latest']) ?>，前往更新</a>
      <?php else: ?>
        <a class="btn btn-sm" href="<?= e(url_to('/admin/upgrade')) ?>">系统更新</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
