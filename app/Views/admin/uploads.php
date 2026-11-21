<?php
/**
 * 媒体库：
 * 变量：$items $total $page $pages $q $type $date
 * 工具栏：搜索（500ms 防抖）/ 5 类筛选 / 上传媒体（多选顺序上传）/ 添加外部资源
 * 网格卡片：缩略图（图片 object-contain，非图片类型图标+扩展名徽标）、外链徽标、
 *          文件名、宽×高 · 大小 · 时间、复制 URL / 新窗口 / 删除
 * 分页：上一页/下一页 + 当前页 ±2 窗口
 */
$kindOf = function (array $u): string {
    $url = (string) ($u['url'] ?? '');
    $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) return 'image';
    if (in_array($ext, ['mp4', 'webm', 'mov', 'mkv'], true)) return 'video';
    if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'flac'], true)) return 'audio';
    if (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'], true)) return 'archive';
    $mime = (string) ($u['mime'] ?? '');
    if (str_starts_with($mime, 'image/')) return 'image';
    if (str_starts_with($mime, 'video/')) return 'video';
    if (str_starts_with($mime, 'audio/')) return 'audio';
    if (str_contains($mime, 'zip') || str_contains($mime, 'compressed')) return 'archive';
    return 'doc';
};
$kindIcon = ['image' => 'image', 'video' => 'file-video', 'audio' => 'file-audio', 'archive' => 'file-archive', 'doc' => 'file-text'];
$formatSize = function (int $size): string {
    if ($size <= 0) return t('admin.external');
    if ($size < 1024) return $size . ' B';
    if ($size < 1048576) return round($size / 1024, 1) . ' KB';
    return round($size / 1048576, 2) . ' MB';
};
$filters = [
    '' => t('common.all'), 'image' => t('common.image'), 'doc' => t('admin.document'), 'archive' => t('admin.archive'), 'audio' => t('admin.audio'), 'video' => t('admin.video'),
];
$view = (($_GET['view'] ?? 'grid') === 'list') ? 'list' : 'grid';
// 分页/筛选链接：t 传空=不筛选，传筛选值时保留当前搜索词
$date = $date ?? '';
$pageUrl = function (int $p, string $t = '', int $size = 48) use ($q, $date, $view): string {
    $qs = [];
    if ($p > 1) $qs['page'] = $p;
    if ($q !== '') $qs['q'] = $q;
    if ($t !== '') $qs['type'] = $t;
    if ($date !== '') $qs['date'] = $date;
    if ($view !== 'grid') $qs['view'] = $view;
    if ($size !== 48) $qs['per'] = $size;
    return url_to('/admin/uploads' . ($qs === [] ? '' : '?' . http_build_query($qs)));
};
$pageCsrf = csrf_token();
$accept = 'image/*,.svg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.md,.csv,.zip,.rar,.7z,.tar,.gz,.mp3,.wav,.ogg,.m4a,.flac,.mp4,.webm,.mov,.mkv';
?>
<div class="admin-stack admin-detail-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e(t('admin.media_library')) ?></h1>
      <p class="admin-page-sub"><?= e(t('admin.total')) ?> <?= (int) $total ?> <?= e(t('admin.media_files')) ?></p>
    </div>
    <div class="admin-head-actions">
<?php if ($canRebuildReferences ?? false): ?>
      <button type="button" class="btn btn-outline" id="btnRebuildReferences"><?= e(t('admin.rebuild_media_references')) ?></button>
