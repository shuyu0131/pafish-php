<?php
/** Navigation items are content targets or links, arranged as a recursive tree. */
$count = count($items);
$hidden = count(array_filter($items, static fn (array $item): bool => (int) $item['visible'] === 0));
?>
<div class="admin-stack admin-detail-page admin-nav-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e((string) ($selectedMenu['name'] ?? t('admin.navigation_menus'))) ?></h1>
      <p class="admin-page-sub"><?= !empty($selectedMenu['is_primary']) ? t('admin.theme_default_menu') : t('admin.separate_menu_group') ?> <?= e(t('admin.total_0e9fac')) ?> <?= $count ?> <?= e(t('admin.items_including_hidden_46c3df')) ?> <?= $hidden ?> <?= e(t('admin.items_64728a')) ?></p>
    </div>
    <button type="button" class="btn btn-primary" data-entity-new="navModal"><?= admin_icon('plus', 15) ?><?= e(t('admin.add_menu_item')) ?></button>
  </div>

  <div class="admin-nav-menu-tools card">
    <label class="admin-nav-menu-select">
      <span class="admin-muted"><?= e(t('admin.menu_group')) ?></span>
      <select class="input" id="navMenuSelect" aria-label="<?= e(t('admin.choose_menu_group')) ?>">
        <?php foreach (($menus ?? []) as $menu): ?>
          <option value="<?= e((string) $menu['slug']) ?>" <?= $menu['slug'] === ($selectedMenu['slug'] ?? '') ? 'selected' : '' ?>><?= e((string) $menu['name']) ?><?= (int) $menu['is_primary'] === 1 ? t('admin.primary_menu') : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="admin-list-ops">
      <?php if (empty($selectedMenu['is_primary'])): ?><button type="button" class="btn btn-ghost" id="navMenuPrimary"><?= e(t('admin.set_as_primary_menu')) ?></button><?php endif; ?>
      <button type="button" class="btn btn-ghost" id="navMenuEdit"><?= e(t('admin.rename')) ?></button>
      <button type="button" class="btn btn-ghost" id="navMenuClone"><?= e(t('admin.duplicate_menu')) ?></button>
      <button type="button" class="btn btn-ghost" id="navMenuCreate"><?= admin_icon('plus', 14) ?><?= e(t('admin.new_menu')) ?></button>
      <button type="button" class="btn btn-ghost" id="navMenuDelete" <?= count($menus ?? []) <= 1 ? 'disabled' : '' ?>><?= e(t('common.delete')) ?></button>
    </div>
  </div>

  <?php if ($count === 0): ?>
    <div class="admin-empty-list card"><?= admin_icon('menu', 32) ?><p><?= e(t('admin.no_menu_items_yet')) ?></p></div>
  <?php else: ?>
    <div class="admin-table-wrap admin-order-table-wrap">
      <table class="admin-table admin-order-table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('admin.url')) ?></th><th><?= e(t('common.status')) ?></th><th class="admin-col-ops"><?= e(t('common.actions')) ?></th></tr></thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <tr class="admin-list-row" data-id="<?= (int) $item['id'] ?>"
                data-parent="<?= $item['parent_id'] === null ? '' : (int) $item['parent_id'] ?>"
                data-menu-id="<?= (int) ($selectedMenu['id'] ?? 1) ?>"
                data-type="<?= e((string) ($item['type'] ?? (!empty($item['category_id']) ? 'category' : 'link'))) ?>"
                data-target="<?= e((string) ($item['target_id'] ?? '')) ?>"
                data-label="<?= e($item['label']) ?>" data-url="<?= e($item['url']) ?>"
                data-external="<?= (int) $item['is_external'] ?>">
              <td data-label="<?= e(t('common.name')) ?>">
                <?php if ((int) $item['depth'] > 0): ?><span class="admin-tree-indent" style="width:<?= (int) $item['depth'] * 18 ?>px" aria-hidden="true"></span><span class="admin-tree-branch" aria-hidden="true">└</span><?php endif; ?>
                <span class="admin-list-name"><?= e($item['label']) ?></span>
                <?php if (($item['type'] ?? '') !== 'link' && ($item['type'] ?? '') !== 'group'): ?> <span class="badge badge-accent"><?= e(['home'=>t('admin.home'),'archive'=>t('admin.archives'),'search'=>t('common.search'),'category'=>t('common.category'),'page'=>t('common.page'),'post'=>t('common.post'),'tag'=>t('common.tags')][$item['type']] ?? t('admin.content')) ?></span><?php elseif (($item['type'] ?? '') === 'group'): ?> <span class="badge"><?= e(t('admin.group')) ?></span><?php endif; ?>
              </td>
              <td data-label="<?= e(t('admin.url')) ?>"><div class="admin-muted"><?php if ((string) $item['url'] !== ''): ?><a href="<?= e(nav_href($item['url'], (int) $item['is_external'] === 1)) ?>" <?= (int) $item['is_external'] === 1 ? 'target="_blank" rel="noopener noreferrer"' : '' ?>><?= e($item['url']) ?></a><?php else: ?>—<?php endif; ?></div></td>
              <td data-label="<?= e(t('common.status')) ?>">
                <?= (int) $item['visible'] === 0 ? ('<span class="badge badge-danger">' . e(t('admin.hidden')) . '</span>') : ('<span class="badge">' . e(t('admin.visible')) . '</span>') ?>
                <?php if ((int) $item['is_external'] === 1): ?> <span class="badge badge-accent"><?= admin_icon('external-link', 11) ?> <?= e(t('admin.external')) ?></span><?php endif; ?>
              </td>
              <td data-label="<?= e(t('common.actions')) ?>" class="admin-col-ops"><div class="admin-list-ops">
                <button type="button" class="admin-icon-btn" data-move="up" title="<?= e(t('admin.move_up')) ?>" <?= (int) $item['sibling_index'] === 0 ? 'disabled' : '' ?>><?= admin_icon('chevron-up', 15) ?></button>
                <button type="button" class="admin-icon-btn" data-move="down" title="<?= e(t('admin.move_down')) ?>" <?= (int) $item['sibling_index'] >= (int) $item['sibling_count'] - 1 ? 'disabled' : '' ?>><?= admin_icon('chevron-down', 15) ?></button>
                <button type="button" class="admin-icon-btn" data-toggle title="<?= (int) $item['visible'] === 1 ? t('admin.hide') : t('admin.show') ?>"><?= admin_icon((int) $item['visible'] === 1 ? 'eye' : 'eye-off', 15) ?></button>
                <button type="button" class="admin-icon-btn" data-entity-edit="navModal" title="<?= e(t('common.edit')) ?>"><?= admin_icon('edit', 15) ?></button>
                <button type="button" class="admin-icon-btn admin-icon-danger" data-delete title="<?= e(t('common.delete')) ?>"><?= admin_icon('trash', 15) ?></button>
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
    <div class="admin-modal-head"><h2 class="admin-modal-title" id="navMenuModalTitle"><?= e(t('admin.new_menu')) ?></h2><button type="button" class="admin-icon-btn" data-nav-menu-close aria-label="<?= e(t('admin.close_menu_dialog')) ?>"><?= admin_icon('x', 16) ?></button></div>
    <form id="navMenuForm" method="post" novalidate>
      <?= csrf_field() ?><input type="hidden" name="id" value="">
      <div class="admin-modal-body"><div class="admin-field"><label class="label" for="navMenuName"><?= e(t('admin.menu_name')) ?></label><input class="input" id="navMenuName" name="name" type="text" maxlength="100" required></div><div class="admin-editor-error" role="alert" hidden></div></div>
      <div class="admin-modal-actions"><button type="button" class="btn btn-ghost" data-nav-menu-close><?= e(t('common.cancel')) ?></button><button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button></div>
    </form>
  </div>
