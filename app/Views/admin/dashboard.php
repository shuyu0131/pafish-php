<?php /** 管理员工作台；软件环境只在管理员视图展示。 */ ?>
<div class="admin-stack admin-dashboard">
  <div class="admin-page-head">
    <div><h1 class="admin-h1">工作台</h1><p class="admin-page-sub">你好，<?= e($username) ?>，欢迎回来</p></div>
  </div>
  <div class="admin-dashboard-stats">
    <?php foreach ($stats as $stat): ?>
      <?php $tag = $stat['url'] ? 'a' : 'div'; ?>
      <<?= $tag ?> class="card admin-overview-card"<?php if ($stat['url']): ?> href="<?= e(url_to($stat['url'])) ?>"<?php endif; ?>>
        <div class="admin-overview-label"><span class="admin-overview-icon"><?= admin_icon($stat['icon'], 18) ?></span><span><?= e($stat['label']) ?></span></div>
        <p class="admin-overview-value"><?= number_format($stat['value']) ?></p>
      </<?= $tag ?>>
    <?php endforeach; ?>
  </div>
  <div class="admin-dashboard-grid">
    <section class="card admin-dashboard-panel">
      <div class="admin-dashboard-panel-head"><h2>快捷入口</h2></div>
      <div class="admin-dashboard-shortcuts">
        <?php foreach ([
          ['/admin/posts/new', 'pen', '写文章'], ['/admin/micro/new', 'message', '发微语'],
          ['/admin/pages/new', 'file-text', '新建页面'], ['/admin/uploads', 'upload', '媒体库'],
          ['/admin/appearance', 'palette', '模板管理'], ['/admin/plugins', 'puzzle', '插件管理'],
        ] as [$href, $icon, $label]): ?>
          <a href="<?= e(url_to($href)) ?>"><span class="admin-dashboard-shortcut-icon"><?= admin_icon($icon, 20) ?></span><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="card admin-dashboard-panel">
      <div class="admin-dashboard-panel-head"><h2>软件信息</h2><span class="admin-muted">纸鱼博客 v<?= e($current) ?></span></div>
      <dl class="admin-software-list">
        <?php foreach ($software as $label => $value): ?><div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div><?php endforeach; ?>
      </dl>
      <div class="admin-software-foot">
        <div><a href="https://www.pafish.cn" target="_blank" rel="noopener noreferrer">官方网站</a><a href="https://www.pafish.cn/docs" target="_blank" rel="noopener noreferrer">使用文档</a></div>
        <?php if (!empty($canUpgrade)): ?>
          <button type="button" class="btn btn-outline btn-sm" id="dashboardUpgradeBtn"
            data-latest="<?= e((string) ($upgradeInfo['latest'] ?? '')) ?>" data-has-update="<?= !empty($upgradeInfo['hasUpdate']) ? '1' : '0' ?>">
            <?= !empty($upgradeInfo['hasUpdate']) ? '发现新版本 v' . e((string) $upgradeInfo['latest']) : '检查更新' ?>
          </button>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<?php if (!empty($canUpgrade)): ?>
<div class="admin-modal-backdrop" id="dashboardUpgradeModal" hidden>
  <div class="admin-modal admin-upgrade-modal" role="dialog" aria-modal="true" aria-labelledby="dashboard-upgrade-title">
    <div class="admin-modal-head">
      <h2 class="admin-modal-title" id="dashboard-upgrade-title">系统更新</h2>
      <button type="button" class="admin-icon-btn" data-modal-close aria-label="关闭" title="关闭"><?= admin_icon('x', 16) ?></button>
    </div>
    <div class="admin-modal-body">
      <p class="admin-modal-hint" id="dashboardUpgradeMessage">正在检查更新…</p>
      <div class="admin-upgrade-modal-notes" id="dashboardUpgradeNotes" hidden></div>
      <p class="admin-modal-error" id="dashboardUpgradeError" hidden></p>
    </div>
    <div class="admin-modal-actions">
      <button type="button" class="btn btn-ghost" data-modal-close id="dashboardUpgradeCancel">关闭</button>
      <button type="button" class="btn btn-primary" id="dashboardUpgradeRun" hidden>立即更新</button>
    </div>
  </div>
</div>
<?php $dashboardUpgradeCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var trigger = document.getElementById("dashboardUpgradeBtn");
  var modal = document.getElementById("dashboardUpgradeModal");
  if (!trigger || !modal) return;
  var message = document.getElementById("dashboardUpgradeMessage");
  var notes = document.getElementById("dashboardUpgradeNotes");
  var error = document.getElementById("dashboardUpgradeError");
  var run = document.getElementById("dashboardUpgradeRun");
  var cancel = document.getElementById("dashboardUpgradeCancel");
  var csrf = <?= json_encode($dashboardUpgradeCsrf) ?>;
  var current = <?= json_encode($current) ?>;
  var latest = trigger.dataset.latest || "";
  var hasUpdate = trigger.dataset.hasUpdate === "1";
  var completed = false;

  function post(path, force) {
    var fd = new FormData();
    fd.append("_csrf", csrf);
    if (force) fd.append("force", "1");
    return fetch(path, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || "操作失败"); return j; }); });
  }
  function open() {
    modal.hidden = false;
    if (window.pafishModalSync) window.pafishModalSync();
    message.textContent = "正在检查更新…";
    notes.hidden = true;
    error.hidden = true;
    run.hidden = true;
    run.disabled = false;
    run.textContent = "立即更新";
    completed = false;
    post(<?= json_encode(url_to('/admin/upgrade/check')) ?>, true)
      .then(function (j) {
        latest = j.latest || "";
        hasUpdate = !!j.hasUpdate && latest !== current;
        trigger.dataset.latest = latest;
        trigger.dataset.hasUpdate = hasUpdate ? "1" : "0";
        trigger.classList.toggle("btn-primary", hasUpdate);
        trigger.textContent = hasUpdate ? "发现新版本 v" + latest : "检查更新";
        if (hasUpdate) {
          message.textContent = "发现新版本 v" + latest + "，可以开始更新。";
          if (j.notes) { notes.textContent = j.notes; notes.hidden = false; }
          run.hidden = false;
        } else {
          message.textContent = "当前已是最新版本（v" + (j.current || current) + "）。";
        }
      })
      .catch(function (e) { message.textContent = "检查更新失败"; error.textContent = e.message; error.hidden = false; });
  }
  trigger.addEventListener("click", open);
  run.addEventListener("click", function () {
    if (!hasUpdate || completed || run.disabled) return;
    run.disabled = true;
    run.textContent = "更新中…";
    message.textContent = "正在更新，请不要关闭页面…";
    post(<?= json_encode(url_to('/admin/upgrade/run')) ?>, false)
      .then(function (j) {
        var installed = j.latest || j.current || latest;
        completed = true;
        message.textContent = "更新完成，当前版本 v" + installed + "。";
        notes.hidden = true;
        run.textContent = "刷新页面";
        run.disabled = false;
        run.onclick = function () { window.location.reload(); };
        cancel.textContent = "稍后刷新";
      })
      .catch(function (e) { message.textContent = "更新失败，系统已恢复原版本"; error.textContent = e.message; error.hidden = false; run.disabled = false; run.textContent = "重试更新"; });
  });
})();
</script>
<?php endif; ?>
