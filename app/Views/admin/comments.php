<?php
/**
 * 评论审核：
 * - 4 Tab（待审核/已通过/垃圾/已删除）+ 徽标计数，20/页 created_at 倒序
 * - 每条评论：作者、置顶徽标、回复 @父作者、时间「评论于」、文章链接（新窗口）、
 *   内容、邮箱 + IP（等宽）、当前状态、操作行
 * - 操作：回复（展开输入框，以管理员身份直接通过）、通过、垃圾、置顶/取消、
 *   按 IP 删除（提示删除条数）、拉黑 IP、删除（两段式「确认？」）
 * 变量：$items $status $total $page $pages $statusCounts
 */
$tabs = ['PENDING' => '待审核', 'APPROVED' => '已通过', 'SPAM' => '垃圾', 'TRASH' => '已删除'];
$listUrl = url_to('/admin/comments') . '?status=' . $status;
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">评论审核</h1>
    </div>
  </div>

  <!-- 4 Tab + 徽标 -->
  <div class="admin-comment-tabs" role="tablist">
    <?php foreach ($tabs as $s => $label): ?>
      <a class="admin-comment-tab<?= $s === $status ? ' active' : '' ?>"
         href="<?= e(url_to('/admin/comments') . '?status=' . $s) ?>">
        <?= $label ?><span class="badge"><?= (int) $statusCounts[$s] ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($items === []): ?>
    <div class="admin-empty-list card">
      <?= admin_icon('message', 32) ?>
      <p>该状态下暂无评论</p>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap admin-comment-table-wrap">
      <table class="admin-table admin-comment-table">
        <thead>
          <tr><th>评论</th><th>文章</th><th>来源</th><th>状态</th><th class="admin-col-ops">操作</th></tr>
        </thead>
        <tbody>
          <?php foreach ($items as $c): ?>
            <tr class="admin-comment-card" data-comment="<?= (int) $c['id'] ?>" data-ip="<?= e($c['ip'] ?? '') ?>">
              <td data-label="评论">
                <div class="admin-comment-head">
                  <span class="admin-comment-author"><?= e($c['author_name']) ?></span>
                  <?php if ((int) $c['is_pinned'] === 1): ?><span class="admin-comment-badge"><?= admin_icon('pin', 11) ?> 置顶</span><?php endif; ?>
                  <?php if ($c['parent_name'] !== null): ?><span class="admin-comment-badge"><?= admin_icon('corner-down-right', 11) ?> 回复 <?= e($c['parent_name']) ?></span><?php endif; ?>
                </div>
                <div class="admin-comment-content"><?= e($c['content']) ?></div>
                <div class="admin-muted admin-comment-time"><?= e(format_date($c['created_at'], 'yyyy-MM-dd HH:mm')) ?></div>
              </td>
              <td data-label="文章" class="admin-comment-post">
                <?= admin_icon('link', 12) ?> <a href="<?= e(url_to('/post/' . $c['post_slug'])) ?>" target="_blank" rel="noopener"><?= e($c['post_title']) ?></a>
              </td>
              <td data-label="来源">
                <code class="admin-comment-code"><?= e($c['author_email'] ?: '—') ?></code>
                <code class="admin-comment-code">IP <?= e($c['ip'] ?? '—') ?></code>
              </td>
              <td data-label="状态"><span class="admin-comment-status"><?= $tabs[$c['status']] ?></span></td>
              <td data-label="操作" class="admin-col-ops">
                <div class="admin-comment-ops">
                  <button type="button" class="btn btn-sm btn-ghost" data-reply="<?= (int) $c['id'] ?>"><?= admin_icon('corner-down-right', 13) ?> 回复</button>
                  <?php if ($status !== 'APPROVED'): ?><button type="button" class="btn btn-sm btn-ghost" data-status="<?= (int) $c['id'] ?>" data-next="APPROVED"><?= admin_icon('check', 13) ?> 通过</button><?php endif; ?>
                  <?php if ($status !== 'SPAM'): ?><button type="button" class="btn btn-sm btn-ghost" data-status="<?= (int) $c['id'] ?>" data-next="SPAM"><?= admin_icon('trash', 13) ?> 垃圾</button><?php endif; ?>
                  <button type="button" class="btn btn-sm btn-ghost" data-pin="<?= (int) $c['id'] ?>"><?= admin_icon('pin', 13) ?> <?= (int) $c['is_pinned'] === 1 ? '取消置顶' : '置顶' ?></button>
                  <?php if (($c['ip'] ?? '') !== ''): ?><button type="button" class="btn btn-sm btn-ghost" data-delete-ip="<?= e($c['ip']) ?>"><?= admin_icon('globe', 13) ?> 按 IP 删除</button><button type="button" class="btn btn-sm btn-ghost" data-block-ip="<?= e($c['ip']) ?>"><?= admin_icon('shield-ban', 13) ?> 拉黑 IP</button><?php endif; ?>
                  <button type="button" class="btn btn-sm btn-ghost admin-icon-danger" data-delete="<?= (int) $c['id'] ?>"><?= admin_icon('trash', 13) ?> 删除</button>
                </div>
                <div class="admin-comment-replybox" hidden>
                  <textarea class="admin-comment-reply-input" rows="2" maxlength="2000" placeholder="以管理员身份回复，回复将直接显示在前台…"></textarea>
                  <div class="admin-comment-reply-actions"><button type="button" class="btn btn-sm btn-primary" data-reply-submit="<?= (int) $c['id'] ?>">回复</button><button type="button" class="btn btn-sm btn-ghost" data-reply-cancel>取消</button></div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="admin-pagination">
        <?php if ($page > 1): ?><a class="admin-pg-btn" href="<?= e($listUrl . '&page=' . ($page - 1)) ?>">上一页</a><?php endif; ?>
        <?php foreach (range(max(1, $page - 2), min($pages, $page + 2)) as $n): ?>
          <?php if ($n === $page): ?><span class="admin-pg-btn active"><?= $n ?></span>
          <?php else: ?><a class="admin-pg-btn" href="<?= e($listUrl . '&page=' . $n) ?>"><?= $n ?></a><?php endif; ?>
        <?php endforeach; ?>
        <?php if ($page < $pages): ?><a class="admin-pg-btn" href="<?= e($listUrl . '&page=' . ($page + 1)) ?>">下一页</a><?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php $commentCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($commentCsrf) ?>;
  var listUrl = <?= json_encode(url_to('/admin/comments')) ?>;

  function post(url, body) {
    var fd = new FormData();
    Object.keys(body || {}).forEach(function (k) { fd.append(k, body[k]); });
    fd.append("_csrf", CSRF);
    return fetch(url, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } });
  }

  function jsonOrAlert(r, failMsg) {
    return r.json().then(function (j) {
      if (j && j.ok) return j;
     pafishNotify((j && j.error) || failMsg);
      return null;
    }).catch(function () { pafishNotify("网络错误"); return null; });
  }

  // ---- 状态流转：通过 / 垃圾 ----
  document.querySelectorAll("[data-status]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var next = btn.getAttribute("data-next");
      var label = next === "APPROVED" ? "通过" : "标记为垃圾";
      (window.pafishConfirm ? window.pafishConfirm("确定将该评论" + label + "？", { title: "更新评论状态" }) : Promise.resolve(window.confirm("确定继续？"))).then(function (ok) {
        if (!ok) return;
        return post(listUrl + "/" + btn.getAttribute("data-status") + "/status", { status: next })
        .then(function (r) { return jsonOrAlert(r, "操作失败"); })
        .then(function (j) { if (j) location.reload(); });
      });
    });
  });

  // ---- 置顶 / 取消置顶 ----
  document.querySelectorAll("[data-pin]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      post(listUrl + "/" + btn.getAttribute("data-pin") + "/pin")
        .then(function (r) { return jsonOrAlert(r, "操作失败"); })
        .then(function (j) { if (j) location.reload(); });
    });
  });

  // ---- 删除（两段式确认：第一次变「确认？」，再点才执行） ----
  document.querySelectorAll("[data-delete]").forEach(function (btn) {
    var armed = false;
    var original = btn.innerHTML;
    btn.addEventListener("click", function () {
      if (!armed) {
        armed = true;
        btn.textContent = "确认？";
        setTimeout(function () { armed = false; btn.innerHTML = original; }, 2500);
        return;
      }
      var card = btn.closest("[data-comment]");
      var id = card.getAttribute("data-comment");
      post(listUrl + "/" + id + "/delete")
        .then(function (r) { return jsonOrAlert(r, "删除失败"); })
        .then(function (j) { if (j) location.reload(); });
    });
  });

  // ---- 按 IP 删除（提示删除条数） ----
  document.querySelectorAll("[data-delete-ip]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var ip = btn.getAttribute("data-delete-ip");
      (window.pafishConfirm ? window.pafishConfirm("删除该 IP 的全部评论？此操作不可恢复。", { title: "按 IP 删除评论" }) : Promise.resolve(window.confirm("确认删除？"))).then(function (ok) {
        if (!ok) return;
        return post(listUrl + "/delete-by-ip", { ip: ip })
        .then(function (r) { return jsonOrAlert(r, "操作失败"); })
        .then(function (j) {
          if (j) pafishNotify("已删除 " + (j.deleted || 0) + " 条评论", false);
          if (j) location.reload();
        });
      });
    });
  });

  // ---- 拉黑 IP ----
  document.querySelectorAll("[data-block-ip]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var ip = btn.getAttribute("data-block-ip");
      (window.pafishConfirm ? window.pafishConfirm("拉黑该 IP？之后它提交的评论将被拒绝（403）。", { title: "拉黑 IP" }) : Promise.resolve(window.confirm("确认拉黑？"))).then(function (ok) {
        if (!ok) return;
        return post(listUrl + "/block-ip", { ip: ip })
        .then(function (r) { return jsonOrAlert(r, "操作失败"); })
        .then(function (j) { if (j) pafishNotify("已拉黑 " + ip, false); });
      });
    });
  });

  // ---- 回复（展开输入框） ----
  document.querySelectorAll("[data-reply]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var card = btn.closest("[data-comment]");
      var box = card.querySelector(".admin-comment-replybox");
      box.hidden = !box.hidden;
      if (!box.hidden) box.querySelector("textarea").focus();
    });
  });

  document.querySelectorAll("[data-reply-cancel]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var box = btn.closest(".admin-comment-replybox");
      box.querySelector("textarea").value = "";
      box.hidden = true;
    });
  });

  document.querySelectorAll("[data-reply-submit]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var card = btn.closest("[data-comment]");
      var box = card.querySelector(".admin-comment-replybox");
      var input = box.querySelector("textarea");
      var content = input.value.trim();
      if (!content) { pafishNotify("回复内容不能为空"); return; }
      post(listUrl + "/" + btn.getAttribute("data-reply-submit") + "/reply", { content: content })
        .then(function (r) { return jsonOrAlert(r, "回复失败"); })
        .then(function (j) {
          if (j) {
            input.value = "";
            box.hidden = true;
           pafishNotify("已回复，评论将直接显示在前台", false);
            location.reload();
          }
        });
    });
  });
})();
</script>
