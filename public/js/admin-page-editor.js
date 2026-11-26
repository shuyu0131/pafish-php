/**
 * 页面编辑器交互：Markdown 编辑、媒体插入和自动保存。
 * - Markdown 编辑：轻量源码编辑器，预览按需请求服务端
 * - 拖拽/粘贴图片上传（/api/upload）；非图片文件走 /api/upload 插入下载链接
 * - 媒体弹窗（本地上传 / 媒体库 24/页 + 500ms 防抖搜索），工具栏「插入媒体」按钮打开
 * - 标题 → slug 自动联动（手动改过后不再覆盖）；Ctrl+S 存草稿
 * 配置：window.PAFISH_PAGE_EDITOR { saveUrl, listUrl, previewUrl, uploadUrl, isEdit, initialSlug, csrf }
 */
(function () {
  "use strict";
  var DATA = window.PAFISH_PAGE_EDITOR || {};
  var CSRF = DATA.csrf || "";

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function isImage(mime) { return (mime || "").indexOf("image/") === 0; }
  function fmtSize(b) {
    if (b < 1024) return b + " B";
    if (b < 1048576) return (b / 1024).toFixed(1) + " KB";
    return (b / 1048576).toFixed(1) + " MB";
  }

  // ---------- 状态与 DOM ----------
  var form = $("#pageForm");
  var title = $("#fTitle");
  var slug = $("#fSlug");
  var content = $("#fContent");
  var errorBox = $(".admin-editor-error");
  var slugTouched = !!DATA.isEdit;
  var slugAuto = $("#fSlugAuto");
  var pending = null;      // draft | publish
  var modal = { open: false, tab: "upload", page: 1, q: "", total: 0, uploading: false, request: 0, searchTimer: null };

  // 编辑器当前值实时同步回 #fContent（表单兜底）
  function editorValue() { return window.__pafishEditor ? window.__pafishEditor.getValue() : content.value; }

  function showError(msg) {
    if (errorBox) { errorBox.textContent = msg; errorBox.hidden = false; }
  }
  function clearError() { if (errorBox) errorBox.hidden = true; }

  // ---------- 上传 ----------
  function uploadFile(file) {
    var fd = new FormData();
    fd.append("file", file);
    fd.append("_csrf", CSRF);
    return fetch(DATA.uploadUrl, {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: fd,
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.j.error || window.pafishT("js.upload_failed"));
        return res.j;
      });
  }

  // ---------- 轻量 Markdown 编辑器 ----------
  // 资源缺失兜底：显示原生 textarea 直接编辑
  function editorFallback() {
    var mount = $("#markdownMount");
    if (mount) mount.style.display = "none";
    content.hidden = false;
    content.style.height = "520px";
    content.style.width = "100%";
    content.style.padding = "12px";
  }

  function initEditor() {
    var mount = $("#markdownMount");
    if (!mount) return;
    if (!window.PafishEditors) { editorFallback(); return; }
    window.__pafishEditor = window.PafishEditors.init({
      type: "page",
      mount: mount,
      field: content,
      placeholder: window.pafishT("js.write_page_content_here_markdown_supported"),
      previewUrl: DATA.previewUrl,
      uploadUrl: DATA.uploadUrl,
      csrf: CSRF,
      onMedia: openModal,
    });
    return;
  }

  // ---------- 媒体弹窗 ----------
  function openModal(request) {
    if (modal.uploading) return;
    modal.open = true;
    modal.imagesOnly = !!(request && request.imagesOnly);
    modal.tab = modal.imagesOnly ? 'library' : 'upload';
    modal.page = 1;
    modal.q = "";
    modal.total = 0;
    $('[data-lib-q]').value = '';
    var mediaTitle = $('#editorMediaTitle');
    var coverMode = modal.imagesOnly;
    if (mediaTitle) mediaTitle.textContent = request && request.title || window.pafishT("js.insert_media");
    var mediaFile = $('#mediaFile');
    mediaFile.dataset.fullAccept = mediaFile.dataset.fullAccept || mediaFile.accept;
    mediaFile.accept = coverMode ? 'image/*,.svg' : mediaFile.dataset.fullAccept;
    $('[data-mupload-hint]').textContent = coverMode ? window.pafishT("js.choose_an_image_file") : window.pafishT("js.images_are_inserted_as_images_other_files_as");
    var me = $("[data-mupload-error]");
    if (me) { me.hidden = true; me.textContent = ""; }
    if (imageUrlForm) imageUrlForm.reset();
    $("#mediaModal").hidden = false;
    renderModal();
  }
  function closeModal() {
    if (window.__pafishEditor) window.__pafishEditor.cancelMedia();
    modal.open = false;
    modal.request++;
    $("#mediaModal").hidden = true;
    if (modal.searchTimer) clearTimeout(modal.searchTimer);
  }
  function renderModal() {
    $$("[data-mtab]", $("#mediaModal")).forEach(function (b) {
      var active = b.getAttribute("data-mtab") === modal.tab;
      b.classList.toggle("active", active);
      b.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    $$("[data-mpanel]", $("#mediaModal")).forEach(function (p) {
      p.hidden = p.getAttribute("data-mpanel") !== modal.tab;
    });
    if (modal.tab === "library") loadLibrary(true);
  }
  function handleUpload(files) {
    var f = files && files[0];
    if (!f || modal.uploading) return;
    if (modal.imagesOnly && !isImage(f.type)) {
      var error = $('[data-mupload-error]'); error.textContent = window.pafishT("js.choose_an_image_file"); error.hidden = false; return;
    }
    modal.uploading = true;
    var panel = $('[data-mpanel="upload"]');
    panel.setAttribute('aria-busy', 'true');
    var browse = $('[data-media-browse]');
    browse.disabled = true;
    $$("[data-mtab]").forEach(function (tab) { tab.disabled = true; });
    $('[data-mupload-error]').hidden = true;
    var help = $('[data-mupload-hint]').textContent;
    var hint = $("[data-mupload-hint]");
    if (hint) hint.textContent = window.pafishT("js.uploading");
    uploadFile(f)
      .then(function (j) { pick(j.url, j.mime, j.originalName || f.name); })
      .catch(function (e) {
        var me = $("[data-mupload-error]");
        me.textContent = e.message || window.pafishT("js.upload_failed");
        me.hidden = false;
      })
      .then(function () {
        modal.uploading = false;
        panel.removeAttribute('aria-busy');
        browse.disabled = false;
        $$("[data-mtab]").forEach(function (tab) { tab.disabled = false; });
        if (hint) hint.textContent = help;
        var mf = $("#mediaFile");
        if (mf) mf.value = "";
      });
  }
  function loadLibrary(reset) {
    if (reset) modal.page = 1;
    var request = ++modal.request;
    var grid = $('[data-lib-grid]'), pager = $('[data-lib-pager]');
    grid.setAttribute('aria-busy', 'true');
    grid.innerHTML = ("<p class=\"admin-lib-empty\">" + window.pafishI18n.escape(window.pafishT("js.loading_media")) + "</p>");
    pager.hidden = true;
    var params = new URLSearchParams({ page: String(modal.page), q: modal.q });
    if (modal.imagesOnly) params.set('type', 'image');
    fetch(window.PafishEditors.query(DATA.uploadsUrl, params), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) {
        if (!r.ok) throw new Error(window.pafishT("js.unable_to_load_media"));
        return r.json();
      })
      .then(function (d) {
        if (!Array.isArray(d.items)) throw new Error(window.pafishT("js.invalid_media_data"));
        if (request === modal.request && modal.open) renderLibrary(d.items, d.total || 0);
      })
      .catch(function () {
        if (request !== modal.request || !modal.open) return;
        grid.innerHTML = ("<div class=\"admin-lib-empty\"><p role=\"alert\">" + window.pafishI18n.escape(window.pafishT("js.unable_to_load_media_please_try_again")) + "</p><button type=\"button\" class=\"btn btn-outline\" data-lib-retry>" + window.pafishI18n.escape(window.pafishT("js.reload")) + "</button></div>");
        $('[data-lib-retry]', grid).addEventListener('click', function () { loadLibrary(false); });
      })
      .then(function () { if (request === modal.request) grid.removeAttribute('aria-busy'); });
  }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function renderLibrary(items, total) {
    modal.total = total;
    var grid = $("[data-lib-grid]");
    if (items.length === 0) {
      grid.innerHTML = '<p class="admin-lib-empty">' + window.pafishI18n.escape(modal.q ? window.pafishT("js.no_matching_media") : window.pafishT("js.the_media_library_is_empty_upload_a_file")) + "</p>";
    } else {
      grid.innerHTML = items.map(function (u) {
        var img = isImage(u.mime);
        var ext = (String(u.url).split(".").pop() || "").toUpperCase();
        var thumb = img
          ? '<img src="' + esc(u.url) + '" alt="' + esc(u.originalName) + '" loading="lazy">'
          : '<span class="admin-lib-ext">' + esc(ext) + "</span>";
        return '<button type="button" class="admin-lib-item" data-lib-pick="' + esc(u.url) + '" data-lib-mime="' + esc(u.mime) + '" data-lib-name="' + esc(u.originalName) + ("\" title=\"" + window.pafishI18n.escape(window.pafishT("js.insert")) + " ") + esc(u.originalName) + "（" + fmtSize(u.size) + "）\">" +
          '<span class="admin-lib-thumb">' + thumb + "</span>" +
          '<span class="admin-lib-name">' + esc(u.originalName) + "</span></button>";
      }).join("");
      $$("[data-lib-pick]", grid).forEach(function (b) {
        b.addEventListener("click", function () {
          pick(b.getAttribute("data-lib-pick"), b.getAttribute("data-lib-mime"), b.getAttribute("data-lib-name"));
        });
      });
    }
    var pages = Math.max(1, Math.ceil(total / 24));
    var pager = $("[data-lib-pager]");
    if (pages <= 1) {
      pager.hidden = true;
    } else {
      pager.hidden = false;
      pager.innerHTML = '<button type="button" class="admin-pager-btn" data-lib-page="' + (modal.page - 1) + '"' + (modal.page <= 1 ? " disabled" : "") + (">" + window.pafishI18n.escape(window.pafishT("js.previous")) + "</button>") +
        ("<span class=\"admin-pager-info\">" + window.pafishI18n.escape(window.pafishT("js.no")) + " ") + modal.page + " / " + pages + (" " + window.pafishI18n.escape(window.pafishT("js.page")) + "</span>") +
        '<button type="button" class="admin-pager-btn" data-lib-page="' + (modal.page + 1) + '"' + (modal.page >= pages ? " disabled" : "") + (">" + window.pafishI18n.escape(window.pafishT("js.next")) + "</button>");
      $$("[data-lib-page]", pager).forEach(function (b) {
        b.addEventListener("click", function () {
          var p = parseInt(b.getAttribute("data-lib-page"), 10);
          if (p >= 1 && p <= pages) { modal.page = p; loadLibrary(false); }
        });
      });
    }
  }
  function pick(url, mime, name, metadata) {
    if (modal.imagesOnly && !isImage(mime)) return;
    if (window.__pafishEditor && window.__pafishEditor.insertMedia(url, mime, name, metadata) === false) return;
    closeModal();
  }

  // ---------- 保存 ----------
  function submit(action) {
    if (pending) return;
    if (window.__pafishEditor && !window.__pafishEditor.isReady()) { showError(window.pafishT("js.the_editor_is_loading_or_uploading_please_wait")); return; }
    clearError();
    if (!title.value.trim()) { showError(window.pafishT("js.enter_a_page_title")); return; }
    pending = action;
    var btns = $$("[data-save]");
    btns.forEach(function (b) { b.disabled = true; });
    content.value = editorValue();
    var fd = new FormData(form);
    fd.set("_csrf", CSRF);
    fd.set("status", action === "publish" ? "PUBLISHED" : "DRAFT");
    fetch(form.action, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.json().catch(function () { return null; }).then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        pending = null;
        btns.forEach(function (b) { b.disabled = false; });
        if (res.ok && res.j && res.j.ok) {
          pafishToastNavigate(DATA.listUrl, window.pafishT("js.page_saved"), "success");
        } else {
          showError((res.j && res.j.error) || window.pafishT("js.unable_to_save_please_try_again"));
        }
      })
      .catch(function () {
        pending = null;
        btns.forEach(function (b) { b.disabled = false; });
        showError(window.pafishT("js.network_error_unable_to_save"));
      });
  }

  var imageUrlForm = window.PafishEditors ? window.PafishEditors.bindImageUrl($('#mediaModal'), pick) : null;
  $('[data-media-browse]').addEventListener('click', function () { $('#mediaFile').click(); });

  // ---------- 绑定 ----------
  // 标题 → slug 联动（仅新建且未手动改过；对齐文章编辑器行为）
  title.addEventListener("input", function () {
    if (!slugTouched) {
      if (slugAuto) slugAuto.value = "1";
      window.pafishPreviewSlug(title, slug, slugAuto);
    }
  });
  slug.addEventListener("input", function () { slugTouched = true; if (slugAuto) slugAuto.value = "0"; });

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var btn = document.activeElement && document.activeElement.getAttribute("data-save");
    submit(btn === "publish" ? "publish" : "draft");
  });

  // 保存按钮（data-save）+ Ctrl+S 存草稿
  $$("[data-save]").forEach(function (b) {
    b.addEventListener("click", function () { submit(b.getAttribute("data-save")); });
  });
  window.addEventListener("keydown", function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "s") {
      e.preventDefault();
      submit("draft");
    }
  });

  // 媒体弹窗绑定
  $$("[data-close-modal]").forEach(function (b) { b.addEventListener("click", closeModal); });
  var modalEl = $("#mediaModal");
  modalEl.addEventListener("click", function (e) { if (e.target === modalEl) closeModal(); });
  $$("[data-mtab]", modalEl).forEach(function (b) {
    b.addEventListener("click", function () {
      if (modal.uploading) return;
      modal.request++;
      modal.tab = b.getAttribute("data-mtab");
      renderModal();
    });
  });
  $("#mediaFile").addEventListener("change", function () { handleUpload($("#mediaFile").files); });
  var uploadPanel = $('[data-mpanel="upload"]', modalEl);
  if (uploadPanel) {
    ["dragenter", "dragover"].forEach(function (ev) {
      uploadPanel.addEventListener(ev, function (e) { e.preventDefault(); uploadPanel.classList.add("admin-drop-over"); });
    });
    ["dragleave", "drop"].forEach(function (ev) {
      uploadPanel.addEventListener(ev, function (e) {
        e.preventDefault();
        uploadPanel.classList.remove("admin-drop-over");
      });
    });
    uploadPanel.addEventListener("drop", function (e) { handleUpload(e.dataTransfer.files); });
  }
  $("[data-lib-q]").addEventListener("input", function () {
    modal.q = $("[data-lib-q]").value.trim();
    if (modal.searchTimer) clearTimeout(modal.searchTimer);
    modal.searchTimer = setTimeout(function () { loadLibrary(true); }, 500);
  });
  window.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && modal.open) closeModal();
  });

  // 初始化编辑器
  initEditor();
})();
