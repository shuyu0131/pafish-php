(function () {
  'use strict';
  document.querySelectorAll('[data-admin-sort]').forEach(function (body) {
    var busy = false, drag = null, frame = 0, statusTimer = 0;
    var status = document.createElement('p');
    status.className = 'admin-sort-status admin-muted';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.hidden = true;
    body.closest('.admin-table-wrap').after(status);

    function rows() { return Array.from(body.children).filter(function (r) { return r.dataset.id; }); }
    function parent(row) { return row.dataset.parent || ''; }
    function siblings(row) { return rows().filter(function (r) { return parent(r) === parent(row); }); }
    function ids(list) { return list.map(function (r) { return Number(r.dataset.id); }); }
    function announce(message, error) {
      clearTimeout(statusTimer);
      status.hidden = false;
      status.textContent = message;
      status.classList.toggle('admin-sort-error', !!error);
      if (message === '排序已保存') statusTimer = setTimeout(function () { status.hidden = true; }, 2500);
    }
    function boundaries() {
      rows().forEach(function (row) {
        var list = siblings(row), index = list.indexOf(row);
        var up = row.querySelector('[data-move="up"], [data-dir="up"]');
        var down = row.querySelector('[data-move="down"], [data-dir="down"]');
        if (up) up.disabled = index === 0;
        if (down) down.disabled = index === list.length - 1;
      });
    }
    // Render from the parent map so a category and all its descendants move together.
    // Navigation/link rows keep the same adjacent inline editor, including unsaved input.
    function render(order) {
      var focused = body.contains(document.activeElement) ? document.activeElement : null;
      var editors = new Map();
      rows().forEach(function (r) {
        var next = r.nextElementSibling;
        if (next && next.classList.contains('admin-list-edit-row')) editors.set(r, next);
      });
      var fragment = document.createDocumentFragment();
      function append(row) {
        fragment.append(row);
        if (editors.has(row)) fragment.append(editors.get(row));
        order.filter(function (r) { return parent(r) === row.dataset.id; }).forEach(append);
      }
      order.filter(function (r) { return parent(r) === ''; }).forEach(append);
      body.append(fragment);
      boundaries();
      if (focused) {
        var target = focused.disabled
          ? focused.closest('tr').querySelector('[data-move]:not(:disabled), [data-move-cat]:not(:disabled)')
          : focused;
        if (target) target.focus({ preventScroll: true });
      }
    }
    async function save(row, ordered) {
      var previous = rows(), expected = ids(siblings(row));
      if (JSON.stringify(ids(ordered)) === JSON.stringify(expected)) return;
      var remaining = previous.filter(function (r) { return parent(r) !== parent(row); });
      render(remaining.concat(ordered));
      busy = true;
      body.setAttribute('aria-busy', 'true');
      announce('正在保存排序…');
      var data = new FormData();
      data.set('_csrf', body.dataset.sortCsrf);
      data.set('ids', JSON.stringify(ids(ordered)));
      data.set('expected', JSON.stringify(expected));
      try {
        var response = await fetch(body.dataset.adminSort, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: data
        });
        var result = await response.json();
        if (!response.ok || !result.ok) throw Error(result.error || '排序保存失败，请重试');
        announce('排序已保存');
      } catch (error) {
        render(previous);
        announce(error.message || '排序保存失败，请重试', true);
      } finally {
        busy = false;
        body.removeAttribute('aria-busy');
      }
    }
    // Keep the existing buttons as the keyboard and click alternative to dragging.
    body.addEventListener('click', function (event) {
      if (busy || drag) { event.preventDefault(); event.stopImmediatePropagation(); return; }
      var button = event.target.closest('[data-move], [data-move-cat]');
      if (!button) return;
      event.preventDefault(); event.stopImmediatePropagation();
      if (button.disabled) return;
      var row = button.closest('tr'), list = siblings(row), index = list.indexOf(row);
      var next = index + ((button.dataset.move || button.dataset.dir) === 'up' ? -1 : 1);
      if (list[next]) {
        var other = list[next]; list[next] = row; list[index] = other;
        save(row, list);
      }
    }, true);

    function clearTarget() {
      body.querySelectorAll('.admin-sort-before, .admin-sort-after').forEach(function (r) {
        r.classList.remove('admin-sort-before', 'admin-sort-after');
      });
      if (drag) drag.target = null;
    }
    function targetAtPointer() {
      clearTarget();
      if (!drag || !drag.started) return;
      var hit = document.elementFromPoint(drag.x, drag.y);
      var target = hit && hit.closest('tr');
      if (target && target.classList.contains('admin-list-edit-row')) target = target.previousElementSibling;
      if (!target || !target.dataset.id || target.parentElement !== body) {
        var bounds = body.getBoundingClientRect(), group = siblings(drag.row);
        if (drag.x < bounds.left || drag.x > bounds.right) return;
        if (drag.y < bounds.top) target = group[0];
        else if (drag.y > bounds.bottom) target = group[group.length - 1];
        else return;
      }
      var all = rows();
      // A parent's descendants belong to its drag block, never a new parent.
      while (target && parent(target) !== parent(drag.row)) {
        var id = parent(target);
        target = all.find(function (r) { return r.dataset.id === id; });
      }
      if (!target || target === drag.row) return;
      var rect = target.getBoundingClientRect();
      drag.target = target;
      drag.after = drag.y > rect.top + rect.height / 2;
      target.classList.add(drag.after ? 'admin-sort-after' : 'admin-sort-before');
    }
    function autoScroll() {
      if (!drag) return;
      if (drag.started) {
        var delta = drag.y < 70 ? -12 : drag.y > innerHeight - 70 ? 12 : 0;
        if (delta) { window.scrollBy(0, delta); targetAtPointer(); }
      }
      frame = requestAnimationFrame(autoScroll);
    }
    function finish(cancel) {
      if (!drag) return;
      var state = drag;
      drag = null;
      cancelAnimationFrame(frame);
      clearTarget();
      state.row.classList.remove('admin-sort-dragging');
      document.documentElement.classList.remove('admin-is-sorting');
      if (state.handle.hasPointerCapture(state.pointer)) state.handle.releasePointerCapture(state.pointer);
      if (!cancel && state.started && state.target) {
        var list = siblings(state.row).filter(function (r) { return r !== state.row; });
        list.splice(list.indexOf(state.target) + (state.after ? 1 : 0), 0, state.row);
        save(state.row, list);
      }
    }
    body.addEventListener('pointerdown', function (event) {
      var handle = event.target.closest('.admin-sort-handle');
      if (!handle || busy || drag || event.button !== 0 || !event.isPrimary) return;
      var row = handle.closest('tr');
      if (siblings(row).length < 2) return;
      event.preventDefault();
      handle.focus({ preventScroll: true });
      handle.setPointerCapture(event.pointerId);
      drag = { row: row, handle: handle, pointer: event.pointerId, x: event.clientX, y: event.clientY,
        startX: event.clientX, startY: event.clientY, started: false, target: null };
      frame = requestAnimationFrame(autoScroll);
    });
    body.addEventListener('pointermove', function (event) {
      if (!drag || event.pointerId !== drag.pointer) return;
      drag.x = event.clientX; drag.y = event.clientY;
      if (!drag.started && Math.hypot(drag.x - drag.startX, drag.y - drag.startY) > 5) {
        drag.started = true;
        drag.row.classList.add('admin-sort-dragging');
        document.documentElement.classList.add('admin-is-sorting');
      }
      targetAtPointer();
    });
    body.addEventListener('pointerup', function (event) { if (drag && event.pointerId === drag.pointer) finish(false); });
    body.addEventListener('pointercancel', function () { finish(true); });
    body.addEventListener('lostpointercapture', function () { finish(true); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && drag) { event.preventDefault(); finish(true); } });
    window.addEventListener('blur', function () { finish(true); });
    boundaries();
  });
})();
