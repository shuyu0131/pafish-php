<?php
/**
 * 评论表单（系统 fallback 模板；主题可覆盖 themes/{active}/comment-form.php）
 * 对齐 Node 版 src/components/comment-form.tsx：
 * - 已登录用户：自动使用登录身份，隐藏昵称/邮箱输入（服务端强制，不可伪造）
 * - 未登录游客：昵称/邮箱 + 图形验证码（后台可关），SVG 点击刷新
 * - 回复评论时 compact 模式（嵌套在楼中楼里）
 * 可用数据：$postId、$parentId（回复目标，可空）、$compact、$user（登录用户数组或 null）、
 *           $captchaEnabled、$needReview
 */
$postId = (int) ($postId ?? 0);
$parentId = $parentId !== null ? (string) $parentId : '';
$compact = !empty($compact);
$user = $user ?? null;
$captchaEnabled = $captchaEnabled ?? true;
$needReview = $needReview ?? true;
$loggedIn = $user !== null;
$needCaptcha = !$loggedIn && $captchaEnabled;
?>
<form class="comment-form<?= $compact ? ' compact' : '' ?>"
      data-comment-form
      data-post-id="<?= $postId ?>"
      data-parent-id="<?= e($parentId) ?>"
      data-logged-in="<?= $loggedIn ? '1' : '0' ?>">
  <?php if ($loggedIn): ?>
    <p class="comment-as">
      以 <span class="comment-as-name"><?= e($user['nickname'] ?: $user['username']) ?></span> 的身份评论
      （<?= e($user['username']) ?>）
    </p>
  <?php else: ?>
    <div class="comment-fields">
      <input class="input" name="name" placeholder="昵称 *" maxlength="50" autocomplete="nickname" required>
      <input class="input" name="email" type="email" placeholder="邮箱（不会公开显示）" maxlength="255" autocomplete="email">
    </div>
  <?php endif; ?>

  <textarea class="input comment-textarea" name="content" placeholder="写下你的想法…" maxlength="2000" required></textarea>

  <?php if ($needCaptcha): ?>
    <div class="comment-captcha">
      <button type="button" class="comment-captcha-svg" title="看不清？点击刷新" data-captcha-refresh></button>
      <input class="input comment-captcha-input" name="captchaAnswer" placeholder="验证码 *" maxlength="8" autocomplete="off" required>
      <button type="button" class="comment-captcha-change" data-captcha-refresh>换一张</button>
    </div>
  <?php endif; ?>

  <p class="comment-error" hidden></p>
  <p class="comment-message" hidden></p>

  <div class="comment-form-foot">
    <label class="comment-notify">
      <input type="checkbox" name="notifyReply" class="comment-notify-check">
      <span>有人回复我时邮件通知</span>
    </label>
    <div class="comment-form-actions">
      <p class="comment-review-tip"><?= $needReview ? '评论需审核后显示' : '评论将直接显示' ?></p>
      <button type="submit" class="btn btn-primary comment-submit">发表评论</button>
    </div>
  </div>
</form>