</div>

<?php ob_start(); ?>
<div class="admin-form-grid">
  <input type="hidden" name="menu_id" value="<?= (int) ($selectedMenu['id'] ?? 1) ?>">
  <div class="admin-field">
    <label class="label" for="navType"><?= e(t('admin.menu_target')) ?></label>
    <select class="input" name="type" id="navType" required>
      <option value="link"><?= e(t('admin.custom_link')) ?></option>
      <option value="group"><?= e(t('admin.no_link_groups')) ?></option>
      <option value="home"><?= e(t('admin.home')) ?></option>
      <?php if (!empty($archivesAvailable)): ?><option value="archive"><?= e(t('admin.archives')) ?></option><?php endif; ?>
      <option value="search"><?= e(t('common.search')) ?></option>
      <option value="category"><?= e(t('common.category')) ?></option>
      <option value="page"><?= e(t('common.page')) ?></option>
      <option value="post"><?= e(t('common.post')) ?></option>
      <option value="tag"><?= e(t('common.tags')) ?></option>
    </select>
  </div>
  <div class="admin-field" data-nav-target hidden>
    <label class="label" for="navTarget"><?= e(t('admin.choose_target')) ?></label>
    <select class="input" name="target_id" id="navTarget" disabled>
      <option value=""><?= e(t('admin.choose_a_target')) ?></option>
      <?php foreach (($categories ?? []) as $category): ?>
        <option value="category:<?= (int) $category['id'] ?>" data-target-type="category"><?= str_repeat('　', (int) $category['depth']) . e($category['name']) ?></option>
      <?php endforeach; ?>
      <?php foreach (($pages ?? []) as $page): ?><option value="page:<?= (int) $page['id'] ?>" data-target-type="page"><?= e($page['title']) ?></option><?php endforeach; ?>
      <?php foreach (($posts ?? []) as $post): ?><option value="post:<?= (int) $post['id'] ?>" data-target-type="post"><?= e($post['title']) ?></option><?php endforeach; ?>
      <?php foreach (($tags ?? []) as $tag): ?><option value="tag:<?= (int) $tag['id'] ?>" data-target-type="tag"><?= e($tag['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="admin-field" data-nav-name>
    <label class="label" for="navLabel"><?= e(t('admin.name')) ?></label>
    <input class="input" type="text" name="label" placeholder="<?= e(t('admin.for_example_home_about')) ?>" maxlength="100" id="navLabel" required>
  </div>
  <div class="admin-field" data-nav-link>
    <label class="label" for="navUrl"><?= e(t('admin.url_7c05d9')) ?></label>
    <input class="input" type="text" name="url" placeholder="<?= e(t('admin.archives_or_https')) ?>" maxlength="500" id="navUrl" required>
  </div>
  <div class="admin-field">
    <label class="label" for="navParent"><?= e(t('admin.parent_menu_item')) ?></label>
    <select class="input" name="parent_id" id="navParent">
      <option value=""><?= e(t('admin.top_level_item')) ?></option>
      <?php foreach ($items as $parent): ?>
        <option value="<?= (int) $parent['id'] ?>"><?= str_repeat('　', (int) ($parent['depth'] ?? 0)) . e($parent['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="admin-field-hint"><?= e(t('admin.choose_a_parent_to_display_this_item_as')) ?></p>
  </div>
</div>
<label class="admin-check-row" data-nav-link><input type="checkbox" name="is_external" value="1" class="admin-check"><span><?= e(t('admin.external_link_opens_in_new_tab')) ?></span></label>
<?php
$entityFields = (string) ob_get_clean();
$entityModalId = 'navModal';
$entityFormId = 'createForm';
$entityConfig = ['createTitle'=>t('admin.add_menu_item'), 'editTitle'=>t('admin.edit_menu_item'), 'savedMessage'=>t('admin.menu_item_saved'),
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
    document.getElementById('navMenuModalTitle').textContent = edit ? window.pafishT("js.rename_menu") : window.pafishT("js.new_menu");
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
      else { var error = form.querySelector('[role=alert]'); error.textContent = (result && result.error) || window.pafishT("js.unable_to_save_please_try_again"); error.hidden = false; }
    }).catch(function () { var error = form.querySelector('[role=alert]'); error.textContent = window.pafishT("common.network_error_retry"); error.hidden = false; });
  });
  var primary = document.getElementById('navMenuPrimary');
  if (primary) primary.addEventListener('click', function () { post('/menus/' + menuId + '/primary').then(function (result) { if (result && result.ok) location.reload(); else pafishNotify((result && result.error) || window.pafishT("common.operation_failed"), true); }); });
  document.getElementById('navMenuClone').addEventListener('click', function () {
    post('/menus/' + menuId + '/clone').then(function (result) { if (result && result.ok) location.href = base + '?menu=' + encodeURIComponent(result.slug); else pafishNotify((result && result.error) || window.pafishT("js.copy_failed"), true); }).catch(function () { pafishNotify(window.pafishT("js.network_error"), true); });
  });
  document.getElementById('navMenuDelete').addEventListener('click', function () {
    var confirmDelete = window.pafishConfirm ? window.pafishConfirm(window.pafishT("js.delete_this_menu_and_all_its_items"), {title: window.pafishT("js.delete_menu")}) : Promise.resolve(window.confirm(window.pafishT("js.delete_this_menu_and_all_its_items")));
    confirmDelete.then(function (confirmed) { if (!confirmed) return; return post('/menus/' + menuId + '/delete').then(function (result) { if (result && result.ok) location.href = base + (result.primarySlug ? '?menu=' + encodeURIComponent(result.primarySlug) : ''); else pafishNotify((result && result.error) || window.pafishT("js.delete_failed"), true); }); }).catch(function () { pafishNotify(window.pafishT("js.network_error"), true); });
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
        .then(function (result) { if (result && result.ok) pafishToastReload(window.pafishT("js.menu_item_visibility_updated"), 'success'); else pafishNotify((result && result.error) || window.pafishT("common.operation_failed"), true); })
        .catch(function () { pafishNotify(window.pafishT("js.network_error"), true); });
    });
  });
  document.querySelectorAll('[data-move]').forEach(function (button) {
    button.addEventListener('click', function () {
      var row = button.closest('.admin-list-row');
      post(base + '/' + row.dataset.id + '/move', {dir: button.dataset.move}).then(function (response) { return response.json(); })
        .then(function (result) { if (result && result.ok) pafishToastReload(window.pafishT("js.menu_order_updated"), 'success'); else pafishNotify((result && result.error) || window.pafishT("common.operation_failed"), true); })
        .catch(function () { pafishNotify(window.pafishT("js.network_error"), true); });
    });
  });
  document.querySelectorAll('[data-delete]').forEach(function (button) {
    button.addEventListener('click', function () {
      var row = button.closest('.admin-list-row');
      var label = row.dataset.label;
      var confirmDelete = window.pafishConfirm
        ? window.pafishConfirm(window.pafishT("js.confirm_delete_menu_item", {name: label}), {title: window.pafishT("js.delete_menu_item_b10fa2")})
        : Promise.resolve(window.confirm(window.pafishT("js.confirm_delete_menu_item", {name: label})));
      confirmDelete.then(function (confirmed) {
        if (!confirmed) return;
        post(base + '/' + row.dataset.id + '/delete').then(function (response) { return response.json(); })
          .then(function (result) { if (result && result.ok) pafishToastReload(window.pafishT("js.menu_item_deleted"), 'success'); else pafishNotify((result && result.error) || window.pafishT("js.delete_failed"), true); })
          .catch(function () { pafishNotify(window.pafishT("js.network_error"), true); });
      });
    });
  });
})();
</script>
