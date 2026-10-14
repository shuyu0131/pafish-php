<?php
$user = $user ?? [];
$me = $me ?? [];
$pointsEnabled = !empty($pointsEnabled);
$isFounder = !empty($isFounder);
$userId = (int) ($user['id'] ?? 0);
$avatar = !empty($user['avatar_url']) ? (string) $user['avatar_url'] : admin_gravatar((string) ($user['email'] ?? ''));
$formatDate = static function (mixed $value): string {
    if (!$value) {
        return '暂无记录';
    }
    $time = strtotime((string) $value);
    return $time === false ? '暂无记录' : date('Y-m-d H:i', $time);
};
$displayName = (string) (($user['nickname'] ?? '') ?: ($user['username'] ?? '用户'));
?>
<div class="admin-stack admin-user-edit-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">编辑用户</h1>
      <p class="admin-page-sub"><?= e($displayName) ?> · ID <?= $userId ?></p>
    </div>
    <div class="admin-head-actions"><a class="btn btn-ghost" href="<?= e(url_to('/admin/users')) ?>">返回用户列表</a></div>
  </div>

  <div class="admin-user-edit-grid">
    <div class="admin-user-edit-main">
      <div class="admin-user-profile-panel">
        <div class="admin-user-edit-card-head">
          <h2 class="admin-card-title">账号资料</h2>
          <?php if ((int) ($user['disabled'] ?? 0) === 1): ?><span class="badge badge-danger">已禁用</span><?php else: ?><span class="badge badge-success">正常</span><?php endif; ?>
        </div>
        <form class="admin-form-grid" method="post" action="<?= e(url_to('/admin/users/' . $userId . '/save')) ?>" novalidate>
          <?= csrf_field() ?>
          <div class="admin-field admin-user-avatar-field">
            <div class="admin-avatar-edit">
              <img class="admin-avatar-preview" id="adminUserAvatarPreview" src="<?= e($avatar) ?>" alt="" width="48" height="48">
              <div class="admin-user-avatar-controls">
                <label class="label" for="adminUserAvatarUrl">头像地址</label>
                <input class="input" type="text" id="adminUserAvatarUrl" name="avatar_url" value="<?= e((string) ($user['avatar_url'] ?? '')) ?>" maxlength="500" placeholder="/uploads/… 或 https://…">
                <div class="admin-user-avatar-hint"><span class="admin-field-hint">留空使用默认头像。</span><button type="button" class="btn btn-ghost btn-sm" id="adminUserAvatarClear">恢复默认</button></div>
              </div>
            </div>
          </div>
          <div class="admin-field"><span class="label">用户名（登录名）</span><input class="input" type="text" name="username" value="<?= e((string) ($user['username'] ?? '')) ?>" maxlength="50" required><p class="admin-field-hint">2-50 位，可使用中文、字母、数字、下划线和连字符。</p></div>
          <div class="admin-field"><span class="label">显示昵称</span><input class="input" type="text" name="nickname" value="<?= e((string) ($user['nickname'] ?? '')) ?>" maxlength="50" placeholder="留空则显示用户名"></div>
          <div class="admin-field"><span class="label">邮箱</span><input class="input" type="email" name="email" value="<?= e((string) ($user['email'] ?? '')) ?>" maxlength="255" required></div>
          <div class="admin-field"><span class="label">角色</span>
            <?php if ($isFounder): ?><input type="hidden" name="role" value="ADMIN"><select class="input" disabled><option selected>管理员（创始人账号）</option></select><p class="admin-field-hint">创始人账号的角色不可修改。</p>
            <?php else: ?><select class="input" name="role"><option value="USER"<?= ($user['role'] ?? '') === 'USER' ? ' selected' : '' ?>>用户</option><option value="EDITOR"<?= ($user['role'] ?? '') === 'EDITOR' ? ' selected' : '' ?>>编辑</option><option value="ADMIN"<?= ($user['role'] ?? '') === 'ADMIN' ? ' selected' : '' ?>>管理员</option></select><?php endif; ?>
          </div>
          <div class="admin-field admin-user-bio-field"><span class="label">个人简介</span><textarea class="input admin-user-description" name="description" maxlength="500" rows="3" placeholder="介绍一下这个用户"><?= e((string) ($user['description'] ?? '')) ?></textarea></div>
          <details class="admin-user-password-section"><summary>设置新密码</summary><p class="admin-field-hint">留空表示不修改密码。密码长度为 6-72 位。</p><div class="admin-form-grid">
          <div class="admin-field"><span class="label">新密码</span><input class="input" type="password" name="password" autocomplete="new-password" minlength="6" maxlength="72"></div>
          <div class="admin-field"><span class="label">确认新密码</span><input class="input" type="password" name="password_confirm" autocomplete="new-password" minlength="6" maxlength="72"></div>
          </div></details>
          <div class="admin-form-actions"><button type="submit" class="btn btn-primary">保存用户</button><a class="btn btn-ghost" href="<?= e(url_to('/admin/users')) ?>">取消</a></div>
        </form>
      </div>
    </div>

    <aside class="admin-user-edit-side">
      <div class="admin-user-info-card">
        <h2 class="admin-card-title">账号信息</h2>
        <dl class="admin-user-info-list">
          <div><dt>用户 ID</dt><dd><?= $userId ?></dd></div>
          <div><dt>注册时间</dt><dd><?= e($formatDate($user['created_at'] ?? null)) ?></dd></div>
          <div><dt>最近登录 IP</dt><dd><?= e((string) ($user['last_login_ip'] ?? '暂无记录')) ?></dd></div>
          <div><dt>最近活动</dt><dd><?= e($formatDate($user['last_active_at'] ?? null)) ?></dd></div>
          <div><dt>文章</dt><dd><a href="<?= e(url_to('/admin/posts?author=' . $userId)) ?>"><?= (int) ($user['post_count'] ?? 0) ?> 篇</a></dd></div>
          <div><dt>评论</dt><dd><?= (int) ($user['comment_count'] ?? 0) ?> 条</dd></div>
          <?php if ($pointsEnabled): ?><div><dt>积分</dt><dd><?= (int) ($user['points_balance'] ?? 0) ?> 分</dd></div><?php endif; ?>
        </dl>
      </div>

      <?php if ($pointsEnabled): ?>
      <details class="admin-user-points-card">
        <summary>调整积分</summary>
        <form class="admin-form-grid" method="post" action="<?= e(url_to('/admin/users/' . $userId . '/points')) ?>">
          <?= csrf_field() ?>
          <div class="admin-field"><span class="label">调整数量</span><input class="input" type="number" name="amount" min="-100000000" max="100000000" step="1" placeholder="正数发放，负数扣减" required></div>
          <div class="admin-field"><span class="label">原因</span><input class="input" type="text" name="reason" maxlength="120" value="管理员调整"></div>
          <div class="admin-form-actions"><button class="btn btn-outline" type="submit">调整积分</button></div>
        </form>
      </details>
      <?php endif; ?>

      <?php if (!$isFounder && $userId !== (int) ($me['id'] ?? 0)): ?>
      <div class="admin-user-danger-card">
        <h2 class="admin-card-title">危险操作</h2>
        <p>删除后，该用户的文章和微语会转移给当前管理员。</p>
        <form id="adminUserDeleteForm" method="post" action="<?= e(url_to('/admin/users/' . $userId . '/delete')) ?>">
          <?= csrf_field() ?>
          <button class="btn btn-danger" type="submit">删除用户</button>
        </form>
      </div>
      <?php else: ?><p class="admin-user-protection-note"><?= $isFounder ? '创始人账号受保护，不能删除。' : '当前登录账号不能删除。' ?></p><?php endif; ?>
    </aside>
  </div>
</div>

<script>
(function () {
  "use strict";
  var avatarInput = document.getElementById("adminUserAvatarUrl");
  var avatarPreview = document.getElementById("adminUserAvatarPreview");
  var fallback = <?= json_encode(admin_gravatar((string) ($user['email'] ?? ''))) ?>;
  if (avatarInput && avatarPreview) {
    avatarInput.addEventListener("input", function () { avatarPreview.src = avatarInput.value.trim() || fallback; });
    document.getElementById("adminUserAvatarClear").addEventListener("click", function () { avatarInput.value = ""; avatarPreview.src = fallback; });
  }
  var deleteForm = document.getElementById("adminUserDeleteForm");
  if (deleteForm) {
    deleteForm.addEventListener("submit", function (event) {
      event.preventDefault();
      var task = window.pafishConfirm
        ? window.pafishConfirm("确定删除用户及其账号吗？文章和微语会转移给当前管理员。", { title: "删除用户" })
        : Promise.resolve(window.confirm("确定删除该用户吗？"));
      task.then(function (ok) { if (ok) deleteForm.submit(); });
    });
  }
})();
</script>
