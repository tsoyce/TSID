/* SSO · Passkeys (WebAuthn). Кнопка на /login и управление в /profile/security.
   QR-вход с телефона (iCloud/Google) браузер предлагает сам — hybrid transport. */
(function () {
  'use strict';

  function b2buf(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    var pad = s.length % 4;
    if (pad) s += '===='.slice(pad);
    var bin = atob(s), buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
  }
  function buf2b(buf) {
    var bin = '', bytes = new Uint8Array(buf);
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  /* Подпись на языке страницы — словарь js_i18n() (v4.75); русская — запасная. */
  function L(key, fallback) {
    return (window.ssoI18n && window.ssoI18n[key]) || fallback;
  }
  function csrf() {
    var el = document.querySelector('input[name=_csrf]');
    return el ? el.value : '';
  }
  function post(url, fields) {
    var fd = new FormData();
    fd.append('_csrf', csrf());
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' } })
      /* Ответ не JSON — истекшая сессия, страница ошибки (v5.0): говорим
         «не получилось», а не «Unexpected token <» в разборе. */
      .then(function (r) {
        if (!/json/.test(r.headers.get('Content-Type') || '')) throw new Error(L('pkFailedShort', 'Не получилось.'));
        return r.json();
      });
  }
  function say(msg, isError) {
    var out = document.getElementById('passkey-status');
    if (out) { out.textContent = msg; out.className = isError ? 'field-error' : 'hint'; }
  }

  if (!window.PublicKeyCredential) {
    /* Экран входа во фрейме меняется без нового документа (v4.75) — кнопка
       может появиться и после загрузки. */
    var off = function () {
      document.querySelectorAll('[data-passkey-login], [data-passkey-add]').forEach(function (b) {
        b.disabled = true;
        b.title = L('pkUnsupported', 'Браузер не поддерживает passkeys');
      });
    };
    off();
    document.addEventListener('sso:page', off);
    return;
  }

  /* ---- Вход ---- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-passkey-login]');
    if (!btn) return;
    btn.disabled = true;
    say(L('pkWaiting', 'Ждём подтверждение…'));
    post('/passkey/login/options', {})
      .then(function (opt) {
        opt.challenge = b2buf(opt.challenge);
        return navigator.credentials.get({ publicKey: opt });
      })
      .then(function (cred) {
        var remember = document.querySelector('input[name=remember]');
        return post('/passkey/login', {
          id: cred.id,
          clientDataJSON: buf2b(cred.response.clientDataJSON),
          authenticatorData: buf2b(cred.response.authenticatorData),
          signature: buf2b(cred.response.signature),
          remember: remember && remember.checked ? '1' : ''
        });
      })
      .then(function (j) {
        /* Во фрейме на странице портала — без нового документа (v4.75). */
        if (j.ok && j.redirect) { (window.ssoGo || function (u) { location.href = u; })(j.redirect); return; }
        say(j.error || L('pkFailed', 'Не получилось — попробуйте ещё раз.'), true);
        btn.disabled = false;
      })
      .catch(function (err) {
        say(err.name === 'NotAllowedError' ? L('pkCancelled', 'Отменено или время вышло.') : L('pkError', 'Ошибка: :e').replace(':e', err.message), true);
        btn.disabled = false;
      });
  });

  /* ---- Добавление ключа ---- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-passkey-add]');
    if (!btn) return;
    btn.disabled = true;
    say(L('pkFollow', 'Следуйте подсказкам браузера…'));
    post('/passkey/register/options', {})
      .then(function (opt) {
        opt.challenge = b2buf(opt.challenge);
        opt.user.id = b2buf(opt.user.id);
        opt.excludeCredentials = (opt.excludeCredentials || []).map(function (c) {
          return { type: c.type, id: b2buf(c.id) };
        });
        return navigator.credentials.create({ publicKey: opt });
      })
      .then(function (cred) {
        var label = prompt(L('pkName', 'Как назвать этот ключ?'), L('pkDefaultName', 'Мой passkey')) || '';
        var transports = '';
        try { transports = (cred.response.getTransports() || []).join(','); } catch (err) {}
        return post('/passkey/register', {
          clientDataJSON: buf2b(cred.response.clientDataJSON),
          attestationObject: buf2b(cred.response.attestationObject),
          label: label,
          transports: transports
        });
      })
      .then(function (j) {
        if (j.ok) { location.reload(); return; }
        say(j.error || L('pkFailedShort', 'Не получилось.'), true);
        btn.disabled = false;
      })
      .catch(function (err) {
        say(err.name === 'NotAllowedError' ? L('pkCancelled', 'Отменено или время вышло.') : L('pkError', 'Ошибка: :e').replace(':e', err.message), true);
        btn.disabled = false;
      });
  });
})();
