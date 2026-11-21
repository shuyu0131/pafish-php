(function () {
  'use strict';
  var form = document.getElementById('settingsForm');
  if (!form) return;
  var tabs = Array.from(form.querySelectorAll('[data-settings-group]'));
  var panels = Array.from(form.querySelectorAll('[data-settings-panel]'));
  var saveBtn = document.getElementById('saveSettingsBtn');
  var errBox = document.getElementById('settingsError');
  var status = document.getElementById('settingsSaveStatus');
  var base = form.action.replace(/\/save$/, '');
  var csrf = form.elements._csrf.value;
  var baseline = snapshot();
  var saving = false;

  function snapshot() {
    return Array.from(new FormData(form)).filter(function (pair) { return pair[0] !== '_csrf'; });
  }
  function dirty() { return JSON.stringify(snapshot()) !== JSON.stringify(baseline); }
  function updateStatus() {
    status.textContent = dirty() ? window.pafishT("js.unsaved") : '';
  }
  function activate(key, updateUrl) {
    if (!tabs.some(function (tab) { return tab.dataset.settingsGroup === key; })) key = 'basic';
    tabs.forEach(function (tab) {
      if (tab.dataset.settingsGroup === key) tab.setAttribute('aria-current', 'page');
      else tab.removeAttribute('aria-current');
    });
    panels.forEach(function (panel) { panel.hidden = panel.dataset.settingsPanel !== key; });
    if (updateUrl) history.replaceState(null, '', '#settings-' + key);
  }
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function (event) {
      event.preventDefault();
      activate(tab.dataset.settingsGroup, true);
    });
  });
  function restoreGroup() { activate(location.hash.replace(/^#settings-/, ''), false); }
  restoreGroup();
  window.addEventListener('hashchange', restoreGroup);
  form.addEventListener('input', updateStatus);
  form.addEventListener('change', updateStatus);
  window.addEventListener('beforeunload', function (event) {
    if (dirty()) { event.preventDefault(); event.returnValue = ''; }
  });

  function showError(error) {
    errBox.textContent = error.message || window.pafishT("common.operation_failed_retry");
    errBox.hidden = false;
  }
  function clearError() { errBox.hidden = true; errBox.textContent = ''; }
  async function post(url, data) {
    var response = await fetch(url, {method: 'POST', body: data, headers: {'X-Requested-With': 'XMLHttpRequest'}});
    var result;
    try { result = await response.json(); } catch (_) { throw new Error(window.pafishT("js.unexpected_server_response_please_try_again")); }
    if (!response.ok || !result.ok) throw new Error(result.error || window.pafishT("common.operation_failed_retry"));
    return result;
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (saving) return;
    clearError();
    var invalid = Array.from(form.elements).find(function (field) { return field.willValidate && !field.validity.valid; });
    if (invalid) {
      var panel = invalid.closest('[data-settings-panel]');
      if (panel) activate(panel.dataset.settingsPanel, true);
      invalid.reportValidity();
      invalid.focus();
      return;
    }
    var submitted = snapshot();
    var data = new FormData(form);
    form.querySelectorAll('input[type=checkbox]').forEach(function (box) { data.set(box.name, box.checked ? 'true' : 'false'); });
    var ips = String(data.get('blocked_ips') || '').split(/[,，\s]+/).map(function (ip) { return ip.trim(); }).filter(Boolean);
    data.set('blocked_ips', JSON.stringify(Array.from(new Set(ips))));
    var emails = String(data.get('blocked_emails') || '').split(/[,，\s]+/).map(function (email) { return email.trim().toLowerCase(); }).filter(Boolean);
    data.set('blocked_emails', JSON.stringify(Array.from(new Set(emails))));
    var keywords = String(data.get('comment_spam_keywords') || '').split(/[,，\r\n]+/).map(function (word) { return word.trim(); }).filter(Boolean);
    data.set('comment_spam_keywords', JSON.stringify(Array.from(new Set(keywords))));
    saving = true;
    saveBtn.disabled = true;
    saveBtn.textContent = window.pafishT("common.saving");
    try {
      var result = await post(form.action, data);
      // Only clear secrets that have not been edited during the request.
      ['smtp_pass', 'store_account_token'].forEach(function (name) {
        var saved = submitted.find(function (pair) { return pair[0] === name; });
        if (saved && form.elements[name].value === saved[1]) {
          form.elements[name].value = '';
          if (saved[1] !== '') form.elements[name].placeholder = window.pafishT("js.configured_leave_blank_to_keep_the_current_value");
        }
        if (saved) saved[1] = '';
      });
      baseline = submitted;
      updateStatus();
      if (!dirty()) status.textContent = window.pafishT("js.saved_cdfab9");
      if (window.pafishToast) window.pafishToast(window.pafishT("js.settings_saved"), 'success');
      if (result.locale && result.locale !== window.pafishI18n.locale) {
        if (!dirty()) location.reload();
        else status.textContent = window.pafishT('js.language_saved_reload');
      }
    } catch (error) { showError(error); }
    finally { saving = false; saveBtn.disabled = false; saveBtn.textContent = window.pafishT("js.save_settings"); }
  });

  var testBtn = document.getElementById('testSmtpBtn');
  var testMsg = document.getElementById('testSmtpMsg');
  testBtn.addEventListener('click', async function () {
    testMsg.hidden = true;
    testMsg.classList.remove('is-error');
    testBtn.disabled = true;
    testBtn.textContent = window.pafishT("js.sending");
    var data = new FormData();
    ['host', 'port', 'user', 'pass', 'from'].forEach(function (key) { data.append(key, form.elements['smtp_' + key].value); });
    data.append('_csrf', csrf);
    try {
      var result = await post(base + '/test-smtp', data);
      testMsg.textContent = window.pafishT("js.test_email_sent_to") + result.to + window.pafishT("js.check_your_inbox_and_spam_folder");
    } catch (error) { testMsg.textContent = error.message || window.pafishT("common.network_error_retry"); testMsg.classList.add('is-error'); }
    finally { testMsg.hidden = false; testBtn.disabled = false; testBtn.textContent = window.pafishT("js.send_test_email"); }
  });

  var regenBtn = document.getElementById('regenerateKeyBtn');
  regenBtn.addEventListener('click', async function () {
    var confirm = window.pafishConfirm
      ? window.pafishConfirm(window.pafishT("js.the_old_api_key_will_become_invalid_immediately"), {title: window.pafishT("js.regenerate_api_key")})
      : Promise.resolve(window.confirm(window.pafishT("js.the_old_api_key_will_become_invalid_immediately")));
    if (!await confirm) return;
    clearError();
    regenBtn.disabled = true;
    var data = new FormData();
    data.append('_csrf', csrf);
    try {
      var result = await post(base + '/regenerate-key', data);
      form.elements.api_key.value = result.key;
      baseline.forEach(function (pair) { if (pair[0] === 'api_key') pair[1] = result.key; });
      updateStatus();
      if (window.pafishToast) window.pafishToast(window.pafishT("js.api_key_regenerated"), 'success');
    } catch (error) { showError(error); }
    finally { regenBtn.disabled = false; }
  });
})();
