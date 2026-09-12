<?php
/**
 * 用户管理：
 * 全量用户按注册时间正序；每行：头像/昵称/@用户名/角色徽章/已禁用徽章/
 * 邮箱·注册日期·文章数·评论数；操作：角色下拉（含自己）、他人可禁用（确认弹窗）/
 * 解禁/重置密码/积分调整（统一操作弹窗）
 * 变量：$users $me
 */
$users = $users ?? [];
$me = $me ?? [];
$meId = (int) ($me['id'] ?? 0);
$pointsEnabled = !empty($pointsEnabled);
$roleLabel = static function (string $role): string {
    return match ($role) {
        'ADMIN' => '管理员',
        'EDITOR' => '编辑',
        default => '用户',
    };
};
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">用户管理</h1>
      <p class="admin-page-sub">共 <?= count($users) ?> 个用户</p>
    </div>
  </div>

  <div class="admin-user-toolbar" role="search">
    <input type="search" class="input" id="adminUserSearch" placeholder="搜索昵称、用户名或邮箱…" autocomplete="off">
    <select class="input" id="adminUserRole" aria-label="筛选角色">
      <option value="">全部角色</option>
      <option value="ADMIN">管理员</option>
      <option value="EDITOR">编辑</option>
      <option value="USER">用户</option>
    </select>
    <select class="input" id="adminUserState" aria-label="筛选状态">
      <option value="">全部状态</option>
      <option value="active">正常</option>
      <option value="disabled">已禁用</option>
    </select>
  </div>

  <?php if ($users === []): ?>
    <div class="admin-empty-list card">暂无用户</div>
  <?php else: ?>
    <div class="admin-table-wrap admin-user-table-wrap">
      <table class="admin-table admin-user-table">
        <thead>
          <tr>
            <th>用户</th>
            <th>角色</th>
            <th>注册时间</th>
            <th>内容</th>
            <?php if ($pointsEnabled): ?><th>积分</th><?php endif; ?>
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
            ?>
            <tr class="admin-user-row" data-id="<?= $uid ?>" data-search="<?= e(mb_strtolower(implode(' ', [(string) ($u['nickname'] ?? ''), (string) ($u['username'] ?? ''), (string) ($u['email'] ?? '')]))) ?>" data-role="<?= e((string) $u['role']) ?>" data-state="<?= $disabled ? 'disabled' : 'active' ?>">
              <td data-label="用户">
                <div class="admin-user-main">
                  <div class="admin-user-name-row">
                    <img class="admin-user-avatar-lg" src="<?= e($avatar) ?>" alt="" width="32" height="32">
                    <span class="admin-user-name"><?= e((string) ($u['nickname'] ?: $u['username'])) ?></span>
                    <?php if ($disabled): ?><span class="badge badge-danger">已禁用</span><?php endif; ?>
                  </div>
                  <div class="admin-muted admin-user-subline">@<?= e($u['username']) ?> · <?= e($u['email']) ?></div>
                </div>
              </td>
              <td data-label="角色">
                <select class="input admin-role-select" aria-label="角色">
                  <?php foreach (['ADMIN' => '管理员', 'EDITOR' => '编辑', 'USER' => '用户'] as $val => $label): ?>
                    <option value="<?= $val ?>" <?= (string) $u['role'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td data-label="注册时间" class="admin-user-date"><?= e(date('Y-m-d', strtotime((string) $u['created_at']))) ?></td>
              <td data-label="内容" class="admin-user-counts"><span><?= (int) $u['post_count'] ?> 篇文章</span><span><?= (int) $u['comment_count'] ?> 条评论</span></td>
              <?php if ($pointsEnabled): ?><td data-label="积分" class="admin-user-points-value"><?= (int) ($u['points_balance'] ?? 0) ?></td><?php endif; ?>
              <td data-label="操作" class="admin-col-ops">
                <div class="admin-user-ops">
                  <?php if ($isMe): ?>
                    <span class="admin-muted admin-current-account">当前账号</span>
                  <?php else: ?>
                    <?php if ($disabled): ?>
                      <button type="button" class="btn btn-outline admin-user-unban">解禁</button>
                    <?php else: ?>
                      <button type="button" class="btn btn-outline admin-user-ban">禁用</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline admin-user-more" data-user-action="more">更多操作</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="admin-modal-backdrop" id="adminUserActionModal" hidden>
  <div class="admin-modal admin-user-action-modal" role="dialog" aria-modal="true" aria-labelledby="adminUserActionTitle">
    <div class="admin-modal-head">
      <h2 class="admin-modal-title" id="adminUserActionTitle">用户操作</h2>
      <button type="button" class="admin-icon-btn" data-modal-close aria-label="关闭">×</button>
    </div>
    <div class="admin-modal-body">
      <p class="admin-modal-hint" id="adminUserActionHint"></p>
      <div class="admin-user-action-tabs" role="tablist">
        <button type="button" class="admin-user-action-tab active" data-user-mode="password">重置密码</button>
        <?php if ($pointsEnabled): ?><button type="button" class="admin-user-action-tab" data-user-mode="points">调整积分</button><?php endif; ?>
      </div>
      <div data-user-panel="password">
        <label class="label" for="adminUserNewPassword">新密码</label>
        <input type="password" class="input" id="adminUserNewPassword" autocomplete="new-password" maxlength="72" placeholder="6-72 位">
      </div>
      <?php if ($pointsEnabled): ?><div data-user-panel="points" hidden>
        <label class="label" for="adminUserPointsAmount">调整数量</label>
        <input type="number" class="input" id="adminUserPointsAmount" placeholder="正数发放，负数扣减">
        <label class="label" for="adminUserPointsReason">原因</label>
        <input class="input" id="adminUserPointsReason" maxlength="120" placeholder="管理员调整">
      </div><?php endif; ?>
      <p class="admin-modal-error" id="adminUserActionError" hidden></p>
    </div>
    <div class="admin-modal-actions">
      <button type="button" class="btn btn-ghost" data-modal-close>取消</button>
      <button type="button" class="btn btn-primary" id="adminUserActionSave">保存</button>
    </div>
  </div>
</div>

<?php $usersCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($usersCsrf) ?>;

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

  // 角色下拉：变更即确认后提交，成功刷新列表
  document.querySelectorAll(".admin-role-select").forEach(function (sel) {
    sel.dataset.original = sel.value;
    sel.addEventListener("change", function () {
      var row = sel.closest(".admin-user-row");
      var isMe = row.querySelector(".admin-current-account") !== null;
      var roleText = sel.options[sel.selectedIndex].textContent;
      var prompt = isMe
        ? "确定将自己的用户组改为「" + roleText + "」吗？\n修改后权限立即变化，请谨慎操作。"
        : "确定将该用户的用户组改为「" + roleText + "」吗？";
      (window.pafishConfirm ? window.pafishConfirm(prompt, { title: "修改用户组" }) : Promise.resolve(window.confirm(prompt))).then(function (ok) {
        if (!ok) { sel.value = sel.dataset.original; return; }
        var fd = new FormData();
        fd.append("role", sel.value);
        fd.append("_csrf", CSRF);
        post("/admin/users/" + row.dataset.id + "/role", fd, function () {
          pafishToastReload("用户角色已更新", "success");
        });
      });
    });
  });

  // 禁用确认
  document.querySelectorAll(".admin-user-ban").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-user-row");
      var name = row.querySelector(".admin-user-name").textContent.trim();
      (window.pafishConfirm ? window.pafishConfirm("确定禁用用户「" + name + "」？", { title: "禁用用户" }) : Promise.resolve(window.confirm("确定禁用该用户？"))).then(function (ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append("_csrf", CSRF);
        post("/admin/users/" + row.dataset.id + "/toggle", fd, function () {
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
      post("/admin/users/" + row.dataset.id + "/toggle", fd, function () {
        pafishToastReload("用户已解禁", "success");
      });
    });
  });

  var actionModal = document.getElementById("adminUserActionModal");
  var actionMode = "password";
  var actionRow = null;
  var actionError = document.getElementById("adminUserActionError");
  function closeActionModal() {
    actionModal.hidden = true;
    if (window.pafishModalSync) window.pafishModalSync();
  }
  function setActionMode(mode) {
    actionMode = mode;
    document.querySelectorAll(".admin-user-action-tab").forEach(function (tab) { tab.classList.toggle("active", tab.dataset.userMode === mode); });
    document.querySelectorAll("[data-user-panel]").forEach(function (panel) { panel.hidden = panel.dataset.userPanel !== mode; });
    actionError.hidden = true;
  }
  document.querySelectorAll(".admin-user-action-tab").forEach(function (tab) { tab.addEventListener("click", function () { setActionMode(tab.dataset.userMode); }); });
  document.querySelectorAll(".admin-user-more").forEach(function (btn) {
    btn.addEventListener("click", function () {
      actionRow = btn.closest(".admin-user-row");
      document.getElementById("adminUserActionHint").textContent = "正在操作：" + (actionRow.querySelector(".admin-user-name").textContent || "用户");
      document.getElementById("adminUserNewPassword").value = "";
      <?php if ($pointsEnabled): ?>document.getElementById("adminUserPointsAmount").value = ""; document.getElementById("adminUserPointsReason").value = "";<?php endif; ?>
      setActionMode("password");
      actionModal.hidden = false;
      if (window.pafishModalSync) window.pafishModalSync();
      document.getElementById("adminUserNewPassword").focus();
    });
  });
  document.getElementById("adminUserActionSave").addEventListener("click", function () {
    if (!actionRow) return;
    var fd = new FormData();
    fd.append("_csrf", CSRF);
    var endpoint = "reset-password";
    if (actionMode === "password") {
      var password = document.getElementById("adminUserNewPassword").value;
      if (password.length < 6 || password.length > 72) { actionError.textContent = "密码长度需 6-72 位"; actionError.hidden = false; return; }
      fd.append("password", password);
    } else {
      endpoint = "points";
      var amount = document.getElementById("adminUserPointsAmount").value;
      if (!amount || Number(amount) === 0) { actionError.textContent = "请输入非零积分"; actionError.hidden = false; return; }
      fd.append("amount", amount);
      fd.append("reason", document.getElementById("adminUserPointsReason").value);
    }
    post("/admin/users/" + actionRow.dataset.id + "/" + endpoint, fd, function () {
      closeActionModal();
      pafishToastReload(actionMode === "password" ? "密码已重置" : "积分已调整", "success");
    });
  });

  var userSearch = document.getElementById("adminUserSearch");
  var userRole = document.getElementById("adminUserRole");
  var userState = document.getElementById("adminUserState");
  function filterUsers() {
    var q = (userSearch.value || "").trim().toLowerCase();
    var role = userRole.value;
    var state = userState.value;
    document.querySelectorAll(".admin-user-row").forEach(function (row) {
      row.hidden = !!((q && (row.dataset.search || "").indexOf(q) < 0) || (role && row.dataset.role !== role) || (state && row.dataset.state !== state));
    });
  }
  [userSearch, userRole, userState].forEach(function (el) { if (el) el.addEventListener("input", filterUsers); });
})();
</script>
