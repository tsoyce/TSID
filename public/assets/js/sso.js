/**
 * SSO · клиентский виджет для порталов (§2.3). Единая версия для всех.
 *
 * <script src="https://<адрес SSO>/assets/js/sso.js"
 *         data-client-id="cli_…" data-start-url="/auth/sso/start" defer></script>
 * <button data-sso-login>Войти через SSO</button>
 *
 * Клик → fetch(start-url) на СВОЙ бэкенд → тот отвечает {"authorize_url": "…"}
 * (state/nonce/verifier остаются в серверной сессии портала и в JS не утекают).
 *
 * Как открывается вход, решает владелец портала в SSO — «Экран входа» → «Как
 * открывается вход»; скрипт узнаёт это по data-client-id:
 *   • «окном поверх сайта» (v4.73) — окно прямо на странице портала: затемнение
 *     и карточка с экраном входа SSO во фрейме. В конце SSO отдаёт странице
 *     адрес возврата с кодом, и она переходит по нему — колбэк портала
 *     работает как при входе страницей. Где фрейм не справится (вход через
 *     сервисы, ссылка из письма, браузер не пустил cookie, CSP портала не
 *     пускает фрейм), вход продолжается отдельным окном;
 *   • «отдельным окном браузера» (v4.72) — окно 480×680, а страница портала под
 *     ним затемняется карточкой «Окно входа открыто»: окно легко потерять за
 *     основным;
 *   • «всей страницей» — обычный переход на SSO и обратно;
 *   • «как решил портал» (или SSO недоступен) — как было до v4.72: окно на
 *     компьютере, страница на телефоне.
 * На телефоне — всегда страница.
 *
 * Для окна на странице порталу с CSP нужен frame-src <адрес SSO>.
 *
 * data-overlay="off" — отдельное окно без затемнения страницы.
 *
 * Кнопка внутри формы — на старт входа уходят поля этой формы (например,
 * CSRF-токен портала), строкой application/x-www-form-urlencoded.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  var startUrl = (script && script.dataset.startUrl) || '/auth/sso/start';
  var reloadOn = (script && script.dataset.done) || 'reload';
  var clientId = (script && script.dataset.clientId) || '';
  var overlayOn = !(script && script.dataset.overlay === 'off');
  var issuer = '';
  try { issuer = new URL(script.src).origin; } catch (e) { /* без адреса — без настроек */ }

  /* Настройка портала: {display: 'popup'|'window'|'page'|null, texts: {...}}.
     Запрашивается при загрузке страницы, а не по клику: к нажатию она уже
     здесь, и окно открывается без лишней паузы. Минуту держится в
     sessionStorage — переход по страницам портала не спрашивает заново. */
  var ui = { display: null, texts: null, accent: null };
  function loadUi() {
    if (!issuer || !clientId || !window.fetch) return Promise.resolve();
    var key = 'sso-ui:' + clientId;
    try {
      var cached = JSON.parse(sessionStorage.getItem(key) || 'null');
      if (cached && cached.at > Date.now() - 60000) { ui = cached.ui; return Promise.resolve(); }
    } catch (e) { /* хранилище закрыто — спросим сервер */ }
    return fetch(issuer + '/portal/ui?client_id=' + encodeURIComponent(clientId), { credentials: 'omit' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j) return;
        ui = { display: j.display || null, texts: j.texts || null,
          accent: /^#[0-9A-Fa-f]{6}$/.test(j.accent || '') ? j.accent : null };
        try { sessionStorage.setItem(key, JSON.stringify({ at: Date.now(), ui: ui })); } catch (e) { /* не страшно */ }
      })
      .catch(function () { /* SSO недоступен — как было */ });
  }

  function isMobile() {
    return /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent) || innerWidth < 640;
  }

  function openPopup(url) {
    var w = 480, h = 680;
    var left = Math.max(0, (screen.width - w) / 2);
    var top = Math.max(0, (screen.height - h) / 2);
    return window.open(
      url + (url.indexOf('?') === -1 ? '?' : '&') + 'display=popup',
      'sso-login',
      'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top + ',resizable=yes,scrollbars=yes'
    );
  }

  /* Затемнение под окном. Собирается из DOM и свойств style — без атрибутов
     style и без вставки CSS: на сайте со строгой CSP inline-стили запрещены,
     а свойства, выставленные скриптом, проходят. */
  var veil = null;
  function set(el, css) { for (var k in css) el.style[k] = css[k]; return el; }
  function button(text, primary) {
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = text;
    return set(b, {
      font: 'inherit', fontSize: '14px', fontWeight: '600', padding: '10px 16px', borderRadius: '10px',
      cursor: 'pointer', border: primary ? '0' : '1px solid rgba(127,127,127,.35)',
      background: primary ? (ui.accent || '#1D4ED8') : 'transparent', color: primary ? '#fff' : 'inherit'
    });
  }
  function showVeil(popup) {
    if (!overlayOn) return;
    var t = ui.texts || {
      title: 'Вход', text: 'Окно входа открыто. Не видите его — оно могло уйти за это окно.',
      show: 'Показать окно', cancel: 'Отмена'
    };
    var dark = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches;
    veil = set(document.createElement('div'), {
      position: 'fixed', inset: '0', zIndex: '2147483646', display: 'flex', alignItems: 'center', justifyContent: 'center',
      padding: '16px', background: 'rgba(10,12,16,.55)', backdropFilter: 'blur(8px)', webkitBackdropFilter: 'blur(8px)'
    });
    veil.setAttribute('role', 'dialog');
    veil.setAttribute('aria-modal', 'true');
    var card = set(document.createElement('div'), {
      maxWidth: '360px', width: '100%', padding: '24px', borderRadius: '16px', textAlign: 'center',
      fontFamily: 'system-ui, -apple-system, "Segoe UI", sans-serif', lineHeight: '1.45',
      background: dark ? '#1b1d22' : '#fff', color: dark ? '#eceef2' : '#16181d',
      boxShadow: '0 20px 60px rgba(0,0,0,.35)'
    });
    var h = set(document.createElement('div'), { fontSize: '17px', fontWeight: '600', marginBottom: '6px' });
    h.textContent = t.title;
    var p = set(document.createElement('div'), { fontSize: '14px', opacity: '.75', marginBottom: '18px' });
    p.textContent = t.text;
    var row = set(document.createElement('div'), { display: 'flex', gap: '8px', justifyContent: 'center', flexWrap: 'wrap' });
    var show = button(t.show, true);
    var cancel = button(t.cancel, false);
    show.addEventListener('click', function () { try { popup.focus(); } catch (e) { /* окно закрыто */ } });
    cancel.addEventListener('click', function () { try { popup.close(); } catch (e) { /* уже закрыто */ } hideVeil(); });
    row.appendChild(show); row.appendChild(cancel);
    card.appendChild(h); card.appendChild(p); card.appendChild(row);
    veil.appendChild(card);
    document.body.appendChild(veil);
    show.focus();
  }
  function hideVeil() {
    if (veil && veil.parentNode) veil.parentNode.removeChild(veil);
    veil = null;
  }

  /* Отдельное окно браузера; блокировщик не дал — вся вкладка. */
  function openWindow(url, btn, withVeil) {
    var popup = openPopup(url);
    if (!popup) { location.href = url; return; }
    if (withVeil) showVeil(popup);
    var timer = setInterval(function () {
      if (popup.closed) { clearInterval(timer); hideVeil(); btn.disabled = false; }
    }, 400);
  }

  /* Окно на странице портала (v4.73): затемнение, карточка, во фрейме — экран
     входа SSO. Собрано из DOM и свойств style, как и затемнение выше. */
  var modal = null;
  function closeModal() {
    if (!modal) return;
    clearTimeout(modal.slow);
    document.removeEventListener('keydown', modal.esc);
    if (modal.wrap.parentNode) modal.wrap.parentNode.removeChild(modal.wrap);
    document.documentElement.style.overflow = modal.overflow;
    modal.btn.disabled = false;
    modal = null;
  }
  function toWindow() {
    if (!modal) return;
    var url = modal.url, btn = modal.btn;
    closeModal();
    btn.disabled = true;
    openWindow(url, btn, overlayOn);
  }
  /* Сервис, выбранный во фрейме (v4.87), — вся вкладка уходит прямо на него.
     Страницы сервисов во фрейм не пускают, а отдельное окно (так было в 4.86)
     давало лишнее окно: владелец, входя в Портфолио через Telegram, насчитал три.
     Вход всё равно кончается переходом по адресу возврата портала в этой
     вкладке, так что уйти с неё — не потеря. */
  function toService(hint) {
    if (!modal) return;
    var url = modal.url;
    closeModal();
    location.href = url + (url.indexOf('?') === -1 ? '?' : '&') + 'idp_hint=' + hint;
  }
  function openModal(url, btn) {
    closeModal();
    var t = ui.texts || {};
    var dark = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches;
    /* Сайт под окном размыт заметно (v4.86, было 3px) — как у X: окно входа
       главное на экране, а страница под ним только угадывается. */
    var wrap = set(document.createElement('div'), {
      position: 'fixed', inset: '0', zIndex: '2147483646', display: 'flex', flexDirection: 'column',
      alignItems: 'center', justifyContent: 'center', gap: '12px', padding: '16px',
      background: 'rgba(10,12,16,.55)', backdropFilter: 'blur(8px)', webkitBackdropFilter: 'blur(8px)'
    });
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-modal', 'true');
    wrap.setAttribute('aria-label', t.title || 'Вход');
    var card = set(document.createElement('div'), {
      position: 'relative', width: '100%', maxWidth: '440px', height: Math.min(620, innerHeight - 32) + 'px',
      borderRadius: '16px', overflow: 'hidden', background: dark ? '#1b1d22' : '#fff',
      boxShadow: '0 24px 70px rgba(0,0,0,.4)'
    });
    var frame = set(document.createElement('iframe'), { width: '100%', height: '100%', border: '0', display: 'block' });
    frame.title = t.title || 'Вход';
    /* Ключ устройства (passkey) во фрейме с другого адреса — только с разрешения страницы. */
    frame.setAttribute('allow', 'publickey-credentials-get *');
    frame.src = url + (url.indexOf('?') === -1 ? '?' : '&') + 'display=frame';
    var close = set(document.createElement('button'), {
      position: 'absolute', top: '8px', right: '8px', width: '32px', height: '32px', borderRadius: '50%',
      border: '0', cursor: 'pointer', fontSize: '20px', lineHeight: '32px', padding: '0',
      background: 'rgba(127,127,127,.18)', color: dark ? '#eceef2' : '#16181d'
    });
    close.type = 'button';
    close.textContent = '×';
    close.setAttribute('aria-label', t.close || 'Закрыть');
    close.addEventListener('click', closeModal);
    /* Фрейм молчит — предложить отдельное окно: его открывает только щелчок. */
    var slowBox = set(document.createElement('div'), {
      display: 'none', gap: '10px', alignItems: 'center', flexWrap: 'wrap', justifyContent: 'center',
      color: '#fff', fontFamily: 'system-ui, -apple-system, "Segoe UI", sans-serif', fontSize: '14px'
    });
    var slowText = document.createElement('span');
    slowText.textContent = t.slow || 'Не открывается?';
    var slowBtn = button(t.window || 'Открыть отдельным окном', true);
    slowBtn.addEventListener('click', toWindow);
    slowBox.appendChild(slowText);
    slowBox.appendChild(slowBtn);
    card.appendChild(frame);
    card.appendChild(close);
    wrap.appendChild(card);
    wrap.appendChild(slowBox);
    var esc = function (ev) { if (ev.key === 'Escape') closeModal(); };
    document.addEventListener('keydown', esc);
    /* Щелчок мимо окна закрывает его (v4.77) — как на mangalib: раньше окно
       закрывали только крестик и Esc. Только если и нажатие, и отпускание
       пришлись на затемнение: выделение в поле, отпущенное за краем
       карточки, окно не закрывает. */
    var downOnVeil = false;
    wrap.addEventListener('mousedown', function (ev) { downOnVeil = ev.target === wrap; });
    wrap.addEventListener('click', function (ev) {
      if (ev.target === wrap && downOnVeil) closeModal();
      downOnVeil = false;
    });
    modal = {
      wrap: wrap, card: card, frame: frame, url: url, btn: btn, ready: false, esc: esc,
      overflow: document.documentElement.style.overflow,
      slow: setTimeout(function () { if (modal && !modal.ready) slowBox.style.display = 'flex'; }, 8000),
      slowBox: slowBox
    };
    document.documentElement.style.overflow = 'hidden';
    document.body.appendChild(wrap);
    close.focus();
  }

  /* Фрейм говорит с нами только с адреса SSO и только из нашего фрейма. */
  window.addEventListener('message', function (e) {
    if (!modal || e.origin !== issuer || e.source !== modal.frame.contentWindow) return;
    var d = e.data || {};
    if (d.type === 'sso:code' && typeof d.url === 'string' && /^https?:\/\//.test(d.url)) {
      /* Адрес возврата с кодом — колбэк портала; идём по нему всей вкладкой. */
      var url = d.url;
      closeModal();
      location.href = url;
      return;
    }
    if (d.type !== 'sso:frame') return;
    if (d.state === 'ready') {
      modal.ready = true;
      modal.slowBox.style.display = 'none';
    } else if (d.state === 'size' && d.h > 0) {
      /* Окно — по содержимому, от 360px (v4.87; было от 480 и только росло). */
      modal.card.style.height = Math.min(Math.max(d.h, 360), innerHeight - 32) + 'px';
    } else if (d.state === 'window') {
      /* В адрес — только имя сервиса из латинских букв. */
      if (typeof d.social === 'string' && /^[a-z]{2,20}$/.test(d.social)) toService(d.social);
      else toWindow();
    }
  });
  /* CSP портала не пускает фрейм SSO — сразу отдельное окно. */
  document.addEventListener('securitypolicyviolation', function (e) {
    if (modal && !modal.ready && issuer && String(e.blockedURI || '').indexOf(issuer) === 0) toWindow();
  });

  function start(e) {
    e.preventDefault();
    var btn = e.currentTarget;
    btn.disabled = true;
    /* Кнопка внутри формы — её поля уходят со стартом входа (v4.72.2). У портала
       POST бывает под CSRF-защитой, токен лежит в скрытом поле формы, а пустой
       POST получал отказ: Архив упёрся в это первым. Только строки — файл
       из случайно попавшего поля на старт входа отправлять незачем. */
    var body = new URLSearchParams();
    var form = btn.form || btn.closest('form');
    if (form) {
      new FormData(form).forEach(function (v, k) { if (typeof v === 'string') body.append(k, v); });
    }
    fetch(startUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'sso.js' }, body: form ? body : undefined })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.authorize_url) throw new Error('no authorize_url');
        /* Алиас (v4.97): сайт открыт по другому адресу, а портал возвращает
           на основной. Здесь вход не завершится: окно SSO на этой странице
           браузер не покажет, а возврат на основной адрес не найдёт сессию
           входа, оставшуюся тут. Переходим на ту же страницу основного адреса
           и начинаем вход там. Чтобы входить, не уходя с алиаса, порталу —
           список 'aliases' в config.php набора и адрес возврата на алиасе в SSO. */
        var back = '';
        try { back = new URL(new URL(j.authorize_url).searchParams.get('redirect_uri') || '').origin; } catch (err) { /* как было */ }
        if (back && back !== 'null' && back !== location.origin && !e.auto) {
          location.href = back + location.pathname + location.search + '#sso-login';
          return;
        }
        /* Вход, начатый сам после перехода, — без щелчка: отдельное окно
           браузер не откроет, поэтому вместо него страница. */
        if (ui.display === 'page' || isMobile() || (e.auto && ui.display !== 'popup')) { location.href = j.authorize_url; return; }
        if (ui.display === 'popup' && issuer) { openModal(j.authorize_url, btn); return; }
        openWindow(j.authorize_url, btn, ui.display === 'window' && overlayOn);
      })
      .catch(function () { btn.disabled = false; location.href = startUrl; });
  }

  window.addEventListener('message', function (e) {
    /* Источник проверяем первым. Сообщение приходит со страницы-заглушки
       колбэка, а она живёт на самом портале — значит origin обязан совпадать
       с нашим. Без этой строки сообщение принималось от кого угодно: любой
       фрейм или окно, дотянувшееся до этой страницы, могло увести её по
       data-done или гонять перезагрузку. Данных в сообщении нет, но
       навигация по чужой команде — уже слишком много. */
    if (e.origin !== location.origin) return;
    if (!e.data || e.data.type !== 'sso:done') return;
    hideVeil();
    if (reloadOn === 'reload') location.reload();
    else location.href = reloadOn;
  });

  function bind() {
    var found = false;
    document.querySelectorAll('[data-sso-login]').forEach(function (btn) {
      found = true;
      if (btn.dataset.ssoBound) return;
      btn.dataset.ssoBound = '1';
      btn.addEventListener('click', start);
    });
    /* Настройку спрашиваем, только если есть чем входить: вошедшему она не нужна. */
    if (!found) return;
    var ready = loadUi();
    /* Пришли с алиаса за входом (#sso-login) — продолжаем, когда настройка здесь. */
    if (location.hash === '#sso-login') {
      try { history.replaceState(null, '', location.pathname + location.search); } catch (err) { /* останется в адресе */ }
      var first = document.querySelector('[data-sso-login]');
      ready.then(function () { start({ preventDefault: function () {}, currentTarget: first, auto: true }); });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
  else bind();
})();
