(function () {
  "use strict";

  var csrf = document.querySelector("[data-store-update-csrf]");
  var buttons = document.querySelectorAll(".admin-store-update");
  if (!csrf || !buttons.length) return;

  function post(formData) {
    return fetch(csrf.dataset.storeUpdateUrl, {
      method: "POST",
      body: formData,
      headers: { "X-Requested-With": "XMLHttpRequest" }
    }).then(function (response) {
      return response.json();
    }).then(function (result) {
      if (result && result.ok) return result;
      throw new Error((result && result.error) || window.pafishT("js.update_failed"));
    });
  }

  buttons.forEach(function (button) {
    button.addEventListener("click", function () {
      var title = button.dataset.title || button.dataset.name;
      var current = button.dataset.localVersion || window.pafishT("js.current_version");
      var version = button.dataset.version || "";
      var changelog = button.dataset.changelog || "";
      var message = window.pafishT("js.change") + title + window.pafishT("js.from_v_efbeb8") + current + window.pafishT("js.update_to_v") + version + "？";
      var detail = window.pafishT("js.the_previous_version_is_restored_automatically_if_an") + (changelog ? window.pafishT("js.release_notes") + changelog : "");
      var confirmation = window.pafishConfirm
        ? window.pafishConfirm(message, { title: window.pafishT("js.update_app"), accept: window.pafishT("js.confirm_update"), danger: false, detail: detail })
        : Promise.resolve(window.confirm(message + "\n" + detail));

      confirmation.then(function (accepted) {
        if (!accepted) return;
        var originalText = button.textContent;
        button.disabled = true;
        button.classList.add("is-loading");
        button.textContent = window.pafishT("js.updating_87c1bc");
        var formData = new FormData();
        formData.append("kind", button.dataset.kind);
        formData.append("name", button.dataset.name);
        formData.append("_csrf", csrf.dataset.storeUpdateCsrf);
        return post(formData).then(function (result) {
          var notice = window.pafishT("js.updated_f54a93") + result.title + window.pafishT("js.to_v") + result.version;
          if (window.pafishToastReload) window.pafishToastReload(notice, "success");
          else window.location.reload();
        }).catch(function (error) {
          button.disabled = false;
          button.classList.remove("is-loading");
          button.textContent = originalText;
          if (window.pafishToast) window.pafishToast(error.message, "error");
          else window.alert(error.message);
        });
      });
    });
  });
})();
