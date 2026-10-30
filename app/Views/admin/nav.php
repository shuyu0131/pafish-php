<?php
/** Navigation items are content targets or links, arranged as a recursive tree. */
$count = count($items);
$hidden = count(array_filter($items, static fn (array $item): bool => (int) $item['visible'] === 0));
?>
<div class="admin-stack admin-detail-page admin-nav-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e((string) ($selectedMenu['name'] ?? '导航菜单')) ?></h1>
      <p class="admin-page-sub"><?= !empty($selectedMenu['is_primary']) ? '主题默认菜单' : '独立菜单组' ?> · 共 <?= $count ?> 项，含隐藏 <?= $hidden ?> 项</p>
    </div>
    <button type="button" class="btn btn-primary" data-entity-new="navModal"><?= admin_icon('plus', 15) ?>添加导航项</button>
  </div>

  <div class="admin-nav-menu-tools card">
    <label class="admin-nav-menu-select">
      <span class="admin-muted">菜单组</span>
      <select class="input" id="navMenuSelect" aria-label="选择菜单组">
        <?php foreach (($menus ?? []) as $menu): ?>
          <option value="<?= e((string) $menu['slug']) ?>" <?= $menu['slug'] === ($selectedMenu['slug'] ?? '') ? 'selected' : '' ?>><?= e((string) $menu['name']) ?><?= (int) $menu['is_primary'] === 1 ? ' · 主菜单' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="admin-list-ops">
      <?php if (empty($selectedMenu['is_primary'])): ?><button type="button" class="btn btn-ghost" id="navMenuPrimary">设为主菜单</button><?php endif; ?>
      <button type="button" class="btn btn-ghost" id="navMenuEdit">重命名</button>
      <button type="button" class="btn btn-ghost" id="navMenuClone">复制菜单</button>
      <button type="button" class="btn btn-ghost" id="navMenuCreate"><?= admin_icon('plus', 14) ?>新建菜单</button>
      <button type="button" class="btn btn-ghost" id="navMenuDelete" <?= count($menus ?? []) <= 1 ? 'disabled' : '' ?>>删除</button>
    </div>
  </div>

  <?php if ($count === 0): ?>
    <div class="admin-empty-list card"><?= admin_icon('menu', 32) ?><p>还没有导航项</p></div>
  <?php else: ?>
    <div class="admin-table-wrap admin-order-table-wrap">
      <table class="admin-table admin-order-table">
        <thead><tr><th>名称</th><th>地址</th><th>状态</th><th class="admin-col-ops">操作</th></tr></thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <tr class="admin-list-row" data-id="<?= (int) $item['id'] ?>"
                data-parent="<?= $item['parent_id'] === null ? '' : (int) $item['parent_id'] ?>"
                data-menu-id="<?= (int) ($selectedMenu['id'] ?? 1) ?>"
                data-type="<?= e((string) ($item['type'] ?? (!empty($item['category_id']) ? 'category' : 'link'))) ?>"
                data-target="<?= e((string) ($item['target_id'] ?? '')) ?>"
                data-label="<?= e($item['label']) ?>" data-url="<?= e($item['url']) ?>"
                data-external="<?= (int) $item['is_external'] ?>">
              <td data-label="名称">
                <?php if ((int) $item['depth'] > 0): ?><span class="admin-tree-indent" style="width:<?= (int) $item['depth'] * 18 ?>px" aria-hidden="true"></span><span class="admin-tree-branch" aria-hidden="true">└</span><?php endif; ?>
                <span class="admin-list-name"><?= e($item['label']) ?></span>
                <?php if (($item['type'] ?? '') !== 'link' && ($item['type'] ?? '') !== 'group'): ?> <span class="badge badge-accent"><?= e(['home'=>'首页','archive'=>'归档','search'=>'搜索','category'=>'分类','page'=>'页面','post'=>'文章','tag'=>'标签'][$item['type']] ?? '内容') ?></span><?php elseif (($item['type'] ?? '') === 'group'): ?> <span class="badge">分组</span><?php endif; ?>
              </td>
              <td data-label="地址"><div class="admin-muted"><?php if ((string) $item['url'] !== ''): ?><a href="<?= e(nav_href($item['url'], (int) $item['is_external'] === 1)) ?>" <?= (int) $item['is_external'] === 1 ? 'target="_blank" rel="noopener noreferrer"' : '' ?>><?= e($item['url']) ?></a><?php else: ?>—<?php endif; ?></div></td>
              <td data-label="状态">
                <?= (int) $item['visible'] === 0 ? '<span class="badge badge-danger">已隐藏</span>' : '<span class="badge">已显示</span>' ?>
                <?php if ((int) $item['is_external'] === 1): ?> <span class="badge badge-accent"><?= admin_icon('external-link', 11) ?> 外部</span><?php endif; ?>
              </td>
              <td data-label="操作" class="admin-col-ops"><div class="admin-list-ops">
                <button type="button" class="admin-icon-btn" data-move="up" title="上移" <?= (int) $item['sibling_index'] === 0 ? 'disabled' : '' ?>><?= admin_icon('chevron-up', 15) ?></button>
                <button type="button" class="admin-icon-btn" data-move="down" title="下移" <?= (int) $item['sibling_index'] >= (int) $item['sibling_count'] - 1 ? 'disabled' : '' ?>><?= admin_icon('chevron-down', 15) ?></button>
                <button type="button" class="admin-icon-btn" data-toggle title="<?= (int) $item['visible'] === 1 ? '隐藏' : '显示' ?>"><?= admin_icon((int) $item['visible'] === 1 ? 'eye' : 'eye-off', 15) ?></button>
                <button type="button" class="admin-icon-btn" data-entity-edit="navModal" title="编辑"><?= admin_icon('edit', 15) ?></button>
                <button type="button" class="admin-icon-btn admin-icon-danger" data-delete title="删除"><?= admin_icon('trash', 15) ?></button>
              </div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="admin-modal-backdrop" id="navMenuModal" hidden>
  <div class="admin-modal admin-entity-modal" role="dialog" aria-modal="true" aria-labelledby="navMenuModalTitle">
    <div class="admin-modal-head"><h2 class="admin-modal-title" id="navMenuModalTitle">新建菜单</h2><button type="button" class="admin-icon-btn" data-nav-menu-close aria-label="关闭菜单窗口"><?= admin_icon('x', 16) ?></button></div>
    <form id="navMenuForm" method="post" novalidate>
      <?= csrf_field() ?><input type="hidden" name="id" value="">
      <div class="admin-modal-body"><div class="admin-field"><label class="label" for="navMenuName">菜单名称 *</label><input class="input" id="navMenuName" name="name" type="text" maxlength="100" required></div><div class="admin-editor-error" role="alert" hidden></div></div>
      <div class="admin-modal-actions"><button type="button" class="btn btn-ghost" data-nav-menu-close>取消</button><button type="submit" class="btn btn-primary">保存</button></div>
    </form>
  </div>
