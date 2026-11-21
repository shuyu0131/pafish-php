<?php
$pluginTotal = count($plugins);
$pluginActive = count(array_filter($plugins, static fn (array $plugin): bool => !empty($plugin['active'])));
?>
<div class="admin-stack admin-extension-page admin-plugin-page admin-detail-page">
  <div data-store-update-csrf="<?= e(csrf_token()) ?>" data-store-update-url="<?= e(url_to('/admin/store/update')) ?>" hidden></div>
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e(t('admin.plugins')) ?></h1>
    </div>
    <div class="admin-head-actions">
      <a class="btn btn-outline" href="<?= e(url_to('/admin/extensions/data')) ?>"><?= e(t('admin.extension_tables')) ?></a>
      <a class="btn btn-outline" href="<?= e(url_to('/admin/store')) ?>"><?= e(t('admin.app_store')) ?></a>
      <button type="button" class="btn btn-primary" data-open-install="#plugin-install"><?= e(t('admin.install_plugin')) ?></button>
    </div>
  </div>
  <?php if (($catalogNotice ?? '') !== ''): ?><p class="admin-muted" role="status"><?= e($catalogNotice) ?></p><?php endif; ?>

  <div class="admin-resource-toolbar">
    <div class="admin-tabs admin-status-tabs" role="tablist" aria-label="<?= e(t('admin.plugin_filter')) ?>">
      <button type="button" class="admin-tab is-active" data-plugin-filter="all" role="tab" aria-selected="true"><?= e(t('common.all')) ?></button>
      <button type="button" class="admin-tab" data-plugin-filter="active" role="tab" aria-selected="false"><?= e(t('admin.active')) ?> <span class="admin-tab-count">(<?= $pluginActive ?>)</span></button>
      <button type="button" class="admin-tab" data-plugin-filter="inactive" role="tab" aria-selected="false"><?= e(t('admin.inactive')) ?> <span class="admin-tab-count">(<?= $pluginTotal - $pluginActive ?>)</span></button>
    </div>
    <input type="search" id="pluginSearch" class="input admin-resource-search" aria-label="<?= e(t('admin.search_plugins')) ?>" placeholder="<?= e(t('admin.search_plugin_names_or_descriptions')) ?>" autocomplete="off">
  </div>

  <div class="admin-resource-list">
      <div class="admin-table-wrap" role="region" aria-label="<?= e(t('admin.plugin_list_scroll_horizontally_for_full_details')) ?>" tabindex="0">
        <table class="admin-table admin-plugins-table">
          <thead>
            <tr>
              <th><?= e(t('admin.plugin_76fcd7')) ?></th>
              <th class="admin-col-md admin-plugin-status-col"><?= e(t('common.status')) ?></th>
              <th class="admin-col-md admin-plugin-secondary"><?= e(t('admin.author')) ?></th>
              <th class="admin-col-sm admin-plugin-secondary"><?= e(t('admin.version')) ?></th>
              <th class="admin-col-ops"><?= e(t('common.actions')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($plugins === []): ?>
              <tr><td colspan="5">
                <div class="admin-empty-list">
                  <?= admin_icon('box', 32) ?>
                  <p><?= e(t('admin.no_plugins_installed')) ?></p>
                </div>
              </td></tr>
            <?php endif; ?>
            <?php foreach ($plugins as $p): ?>
              <tr data-plugin-state="<?= $p['active'] ? 'active' : 'inactive' ?>">
                <td>
                  <div class="admin-plugin-cell">
                    <span class="admin-plugin-icon" aria-hidden="true"><?= admin_icon('puzzle', 22) ?></span>
                    <div class="admin-plugin-info">
                    <div class="admin-plugin-title">
                    <?php if ($p['active'] && $p['settingsCount'] > 0): ?>
                      <a href="<?= e(url_to('/admin/plugins/' . rawurlencode($p['name']))) ?>" class="admin-plugin-name" title="<?= e($p['title']) ?>"><?= e($p['title']) ?></a>
                    <?php else: ?>
                      <span class="admin-plugin-name" title="<?= e($p['title']) ?>"><?= e($p['title']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($p['builtin'])): ?><span class="badge badge-primary admin-plugin-builtin"><?= e(t('admin.built_in')) ?></span><?php endif; ?>
                    <?php if ($p['active'] && ($p['frontendUrl'] ?? '') !== ''): ?>
                      <a class="admin-resource-link admin-plugin-front-link" href="<?= e(url_to((string) $p['frontendUrl'])) ?>" target="_blank" rel="noopener noreferrer" aria-label="打开<?= e($p['title']) ?>前台页面" title="<?= e(t('admin.open_public_page')) ?>"><?= admin_icon('external-link', 13) ?></a>
                    <?php endif; ?>
                  </div>
                  <div class="admin-plugin-meta">
                    <?php if ($p['error'] !== null): ?>
                      <span class="admin-text-danger"><?= e($p['error']) ?></span>
                      <span class="admin-muted"><?= e(t('admin.valid_plugin_json_missing')) ?></span>
                    <?php else: ?>
                      <span><?= e($p['description'] !== '' ? $p['description'] : t('admin.no_description_provided')) ?></span>
                      <?php if (($p['homepage'] ?? '') !== ''): ?><a class="admin-resource-link" href="<?= e($p['homepage']) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('admin.project_homepage')) ?></a><?php endif; ?>
                    <?php endif; ?>
                  </div>
                    </div>
                  </div>
                </td>
                <td class="admin-col-md admin-plugin-status-col" data-label="<?= e(t('common.status')) ?>">
                  <?php if ($p['active']): ?>
                    <span class="badge badge-success"><?= e(t('admin.active')) ?></span>
                  <?php else: ?>
                    <span class="badge"><?= e(t('admin.inactive')) ?></span>
                  <?php endif; ?>
                </td>
                <td class="admin-col-md admin-plugin-secondary" data-label="<?= e(t('admin.author')) ?>">
                  <?php if (($p['authorUrl'] ?? '') !== ''): ?><a href="<?= e($p['authorUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= e($p['author'] !== '' ? $p['author'] : t('admin.unknown')) ?></a><?php else: ?><?= e($p['author'] !== '' ? $p['author'] : t('admin.unknown')) ?><?php endif; ?>
                </td>
                <td class="admin-col-sm admin-plugin-secondary" data-label="<?= e(t('admin.version')) ?>">
                  <?php if ($p['version'] !== ''): ?>v<?= e($p['version']) ?><?php endif; ?>
                  <div class="admin-muted" style="font-size: 11px;">API v<?= (int) $p['apiVersion'] ?></div>
                  <?php if (is_array($p['update'] ?? null)): ?><span class="badge badge-accent"><?= e(t('admin.update_available_v')) ?><?= e($p['update']['version']) ?></span><?php endif; ?>
                </td>
                <td class="admin-col-ops" data-label="<?= e(t('common.actions')) ?>">
                  <div class="admin-row-ops">
                    <?php if (is_array($p['update'] ?? null)): ?>
                      <button type="button" class="btn btn-primary btn-sm admin-store-update" data-kind="plugin" data-name="<?= e($p['name']) ?>" data-title="<?= e($p['title']) ?>" data-version="<?= e($p['update']['version']) ?>" data-local-version="<?= e($p['version']) ?>" data-changelog="<?= e($p['update']['changelog']) ?>"><?= e(t('admin.update')) ?></button>
                    <?php endif; ?>
                    <?php if ($p['active'] && $p['settingsCount'] > 0): ?>
                      <a class="btn btn-sm btn-primary" href="<?= e(url_to('/admin/plugins/' . rawurlencode($p['name']))) ?>"><?= e(t('admin.settings')) ?></a>
                    <?php endif; ?>
                    <?php if ($p['active']): ?>
                      <button type="button" class="btn btn-ghost btn-sm admin-plugin-deactivate" data-name="<?= e($p['name']) ?>" data-title="<?= e($p['title']) ?>"><?= e(t('admin.deactivate')) ?></button>
                    <?php else: ?>
                      <?php if ($p['error'] === null): ?>
                        <button type="button" class="btn btn-primary btn-sm admin-plugin-activate" data-name="<?= e($p['name']) ?>" data-title="<?= e($p['title']) ?>"><?= e(t('admin.activate')) ?></button>
                      <?php endif; ?>
                    <?php endif; ?>
                    <?php if (empty($p['builtin'])): ?>
                      <button type="button" class="btn btn-ghost btn-sm admin-plugin-uninstall" data-name="<?= e($p['name']) ?>" data-title="<?= e($p['title']) ?>"><?= e(t('admin.uninstall')) ?></button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
  </div>

  <div class="admin-modal-backdrop admin-install-modal" id="plugin-install" hidden>
    <div class="admin-modal admin-install-dialog" role="dialog" aria-modal="true" aria-labelledby="plugin-install-title">
      <div class="admin-modal-head admin-install-head">
        <h2 class="admin-modal-title" id="plugin-install-title"><?= e(t('admin.install_plugin')) ?></h2>
        <button type="button" class="admin-icon-btn" data-modal-close aria-label="<?= e(t('admin.close_installation_panel')) ?>" title="<?= e(t('admin.close_installation_panel')) ?>">×</button>
      </div>
      <div class="admin-modal-body admin-install-body">
        <input type="file" id="pluginZipInput" accept=".zip" hidden>
        <label class="admin-package-picker" for="pluginZipInput" id="pluginZipPicker">
          <span class="admin-package-picker-icon" aria-hidden="true"><?= admin_icon('upload', 20) ?></span>
          <span class="admin-package-picker-copy">
            <strong><?= e(t('admin.choose_plugin_archive')) ?></strong>
            <span id="pluginZipName" aria-live="polite"><?= e(t('admin.only_zip_files_are_supported')) ?></span>
          </span>
        </label>
      </div>
      <div class="admin-modal-actions admin-install-actions">
        <button type="button" class="btn btn-ghost" data-modal-close><?= e(t('common.cancel')) ?></button>
        <button type="button" id="pluginUploadBtn" class="btn btn-primary" disabled><?= e(t('admin.install_plugin')) ?></button>
      </div>
    </div>
  </div>
</div>

<?php $pluginCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($pluginCsrf) ?>;
  try { sessionStorage.removeItem("pluginMsg"); } catch (e) {}
  function showMsg(text, isError) {
    if (typeof window.pafishToast === "function") {
      window.pafishToast(text, isError ? "error" : "success");
    }
  }
  var pluginRows = Array.prototype.slice.call(document.querySelectorAll("[data-plugin-state]"));
  var pluginSearch = document.getElementById("pluginSearch");
  var pluginFilter = "all";
  function filterPlugins() {
    var query = pluginSearch ? pluginSearch.value.trim().toLowerCase() : "";
    pluginRows.forEach(function (row) {
      var stateMatch = pluginFilter === "all" || row.dataset.pluginState === pluginFilter;
      var textMatch = !query || row.textContent.toLowerCase().indexOf(query) !== -1;
      row.hidden = !(stateMatch && textMatch);
    });
  }
  document.querySelectorAll("[data-plugin-filter]").forEach(function (tab) {
    tab.addEventListener("click", function () {
      pluginFilter = tab.dataset.pluginFilter;
      document.querySelectorAll("[data-plugin-filter]").forEach(function (item) {
        var selected = item === tab;
        item.classList.toggle("is-active", selected);
        item.setAttribute("aria-selected", selected ? "true" : "false");
      });
      filterPlugins();
    });
  });
  if (pluginSearch) pluginSearch.addEventListener("input", filterPlugins);

  function post(url, fd) {
    return fetch(url, {
      method: "POST",
      body: fd,
      headers: { "X-Requested-With": "XMLHttpRequest" }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) return j;
        throw new Error((j && j.error) || window.pafishT("common.operation_failed"));
      });
  }

  // ---- 启用 / 停用 / 卸载 ----
  document.querySelectorAll(".admin-plugin-activate").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var fd = new FormData();
      fd.append("name", btn.dataset.name);
      fd.append("_csrf", CSRF);
      post("/admin/plugins/activate", fd)
        .then(function () { if (typeof window.pafishToastReload === "function") window.pafishToastReload(window.pafishT("js.activated") + btn.dataset.title, "success"); else location.reload(); })
        .catch(function (err) { showMsg(err.message, true); });
    });
  });
  document.querySelectorAll(".admin-plugin-deactivate").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var fd = new FormData();
      fd.append("name", btn.dataset.name);
      fd.append("_csrf", CSRF);
      post("/admin/plugins/deactivate", fd)
        .then(function () { if (typeof window.pafishToastReload === "function") window.pafishToastReload(window.pafishT("js.deactivated") + btn.dataset.title, "success"); else location.reload(); })
        .catch(function (err) { showMsg(err.message, true); });
    });
  });
  document.querySelectorAll(".admin-plugin-uninstall").forEach(function (btn) {
    btn.addEventListener("click", function () {
      (window.pafishConfirm ? window.pafishConfirm(window.pafishT("js.confirm_delete_plugin", {name: btn.dataset.title}), { title: window.pafishT("js.uninstall_plugin"), accept: window.pafishT("js.uninstall_and_delete") }) : Promise.resolve(window.confirm(window.pafishT("js.permanently_delete_this_plugin_this_cannot_be_undone")))).then(function (ok) {
        if (!ok) return;
        var deleteData = window.confirm(window.pafishT("js.also_delete_this_plugin_s_settings_data_and"));
        var fd = new FormData();
        fd.append("name", btn.dataset.name);
        fd.append("delete_data", deleteData ? "1" : "0");
        fd.append("_csrf", CSRF);
        post("/admin/plugins/uninstall", fd)
          .then(function () { if (typeof window.pafishToastReload === "function") window.pafishToastReload(window.pafishT("js.uninstalled") + btn.dataset.title, "success"); else location.reload(); })
          .catch(function (err) { showMsg(err.message, true); });
      });
    });
  });

  // ---- 安装：zip / URL ----
  var zipInput = document.getElementById("pluginZipInput");
  var uploadBtn = document.getElementById("pluginUploadBtn");
  var zipName = document.getElementById("pluginZipName");
  var zipPicker = document.getElementById("pluginZipPicker");

  zipInput.addEventListener("change", function () {
    if (zipInput.files && zipInput.files[0]) {
      zipName.textContent = zipInput.files[0].name;
      zipPicker.classList.add("is-ready");
      uploadBtn.disabled = false;
    } else {
      zipName.textContent = window.pafishT("js.only_zip_files_are_supported");
      zipPicker.classList.remove("is-ready");
      uploadBtn.disabled = true;
    }
  });
  function install(fd, okText, button, label) {
    post("/admin/plugins/install", fd)
      .then(function (j) {
        var message = okText + window.pafishT("js.installed") + j.title + " v" + j.version + window.pafishT("js.you_can_activate_it_above");
        if (typeof window.pafishToastReload === "function") window.pafishToastReload(message, "success"); else location.reload();
      })
      .catch(function (err) {
        if (button) { button.disabled = false; button.textContent = label; }
        showMsg(err.message, true);
      });
  }
  uploadBtn.addEventListener("click", function () {
    if (!zipInput.files || !zipInput.files[0]) { return; }
    uploadBtn.disabled = true;
    uploadBtn.textContent = window.pafishT("js.installing");
    var fd = new FormData();
    fd.append("zip", zipInput.files[0]);
    fd.append("_csrf", CSRF);
    install(fd, window.pafishT("js.plugin_package_uploaded"), uploadBtn, window.pafishT("js.install_plugin"));
  });
})();
</script>
<script src="<?= e(asset_url('/js/admin-app-updates.js')) ?>"></script>
