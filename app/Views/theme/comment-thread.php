<?php
/**
 * 评论楼中楼树（系统 fallback 模板；主题可覆盖 themes/{active}/comment-thread.php）
 * 对齐 Node 版 src/components/comment-thread.tsx：
 * 置顶徽标（仅顶层）、回复展开/收起、点赞乐观更新（失败回滚）、楼中楼最多 5 层
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
        <span class="comment-date"><?= e($node['createdAtLabel']) ?></span>
        <button type="button" class="comment-reply-btn" data-reply-toggle><?= $depth > 0 ? '回复' : '回复' ?></button>
        <button type="button" class="comment-like<?= !empty($node['liked']) ? ' liked' : '' ?>"
                data-comment-id="<?= $id ?>" data-liked="<?= !empty($node['liked']) ? '1' : '0' ?>"
                title="<?= !empty($node['liked']) ? '取消点赞' : '点赞' ?>">
          <span class="comment-like-icon"><?= admin_icon('thumbs-up', 14) ?></span>
          <?php if ((int) $node['likeCount'] > 0): ?>
            <span class="comment-like-count"><?= (int) $node['likeCount'] ?></span>
          <?php endif; ?>
        </button>
      </div>
      <p class="comment-content"><?= e($node['content']) ?></p>

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
