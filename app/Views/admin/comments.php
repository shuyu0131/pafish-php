<?php
/** 评论审核：常用操作直接显示，来源与低频操作放入详情。 */
$tabs = ['ALL' => '全部', 'PENDING' => '待审核', 'APPROVED' => '已通过', 'SPAM' => '垃圾', 'TRASH' => '已删除'];
$postId = (int) ($postId ?? 0);
$per = (int) ($per ?? 20);
$postTitle = (string) ($postTitle ?? '');
$querySuffix = ($postId > 0 ? '&post_id=' . $postId : '') . ($per !== 20 ? '&per=' . $per : '');
$listUrl = url_to('/admin/comments') . '?status=' . $status . $querySuffix;
?>
<div class="admin-stack admin-list-page admin-comments-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">评论审核</h1>
      <p class="admin-page-sub"><?= $total ?> 条评论<?= $status !== 'ALL' ? ' · ' . e($tabs[$status]) : '' ?><?= $postTitle !== '' ? ' · 《' . e($postTitle) . '》' : '' ?></p>
    </div>
  </div>

  <div class="admin-list-toolbar">
    <nav class="admin-comment-tabs" aria-label="评论状态">
      <?php foreach (['ALL', 'PENDING'] as $s): ?>
        <a class="admin-comment-tab<?= $s === $status ? ' active' : '' ?>"
           <?= $s === $status ? 'aria-current="page"' : '' ?>
           href="<?= e(url_to('/admin/comments') . '?status=' . $s . $querySuffix) ?>">
          <?= $tabs[$s] ?><?php if ($s === 'PENDING'): ?><span class="badge"><?= (int) $statusCounts[$s] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <form action="<?= e(url_to('/admin/comments')) ?>" method="get" class="admin-list-filter-form">
      <?php if ($postId > 0): ?><input type="hidden" name="post_id" value="<?= $postId ?>"><?php endif; ?>
      <?php if ($per !== 20): ?><input type="hidden" name="per" value="<?= $per ?>"><?php endif; ?>
      <select name="status" class="input admin-filter-select" aria-label="更多评论状态" onchange="this.form.submit()">
        <option value="" disabled<?= in_array($status, ['ALL', 'PENDING'], true) ? ' selected' : '' ?>>更多状态</option>
        <?php foreach (['APPROVED', 'SPAM', 'TRASH'] as $s): ?>
          <option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= $tabs[$s] ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-outline" type="submit">筛选</button></noscript>
    </form>
  </div>

  <?php if ($items !== []): ?>
  <form class="admin-batch-bar admin-comment-batch-bar" id="commentBatchForm" action="<?= e(url_to('/admin/comments/batch')) ?>" method="post" hidden>
    <?= csrf_field() ?>
    <span class="admin-batch-count">已选 <strong class="admin-batch-count-number">0</strong> 条</span>
    <select class="input admin-batch-op" name="op" aria-label="批量操作">
      <option value="">选择操作</option>
      <option value="approve">批量通过</option>
      <option value="spam">标记为垃圾</option>
      <option value="delete">删除</option>
    </select>
    <button class="btn btn-outline" type="submit">应用</button>
    <button class="btn btn-ghost" type="button" data-comment-batch-clear>清除选择</button>
  </form>
  <?php endif; ?>

  <?php if ($items === []): ?>
    <div class="admin-empty-list card">
      <?= admin_icon('message', 32) ?>
      <p>该状态下暂无评论</p>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap admin-comment-table-wrap">
      <table class="admin-table admin-comment-table">
        <thead>
          <tr><th class="admin-col-check"><input type="checkbox" id="commentCheckAll" aria-label="选择本页全部评论"></th><th>评论</th><th class="admin-table-secondary">文章</th><th class="admin-table-secondary">时间</th><th class="admin-col-ops">操作</th></tr>
        </thead>
        <tbody>
          <?php foreach ($items as $c): ?>
            <tr class="admin-comment-card" data-comment="<?= (int) $c['id'] ?>" data-ip="<?= e($c['ip'] ?? '') ?>" data-email="<?= e($c['author_email'] ?? '') ?>">
              <td class="admin-col-check" data-label="选择"><input type="checkbox" class="comment-row-check" value="<?= (int) $c['id'] ?>" aria-label="选择评论 <?= (int) $c['id'] ?>"></td>
              <td data-label="评论">
                <div class="admin-comment-head">
                  <span class="admin-comment-author"><?= e($c['author_name']) ?></span>
                  <?php if ($c['status'] !== 'APPROVED'): ?><span class="admin-comment-state"><?= e($tabs[$c['status']]) ?></span><?php endif; ?>
                  <?php if ((int) $c['is_pinned'] === 1): ?><span class="admin-comment-badge"><?= admin_icon('pin', 11) ?> 置顶</span><?php endif; ?>
                  <?php if ($c['parent_name'] !== null): ?><span class="admin-comment-parent">回复 <?= e($c['parent_name']) ?></span><?php endif; ?>
                </div>
                <div class="admin-comment-content"><?= e($c['content']) ?></div>
                <div class="admin-table-mobile-meta admin-comment-mobile-meta">
                  <a href="<?= e(url_to($c['target_path'])) ?>" target="_blank" rel="noopener"><?= e($c['post_title']) ?></a>
                  <time><?= e(format_date($c['created_at'], 'yyyy-MM-dd HH:mm')) ?></time>
                </div>
                <details class="admin-comment-details">
                  <summary>详情</summary>
                  <div class="admin-comment-source">
                    <?php if ($c['author_email'] !== ''): ?><span><?= e($c['author_email']) ?></span><?php endif; ?>
                    <?php if (($c['ip'] ?? '') !== ''): ?><span>IP <?= e($c['ip']) ?></span><?php endif; ?>
                  </div>
                  <div class="admin-comment-detail-actions">
                    <button type="button" class="btn btn-sm btn-ghost" data-pin="<?= (int) $c['id'] ?>"><?= (int) $c['is_pinned'] === 1 ? '取消置顶' : '置顶' ?></button>
                    <?php if ($c['status'] !== 'SPAM'): ?><button type="button" class="btn btn-sm btn-ghost" data-status="<?= (int) $c['id'] ?>" data-next="SPAM">标记为垃圾</button><?php endif; ?>
                    <?php if (($c['ip'] ?? '') !== ''): ?>
                      <button type="button" class="btn btn-sm btn-ghost admin-icon-danger" data-delete-ip="<?= e($c['ip']) ?>">按 IP 删除</button>
                      <button type="button" class="btn btn-sm btn-ghost" data-block-ip="<?= e($c['ip']) ?>">拉黑 IP</button>
                    <?php endif; ?>
                    <?php if (filter_var((string) ($c['author_email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false): ?><button type="button" class="btn btn-sm btn-ghost" data-block-email="<?= e($c['author_email']) ?>">拉黑邮箱</button><?php endif; ?>
                  </div>
                </details>
                <div class="admin-comment-replybox" hidden>
                  <textarea class="admin-comment-reply-input" rows="3" maxlength="2000" aria-label="回复评论" placeholder="回复评论…"></textarea>
                  <div class="admin-comment-reply-actions"><button type="button" class="btn btn-sm btn-primary" data-reply-submit="<?= (int) $c['id'] ?>">回复</button><button type="button" class="btn btn-sm btn-ghost" data-reply-cancel>取消</button></div>
                </div>
              </td>
              <td data-label="内容" class="admin-comment-post admin-table-secondary">
                <a href="<?= e(url_to($c['target_path'])) ?>" target="_blank" rel="noopener"><?= e($c['post_title']) ?></a>
              </td>
              <td data-label="时间" class="admin-table-secondary admin-comment-time"><?= e(format_date($c['created_at'], 'yyyy-MM-dd HH:mm')) ?></td>
              <td data-label="操作" class="admin-col-ops">
                <div class="admin-comment-ops">
                  <button type="button" class="btn btn-sm btn-ghost" data-reply="<?= (int) $c['id'] ?>">回复</button>
                  <?php if ($c['status'] !== 'APPROVED'): ?><button type="button" class="btn btn-sm btn-ghost" data-status="<?= (int) $c['id'] ?>" data-next="APPROVED">通过</button><?php endif; ?>
                  <button type="button" class="btn btn-sm btn-ghost admin-icon-danger" data-delete="<?= (int) $c['id'] ?>">删除</button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?= admin_pagination($page, $pages, $total, static function (int $p, int $per = 20) use ($status, $postId): string {
      return url_to('/admin/comments') . '?status=' . rawurlencode($status) . ($postId > 0 ? '&post_id=' . $postId : '') . ($p > 1 ? '&page=' . $p : '') . ($per !== 20 ? '&per=' . $per : '');
    }, $per, [20, 50, 100]) ?>
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
     pafishNotify((j && j.error) || failMsg, true);
      return null;
    }).catch(function () { pafishNotify("网络错误", true); return null; });
  }

  var batchForm = document.getElementById("commentBatchForm");
  var checkAll = document.getElementById("commentCheckAll");
  var rowChecks = Array.prototype.slice.call(document.querySelectorAll(".comment-row-check"));
  function selectedCommentIds() {
    return rowChecks.filter(function (box) { return box.checked; }).map(function (box) { return box.value; });
  }
  function updateBatchBar() {
    if (!batchForm) return;
    var count = selectedCommentIds().length;
    batchForm.hidden = count === 0;
    batchForm.querySelector(".admin-batch-count-number").textContent = String(count);
    var select = batchForm.elements.op;
    var apply = batchForm.querySelector('button[type="submit"]');
    select.disabled = count === 0;
    apply.disabled = count === 0;
    if (checkAll) {
      checkAll.checked = count > 0 && count === rowChecks.length;
      checkAll.indeterminate = count > 0 && count < rowChecks.length;
    }
  }
  rowChecks.forEach(function (box) { box.addEventListener("change", updateBatchBar); });
  if (checkAll) {
    checkAll.addEventListener("change", function () {
      rowChecks.forEach(function (box) { box.checked = checkAll.checked; });
      updateBatchBar();
    });
  }
  if (batchForm) {
    batchForm.querySelector("[data-comment-batch-clear]").addEventListener("click", function () {
      rowChecks.forEach(function (box) { box.checked = false; });
      updateBatchBar();
    });
    batchForm.addEventListener("submit", function (event) {
      event.preventDefault();
      var ids = selectedCommentIds();
      var operation = batchForm.elements.op.value;
      if (!ids.length || !operation) {
        pafishNotify("请选择评论和批量操作", true);
        return;
      }
      var labels = {approve: "通过", spam: "标记为垃圾", delete: "删除"};
      var prompt = operation === "delete"
        ? "删除选中的 " + ids.length + " 条评论？此操作不可恢复。"
        : labels[operation] + "选中的 " + ids.length + " 条评论？";
      var confirm = window.pafishConfirm
        ? window.pafishConfirm(prompt, {title: "批量处理评论", danger: operation === "delete"})
        : Promise.resolve(window.confirm(prompt));
      confirm.then(function (ok) {
        if (!ok) return;
        var button = batchForm.querySelector('button[type="submit"]');
        button.disabled = true;
        var data = new FormData(batchForm);
        ids.forEach(function (id) { data.append("ids[]", id); });
        fetch(batchForm.action, {method: "POST", body: data, headers: {"X-Requested-With": "XMLHttpRequest"}})
          .then(function (response) { return response.json().then(function (json) { return {ok: response.ok, json: json}; }); })
          .then(function (result) {
            if (!result.ok || !result.json.ok) throw new Error(result.json.error || "批量操作失败");
            pafishToastReload("已处理 " + result.json.changed + " 条评论", "success");
          })
          .catch(function (error) {
            button.disabled = false;
            pafishNotify(error.message || "网络错误", true);
          });
      });
    });
  }
  updateBatchBar();

  // ---- 状态流转：通过 / 垃圾 ----
  document.querySelectorAll("[data-status]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var next = btn.getAttribute("data-next");
      var label = next === "APPROVED" ? "通过" : "标记为垃圾";
      (window.pafishConfirm ? window.pafishConfirm("确定将该评论" + label + "？", { title: "更新评论状态" }) : Promise.resolve(window.confirm("确定继续？"))).then(function (ok) {
        if (!ok) return;
        return post(listUrl + "/" + btn.getAttribute("data-status") + "/status", { status: next })
        .then(function (r) { return jsonOrAlert(r, "操作失败"); })
        .then(function (j) { if (j) pafishToastReload("评论状态已更新", "success"); });
      });
    });
  });

  // ---- 置顶 / 取消置顶 ----
  document.querySelectorAll("[data-pin]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      post(listUrl + "/" + btn.getAttribute("data-pin") + "/pin")
        .then(function (r) { return jsonOrAlert(r, "操作失败"); })
        .then(function (j) { if (j) pafishToastReload("评论置顶状态已更新", "success"); });
    });
  });

  // ---- 删除确认 ----
  document.querySelectorAll("[data-delete]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var card = btn.closest("[data-comment]");
      var id = card.getAttribute("data-comment");
      (window.pafishConfirm ? window.pafishConfirm("确定删除该评论？此操作不可恢复。", { title: "删除评论" }) : Promise.resolve(window.confirm("确定删除该评论？"))).then(function (ok) {
        if (!ok) return;
        post(listUrl + "/" + id + "/delete")
          .then(function (r) { return jsonOrAlert(r, "删除失败"); })
          .then(function (j) { if (j) pafishToastReload("评论已删除", "success"); });
      });
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
          if (j) pafishToastReload("已删除 " + (j.deleted || 0) + " 条评论", "success");
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

  document.querySelectorAll("[data-block-email]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var email = btn.getAttribute("data-block-email");
      (window.pafishConfirm ? window.pafishConfirm("拉黑该邮箱？之后它提交的评论将被拒绝（403）。", { title: "拉黑邮箱" }) : Promise.resolve(window.confirm("确认拉黑？"))).then(function (ok) {
        if (!ok) return;
        return post(listUrl + "/block-email", { email: email })
        .then(function (r) { return jsonOrAlert(r, "操作失败"); })
        .then(function (j) { if (j) pafishNotify("已拉黑 " + email, false); });
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
      if (!content) { pafishNotify("回复内容不能为空", true); return; }
      post(listUrl + "/" + btn.getAttribute("data-reply-submit") + "/reply", { content: content })
        .then(function (r) { return jsonOrAlert(r, "回复失败"); })
        .then(function (j) {
          if (j) {
            input.value = "";
            box.hidden = true;
            pafishToastReload("已回复，评论将直接显示在前台", "success");
          }
        });
    });
  });
})();
</script>
