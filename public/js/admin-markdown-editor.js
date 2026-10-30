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
    audio: '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
    video: '<rect width="18" height="14" x="3" y="5" rx="2"/><path d="m10 9 5 3-5 3z"/>',
    undo: '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/>',
    redo: '<path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3l3 2.7"/>',
    edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
    columns: '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M12 3v18"/>',
    eye: '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
    close: '<path d="m6 6 12 12M18 6 6 18"/>'
  };

  function create(options) {
    var mount = options.mount;
    var field = options.field;
    if (!mount || !field) return null;

    var mode = "edit";
    var htmlSource = options.getFormat && options.getFormat() === "html";
    var busy = false;
    var previewRequest = 0;
    var previewTimer = null;
    var previewController = null;
    var destroyed = false;
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
      '<div class="admin-markdown-preview pafish-content" aria-live="polite" hidden></div></div>' +
      '<div class="admin-markdown-status"><span class="admin-markdown-message" aria-live="polite"></span><span class="admin-markdown-count"></span></div></div>';
    var root = mount.firstElementChild;
    var source = root.querySelector(".admin-markdown-source");
    var previewPane = root.querySelector(".admin-markdown-preview");
    var message = root.querySelector(".admin-markdown-message");
    var count = root.querySelector(".admin-markdown-count");
    source.value = field.value || "";
    if (field.maxLength > 0) source.maxLength = field.maxLength;
    source.placeholder = options.placeholder || "开始写作…支持 Markdown";
    source.setAttribute("aria-label", htmlSource ? "HTML 正文源码" : (options.label || "Markdown 正文"));
    if (htmlSource) { root.classList.add("is-html-source"); source.placeholder = "在此编辑 HTML 正文源码"; }

    function snapshot() { return { value: source.value, start: source.selectionStart, end: source.selectionEnd }; }
    function sameSnapshot(a, b) { return !!a && !!b && a.value === b.value && a.start === b.start && a.end === b.end; }
    function saveHistory(kind) {
      var next = snapshot();
      var current = history[historyIndex];
      if (sameSnapshot(current, next)) return;
      if (kind === 'selection' && current && current.value === next.value) {
        history[historyIndex] = next;
        lastHistoryKind = '';
        return;
      }
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
      clearElementSelection();
      historyIndex = index;
      var state = history[historyIndex];
      source.value = state.value;
      source.focus();
      source.setSelectionRange(state.start, state.end);
      lastHistoryKind = "";
      sync();
      updateCount();
      updateHistoryButtons();
      syncHeading();
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
    function insert(text, selectionStart, selectionEnd, savedSelection) {
      if (!savedSelection) saveHistory('selection');
      clearElementSelection();
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
      syncHeading();
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
      var token = tokenAtSelection();
      if (token && token.type === 'link') editElement(token);
      else editElement({type: 'link', format: htmlSource ? 'html' : 'markdown', start: source.selectionStart,
        end: source.selectionEnd, label: source.value.slice(source.selectionStart, source.selectionEnd), url: '', title: '', fresh: true});
    }
    function mediaUrl(value) {
      var url = String(value || '').trim();
      if (!url || url.length > 2048 || /[\\\u0000-\u0020\u007f]/.test(url) || /^\/\//.test(url)) return null;
      if (/^https?:\/\//i.test(url)) return url;
      return url.charAt(0) === '/' ? url : null;
    }
    function mediaHtml(type, url) {
      var player = document.createElement(type);
      player.controls = true;
      player.preload = 'metadata';
      if (type === 'video') player.playsInline = true;
      var sourceNode = document.createElement('source');
      sourceNode.src = url;
      player.appendChild(sourceNode);
      return player.outerHTML;
    }
    function insertMediaEmbed(type) {
      if (mode === 'preview') return;
      var originalValue = source.value;
      var rangeStart = source.selectionStart;
      var rangeEnd = source.selectionEnd;
      var dialog = document.createElement('dialog');
      dialog.className = 'admin-editor-element-dialog';
      dialog.setAttribute('aria-label', type === 'audio' ? '插入音乐' : '插入视频');
      var form = document.createElement('form');
      form.method = 'dialog';
      var heading = document.createElement('div'); heading.className = 'admin-editor-element-heading';
      var title = document.createElement('h3'); title.textContent = type === 'audio' ? '插入音乐' : '插入视频';
      var close = document.createElement('button'); close.type = 'button'; close.className = 'admin-markdown-tool'; close.setAttribute('aria-label', '关闭'); close.innerHTML = icon('close');
      heading.append(title, close); form.appendChild(heading);
      var fieldLabel = document.createElement('label'); fieldLabel.className = 'admin-editor-element-field';
      var caption = document.createElement('span'); caption.textContent = '媒体地址';
      var input = document.createElement('input'); input.className = 'input'; input.type = 'url'; input.required = true; input.autocomplete = 'off'; input.spellcheck = false;
      input.placeholder = 'https://example.com/' + (type === 'audio' ? 'music.mp3' : 'video.mp4') + ' 或 /uploads/…';
      fieldLabel.append(caption, input); form.appendChild(fieldLabel);
      var hint = document.createElement('p'); hint.className = 'admin-field-hint'; hint.textContent = type === 'audio' ? '支持 MP3、OGG、WAV 等浏览器可播放的音频地址。' : '支持 MP4、WebM 等浏览器可播放的视频地址。'; form.appendChild(hint);
      var error = document.createElement('p'); error.className = 'admin-editor-element-error'; error.setAttribute('role', 'alert'); error.hidden = true; form.appendChild(error);
      var actions = document.createElement('div'); actions.className = 'admin-editor-element-actions';
      var cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'btn btn-ghost'; cancel.textContent = '取消';
      var save = document.createElement('button'); save.type = 'submit'; save.className = 'btn btn-primary'; save.textContent = '插入';
      actions.append(cancel, save); form.appendChild(actions); dialog.appendChild(form); root.appendChild(dialog); elementDialog = dialog;
      function dismiss(restore) { closeElementDialog(restore); }
      close.addEventListener('click', function () { dismiss(true); });
      cancel.addEventListener('click', function () { dismiss(true); });
      dialog.addEventListener('cancel', function (event) { event.preventDefault(); dismiss(true); });
      form.addEventListener('submit', function (event) {
        event.preventDefault(); event.stopPropagation();
        var url = mediaUrl(input.value);
        if (!url) { error.textContent = '请输入 HTTP/HTTPS 地址或以 / 开头的站内媒体路径'; error.hidden = false; input.focus(); return; }
        if (source.value !== originalValue) { error.textContent = '正文已变化，请重新选择插入位置'; error.hidden = false; return; }
        var value = mediaHtml(type, url);
        source.setSelectionRange(rangeStart, rangeEnd);
        insert(value);
        dismiss(true);
      });
      dialog.showModal(); input.focus();
    }

    // A token always carries a source range. Never replace by URL: duplicate images are common.
    var selectedNode = null;
    var elementBar = document.createElement('div');
    elementBar.className = 'admin-editor-element-bar';
    elementBar.setAttribute('role', 'toolbar');
    elementBar.hidden = true;
    (root.closest('.admin-modal-backdrop') || document.body).appendChild(elementBar);
    var elementDialog = null;
    var tokenCache = {value: null, tokens: []};
    var previewValue = null;
    var sourceAnchor = null;

    function escapeLabel(value) { return String(value).replace(/[\\[\]]/g, '\\$&').replace(/[\r\n]/g, ' '); }
    function unescapeMarkdown(value) { return String(value).replace(/\\([\x21-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e])/g, '$1'); }
    function safeElementUrl(value, image) {
      var url = String(value || '').trim();
      if (!url || /[\\\u0000-\u001f\u007f]/.test(url)) return null;
      if (/^(?:javascript|data|vbscript|file|blob):/i.test(url) || /^\/\//.test(url)) return null;
      if (/^[a-z][a-z0-9+.-]*:/i.test(url) && !(image ? /^https?:/i : /^(?:https?:|mailto:|tel:)/i).test(url)) return null;
      return url;
    }
    function decodeEntities(value) {
      var text = document.createElement('textarea'); text.innerHTML = value; return text.value;
    }
    function elementTokens() {
      if (tokenCache.value === source.value) return tokenCache.tokens;
      var text = source.value, tokens = [], hidden = [], references = Object.create(null);
      function hide(start, end) { hidden.push([start, end]); }
      function escaped(index) { var count = 0; while (index > 0 && text[--index] === '\\') count++; return count % 2 === 1; }
      function refKey(label) { return unescapeMarkdown(label).trim().replace(/\s+/g, ' ').toLowerCase(); }
      // Exclude code and definitions before looking for inline syntax.
      var block = /<!--[^]*?-->|<(pre|code|script|style|textarea)\b[^>]*>[^]*?<\/\1\s*>/gi, match;
      while ((match = block.exec(text))) hide(match.index, block.lastIndex);
      if (!htmlSource) {
        var linePattern = /[^\n]*(?:\n|$)/g, fence = null;
        while ((match = linePattern.exec(text)) && match[0]) {
          var line = match[0], marker = line.match(/^ {0,3}(\x60{3,}|~{3,})/);
          if (fence) {
            hide(match.index, linePattern.lastIndex);
            if (marker && marker[1][0] === fence[0] && marker[1].length >= fence.length && /^\s*$/.test(line.slice(marker[0].length))) fence = null;
          } else if (marker) { fence = marker[1]; hide(match.index, linePattern.lastIndex); }
          else if (/^(?: {4}|\t)/.test(line)) hide(match.index, linePattern.lastIndex);
          else {
            var definition = line.match(/^ {0,3}\[([^\]]+)\]:\s*(?:<([^>]+)>|(\S+?))(?:\s+["']([^]*?)["'])?\s*$/);
            if (definition) { references[refKey(definition[1])] = {url: definition[2] || definition[3], title: definition[4] || ''}; hide(match.index, linePattern.lastIndex); }
          }
        }
        var code = /(\x60+)[^]*?\1(?!\x60)/g;
        while ((match = code.exec(text))) if (!escaped(match.index)) hide(match.index, code.lastIndex);
      }
      hidden.sort(function (a, b) { return a[0] - b[0]; });
      var cursor = 0;
      function ignored(index) { while (cursor < hidden.length && hidden[cursor][1] <= index) cursor++; return cursor < hidden.length && hidden[cursor][0] <= index; }
      function htmlToken(index) {
        var tag = text.slice(index).match(/^<(img|a)\b(?:[^>"']|"[^"]*"|'[^']*')*>/i);
        if (!tag) return null;
        var end = index + tag[0].length;
        if (tag[1].toLowerCase() === 'a') {
          var close = text.slice(end).match(/<\/a\s*>/i);
          if (!close) return null;
          end += close.index + close[0].length;
        }
        var doc = new DOMParser().parseFromString(text.slice(index, end), 'text/html');
        var node = doc.body.firstElementChild;
        if (!node || !/^(IMG|A)$/.test(node.tagName)) return null;
        return {type: node.tagName === 'IMG' ? 'image' : 'link', format: 'html', start: index, end: end, openingEnd: index + tag[0].length,
          url: node.getAttribute(node.tagName === 'IMG' ? 'src' : 'href') || '',
          label: node.tagName === 'IMG' ? node.getAttribute('alt') || '' : node.textContent,
          title: node.getAttribute('title') || '', node: node};
      }
      for (var i = 0; i < text.length; i++) {
        if (ignored(i)) continue;
        if (text[i] === '<') {
          var html = htmlToken(i);
          if (html) { tokens.push(html); i = (html.type === 'image' ? html.end : html.openingEnd) - 1; }
          continue;
        }
        if (htmlSource || escaped(i)) continue;
        var image = text[i] === '!' && text[i + 1] === '[';
        if (!image && (text[i] !== '[' || (i > 0 && text[i - 1] === '!' && !escaped(i - 1)))) continue;
        var begin = i + (image ? 2 : 1), pos = begin, depth = 1;
        for (; pos < text.length && depth; pos++) {
          if (escaped(pos)) continue;
          if (text[pos] === '[') depth++;
          if (text[pos] === ']') depth--;
        }
        if (depth) continue;
        var label = text.slice(begin, pos - 1), dest = null, title = '', end = pos;
        if (text[pos] === '(') {
          var p = pos + 1; while (/\s/.test(text[p] || '') && p < text.length) p++;
          if (text[p] === '<') {
            var angleStart = ++p; while (p < text.length && (text[p] !== '>' || escaped(p))) p++;
            if (p === text.length) continue;
            dest = text.slice(angleStart, p++);
          } else {
            var urlStart = p, balance = 0;
            for (; p < text.length; p++) {
              if (escaped(p)) continue;
              if (text[p] === '(') balance++;
              if (text[p] === ')') { if (balance === 0) break; balance--; }
              if (/\s/.test(text[p]) && balance === 0) break;
            }
            dest = text.slice(urlStart, p);
          }
          while (/\s/.test(text[p] || '') && p < text.length) p++;
          if (text[p] === '"' || text[p] === "'" || text[p] === '(') {
            var quote = text[p] === '(' ? ')' : text[p], titleStart = ++p;
            while (p < text.length && (text[p] !== quote || escaped(p))) p++;
            if (p === text.length) continue;
            title = unescapeMarkdown(text.slice(titleStart, p++));
            while (/\s/.test(text[p] || '') && p < text.length) p++;
          }
          if (text[p] !== ')') continue;
          end = p + 1;
        } else {
          var ref = label;
          if (text[pos] === '[') {
            var refEnd = text.indexOf(']', pos + 1); if (refEnd < 0) continue;
            ref = text.slice(pos + 1, refEnd) || label; end = refEnd + 1;
          }
          var definitionValue = references[refKey(ref)];
          if (!definitionValue) continue;
          dest = definitionValue.url; title = definitionValue.title;
        }
        tokens.push({type: image ? 'image' : 'link', format: 'markdown', start: i, end: end,
          url: decodeEntities(unescapeMarkdown(dest)), label: unescapeMarkdown(label), rawLabel: label, title: title});
        // Keep nested image tokens in linked images, but skip syntax inside the destination.
        if (image || label.indexOf('![') < 0) i = end - 1;
      }
      tokenCache = {value: text, tokens: tokens};
      return tokens;
    }
    function tokenAtSelection() {
      var a = source.selectionStart, b = source.selectionEnd;
      return elementTokens().filter(function (token) { return a >= token.start && b <= token.end; })
        .sort(function (a, b) { return (a.end - a.start) - (b.end - b.start); })[0] || null;
    }
    function clearElementSelection() {
      if (selectedNode) selectedNode.classList.remove('is-editor-selected');
      selectedNode = null; elementBar.hidden = true;
    }
    function applyElement(token, value, originalValue) {
      if (destroyed || source.value !== originalValue) { message.textContent = '正文已变化，请重新选择后再编辑'; return false; }
      source.setSelectionRange(token.start, token.end);
      insert(value);
      return true;
    }
    function serializeElement(token, url, label, title) {
      if (token.format === 'html') {
        var node = token.node ? token.node.cloneNode(true) : document.createElement(token.type === 'image' ? 'img' : 'a');
        node.setAttribute(token.type === 'image' ? 'src' : 'href', url);
        if (token.type === 'image') node.setAttribute('alt', label);
        else if (token.fresh || label !== token.label) node.textContent = label;
        if (title) node.setAttribute('title', title); else node.removeAttribute('title');
        return node.outerHTML;
      }
      var rawLabel = !token.fresh && label === token.label ? token.rawLabel : escapeLabel(label);
      return (token.type === 'image' ? '!' : '') + '[' + rawLabel + '](' + url.replace(/[\s()<>]/g, encodeURIComponent) +
        (title ? ' "' + title.replace(/[\\"]/g, '\\$&').replace(/[\r\n]/g, ' ') + '"' : '') + ')';
    }
    function unlinkElement(token) { return token.format === 'html' ? token.node.innerHTML : token.rawLabel; }
    function closeElementDialog(restore) {
      if (!elementDialog) return;
      var current = elementDialog; elementDialog = null; current.close(); current.remove();
      if (restore && !destroyed) { if (mode === 'preview' && selectedNode) selectedNode.focus(); else source.focus(); }
    }
    function editElement(token) {
      closeElementDialog(false);
      var originalValue = source.value;
      var dialog = document.createElement('dialog'); dialog.className = 'admin-editor-element-dialog';
      dialog.setAttribute('aria-label', token.type === 'image' ? '编辑图片' : token.fresh ? '插入链接' : '编辑链接');
      var form = document.createElement('form'); form.method = 'dialog';
      var head = document.createElement('div'); head.className = 'admin-editor-element-heading';
      var title = document.createElement('h3'); title.textContent = dialog.getAttribute('aria-label');
      var close = document.createElement('button'); close.type = 'button'; close.className = 'admin-markdown-tool';
      close.setAttribute('aria-label', '关闭'); close.innerHTML = icon('close'); close.addEventListener('click', function () { closeElementDialog(true); });
      head.append(title, close); form.appendChild(head);
      function input(label, value, name, required) {
        var fieldLabel = document.createElement('label'); fieldLabel.className = 'admin-editor-element-field';
        var caption = document.createElement('span'); caption.textContent = label;
        var control = document.createElement('input'); control.className = 'input'; control.type = 'text';
        control.name = name; control.value = value; control.required = !!required;
        fieldLabel.append(caption, control); form.appendChild(fieldLabel); return control;
      }
      var urlInput = input(token.type === 'image' ? '图片地址' : '链接地址', token.url, 'url', true);
      urlInput.placeholder = 'https://'; urlInput.autocomplete = 'off'; urlInput.spellcheck = false;
      var labelInput = input(token.type === 'image' ? '替代文字' : '显示文字', token.label, 'label');
      var titleInput = input('标题（可选）', token.title, 'title');
      var error = document.createElement('p'); error.className = 'admin-editor-element-error'; error.setAttribute('role', 'alert'); error.hidden = true; form.appendChild(error);
      var actions = document.createElement('div'); actions.className = 'admin-editor-element-actions';
      if (token.type === 'link' && !token.fresh) {
        var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-ghost'; remove.textContent = '移除链接';
        remove.addEventListener('click', function () { if (applyElement(token, unlinkElement(token), originalValue)) closeElementDialog(true); else { error.textContent = message.textContent; error.hidden = false; } }); actions.appendChild(remove);
      }
      var cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'btn btn-ghost'; cancel.textContent = '取消'; cancel.addEventListener('click', function () { closeElementDialog(true); });
      var save = document.createElement('button'); save.type = 'submit'; save.className = 'btn btn-primary'; save.textContent = token.fresh ? '插入' : '保存';
      actions.append(cancel, save); form.appendChild(actions); dialog.appendChild(form); root.appendChild(dialog); elementDialog = dialog;
      form.addEventListener('submit', function (event) {
        event.preventDefault(); event.stopPropagation();
        var url = safeElementUrl(urlInput.value, token.type === 'image');
        if (!url) { error.textContent = token.type === 'image' ? '请输入 http、https 图片地址或站内路径' : '请输入 http、https、mailto、tel 地址或站内路径'; error.hidden = false; urlInput.focus(); return; }
        if (applyElement(token, serializeElement(token, url, labelInput.value || (token.type === 'link' ? url : ''), titleInput.value), originalValue)) closeElementDialog(true);
        else { error.textContent = message.textContent; error.hidden = false; }
      });
      dialog.addEventListener('cancel', function (event) { event.preventDefault(); closeElementDialog(true); });
      dialog.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') event.stopPropagation();
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); event.stopPropagation(); form.requestSubmit(); }
      });
      dialog.addEventListener('click', function (event) { if (event.target === dialog) { var r = dialog.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) closeElementDialog(true); } });
      dialog.showModal(); (token.fresh ? urlInput : labelInput).focus();
    }
    function replaceImage(token) {
      var originalValue = source.value;
      if (typeof options.onMedia !== 'function') return;
      clearElementSelection();
      options.onMedia({imagesOnly: true, title: '替换图片', onSelect: function (url, mime) {
        if (String(mime).indexOf('image/') !== 0 || !safeElementUrl(url, true)) { message.textContent = '请选择图片文件'; return false; }
        applyElement(token, serializeElement(token, url, token.label, token.title), originalValue);
        return true;
      }, onCancel: function () { if (!destroyed && mode !== 'preview') source.focus(); }});
    }
    function placeElementBar() {
      if (elementBar.hidden) return;
      var rect = selectedNode ? selectedNode.getBoundingClientRect() : sourceAnchor;
      if (!rect || rect.bottom < 0 || rect.top > innerHeight || !root.isConnected) { clearElementSelection(); return; }
      var left = Math.max(8, Math.min(rect.left, innerWidth - elementBar.offsetWidth - 8));
      var top = rect.bottom + 7;
      if (top + elementBar.offsetHeight > innerHeight - 8) top = rect.top - elementBar.offsetHeight - 7;
      elementBar.style.left = left + 'px'; elementBar.style.top = Math.max(8, top) + 'px';
    }
    function selectElement(token, node, anchor) {
      clearElementSelection(); selectedNode = node || null; sourceAnchor = anchor;
      if (node) node.classList.add('is-editor-selected');
      elementBar.replaceChildren(); elementBar.setAttribute('aria-label', token.type === 'image' ? '图片操作' : '链接操作');
      var label = document.createElement('span'); label.textContent = token.type === 'image' ? '图片' : '链接'; elementBar.appendChild(label);
      function action(text, fn, danger) { var button = document.createElement('button'); button.type = 'button'; button.className = 'admin-editor-element-action' + (danger ? ' is-danger' : ''); button.textContent = text; button.addEventListener('mousedown', function (e) { e.preventDefault(); }); button.addEventListener('click', fn); elementBar.appendChild(button); }
      action('编辑', function () { editElement(token); });
      if (token.type === 'image' && options.uploadUrl) action('替换', function () { replaceImage(token); });
      var originalValue = source.value;
      action(token.type === 'image' ? '删除' : '移除链接', function () { applyElement(token, token.type === 'image' ? '' : unlinkElement(token), originalValue); }, token.type === 'image');
      elementBar.hidden = false; placeElementBar();
    }
    function selectSourceElement(event) {
      if (elementDialog || destroyed || source.hidden) return;
      var token = tokenAtSelection();
      if (!token) { clearElementSelection(); return; }
      var rect = source.getBoundingClientRect(), y = event && event.clientY ? event.clientY : Math.max(rect.top, 70) + 12;
      selectElement(token, null, {left: rect.left + 14, top: y, bottom: y + 16});
    }
    function normalizedUrl(url) { try { return new URL(url, document.baseURI).href; } catch (e) { return ''; } }
    function bindPreviewElements() {
      previewPane.querySelectorAll('img, a[href]').forEach(function (node) {
        if (node.closest('pre,code')) return;
        var type = node.tagName === 'IMG' ? 'image' : 'link', attr = type === 'image' ? 'src' : 'href';
        var url = normalizedUrl(node.getAttribute(attr));
        var candidates = elementTokens().filter(function (token) { return token.type === type && normalizedUrl(token.url) === url; });
        var peers = Array.prototype.filter.call(previewPane.querySelectorAll(type === 'image' ? 'img' : 'a[href]'), function (other) { return !other.closest('pre,code') && normalizedUrl(other.getAttribute(attr)) === url; });
        // Ambiguous renderer/plugin output must never silently edit the wrong source range.
        if (!candidates.length || candidates.length !== peers.length) return;
        var token = candidates[peers.indexOf(node)];
        node.tabIndex = 0;
        if (type === 'image') { node.setAttribute('role', 'button'); node.setAttribute('aria-label', '编辑图片：' + (node.alt || '图片')); }
        function select(event) {
          if (event.target.closest('img,a') !== node) return;
          event.preventDefault(); event.stopPropagation();
          if (previewValue !== source.value) { message.textContent = '预览正在更新，请稍后重新选择'; return; }
          selectElement(token, node);
          if (type === 'link') editElement(token);
        }
        node.addEventListener('click', select);
        node.addEventListener('keydown', function (event) { if (event.key === 'Enter' || event.key === ' ') select(event); });
      });
    }
    function dismissElements(event) {
      if (elementDialog || elementBar.contains(event.target) || previewPane.contains(event.target) || event.target === source) return;
      clearElementSelection();
    }
    function elementEscape(event) {
      if (event.key === 'Escape' && !elementDialog && !elementBar.hidden) { event.preventDefault(); event.stopPropagation(); clearElementSelection(); source.focus(); }
    }
    function elementScroll() { if (selectedNode) placeElementBar(); else clearElementSelection(); }
    document.addEventListener('pointerdown', dismissElements);
    document.addEventListener('keydown', elementEscape, true);
    window.addEventListener('scroll', elementScroll, true);
    window.addEventListener('resize', elementScroll);
    source.addEventListener('click', selectSourceElement);
    source.addEventListener('keyup', function (event) { if (/^(?:Arrow|Home|End)/.test(event.key)) selectSourceElement(); });
    source.addEventListener('select', function () { if (document.activeElement === source) selectSourceElement(); });
    previewPane.addEventListener('click', function (event) {
      if (event.target.closest('a[href]')) { event.preventDefault(); message.textContent = '请在源码中选择此链接后编辑'; }
      else if (!event.target.closest('img')) clearElementSelection();
    });
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
      var start = a === 0 ? 0 : source.value.lastIndexOf("\n", a - 1) + 1;
      var last = b > a && source.value[b - 1] === '\n' ? b - 1 : b;
      var end = source.value.indexOf("\n", last);
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
      if (htmlSource || mode === 'preview') return;
      var range = lineRange();
      var offset = range.start, changes = [];
      var replacement = range.text.split('\n').map(function (line) {
        var match = line.match(/^( {0,3})(#{1,6})(?:[ \t]+|$)/);
        var oldPrefix = match ? match[0] : '';
        var text = line.slice(oldPrefix.length);
        var nextPrefix = level && (line.trim() || range.a === range.b) ? new Array(level + 1).join('#') + ' ' : '';
        changes.push({start: offset, oldLength: oldPrefix.length, newLength: nextPrefix.length});
        offset += line.length + 1;
        return nextPrefix + text;
      }).join('\n');
      function translate(position) {
        var delta = 0;
        for (var i = 0; i < changes.length; i++) {
          var change = changes[i];
          if (position < change.start) break;
          if (position <= change.start + change.oldLength) return change.start + delta + change.newLength;
          delta += change.newLength - change.oldLength;
        }
        return position + delta;
      }
      var a = translate(range.a), b = translate(range.b), direction = source.selectionDirection;
      if (replacement === range.text) { source.focus({preventScroll: true}); syncHeading(); return; }
      saveHistory('selection');
      source.setSelectionRange(range.start, range.end);
      var scrollTop = source.scrollTop, scrollLeft = source.scrollLeft;
      insert(replacement, a - range.start, b - range.start, true);
      source.setSelectionRange(a, b, direction);
      source.scrollTop = scrollTop; source.scrollLeft = scrollLeft;
      syncHeading();
    }
    function syncHeading() {
      if (!heading || htmlSource) return;
      var range = lineRange(), levels = range.text.split('\n').filter(function (line) { return line.trim(); }).map(function (line) {
        var match = line.match(/^ {0,3}(#{1,6})(?:[ \t]+|$)/);
        return match ? String(match[1].length) : '0';
      });
      var level = levels.length && levels.every(function (value) { return value === levels[0]; }) ? levels[0] : levels.length ? 'mixed' : '0';
      if (heading.value !== level) {
        heading.value = level;
        // Refresh the shared control without executing a formatting command.
        heading.dispatchEvent(new Event('input', {bubbles: true}));
      }
    }
    function renderPreview() {
      if (!options.previewUrl || mode === "edit") return Promise.resolve();
      var request = ++previewRequest;
      var renderedValue = source.value;
      if (previewController) previewController.abort();
      previewController = new AbortController();
      var body = new FormData();
      body.append("content", source.value);
      body.append("content_format", htmlSource ? "html" : "markdown");
      body.append("_csrf", options.csrf || "");
      message.textContent = "正在生成预览…";
      return fetch(options.previewUrl, { method: "POST", body: body, signal: previewController.signal, headers: { "X-Requested-With": "XMLHttpRequest" } })
        .then(function (response) {
          return response.json().then(function (data) {
            if (!response.ok) throw new Error(data.error || "预览失败");
            if (destroyed || request !== previewRequest) return;
            previewPane.innerHTML = data.html || "";
            clearElementSelection();
            previewValue = renderedValue;
            bindPreviewElements();
            message.textContent = "";
          });
        })
        .catch(function (error) { if (!destroyed && request === previewRequest && error.name !== "AbortError") message.textContent = error.message || "预览失败"; });
    }
    function setMode(next) {
      clearElementSelection();
      mode = next;
      root.classList.toggle("is-split", mode === "split");
      root.classList.toggle("is-preview", mode === "preview");
      source.hidden = mode === "preview";
      heading.disabled = mode === 'preview';
      heading.dispatchEvent(new Event('input', {bubbles: true}));
      root.querySelectorAll('.admin-markdown-inline-group button, .admin-markdown-block-group button, .admin-markdown-insert-group button').forEach(function (button) {
        button.disabled = mode === 'preview' || (button.hasAttribute('data-editor-media') && options.isBusy && options.isBusy());
      });
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
      if (options.setBusy) options.setBusy(true);
      message.textContent = "正在上传…";
      var body = new FormData();
      body.append("file", file);
      body.append("_csrf", options.csrf || "");
      fetch(options.uploadUrl, { method: "POST", body: body, headers: { "X-Requested-With": "XMLHttpRequest" } })
        .then(function (response) {
          return response.json().then(function (data) {
            if (!response.ok) throw new Error(data.error || "上传失败");
            if (typeof options.onMediaUploaded === 'function') options.onMediaUploaded(data.url, data.mime || file.type, data.originalName || file.name);
            else if (!destroyed) {
              var name = String(data.originalName || file.name || "媒体").replace(/\.[^.]+$/, "").replace(/[\\[\]]/g, '\\$&');
              var image = String(data.mime || file.type).indexOf("image/") === 0;
              insert("\n" + (image ? "![" : "[") + name + "](" + String(data.url).replace(/[\s()<>]/g, encodeURIComponent) + ")\n");
            }
            message.textContent = "上传完成";
            window.setTimeout(function () { message.textContent = ""; }, 1800);
          });
        })
        .catch(function (error) { message.textContent = error.message || "上传失败"; })
        .then(function () { busy = false; if (options.setBusy) options.setBusy(false); });
    }

    var heading = document.createElement("select");
    heading.className = "input admin-markdown-heading-select";
    heading.dataset.adminSelectCommit = 'explicit';
    heading.dataset.adminSelectHover = 'true';
    heading.dataset.adminSelectPreserveFocus = 'true';
    heading.dataset.adminSelectStyle = 'paragraph';
    heading.setAttribute("aria-label", "段落格式");
    ["正文", "标题 1", "标题 2", "标题 3", "标题 4", "标题 5", "标题 6"].forEach(function (label, level) {
      var option = document.createElement("option"); option.value = String(level); option.textContent = label; heading.appendChild(option);
    });
    var mixed = document.createElement('option'); mixed.value = 'mixed'; mixed.textContent = '混合格式'; mixed.disabled = true; mixed.hidden = true; heading.appendChild(mixed);
    heading.addEventListener("change", function () { if (heading.value !== 'mixed') setHeading(Number(heading.value)); });
    root.querySelector(".admin-markdown-heading-group").appendChild(heading);
    root.dispatchEvent(new Event('pafish:controls-init', {bubbles: true}));
    source.addEventListener('select', syncHeading);
    source.addEventListener('click', syncHeading);
    source.addEventListener('keyup', syncHeading);
    source.addEventListener('focus', syncHeading);

    addGroupButton(".admin-markdown-inline-group", "bold", "粗体", function () { wrap("**", "**"); }, "Ctrl+B");
    addGroupButton(".admin-markdown-inline-group", "italic", "斜体", function () { wrap("*", "*"); }, "Ctrl+I");
    addGroupButton(".admin-markdown-inline-group", "strike", "删除线", function () { wrap("~~", "~~"); });
    undoButton = addGroupButton(".admin-markdown-history-group", "undo", "撤销", undo, "Ctrl+Z");
    redoButton = addGroupButton(".admin-markdown-history-group", "redo", "重做", redo, "Ctrl+Shift+Z");
    addGroupButton(".admin-markdown-inline-group", "link", "插入链接", insertLink, "Ctrl+K", "admin-markdown-link");
    addGroupButton(".admin-markdown-block-group", "quote", "引用", function () { prefixLines("> ", "quote"); });
    addGroupButton(".admin-markdown-block-group", "ul", "无序列表", function () { prefixLines("- ", "ul"); });
    addGroupButton(".admin-markdown-block-group", "ol", "有序列表", function () { prefixLines("1. ", "ol"); });
    addGroupButton(".admin-markdown-block-group", "code", "代码块", function () { wrap("```\n", "\n```", "代码"); });
    addGroupButton(".admin-markdown-block-group", "hr", "插入分隔线", function () { insert("\n---\n"); });
    if (!options.externalMedia) addGroupButton(".admin-markdown-insert-group", "media", "插入媒体", function () {
      if (typeof options.onMedia === "function") return options.onMedia();
      var picker = document.createElement("input");
      picker.type = "file";
      picker.accept = "image/*,.svg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.md,.csv,.zip,.rar,.7z,.mp3,.wav,.ogg,.mp4,.webm,.mov";
      picker.addEventListener("change", function () { upload(picker.files && picker.files[0]); });
      picker.click();
    }, null, "admin-markdown-media");
    addGroupButton(".admin-markdown-insert-group", "audio", "插入音乐", function () { insertMediaEmbed('audio'); }, null, "admin-markdown-audio");
    addGroupButton(".admin-markdown-insert-group", "video", "插入视频", function () { insertMediaEmbed('video'); }, null, "admin-markdown-video");
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
    function updateCount() { count.textContent = (htmlSource ? "HTML · " : "") + source.value.length.toLocaleString("zh-CN") + " 字"; }
    source.addEventListener("input", function () {
      clearElementSelection();
      saveHistory("typing"); sync(); updateCount();
      syncHeading();
      if (previewTimer) clearTimeout(previewTimer);
      if (mode !== "edit") previewTimer = setTimeout(renderPreview, 250);
    });
    source.addEventListener("keydown", function (event) {
      var key = event.key.toLowerCase();
      if ((event.ctrlKey || event.metaKey) && key === "s") return;
      if ((event.ctrlKey || event.metaKey) && !event.altKey) {
        if (key === "z") { event.preventDefault(); if (event.shiftKey) redo(); else undo(); return; }
        if (key === "y") { event.preventDefault(); redo(); return; }
        if (key === 'k') { event.preventDefault(); insertLink(); return; }
        if (htmlSource) return;
        var action = { b: function () { wrap("**", "**"); }, i: function () { wrap("*", "*"); } }[key];
        if (action) { event.preventDefault(); action(); return; }
      }
      if (!htmlSource && event.key === "Enter" && continueMarkdownLine(event)) return;
      if (event.key === "Tab") {
        event.preventDefault();
        if (source.selectionStart !== source.selectionEnd || event.shiftKey) indentLines(event.shiftKey);
        else insert("  ");
      }
    });
    if (!htmlSource) source.addEventListener("dragover", function (event) { if (event.dataTransfer && event.dataTransfer.files.length) event.preventDefault(); });
    if (!htmlSource) source.addEventListener("drop", function (event) {
      var file = event.dataTransfer && event.dataTransfer.files[0];
      if (!file) return;
      event.preventDefault();
      upload(file);
    });
    if (!htmlSource) source.addEventListener("paste", function (event) {
      var item = Array.prototype.find.call(event.clipboardData && event.clipboardData.items || [], function (entry) { return entry.type.indexOf("image/") === 0; });
      if (!item) return;
      event.preventDefault();
      upload(item.getAsFile());
    });
    if (options.externalMedia) root.querySelector(".admin-markdown-insert-group").hidden = true;
    updateCount();
    updateHistoryButtons();
    syncHeading();
    return {
      destroy: function () {
        root.dispatchEvent(new Event('pafish:controls-destroy', {bubbles: true}));
        destroyed = true; previewRequest++; clearTimeout(previewTimer); if (previewController) previewController.abort();
        closeElementDialog(false); clearElementSelection(); elementBar.remove();
        document.removeEventListener('pointerdown', dismissElements); document.removeEventListener('keydown', elementEscape, true);
        window.removeEventListener('scroll', elementScroll, true); window.removeEventListener('resize', elementScroll); root.remove();
      },
      insert: function (markdown) { insert((source.value ? "\n" : "") + markdown + "\n"); },
      insertValue: function (markdown) { insert(markdown); },
      setValue: function (markdown) {
        clearElementSelection();
        source.value = String(markdown == null ? "" : markdown);
        saveHistory("command");
        sync();
        updateCount();
        syncHeading();
        if (mode !== "edit") renderPreview();
      },
      getValue: function () { return source.value; },
      focus: function () { source.focus(); }
    };
  }

  window.PAFISH_MARKDOWN_EDITOR = { icons: icons, init: create };
})();
