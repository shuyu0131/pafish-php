<?php
$themeTotal = count($themes);
$themeActive = count(array_filter($themes, static fn (array $theme): bool => !empty($theme['active'])));
?>
<div class="admin-stack admin-theme-page">
  <div data-store-update-csrf="<?= e(csrf_token()) ?>" data-store-update-url="<?= e(url_to('/admin/store/update')) ?>" hidden></div>
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e(t('admin.themes_and_appearance')) ?></h1>
    </div>
    <div class="admin-head-actions">
      <a class="btn btn-outline" href="<?= e(url_to('/admin/store')) ?>"><?= e(t('admin.app_store')) ?></a>
      <button type="button" class="btn btn-primary" data-open-install="#theme-install"><?= e(t('admin.install_theme')) ?></button>
    </div>
  </div>
  <?php if (($catalogNotice ?? '') !== ''): ?><p class="admin-muted" role="status"><?= e($catalogNotice) ?></p><?php endif; ?>

  <div class="admin-resource-toolbar">
    <div class="admin-tabs admin-status-tabs" role="tablist" aria-label="<?= e(t('admin.theme_filter')) ?>">
      <button type="button" class="admin-tab is-active" data-theme-filter="all" role="tab" aria-selected="true"><?= e(t('common.all')) ?></button>
      <button type="button" class="admin-tab" data-theme-filter="active" role="tab" aria-selected="false"><?= e(t('admin.current_theme')) ?> <span class="admin-tab-count">(<?= $themeActive ?>)</span></button>
      <button type="button" class="admin-tab" data-theme-filter="inactive" role="tab" aria-selected="false"><?= e(t('admin.inactive')) ?> <span class="admin-tab-count">(<?= $themeTotal - $themeActive ?>)</span></button>
    </div>
    <input type="search" id="themeSearch" class="input admin-resource-search" placeholder="<?= e(t('admin.search_theme_names_or_descriptions')) ?>" autocomplete="off">
  </div>

  <div class="admin-installed-theme-grid">
    <?php if ($themes === []): ?>
      <div class="admin-empty-list">
        <?= admin_icon('layout', 32) ?>
        <p><?= e(t('admin.no_themes_installed')) ?></p>
      </div>
    <?php endif; ?>
    <?php foreach ($themes as $t): ?>
      <article class="card admin-installed-theme-card" data-theme-state="<?= $t['active'] ? 'active' : 'inactive' ?>">
        <div class="admin-installed-theme-preview">
          <?php if (($t['preview'] ?? '') !== ''): ?>
            <img src="<?= e($t['preview']) ?>" alt="<?= e($t['title']) ?>" loading="lazy">
          <?php else: ?>
            <span class="admin-installed-theme-placeholder"><?= admin_icon('layout', 36) ?></span>
          <?php endif; ?>
        </div>
        <div class="admin-installed-theme-body">
          <div class="admin-installed-theme-title">
            <?php if ($t['error'] === null): ?>
              <a href="<?= e(url_to('/admin/appearance/' . rawurlencode($t['name']))) ?>"><?= e($t['title']) ?></a>
            <?php else: ?>
              <strong><?= e($t['title']) ?></strong>
            <?php endif; ?>
            <?php if ($t['active']): ?><span class="badge badge-primary"><?= e(t('admin.current')) ?></span><?php endif; ?>
          </div>
          <p class="admin-installed-theme-desc">
            <?= $t['error'] !== null ? e($t['error']) : e($t['description']) ?>
            <?php if (($t['homepage'] ?? '') !== ''): ?><a class="admin-resource-link" href="<?= e($t['homepage']) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('admin.theme_homepage')) ?></a><?php endif; ?>
          </p>
          <p class="admin-installed-theme-meta">
            <span>
              <?php if (($t['authorUrl'] ?? '') !== ''): ?><a href="<?= e($t['authorUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= e($t['author'] !== '' ? $t['author'] : t('admin.unknown_author')) ?></a><?php else: ?><?= e($t['author'] !== '' ? $t['author'] : t('admin.unknown_author')) ?><?php endif; ?>
            </span>
            <?php if ($t['version'] !== ''): ?><span>v<?= e($t['version']) ?></span><?php endif; ?>
            <?php if (($t['parent'] ?? '') !== ''): ?><span><?= e(t('admin.parent_theme')) ?>: <?= e($t['parent']) ?></span><?php endif; ?>
            <?php if (is_array($t['update'] ?? null)): ?><span class="badge badge-accent"><?= e(t('admin.update_available_v')) ?><?= e($t['update']['version']) ?></span><?php endif; ?>
          </p>
        </div>
        <div class="admin-installed-theme-actions">
          <?php if (is_array($t['update'] ?? null)): ?>
            <button type="button" class="btn btn-primary btn-sm admin-store-update" data-kind="theme" data-name="<?= e($t['name']) ?>" data-title="<?= e($t['title']) ?>" data-version="<?= e($t['update']['version']) ?>" data-local-version="<?= e($t['version']) ?>" data-changelog="<?= e($t['update']['changelog']) ?>"><?= e(t('admin.update')) ?></button>
          <?php endif; ?>
          <?php if ($t['error'] === null): ?>
            <a class="btn btn-outline btn-sm" href="<?= e(url_to('/admin/appearance/' . rawurlencode($t['name']))) ?>"><?= e(t('admin.settings')) ?></a>
            <form method="post" action="<?= e(url_to('/admin/appearance/preview')) ?>" target="_blank">
              <?= csrf_field() ?><input type="hidden" name="name" value="<?= e($t['name']) ?>">
              <button class="btn btn-outline btn-sm" type="submit"><?= e(t('admin.theme_preview')) ?></button>
            </form>
          <?php endif; ?>
          <?php if (!$t['active']): ?>
            <button type="button" class="btn btn-primary btn-sm admin-theme-activate" data-name="<?= e($t['name']) ?>" data-title="<?= e($t['title']) ?>"><?= e(t('admin.activate')) ?></button>
            <button type="button" class="btn btn-ghost btn-sm admin-theme-uninstall" data-name="<?= e($t['name']) ?>" data-title="<?= e($t['title']) ?>"<?php if (!empty($t['dependants'])): ?> disabled title="<?= e(t('admin.parent_theme_in_use')) ?>"<?php endif; ?>><?= e(t('admin.uninstall')) ?></button>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <div class="admin-modal-backdrop admin-install-modal" id="theme-install" hidden>
    <div class="admin-modal admin-install-dialog" role="dialog" aria-modal="true" aria-labelledby="theme-install-title">
      <div class="admin-modal-head admin-install-head">
        <h2 class="admin-modal-title" id="theme-install-title"><?= e(t('admin.install_theme')) ?></h2>
        <button type="button" class="admin-icon-btn" data-modal-close aria-label="<?= e(t('admin.close_installation_panel')) ?>" title="<?= e(t('admin.close_installation_panel')) ?>">×</button>
      </div>
      <div class="admin-modal-body admin-install-body">
        <input type="file" id="themeZipInput" accept=".zip" hidden>
        <label class="admin-package-picker" for="themeZipInput" id="themeZipPicker">
          <span class="admin-package-picker-icon" aria-hidden="true"><?= admin_icon('upload', 20) ?></span>
          <span class="admin-package-picker-copy">
            <strong><?= e(t('admin.choose_theme_archive')) ?></strong>
            <span id="themeZipName" aria-live="polite"><?= e(t('admin.only_zip_files_are_supported')) ?></span>
          </span>
        </label>
      </div>
      <div class="admin-modal-actions admin-install-actions">
        <button type="button" class="btn btn-ghost" data-modal-close><?= e(t('common.cancel')) ?></button>
        <button type="button" id="themeUploadBtn" class="btn btn-primary" disabled><?= e(t('admin.install_theme')) ?></button>
      </div>
    </div>
  </div>
