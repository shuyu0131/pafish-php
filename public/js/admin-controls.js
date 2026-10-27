(function () {
  "use strict";

  var openControl = null;
  var monthNames = ["一月", "二月", "三月", "四月", "五月", "六月", "七月", "八月", "九月", "十月", "十一月", "十二月"];
  var weekNames = ["一", "二", "三", "四", "五", "六", "日"];

  function pad(value) { return String(value).padStart(2, "0"); }
  function dateText(date) { return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate()); }
  function parseDate(value) {
    var match = String(value || "").match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!match) return null;
    var date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    return isNaN(date.getTime()) ? null : date;
  }
  function emit(input) {
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }
  function close(control) {
    if (!control) return;
    clearTimeout(control._adminHoverTimer);
    control._adminHoverOpen = false;
    control.classList.remove("is-open");
    var trigger = control.querySelector(".admin-control-trigger");
    if (trigger) trigger.setAttribute("aria-expanded", "false");
    var popup = control._adminPopup;
    if (popup) {
      if (control._adminReposition) {
        window.removeEventListener("resize", control._adminReposition);
        control._adminReposition = null;
      }
      window.removeEventListener("scroll", control._adminOutsideScroll, true);
      control._adminOutsideScroll = null;
      popup.classList.remove("is-portal-open");
      popup.style.position = "";
      popup.style.left = "";
      popup.style.top = "";
      popup.style.width = "";
      popup.style.minWidth = "";
      popup.style.maxWidth = "";
      control.appendChild(popup);
      control._adminPopup = null;
    }
    if (control._adminResetSearch) control._adminResetSearch();
    if (openControl === control) openControl = null;
  }
  function portalPopup(control) {
    var popup = control.querySelector(".admin-control-menu, .admin-date-panel");
    var trigger = control.querySelector(".admin-control-trigger");
    if (!popup || !trigger) return;
    popup.classList.add("is-portal-open");
    popup.style.position = "fixed";
    (control.closest('.admin-modal-backdrop') || document.body).appendChild(popup);
    function reposition() {
      var rect = trigger.getBoundingClientRect();
      var minWidth = popup.classList.contains('admin-date-panel') ? 272 : Math.max(rect.width, popup.classList.contains('has-search') ? 256 : popup.classList.contains('admin-paragraph-menu') ? 144 : 120);
      var maxWidth = Math.max(120, window.innerWidth - 16);
      var width = Math.min(minWidth, maxWidth) + 'px';
      popup.style.minWidth = width;
      popup.style.width = width;
      popup.style.maxWidth = maxWidth + "px";
      var popupWidth = popup.offsetWidth || minWidth;
      var left = Math.max(8, Math.min(rect.left, window.innerWidth - popupWidth - 8));
      var top = rect.bottom + 6;
      if (top + popup.offsetHeight > window.innerHeight - 8 && rect.top - popup.offsetHeight - 6 >= 8) {
        top = rect.top - popup.offsetHeight - 6;
      }
      top = Math.max(8, Math.min(top, window.innerHeight - popup.offsetHeight - 8));
      popup.style.left = left + "px";
      popup.style.top = top + "px";
    }
    control._adminReposition = reposition;
    control._adminOutsideScroll = function (event) {
      if (!popup.contains(event.target)) close(control);
    };
    window.addEventListener("scroll", control._adminOutsideScroll, true);
    window.addEventListener("resize", reposition);
    reposition();
    control._adminPopup = popup;
  }
  function open(control) {
    if (openControl && openControl !== control) close(openControl);
    if (!control.classList.contains("is-open")) {
      control.classList.add("is-open");
      var trigger = control.querySelector(".admin-control-trigger");
      if (trigger) trigger.setAttribute("aria-expanded", "true");
      openControl = control;
      portalPopup(control);
    }
  }
  function toggle(control) {
    if (control.classList.contains('is-open')) close(control);
    else open(control);
  }
  function focusPastControl(control, backwards, trigger) {
    var scope = control.closest('.admin-modal-backdrop, [role="dialog"]') || document;
    var ordered = Array.prototype.slice.call(scope.querySelectorAll('a[href], area[href], button, input, select, textarea, [tabindex]'));
    function isFocusable(element) {
      if (element.disabled || element.classList.contains('admin-native-control') || element.tabIndex < 0 || element.closest('[hidden], [inert]')) return false;
      if (element !== trigger && element.closest('.admin-control') === control) return false;
      if (!element.getClientRects().length) return false;
      return window.getComputedStyle(element).visibility !== 'hidden';
    }
    var triggerIndex = ordered.indexOf(trigger);
    var target = null;
    if (backwards) {
      for (var i = triggerIndex - 1; i >= 0; i--) if (isFocusable(ordered[i])) { target = ordered[i]; break; }
    } else {
      for (var j = triggerIndex + 1; j < ordered.length; j++) if (isFocusable(ordered[j])) { target = ordered[j]; break; }
    }
    if (target) target.focus({ preventScroll: true });
    else trigger.focus({ preventScroll: true });
  }
  function syncState(source, target) {
    target.disabled = source.disabled;
    target.classList.toggle("is-error", source.getAttribute("aria-invalid") === "true" || source.classList.contains("is-error"));
    target.classList.toggle("is-warning", source.getAttribute("aria-invalid") === "warning" || source.classList.contains("is-warning"));
  }

  function initSelect(select) {
    if (select.dataset.adminControl || select.multiple || select.size > 1) return;
    var explicitCommit = select.dataset.adminSelectCommit === 'explicit';
    var preserveFocus = select.dataset.adminSelectPreserveFocus === 'true';
    var paragraphStyle = select.dataset.adminSelectStyle === 'paragraph';
    var searchable = !paragraphStyle && select.dataset.adminSelectSearch !== 'false' && (select.dataset.adminSelectSearch === 'true' || select.options.length >= 10);
    var controlLabel = select.getAttribute('aria-label') || (select.labels && select.labels[0] ? select.labels[0].textContent.trim() : '');
    if (!controlLabel && select.options.length) controlLabel = select.options[0].textContent.replace(/^(全部|选择|请选择)\s*/, '').replace(/[.…]+$/, '').trim();
    if (!controlLabel) controlLabel = '选择';
    select.dataset.adminControl = "select";
    var control = document.createElement("span");
    control.className = "admin-control admin-select-control";
    select.parentNode.insertBefore(control, select);
    control.appendChild(select);

    var trigger = document.createElement("button");
    trigger.type = "button";
    trigger.className = "input admin-control-trigger";
    Array.prototype.forEach.call(select.classList, function (name) { if (name !== "input") trigger.classList.add(name); });
    trigger.setAttribute("aria-haspopup", "listbox");
    trigger.setAttribute("aria-expanded", "false");
    trigger.setAttribute("aria-label", controlLabel);
    control.appendChild(trigger);
    var menu = document.createElement("div");
    menu.className = "admin-control-menu";
    menu.setAttribute("role", searchable ? "dialog" : "listbox");
    menu.id = 'admin-select-menu-' + Math.random().toString(36).slice(2, 10);
    menu.setAttribute('aria-label', searchable ? trigger.getAttribute('aria-label') + '选项' : trigger.getAttribute('aria-label'));
    trigger.setAttribute('aria-controls', menu.id);
    if (searchable) trigger.setAttribute('aria-haspopup', 'dialog');
    if (paragraphStyle) menu.classList.add('admin-paragraph-menu');
    control.appendChild(menu);
    var searchInput = null;
    var optionList = menu;
    var emptyMessage = null;
    if (searchable) {
      menu.classList.add('has-search');
      searchInput = document.createElement('input');
      searchInput.type = 'search';
      searchInput.className = 'input admin-control-search-input';
      searchInput.placeholder = '搜索' + trigger.getAttribute('aria-label') + '…';
      searchInput.setAttribute('aria-label', '搜索' + trigger.getAttribute('aria-label'));
      searchInput.setAttribute('role', 'searchbox');
      searchInput.setAttribute('autocomplete', 'off');
      searchInput.setAttribute('autocapitalize', 'off');
      menu.appendChild(searchInput);
      optionList = document.createElement('div');
      optionList.className = 'admin-control-options';
      optionList.setAttribute('role', 'listbox');
      optionList.setAttribute('aria-label', trigger.getAttribute('aria-label'));
      optionList.id = menu.id + '-options';
      searchInput.setAttribute('aria-controls', optionList.id);
      menu.appendChild(optionList);
      emptyMessage = document.createElement('div');
      emptyMessage.className = 'admin-control-empty';
      emptyMessage.setAttribute('role', 'status');
      emptyMessage.setAttribute('aria-live', 'polite');
      emptyMessage.textContent = '没有匹配的选项';
      emptyMessage.hidden = true;
      menu.appendChild(emptyMessage);
      control._adminResetSearch = function () {
        searchInput.value = '';
        filterOptions();
      };
    }
    if (preserveFocus) {
      // Formatting commands must not blur the textarea and hide its selection.
      trigger.addEventListener('mousedown', function (event) { event.preventDefault(); });
      menu.addEventListener('mousedown', function (event) { event.preventDefault(); });
    }

    function sync() {
      var option = select.options[select.selectedIndex];
      if (paragraphStyle) {
        if (!trigger.firstChild) trigger.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h12M9 5v14M5 19h8m4-8 3 3 3-3"/></svg>';
        trigger.title = '段落格式：' + (option ? option.textContent : '正文');
        trigger.setAttribute('aria-label', trigger.title);
      } else trigger.textContent = option ? option.textContent : "请选择";
      syncState(select, trigger);
      Array.prototype.forEach.call(optionList.children, function (item, index) {
        var selected = select.selectedIndex === index;
        item.classList.toggle("is-selected", selected);
        item.setAttribute("aria-selected", selected ? "true" : "false");
        item.disabled = select.options[index].disabled;
        item.hidden = select.options[index].hidden;
      });
      if (searchable) filterOptions();
    }
    function normalizedText(value) {
      return String(value || '').replace(/\s+/g, ' ').trim().toLocaleLowerCase();
    }
    function filterOptions() {
      if (!searchable) return;
      var query = normalizedText(searchInput.value);
      var visibleCount = 0;
      Array.prototype.forEach.call(optionList.children, function (item, index) {
        item.hidden = select.options[index].hidden || (!!query && item._adminSearchText.indexOf(query) === -1);
        if (!item.hidden) visibleCount++;
      });
      emptyMessage.hidden = visibleCount !== 0;
      if (control._adminReposition) control._adminReposition();
    }
    function enabledItems() { return Array.prototype.filter.call(optionList.children, function (item) { return !item.disabled && !item.hidden; }); }
    function selectOption(item) {
      var index = Array.prototype.indexOf.call(optionList.children, item);
      var option = select.options[index];
      if (!option || option.disabled || item.hidden) return;
      var focused = document.activeElement;
      select.selectedIndex = index;
      emit(select);
      close(control);
      if (!preserveFocus && document.activeElement === focused) trigger.focus({preventScroll: true});
    }
    Array.prototype.forEach.call(select.options, function (option, index) {
      var item = document.createElement("button");
      item.type = "button";
      item.className = "admin-control-option";
      item.setAttribute("role", "option");
      item.textContent = option.textContent;
      item._adminSearchText = normalizedText(option.textContent);
      if (paragraphStyle) {
        item.dataset.paragraphLevel = option.value;
      }
      item.disabled = option.disabled;
      item.hidden = option.hidden;
      if (searchable) item.tabIndex = -1;
      item.addEventListener("click", function () {
        selectOption(item);
      });
      optionList.appendChild(item);
    });
    if (searchable) {
      searchInput.addEventListener('input', filterOptions);
      searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          close(control);
          trigger.focus({ preventScroll: true });
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          var enabled = enabledItems();
          if (!enabled.length) return;
          event.preventDefault();
          enabled[event.key === 'ArrowDown' ? 0 : enabled.length - 1].focus({ preventScroll: true });
        } else if (event.key === 'Enter') {
          var first = enabledItems()[0];
          if (first) { event.preventDefault(); selectOption(first); }
        } else if (event.key === 'Tab') {
          event.preventDefault();
          close(control);
          focusPastControl(control, event.shiftKey, trigger);
        }
      });
      optionList.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          close(control);
          trigger.focus({ preventScroll: true });
          return;
        }
        if (event.key === 'Tab') {
          event.preventDefault();
          close(control);
          focusPastControl(control, event.shiftKey, trigger);
          return;
        }
        if (!/^(ArrowDown|ArrowUp|Home|End)$/.test(event.key)) return;
        var enabled = enabledItems();
        if (!enabled.length) return;
        event.preventDefault();
        var index = enabled.indexOf(document.activeElement);
        if (event.key === 'Home') index = 0;
        else if (event.key === 'End') index = enabled.length - 1;
        else index = Math.max(0, Math.min(index + (event.key === 'ArrowUp' ? -1 : 1), enabled.length - 1));
        enabled[index].focus({ preventScroll: true });
      });
    }
    trigger.addEventListener("click", function () {
      if (select.disabled) return;
      if (control._adminHoverOpen) { clearTimeout(control._adminHoverTimer); control._adminHoverOpen = false; }
      else toggle(control);
    });
    if (select.dataset.adminSelectHover === 'true') {
      function hoverEnter(event) {
        if (event.pointerType !== 'mouse' || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
        clearTimeout(control._adminHoverTimer);
        if (select.disabled || control.classList.contains('is-open')) return;
        control._adminHoverTimer = setTimeout(function () {
          if (!select.disabled && control.isConnected) { open(control); control._adminHoverOpen = true; }
        }, 100);
      }
      function hoverLeave(event) {
        var next = event.relatedTarget;
        if (next instanceof Node && (control.contains(next) || menu.contains(next))) return;
        clearTimeout(control._adminHoverTimer);
        if (control._adminHoverOpen) control._adminHoverTimer = setTimeout(function () { close(control); }, 180);
      }
      control.addEventListener('pointerenter', hoverEnter);
      control.addEventListener('pointerleave', hoverLeave);
      menu.addEventListener('pointerenter', hoverEnter);
      menu.addEventListener('pointerleave', hoverLeave);
      function pinMenu() { clearTimeout(control._adminHoverTimer); control._adminHoverOpen = false; }
      control.addEventListener('keydown', pinMenu);
      menu.addEventListener('keydown', pinMenu);
    }
    trigger.addEventListener("keydown", function (event) {
      if (explicitCommit && /^(ArrowDown|ArrowUp|Enter| )$/.test(event.key)) {
        event.preventDefault();
        if (!control.classList.contains('is-open')) toggle(control);
        var enabled = enabledItems();
        var current = optionList.children[select.selectedIndex];
        var next = enabled.indexOf(current) + (event.key === 'ArrowDown' ? 1 : event.key === 'ArrowUp' ? -1 : 0);
        (enabled[Math.max(0, Math.min(next, enabled.length - 1))] || trigger).focus({preventScroll: true});
        return;
      }
      if (event.key === "Escape" && control.classList.contains("is-open")) { event.preventDefault(); event.stopPropagation(); close(control); return; }
      if (searchable && (event.key === "ArrowDown" || event.key === "ArrowUp" || event.key === "Enter" || event.key === " ")) {
        event.preventDefault();
        if (!control.classList.contains("is-open")) open(control);
        if (searchInput) searchInput.focus({ preventScroll: true });
        return;
      }
      if (event.key !== "ArrowDown" && event.key !== "ArrowUp" && event.key !== "Enter" && event.key !== " ") return;
      event.preventDefault();
      if (!control.classList.contains("is-open")) toggle(control);
      if (event.key === "Enter" || event.key === " ") return;
      var direction = event.key === "ArrowUp" ? -1 : 1;
      var index = select.selectedIndex;
      do { index += direction; } while (index >= 0 && index < select.options.length && select.options[index].disabled);
      if (index >= 0 && index < select.options.length) { select.selectedIndex = index; emit(select); }
    });
    select.addEventListener("change", sync);
    select.addEventListener("input", sync);
    if (explicitCommit) menu.addEventListener('keydown', function (event) {
      if (event.key === 'Tab') { close(control); return; }
      if (event.key === 'Enter' || event.key === ' ') {
        var option = event.target.closest('.admin-control-option');
        if (option && !option.disabled) { event.preventDefault(); option.click(); }
        return;
      }
      if (!/^(ArrowDown|ArrowUp|Home|End)$/.test(event.key)) return;
      event.preventDefault();
      var enabled = enabledItems();
      var index = enabled.indexOf(document.activeElement);
      if (event.key === 'Home') index = 0;
      else if (event.key === 'End') index = enabled.length - 1;
      else index = Math.max(0, Math.min(index + (event.key === 'ArrowUp' ? -1 : 1), enabled.length - 1));
      if (enabled[index]) enabled[index].focus({preventScroll: true});
    });
    sync();
    // 只有触发器和菜单都建立完成后才隐藏原生控件，初始化中断时仍保留可用控件。
    select.classList.add("admin-native-control");
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    control.classList.add("is-enhanced");
  }

  function initDate(input) {
    if (input.dataset.adminControl) return;
    input.dataset.adminControl = "date";
    var control = document.createElement("span");
    control.className = "admin-control admin-date-control";
    input.parentNode.insertBefore(control, input);
    control.appendChild(input);
    var trigger = document.createElement("button");
    trigger.type = "button";
    trigger.className = "input admin-control-trigger";
    Array.prototype.forEach.call(input.classList, function (name) { if (name !== "input") trigger.classList.add(name); });
    trigger.setAttribute("aria-haspopup", "dialog");
    trigger.setAttribute("aria-expanded", "false");
    trigger.setAttribute("aria-label", input.getAttribute('aria-label') || input.title || (input.labels && input.labels[0] ? input.labels[0].textContent.trim() : "选择日期"));
    control.appendChild(trigger);
    var panel = document.createElement("div");
    panel.className = "admin-date-panel";
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-label", "日期选择");
    panel.id = "admin-date-panel-" + Math.random().toString(36).slice(2, 10);
    trigger.setAttribute("aria-controls", panel.id);
    panel.addEventListener("click", function (event) { event.stopPropagation(); });
    panel.addEventListener("wheel", function (event) {
      // 日历高度不足以滚动时也不把滚轮传给背景页面。
      event.preventDefault();
      panel.scrollTop += event.deltaY;
    }, { passive: false });
    control.appendChild(panel);
    var view = parseDate(input.value) || new Date();
    view.setDate(1);

    function sync() {
      var value = input.value;
      var date = parseDate(value);
      trigger.textContent = date ? dateText(date) + (input.type === "datetime-local" ? " " + (value.slice(11, 16) || "00:00") : "") : "请选择日期";
      syncState(input, trigger);
    }
    function setValue(date, time) {
      input.value = dateText(date) + (input.type === "datetime-local" ? "T" + (time || input.value.slice(11, 16) || "00:00") : "");
      emit(input);
      sync();
      render();
    }
    function render() {
      panel.innerHTML = "";
      var head = document.createElement("div");
      head.className = "admin-date-head";
      var prev = document.createElement("button"); prev.type = "button"; prev.className = "admin-date-nav"; prev.textContent = "‹"; prev.setAttribute("aria-label", "上个月");
      var next = document.createElement("button"); next.type = "button"; next.className = "admin-date-nav"; next.textContent = "›"; next.setAttribute("aria-label", "下个月");
      var viewSelect = document.createElement("span"); viewSelect.className = "admin-date-view";
      var year = document.createElement("select"); year.className = "admin-date-select"; year.setAttribute("aria-label", "选择年份");
      var currentYear = new Date().getFullYear();
      for (var yearValue = Math.min(currentYear - 10, view.getFullYear()); yearValue <= Math.max(currentYear + 10, view.getFullYear()); yearValue++) {
        var yearOption = document.createElement("option"); yearOption.value = yearValue; yearOption.textContent = yearValue + "年"; yearOption.selected = yearValue === view.getFullYear(); year.appendChild(yearOption);
      }
      var month = document.createElement("select"); month.className = "admin-date-select"; month.setAttribute("aria-label", "选择月份");
      monthNames.forEach(function (name, monthIndex) { var monthOption = document.createElement("option"); monthOption.value = monthIndex; monthOption.textContent = name; monthOption.selected = monthIndex === view.getMonth(); month.appendChild(monthOption); });
      year.addEventListener("change", function () { view.setFullYear(Number(year.value)); render(); panel.querySelector('[aria-label="选择年份"]').focus({ preventScroll: true }); });
      month.addEventListener("change", function () { view.setMonth(Number(month.value)); render(); panel.querySelector('[aria-label="选择月份"]').focus({ preventScroll: true }); });
      viewSelect.appendChild(year); viewSelect.appendChild(month);
      prev.addEventListener("click", function () { view.setMonth(view.getMonth() - 1); render(); panel.querySelector('[aria-label="上个月"]').focus({ preventScroll: true }); });
      next.addEventListener("click", function () { view.setMonth(view.getMonth() + 1); render(); panel.querySelector('[aria-label="下个月"]').focus({ preventScroll: true }); });
      head.appendChild(prev); head.appendChild(viewSelect); head.appendChild(next); panel.appendChild(head);
      var grid = document.createElement("div"); grid.className = "admin-date-grid";
      weekNames.forEach(function (name) { var label = document.createElement("span"); label.className = "admin-date-week"; label.textContent = name; grid.appendChild(label); });
      var first = new Date(view.getFullYear(), view.getMonth(), 1).getDay();
      first = first === 0 ? 6 : first - 1;
      var days = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
      for (var i = 0; i < first + days; i++) {
        if (i < first) { grid.appendChild(document.createElement("span")); continue; }
        var day = i - first + 1;
        var date = new Date(view.getFullYear(), view.getMonth(), day);
        var selected = input.value.slice(0, 10) === dateText(date);
        var today = dateText(new Date()) === dateText(date);
        var button = document.createElement("button"); button.type = "button"; button.className = "admin-date-day"; button.textContent = day;
        button.setAttribute("aria-label", date.getFullYear() + "年" + (date.getMonth() + 1) + "月" + day + "日");
        button.setAttribute("aria-pressed", selected ? "true" : "false");
        if (today) button.setAttribute("aria-current", "date");
        if (selected) button.classList.add("is-selected");
        if (today) button.classList.add("is-today");
        button.addEventListener("click", (function (selected) { return function () {
          setValue(selected);
          if (input.type === "date") { close(control); trigger.focus({ preventScroll: true }); }
          else { var active = panel.querySelector('.is-selected'); if (active) active.focus({ preventScroll: true }); }
        }; })(date));
        grid.appendChild(button);
      }
      panel.appendChild(grid);
      if (input.type === "datetime-local") {
        var timeRow = document.createElement("label"); timeRow.className = "admin-date-time"; timeRow.appendChild(document.createTextNode("时间"));
        var time = document.createElement("input"); time.type = "time"; time.className = "input"; time.value = input.value.slice(11, 16) || "00:00";
        time.addEventListener("change", function () { setValue(parseDate(input.value) || view, time.value); panel.querySelector('input[type="time"]').focus({ preventScroll: true }); });
        timeRow.appendChild(time); panel.appendChild(timeRow);
      }
      var actions = document.createElement('div'); actions.className = 'admin-date-actions';
      var clear = document.createElement('button'); clear.type = 'button'; clear.className = 'btn btn-ghost'; clear.textContent = '清空';
      clear.disabled = input.required;
      clear.addEventListener('click', function () { input.value = ''; emit(input); sync(); close(control); trigger.focus({ preventScroll: true }); });
      var done = document.createElement('button'); done.type = 'button'; done.className = 'btn btn-ghost'; done.textContent = '完成';
      done.addEventListener('click', function () { close(control); trigger.focus({ preventScroll: true }); });
      actions.appendChild(clear); actions.appendChild(done); panel.appendChild(actions);
    }
    trigger.addEventListener("click", function () { if (!input.disabled) { view = parseDate(input.value) || new Date(); view.setDate(1); render(); toggle(control); } });
    trigger.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && control.classList.contains("is-open")) { event.preventDefault(); event.stopPropagation(); close(control); }
    });
    input.addEventListener("change", sync);
    sync();
    // 自定义日期面板完成后再隐藏原生输入，避免脚本异常造成字段消失。
    input.classList.add("admin-native-control");
    input.tabIndex = -1;
    input.setAttribute('aria-hidden', 'true');
    control.classList.add("is-enhanced");
  }

  function init(root) {
    (root || document).querySelectorAll(".admin-status-tabs").forEach(function (tabs) {
      var active = tabs.querySelector(".admin-tab.is-active");
      if (!active || tabs.scrollWidth <= tabs.clientWidth) return;
      var activeBounds = active.getBoundingClientRect();
      var tabsBounds = tabs.getBoundingClientRect();
      if (activeBounds.right > tabsBounds.right) tabs.scrollLeft += activeBounds.right - tabsBounds.right;
      else if (activeBounds.left < tabsBounds.left) tabs.scrollLeft -= tabsBounds.left - activeBounds.left;
    });
    (root || document).querySelectorAll("select.input").forEach(initSelect);
    (root || document).querySelectorAll('input[type="date"], input[type="datetime-local"]').forEach(initDate);
  }

  document.addEventListener("click", function (event) {
    var popup = openControl && openControl._adminPopup;
    if (openControl && !openControl.contains(event.target) && !(popup && popup.contains(event.target))) close(openControl);
  });
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && openControl) {
      var trigger = openControl.querySelector('.admin-control-trigger');
      close(openControl);
      if (trigger) trigger.focus({ preventScroll: true });
    }
  });
  document.addEventListener("pafish:controls-close", function () { if (openControl) close(openControl); });
  document.addEventListener('pafish:controls-init', function (event) { init(event.target); });
  document.addEventListener('pafish:controls-destroy', function (event) { if (openControl && event.target.contains(openControl)) close(openControl); });
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", function () { init(document); }); else init(document);
})();