</div>

<?php ob_start(); ?>
<div class="admin-form-grid">
  <input type="hidden" name="menu_id" value="<?= (int) ($selectedMenu['id'] ?? 1) ?>">
  <div class="admin-field">
    <label class="label" for="navType">导航目标 *</label>
    <select class="input" name="type" id="navType" required>
      <option value="link">自定义链接</option>
      <option value="group">无链接分组</option>
      <option value="home">首页</option>
      <?php if (!empty($archivesAvailable)): ?><option value="archive">归档</option><?php endif; ?>
      <option value="search">搜索</option>
      <option value="category">分类</option>
      <option value="page">页面</option>
      <option value="post">文章</option>
      <option value="tag">标签</option>
    </select>
  </div>
  <div class="admin-field" data-nav-target hidden>
    <label class="label" for="navTarget">选择目标 *</label>
    <select class="input" name="target_id" id="navTarget" disabled>
      <option value="">请选择目标</option>
      <?php foreach (($categories ?? []) as $category): ?>
        <option value="category:<?= (int) $category['id'] ?>" data-target-type="category"><?= str_repeat('　', (int) $category['depth']) . e($category['name']) ?></option>
      <?php endforeach; ?>
      <?php foreach (($pages ?? []) as $page): ?><option value="page:<?= (int) $page['id'] ?>" data-target-type="page"><?= e($page['title']) ?></option><?php endforeach; ?>
      <?php foreach (($posts ?? []) as $post): ?><option value="post:<?= (int) $post['id'] ?>" data-target-type="post"><?= e($post['title']) ?></option><?php endforeach; ?>
      <?php foreach (($tags ?? []) as $tag): ?><option value="tag:<?= (int) $tag['id'] ?>" data-target-type="tag"><?= e($tag['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="admin-field" data-nav-name>
    <label class="label" for="navLabel">名称 *</label>
    <input class="input" type="text" name="label" placeholder="如：首页 / 关于" maxlength="100" id="navLabel" required>
  </div>
  <div class="admin-field" data-nav-link>
    <label class="label" for="navUrl">地址 *</label>
    <input class="input" type="text" name="url" placeholder="/archives 或 https://…" maxlength="500" id="navUrl" required>
  </div>
  <div class="admin-field">
    <label class="label" for="navParent">上级导航</label>
    <select class="input" name="parent_id" id="navParent">
      <option value="">顶级导航</option>
      <?php foreach ($items as $parent): ?>
        <option value="<?= (int) $parent['id'] ?>"><?= str_repeat('　', (int) ($parent['depth'] ?? 0)) . e($parent['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="admin-field-hint">设置上级导航后，该项会作为子菜单显示。</p>
  </div>
</div>
<label class="admin-check-row" data-nav-link><input type="checkbox" name="is_external" value="1" class="admin-check"><span>外部链接（新窗口打开）</span></label>
<?php
$entityFields = (string) ob_get_clean();
$entityModalId = 'navModal';
$entityFormId = 'createForm';
$entityConfig = ['createTitle'=>'添加导航项', 'editTitle'=>'编辑导航项', 'savedMessage'=>'导航项已保存',
    'savePath'=>'/admin/nav/save', 'basePath'=>'/admin/nav', 'fields'=>['label'=>'label','url'=>'url','is_external'=>'external','parent_id'=>'parent','type'=>'type','target_id'=>'target','menu_id'=>'menu-id']];
require __DIR__ . '/partials/entity-modal.php';
?>
<script src="<?= e(asset_url('/js/admin-entity-forms.js')) ?>" defer></script>
<script>
(function () {
  var base = <?= json_encode(url_to('/admin/nav')) ?>;
  var menuId = <?= (int) ($selectedMenu['id'] ?? 1) ?>;
  var csrf = <?= json_encode(csrf_token()) ?>;
  var modal = document.getElementById('navMenuModal');
  var form = document.getElementById('navMenuForm');
  function post(path, body) {
    var data = new FormData();
    data.append('_csrf', csrf);
    Object.keys(body || {}).forEach(function (key) { data.append(key, body[key]); });
    return fetch(base + path, {method: 'POST', body: data, headers: {'X-Requested-With': 'XMLHttpRequest'}}).then(function (response) { return response.json(); });
  }
  function openMenu(edit) {
    form.reset();
    form.elements.id.value = edit ? String(menuId) : '';
    form.elements.name.value = edit ? <?= json_encode((string) ($selectedMenu['name'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> : '';
    form.dataset.editing = edit ? '1' : '0';
    document.getElementById('navMenuModalTitle').textContent = edit ? '重命名菜单' : '新建菜单';
    var error = form.querySelector('[role=alert]'); error.hidden = true;
    modal.hidden = false;
    form.elements.name.focus();
  }
  document.getElementById('navMenuSelect').addEventListener('change', function () { location.href = base + '?menu=' + encodeURIComponent(this.value); });
  document.getElementById('navMenuCreate').addEventListener('click', function () { openMenu(false); });
  document.getElementById('navMenuEdit').addEventListener('click', function () { openMenu(true); });
  document.querySelectorAll('[data-nav-menu-close]').forEach(function (button) { button.addEventListener('click', function () { modal.hidden = true; }); });
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (!form.reportValidity()) return;
    var id = form.elements.id.value;
    post(id ? '/menus/' + id + '/save' : '/menus/save', {name: form.elements.name.value}).then(function (result) {
      if (result && result.ok) location.href = base + '?menu=' + encodeURIComponent(result.slug);
      else { var error = form.querySelector('[role=alert]'); error.textContent = (result && result.error) || '保存失败，请重试'; error.hidden = false; }
    }).catch(function () { var error = form.querySelector('[role=alert]'); error.textContent = '网络错误，请重试'; error.hidden = false; });
  });
  var primary = document.getElementById('navMenuPrimary');
  if (primary) primary.addEventListener('click', function () { post('/menus/' + menuId + '/primary').then(function (result) { if (result && result.ok) location.reload(); else pafishNotify((result && result.error) || '操作失败', true); }); });
  document.getElementById('navMenuClone').addEventListener('click', function () {
    post('/menus/' + menuId + '/clone').then(function (result) { if (result && result.ok) location.href = base + '?menu=' + encodeURIComponent(result.slug); else pafishNotify((result && result.error) || '复制失败', true); }).catch(function () { pafishNotify('网络错误', true); });
  });
  document.getElementById('navMenuDelete').addEventListener('click', function () {
    var confirmDelete = window.pafishConfirm ? window.pafishConfirm('删除此菜单及其中所有菜单项？', {title: '删除菜单'}) : Promise.resolve(window.confirm('删除此菜单及其中所有菜单项？'));
    confirmDelete.then(function (confirmed) { if (!confirmed) return; return post('/menus/' + menuId + '/delete').then(function (result) { if (result && result.ok) location.href = base + (result.primarySlug ? '?menu=' + encodeURIComponent(result.primarySlug) : ''); else pafishNotify((result && result.error) || '删除失败', true); }); }).catch(function () { pafishNotify('网络错误', true); });
  });
})();
</script>
<script>
(function () {
  var modal = document.getElementById('navModal');
  var parent = document.getElementById('navParent');
  var type = document.getElementById('navType');
  var target = document.getElementById('navTarget');
  var targetWrap = modal.querySelector('[data-nav-target]');
  var linkFields = modal.querySelectorAll('[data-nav-link]');
  var nameField = modal.querySelector('[data-nav-name]');
  function syncType() {
    var targetType = ['category', 'page', 'post', 'tag'].indexOf(type.value) >= 0;
    targetWrap.hidden = !targetType;
    target.disabled = !targetType;
    target.required = targetType;
    Array.from(target.options).forEach(function (option) {
      var incompatible = option.value !== '' && option.dataset.targetType !== type.value;
      option.hidden = incompatible;
      option.disabled = incompatible;
    });
    if (target.selectedOptions.length && target.selectedOptions[0].disabled) target.value = '';
    target.dispatchEvent(new Event('pafish:control-sync'));
    var custom = type.value === 'link';
    linkFields.forEach(function (group) {
      group.hidden = !custom;
      group.querySelectorAll('input, select, textarea').forEach(function (field) { field.disabled = !custom; });
    });
    nameField.hidden = !custom && type.value !== 'group';
    nameField.querySelectorAll('input').forEach(function (field) { field.disabled = nameField.hidden; });
  }
  type.addEventListener('change', syncType);
  modal.querySelector('form').addEventListener('pafish:entity-open', function (event) {
    var row = event.detail && event.detail.row;
    var blocked = Object.create(null);
    if (row) {
      blocked[row.dataset.id] = true;
      var changed = true;
      var rows = Array.from(document.querySelectorAll('.admin-list-row'));
      while (changed) {
        changed = false;
        rows.forEach(function (candidate) {
          if (blocked[candidate.dataset.parent] && !blocked[candidate.dataset.id]) {
            blocked[candidate.dataset.id] = true;
            changed = true;
          }
        });
      }
    }
    Array.from(parent.options).forEach(function (option) {
      option.disabled = option.value !== '' && !!blocked[option.value];
    });
    parent.value = row ? (row.dataset.parent || '') : '';
    parent.dispatchEvent(new Event('pafish:control-sync'));
    syncType();
    target.value = row && row.dataset.target ? row.dataset.type + ':' + row.dataset.target : '';
    target.dispatchEvent(new Event('pafish:control-sync'));
  });
})();
</script>

<?php $navCsrf = csrf_token(); ?>
<script>
(function () {
  'use strict';
  var CSRF = <?= json_encode($navCsrf) ?>;
  var base = <?= json_encode(url_to('/admin/nav')) ?>;
  function post(url, body) {
    var form = new FormData();
    Object.keys(body || {}).forEach(function (key) { form.append(key, body[key]); });
    form.append('_csrf', CSRF);
    return fetch(url, {method: 'POST', body: form, headers: {'X-Requested-With': 'XMLHttpRequest'}});
  }
  document.querySelectorAll('[data-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
      var row = button.closest('.admin-list-row');
      post(base + '/' + row.dataset.id + '/toggle').then(function (response) { return response.json(); })
        .then(function (result) { if (result && result.ok) pafishToastReload('导航项状态已更新', 'success'); else pafishNotify((result && result.error) || '操作失败', true); })
        .catch(function () { pafishNotify('网络错误', true); });
    });
  });
  document.querySelectorAll('[data-move]').forEach(function (button) {
    button.addEventListener('click', function () {
      var row = button.closest('.admin-list-row');
      post(base + '/' + row.dataset.id + '/move', {dir: button.dataset.move}).then(function (response) { return response.json(); })
        .then(function (result) { if (result && result.ok) pafishToastReload('导航顺序已更新', 'success'); else pafishNotify((result && result.error) || '操作失败', true); })
        .catch(function () { pafishNotify('网络错误', true); });
    });
  });
  document.querySelectorAll('[data-delete]').forEach(function (button) {
    button.addEventListener('click', function () {
      var row = button.closest('.admin-list-row');
      var label = row.dataset.label;
      var confirmDelete = window.pafishConfirm
        ? window.pafishConfirm('确定删除导航项「' + label + '」？', {title: '删除导航项'})
        : Promise.resolve(window.confirm('确定删除导航项「' + label + '」？'));
      confirmDelete.then(function (confirmed) {
        if (!confirmed) return;
        post(base + '/' + row.dataset.id + '/delete').then(function (response) { return response.json(); })
          .then(function (result) { if (result && result.ok) pafishToastReload('导航项已删除', 'success'); else pafishNotify((result && result.error) || '删除失败', true); })
          .catch(function () { pafishNotify('网络错误', true); });
      });
    });
  });
})();
</script>
