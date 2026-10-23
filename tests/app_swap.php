<?php
/**
 * Кабинет и админка без перезагрузки (v5.0).
 *
 * Вектор владельца — SSO как лёгкое приложение: переходы без перезагрузки.
 * В 4.97 так ходили только вкладки входа; здесь тем же механизмом — экраны с
 * островком. Проверяется, что:
 *   · подменяются только экраны кабинета и админки; файлы и GET с действием
 *     (выгрузки, отмена, язык, прозвон) идут обычным переходом — и не
 *     подгружаются заранее при наведении;
 *   · ответ не-страница, ошибка, чужая раскладка или экран с долгим скриптом —
 *     обычным переходом;
 *   · островок и док меняются вместе с экраном: в них отмечен раздел;
 *   · то, что настраивается при загрузке, настраивается и после подмены;
 *   · находки ревизии скриптов: высота фрейма, «назад» во время перехода,
 *     гонка поиска, ответ не-JSON, спрайт с версией.
 *
 * Запуск: php tests/app_swap.php  (сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

$passed = 0;
$failed = 0;
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);
$app = $src('public/assets/js/app.js');
$pal = $src('public/assets/js/palette.js');
$pk = $src('public/assets/js/passkey.js');

echo "── Какие переходы подменяются ──\n";
/* Регулярки берём из самого скрипта и прогоняем на адресах: проверка смысла,
   а не написания. */
preg_match('~var APP = (/.+?/);~', $app, $m1);
preg_match('~var NOT_APP = (/.+?/i);~', $app, $m2);
$appRe = isset($m1[1]) ? '~' . substr($m1[1], 1, -1) . '~' : '~^$~';
$notRe = isset($m2[1]) ? '~' . substr($m2[1], 1, -2) . '~i' : '~^~';
$swaps = static fn(string $p): bool => preg_match($appRe, $p) === 1 && preg_match($notRe, $p) !== 1;
$yes = ['/admin', '/admin/users', '/admin/clients/7/people', '/profile', '/profile/sessions'];
$no = ['/login', '/admin/logs/export.csv', '/admin/clients/7/kit.zip', '/admin/tools/backup.zip', '/profile/merge/cancel',
    '/admin/health/probe', '/admin/logs/errors/download', '/lang/en', '/administrator', '/profile/security/recovery.txt'];
ok(array_filter($yes, $swaps) === $yes, 'экраны кабинета и админки подменяются', implode(', ', array_diff($yes, array_filter($yes, $swaps))));
ok(array_filter($no, $swaps) === [], 'файлы, выгрузки и GET с действием — обычным переходом', implode(', ', array_filter($no, $swaps)));
ok(str_contains($app, "if (appShell) return appPath(a.pathname) && !a.hash && !a.hasAttribute('data-download') && !a.hasAttribute('data-no-swap');"),
    'ссылка с якорем, на скачивание или с data-no-swap — документом');
ok(str_contains($app, "if (!a || !tabLink(a) || a.href === location.href || early[a.href]) return;"),
    'заранее подгружается только то, что подменяется: выгрузку наведение не запустит');
ok(str_contains($app, "if (!html || !res.r.ok || !appPath(path) || !/class=\"isle\"/.test(res.html) || NEEDS_LOAD.test(res.html)) { leave(res.r.url); return; }"),
    'файл, ошибка, другая раскладка или экран с долгим скриптом — обычным переходом');

echo "\n── Что меняется при подмене ──\n";
ok(str_contains($app, "['.isle', '.dock'].forEach(function (sel) {"), 'островок и док — вместе с экраном: отмечен новый раздел');
ok(str_contains($app, "b > a ? 'fwd' : (b < a ? 'back' : 'fade')"), 'вглубь — вперёд, наверх — назад, рядом — растворением');
ok(str_contains($app, "main.setAttribute('tabindex', '-1');") && str_contains($app, "main.focus({ preventScroll: true });"),
    'чтение с экрана начинает новый экран с начала');
