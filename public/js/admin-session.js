(function () {
  'use strict';

  var config = window.pafishSessionConfig;
  if (!config || !window.fetch) return;
  var nativeFetch = window.fetch.bind(window);
  var notice = null;
  var noticeCode = '';
  var lastCheck = Date.now();
  var pending = null;
  var translate = window.pafishT;

  // Only this installation's browser admin/API requests participate.
  function routePath(url) {
    var base = new URL(config.adminUrl, location.href);
    if (url.origin !== base.origin) return '';
    var route = url.searchParams.get('p');
    if (base.searchParams.has('p')) {
      return url.pathname === base.pathname && route ? '/' + route.replace(/^\/+/, '') : '';
    }
    var prefix = base.pathname.replace(/\/admin\/?$/, '');
    return url.pathname.indexOf(prefix + '/') === 0 ? url.pathname.slice(prefix.length) : '';
  }

  function renderNotice() {
    if (!noticeCode || !document.body) return;
    if (!notice) {
      notice = document.createElement('section');
      notice.className = 'admin-session-notice';
      notice.setAttribute('role', 'alert');
      notice.setAttribute('aria-atomic', 'true');
      var copy = document.createElement('div');
      var title = document.createElement('strong');
      title.className = 'admin-session-title';
      var message = document.createElement('p');
      copy.appendChild(title);
      copy.appendChild(message);
      var action = document.createElement('a');
      action.className = 'btn btn-outline btn-sm';
      action.target = '_blank';
      action.rel = 'noopener';
      // Never use a URL supplied by an error response.
      var login = new URL(config.loginUrl, location.href);
      login.searchParams.set('from', config.adminUrl);
      action.href = login.href;
      action.textContent = translate('js.session_sign_in_new_tab');
      notice.appendChild(copy);
      notice.appendChild(action);
      (document.querySelector('.admin-content') || document.body).prepend(notice);
    }
    var expired = noticeCode === 'AUTH_REQUIRED';
    notice.querySelector('strong').textContent = translate(expired ? 'js.session_expired_title' : 'js.session_page_expired_title');
    notice.querySelector('p').textContent = translate(expired ? 'js.session_expired_message' : 'js.session_page_expired_message');
    notice.querySelector('a').hidden = !expired;
  }

  window.pafishSessionError = function (status, data) {
    if (!data || !((status === 401 && data.code === 'AUTH_REQUIRED')
      || (status === 419 && data.code === 'CSRF_EXPIRED'))) return false;
    if (noticeCode !== data.code) {
      noticeCode = data.code;
      renderNotice();
    }
    return true;
  };

  window.fetch = function (input, options) {
    var request;
    try { request = new Request(input, options); }
    catch (error) { return Promise.reject(error); }
    var path = routePath(new URL(request.url));
    var scoped = /^(?:\/admin(?:\/|$)|\/api\/)/.test(path)
      && request.credentials !== 'omit' && request.mode !== 'no-cors'
      && !request.headers.has('Authorization');
    if (!scoped) return nativeFetch(request);
    if (noticeCode && !/^(GET|HEAD|OPTIONS)$/i.test(request.method)) {
      var expired = noticeCode === 'AUTH_REQUIRED';
      return Promise.resolve(new Response(JSON.stringify({
        code: noticeCode,
        error: translate(expired ? 'js.session_expired_message' : 'js.session_page_expired_message')
      }), {status: expired ? 401 : 419, headers: {'Content-Type': 'application/json'}}));
    }
    if (/^\/admin(?:\/|$)/.test(path)) {
      var headers = new Headers(request.headers);
      headers.set('X-Requested-With', 'XMLHttpRequest');
      headers.set('Accept', 'application/json');
      request = new Request(request, {headers: headers});
    }
    return nativeFetch(request).then(function (response) {
      if ((response.status !== 401 && response.status !== 419)
        || !/application\/json/i.test(response.headers.get('Content-Type') || '')) return response;
      // Observe a clone; retain the original body for the caller.
      return response.clone().json().then(function (data) {
        window.pafishSessionError(response.status, data);
        return response;
      }, function () { return response; });
    });
  };

  function checkSession() {
    if (document.visibilityState === 'hidden' || pending || Date.now() - lastCheck < 60000) return;
    lastCheck = Date.now();
    pending = nativeFetch(config.statusUrl, {
      credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}
    }).then(function (response) {
      if (!/application\/json/i.test(response.headers.get('Content-Type') || '')) return;
      return response.json().then(function (data) {
        if (window.pafishSessionError(response.status, data)) return;
        if (response.ok && data.ok && data.context && data.context !== config.context) {
          window.pafishSessionError(419, {code: 'CSRF_EXPIRED'});
        }
      });
    }).catch(function () {
      // A network failure is not evidence of an expired login.
    }).then(function () { pending = null; });
  }

  // Keep editors intact. Never navigate or resubmit a write automatically.
  document.addEventListener('submit', function (event) {
    if (noticeCode && event.target.method && event.target.method.toLowerCase() === 'post') {
      event.preventDefault();
      event.stopImmediatePropagation();
      renderNotice();
      notice.scrollIntoView({block: 'nearest'});
    }
  }, true);
  document.addEventListener('DOMContentLoaded', renderNotice);
  window.addEventListener('focus', checkSession);
  window.addEventListener('pageshow', checkSession);
  document.addEventListener('visibilitychange', checkSession);
  window.setInterval(checkSession, 300000);
})();
