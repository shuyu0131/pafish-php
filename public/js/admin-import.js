/* Markdown 批量导入页（对齐 Node import-markdown.tsx）：
   - 点击/拖拽选择 .md 文件（最多 50 个、单文件 1MB，超限即时提示）
   - 已选文件列表可逐个移除；清空按钮
   - 「导入为」单选（草稿/直接发布）
   - 提交 → POST /api/import-markdown → 结果面板（成功 N 失败 M + 逐条失败原因） */
(function () {
  "use strict";

  var MAX_FILES = 50;
  var MAX_SIZE = 1024 * 1024;
  var CSRF = window.PAFISH_IMPORT_CSRF || "";

  var dropzone = document.querySelector("[data-dropzone]");
  var fileInput = document.getElementById("importFile");
  var fileList = document.querySelector("[data-filelist]");
  var errorBox = document.querySelector("[data-error]");
  var clearBtn = document.querySelector("[data-import-clear]");
  var runBtn = document.querySelector("[data-import-run]");
  var resultBox = document.querySelector("[data-result]");
  var form = document.getElementById("importForm");
  if (!dropzone || !fileInput) return;

  var files = []; // {name, size, file}

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function fmtSize(b) {
    if (b < 1024) return b + " B";
    if (b < 1048576) return (b / 1024).toFixed(1) + " KB";
    return (b / 1048576).toFixed(1) + " MB";
  }
  function showError(msg) {
    errorBox.textContent = msg;
    errorBox.hidden = false;
  }
  function hideError() {
    errorBox.hidden = true;
  }

  // ---------- 文件选择 ----------
  function addFiles(list) {
    var added = 0;
    Array.prototype.forEach.call(list, function (f) {
      if (files.length >= MAX_FILES) {
        showError("一次最多导入 50 个文件");
        return;
      }
      if (!/\.md$/i.test(f.name)) {
        showError("仅支持 .md 文件：" + f.name);
        return;
      }
      if (f.size > MAX_SIZE) {
        showError("文件超过 1MB 限制：" + f.name);
        return;
      }
      // 同名去重（追加后替换）
      files = files.filter(function (x) { return x.name !== f.name; });
      files.push({ name: f.name, size: f.size, file: f });
      added++;
    });
    if (added > 0) hideError();
    renderList();
  }

  function renderList() {
    fileList.innerHTML = "";
    if (files.length > 0) {
      fileList.hidden = false;
      files.forEach(function (f, i) {
        var li = document.createElement("li");
        li.className = "admin-import-file";
        li.innerHTML =
          '<span class="admin-import-file-name">' + esc(f.name) + '</span>' +
          '<span class="admin-import-file-size">' + fmtSize(f.size) + '</span>' +
          '<button type="button" class="admin-icon-btn" title="移除" data-remove="' + i + '">' +
          '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>';
        fileList.appendChild(li);
      });
    } else {
      fileList.hidden = true;
    }
    clearBtn.disabled = files.length === 0;
    runBtn.disabled = files.length === 0;
    runBtn.textContent = "开始导入（" + files.length + " 篇）";
  }

  fileList.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-remove]");
    if (btn) {
      files.splice(parseInt(btn.getAttribute("data-remove"), 10), 1);
      renderList();
    }
  });
  clearBtn.addEventListener("click", function () {
    files = [];
    renderList();
    hideError();
  });
  dropzone.addEventListener("click", function () { fileInput.click(); });
  fileInput.addEventListener("change", function () {
    if (fileInput.files && fileInput.files.length) addFiles(fileInput.files);
    fileInput.value = "";
  });
  ["dragover", "dragenter"].forEach(function (ev) {
    dropzone.addEventListener(ev, function (e) {
      e.preventDefault();
      dropzone.classList.add("dragover");
    });
  });
  ["dragleave", "drop"].forEach(function (ev) {
    dropzone.addEventListener(ev, function (e) {
      e.preventDefault();
      dropzone.classList.remove("dragover");
    });
  });
  dropzone.addEventListener("drop", function (e) {
    if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files);
  });

  // ---------- 提交 ----------
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    if (files.length === 0) return;
    var status = form.querySelector('input[name="status"]:checked');
    var fd = new FormData();
    // 注意：PHP 8.4+ 新 multipart 解析器对同名 files 字段只保留最后一个，必须用 files[] 形式
    files.forEach(function (f) { fd.append("files[]", f.file, f.name); });
    fd.append("status", status ? status.value : "draft");
    fd.append("_csrf", CSRF);

    runBtn.disabled = true;
    runBtn.textContent = "导入中…";
    hideError();
    resultBox.hidden = true;

    fetch(form.action, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok) throw new Error(data.error || "导入失败");
          return data;
        });
      })
      .then(function (data) {
        renderResult(data);
        files = [];
        renderList();
      })
      .catch(function (err) {
        showError(err.message || "导入失败");
        runBtn.disabled = false;
        runBtn.textContent = "开始导入（" + files.length + " 篇）";
      });
  });

  function renderResult(data) {
    var okCount = data.created || 0;
    var failCount = data.failed || 0;
    var html = '<p class="admin-import-result-head">' +
      (failCount === 0 ? '全部成功' : '导入完成：成功 ' + okCount + ' 篇，失败 ' + failCount + ' 篇') +
      '</p>';
    if (failCount > 0) {
      html += '<ul class="admin-import-result-fails">';
      (data.results || []).forEach(function (r) {
        if (!r.ok) html += '<li>' + esc(r.name) + '：' + esc(r.error || '导入失败') + '</li>';
      });
      html += '</ul>';
    }
    resultBox.innerHTML = html;
    resultBox.hidden = false;
  }
})();
