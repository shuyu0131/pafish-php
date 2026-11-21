(function () {
  'use strict';

  var body = document.body;
  var sidebar = document.getElementById('adminSidebar');
  var toggle = document.querySelector('.admin-layout-toggle');
  var backdrop = document.querySelector('.admin-drawer-backdrop');
  var main = document.querySelector('.admin-main');
  var media = window.matchMedia('(max-width: 767px)');
  var collapseKey = 'pafish.admin-nav.collapsed.v1';
  var collapsed = false;
  var drawerOpen = false;

  if (!sidebar || !toggle) return;

  function readStorage(key) {
    try { return window.localStorage.getItem(key); } catch (error) { return null; }
  }

  function writeStorage(key, value) {
    try { window.localStorage.setItem(key, value); } catch (error) { /* Storage can be disabled. */ }
  }

  function isMobile() { return media.matches; }

  function syncToggle() {
    var open = isMobile() ? drawerOpen : !collapsed;
    var label = isMobile()
      ? (open ? toggle.getAttribute('data-label-close') : toggle.getAttribute('data-label-open'))
      : (open ? toggle.getAttribute('data-label-collapse') : toggle.getAttribute('data-label-expand'));
    toggle.setAttribute('aria-expanded', String(open));
    var icon = isMobile() ? 'menu' : (open ? 'collapse' : 'expand');
    toggle.querySelectorAll('[data-nav-icon]').forEach(function (element) {
      element.hidden = element.getAttribute('data-nav-icon') !== icon;
    });
    if (label) {
      toggle.setAttribute('aria-label', label);
      toggle.setAttribute('title', label);
    }
  }

  function setDrawerOpen(open, restoreFocus) {
    drawerOpen = Boolean(open);
    body.classList.toggle('admin-drawer-open', drawerOpen);
    document.documentElement.classList.toggle('admin-drawer-open', drawerOpen);
    if (backdrop) {
      backdrop.hidden = !drawerOpen;
      backdrop.setAttribute('aria-hidden', String(!drawerOpen));
    }
    sidebar.inert = !drawerOpen;
    sidebar.setAttribute('aria-hidden', String(!drawerOpen));
    if (main) {
      main.inert = drawerOpen;
      main.setAttribute('aria-hidden', String(drawerOpen));
    }
    syncToggle();

    if (drawerOpen) {
      var firstLink = sidebar.querySelector('a[href]');
      if (firstLink) firstLink.focus();
    } else if (restoreFocus && toggle) {
      toggle.focus();
    }
  }

  function setCollapsed(value, persist) {
    collapsed = Boolean(value) && !isMobile();
    body.classList.toggle('admin-sidebar-collapsed', collapsed);
    syncToggle();
    if (persist) writeStorage(collapseKey, collapsed ? '1' : '0');
  }

  toggle.addEventListener('click', function () {
    if (isMobile()) {
      setDrawerOpen(!drawerOpen, false);
      return;
    }
    setCollapsed(!collapsed, true);
  });

  if (backdrop) backdrop.addEventListener('click', function () { setDrawerOpen(false, true); });

  sidebar.querySelectorAll('a[href]').forEach(function (link) {
    link.addEventListener('click', function () {
      if (isMobile() && drawerOpen) setDrawerOpen(false, false);
    });
  });

  document.addEventListener('keydown', function (event) {
    if (!isMobile() || !drawerOpen) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      setDrawerOpen(false, true);
      return;
    }
    if (event.key !== 'Tab') return;

    var focusable = sidebar.querySelectorAll('a[href]:not([tabindex="-1"]), button:not([disabled]):not([tabindex="-1"])');
    var visible = Array.prototype.filter.call(focusable, function (element) {
      return !element.closest('[inert]') && element.getClientRects().length > 0;
    });
    if (!visible.length) {
      event.preventDefault();
      toggle.focus();
      return;
    }
    var first = visible[0];
    var last = visible[visible.length - 1];
    if (event.shiftKey && (document.activeElement === first || !sidebar.contains(document.activeElement))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  function syncViewport() {
    if (isMobile()) {
      body.classList.remove('admin-sidebar-collapsed');
      collapsed = false;
      setDrawerOpen(false, false);
    } else {
      setDrawerOpen(false, false);
      setCollapsed(readStorage(collapseKey) === '1', false);
      sidebar.inert = false;
      sidebar.removeAttribute('aria-hidden');
      if (main) {
        main.inert = false;
        main.removeAttribute('aria-hidden');
      }
    }
  }

  if (typeof media.addEventListener === 'function') media.addEventListener('change', syncViewport);
  else if (typeof media.addListener === 'function') media.addListener(syncViewport);
  syncViewport();
})();