<?php endif; ?>
<button type="button" class="btn btn-outline" id="btnAddExternal" title="<?= e(t('admin.add_external_resource')) ?>" aria-label="<?= e(t('admin.add_external_resource')) ?>"><?= admin_icon('link', 15) ?><span><?= e(t('admin.add_external_resource')) ?></span></button>
<button type="button" class="btn btn-primary" id="btnUploadMedia"><?= admin_icon('upload', 15) ?><span id="btnUploadLabel"><?= e(t('admin.upload_media')) ?></span></button></div>
  </div>

  <?php if (!($referencesReady ?? true)): ?>
    <p role="status"><?= e(t('admin.media_references_must_be_rebuilt_before_files_can')) ?></p>
  <?php endif; ?>
  <div class="admin-media-toolbar">
    <div class="admin-media-query">
      <input type="search" class="input admin-media-search" id="mediaQ" placeholder="<?= e(t('admin.search_filenames')) ?>" value="<?= e($q) ?>" autocomplete="off">
      <input type="date" class="input admin-media-date" id="mediaDate" value="<?= e($date) ?>" title="<?= e(t('admin.filter_by_date')) ?>">
    </div>
    <div class="admin-media-filters">
      <?php foreach ($filters as $val => $label): ?>
        <a class="admin-media-filter <?= $type === $val ? 'active' : '' ?>" href="<?= e($pageUrl(1, $val, (int) ($per ?? 48))) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
    <?php
      $viewParams = [];
      if ($q !== '') $viewParams['q'] = $q;
      if ($type !== '') $viewParams['type'] = $type;
      if ($date !== '') $viewParams['date'] = $date;
      $viewBase = url_to('/admin/uploads') . ($viewParams === [] ? '' : '?' . http_build_query($viewParams));
      $viewSep = $viewParams === [] ? '?' : '&';
    ?>
    <div class="admin-media-view-toggle" role="group" aria-label="<?= e(t('admin.media_view')) ?>">
      <a class="admin-icon-btn<?= $view === 'grid' ? ' active' : '' ?>" href="<?= e($viewBase . $viewSep . 'view=grid') ?>" title="<?= e(t('admin.grid_view')) ?>" aria-label="<?= e(t('admin.grid_view')) ?>"><?= admin_icon('dashboard', 15) ?></a>
      <a class="admin-icon-btn<?= $view === 'list' ? ' active' : '' ?>" href="<?= e($viewBase . $viewSep . 'view=list') ?>" title="<?= e(t('admin.list_view')) ?>" aria-label="<?= e(t('admin.list_view')) ?>"><?= admin_icon('list', 15) ?></a>
    </div>
    <input type="file" id="mediaFiles" multiple hidden accept="<?= e($accept) ?>">
  </div>
  <div class="admin-upload-progress" id="mediaUploadProgress" hidden aria-live="polite">
    <div class="admin-upload-progress-head"><span id="mediaUploadProgressLabel"><?= e(t('admin.preparing_upload')) ?></span><strong id="mediaUploadProgressValue">0%</strong></div>
    <div class="admin-upload-progress-track"><span id="mediaUploadProgressBar"></span></div>
  </div>

  <?php if ($items === []): ?>
    <div class="admin-empty-list">
      <?= admin_icon('image', 32) ?>
      <p><?= ($q !== '' || $type !== '') ? t('admin.no_media_matches_the_current_filters') : t('admin.no_media_yet') ?></p>
    </div>
  <?php else: ?>
    <?php if ($view === 'grid'): ?>
    <div class="admin-media-grid">
      <?php foreach ($items as $u): ?>
        <?php $kind = $kindOf($u); $isExternal = !str_contains((string) $u['url'], '/uploads/');
              $ext = strtolower(pathinfo((string) parse_url((string) $u['url'], PHP_URL_PATH), PATHINFO_EXTENSION)); ?>
        <div class="admin-media-card" data-media-id="<?= (int) $u['id'] ?>">
          <div class="admin-media-thumb">
            <?php if ($kind === 'image'): ?>
              <img src="<?= e($u['url']) ?>" alt="" loading="lazy">
            <?php else: ?>
              <span class="admin-media-kind"><?= admin_icon($kindIcon[$kind], 30) ?></span>
              <span class="admin-media-ext"><?= e(strtoupper($ext !== '' ? $ext : 'LINK')) ?></span>
            <?php endif; ?>
            <?php if ($isExternal): ?>
              <span class="admin-media-external"><?= admin_icon('globe', 11) ?><?= e(t('admin.external_link')) ?></span>
            <?php endif; ?>
          </div>
          <div class="admin-media-info">
            <p class="admin-media-name" title="<?= e($u['original_name']) ?>"><?= e($u['original_name']) ?></p>
            <p class="admin-media-meta">
              <?php if ($u['width'] !== null && $u['height'] !== null): ?><?= (int) $u['width'] ?>×<?= (int) $u['height'] ?> · <?php endif; ?>
              <?= $formatSize((int) $u['size']) ?> · <?= e(format_date($u['created_at'], 'yyyy-MM-dd HH:mm')) ?> <?= e(t('admin.references')) ?> <?= (int) ($u['usage_count'] ?? 0) ?> <?= e(t('admin.times')) ?>
            </p>
            <div class="admin-media-ops">
              <button type="button" class="admin-icon-btn" data-copy-url="<?= e(absolute_url($u['url'])) ?>" title="<?= e(t('admin.copy_full_url_including_domain')) ?>"><?= admin_icon('copy', 14) ?></button>
              <a class="admin-icon-btn" href="<?= e($u['url']) ?>" target="_blank" rel="noopener" title="<?= e(t('admin.open_in_a_new_tab')) ?>"><?= admin_icon('external-link', 14) ?></a>
              <button type="button" class="admin-icon-btn admin-icon-danger" data-delete-media="<?= (int) $u['id'] ?>"
                      data-name="<?= e($u['original_name']) ?>" title="<?= e(t('common.delete')) ?>"><?= admin_icon('trash', 14) ?></button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="admin-table-wrap admin-media-table-wrap">
      <table class="admin-table admin-media-table">
        <thead><tr><th><?= e(t('admin.file')) ?></th><th class="admin-table-secondary"><?= e(t('admin.type')) ?></th><th class="admin-table-secondary"><?= e(t('admin.size')) ?></th><th class="admin-table-secondary"><?= e(t('admin.uploaded')) ?></th><th class="admin-table-secondary"><?= e(t('admin.references_23c0e1')) ?></th><th class="admin-col-ops"><?= e(t('common.actions')) ?></th></tr></thead>
        <tbody>
          <?php foreach ($items as $u): ?>
            <?php $kind = $kindOf($u); $isExternal = !str_contains((string) $u['url'], '/uploads/'); $ext = strtolower(pathinfo((string) parse_url((string) $u['url'], PHP_URL_PATH), PATHINFO_EXTENSION)); ?>
            <tr class="admin-media-row" data-media-id="<?= (int) $u['id'] ?>">
              <td data-label="<?= e(t('admin.file')) ?>">
                <div class="admin-media-list-name"><span class="admin-media-list-icon"><?= admin_icon($kindIcon[$kind], 18) ?></span><span title="<?= e($u['original_name']) ?>"><?= e($u['original_name']) ?></span><?php if ($isExternal): ?><span class="badge"><?= e(t('admin.external_link')) ?></span><?php endif; ?></div>
                <div class="admin-table-mobile-meta">
                  <span><?= e(strtoupper($ext !== '' ? $ext : $kind)) ?></span><span><?= e($formatSize((int) $u['size'])) ?></span><span><?= e(t('admin.references_23c0e1')) ?> <?= (int) ($u['usage_count'] ?? 0) ?></span>
                  <time title="<?= e(t('admin.uploaded')) ?>" datetime="<?= e((string) $u['created_at']) ?>"><?= e(format_date($u['created_at'], 'yyyy-MM-dd HH:mm')) ?></time>
                </div>
              </td>
              <td data-label="<?= e(t('admin.type')) ?>" class="admin-table-secondary"><?= e(strtoupper($ext !== '' ? $ext : $kind)) ?></td>
              <td data-label="<?= e(t('admin.size')) ?>" class="admin-table-secondary"><?= e($formatSize((int) $u['size'])) ?></td>
              <td data-label="<?= e(t('admin.uploaded')) ?>" class="admin-media-list-date admin-table-secondary"><?= e(format_date($u['created_at'], 'yyyy-MM-dd HH:mm')) ?></td>
              <td data-label="<?= e(t('admin.references_23c0e1')) ?>" class="admin-table-secondary"><?= (int) ($u['usage_count'] ?? 0) ?></td>
              <td data-label="<?= e(t('common.actions')) ?>" class="admin-col-ops"><div class="admin-media-ops"><button type="button" class="admin-icon-btn" data-copy-url="<?= e(absolute_url($u['url'])) ?>" title="<?= e(t('admin.copy_full_url')) ?>"><?= admin_icon('copy', 14) ?></button><a class="admin-icon-btn" href="<?= e($u['url']) ?>" target="_blank" rel="noopener" title="<?= e(t('admin.open_in_a_new_tab')) ?>"><?= admin_icon('external-link', 14) ?></a><button type="button" class="admin-icon-btn admin-icon-danger" data-delete-media="<?= (int) $u['id'] ?>" data-name="<?= e($u['original_name']) ?>" title="<?= e(t('common.delete')) ?>"><?= admin_icon('trash', 14) ?></button></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?= admin_pagination($page, $pages, $total, static function (int $p, int $size = 48) use ($pageUrl, $type): string {
      return $pageUrl($p, $type, $size);
    }, (int) ($per ?? 48), [24, 48, 96]) ?>
  <?php endif; ?>
