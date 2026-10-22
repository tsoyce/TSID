/**
 * Меню виджета {{APP_NAME}}: открыть, закрыть, не мешать остальной странице.
 *
 * Отдельным файлом, а не атрибутом onclick: на сайте со строгой политикой
 * безопасности inline-обработчик не выполняется, и меню не открывается —
 * молча, без ошибки в консоли. Внешний файл проходит по обычному script-src
 * 'self', то есть работает даже там, где inline запрещён целиком.
 *
 * Ничего не требует и ничего не экспортирует: один слушатель на документ.
 */
(function () {
  'use strict';

  function closeAll(except) {
    document.querySelectorAll('[data-sso-widget]').forEach(function (w) {
      if (w === except) return;
      var menu = w.querySelector('.sso-widget-menu');
      var btn = w.querySelector('[data-sso-menu]');
      if (menu) menu.hidden = true;
      if (btn) btn.setAttribute('aria-expanded', 'false');
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-sso-menu]');
    if (!btn) { closeAll(null); return; }

    var widget = btn.closest('[data-sso-widget]');
    var menu = widget && widget.querySelector('.sso-widget-menu');
    if (!menu) return;

    closeAll(widget);
    var open = menu.hidden;
    menu.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  /* Клавиатура: меню обязано закрываться Escape, иначе оно ловушка для того,
     кто не пользуется мышью. */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAll(null);
  });
})();