</div>

<?php $themeCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($themeCsrf) ?>;
  try { sessionStorage.removeItem("themeMsg"); } catch (e) {}
  function showMsg(text, isError) {
    if (typeof window.pafishToast === "function") {
      window.pafishToast(text, isError ? "error" : "success");
    }
  }
  var themeRows = Array.prototype.slice.call(document.querySelectorAll("[data-theme-state]"));
  var themeSearch = document.getElementById("themeSearch");
  var themeFilter = "all";
  function filterThemes() {
    var query = themeSearch ? themeSearch.value.trim().toLowerCase() : "";
    themeRows.forEach(function (row) {
      var stateMatch = themeFilter === "all" || row.dataset.themeState === themeFilter;
      var textMatch = !query || row.textContent.toLowerCase().indexOf(query) !== -1;
      row.hidden = !(stateMatch && textMatch);
    });
  }
  document.querySelectorAll("[data-theme-filter]").forEach(function (tab) {
    tab.addEventListener("click", function () {
      themeFilter = tab.dataset.themeFilter;
      document.querySelectorAll("[data-theme-filter]").forEach(function (item) {
        var selected = item === tab;
        item.classList.toggle("is-active", selected);
        item.setAttribute("aria-selected", selected ? "true" : "false");
      });
      filterThemes();
    });
  });
  if (themeSearch) themeSearch.addEventListener("input", filterThemes);

  function post(url, fd, onOk) {
    return fetch(url, {
      method: "POST",
      body: fd,
      headers: { "X-Requested-With": "XMLHttpRequest" }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) onOk(j);
        else throw new Error((j && j.error) || window.pafishT("common.operation_failed"));
      });
  }
  function run(url, fd, okText) {
    post(url, fd, function () {
      if (typeof window.pafishToastReload === "function") window.pafishToastReload(okText, "success");
      else location.reload();
    }).catch(function (err) {
      showMsg(err.message, true);
    });
  }

  // 启用 / 卸载
  document.querySelectorAll(".admin-theme-activate").forEach(function (btn) {
    btn.addEventListener("click", function () {
      btn.disabled = true;
      btn.textContent = window.pafishT("js.processing");
      var fd = new FormData();
      fd.append("name", btn.dataset.name);
      fd.append("_csrf", CSRF);
      run("/admin/appearance/activate", fd, window.pafishT("js.theme_activated") + btn.dataset.title);
    });
  });
  document.querySelectorAll(".admin-theme-uninstall").forEach(function (btn) {
    btn.addEventListener("click", function () {
      (window.pafishConfirm ? window.pafishConfirm(window.pafishT("js.confirm_delete_theme", {name: btn.dataset.title}), { title: window.pafishT("js.uninstall_theme"), accept: window.pafishT("js.uninstall_and_delete") }) : Promise.resolve(window.confirm(window.pafishT("js.permanently_delete_this_theme_this_cannot_be_undone")))).then(function (ok) {
        if (!ok) return;
        var deleteData = window.confirm(window.pafishT("js.also_delete_this_theme_s_settings_data_and"));
        btn.disabled = true;
        btn.textContent = window.pafishT("js.processing");
        var fd = new FormData();
        fd.append("name", btn.dataset.name);
        fd.append("delete_data", deleteData ? "1" : "0");
        fd.append("_csrf", CSRF);
        run("/admin/appearance/uninstall", fd, window.pafishT("js.theme_uninstalled") + btn.dataset.title);
      });
    });
  });

  // 上传 zip
  var zipInput = document.getElementById("themeZipInput");
  var uploadBtn = document.getElementById("themeUploadBtn");
  var zipName = document.getElementById("themeZipName");
  var zipPicker = document.getElementById("themeZipPicker");
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
  uploadBtn.addEventListener("click", function () {
    if (!zipInput.files || !zipInput.files[0]) {
      showMsg(window.pafishT("js.choose_a_zip_file_first"), true);
      return;
    }
    uploadBtn.disabled = true;
    uploadBtn.textContent = window.pafishT("js.installing");
    var fd = new FormData();
    fd.append("zip", zipInput.files[0]);
    fd.append("_csrf", CSRF);
    post("/admin/appearance/install", fd, function (j) {
      var message = (j.updated ? window.pafishT("js.theme_updated") : window.pafishT("js.theme_installed")) + j.title + " v" + j.version;
      if (typeof window.pafishToastReload === "function") window.pafishToastReload(message, "success");
      else location.reload();
    }).catch(function (err) {
      showMsg(err.message, true);
      uploadBtn.disabled = false;
      uploadBtn.textContent = window.pafishT("js.install_theme");
    });
  });

})();
</script>
<script src="<?= e(asset_url('/js/admin-app-updates.js')) ?>"></script>
