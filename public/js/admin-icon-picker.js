/*
 * SchemaForm 图标选择器。
 *
 * 选择结果是 class 字符串，字段本身仍是普通 text input，因此：
 * - 主题可以直接把值写到前台 class="..."；
 * - 主题作者可以手写自定义字体 class；
 * - Repeater 动态行也能通过事件代理复用同一个弹窗。
 */
(function () {
  "use strict";

  var catalog = window.PafishIconCatalog || {};
  var modal = document.querySelector("[data-icon-picker-modal]");
  if (!modal) { return; }
  var grid = modal.querySelector("[data-icon-picker-grid]");
  var search = modal.querySelector("[data-icon-picker-search]");
  var meta = modal.querySelector("[data-icon-picker-meta]");
  var browse = modal.querySelector("[data-icon-picker-browse]");
  var target = null;
  var lastFocus = null;
  var library = "remixicon";
  var renderTimer = null;
  var visibleCount = 168;
  var browseAll = false;
  var featured = [
    "ri-home-5-line", "ri-home-5-fill", "ri-menu-line", "ri-search-line", "ri-user-line",
    "ri-settings-3-line", "ri-bell-line", "ri-star-line", "ri-heart-line", "ri-image-line",
    "ri-link", "ri-external-link-line", "ri-arrow-right-line", "ri-add-line", "ri-close-line",
    "ri-check-line", "ri-edit-line", "ri-delete-bin-line", "ri-calendar-line", "ri-time-line",
    "ri-mail-line", "ri-chat-3-line", "ri-message-3-line", "ri-share-line", "ri-download-line",
    "ri-upload-line", "ri-eye-line", "ri-bookmark-line", "ri-map-pin-line", "ri-global-line",
    "ri-github-fill", "ri-wechat-fill", "ri-qq-fill", "ri-weibo-fill", "ri-rss-line",
    "fa fa-home", "fa fa-user", "fa fa-cog", "fa fa-search", "fa fa-heart", "fa fa-star"
  ];

  function libraryNames(value) {
    if (value === "both") { return ["remixicon", "fontawesome"]; }
    if (value === "custom") { return []; }
    return [value === "fontawesome" ? "fontawesome" : "remixicon"];
  }

  function getCustomItems() {
    if (!target) { return []; }
    var groups = [];
    try { groups = JSON.parse(target.dataset.iconCustom || "[]"); } catch (e) { groups = []; }
    if (!Array.isArray(groups)) { return []; }
    var items = [];
    groups.forEach(function (group) {
      if (!group || !Array.isArray(group.icons)) { return; }
      group.icons.forEach(function (name) {
        if (typeof name === "string" && name.trim()) {
          items.push({ name: name.trim(), library: "custom", group: String(group.title || "自定义图标") });
        }
      });
    });
    return items;
  }

  function getItems() {
    if (library === "custom") { return getCustomItems(); }
    var names = libraryNames(library);
    var items = [];
    names.forEach(function (name) {
      (Array.isArray(catalog[name]) ? catalog[name] : []).forEach(function (item) {
        items.push({ name: item, library: name, group: name === "remixicon" ? "Remix Icon" : "Font Awesome 4" });
      });
    });
    getCustomItems().forEach(function (item) { items.push(item); });
    return items;
  }

  function focusableInModal() {
    return Array.prototype.slice.call(modal.querySelectorAll(
      "button:not([disabled]):not([hidden]), input:not([disabled]):not([hidden])"
    )).filter(function (el) {
      return el.tabIndex >= 0 && (el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement);
    });
  }

  function render() {
    renderTimer = null;
    var query = (search.value || "").trim().toLowerCase();
    var items = getItems();
    var customItems = getCustomItems();
    var filtered = query
      ? items.filter(function (item) { return item.name.toLowerCase().indexOf(query) !== -1; })
      : (browseAll || library === "custom"
        ? items
        : customItems.concat(items.filter(function (item) {
          return item.library !== "custom" && featured.indexOf(item.name) !== -1;
        })));
    var shown = filtered.slice(0, visibleCount);
    grid.innerHTML = "";
    var lastGroup = null;
    shown.forEach(function (item, index) {
      if (item.group && item.group !== lastGroup && (library === "custom" || browseAll)) {
        var groupTitle = document.createElement("h3");
        groupTitle.className = "admin-icon-picker-group-label";
        groupTitle.setAttribute("role", "presentation");
        groupTitle.textContent = item.group;
        grid.appendChild(groupTitle);
        lastGroup = item.group;
      }
      var button = document.createElement("button");
      button.type = "button";
      button.className = "admin-icon-picker-item";
      button.dataset.iconValue = item.name;
      button.setAttribute("role", "option");
      button.title = item.name;
      button.setAttribute("aria-label", item.name);
      button.setAttribute("aria-selected", target && target.value.trim() === item.name ? "true" : "false");
      button.tabIndex = index === 0 ? 0 : -1;
      var glyph = document.createElement("i");
      glyph.className = item.name;
      glyph.setAttribute("aria-hidden", "true");
      var label = document.createElement("span");
      label.textContent = item.name.replace(/^ri-/, "").replace(/^fa fa-/, "");
      button.appendChild(glyph);
      button.appendChild(label);
      grid.appendChild(button);
    });
    if (filtered.length === 0) {
      grid.innerHTML = '<p class="admin-icon-picker-empty">没有匹配的图标。可以直接在字段中输入自定义 class。</p>';
    }
    if (shown.length < filtered.length) {
      var more = document.createElement("button");
      more.type = "button";
      more.className = "admin-icon-picker-more";
      more.textContent = "加载更多（还有 " + (filtered.length - shown.length) + " 个）";
      more.addEventListener("click", function () { visibleCount += 168; render(); });
      grid.appendChild(more);
    }
    var label = query ? "匹配 " + filtered.length + " 个" : (library === "custom"
      ? "自定义图标共 " + filtered.length + " 个"
      : (browseAll ? "图标库共 " + filtered.length + " 个" : "常用图标 · 图标库共 " + items.length + " 个"));
    meta.textContent = label + (shown.length < filtered.length ? "，当前显示 " + shown.length + " 个" : "");
    browse.hidden = !!query || browseAll || library === "custom";
  }

  function scheduleRender() {
    if (renderTimer !== null) { window.clearTimeout(renderTimer); }
    renderTimer = window.setTimeout(render, 80);
  }

  function updatePreview(input) {
    if (!input) { return; }
    var wrap = input.closest("[data-sf-icon-field]") || input.closest("[data-sf-icon-wrap]") || input.parentElement;
    var preview = wrap ? wrap.querySelector("[data-sf-icon-preview]") : null;
    if (!preview) { return; }
    preview.innerHTML = "";
    var value = input.value.trim();
    if (value !== "") {
      var glyph = document.createElement("i");
      glyph.className = value;
      glyph.setAttribute("aria-hidden", "true");
      preview.appendChild(glyph);
    } else {
      preview.textContent = "—";
    }
  }

  function open(input) {
    lastFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    target = input;
    library = input.dataset.iconLibrary || "remixicon";
    if (!Array.isArray(catalog[library]) && library !== "both" && library !== "custom") { library = "remixicon"; }
    if (library === "custom" && getCustomItems().length === 0) { library = "remixicon"; }
    var hasCustom = getCustomItems().length > 0;
    modal.querySelectorAll("[data-icon-library]").forEach(function (tab) {
      tab.hidden = tab.dataset.iconLibrary === "custom" && !hasCustom;
      var active = tab.dataset.iconLibrary === library;
      tab.classList.toggle("is-active", active);
      tab.setAttribute("aria-selected", active ? "true" : "false");
    });
    search.value = "";
    visibleCount = 168;
    browseAll = false;
    modal.hidden = false;
    modal.setAttribute("aria-hidden", "false");
    document.documentElement.classList.add("admin-modal-open");
    document.body.classList.add("admin-modal-open");
    render();
    window.setTimeout(function () { search.focus(); }, 0);
  }

  function close(restoreFocus) {
    if (restoreFocus === undefined) { restoreFocus = true; }
    var focusTarget = lastFocus;
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
    document.documentElement.classList.remove("admin-modal-open");
    document.body.classList.remove("admin-modal-open");
    target = null;
    if (restoreFocus && focusTarget && focusTarget.isConnected && typeof focusTarget.focus === "function") {
      window.setTimeout(function () { focusTarget.focus(); }, 0);
    }
    lastFocus = null;
  }

  document.addEventListener("click", function (event) {
    var picker = event.target.closest && event.target.closest("[data-icon-picker]");
    if (picker) {
      var wrap = picker.closest("[data-sf-icon-field]") || picker.closest("[data-sf-icon-wrap]");
      var input = wrap ? wrap.querySelector("[data-icon-input]") : null;
      if (input) { open(input); }
      return;
    }
    var clear = event.target.closest && event.target.closest("[data-icon-clear]");
    if (clear) {
      var clearWrap = clear.closest("[data-sf-icon-field]") || clear.closest("[data-sf-icon-wrap]");
      var clearInput = clearWrap ? clearWrap.querySelector("[data-icon-input]") : null;
      if (clearInput) {
        clearInput.value = "";
        clearInput.dispatchEvent(new Event("input", { bubbles: true }));
        updatePreview(clearInput);
      }
      return;
    }
    var item = event.target.closest && event.target.closest("[data-icon-value]");
    if (item && target) {
      target.value = item.dataset.iconValue || "";
      target.dispatchEvent(new Event("input", { bubbles: true }));
      target.dispatchEvent(new Event("change", { bubbles: true }));
      updatePreview(target);
      close();
    }
  });

  document.addEventListener("input", function (event) {
    if (event.target.matches && event.target.matches("[data-icon-input]")) {
      updatePreview(event.target);
    }
  });
  modal.querySelectorAll("[data-icon-library]").forEach(function (tab) {
    tab.addEventListener("click", function () {
      library = tab.dataset.iconLibrary;
      visibleCount = 168;
      browseAll = false;
      search.value = "";
      modal.querySelectorAll("[data-icon-library]").forEach(function (other) {
        var active = other === tab;
        other.classList.toggle("is-active", active);
        other.setAttribute("aria-selected", active ? "true" : "false");
      });
      render();
    });
  });
  grid.addEventListener("keydown", function (event) {
    var current = event.target.closest && event.target.closest("[data-icon-value]");
    if (!current) { return; }
    var items = Array.prototype.slice.call(grid.querySelectorAll("[data-icon-value]"));
    var index = items.indexOf(current);
    var columns = Math.max(1, getComputedStyle(grid).gridTemplateColumns.split(/\s+/).filter(Boolean).length);
    var nextIndex = index;
    if (event.key === "ArrowRight") { nextIndex = Math.min(items.length - 1, index + 1); }
    else if (event.key === "ArrowLeft") { nextIndex = Math.max(0, index - 1); }
    else if (event.key === "ArrowDown") { nextIndex = Math.min(items.length - 1, index + columns); }
    else if (event.key === "ArrowUp") { nextIndex = Math.max(0, index - columns); }
    else if (event.key === "Home") { nextIndex = 0; }
    else if (event.key === "End") { nextIndex = items.length - 1; }
    else { return; }
    event.preventDefault();
    current.tabIndex = -1;
    items[nextIndex].tabIndex = 0;
    items[nextIndex].focus();
  });
  search.addEventListener("input", scheduleRender);
  browse.addEventListener("click", function () {
    browseAll = true;
    visibleCount = 168;
    render();
    window.setTimeout(function () {
      var first = grid.querySelector("[data-icon-value]");
      if (first) { first.focus(); }
    }, 0);
  });
  modal.querySelector("[data-icon-picker-close]").addEventListener("click", close);
  modal.addEventListener("click", function (event) {
    if (event.target === modal) { close(); }
  });
  modal.addEventListener("keydown", function (event) {
    if (modal.hidden) { return; }
    if (event.key === "Escape") {
      event.preventDefault();
      close();
      return;
    }
    if (event.key !== "Tab") { return; }
    var focusables = focusableInModal();
    if (focusables.length === 0) {
      event.preventDefault();
      modal.querySelector(".admin-icon-picker").focus();
      return;
    }
    var first = focusables[0];
    var last = focusables[focusables.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  document.querySelectorAll("[data-icon-input]").forEach(updatePreview);
})();
