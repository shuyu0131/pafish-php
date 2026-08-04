<?php
/**
 * 评论区容器（系统 fallback 模板；主题可覆盖 themes/{active}/comment-section.php）
 * 对齐 Node 版 src/components/comment-section.tsx：
 * 顶部表单 → 楼中楼树 → 顶层评论分页（?cpage=N#comments）
 * 可用数据：$postId、$commentPage、$commentRoots、$commentTotal、$commentTotalPages、
 *           $needReview、$captchaEnabled（comments_captcha_enabled）、$user
 */
$postId = (int) ($postId ?? 0);
$commentPage = max(1, (int) ($commentPage ?? 1));
$commentTotal = (int) ($commentTotal ?? 0);
$commentTotalPages = max(1, (int) ($commentTotalPages ?? 1));
$needReview = $needReview ?? true;
$captchaEnabled = $captchaEnabled ?? true;
$user = $user ?? null;
$baseUrl = \url_to('/post/' . rawurlencode((string) ($post['slug'] ?? '')));
$sep = str_contains($baseUrl, '?') ? '&' : '?';
?>
<section id="comments" class="comment-section" data-comment-section data-need-review="<?= $needReview ? '1' : '0' ?>">
  <h2 class="comment-heading">评论 <span class="comment-total">(<?= $commentTotal ?>)</span></h2>

  <?= render_partial('comment-form', [
      'postId' => $postId,
      'parentId' => null,
      'user' => $user,
      'captchaEnabled' => $captchaEnabled,
      'needReview' => $needReview,
  ]) ?>

  <?= render_partial('comment-thread', [
      'nodes' => $commentRoots ?? [],
      'postId' => $postId,
      'user' => $user,
      'captchaEnabled' => $captchaEnabled,
      'needReview' => $needReview,
  ]) ?>

  <?php if ($commentTotalPages > 1): ?>
    <nav class="comment-pager" aria-label="评论分页">
      <?php if ($commentPage > 1): ?>
        <a class="btn btn-sm" href="<?= e($baseUrl . $sep . 'cpage=' . ($commentPage - 1) . '#comments') ?>">上一页</a>
      <?php endif; ?>
      <span class="comment-pager-now"><?= $commentPage ?> / <?= $commentTotalPages ?></span>
      <?php if ($commentPage < $commentTotalPages): ?>
        <a class="btn btn-sm" href="<?= e($baseUrl . $sep . 'cpage=' . ($commentPage + 1) . '#comments') ?>">下一页</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
</section>

<script>
/* 评论区交互（对齐 Node comment-form/comment-thread 组件行为）：
 * 验证码获取/刷新、提交、点赞乐观更新、回复展开收起 */
