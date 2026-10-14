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
        'ADMIN' => '管理员',
        'EDITOR' => '编辑',
        default => '用户',
    };
};
?>
<div class="admin-stack admin-list-page admin-users-list">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">用户管理</h1>
      <p class="admin-page-sub">共 <?= $total ?> 个用户</p>
    </div>
    <div class="admin-head-actions"><button type="button" class="btn btn-primary" id="adminUserCreateOpen"><?= admin_icon('plus', 15) ?><span>新建用户</span></button></div>
  </div>

  <form class="admin-list-toolbar admin-list-filter-form admin-user-filter-form" action="<?= e(url_to('/admin/users')) ?>" method="get" role="search">
    <input type="search" class="input" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="搜索昵称、用户名或邮箱" autocomplete="off">
    <select class="input" name="role" aria-label="筛选角色" onchange="this.form.submit()">
      <option value="">全部角色</option>
      <option value="ADMIN"<?= ($filters['role'] ?? '') === 'ADMIN' ? ' selected' : '' ?>>管理员</option>
      <option value="EDITOR"<?= ($filters['role'] ?? '') === 'EDITOR' ? ' selected' : '' ?>>编辑</option>
      <option value="USER"<?= ($filters['role'] ?? '') === 'USER' ? ' selected' : '' ?>>用户</option>
    </select>
    <select class="input" name="state" aria-label="筛选状态" onchange="this.form.submit()">
      <option value="">全部状态</option>
      <option value="active"<?= ($filters['state'] ?? '') === 'active' ? ' selected' : '' ?>>正常</option>
      <option value="disabled"<?= ($filters['state'] ?? '') === 'disabled' ? ' selected' : '' ?>>已禁用</option>
    </select>
    <select class="input" name="sort" aria-label="排序" onchange="this.form.submit()">
      <option value="latest"<?= ($filters['sort'] ?? '') === 'latest' ? ' selected' : '' ?>>最近注册</option>
      <option value="oldest"<?= ($filters['sort'] ?? '') === 'oldest' ? ' selected' : '' ?>>最早注册</option>
      <option value="name"<?= ($filters['sort'] ?? '') === 'name' ? ' selected' : '' ?>>昵称</option>
      <option value="content"<?= ($filters['sort'] ?? '') === 'content' ? ' selected' : '' ?>>内容数量</option>
      <option value="activity"<?= ($filters['sort'] ?? '') === 'activity' ? ' selected' : '' ?>>最近活动</option>
      <option value="role"<?= ($filters['sort'] ?? '') === 'role' ? ' selected' : '' ?>>角色</option>
      <option value="state"<?= ($filters['sort'] ?? '') === 'state' ? ' selected' : '' ?>>状态</option>
    </select>
    <button class="btn btn-outline" type="submit" aria-label="搜索用户"><?= admin_icon('search', 15) ?></button>
    <?php if (($filters['q'] ?? '') !== '' || ($filters['role'] ?? '') !== '' || ($filters['state'] ?? '') !== '' || ($filters['sort'] ?? 'latest') !== 'latest'): ?><a class="btn btn-ghost" href="<?= e(url_to('/admin/users')) ?>">清除</a><?php endif; ?>
  </form>

  <?php if ($users === []): ?>
    <div class="admin-empty-list card">
      <p><?= $total === 0 && (($filters['q'] ?? '') !== '' || ($filters['role'] ?? '') !== '' || ($filters['state'] ?? '') !== '') ? '没有找到匹配的用户' : '暂无用户' ?></p>
      <?php if (($filters['q'] ?? '') !== '' || ($filters['role'] ?? '') !== '' || ($filters['state'] ?? '') !== ''): ?><a class="btn btn-sm btn-ghost" href="<?= e(url_to('/admin/users')) ?>">清除筛选</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap admin-user-table-wrap">
      <table class="admin-table admin-user-table">
        <thead>
          <tr>
            <th class="admin-user-check-col"><input type="checkbox" id="adminUsersCheckAll" aria-label="全选用户"></th>
            <th>用户</th>
            <th class="admin-user-role-col">角色</th>
            <th class="admin-user-content-col">文章</th>
            <th class="admin-list-date">最近活动</th>
            <th class="admin-col-ops">操作</th>
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
              <td class="admin-user-check-col"><input type="checkbox" class="admin-user-check" value="<?= $uid ?>" aria-label="选择<?= e((string) ($u['nickname'] ?: $u['username'])) ?>"<?= ($isMe || $isFounder) ? ' disabled' : '' ?>></td>
              <td data-label="用户">
                <div class="admin-user-identity">
                  <img class="admin-user-avatar-lg" src="<?= e($avatar) ?>" alt="" width="32" height="32">
                  <div class="admin-user-main">
                  <div class="admin-user-name-row">
                    <a class="admin-user-name" title="<?= e((string) ($u['nickname'] ?: $u['username'])) ?>" href="<?= e(url_to('/admin/users/' . $uid . '/edit')) ?>"><?= e((string) ($u['nickname'] ?: $u['username'])) ?></a>
                    <?php if ($isMe): ?><span class="admin-muted admin-current-account">当前账号</span><?php endif; ?>
                    <?php if ($disabled): ?><span class="badge badge-danger">已禁用</span><?php endif; ?>
                  </div>
                  <div class="admin-muted admin-user-subline"><?= e($u['email']) ?></div>
                  </div>
                </div>
              </td>
              <td class="admin-user-role-col" data-label="角色"><?= e($roleLabel((string) $u['role'])) ?></td>
              <td class="admin-user-content-col" data-label="文章"><a href="<?= e(url_to('/admin/posts?author=' . $uid)) ?>"><?= (int) $u['post_count'] ?></a></td>
              <td class="admin-list-date" data-label="最近活动"><span title="<?= e((string) ($u['last_active_at'] ?? '暂无记录')) ?>"><?= !empty($u['last_active_at']) ? e(date('Y-m-d', strtotime((string) $u['last_active_at']))) : '暂无记录' ?></span></td>
              <td data-label="操作" class="admin-col-ops">
                <div class="admin-user-ops">
                  <a class="admin-icon-btn" href="<?= e(url_to('/admin/users/' . $uid . '/edit')) ?>" title="编辑用户" aria-label="编辑用户"><?= admin_icon('edit', 15) ?></a>
                  <?php if (!$isMe && !$isFounder): ?>
                    <?php if ($disabled): ?>
                      <button type="button" class="btn btn-sm btn-ghost admin-user-unban">解禁</button>
                    <?php else: ?>
                      <button type="button" class="btn btn-sm btn-ghost admin-user-ban">禁用</button>
                    <?php endif; ?>
                    <button type="button" class="admin-icon-btn admin-icon-danger admin-user-delete" title="删除用户" aria-label="删除用户"><?= admin_icon('trash', 15) ?></button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="admin-batch-bar admin-user-batch-bar" id="adminUserBatchBar">
      <span class="admin-batch-count">已选 <span class="admin-user-batch-count-number">0</span> 个用户</span>
      <select class="input admin-user-bulk-select" id="adminUserBulkAction" aria-label="批量操作" disabled>
        <option value="">批量操作</option>
        <option value="disable">批量禁用</option>
        <option value="enable">批量解禁</option>
        <option value="role:EDITOR">设为编辑</option>
        <option value="role:USER">设为用户</option>
        <option value="role:ADMIN">设为管理员</option>
      </select>
    </div>
    <?= admin_pagination($page, $pages, $total, static function (int $p, int $size = 20) use ($userQuery): string {
      return $userQuery(['page' => $p, 'per' => $size]);
    }, $per, $perOptions) ?>
  <?php endif; ?>