ok(str_contains($app, "document.querySelectorAll('.menu.open, .modal-backdrop.open, .drawer-backdrop.open, .pass-sheet.open, .palette-backdrop.open')"),
    'открытые меню и окна закрываются до подмены — прокрутка не остаётся запертой');
ok(str_contains($app, "if (appShell) document.addEventListener('submit', function (e) {")
    && str_contains($app, "!== 'get') return;") && str_contains($app, "    submitForm(form);\n  });"),
    'фильтры и поиск (формы GET) — тоже подменой; выбор в фильтре отправляет форму с событием');

echo "\n── Настройка после подмены ──\n";
foreach ([
    'тосты' => "onPage(function () { document.querySelectorAll('.toast').forEach(function (t) {\n    if (t._ssoToast) return;",
    'пачка с галочками' => "onPage(function () { document.querySelectorAll('form[data-bulk]').forEach(bulkSync); });",
    'QR' => "if (typeof qrcode !== 'function' || el.querySelector('svg')) return;",
    'фильтры на телефоне' => "    if (bar._ssoFilters) return;",
    'маски' => "onPage(function () { document.querySelectorAll('[data-mask]').forEach(function (el) {",
    'клетки под цифры' => "    if (box._ssoDigits) return;",
    'матрица прав' => "    if (!form || form._ssoPerm) return;",
    'отбор строк' => "      if (input._ssoFilter) return;",
    'редактор обложки' => "    if (box._ssoCover) return;",
] as $what => $needle) {
    ok(str_contains($app, $needle), "$what — настраиваются и на подменённом экране, ровно один раз");
}
ok(str_contains($app, "history.replaceState(history.state, '', location.pathname + location.hash);"),
    '«?do=» убирается из адреса, не стирая отметку истории — «назад» продолжает подменять');

echo "\n── Находки ревизии скриптов ──\n";
ok(str_contains($app, "var frameWrap = function () { return document.querySelector('.auth-wrap'); };")
    && str_contains($app, "document.addEventListener('sso:page', watchSize);"),
    'во фрейме высота мерится по текущей обёртке, а не по отсоединённой');
ok(str_contains($app, "if (swapBusy) { if (!init) queued = { url: url, push: push }; return; }")
    && str_contains($app, "if (queued) { var q = queued; queued = null; if (q.url !== location.href) go(q.url, null, q.push); }"),
    '«назад» во время перехода не теряется — экран догоняет адрес');
ok(str_contains($app, "p.catch(function () { delete early[a.href]; });"), 'несостоявшаяся подгрузка не всплывает ошибкой');
ok(str_contains($app, "if (!j.ok) { dropFail(j.message); return; }") && str_contains($app, "}).catch(function () { dropFail(); });"),
    'удаление схемы оформления сообщает об отказе и сбое');
ok(str_contains($pal, "if (mine === seq) render(j.groups || []);") && str_contains($pal, "if (!r.ok) throw new Error(String(r.status));"),
    'поиск: рисуется только ответ на последний запрос; ошибка сервера — не «ничего не нашлось»');
ok(!preg_match("~'[^']*[А-Яа-яЁё][^']*'(?![^(]*\\))~u", preg_replace(['~/\*.*?\*/~s', "~L\\('[a-zA-Z]+', '[^']*'\\)~u"], '', $pal)),
    'в палитре нет русских строк мимо словаря');
ok(str_contains($pal, "if (el) el.click();"), 'Enter в палитре открывает раздел той же подменой');
ok(str_contains($pk, "if (!/json/.test(r.headers.get('Content-Type') || '')) throw new Error("),
    'passkey: ответ-страница вместо JSON — понятная ошибка, а не сбой разбора');
ok(str_contains($pal, "return u ? u.getAttribute('href').split('#')[0] : '/assets/icons.svg';")
    && str_contains($app, "<use href=\"' + spriteUrl() + '#i-x\"></use>")
    && !str_contains($pal . $app, '<use href="/assets/icons.svg#'),
    'спрайт в скриптах — с версией, как у иконок страницы: после выпуска не залипает в кэше воркера');

echo "\nПройдено: $passed, провалено: $failed\n";
exit($failed === 0 ? 0 : 1);
