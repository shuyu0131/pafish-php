<?php
/** 微语后台列表与编辑器。 */
if (($mode ?? 'list') === 'editor'):
  $m = is_array($micro ?? null) ? $micro : [];
  $id = (int) ($m['id'] ?? 0);
  $status = (string) ($m['status'] ?? 'PUBLISHED');
  $content = \Pafish\Services\MicroStatuses::contentWithMedia(
    (string) ($m['content'] ?? ''),
    is_array($m['media'] ?? null) ? $m['media'] : [],
    (string) ($m['content_format'] ?? 'markdown')
  );
?>
<div class="admin-editor">
  <form id="microForm" method="post" action="<?= e(url_to($id > 0 ? '/admin/micro/' . $id . '/save' : '/admin/micro/save')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="_idempotency" value="<?= e(\Pafish\Core\Session::requestToken()) ?>">
    <?php /* 已有独立图片已合入正文，保存时清空图片墙数据，后续图片统一在正文维护。 */ ?>
    <input type="hidden" name="media" value="[]">
    <div class="admin-editor-grid">
      <div class="admin-editor-main">
        <div class="admin-editor-topline">
          <a class="btn btn-ghost" href="<?= e(url_to('/admin/micro')) ?>"><?= admin_icon('arrow-left', 15) ?><?= e(t('common.back')) ?></a>
          <span class="admin-editor-topline-title"><?= $id > 0 ? t('admin.edit_note') : t('admin.new_note') ?></span>
        </div>
        <?php $editorType = 'micro'; require __DIR__ . '/partials/editor-controls.php'; ?>
        <div class="admin-md-editor">
          <div id="markdownMount"></div>
          <input type="hidden" id="fContentFormat" name="content_format" data-editor-new="<?= $id > 0 ? '0' : '1' ?>" value="<?= e($m['content_format'] ?? 'markdown') ?>">
          <textarea id="fContent" name="content" hidden maxlength="160000"><?= e($content) ?></textarea>
        </div>
        <p class="admin-field-hint"><?= e(t('admin.upload_images_from_the_editor_toolbar_or_paste')) ?></p>
        <?php /* 扩展注入点：插件/主题在这里给微语编辑器补自己的字段（如笔记标题、分类）。 */ ?>
        <?= \Pafish\Services\Plugin::renderInjection('micro_editor', ['micro' => $m, 'isEdit' => $id > 0, 'microId' => $id]) ?>
        <?= \Pafish\Services\Theme::renderInjection('micro_editor', ['micro' => $m, 'isEdit' => $id > 0, 'microId' => $id]) ?>
      </div>
      <div class="admin-editor-side">
        <div class="card admin-form-card admin-publish-card">
          <button type="submit" class="btn btn-primary" style="width:100%"><?= e(t('admin.save_note')) ?></button>
          <?php if ($id > 0): ?><a class="btn btn-ghost" style="width:100%;margin-top:8px" href="<?= e(url_to('/micro')) ?>" target="_blank" rel="noopener"><?= e(t('admin.view_site_abdc32')) ?></a><?php endif; ?>
        </div>
        <div class="card admin-form-card admin-settings-card">
          <div class="admin-field"><span class="label"><?= e(t('common.status')) ?></span><select class="input" name="status"><option value="DRAFT"<?= $status === 'DRAFT' ? ' selected' : '' ?>><?= e(t('admin.draft')) ?></option><option value="PUBLISHED"<?= $status === 'PUBLISHED' ? ' selected' : '' ?>><?= e(t('admin.publish')) ?></option></select></div>
          <div class="admin-field"><span class="label"><?= e(t('admin.published_b410c2')) ?></span><input class="input" type="datetime-local" name="published_at" value="<?= !empty($m['published_at']) ? e(str_replace(' ', 'T', substr((string) $m['published_at'], 0, 16))) : '' ?>"><span class="admin-field-hint"><?= e(t('admin.leave_blank_to_use_the_current_time_when')) ?></span></div>
          <label class="admin-check-row"><input type="checkbox" name="is_pinned" value="1"<?= !empty($m['is_pinned']) ? ' checked' : '' ?>><?= e(t('admin.pin')) ?></label>
          <label class="admin-check-row"><input type="checkbox" name="is_private" value="1"<?= !empty($m['is_private']) ? ' checked' : '' ?>><?= e(t('admin.private_hidden_from_the_site_and_api')) ?></label>
        </div>
      </div>
    </div>
  </form>
