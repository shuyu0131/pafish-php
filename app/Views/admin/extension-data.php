<?php
$labels = [
    'registered' => t('admin.registered'), 'unregistered' => t('admin.unregistered'), 'missing' => t('admin.registered_table_is_missing'), 'pending' => t('admin.table_not_created'),
    'retained' => t('admin.extension_missing_registration_retained'), 'undeclared' => t('admin.no_longer_declared_in_manifest'),
    'invalid_owner' => t('admin.invalid_ownership_registration'), 'conflict' => t('admin.ownership_conflict'), 'invalid_manifest' => t('admin.invalid_manifest'),
];
$formatSize = static fn (int $size): string => $size < 1048576
    ? number_format($size / 1024, 1) . ' KB' : number_format($size / 1048576, 1) . ' MB';
?>
<div class="admin-stack admin-detail-page admin-extension-data-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e(t('admin.extension_tables')) ?></h1>
      <p class="admin-page-sub"><?= e(t('admin.created')) ?> <?= (int) $existing ?> <?= e(t('admin.tables_not_created_missing')) ?> <?= (int) $missing ?> <?= e(t('admin.tables_row_counts_are_estimates_storage_includes_indexes')) ?></p>
    </div>
    <div class="admin-head-actions">
      <a class="btn btn-outline" href="<?= e(url_to('/admin/plugins')) ?>"><?= e(t('admin.plugins')) ?></a>
      <a class="btn btn-outline" href="<?= e(url_to('/admin/backup')) ?>"><?= e(t('admin.backups')) ?></a>
    </div>
  </div>
  <form method="get" action="<?= e(url_to('/admin/extensions/data')) ?>" class="admin-extension-data-filters">
    <?php if (!\Pafish\Core\Config::get('pretty_urls', true)): ?><input type="hidden" name="p" value="admin/extensions/data"><?php endif; ?>
    <label><?= e(t('admin.type')) ?>
      <select name="kind" class="input" onchange="this.form.submit()">
        <option value=""><?= e(t('common.all')) ?></option>
        <option value="plugin" <?= $kind === 'plugin' ? 'selected' : '' ?>><?= e(t('admin.plugin_76fcd7')) ?></option>
        <option value="theme" <?= $kind === 'theme' ? 'selected' : '' ?>><?= e(t('admin.theme_e848dd')) ?></option>
      </select>
    </label>
    <label><?= e(t('common.status')) ?>
      <select name="state" class="input" onchange="this.form.submit()">
        <option value=""><?= e(t('common.all')) ?></option>
        <option value="attention" <?= $state === 'attention' ? 'selected' : '' ?>><?= e(t('admin.needs_review')) ?></option>
        <option value="retained" <?= $state === 'retained' ? 'selected' : '' ?>><?= e(t('admin.extension_missing')) ?></option>
      </select>
    </label>
  </form>
  <div class="admin-table-wrap" role="region" aria-label="<?= e(t('admin.extension_tables_scroll_horizontally')) ?>" tabindex="0">
    <table class="admin-table">
      <thead><tr><th><?= e(t('admin.table')) ?></th><th><?= e(t('admin.owner_extension')) ?></th><th><?= e(t('common.status')) ?></th><th><?= e(t('admin.estimated_rows')) ?></th><th><?= e(t('admin.storage')) ?></th><th><?= e(t('admin.core_uninstall_behavior')) ?></th></tr></thead>
      <tbody>
      <?php if ($tables === []): ?>
        <tr><td colspan="6" class="admin-empty"><?= e(t('admin.no_matching_tables')) ?><a href="<?= e(url_to('/admin/plugins')) ?>"><?= e(t('admin.view_plugin')) ?></a></td></tr>
      <?php endif; ?>
      <?php foreach ($tables as $table): ?>
        <tr>
          <td><code><?= e($table['table']) ?></code></td>
          <td>
            <?php if ($table['identity'] !== null): ?>
              <span><?= e($table['title']) ?></span>
              <small class="admin-muted"><?= $table['identity']['kind'] === 'plugin' ? t('admin.plugin_76fcd7') : t('admin.theme_e848dd') ?> · <?= e($table['identity']['name']) ?></small>
            <?php else: ?><?= e(t('admin.needs_review_29ab4b')) ?><?php endif; ?>
          </td>
          <td><?php foreach ($table['statuses'] as $status): ?><span class="admin-extension-data-status"><?= e($labels[$status]) ?></span><?php endforeach; ?></td>
          <td class="admin-extension-data-number"><?= $table['rows'] === null ? '—' : number_format($table['rows']) ?></td>
          <td class="admin-extension-data-number"><?= $table['bytes'] === null ? '—' : e($formatSize($table['bytes'])) ?></td>
          <td><?= $table['cleanup'] ? t('admin.retained_by_default_removed_when_delete_data_is') : t('admin.manual_review_required_never_removed_automatically') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="admin-muted"><?= e(t('admin.this_page_shows_table_ownership_only_uninstall_notes')) ?></p>
</div>
