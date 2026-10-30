(function () {
  "use strict";
  document.querySelectorAll("[data-entity-modal]").forEach(function (modal) {
    var config = JSON.parse(modal.querySelector("[data-entity-config]").textContent);
    var form = modal.querySelector("form");
    var title = modal.querySelector(".admin-modal-title");
    var submit = form.querySelector('[type="submit"]');
    var error = form.querySelector('[role="alert"]');
    var saving = false;

    function open(row, preset) {
      if (saving) return;
      form.reset();
      form.elements.id.value = row ? row.getAttribute("data-id") : "";
      var slugAuto = form.querySelector("[data-slug-auto]");
      if (slugAuto) slugAuto.value = row ? "0" : "1";
      form.action = row ? config.baseUrl + "/" + form.elements.id.value + "/save" : config.saveUrl;
      error.hidden = true;
      form.querySelectorAll("details").forEach(function (details) { details.open = false; });
      Object.keys(config.fields).forEach(function (name) {
        var field = form.elements.namedItem(name);
        var value = row ? row.getAttribute("data-" + config.fields[name]) || "" : "";
        if (!field) return;
        if (row) {
          if (field.type === "checkbox") field.checked = value === "1";
          else field.value = value;
        }
        if (preset && Object.prototype.hasOwnProperty.call(preset, name)) field.value = preset[name];
        field.dispatchEvent(new Event("input", { bubbles: true }));
        field.dispatchEvent(new Event("change", { bubbles: true }));
      });
      if (slugAuto) slugAuto.value = row ? "0" : "1";
      title.textContent = row ? config.editTitle : config.createTitle;
      submit.textContent = row ? "保存修改" : config.createTitle;
      form.dispatchEvent(new CustomEvent("pafish:entity-open", { detail: { row: row, creating: !row } }));
      modal.hidden = false;
    }

    document.querySelectorAll("[data-entity-new], [data-entity-edit]").forEach(function (button) {
      var creating = button.getAttribute("data-entity-new") === modal.id;
      if (!creating && button.getAttribute("data-entity-edit") !== modal.id) return;
      button.addEventListener("click", function () {
        open(creating ? null : button.closest("[data-id]"));
      });
    });

    var nameField = form.elements.namedItem("name");
    var slugField = form.elements.namedItem("slug");
    var slugAutoField = form.querySelector("[data-slug-auto]");
    if (nameField && slugField && slugAutoField) {
      nameField.addEventListener("input", function () {
        if (slugAutoField.value === "1") {
          window.pafishPreviewSlug(nameField, slugField, slugAutoField);
        }
      });
      slugField.addEventListener("input", function () { slugAutoField.value = "0"; });
    }

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      if (saving) return;
      error.hidden = true;
      if (!form.reportValidity()) return;
      var data = new FormData(form);
      saving = true;
      submit.disabled = true;
      form.setAttribute("aria-busy", "true");
      modal.setAttribute("data-modal-static", "true");
      modal.querySelectorAll("[data-modal-close]").forEach(function (button) { button.disabled = true; });
      fetch(form.action, { method: "POST", body: data, headers: { "X-Requested-With": "XMLHttpRequest" } })
        .then(function (response) { return response.json(); })
        .then(function (result) {
          if (result && result.ok) window.pafishToastReload(config.savedMessage, "success");
          else { error.textContent = (result && result.error) || "保存失败，请重试"; error.hidden = false; }
        })
        .catch(function () { error.textContent = "网络错误，请重试"; error.hidden = false; })
        .finally(function () {
          saving = false;
          submit.disabled = false;
          form.removeAttribute("aria-busy");
          modal.removeAttribute("data-modal-static");
          modal.querySelectorAll("[data-modal-close]").forEach(function (button) { button.disabled = false; });
          if (!error.hidden) error.scrollIntoView({ block: "nearest" });
        });
    });
    // Widget shortcuts share this form, including region selection before saving.
    modal.addEventListener("pafish:entity-create", function (event) { open(null, event.detail || {}); });
  });
})();
