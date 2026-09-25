<?php
/** 微语后台列表与编辑器。 */
if (($mode ?? 'list') === 'editor'):
  $m = is_array($micro ?? null) ? $micro : [];
  $id = (int) ($m['id'] ?? 0);
  $status = (string) ($m['status'] ?? 'DRAFT');
  $media = is_array($m['media'] ?? null) ? implode("\n", $m['media']) : '';
?>
<div class="admin-editor">
  <form id="microForm" method="post" action="<?= e(url_to($id > 0 ? '/admin/micro/' . $id . '/save' : '/admin/micro/save')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="_idempotency" value="<?= e(\Pafish\Core\Session::requestToken()) ?>">
    <div class="admin-editor-grid">
      <div class="admin-editor-main">
        <div class="admin-editor-topline">
          <a class="btn btn-ghost" href="<?= e(url_to('/admin/micro')) ?>"><?= admin_icon('arrow-left', 15) ?>返回</a>
          <span class="admin-editor-topline-title"><?= $id > 0 ? '编辑微语' : '新建微语' ?></span>
        </div>
        <!-- Markdown 编辑器（与文章、页面共用 Vditor：所见即所得/分栏/源码三模式，拖拽粘贴上传） -->
        <div class="admin-md-editor">
          <div id="vditorMount"></div>
          <textarea id="fContent" name="content" hidden maxlength="160000"><?= e((string) ($m['content'] ?? '')) ?></textarea>
        </div>
        <div class="admin-field" style="margin-top:14px">
          <span class="label">媒体地址（每行一个，可选）</span>
          <textarea class="input" name="media" rows="4" placeholder="https://example.com/image.jpg"><?= e($media) ?></textarea>
          <span class="admin-field-hint">仅接受 http(s)、/ 或 uploads/ 开头的地址。编辑器里上传的图片已写在正文中，这里用于单独维护前台图片墙。</span>
        </div>
        <?php /* 扩展注入点：插件/主题在这里给微语编辑器补自己的字段（如笔记标题、分类）。 */ ?>
        <?= \Pafish\Services\Plugin::renderInjection('micro_editor', ['micro' => $m, 'isEdit' => $id > 0, 'microId' => $id]) ?>
        <?= \Pafish\Services\Theme::renderInjection('micro_editor', ['micro' => $m, 'isEdit' => $id > 0, 'microId' => $id]) ?>
      </div>
      <div class="admin-editor-side">
        <div class="card admin-form-card admin-publish-card">
          <button type="submit" class="btn btn-primary" style="width:100%">保存微语</button>
          <?php if ($id > 0): ?><a class="btn btn-ghost" style="width:100%;margin-top:8px" href="<?= e(url_to('/micro')) ?>" target="_blank" rel="noopener">查看前台</a><?php endif; ?>
        </div>
        <div class="card admin-form-card admin-settings-card">
          <div class="admin-field"><span class="label">状态</span><select class="input" name="status"><option value="DRAFT"<?= $status === 'DRAFT' ? ' selected' : '' ?>>草稿</option><option value="PUBLISHED"<?= $status === 'PUBLISHED' ? ' selected' : '' ?>>发布</option></select></div>
          <div class="admin-field"><span class="label">发布时间</span><input class="input" type="datetime-local" name="published_at" value="<?= !empty($m['published_at']) ? e(str_replace(' ', 'T', substr((string) $m['published_at'], 0, 16))) : '' ?>"><span class="admin-field-hint">发布状态留空时使用当前时间，可用于定时发布。</span></div>
          <label class="admin-check-row"><input type="checkbox" name="is_pinned" value="1"<?= !empty($m['is_pinned']) ? ' checked' : '' ?>>置顶</label>
          <label class="admin-check-row"><input type="checkbox" name="is_private" value="1"<?= !empty($m['is_private']) ? ' checked' : '' ?>>私密（不显示在前台和 API）</label>
        </div>
      </div>
    </div>
  </form>
</div>
<script>
window.PAFISH_MICRO_EDITOR = {
  uploadUrl: <?= json_encode(url_to('/api/upload')) ?>,
  assetBase: <?= json_encode(rtrim(\Pafish\Core\Url::base(), '/') . '/public') ?>,
  csrf: <?= json_encode(csrf_token()) ?>
};
</script>
<script src="<?= e(asset_url('/vendor/vditor/dist/index.min.js')) ?>"></script>
<script src="<?= e(asset_url('/js/admin-micro-editor.js')) ?>"></script>
<?php else:
  $filters = $filters ?? [];
