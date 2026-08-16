<?php
/**
 * 用户管理（对齐 Node app/admin/users/ 卡片列表）：
 * 全量用户按注册时间正序；每行：头像/昵称/@用户名/角色徽章/已禁用徽章/
 * 邮箱·注册日期·文章数·评论数；操作：角色下拉（含自己）、他人可禁用（两段确认）/
 * 解禁/重置密码（内联表单）
 * 变量：$users $me
 */
$users = $users ?? [];
$me = $me ?? [];
$meId = (int) ($me['id'] ?? 0);
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

  <?php if ($users === []): ?>
    <div class="card admin-empty">暂无用户</div>
  <?php endif; ?>

  <div class="admin-user-list">
    <?php foreach ($users as $u): ?>
      <?php
      $uid = (int) $u['id'];
      $isMe = $uid === $meId;
      $avatar = !empty($u['avatar_url']) ? $u['avatar_url'] : admin_gravatar((string) $u['email']);
      $disabled = ((int) $u['disabled']) === 1;
      ?>
      <div class="card admin-user-row" data-id="<?= $uid ?>">
        <img class="admin-user-avatar-lg" src="<?= e($avatar) ?>" alt="" width="40" height="40">
        <div class="admin-user-main">
          <div class="admin-user-name-row">
            <span class="admin-user-name"><?= e((string) ($u['nickname'] ?: $u['username'])) ?></span>
            <span class="admin-muted">@<?= e($u['username']) ?></span>
            <span class="badge badge-accent"><?= e($roleLabel((string) $u['role'])) ?></span>
            <?php if ($disabled): ?>
              <span class="badge badge-danger">已禁用</span>
            <?php endif; ?>
          </div>
          <p class="admin-muted admin-user-subline">
            <?= e($u['email']) ?> · 注册于 <?= date('Y-m-d', strtotime((string) $u['created_at'])) ?>
            · <?= (int) $u['post_count'] ?> 篇文章 · <?= (int) $u['comment_count'] ?> 条评论
          </p>
        </div>
        <div class="admin-user-ops">
          <select class="input admin-role-select" aria-label="角色">
            <?php foreach (['ADMIN' => '管理员', 'EDITOR' => '编辑', 'USER' => '用户'] as $val => $label): ?>
              <option value="<?= $val ?>" <?= (string) $u['role'] === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($isMe): ?>
            <span class="admin-muted admin-current-account">当前账号</span>
          <?php else: ?>
            <?php if ($disabled): ?>
              <button type="button" class="btn btn-outline admin-user-unban">解禁</button>
            <?php else: ?>
              <button type="button" class="btn btn-outline admin-user-ban" data-state="idle">禁用</button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline admin-user-reset">重置密码</button>
            <div class="admin-user-reset-box" hidden>
              <input type="password" class="input admin-user-newpass" placeholder="新密码（≥6 位）" autocomplete="off" maxlength="72">
              <button type="button" class="btn btn-primary admin-user-reset-save" disabled>保存</button>
              <button type="button" class="btn btn-ghost admin-user-reset-cancel">取消</button>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
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
        else pafishNotify((j && j.error) || "操作失败");
      })
      .catch(function () { pafishNotify("操作失败"); });
  }

  // 角色下拉：变更即确认后提交，成功刷新列表（对齐 Node router.refresh）
  document.querySelectorAll(".admin-role-select").forEach(function (sel) {
    sel.dataset.original = sel.value;
    sel.addEventListener("change", function () {
      var row = sel.closest(".admin-user-row");
      var isMe = row.querySelector(".admin-current-account") !== null;
      var roleText = sel.options[sel.selectedIndex].textContent;
      var prompt = isMe
        ? "确定将自己的用户组改为「" + roleText + "」吗？\n修改后权限立即变化，请谨慎操作。"
        : "确定将该用户的用户组改为「" + roleText + "」吗？";
      if (!window.confirm(prompt)) {
        sel.value = sel.dataset.original;
        return;
      }
      var fd = new FormData();
      fd.append("role", sel.value);
      fd.append("_csrf", CSRF);
      post("/admin/users/" + row.dataset.id + "/role", fd, function () {
        location.reload();
      });
    });
  });

  // 禁用：两段确认（「禁用」→ 红色「确认禁用？」→ 再点提交；2.5s 复位）
  document.querySelectorAll(".admin-user-ban").forEach(function (btn) {
    btn.addEventListener("click", function () {
      if (btn.dataset.state === "confirm") {
        var row = btn.closest(".admin-user-row");
        var fd = new FormData();
        fd.append("_csrf", CSRF);
        post("/admin/users/" + row.dataset.id + "/toggle", fd, function () {
          location.reload();
        });
        return;
      }
      btn.dataset.state = "confirm";
      btn.textContent = "确认禁用？";
      btn.classList.add("btn-danger");
      clearTimeout(btn._t);
      btn._t = setTimeout(function () {
        btn.dataset.state = "idle";
        btn.textContent = "禁用";
        btn.classList.remove("btn-danger");
      }, 2500);
    });
  });

  // 解禁：直接提交
  document.querySelectorAll(".admin-user-unban").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-user-row");
      var fd = new FormData();
      fd.append("_csrf", CSRF);
      post("/admin/users/" + row.dataset.id + "/toggle", fd, function () {
        location.reload();
      });
    });
  });

  // 重置密码：内联表单（新密码 ≥6 位才能保存）
  document.querySelectorAll(".admin-user-reset").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var ops = btn.closest(".admin-user-ops");
      var box = ops.querySelector(".admin-user-reset-box");
      box.hidden = !box.hidden;
      if (!box.hidden) {
        var input = box.querySelector(".admin-user-newpass");
        input.value = "";
        box.querySelector(".admin-user-reset-save").disabled = true;
        input.focus();
      }
    });
  });
  document.querySelectorAll(".admin-user-reset-cancel").forEach(function (btn) {
    btn.addEventListener("click", function () {
      btn.closest(".admin-user-reset-box").hidden = true;
    });
  });
  document.querySelectorAll(".admin-user-newpass").forEach(function (input) {
    input.addEventListener("input", function () {
      var box = input.closest(".admin-user-reset-box");
      box.querySelector(".admin-user-reset-save").disabled = input.value.length < 6;
    });
  });
  document.querySelectorAll(".admin-user-reset-save").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var box = btn.closest(".admin-user-reset-box");
      var row = btn.closest(".admin-user-row");
      var fd = new FormData();
      fd.append("password", box.querySelector(".admin-user-newpass").value);
      fd.append("_csrf", CSRF);
      post("/admin/users/" + row.dataset.id + "/reset-password", fd, function () {
        box.hidden = true;
      });
    });
  });
})();
</script>
