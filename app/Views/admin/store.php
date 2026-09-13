<?php
/** 应用商店目录与安装更新。 */
function store_kind_label(string $kind): string
{
    return $kind === 'theme' ? '主题' : '插件';
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
      <h1 class="admin-h1">应用商店</h1>
    </div>
  </div>
  <p class="admin-backup-msg" id="storeMsg" hidden></p>
  <?php if ($catError !== ''): ?>
    <p class="admin-backup-msg admin-backup-msg-error" id="storeCatError"><?= e($catError) ?></p>
  <?php endif; ?>

  <div class="admin-store-source">
    <span class="badge badge-primary">内置官方商店</span>
    <?php if ($storeAccount): ?>
      <span class="badge badge-primary">已绑定 <?= e((string)($storeAccount['account']['email'] ?? $storeAccount['account']['name'] ?? '商城账号')) ?></span>
    <?php elseif (($storeAccountStatus ?? 'unbound') === 'invalid_token'): ?>
      <span class="badge badge-accent">商城令牌已失效</span>
      <span class="admin-muted">请到站点设置重新生成令牌</span>
    <?php elseif (($storeAccountStatus ?? 'unbound') === 'unreachable'): ?>
      <span class="badge">官方商城暂时不可达</span>
      <span class="admin-muted">免费应用仍可使用缓存目录，付费下载需稍后重试</span>
    <?php else: ?>
      <span class="admin-muted">未绑定商城账号，付费应用请先在站点设置中绑定</span>
    <?php endif; ?>
    <button type="button" class="btn btn-ghost btn-sm" id="storeRefresh">刷新目录</button>
  </div>

  <div class="admin-tabs" role="tablist">
    <button type="button" class="admin-tab is-active" data-kind="theme" role="tab">主题（<?= count($themeCat['items']) ?>）</button>
    <button type="button" class="admin-tab" data-kind="plugin" role="tab">插件（<?= count($pluginCat['items']) ?>）</button>
  </div>

  <div class="admin-store-view-tabs" role="tablist" aria-label="应用状态">
    <button type="button" class="admin-store-view-tab is-active" data-store-state="all" role="tab" aria-selected="true">全部</button>
    <button type="button" class="admin-store-view-tab" data-store-state="installed" role="tab" aria-selected="false">已安装</button>
    <button type="button" class="admin-store-view-tab" data-store-state="upgradeable" role="tab" aria-selected="false">可更新</button>
  </div>

  <div class="admin-store-toolbar">
    <input type="search" id="storeSearch" class="input admin-store-search" placeholder="搜索已上架的主题与插件…" autocomplete="off">
    <?php if ($allCategories): ?>
      <select id="storeCategory" class="input admin-store-cat" title="按分类筛选">
        <option value="">全部分类</option>
        <?php foreach ($allCategories as $storeCat): ?>
          <option value="<?= e($storeCat) ?>"><?= e($storeCat) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
  </div>

  <?php foreach (['theme' => $themeCat, 'plugin' => $pluginCat] as $kind => $cat): ?>
    <div class="admin-store-pane" data-pane="<?= e($kind) ?>"<?= $kind === 'theme' ? '' : ' hidden' ?>>
      <?php if ($cat['items'] === []): ?>
        <div class="card admin-empty">该分类暂无可用条目（官方商店可能尚未提供<?= store_kind_label($kind) ?>，远程不可用时自动回退内置商店）。</div>
      <?php else: ?>
        <div class="admin-theme-grid">
          <?php foreach ($cat['items'] as $item): ?>
            <?php $detailUrl = rtrim($storeUrl, '/') . '/store/' . ($kind === 'theme' ? 'themes' : 'extensions') . '/' . rawurlencode((string)$item['name']); ?>
            <div class="card admin-theme-card" data-search="<?= e(mb_strtolower(($item['title'] ?? '') . ' ' . ($item['description'] ?? ''))) ?>" data-category="<?= e($item['category'] ?? '') ?>" data-installed="<?= $item['installed'] ? '1' : '0' ?>" data-upgradeable="<?= $item['updateAvailable'] ? '1' : '0' ?>">
              <div class="admin-store-card-main">
                <?php if (($item['preview'] ?? '') !== ''): ?>
                  <a class="admin-store-detail-link" href="<?= e($detailUrl) ?>" target="_blank" rel="noopener" title="查看应用详情"><div class="admin-store-thumb">
                    <img src="<?= e($item['preview']) ?>" alt="<?= e($item['title']) ?>" loading="lazy" onerror="this.closest('.admin-store-thumb').classList.add('is-empty'); this.remove()">
                  </div></a>
                <?php else: ?>
                  <a class="admin-store-detail-link" href="<?= e($detailUrl) ?>" target="_blank" rel="noopener" title="查看应用详情"><div class="admin-store-thumb is-empty" aria-hidden="true"></div></a>
                <?php endif; ?>
                <div class="admin-theme-head">
                  <p class="admin-theme-name">
                    <a class="admin-store-detail-link" href="<?= e($detailUrl) ?>" target="_blank" rel="noopener"><span class="admin-theme-title-text"><?= e($item['title']) ?></span></a>
                    <?php if (!empty($item['paid'])): ?><span class="badge badge-accent">付费</span><?php else: ?><span class="badge badge-primary">免费</span><?php endif; ?>
                    <?php if (!empty($item['paid']) && !empty($item['purchased'])): ?><span class="badge badge-primary">已购买</span><?php endif; ?>
                    <?php if ($item['installed']): ?>
                      <?php if ($item['updateAvailable']): ?><span class="badge badge-accent">可更新</span>
                      <?php else: ?><span class="badge badge-primary">已安装</span><?php endif; ?>
                    <?php endif; ?>
                  </p>
                  <p class="admin-theme-desc"><?= e($item['description'] !== '' ? $item['description'] : '暂无描述') ?></p>
                  <p class="admin-theme-meta">
                    <span>作者：<?= e($item['author'] !== '' ? $item['author'] : '未知') ?></span>
                    <span>最新 v<?= e($item['version']) ?></span>
                  </p>
                </div>
              </div>
              <div class="admin-store-card-info">
                <?php if (($item['category'] ?? '') !== ''): ?><span><?= e($item['category']) ?></span><?php endif; ?>
                <span><?= e($item['name']) ?></span>
                <?php if ($item['installed']): ?><span>本地 v<?= e($item['localVersion']) ?></span><?php endif; ?>
              </div>
              <div class="admin-theme-ops">
                <?php if ($item['installed']): ?>
                  <?php if ($item['updateAvailable']): ?>
                    <button type="button" class="btn btn-primary btn-sm admin-store-update" data-kind="<?= e($kind) ?>" data-name="<?= e($item['name']) ?>" data-title="<?= e($item['title']) ?>" data-version="<?= e($item['version']) ?>" data-local-version="<?= e($item['localVersion']) ?>" data-changelog="<?= e($item['changelog'] ?? '') ?>">更新到 v<?= e($item['version']) ?></button>
                  <?php else: ?>
                    <span class="badge">已是最新版本</span>
                  <?php endif; ?>
                <?php elseif (!empty($item['paid']) && empty($item['purchased'])): ?>
                  <span class="badge badge-accent">需先购买</span>
                  <a class="btn btn-ghost btn-sm" href="<?= e(rtrim($storeUrl, '/') . '/store/' . ($kind === 'theme' ? 'themes' : 'extensions') . '/' . rawurlencode($item['name'])) ?>" target="_blank" rel="noopener">前往官网购买</a>
                <?php else: ?>
                  <button type="button" class="btn btn-primary btn-sm admin-store-install" data-kind="<?= e($kind) ?>" data-name="<?= e($item['name']) ?>" data-title="<?= e($item['title']) ?>" data-version="<?= e($item['version']) ?>" data-paid="<?= !empty($item['paid']) ? '1' : '0' ?>" data-purchased="<?= !empty($item['purchased']) ? '1' : '0' ?>">安装</button>
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
        throw new Error((j && j.error) || "操作失败");
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
      setButtonLoading(refreshBtn, true, "正在刷新");
      var fd = new FormData();
      fd.append("_csrf", CSRF);
      post("/admin/store/refresh", fd)
        .then(function() {
          persistMsg("✓ 已刷新官方应用目录", false);
          location.reload();
        })
        .catch(function(err) {
          showToast(err.message, "error");
          setButtonLoading(refreshBtn, false);
        });
    });
  }

  // ========== Tab 切换 ==========
  var tabs = document.querySelectorAll(".admin-tab");
  var panes = document.querySelectorAll(".admin-store-pane");

  tabs.forEach(function(tab) {
    tab.addEventListener("click", function() {
      tabs.forEach(function(t) { t.classList.remove("is-active"); });
      panes.forEach(function(p) { p.hidden = true; });
      tab.classList.add("is-active");
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
      emptyHint.textContent = "没有找到匹配的应用";
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
        "当前账号尚未显示购买记录，安装可能会被官方商城拒绝。" :
        "应用将下载并写入站点的主题或插件目录。";
      var detail = needsPurchase ?
        "如已完成购买，请先确认后台绑定的是对应的官方账号。" :
        "安装完成后可在主题或插件管理中启用。";

      var confirmPromise = window.pafishConfirm ?
        window.pafishConfirm(ask, {
          title: "安装应用 · " + btn.dataset.title,
          accept: "确认安装",
          danger: false,
          detail: detail
        }) :
        Promise.resolve(window.confirm(ask));

      confirmPromise.then(function(ok) {
        if (!ok) return;

        setButtonLoading(btn, true, "正在安装");
        var fd = new FormData();
        fd.append("kind", btn.dataset.kind);
        fd.append("name", btn.dataset.name);
        fd.append("_csrf", CSRF);

        return post("/admin/store/install", fd)
          .then(function(j) {
            var guide = btn.dataset.kind === "plugin" ?
              "，可到「插件管理」中启用" :
              "，可到「主题与外观」中启用";
            persistMsg("✓ 已安装 " + j.title + " v" + j.version + guide, false);
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
      var text = "将已安装的 " + btn.dataset.title + " 从 v" + (btn.dataset.localVersion || "当前版本") + " 更新到 v" + btn.dataset.version + "？";
      var log = btn.dataset.changelog || "";
      var detail = "更新失败会自动恢复旧版本。" + (log ? "\n\n更新内容：\n" + log : "");

      var confirmPromise = window.pafishConfirm ?
        window.pafishConfirm(text, {
          title: "更新应用",
          accept: "确认更新",
          danger: false,
          detail: detail
        }) :
        Promise.resolve(window.confirm(text + "\n" + detail));

      confirmPromise.then(function(ok) {
        if (!ok) return;

        setButtonLoading(btn, true, "正在更新");
        var fd = new FormData();
        fd.append("kind", btn.dataset.kind);
        fd.append("name", btn.dataset.name);
        fd.append("_csrf", CSRF);

        return post("/admin/store/update", fd)
          .then(function(j) {
            persistMsg("✓ 已更新 " + j.title + " 到 v" + j.version, false);
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
