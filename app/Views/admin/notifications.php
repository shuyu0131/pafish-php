<?php
/**
 * 通知中心：
 * - 20/页，未读在前（read asc）再按时间倒序；顶部未读数 + 「全部已读」按钮
 * - 类型：NEW_COMMENT 新评论（message 图标）/ NEW_REPLY 新回复（回复箭头图标）
 * - 每条：图标、message、时间、「查看文章」（前台新窗口）+「去审核」
 * 变量：$items $total $page $pages $unread
 */
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">通知</h1>
    </div>
    <?php if ($unread > 0): ?>
      <div class="admin-head-actions">
        <button type="button" class="btn btn-ghost" id="readAllBtn"><?= admin_icon('check', 14) ?> 全部已读</button>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($items === []): ?>
    <div class="admin-empty-list card">
      <?= admin_icon('bell', 32) ?>
      <p>暂无通知，新评论、新回复会显示在这里</p>
    </div>
  <?php else: ?>
    <div class="admin-notify-list">
      <?php foreach ($items as $n): ?>
        <div class="admin-notify-item card<?= (int) $n['read'] === 0 ? ' unread' : '' ?>">
          <span class="admin-notify-icon"><?= admin_icon($n['type'] === 'NEW_REPLY' ? 'corner-down-right' : 'message', 16) ?></span>
          <div class="admin-notify-body">
            <div class="admin-notify-msg">
              <?= (int) $n['read'] === 0 ? '<span class="admin-notify-dot"></span>' : '' ?><?= e($n['message']) ?>
            </div>
            <div class="admin-muted"><?= e(format_date($n['created_at'], 'yyyy-MM-dd HH:mm')) ?></div>
          </div>
          <div class="admin-notify-actions">
            <?php if (($n['post_slug'] ?? '') !== ''): ?>
              <a class="btn btn-sm btn-ghost" href="<?= e(url_to('/post/' . $n['post_slug'])) ?>" target="_blank" rel="noopener">
                <?= admin_icon('external-link', 12) ?> 查看文章
              </a>
            <?php endif; ?>
            <a class="btn btn-sm btn-ghost" href="<?= e(url_to('/admin/comments?status=PENDING')) ?>">
              去审核 <?= admin_icon('arrow-right', 12) ?>
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <div class="admin-pagination">
        <?php if ($page > 1): ?><a class="admin-pg-btn" href="<?= e(url_to('/admin/notifications') . '?page=' . ($page - 1)) ?>">上一页</a><?php endif; ?>
        <?php foreach (range(max(1, $page - 2), min($pages, $page + 2)) as $n): ?>
          <?php if ($n === $page): ?><span class="admin-pg-btn active"><?= $n ?></span>
          <?php else: ?><a class="admin-pg-btn" href="<?= e(url_to('/admin/notifications') . '?page=' . $n) ?>"><?= $n ?></a><?php endif; ?>
        <?php endforeach; ?>
        <?php if ($page < $pages): ?><a class="admin-pg-btn" href="<?= e(url_to('/admin/notifications') . '?page=' . ($page + 1)) ?>">下一页</a><?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php $notifyCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($notifyCsrf) ?>;
  var btn = document.getElementById("readAllBtn");
  if (btn) {
    btn.addEventListener("click", function () {
      var fd = new FormData();
      fd.append("_csrf", CSRF);
      fetch(<?= json_encode(url_to('/admin/notifications/read-all')) ?>, {
        method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" }
      })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) location.reload();
          else pafishNotify((j && j.error) || "操作失败");
        })
        .catch(function () { pafishNotify("网络错误"); });
    });
  }
})();
</script>
