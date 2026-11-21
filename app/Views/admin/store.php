<?php
/** 应用商店目录与安装更新。 */
function store_kind_label(string $kind): string
{
    return $kind === 'theme' ? t('admin.theme_e848dd') : t('admin.plugin_76fcd7');
}
$allCategories = [];
foreach (['theme' => $themeCat, 'plugin' => $pluginCat] as $storeKindCat) {
    foreach (($storeKindCat['items'] ?? []) as $storeItem) {
        $storeCat = (string)($storeItem['category'] ?? '');
        if ($storeCat !== '' && !in_array($storeCat, $allCategories, true)) {
            $allCategories[] = $storeCat;
        }
    }
}
sort($allCategories);
?>
<div class="admin-stack admin-store-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e(t('admin.app_store')) ?></h1>
    </div>
  </div>
  <p class="admin-backup-msg" id="storeMsg" hidden></p>
  <?php if (($catalogNotice ?? '') !== ''): ?>
    <p class="admin-backup-msg admin-backup-msg-error" id="storeCatError"><?= e($catalogNotice) ?></p>
  <?php endif; ?>

  <div class="admin-store-source">
    <span class="badge badge-primary"><?= e(t('admin.built_in_official_store')) ?></span>
    <?php if ($storeAccount): ?>
      <span class="badge badge-primary"><?= e(t('admin.linked')) ?> <?= e((string)($storeAccount['account']['email'] ?? $storeAccount['account']['name'] ?? t('admin.store_account'))) ?></span>
    <?php elseif (($storeAccountStatus ?? 'unbound') === 'invalid_token'): ?>
      <span class="badge badge-accent"><?= e(t('admin.the_store_token_has_expired')) ?></span>
      <span class="admin-muted"><?= e(t('admin.generate_a_new_token_in_site_settings')) ?></span>
    <?php elseif (($storeAccountStatus ?? 'unbound') === 'unreachable'): ?>
      <span class="badge"><?= e(t('admin.the_official_store_is_temporarily_unreachable')) ?></span>
      <span class="admin-muted"><?= e(t('admin.free_apps_can_still_use_the_cached_catalogue')) ?></span>
    <?php else: ?>
      <span class="admin-muted"><?= e(t('admin.no_store_account_linked_link_an_account_in')) ?></span>
    <?php endif; ?>
    <button type="button" class="btn btn-ghost btn-sm" id="storeRefresh"><?= e(t('admin.refresh_catalogue')) ?></button>
  </div>

  <div class="admin-tabs admin-store-kind-tabs" role="tablist" aria-label="<?= e(t('admin.app_type')) ?>">
    <button type="button" class="admin-tab is-active" data-kind="theme" role="tab" aria-selected="true"><?= e(t('admin.theme_e848dd')) ?> <span class="admin-tab-count">(<?= count($themeCat['items']) ?>)</span></button>
    <button type="button" class="admin-tab" data-kind="plugin" role="tab" aria-selected="false"><?= e(t('admin.plugin_76fcd7')) ?> <span class="admin-tab-count">(<?= count($pluginCat['items']) ?>)</span></button>
  </div>

  <div class="admin-store-filter-row">
    <div class="admin-tabs admin-status-tabs admin-store-view-tabs" role="tablist" aria-label="<?= e(t('admin.app_status')) ?>">
      <button type="button" class="admin-tab is-active" data-store-state="all" role="tab" aria-selected="true"><?= e(t('common.all')) ?></button>
      <button type="button" class="admin-tab" data-store-state="installed" role="tab" aria-selected="false"><?= e(t('admin.installed')) ?></button>
      <button type="button" class="admin-tab" data-store-state="upgradeable" role="tab" aria-selected="false"><?= e(t('admin.update_available')) ?></button>
    </div>
    <div class="admin-store-toolbar<?= $allCategories ? '' : ' is-single' ?>">
      <input type="search" id="storeSearch" class="input admin-store-search" placeholder="<?= e(t('admin.search_available_themes_and_plugins')) ?>" autocomplete="off">
      <?php if ($allCategories): ?>
        <select id="storeCategory" class="input admin-store-cat" title="<?= e(t('admin.filter_by_category')) ?>">
          <option value=""><?= e(t('admin.all_categories')) ?></option>
          <?php foreach ($allCategories as $storeCat): ?>
            <option value="<?= e($storeCat) ?>"><?= e($storeCat) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
    </div>
  </div>

  <?php foreach (['theme' => $themeCat, 'plugin' => $pluginCat] as $kind => $cat): ?>
    <div class="admin-store-pane" data-pane="<?= e($kind) ?>"<?= $kind === 'theme' ? '' : ' hidden' ?>>
      <?php if ($cat['items'] === []): ?>
        <div class="card admin-empty"><?= e(t('admin.no_items_are_available_in_this_category_the')) ?><?= store_kind_label($kind) ?><?= e(t('admin.the_built_in_store_is_used_if_the')) ?></div>
      <?php else: ?>
        <div class="admin-theme-grid">
          <?php foreach ($cat['items'] as $item): ?>
            <?php $detailUrl = rtrim($storeUrl, '/') . '/store/' . ($kind === 'theme' ? 'themes' : 'extensions') . '/' . rawurlencode((string)$item['name']); ?>
            <div class="card admin-theme-card" data-search="<?= e(mb_strtolower(($item['title'] ?? '') . ' ' . ($item['description'] ?? ''))) ?>" data-category="<?= e($item['category'] ?? '') ?>" data-installed="<?= $item['installed'] ? '1' : '0' ?>" data-upgradeable="<?= $item['updateAvailable'] ? '1' : '0' ?>">
              <div class="admin-store-card-main">
                <?php if (($item['preview'] ?? '') !== ''): ?>
                  <a class="admin-store-detail-link" href="<?= e($detailUrl) ?>" target="_blank" rel="noopener" title="<?= e(t('admin.view_app_details')) ?>"><div class="admin-store-thumb">
                    <img src="<?= e($item['preview']) ?>" alt="<?= e($item['title']) ?>" loading="lazy" onerror="this.closest('.admin-store-thumb').classList.add('is-empty'); this.remove()">
                  </div></a>
                <?php else: ?>
                  <a class="admin-store-detail-link" href="<?= e($detailUrl) ?>" target="_blank" rel="noopener" title="<?= e(t('admin.view_app_details')) ?>"><div class="admin-store-thumb is-empty" aria-hidden="true"></div></a>
                <?php endif; ?>
                <div class="admin-theme-head">
                  <p class="admin-theme-name">
                    <a class="admin-store-detail-link" href="<?= e($detailUrl) ?>" target="_blank" rel="noopener"><span class="admin-theme-title-text"><?= e($item['title']) ?></span></a>
                    <?php if (!empty($item['paid'])): ?><span class="badge badge-accent"><?= e(t('admin.paid')) ?></span><?php else: ?><span class="badge badge-primary"><?= e(t('admin.free')) ?></span><?php endif; ?>
                    <?php if (!empty($item['paid']) && !empty($item['purchased'])): ?><span class="badge badge-primary"><?= e(t('admin.purchased')) ?></span><?php endif; ?>
                    <?php if ($item['installed']): ?>
                      <?php if ($item['updateAvailable']): ?><span class="badge badge-accent"><?= e(t('admin.update_available')) ?></span>
                      <?php else: ?><span class="badge badge-primary"><?= e(t('admin.installed')) ?></span><?php endif; ?>
                    <?php endif; ?>
                  </p>
                  <p class="admin-theme-desc"><?= e($item['description'] !== '' ? $item['description'] : t('admin.no_description')) ?></p>
                  <p class="admin-theme-meta">
                    <span><?= e(t('admin.author_40deed')) ?><?= e($item['author'] !== '' ? $item['author'] : t('admin.unknown')) ?></span>
                    <span><?= e(t('admin.latest_v')) ?><?= e($item['version']) ?></span>
                  </p>
                </div>
              </div>
              <div class="admin-store-card-info">
                <?php if (($item['category'] ?? '') !== ''): ?><span><?= e($item['category']) ?></span><?php endif; ?>
                <span><?= e($item['name']) ?></span>
                <?php if ($item['installed']): ?><span><?= e(t('admin.installed_v')) ?><?= e($item['localVersion']) ?></span><?php endif; ?>
              </div>
              <div class="admin-theme-ops">
                <?php if (empty($cat['installable'])): ?>
                  <span class="badge"><?= e(t('admin.catalogue_unavailable')) ?></span>
                <?php elseif ($item['installed']): ?>
                  <?php if ($item['updateAvailable']): ?>
                    <button type="button" class="btn btn-primary btn-sm admin-store-update" data-kind="<?= e($kind) ?>" data-name="<?= e($item['name']) ?>" data-title="<?= e($item['title']) ?>" data-version="<?= e($item['version']) ?>" data-local-version="<?= e($item['localVersion']) ?>" data-changelog="<?= e($item['changelog'] ?? '') ?>"><?= e(t('admin.update_to_v')) ?><?= e($item['version']) ?></button>
                  <?php else: ?>
                    <span class="badge"><?= e(t('admin.already_up_to_date')) ?></span>
                  <?php endif; ?>
                <?php elseif (!empty($item['paid']) && empty($item['purchased'])): ?>
                  <span class="badge badge-accent"><?= e(t('admin.purchase_required')) ?></span>
                  <a class="btn btn-ghost btn-sm" href="<?= e(rtrim($storeUrl, '/') . '/store/' . ($kind === 'theme' ? 'themes' : 'extensions') . '/' . rawurlencode($item['name'])) ?>" target="_blank" rel="noopener"><?= e(t('admin.buy_on_official_website')) ?></a>
                <?php else: ?>
                  <button type="button" class="btn btn-primary btn-sm admin-store-install" data-kind="<?= e($kind) ?>" data-name="<?= e($item['name']) ?>" data-title="<?= e($item['title']) ?>" data-version="<?= e($item['version']) ?>" data-paid="<?= !empty($item['paid']) ? '1' : '0' ?>" data-purchased="<?= !empty($item['purchased']) ? '1' : '0' ?>"><?= e(t('admin.install')) ?></button>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php $storeCsrf = csrf_token(); ?>
