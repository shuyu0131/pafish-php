<?php
$user = $user ?? [];
$me = $me ?? [];
$pointsEnabled = !empty($pointsEnabled);
$isFounder = !empty($isFounder);
$userId = (int) ($user['id'] ?? 0);
$avatar = !empty($user['avatar_url']) ? (string) $user['avatar_url'] : admin_gravatar((string) ($user['email'] ?? ''));
$formatDate = static function (mixed $value): string {
    if (!$value) {
        return t('admin.no_records_4f9ba2');
    }
    $time = strtotime((string) $value);
    return $time === false ? t('admin.no_records_4f9ba2') : date('Y-m-d H:i', $time);
};
$displayName = (string) (($user['nickname'] ?? '') ?: ($user['username'] ?? t('common.user')));
?>
<div class="admin-stack admin-user-edit-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e(t('admin.edit_user')) ?></h1>
      <p class="admin-page-sub"><?= e($displayName) ?> · ID <?= $userId ?></p>
    </div>
    <div class="admin-head-actions"><a class="btn btn-ghost" href="<?= e(url_to('/admin/users')) ?>"><?= e(t('admin.back_to_users')) ?></a></div>
  </div>

  <div class="admin-user-edit-grid">
    <div class="admin-user-edit-main">
      <div class="admin-user-profile-panel">
        <div class="admin-user-edit-card-head">
          <h2 class="admin-card-title"><?= e(t('admin.account_profile')) ?></h2>
          <?php if ((int) ($user['disabled'] ?? 0) === 1): ?><span class="badge badge-danger"><?= e(t('admin.disabled')) ?></span><?php else: ?><span class="badge badge-success"><?= e(t('admin.normal')) ?></span><?php endif; ?>
        </div>
        <form class="admin-form-grid" method="post" action="<?= e(url_to('/admin/users/' . $userId . '/save')) ?>" novalidate>
          <?= csrf_field() ?>
          <div class="admin-field admin-user-avatar-field">
            <div class="admin-avatar-edit">
              <img class="admin-avatar-preview" id="adminUserAvatarPreview" src="<?= e($avatar) ?>" alt="" width="48" height="48">
              <div class="admin-user-avatar-controls">
                <label class="label" for="adminUserAvatarUrl"><?= e(t('admin.avatar_url')) ?></label>
                <input class="input" type="text" id="adminUserAvatarUrl" name="avatar_url" value="<?= e((string) ($user['avatar_url'] ?? '')) ?>" maxlength="500" placeholder="<?= e(t('admin.uploads_or_https')) ?>">
                <div class="admin-user-avatar-hint"><span class="admin-field-hint"><?= e(t('admin.leave_blank_to_use_the_default_avatar')) ?></span><button type="button" class="btn btn-ghost btn-sm" id="adminUserAvatarClear"><?= e(t('admin.reset_to_defaults')) ?></button></div>
              </div>
            </div>
          </div>
          <div class="admin-field"><span class="label"><?= e(t('admin.username_sign_in_name')) ?></span><input class="input" type="text" name="username" value="<?= e((string) ($user['username'] ?? '')) ?>" maxlength="50" required><p class="admin-field-hint"><?= e(t('admin.2_50_characters_chinese_characters_letters_numbers_underscores_b242a1')) ?></p></div>
          <div class="admin-field"><span class="label"><?= e(t('admin.display_name')) ?></span><input class="input" type="text" name="nickname" value="<?= e((string) ($user['nickname'] ?? '')) ?>" maxlength="50" placeholder="<?= e(t('admin.leave_blank_to_display_the_username')) ?>"></div>
          <div class="admin-field"><span class="label"><?= e(t('admin.email')) ?></span><input class="input" type="email" name="email" value="<?= e((string) ($user['email'] ?? '')) ?>" maxlength="255" required></div>
          <div class="admin-field"><span class="label"><?= e(t('admin.role')) ?></span>
            <?php if ($isFounder): ?><input type="hidden" name="role" value="ADMIN"><select class="input" disabled><option selected><?= e(t('admin.administrator_founder')) ?></option></select><p class="admin-field-hint"><?= e(t('admin.the_founder_s_role_cannot_be_changed_f4aa69')) ?></p>
            <?php else: ?><select class="input" name="role"><option value="USER"<?= ($user['role'] ?? '') === 'USER' ? ' selected' : '' ?>><?= e(t('common.user')) ?></option><option value="EDITOR"<?= ($user['role'] ?? '') === 'EDITOR' ? ' selected' : '' ?>><?= e(t('common.editor')) ?></option><option value="ADMIN"<?= ($user['role'] ?? '') === 'ADMIN' ? ' selected' : '' ?>><?= e(t('admin.administrator')) ?></option></select><?php endif; ?>
          </div>
          <div class="admin-field admin-user-bio-field"><span class="label"><?= e(t('admin.bio')) ?></span><textarea class="input admin-user-description" name="description" maxlength="500" rows="3" placeholder="<?= e(t('admin.introduce_this_user')) ?>"><?= e((string) ($user['description'] ?? '')) ?></textarea></div>
          <details class="admin-user-password-section"><summary><?= e(t('admin.set_new_password')) ?></summary><p class="admin-field-hint"><?= e(t('admin.leave_blank_to_keep_the_password_passwords_must')) ?></p><div class="admin-form-grid">
          <div class="admin-field"><span class="label"><?= e(t('admin.new_password')) ?></span><input class="input" type="password" name="password" autocomplete="new-password" minlength="6" maxlength="72"></div>
          <div class="admin-field"><span class="label"><?= e(t('admin.confirm_new_password')) ?></span><input class="input" type="password" name="password_confirm" autocomplete="new-password" minlength="6" maxlength="72"></div>
          </div></details>
          <div class="admin-form-actions"><button type="submit" class="btn btn-primary"><?= e(t('admin.save_user')) ?></button><a class="btn btn-ghost" href="<?= e(url_to('/admin/users')) ?>"><?= e(t('common.cancel')) ?></a></div>
        </form>
      </div>
    </div>

    <aside class="admin-user-edit-side">
      <div class="admin-user-info-card">
        <h2 class="admin-card-title"><?= e(t('admin.account_information')) ?></h2>
        <dl class="admin-user-info-list">
          <div><dt><?= e(t('admin.user_id')) ?></dt><dd><?= $userId ?></dd></div>
          <div><dt><?= e(t('admin.registered_525ad5')) ?></dt><dd><?= e($formatDate($user['created_at'] ?? null)) ?></dd></div>
          <div><dt><?= e(t('admin.last_login_ip')) ?></dt><dd><?= e((string) ($user['last_login_ip'] ?? t('admin.no_records_4f9ba2'))) ?></dd></div>
          <div><dt><?= e(t('admin.last_active')) ?></dt><dd><?= e($formatDate($user['last_active_at'] ?? null)) ?></dd></div>
          <div><dt><?= e(t('common.post')) ?></dt><dd><a href="<?= e(url_to('/admin/posts?author=' . $userId)) ?>"><?= (int) ($user['post_count'] ?? 0) ?> <?= e(t('admin.posts_69007d')) ?></a></dd></div>
          <div><dt><?= e(t('common.comments')) ?></dt><dd><?= (int) ($user['comment_count'] ?? 0) ?> <?= e(t('admin.items')) ?></dd></div>
          <?php if ($pointsEnabled): ?><div><dt><?= e(t('admin.points')) ?></dt><dd><?= (int) ($user['points_balance'] ?? 0) ?> <?= e(t('admin.points_0d7416')) ?></dd></div><?php endif; ?>
        </dl>
      </div>

      <?php if ($pointsEnabled): ?>
      <details class="admin-user-points-card">
        <summary><?= e(t('admin.adjust_points')) ?></summary>
        <form class="admin-form-grid" method="post" action="<?= e(url_to('/admin/users/' . $userId . '/points')) ?>">
          <?= csrf_field() ?>
          <div class="admin-field"><span class="label"><?= e(t('admin.adjustment_amount')) ?></span><input class="input" type="number" name="amount" min="-100000000" max="100000000" step="1" placeholder="<?= e(t('admin.positive_values_add_points_negative_values_deduct_them')) ?>" required></div>
          <div class="admin-field"><span class="label"><?= e(t('admin.reason')) ?></span><input class="input" type="text" name="reason" maxlength="120" value="<?= e(t('admin.administrator_adjustment')) ?>"></div>
          <div class="admin-form-actions"><button class="btn btn-outline" type="submit"><?= e(t('admin.adjust_points')) ?></button></div>
        </form>
      </details>
      <?php endif; ?>

      <?php if (!$isFounder && $userId !== (int) ($me['id'] ?? 0)): ?>
      <div class="admin-user-danger-card">
        <h2 class="admin-card-title"><?= e(t('admin.danger_zone')) ?></h2>
        <p><?= e(t('admin.their_posts_and_notes_will_be_transferred_to')) ?></p>
        <form id="adminUserDeleteForm" method="post" action="<?= e(url_to('/admin/users/' . $userId . '/delete')) ?>">
          <?= csrf_field() ?>
          <button class="btn btn-danger" type="submit"><?= e(t('admin.delete_user')) ?></button>
        </form>
      </div>
      <?php else: ?><p class="admin-user-protection-note"><?= $isFounder ? t('admin.the_founder_s_account_is_protected_and_cannot') : t('admin.you_cannot_delete_your_own_account_9d5913') ?></p><?php endif; ?>
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
        ? window.pafishConfirm(window.pafishT("js.delete_this_user_and_account_their_posts_and"), { title: window.pafishT("js.delete_user") })
        : Promise.resolve(window.confirm(window.pafishT("js.delete_this_user")));
      task.then(function (ok) { if (ok) deleteForm.submit(); });
    });
  }
})();
</script>
