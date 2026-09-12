<div class="admin-stack admin-transfer-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">内容迁移</h1>
      <p class="admin-page-sub">在不同的 Pafish 站点之间迁移文章、页面、分类和标签</p>
    </div>
  </div>

  <div class="admin-transfer-grid">
    <section class="card admin-transfer-section">
      <div class="admin-transfer-section-head">
        <div>
          <h2 class="admin-card-title">导出内容</h2>
          <p class="admin-transfer-copy">生成一个 JSON 内容包，可在另一套 Pafish 中导入。用户、媒体文件和站点设置不会包含在内。</p>
        </div>
        <?= admin_icon('download', 22) ?>
      </div>
      <a class="btn btn-primary" href="<?= e(url_to('/admin/tools/transfer/export')) ?>"><?= admin_icon('download', 15) ?>导出 JSON</a>
    </section>

    <section class="card admin-transfer-section">
      <div class="admin-transfer-section-head">
        <div>
          <h2 class="admin-card-title">导入内容</h2>
          <p class="admin-transfer-copy">选择导出的 JSON 文件，或直接粘贴内容。已有相同别名的内容可选择跳过或覆盖。</p>
        </div>
        <?= admin_icon('upload', 22) ?>
      </div>
      <form id="transferForm" method="post" enctype="multipart/form-data" action="<?= e(url_to('/admin/tools/transfer/import')) ?>">
        <?= csrf_field() ?>
        <div class="admin-transfer-fields">
          <label class="admin-field">
            <span class="label">JSON 文件</span>
            <input class="input" type="file" name="file" accept="application/json,.json">
            <span class="admin-field-hint">优先使用文件导入；选择文件后无需再粘贴。</span>
          </label>
          <label class="admin-field">
            <span class="label">或粘贴 JSON</span>
            <textarea class="input admin-input-block" name="json" rows="9" placeholder="粘贴导出的 pafish 内容包"></textarea>
          </label>
          <label class="admin-field">
            <span class="label">冲突策略</span>
            <select class="input" name="mode">
              <option value="skip">已有别名时跳过</option>
              <option value="update">已有别名时覆盖</option>
            </select>
          </label>
        </div>
        <div class="admin-transfer-actions">
          <button class="btn btn-primary" type="submit"><?= admin_icon('upload', 15) ?>导入内容</button>
          <p id="transferMsg" class="admin-settings-msg" hidden role="status" aria-live="polite"></p>
        </div>
      </form>
    </section>
  </div>
</div>

<script>
(function () {
  "use strict";
  var form = document.getElementById("transferForm");
  var msg = document.getElementById("transferMsg");
  if (!form || !msg) return;
  form.addEventListener("submit", function (event) {
    event.preventDefault();
    var submit = form.querySelector("button[type=submit]");
    if (submit) { submit.disabled = true; submit.textContent = "导入中…"; }
    fetch(form.action, { method: "POST", body: new FormData(form) })
      .then(function (response) {
        return response.json().then(function (json) { return { ok: response.ok, json: json }; });
      })
      .then(function (result) {
        var json = result.json || {};
        if (!result.ok || !json.ok) throw new Error(json.error || "导入失败");
        var c = json.counts || {};
        msg.textContent = "导入完成：处理 " + (c.posts || 0) + " 篇文章、" + (c.pages || 0) + " 个页面、" + (c.categories || 0) + " 个分类、" + (c.tags || 0) + " 个标签";
        msg.className = "admin-settings-msg admin-settings-msg-ok";
        msg.hidden = false;
        if (typeof window.pafishToast === "function") window.pafishToast(msg.textContent, "success");
      })
      .catch(function (error) {
        msg.textContent = error.message || "网络错误，请稍后重试";
        msg.className = "admin-settings-msg admin-settings-msg-error";
        msg.hidden = false;
        if (typeof window.pafishToast === "function") window.pafishToast(msg.textContent, "error");
      })
      .finally(function () {
        if (submit) { submit.disabled = false; submit.innerHTML = '<?= admin_icon('upload', 15) ?>导入内容'; }
      });
  });
})();
</script>
