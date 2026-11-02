<?php
/**
 * 评论表单（系统默认模板；主题可覆盖 themes/{active}/comment-form.php）
 * 评论表单：
 * - 已登录用户：自动使用登录身份，隐藏昵称/邮箱输入（服务端强制，不可伪造）
 * - 未登录游客：昵称/邮箱/网址（邮箱和网址可选） + 图形验证码（后台可关），SVG 点击刷新
 * - 游客资料可选择记住在当前浏览器中
 * - 回复评论时 compact 模式（嵌套在楼中楼里）
 * 可用数据：$postId、$parentId（回复目标，可空）、$compact、$user（登录用户数组或 null）、
 *           $captchaEnabled、$needReview
 */
$postId = (int) ($postId ?? 0);
$targetType = (string) ($targetType ?? 'post');
$targetId = (int) ($targetId ?? $postId);
$parentId = $parentId !== null ? (string) $parentId : '';
$compact = !empty($compact);
$user = $user ?? null;
$captchaEnabled = $captchaEnabled ?? true;
$needReview = $needReview ?? true;
$loggedIn = $user !== null;
$needCaptcha = !$loggedIn && $captchaEnabled;
$rememberName = !$loggedIn ? mb_substr(trim((string) ($_COOKIE['pafish_comment_name'] ?? '')), 0, 50) : '';
$rememberEmail = !$loggedIn ? mb_substr(trim((string) ($_COOKIE['pafish_comment_email'] ?? '')), 0, 255) : '';
$rememberUrl = !$loggedIn ? mb_substr(trim((string) ($_COOKIE['pafish_comment_url'] ?? '')), 0, 500) : '';
?>
<form class="comment-form<?= $compact ? ' compact' : '' ?>"
      data-comment-form
      data-post-id="<?= $postId ?>"
      data-target-type="<?= e($targetType) ?>"
      data-target-id="<?= $targetId ?>"
      data-parent-id="<?= e($parentId) ?>"
      data-logged-in="<?= $loggedIn ? '1' : '0' ?>">
  <?php if ($loggedIn): ?>
    <p class="comment-as">
      以 <span class="comment-as-name"><?= e($user['nickname'] ?: $user['username']) ?></span> 的身份评论
      （<?= e($user['username']) ?>）
    </p>
  <?php else: ?>
    <div class="comment-fields">
      <input class="input" name="name" value="<?= e($rememberName) ?>" placeholder="昵称 *" maxlength="50" autocomplete="nickname" required>
      <input class="input" name="email" type="email" value="<?= e($rememberEmail) ?>" placeholder="邮箱（不会公开显示，可选）" maxlength="255" autocomplete="email">
      <input class="input" name="url" type="url" value="<?= e($rememberUrl) ?>" placeholder="个人网站（可选）" maxlength="500" autocomplete="url">
    </div>
  <?php endif; ?>

  <div class="comment-honeypot" aria-hidden="true"><label>网站<input name="website" type="text" tabindex="-1" autocomplete="off"></label></div>

  <textarea class="input comment-textarea" name="content" placeholder="写下你的想法…" maxlength="2000" required></textarea>

  <?php $commentInjectContext = [
      'postId' => $postId,
      'targetType' => $targetType,
      'targetId' => $targetId,
      'parentId' => $parentId !== '' ? $parentId : null,
      'compact' => $compact,
      'loggedIn' => $loggedIn,
  ]; ?>
  <?= \Pafish\Services\Theme::renderInjection('comment_form', $commentInjectContext) ?>
  <?= \Pafish\Services\Plugin::renderInjection('comment_form', $commentInjectContext) ?>

  <?php if ($needCaptcha): ?>
    <div class="comment-captcha">
      <button type="button" class="comment-captcha-svg" title="看不清？点击刷新" data-captcha-refresh></button>
      <input class="input comment-captcha-input" name="captchaAnswer" placeholder="验证码 *" maxlength="8" autocomplete="off" required>
      <button type="button" class="comment-captcha-change" data-captcha-refresh>换一张</button>
    </div>
    <p class="comment-captcha-status" data-captcha-status aria-live="polite" hidden></p>
  <?php endif; ?>

  <p class="comment-error" hidden></p>
  <p class="comment-message" hidden></p>

  <div class="comment-form-foot">
    <?php if (!$loggedIn): ?>
      <label class="comment-remember">
        <input type="checkbox" name="remember" value="1" checked>
        <span>记住我的信息</span>
      </label>
    <?php endif; ?>
    <div class="comment-form-actions">
      <?php if ($needReview): ?>
        <p class="comment-review-tip">评论审核后显示</p>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary comment-submit">发表评论</button>
    </div>
  </div>
</form>
