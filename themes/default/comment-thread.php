<?php
/**
 * 默认主题评论列表（覆盖系统 app/Views/theme/comment-thread.php）
 *
 * 系统模板把回复递归嵌套进父评论的 .comment-children，最深 5 层；
 * 主题再给每层加左边框，就会叠出一排竖线。这里改为两层结构：
 * - 顶层评论
 * - 其下所有回复（不论原来第几层）按对话顺序平铺在同一个 .comment-children 里，
 *   回复的不是顶层评论时，用「回复 @某人」标明对象。
 * 回复超过 $collapseAfter + 1 条时，其余的先折叠，点「展开」或从锚点直达时自动展开。
 *
 * 时间显示为相对时间（$formatTime + 文末脚本），「回复」按钮与作者、时间在同一行。
 *
 * DOM 约定与系统模板保持一致，comment-section.php 的脚本无需改动：
 * 每条评论都是 .comment-item，它自己的 .comment-reply-box 排在 .comment-children 之前。
 *
 * 可用数据：$nodes（评论树 roots）、$postId、$targetType、$targetId、$needReview、$user、$captchaEnabled、$commentsOpen
 */
$nodes = $nodes ?? [];
$postId = (int) ($postId ?? 0);
$targetType = (string) ($targetType ?? 'post');
$targetId = (int) ($targetId ?? $postId);
$needReview = $needReview ?? true;
$user = $user ?? null;
$captchaEnabled = $captchaEnabled ?? true;
$commentsOpen = $commentsOpen ?? true;

/** 每条顶层评论下默认展示的回复数；只多 1 条时不折叠，直接全部显示。 */
$collapseAfter = 3;

/**
 * 评论时间：刚刚 / N 分钟前 / N 小时前 / 昨天 HH:mm / N 天前 / n月j日 / Y年n月j日。
 * 返回 [datetime 属性, 悬停显示的完整时间, 显示文字]；页面被缓存时由下方脚本按访客当前时间刷新。
 */
$formatTime = static function (array $node): array {
    $raw = (string) ($node['createdAt'] ?? '');
    $ts = $raw !== '' ? strtotime($raw) : false;
    if ($ts === false) {
        $label = (string) ($node['createdAtLabel'] ?? '');
        return ['', $label, $label];
    }
    $now = time();
    $diff = $now - $ts;
    $today = strtotime('today', $now);
    if (abs($diff) < 60) {
        $label = '刚刚';
    } elseif ($diff > 0 && $diff < 3600) {
        $label = intdiv($diff, 60) . ' 分钟前';
    } elseif ($diff > 0 && $ts >= $today) {
        $label = intdiv($diff, 3600) . ' 小时前';
    } elseif ($ts < $today && $ts >= $today - 86400) {
        $label = '昨天 ' . date('H:i', $ts);
    } elseif ($ts < $today && $ts >= $today - 6 * 86400) {
        $label = (int) round(($today - strtotime('today', $ts)) / 86400) . ' 天前';
    } elseif (date('Y', $ts) === date('Y', $now)) {
        $label = date('n月j日', $ts);
    } else {
        $label = date('Y年n月j日', $ts);
    }
    return [date('c', $ts), date('Y-m-d H:i', $ts), $label];
};

/** 深度优先展开回复树，保留对话顺序，并记下每条回复回的是谁。 */
$flatten = static function (array $replies, string $parentName, bool $parentIsRoot) use (&$flatten): array {
    $out = [];
    foreach ($replies as $reply) {
        if (!is_array($reply)) {
            continue;
        }
        $out[] = ['node' => $reply, 'replyTo' => $parentIsRoot ? '' : $parentName];
        if (!empty($reply['replies']) && is_array($reply['replies'])) {
            array_push($out, ...$flatten($reply['replies'], (string) ($reply['authorName'] ?? ''), false));
        }
    }
    return $out;
};

