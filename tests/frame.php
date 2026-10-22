<?php
/**
 * Вход окном на странице портала — экран входа SSO во фрейме (v4.73).
 *
 * Живой случай 24.09: владелец ждал окно прямо на странице портала, как у
 * SocialLib, а получил отдельное окно браузера. Здесь — всё, что делает SSO,
 * прогоном через сервер с заголовками, которые шлёт браузер во фрейме
 * (Sec-Fetch-Dest: iframe, Sec-Fetch-Site): своя пара cookie на чужом сайте,
 * встраивание только этим порталом и только на экранах входа, адрес возврата
 * отдаётся странице портала, отказ — тоже; браузер не пустил cookie — отдельное
 * окно. И оформление: портал, приславший один акцент, получает его цвет.
 *
 * Запуск: php tests/frame.php  (нужен dev-сервер на :8710; SSO=… — другой)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Bridge;
use App\Core\DB;
use App\Core\Setting;
use App\Models\Client;
use App\Models\User;

define('SSO', getenv('SSO') ?: 'http://localhost:8710');
$JAR = sys_get_temp_dir() . '/frame-admin.txt';
/* Имя cookie — из конфига установки (с 4.89 по умолчанию «sso», у старых — «tsoyid»). */
$ck = (string)App\Core\Config::get('session.name', 'sso');
$passed = 0;
$failed = 0;

$was = Setting::get('policy.welcome_screen');
Setting::set('policy.welcome_screen', '0');
Setting::flush();
register_shutdown_function(static function () use ($was): void {
    Setting::set('policy.welcome_screen', $was);
    Setting::flush();
});
DB::run('DELETE FROM {rate_limits}');

const PORTAL = 'https://frame-portal.example';
DB::run('DELETE FROM {clients} WHERE slug = ?', ['frame-probe']);
[$cid, $clientId] = Client::create([
    'name' => 'Проба окна', 'slug' => 'frame-probe', 'base_url' => PORTAL,
    'redirect_uris' => [PORTAL . '/sso/callback.php'], 'post_logout_uris' => [],
    'backchannel_uri' => null, 'allowed_scopes' => ['openid', 'profile', 'email'],
    'is_trusted' => 1, 'access_policy' => 'open',
]);
$cid = (int)$cid;
Client::update($cid, ['login_display' => 'popup', 'login_welcome' => 0]);

$email = 'frame' . substr((string)time(), -6) . '@test.local';
DB::run('DELETE FROM {users} WHERE email = ?', [$email]);
User::create(['email' => $email, 'first_name' => 'Окно', 'status' => 'active', 'email_verified_at' => now(),
    'password_hash' => password_hash('correct-horse-battery', PASSWORD_ARGON2ID)]);

$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$authUrl = static fn(array $extra = []): string => SSO . '/authorize?' . http_build_query($extra + [
    'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => PORTAL . '/sso/callback.php',
    'scope' => 'openid profile', 'state' => 'st-frame', 'nonce' => 'n', 'code_challenge' => $challenge,
    'code_challenge_method' => 'S256', 'display' => 'frame']);
/* Заголовки фрейма: первый запрос пришёл со страницы портала (другой сайт),
   дальше фрейм ходит по своему адресу (same-origin). */
$crossFrame = ['Sec-Fetch-Dest: iframe', 'Sec-Fetch-Site: cross-site', 'Sec-Fetch-Mode: navigate'];
$inFrame = ['Sec-Fetch-Dest: iframe', 'Sec-Fetch-Site: same-origin', 'Sec-Fetch-Mode: navigate'];
$csp = static fn(string $h): string => preg_match('~^Content-Security-Policy:\s*(.+)$~mi', $h, $m) ? trim($m[1]) : '';
$ancestors = static fn(string $h): string => preg_match('~frame-ancestors ([^;]+)~', $h, $m) ? trim($m[1]) : '';
$cookies = static function (string $h): array {
    preg_match_all('~^Set-Cookie:\s*([^=;]+)=[^;]*;(.*)$~mi', $h, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $c) $out[trim($c[1])] = strtolower($c[2]);
    return $out;
};

