<?php
/**
 * 应用商店（对齐 Node admin/store/page.tsx + store-view）：
 * 消息条 → 源标识 → 双 Tab（主题/插件）→ 卡片网格（版本/描述/作者 + 已安装徽章/更新可用/安装·更新按钮）
 * 变量：$themeCat、$pluginCat、$catError、$storeUrl
 */
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
<div class="admin-stack">
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
    <span class="admin-muted"><?= e($storeUrl) ?></span>
  </div>

  <div class="admin-tabs" role="tablist">
    <button type="button" class="admin-tab is-active" data-kind="theme" role="tab">主题（<?= count($themeCat['items']) ?>）</button>
    <button type="button" class="admin-tab" data-kind="plugin" role="tab">插件（<?= count($pluginCat['items']) ?>）</button>
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
            <div class="card admin-theme-card" data-search="<?= e(mb_strtolower(($item['title'] ?? '') . ' ' . ($item['description'] ?? ''))) ?>" data-category="<?= e($item['category'] ?? '') ?>">
              <?php if (($item['preview'] ?? '') !== ''): ?>
                <div class="admin-store-thumb">
                  <img src="<?= e($item['preview']) ?>" alt="<?= e($item['title']) ?>" loading="lazy" onerror="this.closest('.admin-store-thumb').hidden = true">
                </div>
              <?php endif; ?>
              <div class="admin-theme-head">
                <p class="admin-theme-name"><?= e($item['title']) ?>
                  <?php if (($item['category'] ?? '') !== ''): ?><span class="badge"><?= e($item['category']) ?></span><?php endif; ?>
                  <?php if (!empty($item['paid'])): ?><span class="badge badge-accent">付费</span><?php endif; ?>
                  <?php if ($item['installed']): ?>
                    <?php if ($item['updateAvailable']): ?>
                      <span class="badge badge-accent">可更新</span>
                    <?php else: ?>
                      <span class="badge badge-primary">已安装</span>
                    <?php endif; ?>
                  <?php endif; ?>
                  <span class="admin-theme-version">v<?= e($item['version']) ?></span>
                </p>
                <p class="admin-theme-desc"><?= e($item['description'] !== '' ? $item['description'] : '（该条目未提供描述）') ?></p>
                <p class="admin-muted admin-theme-meta">
                  作者：<?= e($item['author'] !== '' ? $item['author'] : '未知') ?>
                  <?php if ($item['installed']): ?>
                    　本地版本：v<?= e($item['localVersion']) ?><?= $item['updateAvailable'] ? '' : '（最新）' ?>
                  <?php endif; ?>
                </p>
              </div>
              <div class="admin-theme-ops">
                <?php if ($item['installed']): ?>
                  <?php if ($item['updateAvailable']): ?>
                    <button type="button" class="btn btn-primary btn-sm admin-store-update" data-kind="<?= e($kind) ?>" data-name="<?= e($item['name']) ?>" data-title="<?= e($item['title']) ?>" data-version="<?= e($item['version']) ?>" data-changelog="<?= e($item['changelog'] ?? '') ?>">更新到 v<?= e($item['version']) ?></button>
                  <?php else: ?>
                    <span class="badge">已是最新版本</span>
                  <?php endif; ?>
                <?php else: ?>
                  <button type="button" class="btn btn-primary btn-sm admin-store-install" data-kind="<?= e($kind) ?>" data-name="<?= e($item['name']) ?>" data-title="<?= e($item['title']) ?>" data-version="<?= e($item['version']) ?>" data-paid="<?= !empty($item['paid']) ? '1' : '0' ?>">安装</button>
                  <?php if (!empty($item['paid'])): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(rtrim($storeUrl, '/') . '/store/' . ($kind === 'theme' ? 'themes' : 'extensions') . '/' . rawurlencode($item['name'])) ?>" target="_blank" rel="noopener">官网购买</a>
                  <?php endif; ?>
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
(function () {
  "use strict";
  var CSRF = <?= json_encode($storeCsrf) ?>;
  var msg = document.getElementById("storeMsg");

  function showMsg(text, isError) {
    if (typeof window.pafishToast === "function") {
      window.pafishToast(text, isError ? "error" : "success");
      return;
    }
    msg.textContent = text;
    msg.className = "admin-backup-msg " + (isError ? "admin-backup-msg-error" : "admin-backup-msg-ok");
    msg.hidden = false;
  }
  function persistMsg(text, isError) {
    try { sessionStorage.setItem("storeMsg", JSON.stringify({ t: text, e: isError ? 1 : 0 })); } catch (e) {}
  }
  try {
    var saved = sessionStorage.getItem("storeMsg");
    if (saved) {
      sessionStorage.removeItem("storeMsg");
      var m = JSON.parse(saved);
      showMsg(m.t, !!m.e);
    }
  } catch (e) {}

  function post(url, fd) {
    return fetch(url, {
      method: "POST",
      body: fd,
      headers: { "X-Requested-With": "XMLHttpRequest" }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) return j;
        throw new Error((j && j.error) || "操作失败");
      });
  }

  // ---- Tab 切换 ----
  var tabs = document.querySelectorAll(".admin-tab");
  var panes = document.querySelectorAll(".admin-store-pane");
  tabs.forEach(function (tab) {
    tab.addEventListener("click", function () {
      tabs.forEach(function (t) { t.classList.remove("is-active"); });
      panes.forEach(function (p) { p.hidden = true; });
      tab.classList.add("is-active");
      var pane = document.querySelector('.admin-store-pane[data-pane="' + tab.dataset.kind + '"]');
      if (pane) { pane.hidden = false; }
    });
  });

  // ---- 搜索 + 分类筛选（当前 Tab 内按标题/描述过滤，分类与关键词叠加） ----
  var search = document.getElementById("storeSearch");
  var catSelect = document.getElementById("storeCategory");
  function applyStoreFilters() {
    var q = search.value.trim().toLowerCase();
    var cat = catSelect ? catSelect.value : "";
    document.querySelectorAll(".admin-store-pane:not([hidden]) .admin-theme-card").forEach(function (card) {
      var matchQ = q === "" || (card.dataset.search || "").indexOf(q) !== -1;
      var matchCat = cat === "" || card.dataset.category === cat;
      card.style.display = matchQ && matchCat ? "" : "none";
    });
  }
  search.addEventListener("input", applyStoreFilters);
  if (catSelect) { catSelect.addEventListener("change", applyStoreFilters); }

  // ---- 安装 / 更新 ----
  document.querySelectorAll(".admin-store-install").forEach(function (btn) {
    btn.addEventListener("click", function () {
      if (btn.dataset.paid === "1" && !window.confirm("该应用为付费应用，需先在官网购买获取授权码，否则下载将失败。\n确定继续安装吗？")) { return; }
      var fd = new FormData();
      fd.append("kind", btn.dataset.kind);
      fd.append("name", btn.dataset.name);
      fd.append("_csrf", CSRF);
      post("/admin/store/install", fd)
        .then(function (j) {
          var guide = btn.dataset.kind === "plugin"
            ? "，可到「插件管理」中启用"
            : "，可到「主题与外观」中启用";
          persistMsg("✓ 已安装 " + j.title + " v" + j.version + guide, false);
          location.reload();
        })
        .catch(function (err) { showMsg(err.message, true); });
    });
  });
  document.querySelectorAll(".admin-store-update").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var text = "将 " + btn.dataset.title + " 从 v" + btn.dataset.version + " 覆盖更新？\n更新失败会自动恢复旧版本。";
      var log = btn.dataset.changelog || "";
      if (log) { text += "\n\n更新内容：\n" + log; }
      if (!window.confirm(text)) { return; }
      var fd = new FormData();
      fd.append("kind", btn.dataset.kind);
      fd.append("name", btn.dataset.name);
      fd.append("_csrf", CSRF);
      post("/admin/store/update", fd)
        .then(function (j) { persistMsg("✓ 已更新 " + j.title + " 到 v" + j.version, false); location.reload(); })
        .catch(function (err) { showMsg(err.message, true); });
    });
  });
})();
</script>