$renderItem = function (array $node, bool $isRoot, string $replyTo = '', bool $collapsed = false) use (&$renderItem, $flatten, $collapseAfter, $formatTime, $postId, $targetType, $targetId, $needReview, $user, $captchaEnabled, $commentsOpen): void {
    $id = e((string) ($node['id'] ?? ''));
    $avatarSize = $isRoot ? 40 : 24;
    $canReply = $commentsOpen && empty($node['isPending']);
    ?>
    <div id="comment-<?= $id ?>" class="comment-item <?= $isRoot ? 'comment-root' : 'comment-reply' ?>"<?= $collapsed ? ' hidden data-comment-collapsed' : '' ?>>
      <img src="<?= e((string) ($node['avatar'] ?? '')) ?>" alt="" width="<?= $avatarSize ?>" height="<?= $avatarSize ?>" loading="lazy" class="comment-avatar">
      <div class="comment-body">
        <div class="comment-head">
          <?php if (!empty($node['authorUrl'])): ?>
            <a class="comment-author" href="<?= e((string) $node['authorUrl']) ?>" target="_blank" rel="nofollow noopener noreferrer"><?= e((string) ($node['authorName'] ?? '')) ?></a>
          <?php else: ?>
            <span class="comment-author"><?= e((string) ($node['authorName'] ?? '')) ?></span>
          <?php endif; ?>
          <?php if ($replyTo !== ''): ?>
            <span class="comment-reply-to">回复 <b>@<?= e($replyTo) ?></b></span>
          <?php endif; ?>
          <?php if ($isRoot && !empty($node['isPinned'])): ?>
            <span class="comment-pinned"><?= admin_icon('pin', 12) ?> 置顶</span>
          <?php endif; ?>
          <?php if (!empty($node['isPending'])): ?>
            <span class="comment-pending">待审核</span>
          <?php endif; ?>
          <?php [$timeIso, $timeFull, $timeLabel] = $formatTime($node); ?>
          <time class="comment-date"<?= $timeIso !== '' ? ' datetime="' . e($timeIso) . '"' : '' ?> title="<?= e($timeFull) ?>"><?= e($timeLabel) ?></time>
          <?php if ($canReply): ?>
            <button type="button" class="comment-reply-btn" data-reply-toggle>回复</button>
          <?php endif; ?>
        </div>
        <p class="comment-content"><?= e((string) ($node['content'] ?? '')) ?></p>

        <?php if ($canReply): ?>
          <div class="comment-reply-box" hidden>
            <?= render_partial('comment-form', [
                'postId' => $postId,
                'targetType' => $targetType,
                'targetId' => $targetId,
                'parentId' => $node['id'],
                'compact' => true,
                'user' => $user,
                'captchaEnabled' => $captchaEnabled,
                'needReview' => $needReview,
            ]) ?>
          </div>
        <?php endif; ?>

        <?php if ($isRoot && !empty($node['replies']) && is_array($node['replies'])): ?>
          <?php
          $replies = $flatten($node['replies'], (string) ($node['authorName'] ?? ''), true);
          $total = count($replies);
          $limit = $total > $collapseAfter + 1 ? $collapseAfter : $total;
          ?>
          <div class="comment-children">
            <?php foreach ($replies as $i => $entry): ?>
              <?php $renderItem($entry['node'], false, $entry['replyTo'], $i >= $limit); ?>
            <?php endforeach; ?>
            <?php if ($total > $limit): ?>
              <button type="button" class="comment-more" data-comment-more>展开另外 <?= $total - $limit ?> 条回复</button>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
};
?>
<?php if ($nodes === []): ?>
  <p class="comment-empty">还没有评论，来抢沙发吧</p>
<?php else: ?>
  <div class="comment-list comment-flat">
    <?php foreach ($nodes as $root): ?>
      <?php if (is_array($root)) { $renderItem($root, true); } ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<script>
/* 折叠的回复：点「展开」或通过 #comment-N 锚点进入时展开 */
(function () {
  'use strict';
  if (window.__pafishCommentMoreBound) return;
  window.__pafishCommentMoreBound = true;

  function expand(group) {
    if (!group) return;
    group.querySelectorAll('[data-comment-collapsed]').forEach(function (item) {
      item.hidden = false;
      item.removeAttribute('data-comment-collapsed');
    });
    var more = group.querySelector('[data-comment-more]');
    if (more) more.remove();
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-comment-more]');
    if (!btn) return;
    expand(btn.closest('.comment-children'));
  });

  function revealHash() {
    var hash = location.hash;
    if (!/^#comment-\d+$/.test(hash)) return;
    var target = document.getElementById(hash.slice(1));
    if (target && target.hasAttribute('data-comment-collapsed')) {
      expand(target.closest('.comment-children'));
      target.scrollIntoView({ block: 'center' });
    }
  }
  revealHash();
  window.addEventListener('hashchange', revealHash);
  document.addEventListener('pafish:pjax:after', revealHash);
})();

/* 评论时间：按访客当前时间重算一次，规则与模板里的 $formatTime 相同（页面被缓存时也不会过时） */
(function () {
  'use strict';
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function label(date, now) {
    var diff = Math.floor((now - date) / 1000);
    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    var day = new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
    var t = date.getTime();
    if (Math.abs(diff) < 60) return '刚刚';
    if (diff > 0 && diff < 3600) return Math.floor(diff / 60) + ' 分钟前';
    if (diff > 0 && t >= today) return Math.floor(diff / 3600) + ' 小时前';
    if (t < today && t >= today - 86400000) return '昨天 ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    if (t < today && t >= today - 6 * 86400000) return Math.round((today - day) / 86400000) + ' 天前';
    if (date.getFullYear() === now.getFullYear()) return (date.getMonth() + 1) + '月' + date.getDate() + '日';
    return date.getFullYear() + '年' + (date.getMonth() + 1) + '月' + date.getDate() + '日';
  }
  function refresh() {
    var now = new Date();
    document.querySelectorAll('.comment-flat time.comment-date[datetime]').forEach(function (el) {
      var date = new Date(el.getAttribute('datetime'));
      if (!isNaN(date.getTime())) el.textContent = label(date, now);
    });
  }
  refresh();
  if (!window.__pafishCommentTimeBound) {
    window.__pafishCommentTimeBound = true;
    document.addEventListener('pafish:pjax:after', refresh);
  }
})();
</script>