echo "── Вход во фрейме на странице портала другого сайта ──\n";
$fjar = sys_get_temp_dir() . '/frame-cross.txt';
@unlink($fjar);
[$st, , $loc, , $h] = http('GET', $authUrl(), [], $crossFrame, $fjar);
ok($st === 302 && str_contains($loc, '/login?rt='), '/authorize ведёт на вход с ключом возврата', "$st $loc");
$set = $cookies($h);
ok(isset($set[$ck . '_fs']) && !isset($set[$ck . '_s']), 'служебная сессия — своя cookie фрейма, не общая', implode(',', array_keys($set)));
ok(str_contains($set[$ck . '_fs'] ?? '', 'samesite=none') && str_contains($set[$ck . '_fs'] ?? '', 'partitioned')
    && str_contains($set[$ck . '_fs'] ?? '', 'secure'), 'cookie фрейма: SameSite=None; Partitioned; Secure', $set[$ck . '_fs'] ?? '');

$loginUrl = str_starts_with($loc, 'http') ? $loc : SSO . $loc;
[$st, $page, , , $h] = http('GET', $loginUrl, [], $inFrame, $fjar);
ok($st === 200 && str_contains($page, 'name="password"'), 'во фрейме — экран входа', "status=$st");
ok($ancestors($h) === PORTAL, 'встраивать может только этот портал', $ancestors($h));
ok(!preg_match('~^X-Frame-Options~mi', $h), 'X-Frame-Options не запрещает то, что разрешил CSP');
ok(str_contains($page, 'data-frame-origin="' . PORTAL . '"') && str_contains($page, 'class="popup"'),
    'компактная раскладка, сообщения — только на адрес портала');

[$st, , $loc, , $h] = http('POST', SSO . '/login', ['_csrf' => csrfOf($page), 'identity' => $email,
    'password' => 'correct-horse-battery', 'rt' => preg_match('~name="rt" value="([^"]+)"~', $page, $rm) ? $rm[1] : ''],
    $inFrame, $fjar);
ok($st === 302 && str_contains($loc, '/authorize'), 'вход паролем во фрейме — назад в авторизацию', "$st $loc");
$set = $cookies($h);
ok(isset($set[$ck . '_f']) && !isset($set[$ck]) && str_contains($set[$ck . '_f'], 'partitioned'),
    'вход держится на своей cookie фрейма', implode(',', array_keys($set)));
[$st, $hand, , , $h] = http('GET', str_starts_with($loc, 'http') ? $loc : SSO . $loc, [], $inFrame, $fjar);
ok($st === 200 && str_contains($hand, 'data-handoff'), 'в конце фрейм не уходит на портал, а отдаёт адрес странице', "status=$st");
$href = preg_match('~href="([^"]+)"[^>]*data-handoff~', $hand, $m) ? html_entity_decode($m[1]) : '';
ok(str_starts_with($href, PORTAL . '/sso/callback.php?code=') && str_contains($href, 'state=st-frame'),
    'адрес возврата — колбэк портала с кодом и state', substr($href, 0, 90));
ok(str_contains($hand, 'data-origin="' . PORTAL . '"') && str_contains($hand, 'target="_top"'),
    'сообщение — только на адрес портала; не дошло — кнопка уводит всю вкладку');
ok($ancestors($h) === PORTAL, 'страницу возврата тоже встраивает только портал', $ancestors($h));

echo "\n── Вне входа фреймов нет ──\n";
/* Пока вход во фрейме идёт (новый запрос с prompt=login), кабинет в той же
   сессии встраивать всё равно нельзя: разрешение — только экранам входа. */
