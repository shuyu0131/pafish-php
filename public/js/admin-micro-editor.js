/**
 * 微语编辑器交互：Markdown 编辑与图片上传。
 *
 * 与文章、页面编辑器共用轻量 Markdown 编辑器，配置从 window.PAFISH_MICRO_EDITOR 读，
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

  // 提交后立即锁定按钮；服务端令牌负责处理网络重试和重复请求。
  if (form) {
    form.addEventListener("submit", function (event) {
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
    if (!window.PAFISH_MARKDOWN_EDITOR || typeof window.PAFISH_MARKDOWN_EDITOR.init !== "function") { editorFallback(); return; }
    window.__pafishMarkdownEditor = window.PAFISH_MARKDOWN_EDITOR.init({
      mount: mount,
      field: content,
      placeholder: "记录此刻想说的话……（支持拖拽/粘贴图片上传）",
      previewUrl: DATA.previewUrl,
      uploadUrl: DATA.uploadUrl,
      csrf: CSRF,
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initEditor);
  } else {
    initEditor();
  }
})();