</div>

<div class="admin-modal-backdrop" id="extModal" hidden>
  <div class="admin-modal" role="dialog" aria-modal="true" aria-label="<?= e(t('admin.add_external_resource')) ?>">
    <div class="admin-modal-head">
      <h3 class="admin-modal-title"><?= e(t('admin.add_external_resource')) ?></h3>
      <button type="button" class="admin-icon-btn" data-close-ext data-modal-close aria-label="<?= e(t('common.close')) ?>"><?= admin_icon('x', 16) ?></button>
    </div>
    <div class="admin-modal-body">
      <p class="admin-field-hint"><?= e(t('admin.save_the_link_without_downloading_images_can_be')) ?></p>
      <label class="admin-field">
        <span class="label"><?= e(t('admin.resource_url')) ?></span>
        <input type="url" class="input" id="extUrl" placeholder="https://example.com/image.png" autocomplete="off">
      </label>
      <label class="admin-field">
        <span class="label"><?= e(t('admin.display_name_optional')) ?></span>
        <input type="text" class="input" id="extName" placeholder="<?= e(t('admin.defaults_to_the_filename_in_the_url')) ?>" autocomplete="off">
      </label>
      <p class="admin-modal-error" id="extError" hidden></p>
    </div>
    <div class="admin-modal-actions">
      <button type="button" class="btn btn-outline" data-close-ext data-modal-close><?= e(t('common.cancel')) ?></button>
      <button type="button" class="btn btn-primary" id="extSave"><?= e(t('common.add')) ?></button>
    </div>
  </div>