http('GET', $authUrl(['prompt' => 'login']), [], $inFrame, $fjar);
[, , , , $h] = http('GET', SSO . '/profile', [], $inFrame, $fjar);
ok($ancestors($h) === "'none'", 'кабинет в той же сессии фрейма встроить нельзя', $ancestors($h));
$pjar = sys_get_temp_dir() . '/frame-plain.txt';
@unlink($pjar);
[, , , , $h] = http('GET', SSO . '/login', [], [], $pjar);
ok($ancestors($h) === "'none'" && (bool)preg_match('~^X-Frame-Options: DENY~mi', $h), 'обычный вход — фреймы запрещены, как раньше');
$set = $cookies($h);
ok(isset($set[$ck . '_s']) && str_contains($set[$ck . '_s'], 'samesite=lax') && !str_contains($set[$ck . '_s'], 'partitioned'),
    'обычная сессия — прежняя cookie Lax', implode(',', array_keys($set)));

echo "\n── Портал на том же сайте — общая сессия ──\n";
$sjar = sys_get_temp_dir() . '/frame-same.txt';
@unlink($sjar);
[$st, , $loc, , $h] = http('GET', $authUrl(), [], ['Sec-Fetch-Dest: iframe', 'Sec-Fetch-Site: same-site'], $sjar);
$set = $cookies($h);
ok($st === 302 && isset($set[$ck . '_s']) && !isset($set[$ck . '_fs']), 'свой сайт — обычные cookie: вход в SSO общий', implode(',', array_keys($set)));
[, , , , $h] = http('GET', str_starts_with($loc, 'http') ? $loc : SSO . $loc, [], $inFrame, $sjar);
ok($ancestors($h) === PORTAL, 'и встраивает тот же портал');

echo "\n── Браузер не пустил cookie во фрейм ──\n";
[, , $loc] = http('GET', $authUrl(), [], $crossFrame, sys_get_temp_dir() . '/frame-none-a.txt');
@unlink(sys_get_temp_dir() . '/frame-none-b.txt');
[$st, $page, , , $h] = http('GET', str_starts_with($loc, 'http') ? $loc : SSO . $loc, [], $inFrame, sys_get_temp_dir() . '/frame-none-b.txt');
ok($st === 200 && str_contains($page, 'data-frame-window') && !str_contains($page, 'name="password"'),
    'вместо формы — «откроется отдельным окном» с кнопкой', "status=$st");
ok($ancestors($h) === PORTAL, 'эту страницу портал тоже может показать', $ancestors($h));

echo "\n── Отказ из фрейма уходит странице портала ──\n";
[$st, $page, , , $h] = http('GET', $authUrl(['response_type' => 'token']), [], $crossFrame, sys_get_temp_dir() . '/frame-err.txt');
$href = preg_match('~href="([^"]+)"[^>]*data-handoff~', $page, $m) ? html_entity_decode($m[1]) : '';
ok($st === 200 && str_starts_with($href, PORTAL . '/sso/callback.php?error=invalid_request'),
    'ошибка авторизации — адрес с error отдаётся странице, а не открывается во фрейме', "$st " . substr($href, 0, 80));
ok($ancestors($h) === PORTAL, 'и её тоже встраивает только портал');

echo "\n── «Вы вошли» во фрейме ──\n";
Client::update($cid, ['login_welcome' => 1]);
$wjar = sys_get_temp_dir() . '/frame-welcome.txt';
@unlink($wjar);
[, , $loc] = http('GET', $authUrl(), [], $crossFrame, $wjar);
$loginUrl = str_starts_with($loc, 'http') ? $loc : SSO . $loc;
[, $page] = http('GET', $loginUrl, [], $inFrame, $wjar);
[, , $loc] = http('POST', SSO . '/login', ['_csrf' => csrfOf($page), 'identity' => $email, 'password' => 'correct-horse-battery'], $inFrame, $wjar);
[$st, $page] = http('GET', str_starts_with($loc, 'http') ? $loc : SSO . $loc, [], $inFrame, $wjar);
ok($st === 200 && str_contains($page, 'welcome-who') && str_contains($page, 'data-delay="1400"')
    && str_contains($page, 'data-origin="' . PORTAL . '"'), '«Вы вошли» во фрейме — и адрес уходит странице портала', "status=$st");
