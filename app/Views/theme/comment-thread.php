<?php
/**
 * 评论楼中楼树（系统默认模板；主题可覆盖 themes/{active}/comment-thread.php）
 * 评论楼层：
 * 置顶徽标（仅顶层）、回复展开/收起、楼中楼最多 5 层
 * 可用数据：$nodes（评论树 roots）、$postId、$needReview、$user、$captchaEnabled
 */
$nodes = $nodes ?? [];
$postId = (int) ($postId ?? 0);
$needReview = $needReview ?? true;
$user = $user ?? null;
$captchaEnabled = $captchaEnabled ?? true;

$renderItem = function (array $node, int $depth) use (&$renderItem, $postId, $needReview, $user, $captchaEnabled): void {
    $id = e((string) $node['id']);
    ?>
    <div id="comment-<?= $id ?>" class="comment-item">
      <div class="comment-head">
        <img src="<?= e($node['avatar']) ?>" alt="" width="28" height="28" loading="lazy"
             class="comment-avatar">
        <?php if ($depth > 0): ?>
          <span class="comment-reply-mark" aria-hidden="true">↳</span>
        <?php endif; ?>
        <span class="comment-author"><?= e($node['authorName']) ?></span>
        <?php if (!empty($node['isPinned']) && $depth === 0): ?>
          <span class="comment-pinned"><?= admin_icon('pin', 13) ?> 置顶</span>
        <?php endif; ?>
        <?php if (!empty($node['isPending'])): ?>
          <span class="comment-pending">待审核</span>
        <?php endif; ?>
        <span class="comment-date"><?= e($node['createdAtLabel']) ?></span>
        <?php if (empty($node['isPending'])): ?>
          <button type="button" class="comment-reply-btn" data-reply-toggle>回复</button>
        <?php endif; ?>
      </div>
      <p class="comment-content"><?= e($node['content']) ?></p>

      <?php if (empty($node['isPending'])): ?>
        <div class="comment-reply-box" hidden>
          <?= render_partial('comment-form', [
              'postId' => $postId,
              'parentId' => $node['id'],
              'compact' => true,
              'user' => $user,
              'captchaEnabled' => $captchaEnabled,
              'needReview' => $needReview,
          ]) ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($node['replies'])): ?>
        <div class="comment-children">
          <?php foreach ($node['replies'] as $reply): ?>
            <?php $renderItem($reply, $depth + 1); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
};
?>
<?php if ($nodes === []): ?>
  <p class="comment-empty">还没有评论，来抢沙发吧</p>
<?php else: ?>
  <div class="comment-list">
    <?php foreach ($nodes as $root): ?>
      <?php $renderItem($root, 0); ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
