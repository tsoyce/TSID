/* SSO · точечный интерактив (§0: без SPA). Всё через data-атрибуты, CSP-safe. */
(function () {
  'use strict';

  /* Подпись на языке страницы: словарь кладёт js_i18n() в каждой раскладке.
     Русская строка — запасная, на случай страницы без словаря. */
  function L(key, fallback) {
    return (window.ssoI18n && window.ssoI18n[key]) || fallback;
  }

  /* ---------- Тема: prefers-color-scheme по умолчанию, переключатель в футере ----------
     Доступ к localStorage обёрнут: в браузере, где хранилище запрещено —
     приватный режим с жёсткими настройками, встроенный webview, страница,
     открытая как data: — обращение к нему БРОСАЕТ. Исключение на первой строке
     файла убивало весь скрипт целиком: переставали работать меню, модалки,
     вкладки, копирование, маски — всё. Тема — мелочь, ради которой нельзя
     терять интерфейс, поэтому её отсутствие просто означает «авто». */
  function themeGet() {
    /* Не выбирал сам — тема по умолчанию из оформления (v4.80), иначе «как у устройства». */
    var fallback = document.documentElement.getAttribute('data-theme-default') || 'auto';
    /* Ключ «sso-theme» с 4.89; прежний «tsoy-theme» читается, пока человек не
       выберет тему заново, — иначе выбор у всех сбросился бы при обновлении. */
    try { return localStorage.getItem('sso-theme') || localStorage.getItem('tsoy-theme') || fallback; } catch (e) { return fallback; }
  }
  function themeSet(v) {
    try { localStorage.setItem('sso-theme', v); localStorage.removeItem('tsoy-theme'); } catch (e) { /* не сохранится — переживём */ }
  }

  function applyTheme(theme) {
    if (theme === 'dark' || (theme === 'auto' && matchMedia('(prefers-color-scheme: dark)').matches)) {
      document.documentElement.dataset.theme = 'dark';
    } else {
      delete document.documentElement.dataset.theme;
    }
  }
  /* Предпросмотр экрана входа показывает ту схему, которую выбрали в
     редакторе, а не тему администратора (v4.74). Раньше app.js во фрейме
     перекрашивал «светлый» предпросмотр в тёмный, пока редактор не подменит
     документ, — и при смене системной темы перекрашивал снова. */
  var isPreview = !!document.querySelector('meta[name="look-why"]');
  if (!isPreview) {
    applyTheme(themeGet());
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
      applyTheme(themeGet());
    });
  }

  /* Кнопка темы — иконка, а не подпись: подписи «Тема: авто» съедали пол-подвала.
     Текущее состояние показывает сама иконка, название уходит в подсказку. */
  function paintThemeIcon(btn, mode) {
    var name = mode === 'dark' ? 'moon' : (mode === 'light' ? 'sun' : 'theme-auto');
    var label = { auto: L('themeAuto', 'Тема: авто'), dark: L('themeDark', 'Тема: тёмная'),
      light: L('themeLight', 'Тема: светлая') }[mode];
    var use = btn.querySelector('use');
    if (use) use.setAttribute('href', use.getAttribute('href').replace(/#i-.*$/, '#i-' + name));
    btn.setAttribute('title', label);
    btn.setAttribute('aria-label', label);
  }
  document.addEventListener('DOMContentLoaded', function () {
    var b = document.querySelector('[data-theme-toggle]');
    if (b) paintThemeIcon(b, themeGet());
  });

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-theme-toggle]');
    if (!t) return;
    var current = themeGet();
    var next = current === 'auto' ? 'dark' : (current === 'dark' ? 'light' : 'auto');
    themeSet(next);
    applyTheme(next);
    paintThemeIcon(t, next);
  });

  /* Мобильный бургер удалён в v4.3.8: планку `.topbar` в макете заменил
     островок, а разделы, которые бургер разворачивал, живут в нижнем доке.
     Обработчик молча ничего не делал — `.topbar nav` в разметке больше нет. */

  /* ---------- Dropdown-меню ---------- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-menu-toggle]');
    document.querySelectorAll('.menu.open').forEach(function (m) {
      if (!btn || !m.contains(btn)) {
        m.classList.remove('open');
        var t = m.querySelector('[data-menu-toggle]');
        if (t) t.setAttribute('aria-expanded', 'false');
      }
    });
    /* aria-expanded должен ходить за классом: статичный атрибут врёт
       скринридеру хуже, чем его отсутствие */
    if (btn) {
      var open = btn.closest('.menu').classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.menu.open').forEach(function (m) {
        m.classList.remove('open');
        var t = m.querySelector('[data-menu-toggle]');
        if (t) t.setAttribute('aria-expanded', 'false');
      });
      document.querySelectorAll('.modal-backdrop.open, .pass-sheet.open').forEach(function (m) { m.classList.remove('open'); });
      document.querySelectorAll('.drawer-backdrop.open').forEach(function (m) { m.classList.remove('open'); });
    }
  });

  /* ---------- Оверлеи: блокировка прокрутки и ловушка фокуса ----------
     Сделано наблюдателем, а не правкой каждой точки открытия: модалка, drawer и
     палитра открываются из разных файлов, и любая новая точка входа получит то
     же поведение без единой строки на её стороне.
     Без этого фон прокручивался под открытой модалкой, а Tab уводил фокус на
     страницу под оверлеем — с клавиатуры оттуда было не выбраться. */
  (function () {
    var SEL = '.modal-backdrop, .drawer-backdrop, .palette-backdrop, .pass-sheet';
    var OPEN_SEL = '.modal-backdrop.open, .drawer-backdrop.open, .palette-backdrop.open, .pass-sheet.open';
    var FOCUSABLE = 'a[href], button:not(:disabled), input:not(:disabled):not([type=hidden]),'
      + ' select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])';
    var openCount = 0;

    /* Блокировка вешается на <html>, а не на <body>: полосой прокрутки владеет
       корневой элемент, и только он держит зарезервированный жёлоб
       (scrollbar-gutter: stable). Поэтому компенсировать padding'ом нечего —
       ширина контента при открытии оверлея не меняется вообще. */
    function lock() {
      if (openCount++ > 0) return;
      document.documentElement.classList.add('is-scroll-locked');
    }
    function unlock() {
      openCount = Math.max(0, openCount - 1);
      if (openCount > 0) return;
      document.documentElement.classList.remove('is-scroll-locked');
    }
    function focusables(el) {
      return [].slice.call(el.querySelectorAll(FOCUSABLE)).filter(function (n) {
        return n.offsetWidth > 0 || n.offsetHeight > 0 || n === document.activeElement;
      });
    }
    function opened(el) {
      lock();
      el._ssoReturn = document.activeElement;
      var list = focusables(el);
      if (list.length) setTimeout(function () { list[0].focus(); }, 0);
    }
    function closed(el) {
      unlock();
      var back = el._ssoReturn;
      el._ssoReturn = null;
      if (back && document.contains(back)) back.focus();
    }

    /* Tab не должен выходить за пределы верхнего оверлея */
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') return;
      var all = document.querySelectorAll(OPEN_SEL);
      if (!all.length) return;
      var top = all[all.length - 1];
      var list = focusables(top);
      if (!list.length) return;
      var first = list[0], last = list[list.length - 1];
      if (!top.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
      else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    function sync(el) {
      var isOpen = el.classList.contains('open');
      if (isOpen === !!el._ssoOpen) return;
      el._ssoOpen = isOpen;
      isOpen ? opened(el) : closed(el);
    }
    new MutationObserver(function (records) {
      records.forEach(function (r) {
        if (r.type === 'attributes' && r.target.matches && r.target.matches(SEL)) sync(r.target);
        if (r.type === 'childList') {
          [].forEach.call(r.addedNodes, function (n) {
            if (n.nodeType !== 1) return;
            if (n.matches && n.matches(SEL)) sync(n);
            if (n.querySelectorAll) [].forEach.call(n.querySelectorAll(SEL), sync);
          });
        }
      });
    }).observe(document.body, { attributes: true, attributeFilter: ['class'], subtree: true, childList: true });
    document.querySelectorAll(SEL).forEach(sync);
  })();

  /* ---------- Модалки: [data-modal-open="id"], [data-modal-close] ---------- */
  document.addEventListener('click', function (e) {
    var open = e.target.closest('[data-modal-open]');
    if (open) {
      var m = document.getElementById(open.dataset.modalOpen);
      if (m) {
        m.classList.add('open');
        /* is-opening ставится только по нажатию, а не при первом рендере:
           анимация не должна быть тем, что делает содержимое видимым. Там, где
           часы анимации не идут (фоновая вкладка, throttled iframe), клок
           навсегда остаётся в кадре from. */
        if (m.classList.contains('pass-sheet')) m.classList.add('is-opening');
        var f = m.querySelector('input:not([type=hidden]), select, textarea');
        if (f) f.focus();
      }
      return;
    }
    /* Лист удостоверения — тот же оверлей, только другой формы: на телефоне
       он выезжает снизу, на десктопе стоит модалкой. Отдельного механизма ему
       не нужно, поэтому он просто попадает в те же селекторы. */
    if (e.target.closest('[data-modal-close]')
        || (e.target.classList
            && (e.target.classList.contains('modal-backdrop') || e.target.classList.contains('pass-sheet')))) {
      var b = e.target.closest('.modal-backdrop, .pass-sheet');
      if (b) { b.classList.remove('open'); b.classList.remove('is-opening'); }
    }
  });

  /* Модалку можно открыть ССЫЛКОЙ с другой страницы: «#открыть-id».
     «Подключить сервис» из быстрых действий вело просто в список порталов —
     то есть обещало действие, а приводило к оглавлению, и мастер надо было
     искать глазами. Ссылка на модалку решает это без второго маршрута. */
  if (location.hash.startsWith('#открыть-') || location.hash.startsWith('#open-')) {
    var wantId = location.hash.replace(/^#(открыть|open)-/, '');
    var wanted = document.getElementById(wantId);
    if (wanted && wanted.classList.contains('modal-backdrop')) {
      wanted.classList.add('open');
      var wf = wanted.querySelector('input:not([type=hidden]), select, textarea');
      if (wf) wf.focus();
    }
  }

  /* ---------- Drawer: [data-drawer] с JSON-полями в data-fields ---------- */
  var drawer = null;
  function ensureDrawer() {
    if (drawer) return drawer;
    drawer = document.createElement('div');
    drawer.className = 'drawer-backdrop';
    drawer.innerHTML =
      '<aside class="drawer" role="dialog" aria-modal="true">' +
      '  <div class="drawer-head"><h3 class="mb-0 drawer-title"></h3>' +
      '    <button class="btn-icon btn-icon-s" type="button" data-drawer-close aria-label="">' +
      '      <svg class="icon" width="18" height="18" aria-hidden="true"><use href="/assets/icons.svg#i-x"></use></svg>' +
      '    </button></div>' +
      '  <div class="drawer-body"><dl class="kv"></dl></div>' +
      '</aside>';
    drawer.querySelector('[data-drawer-close]').setAttribute('aria-label', L('close', 'Закрыть'));
    document.body.appendChild(drawer);
    drawer.addEventListener('click', function (e) {
      if (e.target === drawer || e.target.closest('[data-drawer-close]')) drawer.classList.remove('open');
    });
    return drawer;
  }

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-drawer]');
    if (!trigger || e.target.closest('a, button, form')) return;
    var d = ensureDrawer();
    var fields;
    try { fields = JSON.parse(trigger.dataset.fields || '{}'); } catch (err) { fields = {}; }
    d.querySelector('.drawer-title').textContent = trigger.dataset.drawer || L('details', 'Подробности');
    var dl = d.querySelector('.kv');
    dl.innerHTML = '';
    Object.keys(fields).forEach(function (k) {
      if (fields[k] === '' || fields[k] === null) return;
      var dt = document.createElement('dt'); dt.textContent = k;
      var dd = document.createElement('dd'); dd.textContent = fields[k];
      if (k === L('detailsKey', 'Детали') || k === 'sub') dd.className = 'mono';
      dl.appendChild(dt); dl.appendChild(dd);
    });
    d.classList.add('open');
  });

  /* ---------- Пароль: показать/скрыть ---------- */
  document.addEventListener('click', function (e) {
    var eye = e.target.closest('[data-pw-toggle]');
    if (!eye) return;
    var input = document.getElementById(eye.dataset.pwToggle);
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
    eye.textContent = input.type === 'password' ? L('show', 'показать') : L('hide', 'скрыть');
  });

  /* ---------- PasswordMeter: честный (длина + список утёкших) ---------- */
  var COMMON = ['password','123456','12345678','qwerty','abc123','111111','123123','1234567890','qwerty123',
    'пароль','йцукен','1q2w3e4r','admin','welcome','monkey','dragon','letmein','iloveyou','000000','zaq12wsx'];
  document.addEventListener('input', function (e) {
    var input = e.target.closest('[data-pw-meter]');
    if (!input) return;
    var bar = document.getElementById(input.dataset.pwMeter);
    if (!bar) return;
    var v = input.value, score = 0;
    var min = parseInt(input.dataset.pwMin || '12', 10);
    if (COMMON.indexOf(v.toLowerCase()) !== -1) score = 5;
    else score = Math.min(100, Math.round(v.length / (min + 6) * 100));
    var i = bar.querySelector('i');
    i.style.width = Math.max(score, v ? 8 : 0) + '%';
    i.style.background = score < 50 ? 'var(--danger)' : (score < 80 ? 'var(--warning)' : 'var(--success)');
  });

  /* Отправить форму так, как если бы нажали кнопку: requestSubmit() порождает
     событие submit, а form.submit() — нет. Во фрейме входа на странице
     портала отправку перехватывает подмена экрана (v4.75), и мимо неё форма
     ушла бы новым документом — тем самым, что роняет вкладку. */
  function submitForm(form) {
    if (form.requestSubmit) form.requestSubmit(); else form.submit();
  }

  /* Экран входа во фрейме меняется без нового документа (v4.75): то, что
     настраивается при загрузке страницы, настраивается и после подмены. */
  function onPage(fn) {
    fn();
    document.addEventListener('sso:page', fn);
  }

  /* ---------- Полоса «Сохранить»: пилюля, пока прилипла (v4.89) ----------
     Липкая полоса прилипает к низу окна (или к низу окна диалога), пока форма
     длиннее экрана. Прилипла — плывёт поверх содержимого стеклом с тенью;
     стоит на своём месте в конце формы — просто ряд кнопок (.is-rest).
     «Прилипла» — нижний край там, где его держит sticky: наблюдатель следит за
     окном, урезанным снизу ровно на этот отступ и ещё на пиксель. */
  onPage(function () {
    if (!('IntersectionObserver' in window)) return;
    document.querySelectorAll('.form-actions').forEach(function (bar) {
      if (bar._ssoSticky) return;
      bar._ssoSticky = true;
      var root = bar.closest('.modal');
      var off = parseFloat(getComputedStyle(bar).bottom) || 0;
      var edge = function () { return root ? root.getBoundingClientRect().bottom : window.innerHeight; };
      var mark = function (stuck) {
        bar.classList.toggle('is-rest', !stuck || bar.classList.contains('is-clean'));
      };
      var stuckNow = function () { return bar.getBoundingClientRect().bottom >= edge() - off - 1.5; };
      /* Липнет, только когда есть что сохранять (v4.93). Пилюля «Сохранить»
         над нетронутой формой плыла поверх содержимого и закрывала его — на
         карточке роли она лежала на «Особых правах», хотя сохранять было
         нечего. Пока форму не трогали, полоса стоит в конце формы; первая
         правка — и она прилипает к низу. Полоса с другими действиями
         («Подставить предложенное», «Выгрузить») липнет всегда: они нужны и
         без правок. */
      var form = bar.closest('form');
      var others = Array.prototype.filter.call(bar.querySelectorAll('a, button'), function (el) {
        return el.type !== 'submit' && !el.hidden;
      });
      if (form && others.length === 0) {
        bar.classList.add('is-clean');
        var touched = function () {
          if (!bar.classList.contains('is-clean')) return;
          bar.classList.remove('is-clean');
          /* Сразу, а не в следующем кадре: замер положения сам пересчитывает
             раскладку, а кадра в фоновой вкладке можно и не дождаться. */
          mark(stuckNow());
        };
        form.addEventListener('input', touched);
        form.addEventListener('change', touched);
        /* Кнопки, которые заполняют форму сами (столбец прав целиком, палитра,
           готовая схема), событий полей не порождают — правкой считается и
           нажатие такой кнопки. */
        form.addEventListener('click', function (e) {
          var b = e.target.closest('button');
          if (b && b.type !== 'submit' && !bar.contains(b)) touched();
        });
      }
      /* Первое состояние — сразу, без перехода: пилюля не должна мигнуть на
         странице, где полоса стоит на месте. */
      mark(stuckNow());
      requestAnimationFrame(function () { bar.classList.add('is-ready'); });
      new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          mark(e.intersectionRatio < 1 && e.boundingClientRect.bottom >= edge() - off - 1.5);
        });
      }, { root: root, threshold: [1], rootMargin: '0px 0px -' + (off + 1) + 'px 0px' }).observe(bar);
    });
  });

  /* ---------- OTP-инпут: 6 ячеек, автоперенос, вставка из буфера ---------- */
  onPage(function () { document.querySelectorAll('[data-otp]').forEach(function (box) {
    if (box.dataset.otpReady) return;
    box.dataset.otpReady = '1';
    var cells = Array.prototype.slice.call(box.querySelectorAll('input[type=text], input[type=tel]'));
    var hidden = document.getElementById(box.dataset.otp);
    function sync() {
      if (hidden) hidden.value = cells.map(function (c) { return c.value; }).join('');
      if (hidden && hidden.value.length === cells.length && box.dataset.otpSubmit) {
        var form = box.closest('form');
        if (form) submitForm(form);
      }
    }
    cells.forEach(function (cell, i) {
      cell.addEventListener('input', function () {
        var v = cell.value.replace(/\D/g, '');
        if (v.length > 1) { // вставка нескольких цифр
          v.split('').slice(0, cells.length - i).forEach(function (d, j) { cells[i + j].value = d; });
          var last = Math.min(i + v.length, cells.length - 1);
          cells[last].focus();
        } else {
          cell.value = v;
          if (v && i < cells.length - 1) cells[i + 1].focus();
        }
        sync();
      });
      cell.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace' && !cell.value && i > 0) { cells[i - 1].focus(); cells[i - 1].value = ''; sync(); }
      });
      cell.addEventListener('paste', function (e) {
        e.preventDefault();
        var text = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, cells.length);
        text.split('').forEach(function (d, j) { if (cells[j]) cells[j].value = d; });
        if (text) cells[Math.min(text.length, cells.length) - 1].focus();
        sync();
      });
    });
    if (cells[0]) cells[0].focus();
  }); });

  /* ---------- CopyField ---------- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    var src = document.getElementById(btn.dataset.copy);
    var text = src ? (src.value !== undefined ? src.value : src.textContent) : '';
    navigator.clipboard.writeText(text).then(function () {
      /* Разметку, а не текст (v4.69): у кнопки-значка текста нет, и возврат
         textContent оставлял после первого копирования пустую кнопку. */
      var old = btn.innerHTML;
      /* Из словаря, а не строкой: в английском интерфейсе здесь стояло
         «Скопировано» (v4.70). */
      btn.textContent = L('copied', 'Скопировано');
      setTimeout(function () { btn.innerHTML = old; }, 1500);
    });
  });

  /* ---------- Всплывающая выдача роли [popover] (v4.70) ----------
     Окно открывает сам браузер (popovertarget), здесь — только место: под
     кнопкой «+», а если снизу не хватает экрана — над ней. Событие toggle не
     всплывает, поэтому слушаем на фазе перехвата. При прокрутке окно
     закрываем: оно в верхнем слое и от кнопки бы отстало. */
  document.addEventListener('toggle', function (e) {
    var pop = e.target;
    if (!pop || !pop.matches || !pop.matches('.role-add[popover]') || e.newState !== 'open') return;
    var btn = document.querySelector('[popovertarget="' + pop.id + '"]');
    if (!btn) return;
    var r = btn.getBoundingClientRect();
    var w = pop.offsetWidth, h = pop.offsetHeight;
    /* Ширина окна — из clientWidth: innerWidth в скрытой вкладке бывает 0. */
    var vw = document.documentElement.clientWidth || window.innerWidth;
    var vh = document.documentElement.clientHeight || window.innerHeight;
    var left = Math.min(Math.max(8, r.left), Math.max(8, vw - w - 8));
    var top = r.bottom + 6;
    if (top + h > vh - 8) top = Math.max(8, r.top - h - 6);
    pop.style.position = 'fixed';
    pop.style.right = 'auto';
    pop.style.bottom = 'auto';
    pop.style.left = left + 'px';
    pop.style.top = top + 'px';
    var sel = pop.querySelector('select');
    if (sel) sel.focus();
  }, true);
  window.addEventListener('scroll', function () {
    document.querySelectorAll('.role-add[popover]').forEach(function (p) {
      if (p.matches(':popover-open')) p.hidePopover();
    });
  }, true);

  /* ---------- Подтверждения [data-confirm] ---------- */
  document.addEventListener('submit', function (e) {
    var form = e.target.closest('form[data-confirm]');
    if (form && !confirm(form.dataset.confirm)) e.preventDefault();
  }, true);

  /* ---------- Пачка с галочками [data-bulk] (v4.95) ----------
     Боты, подсети, правила: «выбрать все», счётчик выбранного и число в
     вопросе перед отправкой. Ничего не выбрано — кнопки молчат: пустая пачка
     ушла бы на сервер ради ответа «ничего не выбрано». */
  var bulkSync = function (form) {
    var boxes = form.querySelectorAll('tbody input[type=checkbox]');
    var n = 0;
    boxes.forEach(function (b) { if (b.checked) n++; });
    var all = form.querySelector('[data-check-all]');
    if (all) { all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
    var count = form.querySelector('[data-bulk-count]');
    if (count) count.textContent = count.dataset.tpl.replace(':n', n);
    if (form.dataset.confirmTpl) form.dataset.confirm = form.dataset.confirmTpl.replace(':n', n);
    form.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = n === 0; });
  };
  document.querySelectorAll('form[data-bulk]').forEach(bulkSync);
  document.addEventListener('change', function (e) {
    var form = e.target.closest('form[data-bulk]');
    if (!form || e.target.type !== 'checkbox') return;
    if (e.target.matches('[data-check-all]')) {
      form.querySelectorAll('tbody input[type=checkbox]').forEach(function (b) { b.checked = e.target.checked; });
    }
    bulkSync(form);
  });

  /* ---------- Submit: спиннер вместо блокировки «в никуда» ---------- */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.dataset.noLoading !== undefined) return;
    // Кнопка, которой отправили форму (важно для форм с несколькими submit)
    var btn = form.querySelector('button[type=submit]:focus') || form.querySelector('button[type=submit]');
    if (!btn) return;
    setTimeout(function () {
      btn.classList.add('is-loading');
      btn.disabled = true;
    }, 0);
    // Страховка: если переход не случился (валидация браузера, отмена) — вернуть кнопку
    setTimeout(function () {
      btn.classList.remove('is-loading');
      btn.disabled = false;
    }, 15000);
  });

  /* ---------- Скачивание: ссылка тоже должна отвечать ----------
     Форма при отправке показывает спиннер, а ссылка — нет: нажал «Скачать
     бэкап» и ничего. На маленькой базе файл собирается за полсекунды, на
     живой — десятки секунд, и человек жмёт второй раз, запуская вторую сборку.

     Честного сигнала «файл ушёл» у браузера нет: событий загрузки страница не
     видит. Поэтому крутим ровно столько, сколько имеет смысл ждать, и
     возвращаем кнопку, когда вкладка снова получает фокус — то есть когда
     диалог сохранения закрылся. Врать «готово» мы не можем, но и молчать
     не должны. */
  document.addEventListener('click', function (e) {
    var link = e.target.closest('a[data-download]');
    if (!link || link.classList.contains('is-loading')) return;
    link.classList.add('is-loading');
    var done = function () {
      link.classList.remove('is-loading');
      window.removeEventListener('focus', done);
    };
    window.addEventListener('focus', done);
    setTimeout(done, 20000);
  });

  /* ---------- Тосты: форма и автоскрытие ----------
     Круглые бока идут однострочному облачку и не идут многострочному: там текст
     упирается в скругление. Считаем по факту, а не по длине строки — ширина
     зависит от экрана, а перенос от шрифта. */
  document.querySelectorAll('.toast').forEach(function (t) {
    var line = parseFloat(getComputedStyle(t).fontSize) * 1.6;
    var text = t.querySelector('span');
    if (text && text.offsetHeight > line * 1.4) t.classList.add('is-long');
    /* Наведение задерживает уход: длинное сообщение не должно исчезать из-под
       читающего. Отсчёт начинается заново, когда курсор ушёл. */
    var timer = null;
    var hide = function () {
      t.style.transition = 'opacity .3s ease-out, transform .3s ease-out';
      t.style.opacity = '0';
      t.style.transform = 'translateY(8px)';
      setTimeout(function () { t.remove(); }, 350);
    };
    var arm = function () { timer = setTimeout(hide, 5000); };

    /* Ошибка не уходит сама и не закрывается по клику: её текст читают,
       выделяют и несут дальше, а облачко, исчезающее под курсором, ровно
       этому и мешало. Закрыть — крестиком, скопировать — кнопкой. */
    if (t.hasAttribute('data-sticky')) {
      t.addEventListener('click', function (e) {
        if (e.target.closest('[data-toast-close]')) { hide(); return; }
        if (!e.target.closest('[data-toast-copy]')) return;
        var btn = e.target.closest('[data-toast-copy]');
        var msg = text ? text.textContent : t.textContent;
        var done = function () {
          var was = btn.textContent;
          btn.textContent = L('copied', 'Скопировано');
          setTimeout(function () { btn.textContent = was; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(msg).then(done, function () { fallbackCopy(msg, done); });
        } else {
          fallbackCopy(msg, done);
        }
      });
      return;
    }

    t.addEventListener('mouseenter', function () { clearTimeout(timer); });
    t.addEventListener('mouseleave', arm);
    t.addEventListener('click', hide);
    arm();
  });

  /* Запасной путь копирования: clipboard-API требует защищённого соединения,
     а установка по http — обычное дело на своём сервере. Без запасного пути
     кнопка на таком сервере молча ничего не делала бы. */
  function fallbackCopy(text, done) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;left:-9999px;top:0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (err) { /* нечем — оставляем как есть */ }
    document.body.removeChild(ta);
  }

  /* ---------- AJAX-кнопки (проверка БД/SMTP): [data-ajax] на форме ---------- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-ajax]');
    if (!btn) return;
    e.preventDefault();
    var form = btn.closest('form');
    var out = document.getElementById(btn.dataset.ajax);
    var fd = new FormData(form);
    fd.append('_ajax_action', btn.dataset.ajaxAction || btn.name || '');
    btn.disabled = true;
    btn.classList.add('is-loading');
    if (out) { out.textContent = L('checking', 'Проверяю…'); out.className = 'hint'; }
    fetch(btn.dataset.ajaxUrl || form.action, { method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (out) { out.textContent = j.message || (j.ok ? L('done', 'Готово') : L('error', 'Ошибка')); out.className = j.ok ? 'text-success' : 'text-danger'; }
        if (j.ok && btn.dataset.ajaxEnable) {
          var next = document.getElementById(btn.dataset.ajaxEnable);
          if (next) next.disabled = false;
        }
      })
      .catch(function () { if (out) { out.textContent = L('requestFailed', 'Сбой запроса — посмотрите логи.'); out.className = 'text-danger'; } })
      .finally(function () { btn.disabled = false; btn.classList.remove('is-loading'); });
  });

  /* ---------- Капча: другая картинка ----------
     Примерно каждый десятый не разберёт первую задачу, и без этой кнопки
     единственный выход — уйти со страницы. Перезагружаем только картинку:
     новую задачу выдаёт сервер, а перезагружать всю форму значило бы стереть
     уже введённые почту и пароль. */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-captcha-new]');
    if (!btn) return;
    var box = btn.closest('.captcha');
    var img = box ? box.querySelector('.captcha-img') : null;
    if (!img) return;
    img.src = '/captcha.png?new=1&' + Date.now();
    var field = box.querySelector('input[name=captcha]');
    if (field) { field.value = ''; field.focus(); }
  });

  /* ---------- Скрытое значение: [data-secret] ----------
     Номер аккаунта, уровень и MRZ закрыты точками по умолчанию: удостоверение
     показывают с экрана и фотографируют, и то, что не нужно собеседнику, не
     должно попадать в кадр само собой.

     Раскрытие по нажатию, а не по наведению: с телефона наведения нет вовсе, а
     на десктопе оно раскрывало бы значение случайным движением мыши.
     Значение живёт в data-атрибуте, а не в скрытом узле, — иначе оно
     обнаружилось бы простым выделением всей страницы. */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-secret]');
    if (!btn) return;
    /* Значение ищем по позиции, а не по классу `.dots`: класс здесь несёт
       состояние — CSS различает `.dots` (точки: разрядка и приглушённый цвет)
       и `:not(.dots)` (обычный текст). Искать по нему значит потерять элемент
       ровно после первого нажатия. */
    var value = btn.firstElementChild;
    var reveal = btn.querySelector('.reveal');
    if (!value) return;
    var i18n = window.ssoI18n || {};
    var shown = btn.dataset.shown === '1';
    if (!btn.dataset.mask) btn.dataset.mask = value.textContent;
    if (shown) {
      value.textContent = btn.dataset.mask;
      value.classList.add('dots');
      btn.classList.add('is-hidden');
      btn.dataset.shown = '0';
    } else {
      value.textContent = btn.dataset.secret;
      value.classList.remove('dots');
      btn.classList.remove('is-hidden');
      btn.dataset.shown = '1';
    }
    if (reveal) reveal.textContent = shown ? (i18n.show || 'показать') : (i18n.hide || 'скрыть');
  });

  /* ---------- QR (TOTP): [data-qr] с текстом в data-qr ---------- */
  document.querySelectorAll('[data-qr]').forEach(function (el) {
    if (typeof qrcode !== 'function') return;
    try {
      var qr = qrcode(0, 'M');
      qr.addData(el.dataset.qr);
      qr.make();
      el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
      var svg = el.querySelector('svg');
      /* Размер задаёт контейнер, а не скрипт: у экрана 2FA это плитка 176px, у
         удостоверения — квадрат 84px рядом с машинной строкой. Зашитое число
         работало ровно до второго места применения. */
      if (svg) { svg.style.width = '100%'; svg.style.height = '100%'; svg.style.display = 'block'; }
    } catch (err) { /* секрет останется в ручном виде рядом */ }
  });

  /* ---------- Аватар: превью выбранного файла ---------- */
  document.addEventListener('change', function (e) {
    var input = e.target.closest('input[type=file][data-preview]');
    if (!input || !input.files || !input.files[0]) return;
    var img = document.getElementById(input.dataset.preview);
    if (img) img.src = URL.createObjectURL(input.files[0]);
    if (input.dataset.autosubmit) input.closest('form').submit();
  });

  /* ---------- Фильтры: на телефоне сворачиваются ----------
     Панель из пяти полей занимала весь первый экран, и до списка приходилось
     доскроллить. Кнопку добавляет скрипт, а не шаблоны: фильтр-баров три, и
     любой новый получит сворачивание сам. Без JS всё остаётся как было. */
  document.querySelectorAll('.filter-bar').forEach(function (bar) {
    var active = 0;
    bar.querySelectorAll('input, select').forEach(function (f) {
      if (f.type === 'hidden' || f.type === 'submit') return;
      if (f.value && f.value !== '') active++;
    });
    var wrap = document.createElement('div');
    wrap.className = 'filters' + (active ? ' open' : '');
    bar.parentNode.insertBefore(wrap, bar);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-secondary btn-s filters-toggle';
    btn.setAttribute('aria-expanded', active ? 'true' : 'false');
    var label = (window.ssoI18n && window.ssoI18n.filters) || 'Фильтры';
    btn.textContent = label;
    if (active) {
      var badge = document.createElement('span');
      badge.className = 'badge badge-ink';
      badge.textContent = String(active);
      btn.appendChild(document.createTextNode(' '));
      btn.appendChild(badge);
    }
    wrap.appendChild(btn);
    wrap.appendChild(bar);
    btn.addEventListener('click', function () {
      var open = wrap.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  /* ---------- Акцент: пипетка пишет в hex-поле, hex-поле — в пипетку ---------- */
  document.addEventListener('input', function (e) {
    var pick = e.target.closest('[data-accent-pick]');
    var hex = e.target.closest('[data-accent-hex]');
    if (!pick && !hex) return;
    /* Пара — внутри своего поля, а не первая в форме (v4.71): в редакторе
       экрана входа цветов девять, и пипетка любого писала в первое поле. */
    var box = e.target.closest('.copyfield') || e.target.closest('form');
    if (!box) return;
    var pickEl = box.querySelector('[data-accent-pick]');
    var hexEl = box.querySelector('[data-accent-hex]');
    if (!pickEl || !hexEl) return;
    if (pick) hexEl.value = pickEl.value.toUpperCase();
    /* Пипетка принимает только полный #RRGGBB — недописанный hex игнорируем */
    else if (/^#[0-9a-f]{6}$/i.test(hexEl.value.trim())) pickEl.value = hexEl.value.trim();
  });

  /* ---------- Строка фильтров: выбор применяется сразу (v4.71) ----------
     Кнопка «Фильтровать» была лишним нажатием после каждого выбора. Текст и
     даты применяются по Enter, как любая форма; кнопка — в <noscript>. */
  document.addEventListener('change', function (e) {
    var form = e.target.closest('form.filter-bar');
    if (!form || e.target.tagName !== 'SELECT' && e.target.type !== 'date') return;
    form.submit();
  });

  /* ---------- Редактор экрана входа (v4.71) ----------
     Фрейм показывает настоящий экран входа с тем, что сейчас в форме: поля
     уходят GET-запросом на страницу предпросмотра, а разбирает их тот же код,
     что и сохранение. Файлы не уходят — обложка видна после сохранения.
     Что из палитры не применится, фрейм сообщает в <meta name="look-why">. */
  document.querySelectorAll('[data-look-editor]').forEach(function (ed) {
    var form = ed.closest('form');
    var frame = ed.querySelector('[data-look-frame]');
    var stage = ed.querySelector('[data-look-stage]');
    var openLink = ed.querySelector('[data-look-open]');
    var whyBox = ed.querySelector('[data-look-why]');
    var base = ed.getAttribute('data-preview');
    if (!form || !frame || !stage || !base) return;
    var scheme = 'light', device = 'desktop', timer = null;
    /* Предпросмотр обновляется БЕЗ перехода фрейма (v4.73.1).

       Фрейм загружается один раз — из своего src. Дальше каждое изменение
       формы берёт свежий экран обычным fetch и подменяет в том же документе
       стили, метку look-why и содержимое body. Новых документов нет.

       Зачем. У владельца вкладка падала с STATUS_BREAKPOINT при быстрых
       щелчках по карточкам и после 4.72.1, где переходы шли строго по одному.
       Семь дампов Chrome 153 (24.09) — одно и то же место внутри Chrome, процесс
       страницы, в нём четыре расширения, и каждое внедряет свои скрипты во все
       фреймы в начале документа. Каждый щелчок — новый документ во фрейме и
       два десятка внедрений, и так раз за разом. Без расширений (панель
       браузера) не падало ни разу. Нет новых документов — нечего и внедрять;
       заодно не растёт история вкладки и сервер не рисует оборванное.

       Загрузка по-прежнему одна за раз: следующий адрес ждёт конца текущей и
       берётся последний. Первая загрузка фрейма тоже считается. */
    var busy = true, again = false, current = '', stall = null;

    function query() {
      var p = new URLSearchParams();
      new FormData(form).forEach(function (v, k) {
        if (typeof v !== 'string' || k.charAt(0) === '_') return;
        p.append(k, v);
      });
      p.set('scheme', scheme);
      return p.toString();
    }
    function refresh() {
      var url = base + '?' + query();
      if (openLink) openLink.href = url;
      if (url === current) return;
      if (busy) { again = true; return; }
      busy = true;
      again = false;
      current = url;
      /* Ответ может не прийти (обрыв сети) — предпросмотр не должен замереть. */
      var ctrl = window.AbortController ? new AbortController() : null;
      clearTimeout(stall);
      stall = setTimeout(function () { if (ctrl) ctrl.abort(); }, 10000);
      fetch(url, { credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
        .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
        .then(swap)
        .catch(function () { current = ''; /* следующее изменение попробует снова */ })
        .then(loaded);
    }
    function loaded() {
      clearTimeout(stall);
      busy = false;
      if (again) refresh();
    }
    /* Подменить экран в том же документе фрейма: атрибуты <html> (тема
       предпросмотра), стили темы и метку look-why в <head>, содержимое <body>.
       Подключённые стили у всех состояний одни и те же — их не трогаем, иначе
       экран мигал бы без оформления. Скрипты из нового body не выполняются —
       экран предпросмотра и так неинтерактивен (inert). */
    function swap(html) {
      var doc = frame.contentDocument;
      if (!doc || !doc.body) return;
      var next = new DOMParser().parseFromString(html, 'text/html');
      ['data-theme', 'lang'].forEach(function (a) {
        var v = next.documentElement.getAttribute(a);
        if (v === null) doc.documentElement.removeAttribute(a);
        else doc.documentElement.setAttribute(a, v);
      });
      doc.head.querySelectorAll('style, meta[name="look-why"]').forEach(function (n) { n.remove(); });
      next.head.querySelectorAll('style, meta[name="look-why"]').forEach(function (n) {
        doc.head.appendChild(doc.importNode(n, true));
      });
      doc.body.className = next.body.className;
      doc.body.innerHTML = next.body.innerHTML;
      showWhy(doc);
    }
    /* Что из палитры не применится — из метки look-why экрана. */
    function showWhy(doc) {
      if (!whyBox) return;
      try {
        var meta = doc && doc.querySelector('meta[name="look-why"]');
        var list = meta ? JSON.parse(meta.getAttribute('content') || '[]') : [];
        whyBox.textContent = '';
        list.forEach(function (w) {
          var li = document.createElement('li');
          li.textContent = w;
          whyBox.appendChild(li);
        });
        whyBox.hidden = list.length === 0;
      } catch (err) { /* чужой ответ во фрейме — молчим */ }
    }
    function later() { clearTimeout(timer); timer = setTimeout(refresh, 250); }
    /* Фрейм рисует экран в настоящую ширину и уменьшается целиком: иначе
       на узкой колонке показалась бы телефонная раскладка вместо настольной. */
    function fit() {
      var phone = device === 'phone';
      var w = phone ? 390 : 1100, h = phone ? 780 : 760, frameW = phone ? 16 : 2;
      var avail = Math.max(200, stage.parentElement.clientWidth - frameW);
      var s = Math.min(phone ? 0.72 : 1, avail / w);
      frame.style.transform = 'scale(' + s + ')';
      stage.style.width = phone ? Math.round(w * s + frameW) + 'px' : '';
      stage.style.height = Math.round(h * s + frameW) + 'px';
    }
    function checked(name) {
      var el = form.querySelector('input[name="' + name + '"]:checked');
      return el ? el.value : null;
    }
    function sync() {
      var mode = checked('look_mode') || 'custom';
      ed.querySelectorAll('[data-look-custom]').forEach(function (el) { el.hidden = mode !== 'custom'; });
      ed.querySelectorAll('[data-look-when]').forEach(function (el) {
        el.hidden = el.getAttribute('data-look-when') !== mode;
      });
      var dark = form.querySelector('#look-dark'), darkBox = ed.querySelector('[data-look-dark]');
      if (dark && darkBox) darkBox.hidden = !dark.checked;
      var methods = ed.querySelector('[data-look-methods]');
      if (methods) methods.hidden = checked('methods_mode') !== 'some';
    }
    function setVal(name, val) {
      var els = form.querySelectorAll('[name="look[' + name + ']"]:not([type=hidden])');
      if (!els.length) return;
      var el = els[0];
      if (el.type === 'radio') {
        els.forEach(function (r) { r.checked = String(r.value) === String(val); });
        return;
      }
      if (el.type === 'checkbox') { el.checked = !!val; return; }
      el.value = val;
      var box = el.closest('.copyfield');
      var pick = box && box.querySelector('[data-accent-pick]');
      /* Пустой цвет — «как у установки»: пипетка показывает подсказку поля. */
      var shown = val || el.placeholder || '';
      if (pick && /^#[0-9a-f]{6}$/i.test(shown)) pick.value = shown;
    }
    function fill(values) {
      var dark = false;
      Object.keys(values).forEach(function (k) {
        if (/_dark$/.test(k)) dark = true;
        setVal(k, values[k]);
      });
      var t = form.querySelector('#look-dark');
      if (t && dark) t.checked = true;
      sync();
      refresh();
    }

    /* Первая и единственная загрузка фрейма. */
    frame.addEventListener('load', function () {
      loaded();
      showWhy(frame.contentDocument);
    });
    form.addEventListener('input', function (e) {
      if (e.target.type === 'file' || e.target.name.charAt(0) === '_') return;
      later();
    });
    form.addEventListener('change', function (e) {
      if (e.target.matches('[data-look-scheme]')) { scheme = e.target.value; refresh(); return; }
      if (e.target.matches('[data-look-device]')) {
        device = e.target.value;
        stage.classList.toggle('is-phone', device === 'phone');
        fit();
        return;
      }
      sync();
      if (e.target.type !== 'file') later();
    });
    ed.addEventListener('click', function (e) {
      var pal = e.target.closest('[data-look-palette]');
      if (pal) {
        var seed = form.querySelector('#look-seed');
        /* Пустое поле — цвет из подсказки: это акцент установки. */
        var hex = seed ? (seed.value.trim() || seed.placeholder || '').replace('#', '') : '';
        if (!/^[0-9a-f]{6}$/i.test(hex)) { if (seed) seed.focus(); return; }
        pal.disabled = true;
        fetch('/admin/look/palette?accent=' + encodeURIComponent(hex), { credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (p) { if (p) fill(p); })
          .finally(function () { pal.disabled = false; });
        return;
      }
      if (e.target.closest('[data-look-reset]')) {
        form.querySelectorAll('[name^="look["]').forEach(function (el) {
          if (el.type === 'hidden' || el.type === 'file') return;
          if (el.type === 'radio') { el.checked = el.value === '' || el.value === 'left'; return; }
          if (el.type === 'checkbox') { el.checked = el.name === 'look[wash]'; return; }
          el.value = '';
          var box = el.closest('.copyfield');
          var pick = box && box.querySelector('[data-accent-pick]');
          if (pick && el.placeholder) pick.value = el.placeholder;
        });
        sync();
        refresh();
        return;
      }
      /* Свои схемы (v4.79): сохранить то, что сейчас в форме, и убрать. Ответ
         сервера — готовая плитка: форма с несохранёнными правками остаётся. */
      var csrfOf = function () { var c = form.querySelector('input[name=_csrf]'); return c ? c.value : ''; };
      var post = function (url, fd) {
        fd.append('_csrf', csrfOf());
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
          .then(function (r) { return r.json(); });
      };
      var mineList = ed.querySelector('[data-look-mine-list]');
      var mineEmpty = ed.querySelector('[data-look-mine-empty]');
      var mineSync = function () {
        var none = !mineList || !mineList.querySelector('.look-mine');
        if (mineList) mineList.hidden = none;
        if (mineEmpty) mineEmpty.hidden = !none;
      };
      var save = e.target.closest('[data-look-save-scheme]');
      if (save) {
        var name = window.prompt(L('schemeName', 'Как назвать схему?'), '');
        if (!name || !name.trim()) return;
        name = name.trim();
        var fd = new FormData();
        fd.append('name', name);
        new FormData(form).forEach(function (v, k) { if (k.indexOf('look[') === 0 && typeof v === 'string') fd.append(k, v); });
        var status = ed.querySelector('[data-look-scheme-status]');
        save.disabled = true;
        post('/admin/look/schemes', fd)
          .then(function (j) {
            if (status) status.textContent = j.message || '';
            if (!j.ok || !j.html || !mineList) return;
            /* То же имя — та же схема: прежняя плитка уступает новой. */
            mineList.querySelectorAll('.look-mine').forEach(function (t) {
              var b = t.querySelector('[data-look-adopt]');
              if (b && (b.getAttribute('title') || '').toLowerCase() === name.toLowerCase()) t.remove();
            });
            mineList.insertAdjacentHTML('beforeend', j.html);
            mineSync();
          })
          .catch(function () { if (status) status.textContent = L('requestFailed', 'Сбой запроса — посмотрите логи.'); })
          .then(function () { save.disabled = false; });
        return;
      }
      var drop = e.target.closest('[data-look-drop-scheme]');
      if (drop) {
        if (!window.confirm(L('schemeDrop', 'Убрать схему «:name»?').replace(':name', drop.getAttribute('data-name') || ''))) return;
        var dfd = new FormData();
        dfd.append('id', drop.getAttribute('data-look-drop-scheme') || '');
        post('/admin/look/schemes/drop', dfd).then(function (j) {
          if (!j.ok) return;
          var tile = drop.closest('.look-mine');
          if (tile) tile.remove();
          mineSync();
        });
        return;
      }
      var ad = e.target.closest('[data-look-adopt]');
      if (ad) {
        var custom = form.querySelector('input[name="look_mode"][value="custom"]');
        if (custom) custom.checked = true;
        try { fill(JSON.parse(ad.getAttribute('data-look-adopt') || '{}')); } catch (err) { /* пусто */ }
      }
    });
    window.addEventListener('resize', fit);
    sync();
    fit();
    /* Первая загрузка могла закончиться раньше, чем подключился этот скрипт, —
       тогда её load уже прошёл, и ждать его нельзя. */
    var doc = null;
    try { doc = frame.contentDocument; } catch (err) { /* чужой документ — ждём load */ }
    busy = !(doc && doc.readyState === 'complete' && doc.URL !== 'about:blank');
    if (busy) stall = setTimeout(loaded, 8000);
    refresh();
  });

  /* ---------- Карта ролей: перенести подсказки в поля ----------
     Подсказка стоит в placeholder, а не в value, намеренно: карта управляет
     доступами, и заполнять её по содержимому чужого файла автоматически
     нельзя. Кнопка переносит подсказки в поля — дальше человек смотрит и
     сохраняет сам. Уже заполненное не трогаем. */
  document.addEventListener('click', function (e) {
    if (!e.target.closest('[data-fill-suggest]')) return;
    var filled = 0;
    document.querySelectorAll('input[data-suggest]').forEach(function (el) {
      if (el.value.trim() !== '') return;
      el.value = el.dataset.suggest;
      filled++;
    });
    if (filled) e.target.closest('[data-fill-suggest]').disabled = true;
  });

  /* ---------- Офлайн-заглушка: повторить именно ту страницу ----------
     Адрес не менялся — воркер подставил заглушку в ответ на навигацию,
     поэтому reload() уходит по исходному URL, а не по /offline. */
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-reload]')) location.reload();
  });

  /* ---------- Возврат в портал после входа (v4.72, v4.73) ----------
     Ссылка data-handoff уводит по адресу возврата с кодом через data-delay мс.
     Страницей — location.replace. Во фрейме на странице портала адрес уходит
     ей (postMessage только на data-origin), и она переходит по нему сама;
     не дошло — у ссылки target="_top", и нажатие уведёт всю вкладку. */
  onPage(function () { document.querySelectorAll('a[data-handoff]').forEach(function (a) {
    if (a.dataset.handoffDone) return;
    a.dataset.handoffDone = '1';
    var origin = a.getAttribute('data-origin');
    var framed = !!origin && window.parent !== window;
    setTimeout(function () {
      if (framed) window.parent.postMessage({ type: 'sso:code', url: a.href }, origin);
      else location.replace(a.href);
    }, parseInt(a.getAttribute('data-delay') || '0', 10));
  }); });

  /* ---------- Возврат из Telegram (v4.87) ----------
     Telegram отдаёт данные входа во фрагменте адреса (#tgAuthResult=…,
     base64url от JSON), а до сервера фрагмент не доходит. Переносим их в
     запрос обработчика как есть — подпись и state проверяет сервер; здесь
     только разбор. Имена полей — латиница, иначе поле отбрасывается. */
  var tgBack = document.querySelector('[data-tg-return]');
  if (tgBack) {
    var tgMatch = location.hash.match(/[#?&]tgAuthResult=([A-Za-z0-9\-_=]*)$/), tgData = null;
    if (tgMatch) {
      try {
        var tgRaw = tgMatch[1].replace(/-/g, '+').replace(/_/g, '/');
        while (tgRaw.length % 4) tgRaw += '=';
        var tgBin = atob(tgRaw), tgBytes = new Uint8Array(tgBin.length);
        for (var tgI = 0; tgI < tgBin.length; tgI++) tgBytes[tgI] = tgBin.charCodeAt(tgI);
        tgData = JSON.parse(new TextDecoder().decode(tgBytes));
      } catch (err) { tgData = null; }
    }
    if (tgData && typeof tgData === 'object' && tgData.hash) {
      var tgQuery = new URLSearchParams();
      Object.keys(tgData).forEach(function (k) {
        if (/^[a-z_]{1,32}$/.test(k) && k !== 'state') tgQuery.set(k, String(tgData[k]));
      });
      tgQuery.set('state', new URLSearchParams(location.search).get('state') || '');
      location.replace('/social/telegram/callback?' + tgQuery.toString());
    } else {
      var tgFail = tgBack.querySelector('[data-tg-fail]');
      if (tgFail) tgFail.hidden = false;
    }
  }

  /* ---------- Вход во фрейме на странице портала (v4.73) ----------
     Фрейм говорит странице портала: готов, вот высота — и просит отдельное
     окно там, где фрейм не справится: вход через сервисы (их страницы фреймы
     запрещают) и ссылка из письма (откроется в новой вкладке, а не здесь).
     Щелчок во фрейме даёт право открыть окно и странице портала. */
  var frameOrigin = document.documentElement.getAttribute('data-frame-origin');
  if (frameOrigin && window.parent !== window) {
    var toPortal = function (m) { m.type = 'sso:frame'; window.parent.postMessage(m, frameOrigin); };
    /* Высота — по содержимому (v4.87), а не по странице: страница не ниже
       фрейма, и по ней окно умело только расти. */
    var frameWrap = document.querySelector('.auth-wrap');
    var reportSize = function () {
      toPortal({ state: 'size', h: frameWrap ? Math.ceil(frameWrap.getBoundingClientRect().height)
        : document.documentElement.scrollHeight });
    };
    toPortal({ state: 'ready' });
    reportSize();
    if (window.ResizeObserver) new ResizeObserver(reportSize).observe(frameWrap || document.body);
    document.addEventListener('click', function (e) {
      var out = e.target.closest('a[href^="/social/"], [data-frame-window]');
      if (!out) return;
      e.preventDefault();
      /* Какой сервис выбран (v4.86) — окно откроется сразу на нём, без
         второго выбора там. */
      toPortal({ state: 'window', social: out.getAttribute('data-social') || '' });
    }, true);
    document.addEventListener('submit', function (e) {
      if (!e.target.matches('form[action="/login/link"]')) return;
      e.preventDefault();
      toPortal({ state: 'window' });
    }, true);

    /* ---------- Переходы во фрейме — без новых документов (v4.75) ----------
       Chrome 153 с блокировщиком рекламы (Adblock Plus, AdBlock — их скрипты
       встраиваются в каждый новый документ каждого фрейма) роняет процесс
       фрейма, когда фрейм переходит на новый документ: серое окно с грустным
       значком, STATUS_BREAKPOINT в chrome.dll+0x4a63980. Одиннадцать дампов
       25.09 — все на экранах входа во фрейме на Портфолио и Архиве; первая
       загрузка проходит, падает любой щелчок. Тот же сбой, что в предпросмотре
       экрана входа (4.73.1), и лечится так же: документ во фрейме один на весь
       вход. Ссылки и формы идут через fetch, ответ подменяет содержимое.

       Подменяется только ответ, который сам разрешил встраивать себя этому
       порталу (frame-ancestors) — fetch не должен стать обходом запрета: страница,
       которую нельзя показать во фрейме, не показывается в нём и так. Такой
       ответ и любой сбой — повод открыть вход отдельным окном, как для входа
       через сервисы. */
    var loadedScripts = {};
    Array.prototype.forEach.call(document.scripts, function (s) { if (s.src) loadedScripts[s.src] = true; });
    var swapBusy = false;
    var framable = function (r) {
      var fa = /frame-ancestors([^;]*)/.exec(r.headers.get('Content-Security-Policy') || '');
      return !!fa && fa[1].split(/\s+/).indexOf(frameOrigin) !== -1;
    };
    var swapPage = function (html, url) {
      var next = new DOMParser().parseFromString(html, 'text/html');
      ['lang', 'data-frame-origin'].forEach(function (a) {
        if (next.documentElement.hasAttribute(a)) document.documentElement.setAttribute(a, next.documentElement.getAttribute(a));
      });
      document.title = next.title;
      document.head.querySelectorAll('style').forEach(function (s) { s.remove(); });
      next.head.querySelectorAll('style').forEach(function (s) { document.head.appendChild(document.importNode(s, true)); });
      next.head.querySelectorAll('link[rel="stylesheet"]').forEach(function (l) {
        var href = l.getAttribute('href');
        var has = Array.prototype.some.call(document.head.querySelectorAll('link[rel="stylesheet"]'),
          function (x) { return x.getAttribute('href') === href; });
        if (!has) document.head.appendChild(document.importNode(l, true));
      });
      /* Словарь для скрипта: язык мог смениться (RU/EN внизу экрана). */
      next.querySelectorAll('script').forEach(function (s) {
        var m = /window\.ssoI18n = (\{.*\});/.exec(s.textContent);
        if (m) { try { window.ssoI18n = JSON.parse(m[1]); } catch (err) { /* останется прежний */ } }
      });
      document.body.className = next.body.className;
      document.body.innerHTML = next.body.innerHTML;
      /* Скрипты из innerHTML не исполняются. Свои файлы (passkey.js, скрипт
         капчи) догружаем, если их ещё не было; встроенные — только
         подстраховка прямой загрузки, во фрейме они не нужны. */
      next.body.querySelectorAll('script[src]').forEach(function (s) {
        var src = new URL(s.getAttribute('src'), url).href;
        if (loadedScripts[src]) return;
        loadedScripts[src] = true;
        var el = document.createElement('script');
        el.src = src;
        document.head.appendChild(el);
      });
      /* Виджет капчи, уже загруженный раньше, сам второй раз не рисуется. */
      if (window.turnstile) document.querySelectorAll('.cf-turnstile:empty').forEach(function (el) { window.turnstile.render(el); });
      if (window.hcaptcha) document.querySelectorAll('.h-captcha:empty').forEach(function (el) { window.hcaptcha.render(el); });
      try { history.replaceState(null, '', url); } catch (err) { /* адрес документа останется прежним */ }
      window.scrollTo(0, 0);
      document.dispatchEvent(new CustomEvent('sso:page'));
      var focus = document.querySelector('[autofocus]');
      if (focus) focus.focus();
      reportSize();
    };
    var go = function (url, init) {
      if (swapBusy) return;
      swapBusy = true;
      document.documentElement.setAttribute('aria-busy', 'true');
      /* mode: 'same-origin' — переадресация на чужой адрес обрывается ДО
         запроса туда: код авторизации не уйдёт на колбэк портала мимо
         страницы. Во фрейме SSO туда и не переадресует — отдаёт код странице
         портала (data-handoff). */
      fetch(url, Object.assign({ credentials: 'same-origin', mode: 'same-origin' }, init || {}))
        .then(function (r) {
          if (!framable(r)) throw new Error('not-framable');
          return r.text().then(function (html) { swapPage(html, r.url); });
        })
        .catch(function () { toPortal({ state: 'window' }); })
        .then(function () { swapBusy = false; document.documentElement.removeAttribute('aria-busy'); });
    };
    /* passkey.js после входа уходит по адресу из ответа — тоже сюда. */
    window.ssoGo = function (url) { go(url); };

    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var a = e.target.closest('a[href]');
      if (!a || a.hasAttribute('download')) return;
      var target = a.getAttribute('target');
      if (target && target !== '_self') return;
      var href = a.getAttribute('href');
      if (!href || href.charAt(0) === '#' || a.origin !== location.origin) return;
      e.preventDefault();
      go(a.href);
    });
    document.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      var form = e.target;
      var target = form.getAttribute('target');
      if (target && target !== '_self') return;
      var action = new URL(form.getAttribute('action') || location.href, location.href);
      if (action.origin !== location.origin) return;
      e.preventDefault();
      var data;
      try { data = new FormData(form, e.submitter || undefined); } catch (err) {
        data = new FormData(form);
        if (e.submitter && e.submitter.name) data.append(e.submitter.name, e.submitter.value);
      }
      var params = new URLSearchParams();
      data.forEach(function (v, k) { if (typeof v === 'string') params.append(k, v); });
      if ((form.getAttribute('method') || 'get').toLowerCase() === 'post') {
        go(action.href, { method: 'POST', body: params });
      } else {
        action.search = params.toString();
        go(action.href);
      }
    });
  }

  /* ---------- PWA ----------
     Воркер не регистрируется в попапе соцвхода и во фрейме: окно живёт
     секунды, а регистрация — на весь origin, смысла нет. */
  if ('serviceWorker' in navigator && window.top === window.self && !window.opener) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
    });
  }

  /* Кнопка «Установить» показывается только когда браузер сам подтвердил,
     что установка возможна. Иначе это была бы кнопка в никуда: критерии у
     Chrome, Safari и Firefox разные и меняются. */
  function pwaCards(visible) {
    document.querySelectorAll('[data-pwa-install]').forEach(function (el) { el.hidden = !visible; });
  }
  var installPrompt = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    installPrompt = e;
    pwaCards(true);
  });
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-pwa-prompt]');
    if (!btn || !installPrompt) return;
    installPrompt.prompt();
    installPrompt.userChoice.then(function () {
      installPrompt = null;
      pwaCards(false);
    });
  });
  /* Уже установлено — предлагать нечего */
  window.addEventListener('appinstalled', function () {
    installPrompt = null;
    pwaCards(false);
  });
  /* iOS: события установки нет вообще, зато есть navigator.standalone — он
     существует только в Safari и равен false, пока приложение не поставлено.
     Там показываем не кнопку, а куда нажать в самом браузере. */
  if ('standalone' in navigator && navigator.standalone === false) {
    document.querySelectorAll('[data-pwa-install]').forEach(function (card) {
      var btn = card.querySelector('[data-pwa-prompt]');
      var hint = card.querySelector('[data-pwa-ios]');
      if (btn) btn.hidden = true;
      if (hint) hint.hidden = false;
      card.hidden = false;
    });
  }

  /* ---------- Матрица прав роли (/admin/roles/{id}) ----------
     Каталог областей живёт в PHP и в JS не дублируется: всё, что нужно
     скрипту, разметка уже сообщила через data-area и data-action. Второй
     список областей однажды разошёлся бы с первым, и заметили бы это не сразу.

     Скрипт ничего не решает — он объясняет. Право «чтение» сервер и так
     проставляет сам (Permission::withImplied), но до сих пор делал это молча:
     человек ставил «изменять», сохранял и видел в таблице лишнюю галочку,
     которой не ставил. Теперь это видно до сохранения. */
  (function () {
    var form = document.querySelector('[data-perm-form]');
    if (!form) return;

    var LETTER = { read: 'r', write: 'w', delete: 'd' };
    var rows = Array.prototype.slice.call(form.querySelectorAll('tbody tr[data-area]'));
    var i18n = window.ssoI18n || {};

    function cellInput(row, action) {
      var td = row.querySelector('td[data-action="' + action + '"]');
      return td ? td.querySelector('input[type=checkbox]') : null;
    }

    /* Чтение, проставленное из-за «изменять» или «удалять»: галочка стоит, но
       она не ваша. Отключённое поле не отправляется — и это безопасно ровно
       потому, что withImplied() на сервере добавит право само. */
    function syncDerived() {
      rows.forEach(function (row) {
        var read = cellInput(row, 'read');
        if (!read) return;
        var w = cellInput(row, 'write'), d = cellInput(row, 'delete');
        var forced = (w && w.checked) || (d && d.checked);
        var cell = read.closest('.perm-cell');
        if (!cell) return;
        if (forced) {
          read.checked = true;
          read.disabled = true;
          cell.classList.add('is-derived');
          cell.title = i18n.permDerived || '';
        } else {
          read.disabled = false;
          cell.classList.remove('is-derived');
          cell.removeAttribute('title');
        }
      });
    }

    /* Та же раскладка одной строкой, в духе rwx. Буквы латинские в обоих
       языках намеренно: строку вставляют в тикет и сравнивают глазами, а не
       читают вслух. */
    function renderMode() {
      var box = form.querySelector('[data-perm-mode]');
      if (!box) return;
      box.textContent = '';
      rows.forEach(function (row) {
        var item = document.createElement('span');
        item.className = 'perm-mode-item';
        var name = document.createElement('span');
        name.textContent = row.dataset.area;
        var bits = document.createElement('span');
        bits.className = 'perm-mode-bits';
        ['read', 'write', 'delete'].forEach(function (action) {
          var input = cellInput(row, action);
          if (!input) { bits.append('·'); return; }
          if (input.checked) {
            var b = document.createElement('b');
            b.textContent = LETTER[action];
            bits.appendChild(b);
          } else {
            bits.append('-');
          }
        });
        item.appendChild(name);
        item.appendChild(bits);
        box.appendChild(item);
      });
    }

    /* Сколько галочек отличается от сохранённого. Без счётчика «Сохранить»
       выглядит одинаково и когда есть что сохранять, и когда нет. */
    var initial = null;
    function snapshot() {
      var map = {};
      form.querySelectorAll('input[name="perms[]"]').forEach(function (i) { map[i.value] = i.checked; });
      var level = form.querySelector('[data-level]');
      map['@level'] = level ? level.value : '';
      return map;
    }
    function renderDiff() {
      var now = snapshot(), n = 0;
      for (var k in initial) if (initial[k] !== now[k]) n++;
      var out = form.querySelector('[data-perm-diff]');
      var reset = form.querySelector('[data-perm-reset]');
      if (out) {
        out.hidden = n === 0;
        out.textContent = '';
        if (n) {
          var b = document.createElement('b');
          b.textContent = String(n);
          out.appendChild(b);
          out.append(' ' + (i18n.permChanged || 'изменено'));
        }
      }
      if (reset) reset.hidden = n === 0;
    }

    /* Число рядом со списком: список на шестнадцать пунктов прячет ответ на
       единственный вопрос — какое число уедет порталу. */
    function renderLevel() {
      var sel = form.querySelector('[data-level]');
      if (!sel) return;
      var opt = sel.options[sel.selectedIndex];
      var num = form.querySelector('[data-level-num]');
      var slug = form.querySelector('[data-level-slug]');
      if (num) num.textContent = sel.value;
      if (slug) slug.textContent = opt ? (opt.dataset.slug || '') : '';
    }

    function refresh() { syncDerived(); renderMode(); renderLevel(); renderDiff(); }

    /* Столбец целиком: восемнадцать галочек по одной ставили руками.
       Если хоть одна снята — включаем все, иначе снимаем: одна и та же кнопка
       и ставит, и снимает, и по её виду не нужно гадать, что она сделает. */
    form.querySelectorAll('.perm-col[data-col]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var action = btn.dataset.col;
        var boxes = [];
        rows.forEach(function (row) {
          var i = cellInput(row, action);
          if (i && !i.disabled) boxes.push(i);
        });
        if (!boxes.length) return;
        var turnOn = boxes.some(function (i) { return !i.checked; });
        boxes.forEach(function (i) { i.checked = turnOn; });
        refresh();
      });
    });

    var resetBtn = form.querySelector('[data-perm-reset]');
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        form.querySelectorAll('input[name="perms[]"]').forEach(function (i) {
          if (i.value in initial) i.checked = initial[i.value];
        });
        var level = form.querySelector('[data-level]');
        if (level && '@level' in initial) level.value = initial['@level'];
        refresh();
      });
    }

    form.addEventListener('change', refresh);

    /* Снимок делаем ДО первого syncDerived: иначе автопроставленное чтение
       попадёт в исходное состояние, и счётчик покажет ноль там, где изменение
       есть. */
    initial = snapshot();
    refresh();
  })();


  /* ---------- Маски ввода: [data-mask] ----------
     Маска описывается в разметке одной строкой вида «+7 (###) ###-##-##»:
     решётка — место под цифру, остальное — постоянные знаки. Обработчик один
     на всю страницу, поэтому новое поле с маской не требует ни строчки скрипта.

     Правило простое: человек набирает цифры, разделители появляются сами. Если
     он вставляет из буфера уже отформатированное — разделители снимаются и
     ставятся заново, а не удваиваются. */
  function applyMask(mask, raw) {
    var digits = (raw || '').replace(/\D+/g, '');
    if (!digits) return '';
    var out = '';
    var di = 0;
    for (var i = 0; i < mask.length && di < digits.length; i++) {
      if (mask[i] === '#') { out += digits[di++]; }
      else { out += mask[i]; }
    }
    return out;
  }

  document.addEventListener('input', function (e) {
    var el = e.target;
    if (!el || !el.dataset || !el.dataset.mask) return;
    var before = el.value;
    var atEnd = el.selectionStart === before.length;
    el.value = applyMask(el.dataset.mask, before);
    /* Курсор в конец только если он там и был: иначе правка в середине
       выбрасывала бы его в хвост на каждом нажатии. */
    if (atEnd) { try { el.setSelectionRange(el.value.length, el.value.length); } catch (err) {} }
  });
  document.querySelectorAll('[data-mask]').forEach(function (el) {
    if (el.value) el.value = applyMask(el.dataset.mask, el.value);
  });

  /* ---------- Клетки под цифры: [data-digits] ----------
     Номер аккаунта раньше вводили строкой: человек должен был знать формат
     наизусть, сам поставить тире и сам посчитать контрольную цифру — а узнать
     об ошибке уже после отправки. Клетки задают длину собой, лишнюю цифру
     некуда поставить, а контрольная считается на месте и показывается
     отдельно: её не набирают, на неё смотрят.

     Считаем по Луну — тем же алгоритмом, что на сервере. Сервер остаётся
     главным: он пересчитывает при сохранении, здесь только показ. */
  function luhnCheckDigit(body) {
    var sum = 0, dbl = true;
    for (var i = body.length - 1; i >= 0; i--) {
      var d = +body[i];
      if (dbl) { d *= 2; if (d > 9) d -= 9; }
      sum += d;
      dbl = !dbl;
    }
    return String((10 - (sum % 10)) % 10);
  }

  document.querySelectorAll('[data-digits]').forEach(function (box) {
    var len = +box.dataset.len || 9;
    var body = len - 1;                       // последняя клетка — контрольная
    var hidden = box.querySelector('input[type="hidden"]');
    var cells = box.querySelector('.digit-boxes');
    var status = box.parentElement.querySelector('[data-digit-status]');
    if (!cells || !hidden) return;

    var start = (hidden.value || '').replace(/\D+/g, '').slice(0, body);
    for (var i = 0; i < body; i++) {
      var inp = document.createElement('input');
      inp.className = 'digit-cell';
      inp.type = 'text';
      inp.inputMode = 'numeric';
      inp.maxLength = 1;
      inp.value = start[i] || '';
      inp.setAttribute('aria-label', L('digit', 'Цифра :n').replace(':n', String(i + 1)));
      cells.appendChild(inp);
      /* Разделитель после четвёртой: тот же вид, что и при показе номера
         (4827-13905) — иначе набранное не совпадает с тем, что человек
         сверяет глазами. */
      if (i === 3) {
        var dash = document.createElement('span');
        dash.className = 'digit-dash';
        dash.textContent = '–';
        cells.appendChild(dash);
      }
    }
    /* Контрольная — такая же клетка, только запертая и другого цвета.
       Подпись «контрольная» рядом была лишней: клетка, в которую нельзя
       печатать, объясняет себя сама, а строка текста ломала ряд. */
    var checkOut = document.createElement('input');
    checkOut.className = 'digit-cell digit-cell-check';
    checkOut.type = 'text';
    checkOut.readOnly = true;
    checkOut.tabIndex = -1;
    checkOut.value = '';
    checkOut.setAttribute('aria-label', L('checkDigit', 'Контрольная цифра, считается сама'));
    cells.appendChild(checkOut);

    function collect() {
      var v = '';
      /* Контрольная в сборе не участвует: её считают, а не набирают. */
      cells.querySelectorAll('.digit-cell:not(.digit-cell-check)').forEach(function (c) { v += c.value; });
      return v;
    }
    function refresh() {
      var v = collect();
      var full = v.length === body;
      var check = full ? luhnCheckDigit(v) : '';
      if (checkOut) checkOut.value = full ? check : '';
      hidden.value = full ? v + check : '';
      if (status) {
        if (!full) {
          status.textContent = L('typeDigits', 'Наберите :n цифр — последняя посчитается сама.').replace(':n', String(body));
          status.className = 'hint mt-4';
        } else if (v[0] === '0') {
          status.textContent = L('firstZero', 'Первая цифра не может быть нулём: номер потеряет её при копировании.');
          status.className = 'hint mt-4 text-danger';
          hidden.value = '';
        } else {
          status.textContent = L('wholeNumber', 'Номер целиком: :n').replace(':n', v.slice(0, 4) + '-' + v.slice(4) + check);
          status.className = 'hint mt-4 text-success';
        }
      }
    }

    cells.addEventListener('input', function (e) {
      var c = e.target;
      if (!c.classList.contains('digit-cell') || c.classList.contains('digit-cell-check')) return;
      c.value = (c.value || '').replace(/\D+/g, '').slice(-1);
      if (c.value) {
        var next = c.nextElementSibling;
        while (next && (!next.classList.contains('digit-cell') || next.classList.contains('digit-cell-check'))) next = next.nextElementSibling;
        if (next) next.focus();
      }
      refresh();
    });
    cells.addEventListener('keydown', function (e) {
      var c = e.target;
      if (!c.classList.contains('digit-cell') || c.classList.contains('digit-cell-check')) return;
      /* Backspace в пустой клетке уводит назад: иначе стирать набранное
         приходится мышью, клетка за клеткой. */
      if (e.key === 'Backspace' && !c.value) {
        var prev = c.previousElementSibling;
        while (prev && !prev.classList.contains('digit-cell')) prev = prev.previousElementSibling;
        if (prev) { prev.focus(); prev.value = ''; refresh(); e.preventDefault(); }
      }
    });
    /* Вставка целого номера из буфера: раскладываем по клеткам, а не
       вписываем всё в первую. */
    cells.addEventListener('paste', function (e) {
      var text = (e.clipboardData || window.clipboardData).getData('text') || '';
      var digits = text.replace(/\D+/g, '').slice(0, body);
      if (!digits) return;
      e.preventDefault();
      var list = cells.querySelectorAll('.digit-cell:not(.digit-cell-check)');
      for (var i = 0; i < list.length; i++) list[i].value = digits[i] || '';
      refresh();
    });
    /* Кнопка «Подобрать»: номер выводится из sub и уровня доступа, поэтому
       у одного человека он всегда один и тот же — нажатие не лотерея. Считает
       сервер и кладёт значение в data-suggest при рендере: повторять здесь
       вывод номера значило бы завести вторую реализацию. */
    var suggestBtn = box.querySelector('[data-digit-suggest]');
    if (suggestBtn) {
      suggestBtn.addEventListener('click', function () {
        var v = (box.dataset.suggest || '').replace(/\D+/g, '');
        if (v.length !== len) return;
        var list = cells.querySelectorAll('.digit-cell:not(.digit-cell-check)');
        for (var i = 0; i < list.length; i++) list[i].value = v[i] || '';
        refresh();
      });
    }
    refresh();
  });


  /* ---------- «Довести до дела»: ?do=<id> ----------
     Пункт палитры «Подключить сервис» высаживал человека на список порталов и
     оставлял там: мастер — модалка, а адреса у неё нет. То же было со всеми
     действиями палитры — они вели на страницу, где действие где-то есть.

     Один механизм на всё: адрес несёт `?do=id`, а страница по прибытии либо
     открывает оверлей с этим id, либо подводит к блоку и подсвечивает его.
     Ничего не знать заранее не нужно — работает с любым будущим действием.

     Параметр из адреса убираем сразу: перезагрузка страницы не должна
     открывать мастер второй раз, а ссылка из истории — открывать его через
     неделю. */
  (function () {
    var target = new URLSearchParams(location.search).get('do');
    if (!target) return;
    var el = document.getElementById(target);
    history.replaceState(null, '', location.pathname + location.hash);
    if (!el) return;

    var OVERLAY = 'modal-backdrop drawer-backdrop palette-backdrop pass-sheet'.split(' ');
    var isOverlay = OVERLAY.some(function (c) { return el.classList.contains(c); });
    if (isOverlay) {
      el.classList.add('open');
      if (el.classList.contains('pass-sheet')) el.classList.add('is-opening');
      return;
    }
    /* Не оверлей — значит блок на странице. Подводим и помечаем: без пометки
       человек оказывается посреди длинной страницы и ищет глазами, ради чего
       его сюда привели. */
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    el.classList.add('is-called');
    setTimeout(function () { el.classList.remove('is-called'); }, 2000);
    var focusable = el.querySelector('input:not([type=hidden]), select, textarea, button');
    if (focusable) setTimeout(function () { focusable.focus({ preventScroll: true }); }, 350);
  })();


  /* ---------- После неудачной отправки — курсор в первое непринятое поле ----
     Форма возвращается тем же POST-ом, поля с ошибками покрашены и объяснены,
     а фокус при этом лежит в начале страницы. Человеку с клавиатурой надо
     пройти табуляцией всю навигацию и половину формы, чтобы добраться до
     места, где он ошибся, — при том, что система уже знает это место.
     Браузер так делает сам при своей проверке; здесь проверка серверная, и
     поведение приходится повторить.

     Опираемся на aria-invalid, а не на класс: это признак СОСТОЯНИЯ, который
     мы и так обязаны выставлять, и второй правды о том, какое поле не принято,
     не заводится. Прокрутка при этом уводит поле из-под островка — за это
     отвечает scroll-padding-top у html. */
  (function () {
    var bad = document.querySelector('[aria-invalid="true"]');
    if (!bad) return;
    /* Небольшая задержка: автофокус на первом поле формы ставится браузером
       раньше, и без неё он перебивал бы наш выбор. */
    setTimeout(function () { bad.focus(); }, 0);
  })();

  /* ---------- Отбор строк в таблице: [data-filter="#id-таблицы"] ----------
     Список людей сервиса бывает на двести строк, и найти в нём нужного значит
     проскроллить глазами. Отбор идёт по уже отрисованным строкам, без запроса
     на сервер: страница целиком здесь, спрашивать её заново не за чем.

     Поле лежит в разметке с `hidden` и открывается ЭТИМ кодом. Без такого
     порядка получается мёртвый орган: при выключенном JS человек видит поле,
     печатает в него и ничего не происходит. А заодно поле не показывается там,
     где искать не в чем: пять строк видно целиком, и отбор в них — лишний
     элемент управления на экране.

     Сравнение по всему тексту строки: имя, почта, логин и роли лежат в разных
     ячейках, а человек ищет по тому, что помнит, — и помнит он не колонку. */
  (function () {
    var MIN_ROWS = 8;
    document.querySelectorAll('[data-filter]').forEach(function (input) {
      var table = document.querySelector(input.getAttribute('data-filter'));
      if (!table) return;
      var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
      if (rows.length < MIN_ROWS) return;

      var empty = table.querySelector('[data-filter-empty]');
      if (empty) { rows = rows.filter(function (r) { return r !== empty; }); }

      /* Текст строки считаем один раз: на двухстах строках пересчитывать его
         на каждое нажатие клавиши — это заметная задержка ввода.

         Из текста выбрасываем органы управления. Иначе выходит нелепость,
         которую я поймал замером: в строке лежит выпадающий список ролей, и
         в нём перечислены ВСЕ роли сервиса — поиск по «auditor» показывал
         двенадцать строк из двенадцати. Искать надо по данным строки, а не по
         подписям кнопок и вариантам списка, которые у всех одинаковы. */
      var hay = rows.map(function (r) {
        var clone = r.cloneNode(true);
        clone.querySelectorAll('select, option, button').forEach(function (n) { n.remove(); });
        return (clone.textContent || '').toLowerCase();
      });

      input.hidden = false;
      input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        var shown = 0;
        rows.forEach(function (r, i) {
          var hit = q === '' || hay[i].indexOf(q) !== -1;
          r.hidden = !hit;
          if (hit) shown++;
        });
        if (empty) empty.hidden = shown !== 0;
      });
    });
  })();

  /* Редактор обложки: блоки видео и YouTube — только у выбранного вида, блок
     картинок — всегда (они заставка видео). Без скрипта видно всё. */
  /* Редакторов на странице бывает два — обложка и фон страниц (v4.84): у
     каждого своя приставка полей. */
  document.querySelectorAll('[data-cover-edit]').forEach(function (box) {
    var kindName = (box.getAttribute('data-cover-edit') || 'cover') + '_kind';
    function sync() {
      var k = box.querySelector('input[name="' + kindName + '"]:checked');
      var kind = k ? k.value : 'images';
      box.querySelectorAll('[data-cover-for]').forEach(function (el) {
        el.hidden = (el.getAttribute('data-cover-for') || '').split(' ').indexOf(kind) < 0;
      });
    }
    box.addEventListener('change', function (e) { if (e.target.name === kindName) sync(); });
    sync();
  });

  /* ---------- Обложка панели входа: галерея, видео, YouTube (v4.81) ----------
     В разметке нет ни адреса видео, ни фрейма YouTube: их подключает этот код
     и только когда панель видна (на телефоне и в окне портала её нет — видео
     не качается впустую) и человек не просил «без движения». Иначе стоит
     первая картинка. В предпросмотре редактора — тоже она: фрейм
     предпросмотра перерисовывается на каждую правку. */
  /* Один оживитель на панель входа и на фон страниц (v4.84): videoOk решает,
     можно ли подключать видео и YouTube здесь и сейчас. */
  function liveCover(pane, videoOk) {
    var still = window.matchMedia('(prefers-reduced-motion: reduce)');
    var wide = window.matchMedia('(min-width: 1024px)');
    function start() {
      if (pane.dataset.coverLive || pane.getClientRects().length === 0 || pane.offsetWidth === 0) return;
      pane.dataset.coverLive = '1';
      if (still.matches) {
        /* Без движения: видео без картинки-заставки показывает свой первый
           кадр — стоя, не играя. Иначе панель была бы пустой. */
        pane.querySelectorAll('video[data-cover-video]').forEach(function (v) {
          if (!videoOk() || (v.previousElementSibling && v.previousElementSibling.tagName === 'IMG')) return;
          v.preload = 'metadata';
          v.src = v.getAttribute('data-cover-video');
        });
        return;
      }
      pane.querySelectorAll('[data-cover-slides]').forEach(function (box) {
        var imgs = Array.prototype.slice.call(box.querySelectorAll('img'));
        var every = (parseInt(box.getAttribute('data-cover-slides'), 10) || 8) * 1000;
        var at = 0;
        /* Следующий кадр грузится заранее и встаёт, только когда готов:
           иначе сменой показалась бы пустота. */
        function load(img) {
          if (img && img.dataset.src) { img.src = img.dataset.src; img.removeAttribute('data-src'); }
        }
        load(imgs[1]);
        setInterval(function () {
          var next = imgs[(at + 1) % imgs.length];
          load(next);
          if (!next.complete || next.naturalWidth === 0) return;
          imgs[at].classList.remove('is-on');
          at = (at + 1) % imgs.length;
          next.classList.add('is-on');
          load(imgs[(at + 1) % imgs.length]);
        }, every);
      });
      /* Кнопка звука (v4.83): видео стартует без звука — со звуком браузер его
         не запустит вовсе, — а включает звук сам посетитель. */
      var soundBtn = pane.querySelector('[data-cover-sound]');
      function soundReady(toggle) {
        if (!soundBtn || soundBtn.dataset.ready) return;
        soundBtn.dataset.ready = '1';
        soundBtn.hidden = false;
        soundBtn.addEventListener('click', function () {
          var on = soundBtn.getAttribute('aria-pressed') !== 'true';
          toggle(on);
          soundBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
          soundBtn.setAttribute('aria-label', soundBtn.getAttribute(on ? 'data-on' : 'data-off') || '');
        });
      }
      pane.querySelectorAll('video[data-cover-video]').forEach(function (v) {
        if (!videoOk()) return;
        v.src = v.getAttribute('data-cover-video');
        v.removeAttribute('data-cover-video');
        v.addEventListener('playing', function () {
          soundReady(function (on) { v.muted = !on; if (on) { v.volume = 0.7; v.play(); } });
        });
        var p = v.play();
        if (p && p.catch) p.catch(function () { /* автозапуск запрещён — стоит заставка */ });
      });
      /* YouTube — через API встраивания, без его скрипта: команды и события
         ходят сообщениями фрейма. Повтор — перемоткой, а не плейлистом из
         одного ролика: плейлист рисовал кнопки «назад · вперёд». Заставка лежит
         поверх плеера и уходит, когда тот отыграл свои подписи (~3 с), а на
         повторе возвращается на те же секунды. */
      pane.querySelectorAll('[data-cover-yt]').forEach(function (box) {
        if (!videoOk()) return;
        var id = box.getAttribute('data-cover-yt');
        if (!/^[A-Za-z0-9_-]{11}$/.test(id)) return;
        var yt = 'https://www.youtube-nocookie.com';
        var f = document.createElement('iframe');
        f.src = yt + '/embed/' + id + '?autoplay=1&mute=1&controls=0&playsinline=1&rel=0&disablekb=1&fs=0'
          + '&iv_load_policy=3&modestbranding=1&enablejsapi=1&origin=' + encodeURIComponent(location.origin);
        f.title = box.getAttribute('data-cover-title') || '';
        f.allow = 'autoplay; encrypted-media; picture-in-picture';
        f.tabIndex = -1;
        f.setAttribute('aria-hidden', 'true');
        f.setAttribute('referrerpolicy', 'strict-origin');
        function cmd(func, args) {
          try { f.contentWindow.postMessage(JSON.stringify({ event: 'command', func: func, args: args || [] }), yt); } catch (err) { /* фрейм ушёл */ }
        }
        var heard = false;
        window.addEventListener('message', function (e) {
          if (e.origin !== yt || e.source !== f.contentWindow) return;
          var d = null;
          try { d = JSON.parse(e.data); } catch (err) { return; }
          if (!d || typeof d !== 'object') return;
          heard = true;
          var info = d.info && typeof d.info === 'object' ? d.info : {};
          var state = d.event === 'onStateChange' ? d.info : info.playerState;
          if (state === 0) { box.classList.remove('is-playing'); cmd('seekTo', [0, true]); cmd('playVideo'); return; }
          if (typeof info.currentTime === 'number') {
            box.classList.toggle('is-playing', info.currentTime >= 3);
            if (info.currentTime >= 3) soundReady(function (on) { if (on) { cmd('unMute'); cmd('setVolume', [70]); } else { cmd('mute'); } });
          }
        });
        f.addEventListener('load', function () {
          /* «Слушаю» — пока плеер не ответит: до готовности он сообщения теряет. */
          var tries = 0;
          var hello = setInterval(function () {
            if (heard || ++tries > 40) { clearInterval(hello); return; }
            try { f.contentWindow.postMessage(JSON.stringify({ event: 'listening', id: 1, channel: 'widget' }), yt); } catch (err) { /* пусто */ }
          }, 250);
          /* Плеер не заговорил — показываем видео как есть, чем держать заставку вечно. */
          setTimeout(function () { if (!heard) box.classList.add('is-playing'); }, 10000);
        });
        box.appendChild(f);
      });
    }
    start();
    if (!pane.dataset.coverLive && wide.addEventListener) wide.addEventListener('change', start);
  }
  onPage(function () {
    var pane = document.querySelector('.auth-split-media');
    if (!pane || document.body.classList.contains('is-preview')) return;
    liveCover(pane, function () { return true; });
  });

  /* ---------- Фон страниц (v4.84) ----------
     Картинки — на любом экране; видео и YouTube — только на широком и не при
     экономии трафика: фон за формой на телефоне не стоит мегабайт. Параллакс —
     если владелец включил, мышью и не при «без движения»: фон чуть смещается за
     курсором, как окно, в которое смотришь под другим углом. */
  onPage(function () {
    var bd = document.querySelector('[data-backdrop]');
    if (!bd || bd.dataset.live || document.body.classList.contains('is-preview')) return;
    bd.dataset.live = '1';
    var wideNow = window.matchMedia('(min-width: 1024px)');
    var saveData = !!(navigator.connection && navigator.connection.saveData);
    /* Во фрейме портала — только картинки (v4.85): видео и YouTube там не место. */
    var framed = document.documentElement.hasAttribute('data-frame-origin');
    liveCover(bd, function () { return wideNow.matches && !saveData && !framed; });
    var media = bd.querySelector('.pb-media');
    if (!media || !bd.hasAttribute('data-parallax')) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
    var tx = 0, ty = 0, x = 0, y = 0, raf = 0;
    function step() {
      x += (tx - x) * 0.08;
      y += (ty - y) * 0.08;
      media.style.transform = 'translate3d(' + x.toFixed(2) + 'px,' + y.toFixed(2) + 'px,0)';
      raf = Math.abs(tx - x) > 0.1 || Math.abs(ty - y) > 0.1 ? requestAnimationFrame(step) : 0;
    }
    window.addEventListener('pointermove', function (e) {
      tx = (e.clientX / window.innerWidth - 0.5) * -18;
      ty = (e.clientY / window.innerHeight - 0.5) * -18;
      if (!raf) raf = requestAnimationFrame(step);
    }, { passive: true });
  });

})();
