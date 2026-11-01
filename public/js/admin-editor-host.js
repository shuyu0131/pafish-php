/* Editors share one content field, explicit format, media library and save flow. */
(function () {
  'use strict';
  var factories = {};
  var resources = {};

  function asset(url, style) {
    if (resources[url]) return resources[url];
    resources[url] = new Promise(function (resolve, reject) {
      var node = document.createElement(style ? 'link' : 'script');
      if (style) { node.rel = 'stylesheet'; node.href = url; }
      else { node.src = url; node.async = false; }
      var timeout = setTimeout(function () { fail(); }, 15000);
      function fail() {
        clearTimeout(timeout);
        node.remove();
        delete resources[url];
        reject(new Error('编辑器资源加载失败，已恢复默认编辑器'));
      }
      node.onload = function () { clearTimeout(timeout); resolve(); };
      node.onerror = fail;
      document.head.appendChild(node);
    });
    return resources[url];
  }

  function init(options) {
    var workspace = options.mount.closest('.admin-editor-workspace');
    if (workspace && !workspace.dataset.mobileLayout) {
      var actions = workspace.querySelector('.admin-editor-header-actions');
      var side = workspace.querySelector('.admin-editor-side');
      if (actions && side) {
        var actionAnchor = document.createComment('Editor desktop actions');
        actions.before(actionAnchor);
        var mobileActions = document.createElement('div');
        mobileActions.className = 'admin-editor-mobile-actions';
        mobileActions.hidden = true;
        side.before(mobileActions);
        var phoneLayout = window.matchMedia('(max-width: 767px)');
        function placeActions() {
          var focused = document.activeElement;
          var restoreFocus = actions.contains(focused);
          if (phoneLayout.matches) mobileActions.appendChild(actions);
          else actionAnchor.after(actions);
          mobileActions.hidden = !phoneLayout.matches;
          if (restoreFocus) focused.focus({preventScroll: true});
        }
        placeActions();
        phoneLayout.addEventListener('change', placeActions);
        workspace.dataset.mobileLayout = 'true';
      }
    }
    var instance = null;
    var changing = false;
    var uploads = 0;
    var status = document.querySelector('[data-editor-status]');
    var providers = [];
    var descriptor = document.getElementById('editorProviders');
    var configured = 'default';
    function notifyReady() {
      if (!window || typeof window.dispatchEvent !== 'function') return;
      var event = typeof CustomEvent === 'function'
        ? new CustomEvent('pafish:editor-ready', { detail: { mount: options.mount, selected: selected } })
        : null;
      if (event) window.dispatchEvent(event);
    }
    if (descriptor) {
      try {
        var parsed = JSON.parse(descriptor.textContent);
        if (Array.isArray(parsed)) providers = parsed;
        else { providers = Array.isArray(parsed.providers) ? parsed.providers : []; configured = String(parsed.selected || 'default'); }
      } catch (e) {}
    }
    var available = Object.create(null);
    available.default = { id: 'default', scripts: [], styles: [] };
    providers.forEach(function (provider) { available[provider.id] = provider; });
    var selected = available[configured] ? configured : 'default';
    var formatField = document.getElementById('fContentFormat');
    options.getFormat = function () { return formatField ? formatField.value : 'markdown'; };
    options.setFormat = function (format) {
      if (formatField && (format === 'html' || format === 'markdown')) formatField.value = format;
    };
    var isNewContent = options.newContent === true || (formatField && formatField.dataset.editorNew === '1');
    var selectedFormat = available[selected] && available[selected].format || 'markdown';
    // A new empty document takes the configured editor's native format. Existing
    // documents keep their stored format, even when a different editor is active.
    if (isNewContent && !options.field.value.trim() && selected !== 'default') {
      options.setFormat(selectedFormat);
    }
    var incompatible = selected !== 'default' && selectedFormat !== options.getFormat() && (options.field.value.trim() || !isNewContent);
    // Never let an HTML editor open Markdown (or vice versa). The core source
    // editor remains available for historical content when no matching plugin exists.
    if (incompatible) selected = 'default';

    function message(text) {
      if (status) { status.textContent = text; status.hidden = !text; }
    }
    function sync(value, format) {
      if (formatField && (format === 'html' || format === 'markdown')) formatField.value = format;
      options.field.value = value;
      options.field.dispatchEvent(new Event('input', { bubbles: true }));
      if (typeof options.onChange === 'function') options.onChange(value);
    }
    options.setBusy = function (busy) {
      uploads = Math.max(0, uploads + (busy ? 1 : -1));
      syncMediaBusy();
    };
    options.isBusy = function () { return changing || uploads > 0; };
    function syncMediaBusy() {
      if (media) media.disabled = options.isBusy() || !!media.closest('.admin-markdown-editor.is-preview');
    }
    function restoreMedia() {
      if (!media || !mediaParent) return;
      mediaParent.insertBefore(media, mediaNext && mediaNext.parentNode === mediaParent ? mediaNext : null);
      media.className = mediaClass;
      mediaParent.hidden = mediaParentHidden;
    }
    function fallback() {
      options.mount.replaceChildren();
      options.field.hidden = false;
      options.field.classList.add('admin-editor-fallback');
      return {
        getValue: function () { return options.field.value; },
        insert: function (text) { options.field.setRangeText(text, options.field.selectionStart, options.field.selectionEnd, 'end'); sync(options.field.value); },
        insertSelection: function (text) { options.field.setRangeText(text, options.field.selectionStart, options.field.selectionEnd, 'end'); sync(options.field.value); },
        insertHtml: function (html) { options.field.setRangeText(html, options.field.selectionStart, options.field.selectionEnd, 'end'); sync(options.field.value); },
        insertAfterSelection: function (text) { options.field.setRangeText(text, options.field.selectionEnd, options.field.selectionEnd, 'end'); sync(options.field.value); },
        replaceAll: function (text) { options.field.value = String(text == null ? '' : text); sync(options.field.value); },
        getSelection: function () {
          var start = options.field.selectionStart || 0;
          var end = options.field.selectionEnd || start;
          return { text: options.field.value.slice(start, end), start: start, end: end, format: options.getFormat() };
        },
        focus: function () { options.field.focus(); },
        destroy: function () { options.field.hidden = true; }
      };
    }
    function createDefault() {
      // HTML bodies need a source editor. Showing them through the Markdown
      // preview would imply a format conversion even though the hidden format
      // field remains HTML.
      if (options.getFormat() === 'html') return fallback();
      options.field.hidden = true;
      var editor = window.PAFISH_MARKDOWN_EDITOR ? window.PAFISH_MARKDOWN_EDITOR.init(Object.assign({}, options, { onChange: sync })) : fallback();
      var group = options.mount.querySelector('.admin-markdown-insert-group');
      if (media && group) {
        media.className = 'admin-markdown-tool admin-markdown-media';
        group.appendChild(media);
        group.classList.add('has-external-media');
        group.hidden = false;
        mediaParent.hidden = true;
      }
      return editor;
    }
    function currentValue() {
      if (instance && typeof instance.getValue === 'function') options.field.value = instance.getValue();
      return options.field.value;
    }
    function replace(id) {
      if (changing || uploads > 0) return Promise.resolve(false);
      changing = true;
      var mediaButton = document.querySelector('[data-editor-media]');
      if (mediaButton) mediaButton.disabled = true;
      options.mount.setAttribute('aria-busy', 'true');
      message(id === 'default' ? '' : '正在加载编辑器…');
      var provider = available[id] || available.default;
      var sequence = Promise.all(provider.styles.map(function (url) { return asset(url, true); }));
      provider.scripts.forEach(function (url) { sequence = sequence.then(function () { return asset(url, false); }); });
      return sequence.then(function () {
        // Keep the old editor editable while downloading, and read its latest value now.
        currentValue();
        restoreMedia();
        if (instance && instance.destroy) instance.destroy();
        instance = null;
        options.mount.replaceChildren();
        options.field.hidden = true;
        var factory = id === 'default' ? createDefault : factories[id];
        if (!factory) throw new Error('编辑器未能启动，已恢复默认编辑器');
        var controller = new AbortController();
        var timeout;
        var expired = false;
        var boot = Promise.resolve().then(function () {
          return factory(Object.assign({}, options, { onChange: sync, signal: controller.signal, format: options.getFormat() }));
        }).then(function (editor) {
          if (expired) { if (editor && editor.destroy) editor.destroy(); throw new Error('编辑器启动超时'); }
          return editor;
        });
        return Promise.race([boot, new Promise(function (_, reject) {
          timeout = setTimeout(function () { expired = true; controller.abort(); reject(new Error('编辑器启动超时，已恢复默认编辑器')); }, 20000);
        })]).finally(function () { clearTimeout(timeout); });
      }).then(function (editor) {
        if (!editor || typeof editor.getValue !== 'function' || typeof editor.destroy !== 'function') throw new Error('编辑器接口不完整');
        instance = editor;
        selected = id;
        message('');
        notifyReady();
        return true;
      }).catch(function (error) {
        restoreMedia();
        if (instance && instance.destroy) instance.destroy();
        instance = createDefault();
        selected = 'default';
        message(error.message || '编辑器加载失败，已恢复默认编辑器');
        notifyReady();
        return false;
      }).then(function (ok) {
        changing = false;
        syncMediaBusy();
        options.mount.removeAttribute('aria-busy');
        return ok;
      });
    }
    var media = document.querySelector('[data-editor-media]');
    var mediaParent = media && media.parentNode;
    var mediaNext = media && media.nextSibling;
    var mediaClass = media && media.className;
    var mediaParentHidden = mediaParent && mediaParent.hidden;
    var mediaRequest = null;
    var openMedia = options.onMedia;
    options.onMedia = function (request) {
      cancelMedia();
      mediaRequest = request && typeof request.onSelect === 'function' ? request : null;
      if (typeof openMedia === 'function') openMedia(mediaRequest);
    };
    function cancelMedia() {
      var request = mediaRequest; mediaRequest = null;
      if (request && request.onCancel) request.onCancel();
    }
    if (media && typeof openMedia === 'function') media.addEventListener('click', function () { options.onMedia(); });
    if (media) media.addEventListener('mousedown', function (event) {
      if (media.closest('.admin-markdown-toolbar')) event.preventDefault();
    });
    var mediaModal = document.getElementById('mediaModal');
    if (mediaModal) new MutationObserver(function () { if (mediaModal.hidden) cancelMedia(); }).observe(mediaModal, {attributes: true, attributeFilter: ['hidden']});
    options.externalMedia = true;
    options.onMediaUploaded = insertMedia;
    var ready = replace(selected).then(function (ok) {
      if (ok && incompatible) message('此正文格式与当前插件不匹配，已使用核心源码编辑器保留原格式。');
      return ok;
    });
    function insertMedia(url, mime, name) {
      var markdown = window.PafishEditors.mediaMarkdown(url, mime, name);
      if (changing || !instance) return;
      if (mediaRequest) {
        var request = mediaRequest;
        if (request.imagesOnly && String(mime).indexOf('image/') !== 0) return false;
        if (request.onSelect(url, mime, name) === false) return false;
        mediaRequest = null;
        return true;
      }
      if (options.getFormat() === 'html' || available[selected].format === 'html') {
        var node = document.createElement(String(mime).indexOf('image/') === 0 ? 'img' : 'a');
        if (node.tagName === 'IMG') { node.src = url; node.alt = name || ''; }
        else { node.href = url; node.textContent = name || '下载附件'; }
        var html = '<p>' + node.outerHTML + '</p>';
        if (typeof instance.insertHtml === 'function') instance.insertHtml(html);
        else instance.insert(html);
      } else instance.insert(markdown);
    }
    return {
      ready: ready,
      isReady: function () { return !changing && uploads === 0 && !!instance; },
      getValue: currentValue,
      insert: function (text) {
        if (changing || !instance) return;
        if (typeof instance.insert === 'function') instance.insert(text);
        else if (typeof instance.insertValue === 'function') instance.insertValue(text);
        else if (typeof instance.insertSelection === 'function') instance.insertSelection(text);
      },
      insertSelection: function (text) {
        if (changing || !instance) return;
        if (typeof instance.insertSelection === 'function') instance.insertSelection(text);
        else if (typeof instance.insertValue === 'function') instance.insertValue(text);
        else if (typeof instance.insert === 'function') instance.insert(text);
      },
      insertMedia: insertMedia,
      cancelMedia: cancelMedia,
      focus: function () { if (instance && instance.focus) instance.focus(); },
      getSelection: function () {
        if (instance && typeof instance.getSelection === 'function') return instance.getSelection();
        return { text: '', start: 0, end: 0, format: options.getFormat() };
      },
      insertHtml: function (html) { if (!changing && instance) { if (typeof instance.insertHtml === 'function') instance.insertHtml(html); else instance.insert(html); } },
      insertAfterSelection: function (text) { if (!changing && instance) { if (typeof instance.insertAfterSelection === 'function') instance.insertAfterSelection(text); else if (typeof instance.insert === 'function') instance.insert(text); } },
      replaceAll: function (text) {
        if (changing || !instance) return false;
        var value = String(text == null ? '' : text);
        if (typeof instance.replaceAll === 'function') instance.replaceAll(value);
        else if (typeof instance.setValue === 'function') instance.setValue(value);
        else return false;
        return true;
      },
      selected: function () { return selected; }
    };
  }

  window.PafishEditors = {
    register: function (id, factory) { if (/^[a-z0-9_-]{1,50}$/.test(id) && id !== 'default' && typeof factory === 'function') factories[id] = factory; },
    init: init,
    query: function (url, params) { return url + (url.indexOf('?') >= 0 ? '&' : '?') + params.toString(); },
    mediaMarkdown: function (url, mime, name) {
      var safeUrl = String(url || '');
      if (!/^(?:https?:\/\/|\/(?!\/))/.test(safeUrl) || /[\\\u0000-\u001f\u007f]/.test(safeUrl)) throw new Error('媒体地址无效');
      safeUrl = safeUrl.replace(/[\s()<>]/g, function (c) { return encodeURIComponent(c); });
      var label = (String(name || '媒体').replace(/\.[^.]+$/, '') || '媒体').replace(/[\r\n]/g, ' ').replace(/[\\[\]]/g, '\\$&');
      return (String(mime).indexOf('image/') === 0 ? '![' : '[') + label + '](' + safeUrl + ')';
    }
  };
})();