</div>
<script>
window.PAFISH_MICRO_EDITOR = {
  previewUrl: <?= json_encode(url_to('/api/md-preview')) ?>,
  uploadUrl: <?= json_encode(url_to('/api/upload')) ?>,
  assetBase: <?= json_encode(rtrim(\Pafish\Core\Url::base(), '/') . '/public') ?>,
  csrf: <?= json_encode(csrf_token()) ?>
};
</script>
<script src="<?= e(asset_url('/js/admin-markdown-editor.js')) ?>"></script>
<script src="<?= e(asset_url('/js/admin-editor-host.js')) ?>"></script>
<script src="<?= e(asset_url('/js/admin-micro-editor.js')) ?>"></script>
<?php else:
  $filters = $filters ?? [];
  ?>
<div class="admin-stack admin-list-page admin-micro-list">
  <div class="admin-page-head"><div><h1 class="admin-h1"><?= e(t('admin.notes')) ?></h1><p class="admin-page-sub"><?= e(t('admin.total')) ?> <?= (int) $total ?> <?= e(t('admin.items')) ?></p></div><div class="admin-head-actions"><a class="btn btn-primary" href="<?= e(url_to('/admin/micro/new')) ?>"><?= admin_icon('plus', 15) ?><span><?= e(t('admin.new_note')) ?></span></a></div></div>
  <form class="admin-list-toolbar admin-list-filter-form" method="get" action="<?= e(url_to('/admin/micro')) ?>">
    <input class="input" type="search" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="<?= e(t('admin.search_content')) ?>">
    <select class="input" name="status"><option value=""><?= e(t('admin.all_statuses')) ?></option><option value="PUBLISHED"<?= ($filters['status'] ?? '') === 'PUBLISHED' ? ' selected' : '' ?>><?= e(t('admin.published')) ?></option><option value="DRAFT"<?= ($filters['status'] ?? '') === 'DRAFT' ? ' selected' : '' ?>><?= e(t('admin.draft')) ?></option></select>
    <?php if ((int) ($per ?? 20) !== 20): ?><input type="hidden" name="per" value="<?= (int) $per ?>"><?php endif; ?>
    <button class="btn btn-primary" type="submit"><?= admin_icon('search', 15) ?><?= e(t('admin.filter')) ?></button>
  </form>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th><?= e(t('admin.content')) ?></th><th class="admin-table-secondary"><?= e(t('common.status')) ?></th><th class="admin-table-secondary"><?= e(t('admin.author')) ?></th><th class="admin-table-secondary"><?= e(t('admin.updated')) ?></th><th class="admin-col-ops"><?= e(t('common.actions')) ?></th></tr></thead><tbody>
  <?php if ($items === []): ?><tr><td colspan="5"><div class="admin-empty-list"><?= admin_icon('message', 32) ?><p><?= e(t('admin.no_notes_yet')) ?></p><a class="btn btn-outline" href="<?= e(url_to('/admin/micro/new')) ?>"><?= e(t('admin.write_your_first_note')) ?></a></div></td></tr><?php endif; ?>
  <?php foreach ($items as $item): ?>
    <tr data-micro-row="<?= (int) $item['id'] ?>">
      <td data-label="<?= e(t('admin.content')) ?>">
        <div class="admin-post-title-line">
          <?php if (!empty($item['is_pinned'])): ?><span class="badge badge-accent"><?= e(t('admin.pin')) ?></span><?php endif; ?>
          <?php if (!empty($item['is_private'])): ?><span class="badge"><?= e(t('admin.private')) ?></span><?php endif; ?>
          <a class="admin-post-title" href="<?= e(url_to('/admin/micro/' . (int) $item['id'] . '/edit')) ?>"><?= e(mb_strimwidth(\Pafish\Services\Content::plain((string) $item['content'], (string) ($item['content_format'] ?? 'markdown')), 0, 100, '…')) ?></a>
        </div>
        <div class="admin-table-mobile-meta">
          <span><?= $item['status'] === 'PUBLISHED' ? t('admin.published') : t('admin.draft') ?></span>
          <span><?= e((string) ($item['author_name'] ?? '')) ?></span>
          <time title="<?= e(t('admin.updated')) ?>" datetime="<?= e((string) $item['updated_at']) ?>"><?= e(format_date($item['updated_at'], 'yyyy-MM-dd HH:mm')) ?></time>
        </div>
      </td>
      <td data-label="<?= e(t('common.status')) ?>" class="admin-table-secondary"><span class="badge <?= $item['status'] === 'PUBLISHED' ? 'badge-success' : '' ?>"><?= $item['status'] === 'PUBLISHED' ? t('admin.published') : t('admin.draft') ?></span></td>
      <td data-label="<?= e(t('admin.author')) ?>" class="admin-muted admin-table-secondary"><?= e((string) ($item['author_name'] ?? '')) ?></td>
      <td data-label="<?= e(t('admin.updated')) ?>" class="admin-muted admin-table-secondary"><?= e(format_date($item['updated_at'], 'yyyy-MM-dd HH:mm')) ?></td>
      <td class="admin-col-ops" data-label="<?= e(t('common.actions')) ?>"><div class="admin-row-ops"><a class="admin-icon-btn" href="<?= e(url_to('/admin/micro/' . (int) $item['id'] . '/edit')) ?>" title="<?= e(t('common.edit')) ?>"><?= admin_icon('edit', 15) ?></a><button type="button" class="admin-icon-btn admin-icon-danger" data-delete-micro="<?= (int) $item['id'] ?>" title="<?= e(t('common.delete')) ?>"><?= admin_icon('trash', 15) ?></button></div></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?= admin_pagination((int) $page, (int) $totalPages, (int) $total, static function (int $p, int $size = 20) use ($filters): string { $params = ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? '', 'page' => $p]; if ($p === 1) unset($params['page']); if ($size !== 20) $params['per'] = $size; $params = array_filter($params, static fn ($v) => $v !== ''); return url_to('/admin/micro') . ($params ? '?' . http_build_query($params) : ''); }, (int) ($per ?? 20), [20, 50, 100]) ?>
</div>
<?php $microCsrf = csrf_token(); ?><script>(function(){var token=<?= json_encode($microCsrf) ?>;document.querySelectorAll('[data-delete-micro]').forEach(function(btn){btn.addEventListener('click',function(){var ok=window.pafishConfirm?window.pafishConfirm(window.pafishT("js.delete_this_note"),{title:window.pafishT("js.delete_note")}):Promise.resolve(window.confirm(window.pafishT("js.delete_this_note")));ok.then(function(yes){if(!yes)return;var fd=new FormData();fd.append('_csrf',token);fetch(<?= json_encode(url_to('/admin/micro')) ?>+'/'+btn.getAttribute('data-delete-micro')+'/delete',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json()}).then(function(j){if(j&&j.ok){var row=btn.closest('[data-micro-row]');if(row)row.remove();pafishToast(window.pafishT("js.note_deleted"),'success')}else pafishNotify((j&&j.error)||window.pafishT("js.delete_failed"),true)}).catch(function(){pafishNotify(window.pafishT("js.network_error"),true)})})})})})();</script>
<?php endif; ?>
