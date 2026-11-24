<?php /** 管理员工作台；软件环境只在管理员视图展示。 */ ?>
<div class="admin-stack admin-dashboard">
  <div class="admin-page-head">
    <div><h1 class="admin-h1"><?= e(t('admin.dashboard')) ?></h1><p class="admin-page-sub"><?= e(t('admin.hello')) ?><?= e($username) ?><?= e(t('admin.welcome_back')) ?></p></div>
  </div>
  <?php require __DIR__ . '/partials/dashboard-stats.php'; ?>
  <div class="admin-dashboard-grid">
    <section class="card admin-dashboard-panel">
      <div class="admin-dashboard-panel-head"><h2><?= e(t('admin.quick_actions')) ?></h2></div>
      <div class="admin-dashboard-shortcuts">
        <?php foreach ([
          ['/admin/posts/new', 'pen', t('admin.write_a_post')], ['/admin/micro/new', 'message', t('admin.write_a_note')],
          ['/admin/pages/new', 'file-text', t('admin.new_page')], ['/admin/uploads', 'upload', t('admin.media_library')],
          ['/admin/appearance', 'palette', t('admin.templates')], ['/admin/plugins', 'puzzle', t('admin.plugins')],
        ] as [$href, $icon, $label]): ?>
          <a href="<?= e(url_to($href)) ?>"><span class="admin-dashboard-shortcut-icon"><?= admin_icon($icon, 20) ?></span><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="card admin-dashboard-panel">
      <div class="admin-dashboard-panel-head"><h2><?= e(t('admin.software_information')) ?></h2><span class="admin-muted"><?= e(t('admin.pafish_v')) ?><?= e($current) ?></span></div>
      <dl class="admin-software-list">
        <?php foreach ($software as $label => $value): ?><div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div><?php endforeach; ?>
      </dl>
      <div class="admin-software-foot">
        <div><a href="https://www.pafish.cn" target="_blank" rel="noopener noreferrer"><?= e(t('admin.official_website')) ?></a><a href="https://www.pafish.cn/docs" target="_blank" rel="noopener noreferrer"><?= e(t('admin.documentation')) ?></a></div>
        <?php if (!empty($canUpgrade)): ?>
          <button type="button" class="btn <?= !empty($upgradeInfo['hasUpdate']) ? 'btn-primary' : 'btn-outline' ?> btn-sm" id="dashboardUpgradeBtn"
            data-latest="<?= e((string) ($upgradeInfo['latest'] ?? '')) ?>" data-has-update="<?= !empty($upgradeInfo['hasUpdate']) ? '1' : '0' ?>">
            <?= !empty($upgradeInfo['hasUpdate']) ? t('admin.new_version_available_v') . e((string) $upgradeInfo['latest']) : t('admin.check_for_updates') ?>
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
      <h2 class="admin-modal-title" id="dashboard-upgrade-title"><?= e(t('admin.system_update')) ?></h2>
      <button type="button" class="admin-icon-btn" data-modal-close aria-label="<?= e(t('common.close')) ?>" title="<?= e(t('common.close')) ?>"><?= admin_icon('x', 16) ?></button>
    </div>
    <div class="admin-modal-body">
      <p class="admin-modal-hint" id="dashboardUpgradeMessage"><?= e(t('admin.checking_for_updates')) ?></p>
      <div class="admin-upgrade-modal-notes" id="dashboardUpgradeNotes" hidden></div>
      <p class="admin-modal-error" id="dashboardUpgradeError" hidden></p>
    </div>
    <div class="admin-modal-actions">
      <button type="button" class="btn btn-ghost" data-modal-close id="dashboardUpgradeCancel"><?= e(t('common.close')) ?></button>
      <button type="button" class="btn btn-primary" id="dashboardUpgradeRun" hidden><?= e(t('admin.update_now')) ?></button>
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
  var manualCheckStarted = false;

  function updateTrigger(j) {
    latest = j.latest || "";
    hasUpdate = !!j.hasUpdate && latest !== current;
    trigger.dataset.latest = latest;
    trigger.dataset.hasUpdate = hasUpdate ? "1" : "0";
    trigger.classList.toggle("btn-primary", hasUpdate);
    trigger.classList.toggle("btn-outline", !hasUpdate);
    trigger.textContent = hasUpdate ? window.pafishT("js.new_version_available_v") + latest : window.pafishT("js.check_for_updates");
  }

  function post(path, force) {
    var fd = new FormData();
    fd.append("_csrf", csrf);
    if (force) fd.append("force", "1");
    return fetch(path, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          if (!r.ok || !j.ok) {
            var err = new Error(j.error || window.pafishT("common.operation_failed"));
            err.upgradeState = j.upgradeState || null;
            throw err;
          }
          return j;
        });
      });
  }
  function open() {
    manualCheckStarted = true;
    modal.hidden = false;
    if (window.pafishModalSync) window.pafishModalSync();
    message.textContent = window.pafishT("js.checking_for_updates");
    notes.hidden = true;
    error.hidden = true;
    run.hidden = true;
    run.disabled = false;
    run.textContent = window.pafishT("js.update_now");
    completed = false;
    post(<?= json_encode(url_to('/admin/upgrade/check')) ?>, true)
      .then(function (j) {
        updateTrigger(j);
        if (hasUpdate) {
          message.textContent = window.pafishT("js.new_version_available_v") + latest + window.pafishT("js.ready_to_update");
          if (j.notes) {
            // notesHtml is rendered and sanitized by the check endpoint.
            if (typeof j.notesHtml === "string") notes.innerHTML = j.notesHtml;
            else notes.textContent = j.notes;
            notes.hidden = false;
          }
          run.hidden = false;
        } else {
          message.textContent = window.pafishT("js.already_up_to_date_v") + (j.current || current) + "）。";
        }
      })
      .catch(function (e) { message.textContent = window.pafishT("js.unable_to_check_for_updates"); error.textContent = e.message; error.hidden = false; });
  }
  trigger.addEventListener("click", open);
  // 页面呈现后静默检查；自动检查使用服务端缓存，手动点击仍强制刷新。
  window.setTimeout(function () {
    if (manualCheckStarted) return;
    post(<?= json_encode(url_to('/admin/upgrade/check')) ?>, false)
      .then(function (j) { if (!manualCheckStarted) updateTrigger(j); })
      .catch(function () { /* 自动检查失败不打断工作台，手动检查会显示错误。 */ });
  }, 0);
  run.addEventListener("click", function () {
    if (!hasUpdate || completed || run.disabled) return;
    run.disabled = true;
    run.textContent = window.pafishT("js.updating");
    message.textContent = window.pafishT("js.updating_please_keep_this_page_open");
    post(<?= json_encode(url_to('/admin/upgrade/run')) ?>, false)
      .then(function (j) {
        var installed = j.latest || j.current || latest;
        completed = true;
        message.textContent = window.pafishT("js.update_complete_current_version_v") + installed + "。";
        notes.hidden = true;
        run.textContent = window.pafishT("js.refresh_page");
        run.disabled = false;
        run.onclick = function () { window.location.reload(); };
        cancel.textContent = window.pafishT("js.refresh_later");
      })
      .catch(function (e) {
        var state = e.upgradeState || {};
        // 预检失败不会动程序文件；迁移失败可能已经完成回滚，不能再用一句
        // 固定文案掩盖两者差异。
        if (state.failed_phase === "checking_permissions") {
          message.textContent = window.pafishT("js.update_was_not_performed_target_path_permission_checks");
        } else if (state.files_modified === false) {
          message.textContent = window.pafishT("js.update_was_not_performed_application_files_are_unchanged");
        } else if (state.rollback_completed === true) {
          message.textContent = window.pafishT("js.update_failed_application_files_were_restored_to_the");
        } else if (state.rollback_completed === false) {
          message.textContent = window.pafishT("js.update_failed_automatic_restoration_was_incomplete");
        } else {
          message.textContent = window.pafishT("js.update_failed_check_the_error_details");
        }
        var details = [e.message];
        if (state.failed_phase) details.push(window.pafishT("js.failed_stage") + state.failed_phase);
        if (state.failed_path) details.push(window.pafishT("js.failed_path") + state.failed_path);
        if (state.rollback_error) details.push(window.pafishT("js.restore_error") + state.rollback_error);
        if (state.database_restore_error) details.push(window.pafishT("js.database_restore_error") + state.database_restore_error);
        if (state.package_sha256) details.push(window.pafishT("js.package_sha_256") + state.package_sha256);
        error.textContent = details.join("\n");
        error.hidden = false;
        run.disabled = false;
        run.textContent = window.pafishT("js.retry_update");
      });
  });
})();
</script>
<?php endif; ?>