Client::update($cid, ['login_welcome' => 0]);

echo "\n── Вне фрейма поток прежний ──\n";
$njar = sys_get_temp_dir() . '/frame-normal.txt';
@unlink($njar);
http('GET', $authUrl(['display' => 'page']), [], [], $njar);
[, $page] = http('GET', SSO . '/login', [], [], $njar);
[, , $loc] = http('POST', SSO . '/login', ['_csrf' => csrfOf($page), 'identity' => $email, 'password' => 'correct-horse-battery'], [], $njar);
[$st, , $loc] = http('GET', str_starts_with($loc, 'http') ? $loc : SSO . $loc, [], [], $njar);
ok($st === 302 && str_starts_with($loc, PORTAL . '/sso/callback.php?code='), 'страницей — прежний 302 на колбэк портала', "$st");

echo "\n── Переходы во фрейме — запросами, без новых документов (v4.75) ──\n";
/* Chrome 153 с блокировщиком рекламы роняет процесс фрейма на каждом новом
   документе во фрейме: первая загрузка окна проходила, падал любой щелчок
   (одиннадцать дампов 25.09 на Портфолио и Архиве). Во фрейме app.js ходит
   fetch'ем и подменяет содержимое — сервер должен отвечать на такие запросы
   тем же, что и на переход, и разрешать встраивание ровно там же: скрипт
   подменяет только ответ, чей frame-ancestors пускает этот портал. */
$fetchHdr = ['Sec-Fetch-Dest: empty', 'Sec-Fetch-Site: same-origin', 'Sec-Fetch-Mode: same-origin'];
$xjar = sys_get_temp_dir() . '/frame-fetch.txt';
@unlink($xjar);
[, , $loc] = http('GET', $authUrl(), [], $crossFrame, $xjar);
$loginUrl = str_starts_with($loc, 'http') ? $loc : SSO . $loc;
[, $page] = http('GET', $loginUrl, [], $inFrame, $xjar);
$rt = preg_match('~name="rt" value="([^"]+)"~', $page, $rm) ? $rm[1] : '';
[$st, $reg, , , $h] = http('GET', SSO . '/register?rt=' . urlencode($rt), [], $fetchHdr, $xjar);
ok($st === 200 && str_contains($reg, 'name="first_name"') && $ancestors($h) === PORTAL,
    'вкладка «Регистрация» запросом — экран, который встраивает только этот портал', "status=$st fa=" . $ancestors($h));
[$st, , $loc, , $h] = http('POST', SSO . '/login', ['_csrf' => csrfOf($page), 'identity' => $email,
    'password' => 'correct-horse-battery', 'rt' => $rt], $fetchHdr, $xjar);
ok($st === 302 && str_contains($loc, '/authorize'), 'вход паролем запросом — та же переадресация', "$st $loc");
[$st, $hand, , , $h] = http('GET', str_starts_with($loc, 'http') ? $loc : SSO . $loc, [], $fetchHdr, $xjar);
ok($st === 200 && str_contains($hand, 'data-handoff') && str_contains($hand, 'data-origin="' . PORTAL . '"')
    && $ancestors($h) === PORTAL, 'в конце — страница возврата для портала, и её можно подменить во фрейме', "status=$st");
[$st, , , , $h] = http('GET', SSO . '/profile', [], $fetchHdr, $xjar);
ok(!str_contains($ancestors($h), PORTAL), 'кабинет порталу встраивать нельзя — и подменить им экран фрейма тоже',
    "status=$st fa=" . $ancestors($h));

