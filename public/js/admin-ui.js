(function () {
  "use strict";

  var activeModal = null;
  var lastFocus = null;

  function focusables(root) {
    return Array.prototype.slice.call(root.querySelectorAll(
      'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
    ));
  }

  function visible(modal) {
    return modal && !modal.hidden;
  }

  function sync() {
    var modals = document.querySelectorAll('.admin-modal-backdrop');
    var next = null;
    Array.prototype.forEach.call(modals, function (modal) {
      if (visible(modal)) next = modal;
    });
    if (next === activeModal) return;
    if (activeModal && !next) {
      activeModal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('admin-modal-open');
      if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
      lastFocus = null;
    }
    activeModal = next;
    if (!activeModal) return;
    if (!lastFocus) lastFocus = document.activeElement;
    document.body.classList.add('admin-modal-open');
    activeModal.setAttribute('aria-hidden', 'false');
    activeModal.setAttribute('role', 'presentation');
    var dialog = activeModal.querySelector('[role="dialog"],.admin-modal');
    if (dialog) {
      dialog.setAttribute('tabindex', '-1');
      dialog.setAttribute('aria-modal', 'true');
      var target = focusables(dialog)[0] || dialog;
      window.setTimeout(function () { if (visible(activeModal)) target.focus(); }, 0);
    }
  }

  function closeModal(modal) {
    if (!modal || modal.getAttribute('data-modal-static') === 'true') return;
    var resolver = modal._confirmResolve;
    modal._confirmResolve = null;
    modal.hidden = true;
    if (resolver) resolver(false);
    sync();
    if (modal.hasAttribute('data-admin-confirm')) {
      window.setTimeout(function () { if (modal.parentNode) modal.parentNode.removeChild(modal); }, 0);
    }
  }

  function confirmDialog(message, options) {
    options = options || {};
    return new Promise(function (resolve) {
      var backdrop = document.createElement('div');
      backdrop.className = 'admin-modal-backdrop';
      backdrop.setAttribute('data-admin-confirm', '');
      var dialog = document.createElement('div');
      dialog.className = 'admin-modal admin-confirm';
      dialog.setAttribute('role', 'alertdialog');
      dialog.setAttribute('aria-modal', 'true');
      var head = document.createElement('div');
      head.className = 'admin-modal-head';
      var title = document.createElement('h2');
      title.className = 'admin-modal-title';
      title.textContent = options.title || '请确认操作';
      var close = document.createElement('button');
      close.type = 'button';
      close.className = 'admin-icon-btn';
      close.setAttribute('data-modal-close', '');
      close.setAttribute('aria-label', '关闭');
      close.textContent = '×';
      head.appendChild(title);
      head.appendChild(close);
      var bodyWrap = document.createElement('div');
      bodyWrap.className = 'admin-modal-body admin-confirm-body';
      var body = document.createElement('p');
      body.className = 'admin-confirm-message';
      body.textContent = message;
      bodyWrap.appendChild(body);
      if (options.detail) {
        var detail = document.createElement('p');
        detail.className = 'admin-confirm-detail';
        detail.textContent = options.detail;
        bodyWrap.appendChild(detail);
      }
      var actions = document.createElement('div');
      actions.className = 'admin-modal-actions';
      var cancel = document.createElement('button');
      cancel.type = 'button'; cancel.className = 'btn btn-ghost'; cancel.textContent = '取消';
      var accept = document.createElement('button');
      accept.type = 'button'; accept.className = options.danger === false ? 'btn btn-primary' : 'btn btn-danger';
      accept.textContent = options.accept || '确认';
      actions.appendChild(cancel); actions.appendChild(accept);
      dialog.appendChild(head);
      dialog.appendChild(bodyWrap);
      dialog.appendChild(actions);
      backdrop.appendChild(dialog); document.body.appendChild(backdrop);
      backdrop._confirmResolve = resolve;
      function finish(value) {
        if (!backdrop._confirmResolve) return;
        var done = backdrop._confirmResolve; backdrop._confirmResolve = null;
        backdrop.hidden = true; done(value); sync();
        window.setTimeout(function () { if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop); }, 0);
      }
      cancel.addEventListener('click', function () { finish(false); });
      accept.addEventListener('click', function () { finish(true); });
      sync();
    });
  }

  function init() {
    var observer = new MutationObserver(sync);
    observer.observe(document.body, { subtree: true, attributes: true, attributeFilter: ['hidden'] });
    document.addEventListener('click', function (event) {
      var close = event.target.closest('[data-modal-close]');
      if (close) closeModal(close.closest('.admin-modal-backdrop'));
      if (event.target.classList && event.target.classList.contains('admin-modal-backdrop')) closeModal(event.target);
    });
    document.addEventListener('keydown', function (event) {
      if (!activeModal) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeModal(activeModal);
        return;
      }
      if (event.key !== 'Tab') return;
      var dialog = activeModal.querySelector('[role="dialog"],.admin-modal');
      var list = dialog ? focusables(dialog) : [];
      if (!list.length) return;
      var first = list[0];
      var last = list[list.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    sync();
    window.pafishConfirm = confirmDialog;
    window.pafishModalSync = sync;
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
