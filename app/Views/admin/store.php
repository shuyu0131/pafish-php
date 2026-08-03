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
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">应用商店</h1>
      <p class="admin-page-sub">从商店一键安装主题与插件。安装包校验唯一顶层目录与路径安全，更新失败会自动恢复旧版本。商店源可在「站点设置 → 商店」中配置。</p>
    </div>
  </div>
  <p class="admin-backup-msg" id="storeMsg" hidden></p>
  <?php if ($catError !== ''): ?>
    <p class="admin-backup-msg admin-backup-msg-error" id="storeCatError"><?= e($catError) ?></p>
  <?php endif; ?>

  <div class="admin-store-source">
    <?php if ($storeUrl !== ''): ?>
      <span class="badge badge-primary">远程商店</span>
      <span class="admin-muted"><?= e($storeUrl) ?></span>
    <?php else: ?>
      <span class="badge">未配置</span>
      <span class="admin-muted">未配置远程商店地址，请在「站点设置 → 应用商店」中填写后使用。</span>
    <?php endif; ?>
  </div>

  <div class="admin-tabs" role="tablist">
    <button type="button" class="admin-tab is-active" data-kind="theme" role="tab">主题（<?= count($themeCat['items']) ?>）</button>
    <button type="button" class="admin-tab" data-kind="plugin" role="tab">插件（<?= count($pluginCat['items']) ?>）</button>
  </div>

  <?php foreach (['theme' => $themeCat, 'plugin' => $pluginCat] as $kind => $cat): ?>
    <div class="admin-store-pane" data-pane="<?= e($kind) ?>"<?= $kind === 'theme' ? '' : ' hidden' ?>>
      <?php if ($cat['items'] === []): ?>
        <div class="card admin-empty">该分类暂无可用条目<?= $storeUrl !== '' ? '（远程商店可能尚未提供' . store_kind_label($kind) . '目录）' : '，请先配置远程商店地址' ?>。</div>
      <?php else: ?>
        <div class="admin-theme-grid">
          <?php foreach ($cat['items'] as $item): ?>
            <div class="card admin-theme-card">
              <div class="admin-theme-head">
                <p class="admin-theme-name"><?= e($item['title']) ?>
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
                    　本地版本：<?= $item['updateAvailable'] ? 'v' . e($item['localVersion']) : 'v' . e($item['localVersion']) ?>（最新）
                  <?php endif; ?>
                </p>
              </div>
              <div class="admin-theme-ops">
                <?php if ($item['installed']): ?>
                  <?php if ($item['updateAvailable']): ?>
                    <button type="button" class="btn btn-primary btn-sm admin-store-update" data-kind="<?= e($kind) ?>" data-name="<?= e($item['name']) ?>" data-title="<?= e($item['title']) ?>" data-version="<?= e($item['version']) ?>">更新到 v<?= e($item['version']) ?></button>
                  <?php else: ?>
                    <span class="badge">已是最新版本</span>
                  <?php endif; ?>
                <?php else: ?>
                  <button type="button" class="btn btn-primary btn-sm admin-store-install" data-kind="<?= e($kind) ?>" data-name="<?= e($item['name']) ?>" data-title="<?= e($item['title']) ?>" data-version="<?= e($item['version']) ?>">安装</button>
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

  // ---- 安装 / 更新 ----
  document.querySelectorAll(".admin-store-install").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var fd = new FormData();
      fd.append("kind", btn.dataset.kind);
      fd.append("name", btn.dataset.name);
      fd.append("_csrf", CSRF);
      post("/admin/store/install", fd)
        .then(function (j) { persistMsg("✓ 已安装 " + j.title + " v" + j.version, false); location.reload(); })
        .catch(function (err) { showMsg(err.message, true); });
    });
  });
  document.querySelectorAll(".admin-store-update").forEach(function (btn) {
    btn.addEventListener("click", function () {
      if (!window.confirm("将 " + btn.dataset.title + " 从 v" + btn.dataset.version + " 覆盖更新？\n更新失败会自动恢复旧版本。")) { return; }
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
