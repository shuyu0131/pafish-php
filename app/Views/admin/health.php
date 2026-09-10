<?php $checks = $checks ?? []; ?>
<div class="admin-stack">
  <div class="admin-page-head"><div><h1 class="admin-h1">系统健康</h1></div></div>
  <div class="card admin-table-card">
    <table class="admin-table"><thead><tr><th>检查项</th><th>状态</th><th>详情</th></tr></thead><tbody>
      <?php foreach ($checks as $label => [$ok, $detail]): ?><tr><td><?= e($label) ?></td><td><span class="status-badge <?= $ok ? 'status-published' : 'status-draft' ?>"><?= $ok ? '正常' : '需要处理' ?></span></td><td><?= e($detail) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>
</div>
