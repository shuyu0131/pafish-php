(function () {
  'use strict';
  var data = window.pafishI18nData || {};
  var messages = data.messages || {};
  var own = Object.prototype.hasOwnProperty;
  window.pafishI18n = {
    locale: data.locale || 'zh-CN',
    escape: function (value) {
      return String(value).replace(/[&<>"']/g, function (character) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character];
      });
    }
  };
  window.pafishT = function (key, parameters) {
    var message = own.call(messages, key) && typeof messages[key] === 'string' ? messages[key] : key;
    return message.replace(/\{([A-Za-z0-9_]+)\}/g, function (placeholder, name) {
      return parameters && own.call(parameters, name) ? String(parameters[name]) : placeholder;
    });
  };
})();
