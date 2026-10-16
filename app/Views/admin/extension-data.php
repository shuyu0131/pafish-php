<?php
$labels = [
    'registered' => '已登记', 'unregistered' => '未登记', 'missing' => '登记的表已缺失', 'pending' => '尚未建表',
    'retained' => '扩展缺失，保留登记', 'undeclared' => '清单不再声明',
    'invalid_owner' => '归属登记异常', 'conflict' => '归属冲突', 'invalid_manifest' => '清单无效',
];
$formatSize = static fn (int $size): string => $size < 1048576
    ? number_format($size / 1024, 1) . ' KB' : number_format($size / 1048576, 1) . ' MB';
?>
<div class="admin-stack admin-detail-page admin-extension-data-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">扩展数据表</h1>
      <p class="admin-page-sub">已建 <?= (int) $existing ?> 张 · 未建/缺失 <?= (int) $missing ?> 张 · 行数为估算，占用包含索引</p>
    </div>
    <div class="admin-head-actions">
      <a class="btn btn-outline" href="<?= e(url_to('/admin/plugins')) ?>">插件管理</a>
      <a class="btn btn-outline" href="<?= e(url_to('/admin/backup')) ?>">数据备份</a>
    </div>
  </div>
  <form method="get" action="<?= e(url_to('/admin/extensions/data')) ?>" class="admin-extension-data-filters">
    <?php if (!\Pafish\Core\Config::get('pretty_urls', true)): ?><input type="hidden" name="p" value="admin/extensions/data"><?php endif; ?>
    <label>类型
      <select name="kind" class="input" onchange="this.form.submit()">
        <option value="">全部</option>
        <option value="plugin" <?= $kind === 'plugin' ? 'selected' : '' ?>>插件</option>
        <option value="theme" <?= $kind === 'theme' ? 'selected' : '' ?>>主题</option>
      </select>
    </label>
    <label>状态
      <select name="state" class="input" onchange="this.form.submit()">
        <option value="">全部</option>
        <option value="attention" <?= $state === 'attention' ? 'selected' : '' ?>>需核查</option>
        <option value="retained" <?= $state === 'retained' ? 'selected' : '' ?>>扩展缺失</option>
      </select>
    </label>
  </form>
  <div class="admin-table-wrap" role="region" aria-label="扩展数据表，可左右滚动" tabindex="0">
    <table class="admin-table">
      <thead><tr><th>数据表</th><th>所属扩展</th><th>状态</th><th>估算行数</th><th>占用</th><th>核心卸载处理</th></tr></thead>
      <tbody>
      <?php if ($tables === []): ?>
        <tr><td colspan="6" class="admin-empty">没有符合条件的数据表。<a href="<?= e(url_to('/admin/plugins')) ?>">查看插件</a></td></tr>
      <?php endif; ?>
      <?php foreach ($tables as $table): ?>
        <tr>
          <td><code><?= e($table['table']) ?></code></td>
          <td>
            <?php if ($table['identity'] !== null): ?>
              <span><?= e($table['title']) ?></span>
              <small class="admin-muted"><?= $table['identity']['kind'] === 'plugin' ? '插件' : '主题' ?> · <?= e($table['identity']['name']) ?></small>
            <?php else: ?>待核查<?php endif; ?>
          </td>
          <td><?php foreach ($table['statuses'] as $status): ?><span class="admin-extension-data-status"><?= e($labels[$status]) ?></span><?php endforeach; ?></td>
          <td class="admin-extension-data-number"><?= $table['rows'] === null ? '—' : number_format($table['rows']) ?></td>
          <td class="admin-extension-data-number"><?= $table['bytes'] === null ? '—' : e($formatSize($table['bytes'])) ?></td>
          <td><?= $table['cleanup'] ? '默认保留；勾选删除数据时清理' : '不自动清理，需人工核查' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="admin-muted">这里只展示表归属。卸载说明仅指核心对清单表的处理；扩展自身的卸载回调仍可能修改数据。</p>
</div>