?>
<div class="admin-stack">
  <div class="admin-page-head"><div><h1 class="admin-h1">微语</h1><p class="admin-page-sub">共 <?= (int) $total ?> 条</p></div><div class="admin-head-actions"><a class="btn btn-primary" href="<?= e(url_to('/admin/micro/new')) ?>"><?= admin_icon('plus', 15) ?>新建微语</a></div></div>
  <form class="admin-user-toolbar" method="get" action="<?= e(url_to('/admin/micro')) ?>">
    <input class="input" type="search" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="搜索内容">
    <select class="input" name="status"><option value="">全部状态</option><option value="PUBLISHED"<?= ($filters['status'] ?? '') === 'PUBLISHED' ? ' selected' : '' ?>>已发布</option><option value="DRAFT"<?= ($filters['status'] ?? '') === 'DRAFT' ? ' selected' : '' ?>>草稿</option></select>
    <button class="btn btn-primary" type="submit"><?= admin_icon('search', 15) ?>筛选</button>
  </form>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>内容</th><th>状态</th><th>作者</th><th>更新时间</th><th class="admin-col-ops">操作</th></tr></thead><tbody>
  <?php if ($items === []): ?><tr><td colspan="5"><div class="admin-empty-list"><?= admin_icon('message', 32) ?><p>还没有微语</p><a class="btn btn-outline" href="<?= e(url_to('/admin/micro/new')) ?>">写下第一条</a></div></td></tr><?php endif; ?>
  <?php foreach ($items as $item): ?><tr data-micro-row="<?= (int) $item['id'] ?>"><td data-label="内容"><a class="admin-post-title" href="<?= e(url_to('/admin/micro/' . (int) $item['id'] . '/edit')) ?>"><?= e(mb_strimwidth(str_replace(["\r", "\n"], ' ', (string) $item['content']), 0, 100, '…')) ?></a><?php if (!empty($item['is_pinned'])): ?><span class="badge badge-accent">置顶</span><?php endif; ?><?php if (!empty($item['is_private'])): ?><span class="badge">私密</span><?php endif; ?></td><td data-label="状态"><span class="badge <?= $item['status'] === 'PUBLISHED' ? 'badge-success' : '' ?>"><?= $item['status'] === 'PUBLISHED' ? '已发布' : '草稿' ?></span></td><td data-label="作者" class="admin-muted"><?= e((string) ($item['author_name'] ?? '')) ?></td><td data-label="更新时间" class="admin-muted"><?= e(format_date($item['updated_at'], 'yyyy-MM-dd HH:mm')) ?></td><td class="admin-col-ops" data-label="操作"><div class="admin-row-ops"><a class="admin-icon-btn" href="<?= e(url_to('/admin/micro/' . (int) $item['id'] . '/edit')) ?>" title="编辑"><?= admin_icon('edit', 15) ?></a><button type="button" class="admin-icon-btn admin-icon-danger" data-delete-micro="<?= (int) $item['id'] ?>" title="删除"><?= admin_icon('trash', 15) ?></button></div></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?= admin_pagination((int) $page, (int) $totalPages, (int) $total, static function (int $p, int $size = 20) use ($filters): string { $params = ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? '', 'page' => $p]; $params = array_filter($params, static fn ($v) => $v !== '' && $v !== 1); return url_to('/admin/micro') . ($params ? '?' . http_build_query($params) : ''); }, 20) ?>
</div>
<?php $microCsrf = csrf_token(); ?><script>(function(){var token=<?= json_encode($microCsrf) ?>;document.querySelectorAll('[data-delete-micro]').forEach(function(btn){btn.addEventListener('click',function(){var ok=window.pafishConfirm?window.pafishConfirm('确定删除这条微语？',{title:'删除微语'}):Promise.resolve(window.confirm('确定删除这条微语？'));ok.then(function(yes){if(!yes)return;var fd=new FormData();fd.append('_csrf',token);fetch(<?= json_encode(url_to('/admin/micro')) ?>+'/'+btn.getAttribute('data-delete-micro')+'/delete',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json()}).then(function(j){if(j&&j.ok){var row=btn.closest('[data-micro-row]');if(row)row.remove();pafishToast('微语已删除','success')}else pafishNotify((j&&j.error)||'删除失败',true)}).catch(function(){pafishNotify('网络错误',true)})})})})})();</script>
<?php endif; ?>
