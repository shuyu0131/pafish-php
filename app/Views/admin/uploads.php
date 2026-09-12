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
    if ($size <= 0) return '外部';
    if ($size < 1024) return $size . ' B';
    if ($size < 1048576) return round($size / 1024, 1) . ' KB';
    return round($size / 1048576, 2) . ' MB';
};
$filters = [
    '' => '全部', 'image' => '图片', 'doc' => '文档', 'archive' => '压缩包', 'audio' => '音频', 'video' => '视频',
];
$view = (($_GET['view'] ?? 'grid') === 'list') ? 'list' : 'grid';
// 分页/筛选链接：t 传空=不筛选，传筛选值时保留当前搜索词
$date = $date ?? '';
$pageUrl = function (int $p, string $t = '') use ($q, $date, $view): string {
    $qs = [];
    if ($p > 1) $qs['page'] = $p;
    if ($q !== '') $qs['q'] = $q;
    if ($t !== '') $qs['type'] = $t;
    if ($date !== '') $qs['date'] = $date;
    if ($view !== 'grid') $qs['view'] = $view;
    return url_to('/admin/uploads' . ($qs === [] ? '' : '?' . http_build_query($qs)));
};
$pageCsrf = csrf_token();
$accept = 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.md,.csv,.zip,.rar,.7z,.tar,.gz,.mp3,.wav,.ogg,.m4a,.flac,.mp4,.webm,.mov,.mkv';
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">媒体库</h1>
      <p class="admin-page-sub">共 <?= (int) $total ?> 个媒体，支持本地上传与外部链接（图片自动压缩，大小限制可在站点设置调整）</p>
    </div>
  </div>

  <div class="admin-media-toolbar">
    <input type="search" class="input admin-media-search" id="mediaQ" placeholder="搜索文件名…" value="<?= e($q) ?>" autocomplete="off">
    <input type="date" class="input" id="mediaDate" value="<?= e($date) ?>" title="按日期筛选">
    <div class="admin-media-filters">
      <?php foreach ($filters as $val => $label): ?>
        <a class="admin-media-filter <?= $type === $val ? 'active' : '' ?>" href="<?= e($pageUrl(1, $val)) ?>"><?= e($label) ?></a>
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
    <div class="admin-media-view-toggle" role="group" aria-label="媒体视图">
      <a class="admin-icon-btn<?= $view === 'grid' ? ' active' : '' ?>" href="<?= e($viewBase . $viewSep . 'view=grid') ?>" title="网格视图" aria-label="网格视图"><?= admin_icon('dashboard', 15) ?></a>
      <a class="admin-icon-btn<?= $view === 'list' ? ' active' : '' ?>" href="<?= e($viewBase . $viewSep . 'view=list') ?>" title="列表视图" aria-label="列表视图"><?= admin_icon('list', 15) ?></a>
    </div>
    <div class="admin-head-actions">
      <button type="button" class="btn btn-primary" id="btnUploadMedia">
        <?= admin_icon('upload', 15) ?><span id="btnUploadLabel">上传媒体</span>
      </button>
      <input type="file" id="mediaFiles" multiple hidden accept="<?= e($accept) ?>">
      <button type="button" class="btn btn-outline" id="btnAddExternal"><?= admin_icon('link', 15) ?>添加外部资源</button>
    </div>
  </div>

  <?php if ($items === []): ?>
    <div class="admin-empty-list">
      <?= admin_icon('image', 32) ?>
      <p><?= ($q !== '' || $type !== '') ? '没有符合筛选条件的媒体' : '还没有媒体' ?></p>
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
              <span class="admin-media-external"><?= admin_icon('globe', 11) ?>外链</span>
            <?php endif; ?>
          </div>
          <div class="admin-media-info">
            <p class="admin-media-name" title="<?= e($u['original_name']) ?>"><?= e($u['original_name']) ?></p>
            <p class="admin-media-meta">
              <?php if ($u['width'] !== null && $u['height'] !== null): ?><?= (int) $u['width'] ?>×<?= (int) $u['height'] ?> · <?php endif; ?>
              <?= $formatSize((int) $u['size']) ?> · <?= e(format_date($u['created_at'], 'yyyy-MM-dd HH:mm')) ?> · 引用 <?= (int) ($u['usage_count'] ?? 0) ?> 次
            </p>
            <div class="admin-media-ops">
              <button type="button" class="admin-icon-btn" data-copy-url="<?= e(absolute_url($u['url'])) ?>" title="复制完整 URL（含域名）"><?= admin_icon('copy', 14) ?></button>
              <a class="admin-icon-btn" href="<?= e($u['url']) ?>" target="_blank" rel="noopener" title="新窗口打开"><?= admin_icon('external-link', 14) ?></a>
              <button type="button" class="admin-icon-btn admin-icon-danger" data-delete-media="<?= (int) $u['id'] ?>"
                      data-name="<?= e($u['original_name']) ?>" title="删除"><?= admin_icon('trash', 14) ?></button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="admin-table-wrap admin-media-table-wrap">
      <table class="admin-table admin-media-table">
        <thead><tr><th>文件</th><th>类型</th><th>大小</th><th>上传时间</th><th>引用</th><th class="admin-col-ops">操作</th></tr></thead>
        <tbody>
          <?php foreach ($items as $u): ?>
            <?php $kind = $kindOf($u); $isExternal = !str_contains((string) $u['url'], '/uploads/'); $ext = strtolower(pathinfo((string) parse_url((string) $u['url'], PHP_URL_PATH), PATHINFO_EXTENSION)); ?>
            <tr class="admin-media-row" data-media-id="<?= (int) $u['id'] ?>">
              <td data-label="文件"><div class="admin-media-list-name"><span class="admin-media-list-icon"><?= admin_icon($kindIcon[$kind], 18) ?></span><span title="<?= e($u['original_name']) ?>"><?= e($u['original_name']) ?></span><?php if ($isExternal): ?><span class="badge">外链</span><?php endif; ?></div></td>
              <td data-label="类型"><?= e(strtoupper($ext !== '' ? $ext : $kind)) ?></td>
              <td data-label="大小"><?= e($formatSize((int) $u['size'])) ?></td>
              <td data-label="上传时间" class="admin-media-list-date"><?= e(format_date($u['created_at'], 'yyyy-MM-dd HH:mm')) ?></td>
              <td data-label="引用"><?= (int) ($u['usage_count'] ?? 0) ?></td>
              <td data-label="操作" class="admin-col-ops"><div class="admin-media-ops"><button type="button" class="admin-icon-btn" data-copy-url="<?= e(absolute_url($u['url'])) ?>" title="复制完整 URL"><?= admin_icon('copy', 14) ?></button><a class="admin-icon-btn" href="<?= e($u['url']) ?>" target="_blank" rel="noopener" title="新窗口打开"><?= admin_icon('external-link', 14) ?></a><button type="button" class="admin-icon-btn admin-icon-danger" data-delete-media="<?= (int) $u['id'] ?>" data-name="<?= e($u['original_name']) ?>" title="删除"><?= admin_icon('trash', 14) ?></button></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
      <div class="admin-pagination">
        <?php if ($page > 1): ?><a class="admin-pg-btn" href="<?= e($pageUrl($page - 1, $type)) ?>">上一页</a><?php endif; ?>
        <?php foreach (range(max(1, $page - 2), min($pages, $page + 2)) as $n): ?>
          <?php if ($n === $page): ?><span class="admin-pg-btn active"><?= $n ?></span>
          <?php else: ?><a class="admin-pg-btn" href="<?= e($pageUrl($n, $type)) ?>"><?= $n ?></a><?php endif; ?>
        <?php endforeach; ?>
        <?php if ($page < $pages): ?><a class="admin-pg-btn" href="<?= e($pageUrl($page + 1, $type)) ?>">下一页</a><?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="admin-modal-backdrop" id="extModal" hidden>
  <div class="admin-modal" role="dialog" aria-modal="true" aria-label="添加外部资源">
    <div class="admin-modal-head">
      <h3 class="admin-modal-title">添加外部资源</h3>
      <button type="button" class="admin-icon-btn" data-close-ext data-modal-close aria-label="关闭"><?= admin_icon('x', 16) ?></button>
    </div>
    <div class="admin-modal-body">
      <p class="admin-field-hint">仅保存链接不下载文件；图片可在编辑器直接插入，其他链接插入为下载链接</p>
      <label class="admin-field">
        <span class="label">资源地址 *</span>
        <input type="url" class="input" id="extUrl" placeholder="https://example.com/image.png" autocomplete="off">
      </label>
      <label class="admin-field">
        <span class="label">显示名称（可选）</span>
        <input type="text" class="input" id="extName" placeholder="默认取地址文件名" autocomplete="off">
      </label>
      <p class="admin-modal-error" id="extError" hidden></p>
    </div>
    <div class="admin-modal-actions">
      <button type="button" class="btn btn-outline" data-close-ext data-modal-close>取消</button>
      <button type="button" class="btn btn-primary" id="extSave">添加</button>
    </div>
  </div>
