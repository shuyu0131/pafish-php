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
    control.classList.remove("is-open");
    var trigger = control.querySelector(".admin-control-trigger");
    if (trigger) trigger.setAttribute("aria-expanded", "false");
    if (openControl === control) openControl = null;
  }
  function toggle(control) {
    if (openControl && openControl !== control) close(openControl);
    if (control.classList.contains("is-open")) close(control);
    else {
      control.classList.add("is-open");
      var trigger = control.querySelector(".admin-control-trigger");
      if (trigger) trigger.setAttribute("aria-expanded", "true");
      openControl = control;
    }
  }
  function syncState(source, target) {
    target.disabled = source.disabled;
    target.classList.toggle("is-error", source.getAttribute("aria-invalid") === "true" || source.classList.contains("is-error"));
    target.classList.toggle("is-warning", source.getAttribute("aria-invalid") === "warning" || source.classList.contains("is-warning"));
  }

  function initSelect(select) {
    if (select.dataset.adminControl || select.multiple || select.size > 1) return;
    select.dataset.adminControl = "select";
    var control = document.createElement("span");
    control.className = "admin-control admin-select-control";
    select.parentNode.insertBefore(control, select);
    control.appendChild(select);
    select.classList.add("admin-native-control");

    var trigger = document.createElement("button");
    trigger.type = "button";
    trigger.className = "input admin-control-trigger";
    Array.prototype.forEach.call(select.classList, function (name) { if (name !== "input") trigger.classList.add(name); });
    trigger.setAttribute("aria-haspopup", "listbox");
    trigger.setAttribute("aria-expanded", "false");
    trigger.setAttribute("aria-label", select.getAttribute("aria-label") || "选择");
    control.appendChild(trigger);
    var menu = document.createElement("div");
    menu.className = "admin-control-menu";
    menu.setAttribute("role", "listbox");
    control.appendChild(menu);

    function sync() {
      var option = select.options[select.selectedIndex];
      trigger.textContent = option ? option.textContent : "请选择";
      syncState(select, trigger);
      Array.prototype.forEach.call(menu.children, function (item, index) {
        var selected = select.selectedIndex === index;
        item.classList.toggle("is-selected", selected);
        item.setAttribute("aria-selected", selected ? "true" : "false");
      });
    }
    Array.prototype.forEach.call(select.options, function (option, index) {
      var item = document.createElement("button");
      item.type = "button";
      item.className = "admin-control-option";
      item.setAttribute("role", "option");
      item.textContent = option.textContent;
      item.disabled = option.disabled;
      item.addEventListener("click", function () {
        if (option.disabled) return;
        select.selectedIndex = index;
        emit(select);
        sync();
        close(control);
        trigger.focus();
      });
      menu.appendChild(item);
    });
    trigger.addEventListener("click", function () { if (!select.disabled) toggle(control); });
    trigger.addEventListener("keydown", function (event) {
      if (event.key === "Escape") { close(control); return; }
      if (event.key !== "ArrowDown" && event.key !== "ArrowUp" && event.key !== "Enter" && event.key !== " ") return;
      event.preventDefault();
      if (!control.classList.contains("is-open")) toggle(control);
      if (event.key === "Enter" || event.key === " ") return;
      var direction = event.key === "ArrowUp" ? -1 : 1;
      var index = select.selectedIndex;
      do { index += direction; } while (index >= 0 && index < select.options.length && select.options[index].disabled);
      if (index >= 0 && index < select.options.length) { select.selectedIndex = index; emit(select); sync(); }
    });
    select.addEventListener("change", sync);
    sync();
  }

  function initDate(input) {
    if (input.dataset.adminControl) return;
    input.dataset.adminControl = "date";
    var control = document.createElement("span");
    control.className = "admin-control admin-date-control";
    input.parentNode.insertBefore(control, input);
    control.appendChild(input);
    input.classList.add("admin-native-control");
    var trigger = document.createElement("button");
    trigger.type = "button";
    trigger.className = "input admin-control-trigger";
    Array.prototype.forEach.call(input.classList, function (name) { if (name !== "input") trigger.classList.add(name); });
    trigger.setAttribute("aria-haspopup", "dialog");
    trigger.setAttribute("aria-expanded", "false");
    trigger.setAttribute("aria-label", input.title || "选择日期");
    control.appendChild(trigger);
    var panel = document.createElement("div");
    panel.className = "admin-date-panel";
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-label", "日期选择");
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
      var title = document.createElement("strong"); title.textContent = view.getFullYear() + "年 " + monthNames[view.getMonth()];
      prev.addEventListener("click", function () { view.setMonth(view.getMonth() - 1); render(); });
      next.addEventListener("click", function () { view.setMonth(view.getMonth() + 1); render(); });
      head.appendChild(prev); head.appendChild(title); head.appendChild(next); panel.appendChild(head);
      var grid = document.createElement("div"); grid.className = "admin-date-grid";
      weekNames.forEach(function (name) { var label = document.createElement("span"); label.className = "admin-date-week"; label.textContent = name; grid.appendChild(label); });
      var first = new Date(view.getFullYear(), view.getMonth(), 1).getDay();
      first = first === 0 ? 6 : first - 1;
      var days = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
      for (var i = 0; i < first + days; i++) {
        if (i < first) { grid.appendChild(document.createElement("span")); continue; }
        var day = i - first + 1;
        var date = new Date(view.getFullYear(), view.getMonth(), day);
        var button = document.createElement("button"); button.type = "button"; button.className = "admin-date-day"; button.textContent = day;
        if (input.value.slice(0, 10) === dateText(date)) button.classList.add("is-selected");
        if (dateText(new Date()) === dateText(date)) button.classList.add("is-today");
        button.addEventListener("click", (function (selected) { return function () { setValue(selected); }; })(date));
        grid.appendChild(button);
      }
      panel.appendChild(grid);
      if (input.type === "datetime-local") {
        var timeRow = document.createElement("label"); timeRow.className = "admin-date-time"; timeRow.appendChild(document.createTextNode("时间"));
        var time = document.createElement("input"); time.type = "time"; time.className = "input"; time.value = input.value.slice(11, 16) || "00:00";
        time.addEventListener("change", function () { setValue(parseDate(input.value) || view, time.value); });
        timeRow.appendChild(time); panel.appendChild(timeRow);
      }
    }
    trigger.addEventListener("click", function () { if (!input.disabled) { view = parseDate(input.value) || new Date(); view.setDate(1); render(); toggle(control); } });
    trigger.addEventListener("keydown", function (event) { if (event.key === "Escape") close(control); });
    input.addEventListener("change", sync);
    sync();
  }

  function init(root) {
    (root || document).querySelectorAll("select.input").forEach(initSelect);
    (root || document).querySelectorAll('input[type="date"], input[type="datetime-local"]').forEach(initDate);
  }

  document.addEventListener("click", function (event) { if (openControl && !openControl.contains(event.target)) close(openControl); });
  document.addEventListener("keydown", function (event) { if (event.key === "Escape" && openControl) close(openControl); });
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", function () { init(document); }); else init(document);
})();