$appJs = (string)file_get_contents(SSO_ROOT . '/public/assets/js/app.js');
$from = (int)strpos($appJs, 'Переходы во фрейме — без новых документов');
$swap = substr($appJs, $from, (int)strpos($appJs, '---------- PWA', $from) - $from);
ok(str_contains($swap, "fetch(url, Object.assign({ credentials: 'same-origin', mode: 'same-origin' }, init || {}))")
    && str_contains($swap, 'document.body.innerHTML = next.body.innerHTML')
    && str_contains($swap, 'history.replaceState(null, \'\', url)'),
    'app.js: во фрейме ссылки и формы идут запросом, ответ подменяет содержимое того же документа');
ok(!preg_match('~location\.(href\s*=|assign|replace)|\.submit\(\)~', $swap),
    'app.js: во фрейме нет ни одного перехода на новый документ');
ok(str_contains($swap, "if (!framable(r)) throw new Error('not-framable');")
    && str_contains($swap, "fa[1].split(/\\s+/).indexOf(frameOrigin) !== -1"),
    'app.js: подменяется только ответ, который разрешил встраивать себя этому порталу');
ok(str_contains($swap, "mode: 'same-origin'"), 'app.js: переадресация на чужой адрес обрывается до запроса туда');
ok(str_contains($appJs, "if (form) submitForm(form);") && str_contains($appJs, 'if (form.requestSubmit) form.requestSubmit(); else form.submit();'),
    'app.js: код из шести цифр отправляется событием submit — его перехватывает подмена');
ok(substr_count($appJs, 'onPage(function () { document.querySelectorAll(') >= 2,
    'app.js: поле кода и возврат на портал оживают и после подмены экрана');
$pkJs = (string)file_get_contents(SSO_ROOT . '/public/assets/js/passkey.js');
ok(str_contains($pkJs, '(window.ssoGo || function (u) { location.href = u; })(j.redirect)'),
    'passkey.js: после входа во фрейме — подмена, а не новый документ');

echo "\n── Настройка портала ──\n";
Client::update($cid, ['login_display' => 'window']);
$ui = json_decode(http('GET', SSO . '/portal/ui?client_id=' . $clientId)[1], true) ?: [];
ok(($ui['display'] ?? null) === 'window' && !empty($ui['texts']['close']) && !empty($ui['texts']['window']),
    'отдельное окно — свой режим, подписи для окна на странице есть');
$js = (string)file_get_contents(SSO_ROOT . '/public/assets/js/sso.js');
ok(str_contains($js, "'display=frame'") && str_contains($js, "if (ui.display === 'popup' && issuer) { openModal(j.authorize_url, btn); return; }"),
    'sso.js: «окном поверх сайта» — окно на странице с фреймом');
ok(str_contains($js, 'if (!modal || e.origin !== issuer || e.source !== modal.frame.contentWindow) return;'),
    'sso.js: слушает только наш фрейм и только с адреса SSO');
$appJs = (string)file_get_contents(SSO_ROOT . '/public/assets/js/app.js');
ok(str_contains($appJs, "window.parent.postMessage({ type: 'sso:code', url: a.href }, origin);"),
    'app.js: адрес возврата уходит странице портала только на её origin');

echo "\n── Оформление: один акцент — тоже тема ──\n";
Client::update($cid, ['brand_theme' => null, 'brand_accent' => '#2B44C7', 'look_mode' => null]);
$t = Bridge::themeOf(Client::find($cid));
ok(($t['source'] ?? '') === 'portal' && ($t['light']['ink'] ?? '') === '#2B44C7' && !empty($t['dark']['ink']),
    'портал прислал только акцент — кнопки в его цвете в обеих темах', json_encode($t['light'] ?? null));
Client::update($cid, ['look_mode' => 'off']);
ok(Bridge::themeOf(Client::find($cid)) === null || (Bridge::themeOf(Client::find($cid))['source'] ?? '') !== 'portal',
    '«Как у сайта» — цвет портала не применяется');

DB::run('DELETE FROM {clients} WHERE id = ?', [$cid]);
DB::run('DELETE FROM {users} WHERE email = ?', [$email]);

echo "\n" . ($failed === 0 ? "✓ все $passed проверок" : "✗ провалов: $failed из " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
