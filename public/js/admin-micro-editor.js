/**
 * 微语编辑器交互：使用站点当前全局编辑器。
 *
 * 与文章、页面共用编辑器宿主，配置从 window.PAFISH_MICRO_EDITOR 读，
 * 不依赖 PAFISH_EDITOR_DATA（那份是文章专用的，含标题/别名/分类等字段）。
 * 表单是原生提交，编辑器值经 input 回调同步回隐藏的 #fContent。
 * 配置：window.PAFISH_MICRO_EDITOR { uploadUrl, assetBase, csrf }
 */
(function () {
  "use strict";

  var DATA = window.PAFISH_MICRO_EDITOR || {};
  var CSRF = DATA.csrf || "";

  function $(sel, root) { return (root || document).querySelector(sel); }

  var content = $("#fContent");
  if (!content) return;
  var form = $("#microForm");
  var uploading = false;

  // 提交后立即锁定按钮；服务端令牌负责处理网络重试和重复请求。
  if (form) {
    form.addEventListener("submit", function (event) {
      if (uploading || (window.__pafishEditor && !window.__pafishEditor.isReady())) {
        event.preventDefault();
        if (window.pafishNotify) window.pafishNotify('编辑器正在加载或上传，请稍候', true);
        return;
      }
      if (form.dataset.submitting === "1") {
        event.preventDefault();
        return;
      }
      form.dataset.submitting = "1";
      Array.prototype.forEach.call(form.querySelectorAll("button[type=submit]"), function (button) {
        button.disabled = true;
      });
    });
  }

  function selectMedia(request) {
    if (uploading || !window.__pafishEditor || !window.__pafishEditor.isReady()) return;
    var picker = document.createElement('input');
    picker.type = 'file';
    picker.accept = 'image/*,.svg,.pdf,.txt,.md,.zip,.mp3,.mp4';
    if (request && request.imagesOnly) picker.accept = 'image/*,.svg';
    picker.addEventListener('cancel', function () { window.__pafishEditor.cancelMedia(); });
    picker.addEventListener('change', async function () {
      var file = picker.files && picker.files[0];
      if (!file) { window.__pafishEditor.cancelMedia(); return; }
      uploading = true;
      var button = $('[data-editor-media]');
      if (button) button.disabled = true;
      try {
        var body = new FormData();
        body.append('file', file);
        body.append('_csrf', CSRF);
        var response = await fetch(DATA.uploadUrl, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        var result = await response.json();
        if (!response.ok || !result.url) throw new Error(result.error || '上传失败');
        window.__pafishEditor.insertMedia(result.url, result.mime, result.originalName || file.name);
      } catch (error) {
        window.__pafishEditor.cancelMedia();
        if (window.pafishNotify) window.pafishNotify(error.message || '上传失败', true);
      } finally {
        uploading = false;
        if (button) button.disabled = false;
      }
    });
    picker.click();
  }

  /** 编辑器资源缺失时退回原生 textarea，保证还能写。 */
  function editorFallback() {
    var mount = $("#markdownMount");
    if (mount) mount.hidden = true;
    content.hidden = false;
    content.className = "input";
    content.rows = 14;
    content.style.width = "100%";
    content.style.padding = "12px";
  }

  function initEditor() {
    var mount = $("#markdownMount");
    if (!mount) return;
    if (!window.PafishEditors || typeof window.PafishEditors.init !== "function") { editorFallback(); return; }
    window.__pafishEditor = window.PafishEditors.init({
      type: "micro",
      mount: mount,
      field: content,
      placeholder: "记录此刻想说的话……（支持拖拽/粘贴图片上传）",
      previewUrl: DATA.previewUrl,
      uploadUrl: DATA.uploadUrl,
      csrf: CSRF,
      onMedia: selectMedia,
      onChange: function (value) { content.value = value; },
    });
  }

  if (form) form.addEventListener("submit", function () {
    if (window.__pafishEditor && typeof window.__pafishEditor.getValue === "function") {
      content.value = window.__pafishEditor.getValue();
    }
  }, true);

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initEditor);
  } else {
    initEditor();
  }
})();
