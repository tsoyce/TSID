/* ============================================================================
   Кнопка «Войти через {{APP_NAME}}» — для встраивания на вашем сайте.

   Файл самодостаточный: ни одной переменной, ни одного шрифта со стороны, ни
   одной зависимости. Значения подставлены под ваш экземпляр SSO при сборке
   набора, поэтому кнопка выглядит одинаково у всех порталов группы, но не
   тянет за собой наш дизайн в ваш интерфейс.

   Подключение — одна строка в <head> и три строки разметки:

     <link rel="stylesheet" href="/sso/sso-button.css">

     <a class="sso-btn" href="/sso/login.php" data-turbo="false" hx-boost="false">
       <span class="sso-btn-mark">{{MARK}}</span>
       Войти через {{APP_NAME}}
     </a>

   data-turbo и hx-boost — не украшение. На сайте, «ускоренном» Turbo или htmx,
   переход по ссылке уходит фоновым запросом, ответ 302 на SSO упирается в CORS,
   и кнопка молчит: ни перехода, ни ошибки. Эти атрибуты возвращают обычный
   переход; на сайте без ускорителей они ничего не делают.

   На кнопке стоит имя установки, а не название технологии: человек знает
   «{{APP_NAME}}», а не то, на чём это сделано. Если юристам нужна отсылка к
   провайдеру — она уходит мелкой строкой ПОД кнопку, а не на неё.
   ========================================================================= */

.sso-btn {
  display: inline-flex; align-items: center; gap: 10px; box-sizing: border-box;
  min-height: 44px;                 /* минимальная зона пальца */
  padding: 10px 18px 10px 14px;
  border-radius: 10px; border: 1px solid transparent;
  cursor: pointer; text-decoration: none;
  font: 500 15px/1.2 system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
  white-space: nowrap;
  background: {{INK}}; color: {{ON_INK}};
  transition: filter .12s cubic-bezier(.2, .8, .2, 1), box-shadow .12s cubic-bezier(.2, .8, .2, 1);
}
.sso-btn:hover { filter: brightness(1.08); }
.sso-btn:focus-visible { outline: none; box-shadow: 0 0 0 3px {{INK_FOCUS}}; }
.sso-btn:active { filter: brightness(.96); }

/* Знак: квадрат со срезанным углом, контуром в цвет надписи. Не картинка —
   значит не грузится, не мылится на ретине и не ломается, если ваш сайт
   отдаётся с другого домена. */
.sso-btn-mark {
  display: inline-flex; align-items: center; justify-content: center; flex: none;
  width: 22px; height: 22px; border-radius: 6px;
  box-shadow: inset 0 0 0 1.5px currentColor;
  font: 600 11px/1 ui-monospace, 'Cascadia Mono', Consolas, monospace;
  letter-spacing: -.02em;
}

/* Контурный — когда у вас свой яркий фирменный цвет и наш акцент с ним спорит. */
.sso-btn-outline { background: #FFFFFF; color: #1C1917; border-color: #D6D3D1; }
.sso-btn-outline:hover { border-color: #78716C; filter: none; background: #FAFAF9; }

/* Тёмный — универсальный: работает на любом фоне и не конфликтует ни с чем. */
.sso-btn-dark { background: #1C1917; color: #FFFFFF; }
.sso-btn-dark:hover { filter: brightness(1.25); }

/* Размеры. 44px по умолчанию — не эстетика, а нижняя граница зоны пальца. */
.sso-btn-s { min-height: 36px; padding: 8px 14px 8px 10px; font-size: 14px; border-radius: 8px; gap: 8px; }
.sso-btn-s .sso-btn-mark { width: 18px; height: 18px; border-radius: 5px; font-size: 10px; }
.sso-btn-l { min-height: 52px; padding: 14px 22px 14px 18px; font-size: 16px; border-radius: 12px; }
.sso-btn-l .sso-btn-mark { width: 26px; height: 26px; border-radius: 7px; font-size: 13px; }
.sso-btn-block { display: flex; width: 100%; justify-content: center; }

/* Повторный вход: человек уже входил с этого устройства. Назвать его по имени
   дешевле любой подписи — нажатие становится подтверждением, а не решением.
   Имя подставляйте только если оно у вас уже есть; запрашивать его ради
   кнопки не нужно. */
.sso-btn-known { padding: 8px 18px 8px 8px; gap: 10px; text-align: left; white-space: normal; }
.sso-btn-known .sso-known-in { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.sso-btn-known .sso-known-in b { font-weight: 600; font-size: 14px; }
.sso-btn-known .sso-known-in span { font-size: 12px; opacity: .75; }

/* Подпись под кнопкой — там, где отсылка к провайдеру нужна по договору. */
.sso-btn-note { margin-top: 6px; font: 400 12px/1.4 system-ui, sans-serif; color: #78716C; }

/* Тёмная тема сайта: контурный вариант обязан переехать вместе с ней, иначе
   белая кнопка выжигает тёмную страницу. Акцентный и тёмный варианты
   переопределять не нужно — они и так самодостаточны. */
@media (prefers-color-scheme: dark) {
  .sso-btn-outline { background: #292524; color: #FAFAF9; border-color: #44403C; }
  .sso-btn-outline:hover { border-color: #78716C; background: #1C1917; }
  .sso-btn-note { color: #A8A29E; }
}

/* ── Виджет: аватар, имя, меню ──────────────────────────────────────────────
   Оформление здесь, а не в атрибутах style у разметки: на сайте со строгой
   политикой безопасности inline-стили и обработчики не выполняются, и виджет
   разваливался молча — меню не открывалось, ошибки в консоли не было. */
.sso-widget { position: relative; display: inline-block; font: 400 14px/1.4 system-ui, sans-serif; }
.sso-widget-btn {
  display: inline-flex; align-items: center; gap: 8px; padding: 4px;
  background: none; border: 0; cursor: pointer; font: inherit; color: inherit;
  border-radius: 8px;
}
.sso-widget-btn:hover { background: rgba(0, 0, 0, .05); }
.sso-widget-btn:focus-visible { outline: 2px solid {{INK}}; outline-offset: 2px; }
.sso-widget-pic { width: 28px; height: 28px; border-radius: 50%; flex: none; object-fit: cover; }
.sso-widget-initial {
  display: inline-flex; align-items: center; justify-content: center;
  background: {{INK}}; color: {{ON_INK}}; font-weight: 600;
}
.sso-widget-menu {
  position: absolute; right: 0; top: 100%; margin-top: 6px; min-width: 190px; z-index: 50;
  background: #FFFFFF; border: 1px solid #E7E5E4; border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, .12); padding: 4px; overflow: hidden;
}
/* hidden у элемента с display:block по умолчанию не срабатывает — задаём явно. */
.sso-widget-menu[hidden] { display: none; }
.sso-widget-menu a {
  display: block; padding: 9px 12px; color: #1C1917; text-decoration: none; border-radius: 6px;
}
.sso-widget-menu a:hover { background: #F5F5F4; }
.sso-widget-menu .sso-widget-danger { color: #B91C1C; }

@media (prefers-color-scheme: dark) {
  .sso-widget-btn:hover { background: rgba(255, 255, 255, .08); }
  .sso-widget-menu { background: #292524; border-color: #44403C; }
  .sso-widget-menu a { color: #FAFAF9; }
  .sso-widget-menu a:hover { background: #1C1917; }
  .sso-widget-menu .sso-widget-danger { color: #FCA5A5; }
}
