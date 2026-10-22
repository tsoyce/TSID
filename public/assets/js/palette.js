/* SSO · командная палитра (Ctrl+K / Cmd+K).
   Одна точка входа ко всему: разделы, пользователи, порталы, быстрые действия.
   Данные — GET /search?q=, права соблюдаются на сервере. */
(function () {
  'use strict';

  var root = null, input = null, list = null, items = [], cursor = 0, timer = null, lastQuery = null;

  function build() {
    root = document.createElement('div');
    root.className = 'palette-backdrop';
    root.innerHTML =
      '<div class="palette" role="dialog" aria-modal="true" aria-label="Поиск по системе">' +
      '  <div class="palette-head">' +
      '    <svg class="icon icon-muted" width="18" height="18" aria-hidden="true"><use href="/assets/icons.svg#i-search"></use></svg>' +
      '    <input class="palette-input" type="text" placeholder="Раздел, пользователь, портал или действие…" autocomplete="off" spellcheck="false">' +
      '    <kbd class="palette-kbd">Esc</kbd>' +
      '  </div>' +
      '  <div class="palette-list" role="listbox"></div>' +
      '</div>';
    document.body.appendChild(root);
    input = root.querySelector('.palette-input');
    list = root.querySelector('.palette-list');

    root.addEventListener('click', function (e) { if (e.target === root) close(); });
    input.addEventListener('input', function () { schedule(input.value.trim()); });
    input.addEventListener('keydown', onKey);
  }

  function open() {
    if (!root) build();
    root.classList.add('open');
    input.value = '';
    input.focus();
    lastQuery = null;
    schedule('');
  }

  function close() {
    if (root) root.classList.remove('open');
  }

  function isOpen() { return root && root.classList.contains('open'); }

  function schedule(q) {
    clearTimeout(timer);
    timer = setTimeout(function () { fetchResults(q); }, q === '' ? 0 : 120);
  }

  function fetchResults(q) {
    if (q === lastQuery) return;
    lastQuery = q;
    if (!list.querySelector('.palette-item')) {
      list.innerHTML = '<div style="padding:10px">' +
        '<div class="skeleton skeleton-line w-60"></div><div class="skeleton skeleton-line"></div>' +
        '<div class="skeleton skeleton-line w-40"></div></div>';
    }
    fetch('/search?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (j) { render(j.groups || []); })
      .catch(function () { list.innerHTML = '<div class="palette-empty">Не удалось получить результаты</div>'; });
  }

  function render(groups) {
    items = [];
    if (!groups.length) {
      list.innerHTML = '<div class="palette-empty">Ничего не нашлось</div>';
      return;
    }
    var html = '';
    groups.forEach(function (g) {
      html += '<div class="palette-group">' + esc(g.title) + '</div>';
      g.items.forEach(function (it) {
        var idx = items.length;
        items.push(it);
        html += '<a class="palette-item" role="option" data-idx="' + idx + '" href="' + esc(it.url) + '">' +
          '<svg class="icon icon-muted" width="18" height="18" aria-hidden="true"><use href="/assets/icons.svg#i-' + esc(it.icon || 'chevron-right') + '"></use></svg>' +
          '<span class="palette-label">' + esc(it.label) + '</span>' +
          (it.hint ? '<span class="palette-hint">' + esc(it.hint) + '</span>' : '') +
          '</a>';
      });
    });
    list.innerHTML = html;
    cursor = 0;
    highlight();
  }

  function highlight() {
    var els = list.querySelectorAll('.palette-item');
    els.forEach(function (el, i) {
      el.classList.toggle('is-cursor', i === cursor);
      if (i === cursor) el.scrollIntoView({ block: 'nearest' });
    });
  }

  function onKey(e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); cursor = Math.min(cursor + 1, items.length - 1); highlight(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); cursor = Math.max(cursor - 1, 0); highlight(); }
    else if (e.key === 'Enter') {
      e.preventDefault();
      var el = list.querySelector('.palette-item.is-cursor');
      if (el) location.href = el.getAttribute('href');
    } else if (e.key === 'Escape') { e.preventDefault(); close(); }
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      isOpen() ? close() : open();
    }
  });

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-palette-open]')) { e.preventDefault(); open(); }
  });
})();