<script>
(function() {
  "use strict";

  var CSRF = <?= json_encode($storeCsrf) ?>;
  var msg = document.getElementById("storeMsg");

  // ========== Toast 通知 ==========
  function showToast(text, type) {
    if (typeof window.pafishToast === "function") {
      window.pafishToast(text, type || "success");
      return;
    }
    if (msg) {
      msg.textContent = text;
      msg.className = "admin-backup-msg " + (type === "error" ? "admin-backup-msg-error" : "admin-backup-msg-ok");
      msg.hidden = false;
      setTimeout(function() { msg.hidden = true; }, 5000);
    }
  }

  // ========== 持久化消息 ==========
  function persistMsg(text, isError) {
    try {
      sessionStorage.setItem("storeMsg", JSON.stringify({ t: text, e: isError ? 1 : 0 }));
    } catch (e) {}
  }

  try {
    var saved = sessionStorage.getItem("storeMsg");
    if (saved) {
      sessionStorage.removeItem("storeMsg");
      var m = JSON.parse(saved);
      showToast(m.t, m.e ? "error" : "success");
    }
  } catch (e) {}

  // ========== HTTP 工具 ==========
  function post(url, fd) {
    return fetch(url, {
      method: "POST",
      body: fd,
      headers: { "X-Requested-With": "XMLHttpRequest" }
    })
      .then(function(r) { return r.json(); })
      .then(function(j) {
        if (j && j.ok) return j;
        throw new Error((j && j.error) || window.pafishT("common.operation_failed"));
      });
  }

  // ========== 按钮加载状态 ==========
  function setButtonLoading(btn, loading, text) {
    if (loading) {
      btn.disabled = true;
      btn.dataset.originalText = btn.textContent;
      btn.classList.add("is-loading");
      if (text) btn.textContent = text;
    } else {
      btn.disabled = false;
      btn.classList.remove("is-loading");
      btn.textContent = btn.dataset.originalText || btn.textContent;
    }
  }

  // ========== 刷新目录 ==========
  var refreshBtn = document.getElementById("storeRefresh");
  if (refreshBtn) {
    refreshBtn.addEventListener("click", function() {
      setButtonLoading(refreshBtn, true, window.pafishT("js.refreshing"));
      var fd = new FormData();
      fd.append("_csrf", CSRF);
      post("/admin/store/refresh", fd)
        .then(function(j) {
          // 失败原因已由服务端记入缓存并渲染到页面提示行，这里不再弹成功提示。
          if (!j.error) {
            persistMsg(window.pafishT("js.official_app_catalogue_refreshed"), false);
          }
          location.reload();
        })
        .catch(function(err) {
          showToast(err.message, "error");
          setButtonLoading(refreshBtn, false);
        });
    });
  }

  // ========== Tab 切换 ==========
  var tabs = document.querySelectorAll(".admin-store-kind-tabs .admin-tab");
  var panes = document.querySelectorAll(".admin-store-pane");

  tabs.forEach(function(tab) {
    tab.addEventListener("click", function() {
      tabs.forEach(function(t) { t.classList.remove("is-active"); });
      tabs.forEach(function(t) { t.setAttribute("aria-selected", "false"); });
      panes.forEach(function(p) { p.hidden = true; });
      tab.classList.add("is-active");
      tab.setAttribute("aria-selected", "true");
      var pane = document.querySelector('.admin-store-pane[data-pane="' + tab.dataset.kind + '"]');
      if (pane) pane.hidden = false;
      applyStoreFilters();
    });
  });

  // ========== 搜索 + 分类筛选 ==========
  var search = document.getElementById("storeSearch");
  var catSelect = document.getElementById("storeCategory");
  var storeState = "all";
  var searchTimer = null;

  function applyStoreFilters() {
    var q = search ? search.value.trim().toLowerCase() : "";
    var cat = catSelect ? catSelect.value : "";

    var activePane = document.querySelector(".admin-store-pane:not([hidden])");
    if (!activePane) return;

    var cards = activePane.querySelectorAll(".admin-theme-card");
    var visibleCount = 0;

    cards.forEach(function(card) {
      var matchQ = q === "" || (card.dataset.search || "").indexOf(q) !== -1;
      var matchCat = cat === "" || card.dataset.category === cat;
      var matchState = storeState === "all" ||
        (storeState === "installed" && card.dataset.installed === "1") ||
        (storeState === "upgradeable" && card.dataset.upgradeable === "1");
      var isVisible = matchQ && matchCat && matchState;

      card.style.display = isVisible ? "" : "none";
      if (isVisible) {
        visibleCount++;
        if (q !== "") {
          card.dataset.searchMatched = "true";
          setTimeout(function() { delete card.dataset.searchMatched; }, 300);
        }
      }
    });

    var emptyHint = activePane.querySelector(".admin-store-empty");
    if (visibleCount === 0 && !emptyHint) {
      emptyHint = document.createElement("div");
      emptyHint.className = "card admin-empty admin-store-empty";
      emptyHint.textContent = window.pafishT("js.no_matching_apps");
      var grid = activePane.querySelector(".admin-theme-grid");
      if (grid) grid.after(emptyHint);
    } else if (visibleCount > 0 && emptyHint) {
      emptyHint.remove();
    }
  }

  if (search) {
    search.addEventListener("input", function() {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(applyStoreFilters, 300);
    });
  }

  if (catSelect) {
    catSelect.addEventListener("change", applyStoreFilters);
  }

  document.querySelectorAll("[data-store-state]").forEach(function(tab) {
    tab.addEventListener("click", function() {
      storeState = tab.dataset.storeState || "all";
      document.querySelectorAll("[data-store-state]").forEach(function(item) {
        var active = item === tab;
        item.classList.toggle("is-active", active);
        item.setAttribute("aria-selected", active ? "true" : "false");
      });
      applyStoreFilters();
    });
  });

  // ========== 安装处理 ==========
  function bindInstallHandler(btn) {
    btn.addEventListener("click", function() {
      var needsPurchase = btn.dataset.paid === "1" && btn.dataset.purchased !== "1";
      var ask = needsPurchase ?
        window.pafishT("js.no_purchase_was_found_for_the_current_account") :
        window.pafishT("js.the_app_will_be_downloaded_to_the_site");
      var detail = needsPurchase ?
        window.pafishT("js.if_you_have_purchased_this_app_confirm_that") :
        window.pafishT("js.activate_it_in_theme_or_plugin_management_after");

      var confirmPromise = window.pafishConfirm ?
        window.pafishConfirm(ask, {
          title: window.pafishT("js.install_named_app", {name: btn.dataset.title}),
          accept: window.pafishT("js.confirm_installation"),
          danger: false,
          detail: detail
        }) :
        Promise.resolve(window.confirm(ask));

      confirmPromise.then(function(ok) {
        if (!ok) return;

        setButtonLoading(btn, true, window.pafishT("js.installing_12b5f3"));
        var fd = new FormData();
        fd.append("kind", btn.dataset.kind);
        fd.append("name", btn.dataset.name);
        fd.append("_csrf", CSRF);

        return post("/admin/store/install", fd)
          .then(function(j) {
            var guide = btn.dataset.kind === "plugin" ?
              window.pafishT("js.activate_it_in_plugins") :
              window.pafishT("js.activate_it_in_themes_and_appearance");
            persistMsg(window.pafishT("js.installed_67e676") + j.title + " v" + j.version + guide, false);
            location.reload();
          })
          .catch(function(err) {
            showToast(err.message, "error");
            setButtonLoading(btn, false);
          });
      });
    });
  }

  document.querySelectorAll(".admin-store-install").forEach(bindInstallHandler);

  // ========== 更新处理 ==========
  function bindUpdateHandler(btn) {
    btn.addEventListener("click", function() {
      var text = window.pafishT("js.confirm_update_app", {name: btn.dataset.title, from: btn.dataset.localVersion || window.pafishT("js.current_version"), to: btn.dataset.version});
      var log = btn.dataset.changelog || "";
      var detail = window.pafishT("js.the_previous_version_is_restored_automatically_if_an") + (log ? window.pafishT("js.release_notes") + log : "");

      var confirmPromise = window.pafishConfirm ?
        window.pafishConfirm(text, {
          title: window.pafishT("js.update_app"),
          accept: window.pafishT("js.confirm_update"),
          danger: false,
          detail: detail
        }) :
        Promise.resolve(window.confirm(text + "\n" + detail));

      confirmPromise.then(function(ok) {
        if (!ok) return;

        setButtonLoading(btn, true, window.pafishT("js.updating_87c1bc"));
        var fd = new FormData();
        fd.append("kind", btn.dataset.kind);
        fd.append("name", btn.dataset.name);
        fd.append("_csrf", CSRF);

        return post("/admin/store/update", fd)
          .then(function(j) {
            persistMsg(window.pafishT("js.updated") + j.title + window.pafishT("js.to_v") + j.version, false);
            location.reload();
          })
          .catch(function(err) {
            showToast(err.message, "error");
            setButtonLoading(btn, false);
          });
      });
    });
  }

  document.querySelectorAll(".admin-store-update").forEach(bindUpdateHandler);

})();
</script>
