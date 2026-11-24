<?php
/**
 * 评论列表（系统默认模板；主题可覆盖 themes/{active}/comment-thread.php）
 *
 * 只有两层：顶层评论，以及它下面的一个 .comment-children。
 * 回复不论原来在第几层，都按对话顺序平铺进这个 .comment-children；
 * 回复的不是顶层评论时，作者名后加「回复 @某人」。
 *
 * 为什么不再递归嵌套：旧模板把回复逐层套进父评论的 .comment-children（最深 5 层），
 * 主题给 .comment-item 或 .comment-children 加左边框时，就会并排叠出一排竖线。
 *
 * 每条评论的 HTML 结构与旧模板保持一致（.comment-head 内含头像、↳、作者、徽标、日期、回复按钮，
 * 其后依次是 .comment-content、.comment-reply-box），现有主题样式和 comment-section.php 的脚本无需改动。
 * 新增的钩子：.comment-root / .comment-reply、data-depth、.comment-reply-to。
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

/** 深度优先展开回复树，保留对话顺序，并记下每条回复的原始层级和回复对象。 */
$flatten = static function (array $replies, string $parentName, int $depth) use (&$flatten): array {
    $out = [];
    foreach ($replies as $reply) {
        if (!is_array($reply)) {
            continue;
        }
        $out[] = ['node' => $reply, 'depth' => $depth, 'replyTo' => $depth > 1 ? $parentName : ''];
        if (!empty($reply['replies']) && is_array($reply['replies'])) {
            array_push($out, ...$flatten($reply['replies'], (string) ($reply['authorName'] ?? ''), $depth + 1));
        }
    }
    return $out;
};

$renderItem = function (array $node, int $depth, string $replyTo = '') use (&$renderItem, $flatten, $postId, $targetType, $targetId, $needReview, $user, $captchaEnabled, $commentsOpen): void {
    $id = e((string) ($node['id'] ?? ''));
    $isRoot = $depth === 0;
    ?>
    <div id="comment-<?= $id ?>" class="comment-item <?= $isRoot ? 'comment-root' : 'comment-reply' ?>" data-depth="<?= $depth ?>">
      <div class="comment-head">
        <img src="<?= e((string) ($node['avatar'] ?? '')) ?>" alt="" width="28" height="28" loading="lazy"
             class="comment-avatar">
        <?php if (!$isRoot): ?>
          <span class="comment-reply-mark" aria-hidden="true">↳</span>
        <?php endif; ?>
        <?php if (!empty($node['authorUrl'])): ?>
          <a class="comment-author" href="<?= e((string) $node['authorUrl']) ?>" target="_blank" rel="nofollow noopener noreferrer"><?= e((string) ($node['authorName'] ?? '')) ?></a>
        <?php else: ?>
          <span class="comment-author"><?= e((string) ($node['authorName'] ?? '')) ?></span>
        <?php endif; ?>
        <?php if ($replyTo !== ''): ?>
          <span class="comment-reply-to">回复 <b>@<?= e($replyTo) ?></b></span>
        <?php endif; ?>
        <?php if (!empty($node['isPinned']) && $isRoot): ?>
          <span class="comment-pinned"><?= admin_icon('pin', 13) ?> 置顶</span>
        <?php endif; ?>
        <?php if (!empty($node['isPending'])): ?>
          <span class="comment-pending">待审核</span>
        <?php endif; ?>
        <span class="comment-date"><?= e((string) ($node['createdAtLabel'] ?? '')) ?></span>
        <?php if ($commentsOpen && empty($node['isPending'])): ?>
          <button type="button" class="comment-reply-btn" data-reply-toggle>回复</button>
        <?php endif; ?>
      </div>
      <p class="comment-content"><?= e((string) ($node['content'] ?? '')) ?></p>

      <?php if ($commentsOpen && empty($node['isPending'])): ?>
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
        <div class="comment-children">
          <?php foreach ($flatten($node['replies'], (string) ($node['authorName'] ?? ''), 1) as $entry): ?>
            <?php $renderItem($entry['node'], $entry['depth'], $entry['replyTo']); ?>
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
      <?php if (is_array($root)) { $renderItem($root, 0); } ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