</div>

<script>
(function () {
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
  uploadBtn.addEventListener("click", function () { fileInput.click(); });
  fileInput.addEventListener("change", function () {
    var files = Array.prototype.slice.call(fileInput.files || []);
    if (!files.length) return;
    var failed = 0;
    var i = 0;
    uploadBtn.disabled = true;
    function next() {
      if (i >= files.length) {
        if (failed) pafishToastReload("上传完成，失败 " + failed + " 个文件", "error");
        else pafishToastReload("文件上传成功", "success");
        return;
      }
      var f = files[i++];
      uploadLabel.textContent = "上传中：" + f.name.slice(0, 12) + "…";
      var fd = new FormData();
      fd.append("file", f);
      fd.append("_csrf", token);
      fetch(<?= json_encode(url_to('/api/upload')) ?>, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (!j || !j.ok) failed++; next(); })
        .catch(function () { failed++; next(); });
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
        btn.title = "已复制";
        setTimeout(function () { btn.innerHTML = old; btn.title = "复制 URL"; }, 1500);
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
      (window.pafishConfirm ? window.pafishConfirm("确定删除「" + name + "」？\n\n已在文章中引用的文件将无法显示（不可恢复）。", { title: "删除媒体" }) : Promise.resolve(window.confirm("确定删除「" + name + "」？"))).then(function (ok) {
        if (!ok) return;
        return post(<?= json_encode(url_to('/admin/uploads')) ?> + "/" + id + "/delete")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) pafishToastReload("媒体已删除", "success");
          else if (j && j.usageCount) {
            (window.pafishConfirm ? window.pafishConfirm("该媒体仍被引用 " + j.usageCount + " 次，确认强制删除？", { title: "强制删除媒体" }) : Promise.resolve(window.confirm("确认强制删除？"))).then(function (force) {
              if (force) post(<?= json_encode(url_to('/admin/uploads')) ?> + "/" + id + "/delete", { force: "1" }).then(function (forced) { if (forced && forced.ok) pafishToastReload("媒体已强制删除", "success"); else pafishNotify("强制删除失败", true); });
            });
          } else pafishNotify((j && j.error) || "删除失败", true);
        })
        .catch(function () { pafishNotify("网络错误", true); });
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
    if (!/^https?:\/\//i.test(url)) { extError.textContent = "仅支持 http/https 开头的完整地址"; extError.hidden = false; return; }
    post(<?= json_encode(url_to('/admin/uploads/external')) ?>, { url: url, name: extName.value.trim() })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) pafishToastReload("外部媒体已添加", "success");
        else { extError.textContent = (j && j.error) || "添加失败"; extError.hidden = false; pafishNotify((j && j.error) || "添加失败", true); }
      })
      .catch(function () { extError.textContent = "网络错误"; extError.hidden = false; pafishNotify("网络错误", true); });
  });
})();
</script>