</div>

<script>
(function () {
  var rebuildBtn = document.getElementById("btnRebuildReferences");
  if (rebuildBtn) rebuildBtn.addEventListener("click", function () {
    rebuildBtn.disabled = true;
    rebuildBtn.textContent = window.pafishT("js.rebuilding");
    post(<?= json_encode(url_to('/admin/uploads/rebuild-references')) ?>)
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) throw new Error((j && j.error) || window.pafishT("js.unable_to_rebuild_references"));
        pafishToastReload(window.pafishT("js.media_references_rebuilt"), "success");
      })
      .catch(function (error) { pafishNotify(error.message || window.pafishT("js.unable_to_rebuild_references"), true); })
      .finally(function () { rebuildBtn.disabled = false; rebuildBtn.textContent = window.pafishT("js.rebuild_media_references"); });
  });
  var token = <?= json_encode($pageCsrf) ?>;
  function post(url, body) {
    var fd = new FormData();
    Object.keys(body || {}).forEach(function (k) { fd.append(k, body[k]); });
    fd.append("_csrf", token);
    return fetch(url, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } });
  }

  // 搜索与日期筛选，保留其它筛选条件
  var qInput = document.getElementById("mediaQ");
  var qTimer = null;
  qInput.addEventListener("input", function () {
    clearTimeout(qTimer);
    var v = qInput.value.trim();
    qTimer = setTimeout(function () {
      var qs = [];
      if (v !== "") qs.push("q=" + encodeURIComponent(v));
      var type = <?= json_encode($type) ?>;
      if (type) qs.push("type=" + encodeURIComponent(type));
      var date = document.getElementById("mediaDate").value;
      if (date) qs.push("date=" + encodeURIComponent(date));
      var per = new URLSearchParams(window.location.search).get("per");
      if (per) qs.push("per=" + encodeURIComponent(per));
      var view = <?= json_encode($view) ?>;
      if (view !== "grid") qs.push("view=" + encodeURIComponent(view));
      location.href = <?= json_encode(url_to('/admin/uploads')) ?> + (qs.length ? "?" + qs.join("&") : "");
    }, 500);
  });
  document.getElementById("mediaDate").addEventListener("change", function () { qInput.dispatchEvent(new Event("input")); });

  // 上传媒体：多选，逐个顺序上传，完成后刷新
  var uploadBtn = document.getElementById("btnUploadMedia");
  var uploadLabel = document.getElementById("btnUploadLabel");
  var fileInput = document.getElementById("mediaFiles");
  var progress = document.getElementById("mediaUploadProgress");
  var progressLabel = document.getElementById("mediaUploadProgressLabel");
  var progressValue = document.getElementById("mediaUploadProgressValue");
  var progressBar = document.getElementById("mediaUploadProgressBar");
  var chunkUrl = <?= json_encode(url_to('/api/upload/chunk')) ?>;
  uploadBtn.addEventListener("click", function () { fileInput.click(); });
  fileInput.addEventListener("change", function () {
    var files = Array.prototype.slice.call(fileInput.files || []);
    if (!files.length) return;
    var failed = 0;
    var i = 0;
    uploadBtn.disabled = true;
    if (progress) progress.hidden = false;
    function next() {
      if (i >= files.length) {
        if (progressBar) progressBar.style.width = "100%";
        if (progressValue) progressValue.textContent = "100%";
        if (failed) pafishToastReload(window.pafishT("js.upload_complete_failed") + failed + window.pafishT("js.files"), "error");
        else pafishToastReload(window.pafishT("js.file_uploaded"), "success");
        return;
      }
      var f = files[i++];
      uploadLabel.textContent = window.pafishT("js.uploading_a69211") + f.name.slice(0, 12) + "…";
      var chunkSize = 1024 * 1024;
      var uploadId = Array.from(crypto.getRandomValues(new Uint8Array(16))).map(function (n) { return n.toString(16).padStart(2, "0"); }).join("");
      var totalChunks = Math.max(1, Math.ceil(f.size / chunkSize));
      var sent = 0;
      var fileIndex = i - 1;
      function sendChunk(index) {
        var start = index * chunkSize;
        var xhr = new XMLHttpRequest();
        xhr.open("POST", chunkUrl, true);
        xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
        xhr.upload.onprogress = function (event) {
          if (!event.lengthComputable) return;
          var current = Math.min(f.size, sent + event.loaded);
          var overall = ((fileIndex + current / f.size) / files.length) * 100;
          var percent = Math.round(overall);
          uploadLabel.textContent = window.pafishT("js.uploading_8f7843") + percent + "%：" + f.name.slice(0, 12);
          if (progressLabel) progressLabel.textContent = f.name;
          if (progressValue) progressValue.textContent = percent + "%";
          if (progressBar) progressBar.style.width = percent + "%";
        };
        xhr.onload = function () {
          var j = null;
          try { j = JSON.parse(xhr.responseText); } catch (e) {}
          if (window.pafishSessionError && window.pafishSessionError(xhr.status, j)) {
            // Stop the queue; preserve the selected files and the current page.
            uploadBtn.disabled = false;
            uploadLabel.textContent = window.pafishT("js.upload_failed");
            if (progress) progress.hidden = true;
            return;
          }
          if (xhr.status < 200 || xhr.status >= 300 || !j || !j.ok) { failed++; next(); return; }
          sent += Math.min(chunkSize, f.size - start);
          if (index + 1 < totalChunks) sendChunk(index + 1);
          else next();
        };
        xhr.onerror = function () { failed++; next(); };
        var fd = new FormData();
        fd.append("file", f.slice(start, Math.min(start + chunkSize, f.size)), f.name);
        fd.append("upload_id", uploadId);
        fd.append("chunk_index", String(index));
        fd.append("chunk_total", String(totalChunks));
        fd.append("filename", f.name);
        fd.append("_csrf", token);
        xhr.send(fd);
      }
      sendChunk(0);
    }
    next();
  });

  // 复制 URL（成功短暂显示"已复制"）
  document.querySelectorAll("[data-copy-url]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var url = btn.getAttribute("data-copy-url");
      function done() {
        var old = btn.innerHTML;
        btn.innerHTML = <?= json_encode(admin_icon('check', 14)) ?>;
        btn.title = window.pafishT("js.copied");
        setTimeout(function () { btn.innerHTML = old; btn.title = window.pafishT("js.copy_url"); }, 1500);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(done).catch(function () { done(); });
      } else {
        var ta = document.createElement("textarea");
        ta.value = url;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand("copy"); } catch (e) {}
        document.body.removeChild(ta);
        done();
      }
    });
  });

  // 删除：被引用媒体需要二次确认后强制删除
  document.querySelectorAll("[data-delete-media]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var id = btn.getAttribute("data-delete-media");
      var name = btn.getAttribute("data-name") || "";
      (window.pafishConfirm ? window.pafishConfirm(window.pafishT("js.confirm_delete_media", {name: name}), { title: window.pafishT("js.delete_media") }) : Promise.resolve(window.confirm(window.pafishT("js.confirm_delete_media", {name: name})))).then(function (ok) {
        if (!ok) return;
        return post(<?= json_encode(url_to('/admin/uploads')) ?> + "/" + id + "/delete")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) pafishToastReload(window.pafishT("js.media_deleted"), "success");
          else if (j && j.usageCount) {
            (window.pafishConfirm ? window.pafishConfirm(window.pafishT("js.confirm_force_delete_media", {n: j.usageCount}), { title: window.pafishT("js.force_delete_media") }) : Promise.resolve(window.confirm(window.pafishT("js.confirm_force_delete")))).then(function (force) {
              if (force) post(<?= json_encode(url_to('/admin/uploads')) ?> + "/" + id + "/delete", { force: "1" })
                .then(function (r) { return r.json(); })
                .then(function (forced) { if (forced && forced.ok) pafishToastReload(window.pafishT("js.media_permanently_deleted"), "success"); else pafishNotify((forced && forced.error) || window.pafishT("js.force_delete_failed"), true); })
                .catch(function () { pafishNotify(window.pafishT("js.network_error"), true); });
            });
          } else pafishNotify((j && j.error) || window.pafishT("js.delete_failed"), true);
        })
        .catch(function () { pafishNotify(window.pafishT("js.network_error"), true); });
      });
    });
  });

  // 外部资源弹窗
  var extModal = document.getElementById("extModal");
  var extUrl = document.getElementById("extUrl");
  var extName = document.getElementById("extName");
  var extError = document.getElementById("extError");
  function openExt() { extError.hidden = true; extModal.hidden = false; extUrl.value = ""; extName.value = ""; extUrl.focus(); }
  function closeExt() { extModal.hidden = true; }
  document.getElementById("btnAddExternal").addEventListener("click", openExt);
  document.querySelectorAll("[data-close-ext]").forEach(function (b) { b.addEventListener("click", closeExt); });
  extModal.addEventListener("click", function (e) { if (e.target === extModal) closeExt(); });
  document.getElementById("extSave").addEventListener("click", function () {
    var url = extUrl.value.trim();
    if (!/^https?:\/\//i.test(url)) { extError.textContent = window.pafishT("js.enter_a_complete_url_beginning_with_http_or"); extError.hidden = false; return; }
    post(<?= json_encode(url_to('/admin/uploads/external')) ?>, { url: url, name: extName.value.trim() })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) pafishToastReload(window.pafishT("js.external_media_added"), "success");
        else { extError.textContent = (j && j.error) || window.pafishT("js.unable_to_add"); extError.hidden = false; }
      })
      .catch(function () { extError.textContent = window.pafishT("js.network_error"); extError.hidden = false; });
  });
})();
</script>
