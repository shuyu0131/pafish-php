/** Lightweight source-first Markdown editor shared by posts, pages, and micro posts. */
(function () {
  "use strict";

  var icons = {
    heading: '<path d="M6 12h12M6 20V4M18 20V4"/>',
    bold: '<path d="M6 12h8a4 4 0 0 0 0-8H6v16h9a4 4 0 0 0 0-8H6"/>',
    italic: '<path d="M19 4h-9M14 20H5M15 4 9 20"/>',
    strike: '<path d="M16 4H9a3.5 3.5 0 0 0 0 7h6a3.5 3.5 0 0 1 0 7H6M4 12h16"/>',
    quote: '<path d="M17 6H3M21 12H8M21 18H8M3 12v6"/>',
    ul: '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
    ol: '<path d="M10 6h11M10 12h11M10 18h11"/><path d="M4 6h1v4M4 10h2M6 18H4c0-1 2-2 2-3 0-.7-.4-1-1-1s-1 .3-1 1"/>',
    code: '<path d="m8 8-4 4 4 4M16 8l4 4-4 4M14.5 4l-5 16"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.1 0l2-2A5 5 0 0 0 12 3.9l-1.1 1.1M14 11a5 5 0 0 0-7.1 0l-2 2A5 5 0 0 0 12 20.1l1.1-1.1"/>',
    hr: '<path d="M5 12h14"/>',
    media: '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
    undo: '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/>',
    redo: '<path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3l3 2.7"/>',
    edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
    columns: '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M12 3v18"/>',
    eye: '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
    chevron: '<path d="m7 10 5 5 5-5"/>'
  };

  function create(options) {
    var mount = options.mount;
    var field = options.field;
    if (!mount || !field) return null;

    var mode = "edit";
    var busy = false;
    var previewRequest = 0;
    var history = [];
    var historyIndex = -1;
    var lastHistoryKind = "";
    var lastHistoryTime = 0;
    var undoButton = null;
    var redoButton = null;
    mount.innerHTML = '<div class="admin-markdown-editor">' +
      '<div class="admin-markdown-toolbar" role="toolbar" aria-label="Markdown 工具栏">' +
        '<div class="admin-markdown-toolbar-row">' +
        '<div class="admin-markdown-group admin-markdown-history-group"></div>' +
        '<span class="admin-markdown-divider" aria-hidden="true"></span>' +
        '<div class="admin-markdown-group admin-markdown-heading-group"></div>' +
        '<span class="admin-markdown-divider" aria-hidden="true"></span>' +
        '<div class="admin-markdown-group admin-markdown-inline-group"></div>' +
        '</div>' +
        '<div class="admin-markdown-toolbar-row">' +
        '<span class="admin-markdown-divider" aria-hidden="true"></span>' +
        '<div class="admin-markdown-group admin-markdown-block-group"></div>' +
        '<span class="admin-markdown-divider" aria-hidden="true"></span>' +
        '<div class="admin-markdown-group admin-markdown-insert-group"></div>' +
        '<span class="admin-markdown-toolbar-spacer"></span>' +
        '<div class="admin-markdown-group admin-markdown-view-group"></div>' +
        '</div>' +
      '</div>' +
      '<div class="admin-markdown-body"><textarea class="admin-markdown-source" spellcheck="true"></textarea>' +
      '<div class="admin-markdown-preview" aria-live="polite" hidden></div></div>' +
      '<div class="admin-markdown-status"><span class="admin-markdown-message" aria-live="polite"></span><span class="admin-markdown-count"></span></div></div>';
    var root = mount.firstElementChild;
    var source = root.querySelector(".admin-markdown-source");
    var previewPane = root.querySelector(".admin-markdown-preview");
    var message = root.querySelector(".admin-markdown-message");
    var count = root.querySelector(".admin-markdown-count");
    source.value = field.value || "";
    if (field.maxLength > 0) source.maxLength = field.maxLength;
    source.placeholder = options.placeholder || "开始写作…支持 Markdown";
    source.setAttribute("aria-label", options.label || "Markdown 正文");

    function snapshot() { return { value: source.value, start: source.selectionStart, end: source.selectionEnd }; }
    function sameSnapshot(a, b) { return !!a && !!b && a.value === b.value && a.start === b.start && a.end === b.end; }
    function saveHistory(kind) {
      var next = snapshot();
      var current = history[historyIndex];
      if (sameSnapshot(current, next)) return;
      var now = Date.now();
      var coalesce = kind === "typing" && lastHistoryKind === "typing" && now - lastHistoryTime < 700 && historyIndex === history.length - 1;
      if (coalesce) history[historyIndex] = next;
      else {
        history = history.slice(0, historyIndex + 1);
        history.push(next);
        historyIndex = history.length - 1;
      }
      lastHistoryKind = kind;
      lastHistoryTime = now;
      if (history.length > 100) { history.shift(); historyIndex -= 1; }
      updateHistoryButtons();
    }
    function restoreHistory(index) {
      if (index < 0 || index >= history.length) return;
      historyIndex = index;
      var state = history[historyIndex];
      source.value = state.value;
      source.focus();
      source.setSelectionRange(state.start, state.end);
      lastHistoryKind = "";
      sync();
      updateCount();
      updateHistoryButtons();
      if (mode !== "edit") renderPreview();
    }
    function undo() { if (historyIndex > 0) restoreHistory(historyIndex - 1); }
    function redo() { if (historyIndex < history.length - 1) restoreHistory(historyIndex + 1); }
    history.push(snapshot());
    historyIndex = 0;

    function icon(name) { return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + icons[name] + "</svg>"; }
    function sync() {
      field.value = source.value;
      field.dispatchEvent(new Event("input", { bubbles: true }));
      if (typeof options.onChange === "function") options.onChange(source.value);
    }
    function insert(text, selectionStart, selectionEnd) {
      var a = source.selectionStart;
      var b = source.selectionEnd;
      var selected = source.value.slice(a, b);
      var replacement = typeof text === "function" ? text(selected) : text;
      source.value = source.value.slice(0, a) + replacement + source.value.slice(b);
      source.focus();
      var start = a + (selectionStart == null ? replacement.length : selectionStart);
      var end = selectionEnd == null ? start : a + selectionEnd;
      source.setSelectionRange(start, end);
      saveHistory("command");
      sync();
      updateCount();
      if (mode !== "edit") renderPreview();
    }
    function wrap(prefix, suffix, placeholder) {
      var a = source.selectionStart;
      var b = source.selectionEnd;
      var selected = source.value.slice(a, b);
      if (!selected) selected = placeholder || "文字";
      insert(prefix + selected + suffix, prefix.length, prefix.length + selected.length);
    }
    function insertLink() {
      var a = source.selectionStart;
      var b = source.selectionEnd;
      var label = source.value.slice(a, b) || "链接文字";
      var markdown = "[" + label + "](https://)";
      insert(markdown, label.length + 3, label.length + 11);
    }
    function addButton(container, name, label, shortcut, action, extraClass) {
      var button = document.createElement("button");
      button.type = "button";
      button.className = "admin-markdown-tool" + (extraClass ? " " + extraClass : "");
      button.innerHTML = icon(name);
      var shortcutLabel = shortcut && /Mac|iPhone|iPad/.test(navigator.platform || "") ? shortcut.replace(/^Ctrl/, "⌘").replace("Shift+", "⇧") : shortcut;
      button.title = label + (shortcutLabel ? " (" + shortcutLabel + ")" : "");
      button.setAttribute("aria-label", button.title);
      button.addEventListener("mousedown", function (event) { event.preventDefault(); });
      button.addEventListener("click", action);
      container.appendChild(button);
      return button;
    }
    function addGroupButton(group, name, label, action, shortcut, extraClass) { return addButton(root.querySelector(group), name, label, shortcut, action, extraClass); }
    function lineRange() {
      var a = source.selectionStart;
      var b = source.selectionEnd;
      var start = source.value.lastIndexOf("\n", a - 1) + 1;
      var end = source.value.indexOf("\n", b);
      if (end < 0) end = source.value.length;
      return { start: start, end: end, text: source.value.slice(start, end), a: a, b: b };
    }
    function prefixLines(prefix, kind) {
      var range = lineRange();
      var lines = range.text.split("\n");
      var orderedIndex = 0;
      var output = lines.map(function (line) {
        if (!line && lines.length > 1) return line;
        if (kind === "ul" && /^\s*[-*+]\s/.test(line)) return line.replace(/^(\s*)[-*+]\s+/, "$1");
        if (kind === "ol" && /^\s*\d+\.\s/.test(line)) return line.replace(/^(\s*)\d+\.\s+/, "$1");
        if (kind === "quote" && /^\s*>\s?/.test(line)) return line.replace(/^(\s*)>\s?/, "$1");
        orderedIndex += 1;
        return kind === "ol" ? String(orderedIndex) + ". " + (line || "列表项") : prefix + (line || "列表项");
      }).join("\n");
      source.setSelectionRange(range.start, range.end);
      insert(output, 0, output.length);
    }
    function indentLines(outdent) {
      var range = lineRange();
      var output = range.text.split("\n").map(function (line) {
        if (outdent) return line.replace(/^(?: {1,2}|\t)/, "");
        return "  " + line;
      }).join("\n");
      source.setSelectionRange(range.start, range.end);
      insert(output, 0, output.length);
    }
    function continueMarkdownLine(event) {
      if (source.selectionStart !== source.selectionEnd) return false;
      var cursor = source.selectionStart;
      var lineStart = source.value.lastIndexOf("\n", cursor - 1) + 1;
      var line = source.value.slice(lineStart, cursor);
      var match = line.match(/^(\s*)([-*+] |(\d+)\. |> )(.*)$/);
      if (!match) {
        var indentation = line.match(/^[ \t]+/);
        if (!indentation) return false;
        event.preventDefault();
        insert("\n" + indentation[0]);
        return true;
      }
      var indent = match[1];
      var marker = match[2];
      var content = match[4];
      event.preventDefault();
      if (!content.trim()) {
        var markerStart = lineStart + indent.length;
        source.setSelectionRange(markerStart, cursor);
        insert("\n", 1, 1);
        return true;
      }
      var nextMarker = marker;
      if (/^\d+\. $/.test(marker)) nextMarker = (Number(marker.match(/^\d+/)[0]) + 1) + ". ";
      insert("\n" + indent + nextMarker);
      return true;
    }
    function setHeading(level) {
      var range = lineRange();
      var text = range.text.replace(/^\s{0,3}#{1,6}\s+/, "");
      var replacement = level ? new Array(level + 1).join("#") + " " + (text || "标题") : text;
      source.setSelectionRange(range.start, range.end);
      insert(replacement, replacement.length, replacement.length);
    }
    function renderPreview() {
      if (!options.previewUrl || mode === "edit") return Promise.resolve();
      var request = ++previewRequest;
      var body = new FormData();
      body.append("content", source.value);
      body.append("_csrf", options.csrf || "");
      message.textContent = "正在生成预览…";
      return fetch(options.previewUrl, { method: "POST", body: body, headers: { "X-Requested-With": "XMLHttpRequest" } })
        .then(function (response) {
          return response.json().then(function (data) {
            if (!response.ok) throw new Error(data.error || "预览失败");
            if (request !== previewRequest) return;
            previewPane.innerHTML = data.html || "";
            message.textContent = "";
          });
        })
        .catch(function (error) { if (request === previewRequest) message.textContent = error.message || "预览失败"; });
    }
    function setMode(next) {
      mode = next;
      root.classList.toggle("is-split", mode === "split");
      root.classList.toggle("is-preview", mode === "preview");
      source.hidden = mode === "preview";
      previewPane.hidden = mode === "edit";
      root.querySelectorAll("[data-editor-mode]").forEach(function (button) {
        var active = button.getAttribute("data-editor-mode") === mode;
        button.classList.toggle("is-active", active);
        button.setAttribute("aria-pressed", active ? "true" : "false");
      });
      if (mode !== "edit") renderPreview();
      if (mode !== "preview") source.focus();
    }
    function upload(file) {
      if (!file || !options.uploadUrl || busy) return;
      busy = true;
      message.textContent = "正在上传…";
      var body = new FormData();
      body.append("file", file);
      body.append("_csrf", options.csrf || "");
      fetch(options.uploadUrl, { method: "POST", body: body, headers: { "X-Requested-With": "XMLHttpRequest" } })
        .then(function (response) {
          return response.json().then(function (data) {
            if (!response.ok) throw new Error(data.error || "上传失败");
            var name = String(data.originalName || file.name || "媒体").replace(/\.[^.]+$/, "");
            var image = String(data.mime || file.type).indexOf("image/") === 0;
            insert("\n" + (image ? "![" : "[") + name + "](" + data.url + ")\n");
            message.textContent = "上传完成";
            window.setTimeout(function () { message.textContent = ""; }, 1800);
          });
        })
        .catch(function (error) { message.textContent = error.message || "上传失败"; })
        .then(function () { busy = false; });
    }

    var heading = document.createElement("div");
    heading.className = "admin-markdown-heading-control";
    var headingButton = addButton(heading, "heading", "标题级别", null, function () { headingMenu.hidden = !headingMenu.hidden; }, "admin-markdown-heading-button");
    headingButton.setAttribute("aria-haspopup", "true");
    var chevron = document.createElement("span");
    chevron.innerHTML = icon("chevron");
    chevron.setAttribute("aria-hidden", "true");
    heading.appendChild(chevron);
    var headingMenu = document.createElement("div");
    headingMenu.className = "admin-markdown-heading-menu";
    headingMenu.hidden = true;
    [1, 2, 3, 4, 5, 6].forEach(function (level) {
      var item = document.createElement("button");
      item.type = "button";
      item.textContent = "标题 " + level;
      item.addEventListener("mousedown", function (event) { event.preventDefault(); });
      item.addEventListener("click", function () { setHeading(level); headingMenu.hidden = true; });
      headingMenu.appendChild(item);
    });
    var clearHeading = document.createElement("button");
    clearHeading.type = "button";
    clearHeading.textContent = "正文";
    clearHeading.addEventListener("mousedown", function (event) { event.preventDefault(); });
    clearHeading.addEventListener("click", function () { setHeading(0); headingMenu.hidden = true; });
    headingMenu.appendChild(clearHeading);
    heading.appendChild(headingMenu);
    root.querySelector(".admin-markdown-heading-group").appendChild(heading);

    addGroupButton(".admin-markdown-inline-group", "bold", "粗体", function () { wrap("**", "**"); }, "Ctrl+B");
    addGroupButton(".admin-markdown-inline-group", "italic", "斜体", function () { wrap("*", "*"); }, "Ctrl+I");
    addGroupButton(".admin-markdown-inline-group", "strike", "删除线", function () { wrap("~~", "~~"); });
    undoButton = addGroupButton(".admin-markdown-history-group", "undo", "撤销", undo, "Ctrl+Z");
    redoButton = addGroupButton(".admin-markdown-history-group", "redo", "重做", redo, "Ctrl+Shift+Z");
    addGroupButton(".admin-markdown-inline-group", "link", "插入链接", insertLink, "Ctrl+K");
    addGroupButton(".admin-markdown-block-group", "quote", "引用", function () { prefixLines("> ", "quote"); });
    addGroupButton(".admin-markdown-block-group", "ul", "无序列表", function () { prefixLines("- ", "ul"); });
    addGroupButton(".admin-markdown-block-group", "ol", "有序列表", function () { prefixLines("1. ", "ol"); });
    addGroupButton(".admin-markdown-block-group", "code", "代码块", function () { wrap("```\n", "\n```", "代码"); });
    addGroupButton(".admin-markdown-block-group", "hr", "插入分隔线", function () { insert("\n---\n"); });
    addGroupButton(".admin-markdown-insert-group", "media", "上传并插入媒体", function () {
      if (typeof options.onMedia === "function") return options.onMedia();
      var picker = document.createElement("input");
      picker.type = "file";
      picker.accept = "image/*,.svg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.md,.csv,.zip,.rar,.7z,.mp3,.wav,.ogg,.mp4,.webm,.mov";
      picker.addEventListener("change", function () { upload(picker.files && picker.files[0]); });
      picker.click();
    }, null, "admin-markdown-media");
    [
      ["edit", "编辑"], ["split", "分栏预览"], ["preview", "预览"]
    ].forEach(function (entry) {
      var button = addButton(root.querySelector(".admin-markdown-view-group"), entry[0] === "split" ? "columns" : entry[0] === "edit" ? "edit" : "eye", entry[1], null, function () { setMode(entry[0]); }, "admin-markdown-view-button");
      button.setAttribute("data-editor-mode", entry[0]);
      button.setAttribute("aria-pressed", entry[0] === "edit" ? "true" : "false");
    });

    function updateHistoryButtons() {
      if (undoButton) undoButton.disabled = historyIndex <= 0;
      if (redoButton) redoButton.disabled = historyIndex >= history.length - 1;
    }
    function updateCount() { count.textContent = source.value.length.toLocaleString("zh-CN") + " 字"; }
    source.addEventListener("input", function () { saveHistory("typing"); sync(); updateCount(); if (mode !== "edit") renderPreview(); });
    source.addEventListener("keydown", function (event) {
      var key = event.key.toLowerCase();
      if ((event.ctrlKey || event.metaKey) && key === "s") return;
      if ((event.ctrlKey || event.metaKey) && !event.altKey) {
        if (key === "z") { event.preventDefault(); if (event.shiftKey) redo(); else undo(); return; }
        if (key === "y") { event.preventDefault(); redo(); return; }
        var action = { b: function () { wrap("**", "**"); }, i: function () { wrap("*", "*"); }, k: insertLink }[key];
        if (action) { event.preventDefault(); action(); return; }
      }
      if (event.key === "Enter" && continueMarkdownLine(event)) return;
      if (event.key === "Tab") {
        event.preventDefault();
        if (source.selectionStart !== source.selectionEnd || event.shiftKey) indentLines(event.shiftKey);
        else insert("  ");
      }
    });
    source.addEventListener("dragover", function (event) { if (event.dataTransfer && event.dataTransfer.files.length) event.preventDefault(); });
    source.addEventListener("drop", function (event) {
      var file = event.dataTransfer && event.dataTransfer.files[0];
      if (!file) return;
      event.preventDefault();
      upload(file);
    });
    source.addEventListener("paste", function (event) {
      var item = Array.prototype.find.call(event.clipboardData && event.clipboardData.items || [], function (entry) { return entry.type.indexOf("image/") === 0; });
      if (!item) return;
      event.preventDefault();
      upload(item.getAsFile());
    });
    updateCount();
    updateHistoryButtons();
    return {
      insert: function (markdown) { insert((source.value ? "\n" : "") + markdown + "\n"); },
      insertValue: function (markdown) { insert(markdown); },
      setValue: function (markdown) {
        source.value = String(markdown == null ? "" : markdown);
        saveHistory("command");
        sync();
        updateCount();
        if (mode !== "edit") renderPreview();
      },
      getValue: function () { return source.value; },
      focus: function () { source.focus(); }
    };
  }

  window.PAFISH_MARKDOWN_EDITOR = { init: create, insert: function (markdown) { if (window.__pafishMarkdownEditor) window.__pafishMarkdownEditor.insert(markdown); } };
})();
