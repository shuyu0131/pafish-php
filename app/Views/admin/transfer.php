<div class="admin-stack admin-transfer-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e(t('admin.content_migration')) ?></h1>
      <p class="admin-page-sub"><?= e(t('admin.migrate_posts_pages_categories_and_tags_between_pafish')) ?></p>
    </div>
  </div>

  <div class="admin-transfer-grid">
    <section class="card admin-transfer-section">
      <div class="admin-transfer-section-head">
        <div>
          <h2 class="admin-card-title"><?= e(t('admin.export_content')) ?></h2>
          <p class="admin-transfer-copy"><?= e(t('admin.create_a_json_content_package_to_import_into')) ?></p>
        </div>
        <?= admin_icon('download', 22) ?>
      </div>
      <a class="btn btn-primary" href="<?= e(url_to('/admin/tools/transfer/export')) ?>"><?= admin_icon('download', 15) ?><?= e(t('admin.export_json')) ?></a>
    </section>

    <section class="card admin-transfer-section">
      <div class="admin-transfer-section-head">
        <div>
          <h2 class="admin-card-title"><?= e(t('admin.import_content')) ?></h2>
          <p class="admin-transfer-copy"><?= e(t('admin.select_an_exported_json_file_or_paste_its')) ?></p>
        </div>
        <?= admin_icon('upload', 22) ?>
      </div>
      <form id="transferForm" method="post" enctype="multipart/form-data" action="<?= e(url_to('/admin/tools/transfer/import')) ?>">
        <?= csrf_field() ?>
        <div class="admin-transfer-fields">
          <label class="admin-field">
            <span class="label"><?= e(t('admin.json_file')) ?></span>
            <input class="input" type="file" name="file" accept="application/json,.json">
            <span class="admin-field-hint"><?= e(t('admin.file_import_is_recommended_pasting_is_unnecessary_after')) ?></span>
          </label>
          <label class="admin-field">
            <span class="label"><?= e(t('admin.or_paste_json')) ?></span>
            <textarea class="input admin-input-block" name="json" rows="9" placeholder="<?= e(t('admin.paste_an_exported_pafish_content_package')) ?>"></textarea>
          </label>
          <label class="admin-field">
            <span class="label"><?= e(t('admin.conflict_policy')) ?></span>
            <select class="input" name="mode">
              <option value="skip"><?= e(t('admin.skip_existing_slugs')) ?></option>
              <option value="update"><?= e(t('admin.overwrite_existing_slugs')) ?></option>
            </select>
          </label>
        </div>
        <div class="admin-transfer-actions">
          <button class="btn btn-primary" type="submit"><?= admin_icon('upload', 15) ?><?= e(t('admin.import_content')) ?></button>
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
    if (submit) { submit.disabled = true; submit.textContent = window.pafishT("js.importing"); }
    fetch(form.action, { method: "POST", body: new FormData(form) })
      .then(function (response) {
        return response.json().then(function (json) { return { ok: response.ok, json: json }; });
      })
      .then(function (result) {
        var json = result.json || {};
        if (!result.ok || !json.ok) throw new Error(json.error || window.pafishT("js.import_failed"));
        var c = json.counts || {};
        msg.textContent = window.pafishT("js.import_complete_counts", {posts: c.posts || 0, pages: c.pages || 0, categories: c.categories || 0, tags: c.tags || 0});
        msg.className = "admin-settings-msg admin-settings-msg-ok";
        msg.hidden = false;
      })
      .catch(function (error) {
        msg.textContent = error.message || window.pafishT("js.network_error_please_try_again_later");
        msg.className = "admin-settings-msg admin-settings-msg-error";
        msg.hidden = false;
      })
      .finally(function () {
        if (submit) { submit.disabled = false; submit.innerHTML = '<?= admin_icon('upload', 15) ?>' + window.pafishI18n.escape(window.pafishT('js.import_content')); }
      });
  });
})();
</script>
