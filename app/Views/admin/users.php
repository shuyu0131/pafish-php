<?php
$users = $users ?? [];
$me = $me ?? [];
$meId = (int) ($me['id'] ?? 0);
$pointsEnabled = !empty($pointsEnabled);
$total = (int) ($total ?? count($users));
$page = max(1, (int) ($page ?? 1));
$pages = max(1, (int) ($pages ?? 1));
$per = (int) ($per ?? 20);
$perOptions = $perOptions ?? [10, 20, 50, 100];
$filters = $filters ?? ['q' => '', 'role' => '', 'state' => '', 'sort' => 'latest'];
$userQuery = static function (array $changes = []) use ($filters): string {
    $params = array_merge($filters, $changes);
    $params = array_filter($params, static fn ($value): bool => $value !== '' && $value !== null);
    return url_to('/admin/users') . ($params ? '?' . http_build_query($params) : '');
};
$roleLabel = static function (string $role): string {
    return match ($role) {
        'ADMIN' => t('admin.administrator'),
        'EDITOR' => t('common.editor'),
        default => t('common.user'),
    };
};
?>
<div class="admin-stack admin-list-page admin-users-list">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e(t('admin.users')) ?></h1>
      <p class="admin-page-sub"><?= e(t('admin.total')) ?> <?= $total ?> <?= e(t('admin.users_887811')) ?></p>
    </div>
    <div class="admin-head-actions"><button type="button" class="btn btn-primary" id="adminUserCreateOpen"><?= admin_icon('plus', 15) ?><span><?= e(t('admin.new_user')) ?></span></button></div>
  </div>

  <form class="admin-list-toolbar admin-list-filter-form admin-user-filter-form" action="<?= e(url_to('/admin/users')) ?>" method="get" role="search">
    <input type="search" class="input" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="<?= e(t('admin.search_nickname_username_or_email')) ?>" autocomplete="off">
    <select class="input" name="role" aria-label="<?= e(t('admin.filter_role')) ?>" onchange="this.form.submit()">
      <option value=""><?= e(t('admin.all_roles')) ?></option>
      <option value="ADMIN"<?= ($filters['role'] ?? '') === 'ADMIN' ? ' selected' : '' ?>><?= e(t('admin.administrator')) ?></option>
      <option value="EDITOR"<?= ($filters['role'] ?? '') === 'EDITOR' ? ' selected' : '' ?>><?= e(t('common.editor')) ?></option>
      <option value="USER"<?= ($filters['role'] ?? '') === 'USER' ? ' selected' : '' ?>><?= e(t('common.user')) ?></option>
    </select>
    <select class="input" name="state" aria-label="<?= e(t('admin.filter_status')) ?>" onchange="this.form.submit()">
      <option value=""><?= e(t('admin.all_statuses')) ?></option>
      <option value="active"<?= ($filters['state'] ?? '') === 'active' ? ' selected' : '' ?>><?= e(t('admin.normal')) ?></option>
      <option value="disabled"<?= ($filters['state'] ?? '') === 'disabled' ? ' selected' : '' ?>><?= e(t('admin.disabled')) ?></option>
    </select>
    <select class="input" name="sort" aria-label="<?= e(t('admin.sort')) ?>" onchange="this.form.submit()">
      <option value="latest"<?= ($filters['sort'] ?? '') === 'latest' ? ' selected' : '' ?>><?= e(t('admin.newest_registration')) ?></option>
      <option value="oldest"<?= ($filters['sort'] ?? '') === 'oldest' ? ' selected' : '' ?>><?= e(t('admin.oldest_registration')) ?></option>
      <option value="name"<?= ($filters['sort'] ?? '') === 'name' ? ' selected' : '' ?>><?= e(t('admin.nickname')) ?></option>
      <option value="content"<?= ($filters['sort'] ?? '') === 'content' ? ' selected' : '' ?>><?= e(t('admin.content_count')) ?></option>
      <option value="activity"<?= ($filters['sort'] ?? '') === 'activity' ? ' selected' : '' ?>><?= e(t('admin.last_active')) ?></option>
      <option value="role"<?= ($filters['sort'] ?? '') === 'role' ? ' selected' : '' ?>><?= e(t('admin.role')) ?></option>
      <option value="state"<?= ($filters['sort'] ?? '') === 'state' ? ' selected' : '' ?>><?= e(t('common.status')) ?></option>
    </select>
    <button class="btn btn-outline" type="submit" aria-label="<?= e(t('admin.search_users')) ?>"><?= admin_icon('search', 15) ?></button>
    <?php if (($filters['q'] ?? '') !== '' || ($filters['role'] ?? '') !== '' || ($filters['state'] ?? '') !== '' || ($filters['sort'] ?? 'latest') !== 'latest'): ?><a class="btn btn-ghost" href="<?= e(url_to('/admin/users')) ?>"><?= e(t('common.clear')) ?></a><?php endif; ?>
  </form>

  <?php if ($users === []): ?>
    <div class="admin-empty-list card">
      <p><?= $total === 0 && (($filters['q'] ?? '') !== '' || ($filters['role'] ?? '') !== '' || ($filters['state'] ?? '') !== '') ? t('admin.no_matching_users') : t('admin.no_users_found') ?></p>
      <?php if (($filters['q'] ?? '') !== '' || ($filters['role'] ?? '') !== '' || ($filters['state'] ?? '') !== ''): ?><a class="btn btn-sm btn-ghost" href="<?= e(url_to('/admin/users')) ?>"><?= e(t('admin.clear_filters')) ?></a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap admin-user-table-wrap">
      <table class="admin-table admin-user-table">
        <thead>
          <tr>
            <th class="admin-user-check-col"><input type="checkbox" id="adminUsersCheckAll" aria-label="<?= e(t('admin.select_all_users')) ?>"></th>
            <th><?= e(t('common.user')) ?></th>
            <th class="admin-user-role-col admin-table-secondary"><?= e(t('admin.role')) ?></th>
            <th class="admin-user-content-col"><?= e(t('common.post')) ?></th>
            <th class="admin-list-date admin-table-secondary"><?= e(t('admin.last_active')) ?></th>
            <th class="admin-col-ops"><?= e(t('common.actions')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <?php
            $uid = (int) $u['id'];
            $isMe = $uid === $meId;
            $avatar = !empty($u['avatar_url']) ? $u['avatar_url'] : admin_gravatar((string) $u['email']);
            $disabled = ((int) $u['disabled']) === 1;
            $isFounder = $uid === 1 && (string) $u['role'] === 'ADMIN';
            ?>
            <tr class="admin-user-row" data-id="<?= $uid ?>">
              <td class="admin-user-check-col"><input type="checkbox" class="admin-user-check" value="<?= $uid ?>" aria-label="<?= e(t('common.select')) ?> <?= e((string) ($u['nickname'] ?: $u['username'])) ?>"<?= ($isMe || $isFounder) ? ' disabled' : '' ?>></td>
              <td data-label="<?= e(t('common.user')) ?>">
                <div class="admin-user-identity">
                  <img class="admin-user-avatar-lg" src="<?= e($avatar) ?>" alt="" width="32" height="32">
                  <div class="admin-user-main">
                  <div class="admin-user-name-row">
                    <a class="admin-user-name" title="<?= e((string) ($u['nickname'] ?: $u['username'])) ?>" href="<?= e(url_to('/admin/users/' . $uid . '/edit')) ?>"><?= e((string) ($u['nickname'] ?: $u['username'])) ?></a>
                    <?php if ($isMe): ?><span class="admin-muted admin-current-account"><?= e(t('admin.current_account')) ?></span><?php endif; ?>
                    <?php if ($disabled): ?><span class="badge badge-danger"><?= e(t('admin.disabled')) ?></span><?php endif; ?>
                  </div>
                  <div class="admin-muted admin-user-subline"><?= e($u['email']) ?></div>
                  <div class="admin-table-mobile-meta">
                    <span><?= e($roleLabel((string) $u['role'])) ?></span>
                    <span title="<?= e(t('admin.last_active')) ?><?= e((string) ($u['last_active_at'] ?? t('admin.no_records_4f9ba2'))) ?>"><?= !empty($u['last_active_at']) ? e(date('Y-m-d', strtotime((string) $u['last_active_at']))) : t('admin.no_records_4f9ba2') ?></span>
                  </div>
                  </div>
                </div>
              </td>
              <td class="admin-user-role-col admin-table-secondary" data-label="<?= e(t('admin.role')) ?>"><?= e($roleLabel((string) $u['role'])) ?></td>
              <td class="admin-user-content-col" data-label="<?= e(t('common.post')) ?>"><a href="<?= e(url_to('/admin/posts?author=' . $uid)) ?>"><?= (int) $u['post_count'] ?></a></td>
              <td class="admin-list-date admin-table-secondary" data-label="<?= e(t('admin.last_active')) ?>"><span title="<?= e((string) ($u['last_active_at'] ?? t('admin.no_records_4f9ba2'))) ?>"><?= !empty($u['last_active_at']) ? e(date('Y-m-d', strtotime((string) $u['last_active_at']))) : t('admin.no_records_4f9ba2') ?></span></td>
              <td data-label="<?= e(t('common.actions')) ?>" class="admin-col-ops">
                <div class="admin-user-ops">
                  <a class="admin-icon-btn" href="<?= e(url_to('/admin/users/' . $uid . '/edit')) ?>" title="<?= e(t('admin.edit_user')) ?>" aria-label="<?= e(t('admin.edit_user')) ?>"><?= admin_icon('edit', 15) ?></a>
                  <?php if (!$isMe && !$isFounder): ?>
                    <?php if ($disabled): ?>
                      <button type="button" class="btn btn-sm btn-ghost admin-user-unban"><?= e(t('admin.enable_c27db7')) ?></button>
                    <?php else: ?>
                      <button type="button" class="btn btn-sm btn-ghost admin-user-ban"><?= e(t('admin.disable')) ?></button>
                    <?php endif; ?>
                    <button type="button" class="admin-icon-btn admin-icon-danger admin-user-delete" title="<?= e(t('admin.delete_user')) ?>" aria-label="<?= e(t('admin.delete_user')) ?>"><?= admin_icon('trash', 15) ?></button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="admin-batch-bar admin-user-batch-bar" id="adminUserBatchBar">
      <span class="admin-batch-count"><?= e(t('admin.selected')) ?> <span class="admin-user-batch-count-number">0</span> <?= e(t('admin.users_887811')) ?></span>
      <select class="input admin-user-bulk-select" id="adminUserBulkAction" aria-label="<?= e(t('admin.batch_actions')) ?>" disabled>
        <option value=""><?= e(t('admin.batch_actions')) ?></option>
        <option value="disable"><?= e(t('admin.disable_selected')) ?></option>
        <option value="enable"><?= e(t('admin.enable_selected')) ?></option>
        <option value="role:EDITOR"><?= e(t('admin.set_as_editor')) ?></option>
        <option value="role:USER"><?= e(t('admin.set_as_user')) ?></option>
        <option value="role:ADMIN"><?= e(t('admin.set_as_administrator')) ?></option>
      </select>
    </div>
    <?= admin_pagination($page, $pages, $total, static function (int $p, int $size = 20) use ($userQuery): string {
      return $userQuery(['page' => $p, 'per' => $size]);
    }, $per, $perOptions) ?>
  <?php endif; ?>
</div>

<div class="admin-modal-backdrop" id="adminUserCreateModal" hidden>
  <div class="admin-modal" role="dialog" aria-modal="true" aria-label="<?= e(t('admin.new_user')) ?>">
    <div class="admin-modal-head"><h2 class="admin-modal-title"><?= e(t('admin.new_user')) ?></h2><button type="button" class="admin-icon-btn" data-create-close aria-label="<?= e(t('common.close')) ?>">×</button></div>
    <div class="admin-modal-body">
      <label class="admin-field"><span class="label"><?= e(t('admin.username')) ?></span><input class="input" id="createUsername" maxlength="50" placeholder="<?= e(t('admin.3_50_letters_numbers_underscores_or_hyphens')) ?>"></label>
      <label class="admin-field"><span class="label"><?= e(t('admin.display_name')) ?></span><input class="input" id="createNickname" maxlength="50"></label>
      <label class="admin-field"><span class="label"><?= e(t('admin.email_f1f231')) ?></span><input class="input" id="createEmail" type="email" maxlength="255"></label>
      <label class="admin-field"><span class="label"><?= e(t('admin.initial_password')) ?></span><input class="input" id="createPassword" type="password" minlength="6" maxlength="72"></label>
      <label class="admin-field"><span class="label"><?= e(t('admin.role')) ?></span><select class="input" id="createRole"><option value="USER"><?= e(t('common.user')) ?></option><option value="EDITOR"><?= e(t('common.editor')) ?></option><option value="ADMIN"><?= e(t('admin.administrator')) ?></option></select></label>
      <p class="admin-modal-error" id="createUserError" hidden></p>
    </div>
    <div class="admin-modal-actions"><button type="button" class="btn btn-ghost" data-create-close><?= e(t('common.cancel')) ?></button><button type="button" class="btn btn-primary" id="adminUserCreateSave"><?= e(t('admin.create_user')) ?></button></div>
  </div>
</div>

<?php $usersCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($usersCsrf) ?>;
  var ADMIN_USERS = <?= json_encode(url_to('/admin/users')) ?>;
  var createModal = document.getElementById("adminUserCreateModal");
  function closeCreate() { createModal.hidden = true; if (window.pafishModalSync) window.pafishModalSync(); }
  document.getElementById("adminUserCreateOpen").addEventListener("click", function () { createModal.hidden = false; document.getElementById("createUserError").hidden = true; document.getElementById("createUsername").focus(); if (window.pafishModalSync) window.pafishModalSync(); });
  document.querySelectorAll("[data-create-close]").forEach(function (button) { button.addEventListener("click", closeCreate); });
  document.getElementById("adminUserCreateSave").addEventListener("click", function () {
    var fd = new FormData(); fd.append("_csrf", CSRF);
    [["username", "createUsername"], ["nickname", "createNickname"], ["email", "createEmail"], ["password", "createPassword"], ["role", "createRole"]].forEach(function (pair) { fd.append(pair[0], document.getElementById(pair[1]).value.trim()); });
    post(<?= json_encode(url_to('/admin/users/create')) ?>, fd, function () { closeCreate(); pafishToastReload(window.pafishT("js.user_created"), "success"); });
  });

  function post(url, fd, onOk) {
    fetch(url, {
      method: "POST",
      body: fd,
      headers: { "X-Requested-With": "XMLHttpRequest" }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) onOk(j);
        else pafishNotify((j && j.error) || window.pafishT("common.operation_failed"), true);
      })
      .catch(function () { pafishNotify(window.pafishT("common.operation_failed"), true); });
  }

  // 禁用确认
  document.querySelectorAll(".admin-user-ban").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-user-row");
      var name = row.querySelector(".admin-user-name").textContent.trim();
      (window.pafishConfirm ? window.pafishConfirm(window.pafishT("js.confirm_disable_user", {name: name}), { title: window.pafishT("js.disable_user_638157") }) : Promise.resolve(window.confirm(window.pafishT("js.disable_this_user")))).then(function (ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append("_csrf", CSRF);
        post(ADMIN_USERS + "/" + row.dataset.id + "/toggle", fd, function () {
          pafishToastReload(window.pafishT("js.user_disabled"), "success");
        });
      });
    });
  });

  // 解禁：直接提交
  document.querySelectorAll(".admin-user-unban").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-user-row");
      var fd = new FormData();
      fd.append("_csrf", CSRF);
      post(ADMIN_USERS + "/" + row.dataset.id + "/toggle", fd, function () {
        pafishToastReload(window.pafishT("js.user_enabled"), "success");
      });
    });
  });

  document.querySelectorAll(".admin-user-delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-user-row");
      var name = row.querySelector(".admin-user-name").textContent.trim();
      var confirmTask = window.pafishConfirm
        ? window.pafishConfirm(window.pafishT("js.confirm_delete_user", {name: name}), { title: window.pafishT("js.delete_user") })
        : Promise.resolve(window.confirm(window.pafishT("js.delete_this_user_their_posts_and_notes_will")));
      confirmTask.then(function (ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append("_csrf", CSRF);
        post(ADMIN_USERS + "/" + row.dataset.id + "/delete", fd, function () {
          pafishToastReload(window.pafishT("js.user_deleted"), "success");
        });
      });
    });
  });

  var checks = Array.prototype.slice.call(document.querySelectorAll(".admin-user-check"));
  var checkAll = document.getElementById("adminUsersCheckAll");
  var bulkSelect = document.getElementById("adminUserBulkAction");
  var batchBar = document.getElementById("adminUserBatchBar");
  var batchCount = batchBar ? batchBar.querySelector(".admin-user-batch-count-number") : null;
  function selectedIds() {
    return checks.filter(function (box) { return box.checked && !box.disabled; }).map(function (box) { return box.value; });
  }
  function syncBatch() {
    var ids = selectedIds();
    if (batchBar) batchBar.hidden = ids.length === 0;
    if (batchCount) batchCount.textContent = ids.length;
    if (bulkSelect) bulkSelect.disabled = ids.length === 0;
    if (checkAll) {
      checkAll.checked = ids.length > 0 && ids.length === checks.filter(function (box) { return !box.disabled; }).length;
      checkAll.indeterminate = ids.length > 0 && !checkAll.checked;
    }
  }
  if (checkAll) checkAll.addEventListener("change", function () {
    checks.forEach(function (box) { if (!box.disabled) box.checked = checkAll.checked; });
    syncBatch();
  });
  checks.forEach(function (box) { box.addEventListener("change", syncBatch); });
  if (bulkSelect) bulkSelect.addEventListener("change", function () {
      var ids = selectedIds();
      var action = bulkSelect.value;
      if (!ids.length || !action) return;
      var roleAction = action.indexOf("role:") === 0;
      var role = roleAction ? action.slice(5) : "";
      var roleLabels = { ADMIN: window.pafishT("js.administrator"), EDITOR: window.pafishT("common.editor"), USER: window.pafishT("common.user") };
      var label = roleAction ? window.pafishT("js.set_role_to", {role: roleLabels[role] || window.pafishT("js.set_role")}) : (action === "disable" ? window.pafishT("js.disable") : window.pafishT("js.enable"));
      var confirmTask = window.pafishConfirm
        ? window.pafishConfirm(window.pafishT("js.confirm_bulk_user_action", {action: label, n: ids.length}), { title: window.pafishT("js.bulk_user_action", {action: label}) })
        : Promise.resolve(window.confirm(window.pafishT("js.confirm_bulk_user_action", {action: label, n: ids.length})));
      confirmTask.then(function (ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append("_csrf", CSRF);
        fd.append("action", roleAction ? "set_role" : action);
        if (roleAction) fd.append("role", role);
        ids.forEach(function (id) { fd.append("ids[]", id); });
        post(ADMIN_USERS + "/bulk", fd, function () {
          pafishToastReload(window.pafishT("js.done") + label + window.pafishT("js.select_user"), "success");
        });
      });
      bulkSelect.value = "";
    });
  syncBatch();
})();
</script>