(function () {
  'use strict';

  function postJSON(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(function (r) { return r.json(); });
  }

  // ---- 验证码：每个表单独立获取（游客 + 开启验证码时） ----
  function needCaptcha(form) {
    return form.dataset.loggedIn !== '1' && form.querySelector('[data-captcha-refresh]') !== null;
  }
  function fetchCaptcha(form, then) {
    return fetch(pafishApi('/captcha'))
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d && document.body.contains(form)) {
          form.dataset.captchaToken = d.token;
          var box = form.querySelector('.comment-captcha-svg');
          if (box) box.innerHTML = d.svg;
          var input = form.querySelector('[name="captchaAnswer"]');
          if (input) input.value = '';
          if (then) then();
        }
      })
      .catch(function () {});
  }
  function refreshCaptcha(form) {
    fetchCaptcha(form);
  }

  // ---- 提交 ----
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches('[data-comment-form]')) return;
    e.preventDefault();

    var postId = form.dataset.postId;
    var parentId = form.dataset.parentId || null;
    var errorEl = form.querySelector('.comment-error');
    var messageEl = form.querySelector('.comment-message');
    var submitBtn = form.querySelector('.comment-submit');
    var section = document.querySelector('[data-comment-section]');
    var needReview = section ? section.dataset.needReview === '1' : true;

    function showError(msg) {
      errorEl.textContent = msg;
      errorEl.hidden = false;
      messageEl.hidden = true;
    }
    errorEl.hidden = true;
    messageEl.hidden = true;

    var body = {
      postId: postId,
      parentId: parentId,
      content: form.querySelector('[name="content"]').value,
      notifyReply: !!(form.querySelector('[name="notifyReply"]') || {}).checked,
    };
    if (form.dataset.loggedIn === '1') {
      // 已登录：身份由服务端强制，不提交昵称邮箱
    } else {
      body.name = form.querySelector('[name="name"]').value;
      body.email = form.querySelector('[name="email"]').value;
      body.captchaToken = form.dataset.captchaToken || '';
      body.captchaAnswer = (form.querySelector('[name="captchaAnswer"]') || {}).value || '';
    }

    submitBtn.disabled = true;
    var label = submitBtn.textContent;
    submitBtn.textContent = '提交中…';

    postJSON(pafishApi('/comments'), body).then(function (res) {
      if (!res.ok) {
        showError(res.error || '提交失败');
        // 验证码错误（或作废）时刷新验证码，方便重新输入
        if ((res.error || '').indexOf('验证码') !== -1) refreshCaptcha(form);
        return;
      }
      form.querySelector('[name="content"]').value = '';
      if (needReview) {
        // 需审核：评论不会立即显示，保留提示
        messageEl.textContent = '✓ 评论已提交，审核通过后将显示。';
        messageEl.hidden = false;
      } else {
        location.reload();
        return;
      }
    }).catch(function () {
      showError('网络错误，请重试');
    }).finally(function () {
      submitBtn.disabled = false;
      submitBtn.textContent = label;
    });
  });

  // ---- 验证码：进入页面/展开回复表单时获取；点击刷新 ----
  function initCaptcha(form) {
    if (!needCaptcha(form)) return;
    fetchCaptcha(form);
  }
  document.querySelectorAll('[data-comment-form]').forEach(initCaptcha);
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-captcha-refresh]');
    if (btn) {
      e.preventDefault();
      refreshCaptcha(btn.closest('[data-comment-form]'));
    }
  });

  // ---- 回复：展开/收起回复表单 ----
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-reply-toggle]');
    if (!btn) return;
    var item = btn.closest('.comment-item');
    var box = item.querySelector('.comment-reply-box');
    if (box.hidden) {
      box.hidden = false;
      btn.textContent = '取消回复';
      initCaptcha(box.querySelector('[data-comment-form]'));
    } else {
      box.hidden = true;
      btn.textContent = '回复';
    }
  });

  // ---- 点赞：乐观更新，失败回滚 ----
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.comment-like');
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    var id = btn.dataset.commentId;
    var liked = btn.dataset.liked === '1';
    var countEl = btn.querySelector('.comment-like-count');
    var count = countEl ? parseInt(countEl.textContent, 10) : 0;
    var prev = { liked: liked, count: count };
    // 乐观更新
    btn.dataset.liked = liked ? '0' : '1';
    btn.classList.toggle('liked', !liked);
    var next = Math.max(0, count + (liked ? -1 : 1));
    if (countEl) {
      countEl.textContent = next > 0 ? next : '';
    }
    postJSON(pafishApi('/comments/like'), { commentId: id }).then(function (res) {
      if (!res || typeof res.liked === 'undefined') {
        btn.dataset.liked = prev.liked ? '1' : '0';
        btn.classList.toggle('liked', prev.liked);
        if (countEl) countEl.textContent = prev.count > 0 ? prev.count : '';
        return;
      }
      btn.dataset.liked = res.liked ? '1' : '0';
      btn.classList.toggle('liked', !!res.liked);
      if (countEl) countEl.textContent = res.count > 0 ? res.count : '';
      btn.title = res.liked ? '取消点赞' : '点赞';
    }).catch(function () {
      btn.dataset.liked = prev.liked ? '1' : '0';
      btn.classList.toggle('liked', prev.liked);
      if (countEl) countEl.textContent = prev.count > 0 ? prev.count : '';
    }).finally(function () {
      btn.disabled = false;
    });
  });
})();
</script>