</div>

<div class="admin-modal-backdrop" id="adminUserCreateModal" hidden>
  <div class="admin-modal" role="dialog" aria-modal="true" aria-label="新建用户">
    <div class="admin-modal-head"><h2 class="admin-modal-title">新建用户</h2><button type="button" class="admin-icon-btn" data-create-close aria-label="关闭">×</button></div>
    <div class="admin-modal-body">
      <label class="admin-field"><span class="label">用户名 *</span><input class="input" id="createUsername" maxlength="50" placeholder="3-50 位字母、数字、_ 或 -"></label>
      <label class="admin-field"><span class="label">显示昵称</span><input class="input" id="createNickname" maxlength="50"></label>
      <label class="admin-field"><span class="label">邮箱 *</span><input class="input" id="createEmail" type="email" maxlength="255"></label>
      <label class="admin-field"><span class="label">初始密码 *</span><input class="input" id="createPassword" type="password" minlength="6" maxlength="72"></label>
      <label class="admin-field"><span class="label">角色</span><select class="input" id="createRole"><option value="USER">用户</option><option value="EDITOR">编辑</option><option value="ADMIN">管理员</option></select></label>
      <p class="admin-modal-error" id="createUserError" hidden></p>
    </div>
    <div class="admin-modal-actions"><button type="button" class="btn btn-ghost" data-create-close>取消</button><button type="button" class="btn btn-primary" id="adminUserCreateSave">创建用户</button></div>
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
    post(<?= json_encode(url_to('/admin/users/create')) ?>, fd, function () { closeCreate(); pafishToastReload("用户已创建", "success"); });
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
        else pafishNotify((j && j.error) || "操作失败", true);
      })
      .catch(function () { pafishNotify("操作失败", true); });
  }

  // 禁用确认
  document.querySelectorAll(".admin-user-ban").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-user-row");
      var name = row.querySelector(".admin-user-name").textContent.trim();
      (window.pafishConfirm ? window.pafishConfirm("确定禁用用户「" + name + "」？", { title: "禁用用户" }) : Promise.resolve(window.confirm("确定禁用该用户？"))).then(function (ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append("_csrf", CSRF);
        post(ADMIN_USERS + "/" + row.dataset.id + "/toggle", fd, function () {
          pafishToastReload("用户已禁用", "success");
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
        pafishToastReload("用户已解禁", "success");
      });
    });
  });

  // 删除用户：后端会把文章和微语转移给当前管理员，必须二次确认。
  document.querySelectorAll(".admin-user-delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-user-row");
      var name = row.querySelector(".admin-user-name").textContent.trim();
      var confirmTask = window.pafishConfirm
        ? window.pafishConfirm("确定删除用户「" + name + "」吗？文章和微语会转移给当前管理员。", { title: "删除用户" })
        : Promise.resolve(window.confirm("确定删除该用户吗？文章和微语会转移给当前管理员。"));
      confirmTask.then(function (ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append("_csrf", CSRF);
        post(ADMIN_USERS + "/" + row.dataset.id + "/delete", fd, function () {
          pafishToastReload("用户已删除", "success");
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
      var roleLabels = { ADMIN: "管理员", EDITOR: "编辑", USER: "用户" };
      var label = roleAction ? "设为" + (roleLabels[role] || "指定角色") : (action === "disable" ? "禁用" : "解禁");
      var confirmTask = window.pafishConfirm
        ? window.pafishConfirm("确定" + label + "选中的 " + ids.length + " 个用户吗？", { title: "批量" + label })
        : Promise.resolve(window.confirm("确定" + label + "选中的用户吗？"));
      confirmTask.then(function (ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append("_csrf", CSRF);
        fd.append("action", roleAction ? "set_role" : action);
        if (roleAction) fd.append("role", role);
        ids.forEach(function (id) { fd.append("ids[]", id); });
        post(ADMIN_USERS + "/bulk", fd, function () {
          pafishToastReload("已" + label + "选中用户", "success");
        });
      });
      bulkSelect.value = "";
    });
  syncBatch();
})();
</script>
