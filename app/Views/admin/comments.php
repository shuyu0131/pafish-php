<?php
/** 评论审核：常用操作直接显示，来源与低频操作放入详情。 */
$tabs = ['ALL' => t('common.all'), 'PENDING' => t('admin.pending_review'), 'APPROVED' => t('admin.approved'), 'SPAM' => t('admin.spam'), 'TRASH' => t('admin.deleted')];
$tabCounts = $statusCounts ?? [];
$tabCounts['ALL'] = array_sum($tabCounts);
$postId = (int) ($postId ?? 0);
$per = (int) ($per ?? 20);
$postTitle = (string) ($postTitle ?? '');
$search = (string) ($search ?? '');
$querySuffix = ($postId > 0 ? '&post_id=' . $postId : '') . ($search !== '' ? '&q=' . rawurlencode($search) : '') . ($per !== 20 ? '&per=' . $per : '');
$listUrl = url_to('/admin/comments') . '?status=' . $status . $querySuffix;
?>
<div class="admin-stack admin-list-page admin-comments-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e(t('admin.comment_moderation')) ?></h1>
      <p class="admin-page-sub"><?= $total ?> <?= e(t('admin.comments')) ?><?= $status !== 'ALL' ? ' · ' . e($tabs[$status]) : '' ?><?= $postTitle !== '' ? ' · 《' . e($postTitle) . '》' : '' ?></p>
    </div>
  </div>

  <div class="admin-list-toolbar">
    <nav class="admin-tabs admin-status-tabs admin-comment-tabs" aria-label="<?= e(t('admin.comment_status')) ?>">
      <?php foreach ($tabs as $s => $label): ?>
        <a class="admin-tab<?= $s === $status ? ' is-active' : '' ?>"
           <?= $s === $status ? 'aria-current="page"' : '' ?>
           href="<?= e(url_to('/admin/comments') . '?status=' . $s . $querySuffix) ?>">
          <span><?= e($label) ?></span> <span class="admin-tab-count"><?= (int) ($tabCounts[$s] ?? 0) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <form class="admin-list-search" method="get" action="<?= e(url_to('/admin/comments')) ?>">
      <input type="hidden" name="status" value="<?= e($status) ?>">
      <?php if ($postId > 0): ?><input type="hidden" name="post_id" value="<?= $postId ?>"><?php endif; ?>
      <label class="admin-sr-only" for="comment-search"><?= e(t('admin.search_comments')) ?></label>
      <input class="input" id="comment-search" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('admin.search_author_email_content_or_ip')) ?>" maxlength="120">
      <button class="btn btn-outline" type="submit"><?= e(t('common.search')) ?></button>
      <?php if ($search !== ''): ?><a class="btn btn-ghost" href="<?= e(url_to('/admin/comments') . '?status=' . rawurlencode($status) . ($postId > 0 ? '&post_id=' . $postId : '')) ?>"><?= e(t('common.clear')) ?></a><?php endif; ?>
    </form>
  </div>

  <?php if ($items !== []): ?>
  <div class="admin-comment-list-tools">
    <label class="admin-comment-select-all"><input type="checkbox" id="commentCheckAll" aria-label="<?= e(t('admin.select_all_comments_on_this_page')) ?>"> <?= e(t('admin.select_all_on_this_page')) ?></label>
    <span><?= e(t('admin.this_page')) ?> <?= count($items) ?> <?= e(t('admin.items')) ?></span>
  </div>
  <form class="admin-batch-bar admin-comment-batch-bar" id="commentBatchForm" action="<?= e(url_to('/admin/comments/batch')) ?>" method="post" hidden>
    <?= csrf_field() ?>
    <span class="admin-batch-count"><?= e(t('admin.selected')) ?> <strong class="admin-batch-count-number">0</strong> <?= e(t('admin.items')) ?></span>
    <select class="input admin-batch-op" name="op" aria-label="<?= e(t('admin.batch_actions')) ?>">
      <option value=""><?= e(t('admin.choose_action')) ?></option>
      <option value="approve"><?= e(t('admin.approve_selected')) ?></option>
      <option value="spam"><?= e(t('admin.mark_as_spam')) ?></option>
      <option value="delete"><?= e(t('common.delete')) ?></option>
    </select>
    <button class="btn btn-outline" type="submit"><?= e(t('admin.app')) ?></button>
    <button class="btn btn-ghost" type="button" data-comment-batch-clear><?= e(t('admin.clear_selection')) ?></button>
  </form>
  <?php endif; ?>

  <?php if ($items === []): ?>
    <div class="admin-empty-list card">
      <?= admin_icon('message', 32) ?>
      <p><?= e(t('admin.no_comments_with_this_status')) ?></p>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap admin-comment-table-wrap">
      <table class="admin-table admin-comment-table">
        <thead>
          <tr><th class="admin-col-check" aria-label="<?= e(t('common.select')) ?>"></th><th><?= e(t('common.comments')) ?></th><th class="admin-table-secondary"><?= e(t('common.post')) ?></th><th class="admin-table-secondary"><?= e(t('admin.time')) ?></th><th class="admin-col-ops"><?= e(t('common.actions')) ?></th></tr>
        </thead>
        <tbody>
          <?php foreach ($items as $c): ?>
            <tr class="admin-comment-card" data-comment="<?= (int) $c['id'] ?>" data-ip="<?= e($c['ip'] ?? '') ?>" data-email="<?= e($c['author_email'] ?? '') ?>">
              <td class="admin-col-check" data-label="<?= e(t('common.select')) ?>"><input type="checkbox" class="comment-row-check" value="<?= (int) $c['id'] ?>" aria-label="<?= e(t('admin.select_comment', ['id' => (int) $c['id']])) ?>"></td>
              <td data-label="<?= e(t('common.comments')) ?>">
                <div class="admin-comment-head">
                  <span class="admin-comment-author"><?= e($c['author_name']) ?></span>
                  <span class="admin-comment-state"><?= e($tabs[$c['status']]) ?></span>
                  <?php if ((int) $c['is_pinned'] === 1): ?><span class="admin-comment-badge"><?= admin_icon('pin', 11) ?> <?= e(t('admin.pin')) ?></span><?php endif; ?>
                  <?php if ($c['parent_name'] !== null): ?><span class="admin-comment-parent"><?= e(t('admin.reply')) ?> <?= e($c['parent_name']) ?></span><?php endif; ?>
                </div>
                <div class="admin-comment-content"><?= e($c['content']) ?></div>
                <div class="admin-table-mobile-meta admin-comment-mobile-meta">
                  <a href="<?= e(url_to($c['target_path'])) ?>" target="_blank" rel="noopener"><?= e($c['post_title']) ?></a>
                  <time><?= e(format_date($c['created_at'], 'yyyy-MM-dd HH:mm')) ?></time>
                </div>
                <details class="admin-comment-details">
                  <summary><?= e(t('admin.replies_and_more_actions')) ?></summary>
                  <div class="admin-comment-source">
                    <?php if ($c['author_email'] !== ''): ?><span><?= e($c['author_email']) ?></span><?php endif; ?>
                    <?php if (($c['ip'] ?? '') !== ''): ?><span>IP <?= e($c['ip']) ?></span><?php endif; ?>
                  </div>
                  <div class="admin-comment-detail-actions">
                    <button type="button" class="btn btn-sm btn-ghost" data-reply="<?= (int) $c['id'] ?>"><?= e(t('admin.reply')) ?></button>
                    <button type="button" class="btn btn-sm btn-ghost" data-pin="<?= (int) $c['id'] ?>"><?= (int) $c['is_pinned'] === 1 ? t('admin.unpin') : t('admin.pin') ?></button>
                    <?php if (($c['ip'] ?? '') !== ''): ?>
                      <button type="button" class="btn btn-sm btn-ghost admin-icon-danger" data-delete-ip="<?= e($c['ip']) ?>"><?= e(t('admin.delete_by_ip')) ?></button>
                      <button type="button" class="btn btn-sm btn-ghost" data-block-ip="<?= e($c['ip']) ?>"><?= e(t('admin.block_ip')) ?></button>
                    <?php endif; ?>
                    <?php if (filter_var((string) ($c['author_email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false): ?><button type="button" class="btn btn-sm btn-ghost" data-block-email="<?= e($c['author_email']) ?>"><?= e(t('admin.block_email')) ?></button><?php endif; ?>
                  </div>
                  <div class="admin-comment-replybox" hidden>
                    <textarea class="admin-comment-reply-input" rows="3" maxlength="2000" aria-label="<?= e(t('admin.reply_to_comment')) ?>" placeholder="<?= e(t('admin.write_a_reply')) ?>"></textarea>
                    <div class="admin-comment-reply-actions"><button type="button" class="btn btn-sm btn-primary" data-reply-submit="<?= (int) $c['id'] ?>"><?= e(t('admin.reply')) ?></button><button type="button" class="btn btn-sm btn-ghost" data-reply-cancel><?= e(t('common.cancel')) ?></button></div>
                  </div>
                </details>
              </td>
              <td data-label="<?= e(t('common.post')) ?>" class="admin-comment-post admin-table-secondary">
                <a href="<?= e(url_to($c['target_path'])) ?>" target="_blank" rel="noopener"><?= e($c['post_title']) ?></a>
              </td>
              <td data-label="<?= e(t('admin.time')) ?>" class="admin-table-secondary admin-comment-time"><?= e(format_date($c['created_at'], 'yyyy-MM-dd HH:mm')) ?></td>
              <td data-label="<?= e(t('common.actions')) ?>" class="admin-col-ops">
                <div class="admin-comment-ops">
                  <?php if ($c['status'] !== 'APPROVED'): ?><button type="button" class="btn btn-sm btn-ghost" data-status="<?= (int) $c['id'] ?>" data-next="APPROVED"><?= e(t('admin.approve')) ?></button><?php endif; ?>
                  <?php if ($c['status'] !== 'SPAM'): ?><button type="button" class="btn btn-sm btn-ghost" data-status="<?= (int) $c['id'] ?>" data-next="SPAM"><?= e(t('admin.mark_spam')) ?></button><?php endif; ?>
                  <button type="button" class="btn btn-sm btn-ghost admin-icon-danger" data-delete="<?= (int) $c['id'] ?>"><?= e(t('common.delete')) ?></button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?= admin_pagination($page, $pages, $total, static function (int $p, int $per = 20) use ($status, $postId, $search): string {
      return url_to('/admin/comments') . '?status=' . rawurlencode($status) . ($postId > 0 ? '&post_id=' . $postId : '') . ($search !== '' ? '&q=' . rawurlencode($search) : '') . ($p > 1 ? '&page=' . $p : '') . ($per !== 20 ? '&per=' . $per : '');
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
    }).catch(function () { pafishNotify(window.pafishT("js.network_error"), true); return null; });
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
    var selectDisabled = count === 0;
    if (select.disabled !== selectDisabled) {
      select.disabled = selectDisabled;
      // admin-controls.js mirrors the native select's disabled state on its
      // visible trigger; keep that trigger usable as selections change.
      select.dispatchEvent(new Event("pafish:control-sync"));
    }
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
        pafishNotify(window.pafishT("js.select_comments_and_a_batch_action"), true);
        return;
      }
      var labels = {approve: window.pafishT("js.approve"), spam: window.pafishT("js.mark_as_spam"), delete: window.pafishT("common.delete")};
      var prompt = operation === "delete"
        ? window.pafishT("js.confirm_delete_comments", {n: ids.length})
        : window.pafishT("js.confirm_bulk_comment_action", {action: labels[operation], n: ids.length});
      var confirm = window.pafishConfirm
        ? window.pafishConfirm(prompt, {title: window.pafishT("js.batch_comment_actions"), danger: operation === "delete"})
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
            if (!result.ok || !result.json.ok) throw new Error(result.json.error || window.pafishT("js.batch_operation_failed"));
            pafishToastReload(window.pafishT("js.processed") + result.json.changed + window.pafishT("js.comments_f79a76"), "success");
          })
          .catch(function (error) {
            button.disabled = false;
            pafishNotify(error.message || window.pafishT("js.network_error"), true);
          });
      });
    });
  }
  updateBatchBar();

  // ---- 状态流转：通过 / 垃圾 ----
  document.querySelectorAll("[data-status]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var next = btn.getAttribute("data-next");
      var label = next === "APPROVED" ? window.pafishT("js.approve") : window.pafishT("js.mark_as_spam");
      (window.pafishConfirm ? window.pafishConfirm(window.pafishT("js.confirm_comment_action", {action: label}), { title: window.pafishT("js.update_comment_status") }) : Promise.resolve(window.confirm(window.pafishT("js.continue")))).then(function (ok) {
        if (!ok) return;
        return post(listUrl + "/" + btn.getAttribute("data-status") + "/status", { status: next })
        .then(function (r) { return jsonOrAlert(r, window.pafishT("common.operation_failed")); })
        .then(function (j) { if (j) pafishToastReload(window.pafishT("js.comment_status_updated"), "success"); });
      });
    });
  });

  // ---- 置顶 / 取消置顶 ----
  document.querySelectorAll("[data-pin]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      post(listUrl + "/" + btn.getAttribute("data-pin") + "/pin")
        .then(function (r) { return jsonOrAlert(r, window.pafishT("common.operation_failed")); })
        .then(function (j) { if (j) pafishToastReload(window.pafishT("js.comment_pin_status_updated"), "success"); });
    });
  });

  // ---- 删除确认 ----
  document.querySelectorAll("[data-delete]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var card = btn.closest("[data-comment]");
      var id = card.getAttribute("data-comment");
      (window.pafishConfirm ? window.pafishConfirm(window.pafishT("js.delete_this_comment_this_cannot_be_undone"), { title: window.pafishT("js.delete_comment") }) : Promise.resolve(window.confirm(window.pafishT("js.delete_this_comment")))).then(function (ok) {
        if (!ok) return;
        post(listUrl + "/" + id + "/delete")
          .then(function (r) { return jsonOrAlert(r, window.pafishT("js.delete_failed")); })
          .then(function (j) { if (j) pafishToastReload(window.pafishT("js.comment_deleted"), "success"); });
      });
    });
  });

  // ---- 按 IP 删除（提示删除条数） ----
  document.querySelectorAll("[data-delete-ip]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var ip = btn.getAttribute("data-delete-ip");
      (window.pafishConfirm ? window.pafishConfirm(window.pafishT("js.delete_all_comments_from_this_ip_address_this"), { title: window.pafishT("js.delete_comments_by_ip") }) : Promise.resolve(window.confirm(window.pafishT("js.confirm_deletion")))).then(function (ok) {
        if (!ok) return;
        return post(listUrl + "/delete-by-ip", { ip: ip })
        .then(function (r) { return jsonOrAlert(r, window.pafishT("common.operation_failed")); })
        .then(function (j) {
          if (j) pafishToastReload(window.pafishT("js.deleted") + (j.deleted || 0) + window.pafishT("js.comments_f79a76"), "success");
        });
      });
    });
  });

  // ---- 拉黑 IP ----
  document.querySelectorAll("[data-block-ip]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var ip = btn.getAttribute("data-block-ip");
      (window.pafishConfirm ? window.pafishConfirm(window.pafishT("js.block_this_ip_address_future_comments_will_be"), { title: window.pafishT("js.block_ip") }) : Promise.resolve(window.confirm(window.pafishT("js.confirm_block")))).then(function (ok) {
        if (!ok) return;
        return post(listUrl + "/block-ip", { ip: ip })
        .then(function (r) { return jsonOrAlert(r, window.pafishT("common.operation_failed")); })
        .then(function (j) { if (j) pafishNotify(window.pafishT("js.blocked") + ip, false); });
      });
    });
  });

  document.querySelectorAll("[data-block-email]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var email = btn.getAttribute("data-block-email");
      (window.pafishConfirm ? window.pafishConfirm(window.pafishT("js.block_this_email_address_future_comments_will_be"), { title: window.pafishT("js.block_email") }) : Promise.resolve(window.confirm(window.pafishT("js.confirm_block")))).then(function (ok) {
        if (!ok) return;
        return post(listUrl + "/block-email", { email: email })
        .then(function (r) { return jsonOrAlert(r, window.pafishT("common.operation_failed")); })
        .then(function (j) { if (j) pafishNotify(window.pafishT("js.blocked") + email, false); });
      });
    });
  });

  // ---- 回复（展开输入框） ----
  document.querySelectorAll("[data-reply]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var card = btn.closest("[data-comment]");
      var box = card.querySelector(".admin-comment-replybox");
      var details = btn.closest(".admin-comment-details");
      box.hidden = !box.hidden;
      if (!box.hidden) {
        if (details) details.open = true;
        box.querySelector("textarea").focus();
      }
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
      if (!content) { pafishNotify(window.pafishT("js.reply_content_is_required"), true); return; }
      post(listUrl + "/" + btn.getAttribute("data-reply-submit") + "/reply", { content: content })
        .then(function (r) { return jsonOrAlert(r, window.pafishT("js.reply_failed")); })
        .then(function (j) {
          if (j) {
            input.value = "";
            box.hidden = true;
            pafishToastReload(window.pafishT("js.reply_sent_the_comment_is_now_public"), "success");
          }
        });
    });
  });
})();
</script>
