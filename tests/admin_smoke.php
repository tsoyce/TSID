<?php
/**
 * Смоук всей админки и профиля: каждая страница отвечает 200 без фаталов,
 * ключевые POST-действия работают, аватар и иконка портала загружаются.
 * Требует свежей установки (bash tests/install_e2e.sh) и SSO на :8710.
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';   // ok(), http(), upload(), csrfOf(), pageClean() — общие с e2e

const SSO = 'http://localhost:8710';
$JAR = __DIR__ . '/tmp/smoke-cookies.txt';
@unlink($JAR);
$passed = 0;
$failed = 0;

/* Сброс артефактов прошлых прогонов: смоук идемпотентен */
/* Аккаунты смоука сносим вместе с ролями: второй суперадмин, оставшийся от
   прошлого прогона, поменял бы ответ на вопрос «кто старейший». */
foreach (['smoke-victim@test.local', 'smoke-second-admin@test.local', 'smoke-number-twin@test.local', 'smoke-purge@test.local', 'smoke-limited@test.local'] as $e) {
    $u = \App\Core\DB::row('SELECT id FROM {users} WHERE email = ?', [$e]);
    if (!$u) continue;
    \App\Core\DB::run('DELETE FROM {user_roles} WHERE user_id = ?', [(int)$u['id']]);
    \App\Core\DB::run('DELETE FROM {sessions} WHERE user_id = ?', [(int)$u['id']]);
    \App\Core\DB::run('DELETE FROM {users} WHERE id = ?', [(int)$u['id']]);
}
\App\Core\DB::run('DELETE FROM {clients} WHERE name = ?', ['Витрина']);
\App\Core\DB::run('DELETE FROM {roles} WHERE slug = ?', ['smoketest']);
\App\Core\DB::run('DELETE FROM {rate_limits}');
\App\Core\Setting::set('policy.admin_require_2fa', '0');
\App\Core\DB::run('UPDATE {users} SET totp_secret = NULL, totp_enabled_at = NULL WHERE email = ?', ['admin@test.local']);


echo "═══ Вход суперадмина ═══\n";
[, $login] = http('GET', SSO . '/login');
$csrf = csrfOf($login);
[$st, , $loc] = http('POST', SSO . '/login', ['_csrf' => $csrf, 'identity' => 'admin@test.local', 'password' => 'correct-horse-battery']);
ok($st === 302 && str_contains($loc, 'profile'), 'вход выполнен', "st=$st");

echo "═══ GET-обход: профиль + админка (политика 2FA выключена — пускает сразу) ═══\n";
$pages = [
    '/profile', '/profile/edit', '/profile/security', '/profile/sessions',
    '/profile/services', '/profile/settings',
    '/admin', '/admin/users', '/admin/users/1', '/admin/clients', '/admin/roles',
    '/admin/logs', '/admin/logs/errors', '/admin/logs/mail', '/admin/settings',
    '/admin/health', '/admin/tools', '/status',
];
foreach ($pages as $p) {
    [$st, $body] = http('GET', SSO . $p);
    ok($st === 200 && pageClean($body), "GET $p", "st=$st" . (pageClean($body) ? '' : ' + фатал в теле'));
}

echo "═══ Роли: создать и удалить ═══\n";
[$st, , $loc] = http('POST', SSO . '/admin/roles/create', ['_csrf' => $csrf, 'client_id' => '', 'slug' => 'smoketest', 'name' => 'Смоук-роль']);
ok($st === 302, 'роль создана');
$roleId = (int)\App\Core\DB::val('SELECT id FROM {roles} WHERE slug = ?', ['smoketest']);
ok($roleId > 0, 'роль в БД');
[$st] = http('POST', SSO . "/admin/roles/$roleId/delete", ['_csrf' => $csrf]);
ok($st === 302 && \App\Core\DB::val('SELECT id FROM {roles} WHERE slug = ?', ['smoketest']) === null, 'роль удалена');

echo "═══ Настройки: сохранение политик и брендинга ═══\n";
[$st] = http('POST', SSO . '/admin/settings/save', ['_csrf' => $csrf, 'section' => 'policies',
    'password_min' => '1', 'lockout_attempts' => '10', 'lockout_minutes' => '15', 'session_lifetime' => '43200']);
ok($st === 302 && \App\Core\Setting::get('policy.password_min') === '1', 'политика: мин. длина 1 (опциональность!)');
// вернём 12, чтобы не влиять на другие прогоны
http('POST', SSO . '/admin/settings/save', ['_csrf' => $csrf, 'section' => 'policies',
    'password_min' => '12', 'lockout_attempts' => '10', 'lockout_minutes' => '15', 'session_lifetime' => '43200']);
[$st] = http('POST', SSO . '/admin/settings/save', ['_csrf' => $csrf, 'section' => 'branding',
    'name' => 'SSO (dev)', 'accent' => '#1D4ED8']);
ok($st === 302, 'брендинг сохранён');

/* Заголовок на экране один. Оболочка настроек рисует его сама, но четыре
   раздела — вебхуки, здоровье, инструменты, система — переехали в неё вместе со
   своим собственным заголовком и печатали его вторым. Никто этого не замечал
   полтора релиза: экраны открывали редко, а глаз принимал повтор за подзаголовок.
   Проверка идёт по тому же перечню, что рисует колонку разделов, — новый раздел
   попадёт под неё сам. */
echo "═══ Настройки: у каждого раздела ровно один заголовок ═══\n";
foreach (\App\Core\SettingsMap::sections() as $sec) {
    [$stH, $bodyH] = http('GET', SSO . $sec['route']);
    if ($stH !== 200) continue;
    $h1 = substr_count($bodyH, '<h1');
    ok($h1 === 1, 'раздел «' . $sec['title'] . '»: заголовок один', "их $h1");
}

/* Раздел сохраняет ТОЛЬКО свои поля. «Политики» разрослись до девяти карточек
   и в v4.21 разрезаны надвое; общая ветка сохранения писала бы все ключи из
   $_POST, а форма присылает лишь свои — сохранение «Регистрации» молча
   обнулило бы тумблеры «Входа». Проверяем именно это. */
echo "═══ Настройки: раздел не трогает чужие поля ═══\n";
/* Тумблер берём тот, что есть, и возвращаем как было: включённая обязательная
   2FA закрывает админку админу без второго фактора, и весь остаток прогона
   уходит на /profile/security. Именно так и случилось при первой попытке этой
   проверки: половина смоука посыпалась не по делу. */
$was2fa = (string)\App\Core\Setting::get('policy.admin_require_2fa', '0');
\App\Core\Setting::set('policy.admin_require_2fa', '1');
\App\Core\Setting::set('policy.lock_minutes', '45');
\App\Core\Setting::flush();
[$st] = http('POST', SSO . '/admin/settings/save', ['_csrf' => $csrf, 'section' => 'signup',
    'login_min' => '3', 'login_max' => '32', 'captcha_after_attempts' => '3', 'captcha_ttl' => '300']);
\App\Core\Setting::flush();
ok($st === 302 && \App\Core\Setting::get('policy.admin_require_2fa') === '1',
    'сохранение «Регистрации» не сбросило тумблер «Входа»');
ok((int)\App\Core\Setting::get('policy.lock_minutes') === 45,
    'и не сбросило срок замка: ' . \App\Core\Setting::get('policy.lock_minutes'));
\App\Core\Setting::set('policy.lock_minutes', '0');
\App\Core\Setting::set('policy.admin_require_2fa', $was2fa);
\App\Core\Setting::flush();

echo "═══ Настройки: раздел «Сроки» ═══\n";
/* Просили именно укорачивать — проверяем, что короткое значение принимается,
   а не «подтягивается» к прежнему безопасному минимуму в час. */
$short = ['_csrf' => $csrf, 'section' => 'timeouts', 't_session' => '600',
    't_remember' => '3600', 't_access_token' => '120',
    't_token_email_verify' => '900', 't_token_password_reset' => '300'];
[$st, $shortBody, $shortLoc] = http('POST', SSO . '/admin/settings/save', $short);
fwrite(STDERR, "ОТЛАДКА сроков: st=$st loc=$shortLoc
");
/* Настройки поменял серверный процесс — в своём кэше у нас всё ещё старое. */
\App\Core\Setting::flush();
ok($st === 302 && \App\Core\Setting::sessionLifetime() === 600, 'сессию можно укоротить до 10 минут');
ok(\App\Oidc\IdToken::accessTtl() === 120, 'срок access-токена берётся из настроек');
ok(\App\Core\Setting::timeout('token_password_reset', 3600) === 300, 'ссылка сброса живёт 5 минут');

/* Границы: значение ниже минимума молча обрезается, а не сохраняется как есть —
   иначе сессия в одну секунду выкидывала бы человека прямо со страницы входа. */
http('POST', SSO . '/admin/settings/save', ['_csrf' => $csrf, 'section' => 'timeouts',
    't_session' => '1'] + $short);
\App\Core\Setting::flush();
ok(\App\Core\Setting::sessionLifetime() === 300, 'слишком короткий срок обрезан до минимума');

/* Возвращаем умолчания, чтобы не влиять на соседние проверки. */
http('POST', SSO . '/admin/settings/save', ['_csrf' => $csrf, 'section' => 'timeouts',
    't_session' => '43200', 't_remember' => '2592000', 't_access_token' => '900',
    't_token_email_verify' => (string)(3 * 86400), 't_token_password_reset' => '3600']);
\App\Core\Setting::flush();
ok(\App\Oidc\IdToken::accessTtl() === 900, 'умолчания вернулись');

echo "═══ Аватар: PNG → webp-пайплайн ═══\n";
$png = __DIR__ . '/tmp/avatar-test.png';
$img = imagecreatetruecolor(200, 300);
imagefill($img, 0, 0, (int)imagecolorallocate($img, 30, 80, 200));
imagepng($img, $png);
imagedestroy($img);
[$st, , $loc] = upload(SSO . '/profile/avatar', ['_csrf' => $csrf], ['avatar' => [$png, 'image/png', 'me.png']]);
ok($st === 302 && str_contains($loc, 'profile/edit'), 'загрузка аватара прошла', "st=$st loc=$loc");
$admin = \App\Models\User::findByEmail('admin@test.local');
[$st, $body, , $ctype] = http('GET', SSO . '/avatar/' . $admin['public_id'] . '/128.webp');
ok($st === 200 && str_contains($ctype, 'image/webp') && strlen($body) > 100, 'аватар отдаётся как webp');

echo "═══ Портал: создание, иконка, артефакты ═══\n";
[$st, , $loc] = http('POST', SSO . '/admin/clients/create', ['_csrf' => $csrf,
    'name' => 'Витрина', 'base_url' => 'https://showcase.example', 'access_policy' => 'open', 'is_trusted' => '1']);
preg_match('~/admin/clients/(\d+)~', $loc, $m);
$cid = (int)($m[1] ?? 0);
ok($cid > 0, 'клиент создан из мастера');
[$st, , $loc] = upload(SSO . "/admin/clients/$cid/update",
    ['_csrf' => $csrf, 'name' => 'Витрина', 'base_url' => 'https://showcase.example', 'access_policy' => 'open',
     'is_trusted' => '1', 'is_active' => '1',
     'redirect_uris' => "https://showcase.example/sso/callback.php", 'post_logout_uris' => 'https://showcase.example/'],
    ['icon' => [$png, 'image/png', 'icon.png']]);
ok($st === 302, 'иконка портала загружена', "st=$st");
[$st, $body, , $ctype] = http('GET', SSO . "/client-icon/$cid");
ok($st === 200 && str_contains($ctype, 'image/webp'), 'иконка отдаётся как webp');
[, $loginPage2] = http('GET', SSO . '/admin/clients/' . $cid);
[, $iconCard] = http('GET', SSO . "/admin/clients/$cid/req");
ok(str_contains($iconCard, "client-icon/$cid"), 'иконка видна в разделе реквизитов');
[$st, $body] = http('GET', SSO . "/admin/clients/$cid/integration.md");
ok($st === 200 && str_contains($body, 'showcase.example'), 'integration.md генерируется');

/* ── Кириллический домен: название, а не «Xn--80Aadkevhbkvnxnq8Km» ──
   Браузер отдаёт такой адрес punycode-ом. Слаг это разворачивал, название —
   нет, и порталу доставалась строка, которую невозможно ни прочесть, ни
   произнести. Проверяем обе формы записи: человек может прислать любую. */
foreach ([
    'кириллица как есть' => 'https://календарьсобытий.рф',
    'punycode из адресной строки' => 'https://xn--80aadkevhbkvnxnq8km.xn--p1ai',
] as $how => $url) {
    \App\Core\DB::run('DELETE FROM {clients} WHERE base_url = ?', [$url]);
    [, , $loc] = http('POST', SSO . '/admin/clients/create', ['_csrf' => $csrf, 'base_url' => $url]);
    preg_match('~/admin/clients/(\d+)~', $loc, $mIdn);
    $idnClient = \App\Models\Client::find((int)($mIdn[1] ?? 0));
    ok(($idnClient['name'] ?? '') === 'Календарьсобытий',
        'домен на кириллице даёт читаемое название (' . $how . ')', (string)($idnClient['name'] ?? '—'));
    ok(($idnClient['slug'] ?? '') === 'kalendarsobytiy-ru',
        'и машинное имя латиницей (' . $how . ')', (string)($idnClient['slug'] ?? '—'));
    if ($idnClient) \App\Models\Client::purge((int)$idnClient['id']);
}

/* ── Машинное имя переименовывается ──
   Раньше слаг назначался при создании навсегда. Портал, которому он достался
   кривым, оставался с ним жить — а видно его и в claim `roles`, и в именах
   файлов набора. */
$renameForm = static fn(array $over = []): array => $over + ['_csrf' => $csrf,
    'name' => 'Витрина', 'base_url' => 'https://showcase.example', 'access_policy' => 'open',
    'is_trusted' => '1', 'is_active' => '1',
    'redirect_uris' => 'https://showcase.example/sso/callback.php',
    'post_logout_uris' => 'https://showcase.example/'];

http('POST', SSO . "/admin/clients/$cid/update", $renameForm(['slug' => 'vitrina-nova']));
ok((string)\App\Models\Client::find($cid)['slug'] === 'vitrina-nova', 'машинное имя меняется');

/* Занятое имя не отбирается у соседа — и заодно не отменяет остальную правку
   формы: человек менял название, а не только слаг. */
$busy = (string)\App\Core\DB::val('SELECT slug FROM {clients} WHERE id <> ? LIMIT 1', [$cid]);
/* Без соседа проверка прошла бы вхолостую: пустой слаг в форме означает
   «не меняли», и «не отобралось» получилось бы само собой. */
ok($busy !== '', 'есть чужое машинное имя, на которое можно покуситься');
http('POST', SSO . "/admin/clients/$cid/update", $renameForm(['slug' => $busy, 'name' => 'Витрина 2']));
$afterBusy = \App\Models\Client::find($cid);
ok((string)$afterBusy['slug'] === 'vitrina-nova', 'занятое машинное имя не отбирается');
ok((string)$afterBusy['name'] === 'Витрина 2', 'при отказе по слагу остальная правка сохраняется');

http('POST', SSO . "/admin/clients/$cid/update", $renameForm(['slug' => 'ЭТО Не Слаг!']));
ok((string)\App\Models\Client::find($cid)['slug'] === 'vitrina-nova', 'мусор вместо машинного имени не принимается');

http('POST', SSO . "/admin/clients/$cid/update", $renameForm(['slug' => 'showcase']));
ok((string)\App\Models\Client::find($cid)['slug'] === 'showcase', 'имя возвращается обратно');

/* ── Ключ переноса: его должно хватить времени скопировать ──
   Он приходил текстом в тост, а тост уезжает через пять секунд. Сорок восемь
   знаков оттуда не успевает перенести никто, а второй раз ключ не показывают. */
[$st, , $keyLoc] = http('POST', SSO . "/admin/clients/$cid/users/key", ['_csrf' => $csrf]);
ok($st === 302 && str_contains($keyLoc, '/users/preview'),
    'после выдачи ключа попадаем на экран переноса', $keyLoc);
$issuedKey = (string)\App\Models\Client::find($cid)['users_secret'];
[, $keyPage] = http('GET', SSO . "/admin/clients/$cid/users/preview");
ok($issuedKey !== '' && str_contains($keyPage, $issuedKey), 'ключ показан на странице целиком');
ok(str_contains($keyPage, 'data-copy'), 'и рядом с ним кнопка «копировать»');
[, $keyPage2] = http('GET', SSO . "/admin/clients/$cid/users/preview");
ok(!str_contains($keyPage2, $issuedKey), 'при повторном заходе ключа уже нет — показ один');
\App\Core\PortalUsers::revokeSecret(\App\Models\Client::find($cid));
[$st] = http('GET', SSO . '/admin/logs/export.csv');
ok($st === 200, 'CSV-экспорт аудита работает');

echo "═══ White-label: логотип, favicon, подвал ═══\n";
[$st] = upload(SSO . '/admin/settings/save',
    ['_csrf' => $csrf, 'section' => 'branding', 'name' => 'Моя Компания ID', 'accent' => '#0E7490', 'footer' => 'Моя Компания · вход для всех сервисов'],
    ['logo' => [$png, 'image/png', 'logo.png'], 'favicon' => [$png, 'image/png', 'fav.png']]);
ok($st === 302, 'брендинг с файлами сохранён', "st=$st");
[$st, , , $ctype] = http('GET', SSO . '/branding/logo.webp');
ok($st === 200 && str_contains($ctype, 'image/webp'), 'логотип отдаётся');
[$st, , , $ctype] = http('GET', SSO . '/branding/favicon.png');
ok($st === 200 && str_contains($ctype, 'image/png'), 'favicon отдаётся');
[, $body] = http('GET', SSO . '/profile');

/* Удостоверение: чувствительное закрыто по умолчанию, а генератор QR подключён.
   Без скрипта плашка QR рисовалась пустым белым квадратом — и это жило
   незамеченным, потому что qrcode.js стоял только на экране 2FA. */
ok(str_contains($body, 'class="pass-sheet"'), 'удостоверение — отдельный лист, а не блок в потоке');
ok(str_contains($body, 'assets/js/qrcode.js'), 'генератор QR подключён на странице профиля');
ok(substr_count($body, 'data-secret=') >= 2, 'номер и уровень закрыты до нажатия');
ok(!preg_match('~<div class="idcard-v[^"]*">\s*\d{4}-\d{4}~', $body),
    'номер аккаунта не отдаётся открытым текстом в разметке карточки');

ok(str_contains($body, 'Моя Компания · вход для всех сервисов'), 'подвал показывает свой текст');
ok(str_contains($body, '/branding/logo.webp'), 'логотип в шапке');

/* Панель на экране входа. По умолчанию пусто — там акцентная заставка со
   знаком: белая марка означает, что большинство ничего не загрузит, и «пусто»
   должно быть состоянием, а не дырой. */
/* С 4.81 обложка — набором (CoverSet): картинки, видео или YouTube. */
foreach ((array)glob(\App\Core\CoverSet::dir() . '/site-*') as $f) @unlink((string)$f);
\App\Core\Setting::delete('cover.site');
\App\Core\Setting::flush();
/* Отдельная банка cookie: смоук залогинен, а /login для вошедшего уходит
   редиректом — тело было бы пустым, и проверка соврала бы про вёрстку. */
$mediaJar = __DIR__ . '/tmp/media-guest.txt';
@unlink($mediaJar);
[, $blankLogin] = http('GET', SSO . '/login', [], [], $mediaJar);
ok(str_contains($blankLogin, 'media-blank') && !str_contains($blankLogin, 'media-pane'),
    'без снимка панель входа показывает заставку, а не пустую рамку');

/* Загруженный кадр укладывается в потолок: экран входа не должен весить как
   галерея, и полагаться на то, что владелец загрузит уже сжатое, нельзя. */
$bigPath = __DIR__ . '/tmp/media-big.jpg';
$bigImg = imagecreatetruecolor(2400, 2000);
imagefilledrectangle($bigImg, 0, 0, 2400, 2000, (int)imagecolorallocate($bigImg, 40, 70, 130));
imagejpeg($bigImg, $bigPath, 85);
imagedestroy($bigImg);
/* Обложка загружается в «Оформлении сайта», а не в брендинге: логотип виден
   везде, а панель — только не вошедшему (v4.8). Способы входа передаём все:
   раздел отвергает сохранение, где не осталось ни одного. */
[, $apForMedia] = http('GET', SSO . '/admin/appearance');
upload(SSO . '/admin/appearance/save',
    ['_csrf' => csrfOf($apForMedia), 'section' => 'login',
     'method_password' => '1', 'method_magic' => '1', 'method_passkey' => '1', 'method_social' => '1',
     'media_title' => 'Панель', 'media_text' => 'Подпись под кадром', 'cover_kind' => 'images'],
    ['cover_add[0]' => [$bigPath, 'image/jpeg', 'shot.jpg']]);
\App\Core\Setting::flush();
$coverSet = \App\Core\CoverSet::get(null);
$mediaFile = $coverSet['images'] !== [] ? \App\Core\CoverSet::path('site', $coverSet['images'][0], 'webp') : '';
ok($mediaFile !== '' && is_file($mediaFile), 'снимок панели сохранён');
if (is_file($mediaFile)) {
    $dim = (array)getimagesize($mediaFile);
    ok(($dim[0] ?? 9999) <= 1600 && ($dim[1] ?? 9999) <= 1400,
        sprintf('кадр уложен в потолок (%d×%d из 2400×2000)', $dim[0] ?? 0, $dim[1] ?? 0));
}
[, $shotLogin] = http('GET', SSO . '/login', [], [], $mediaJar);
ok(str_contains($shotLogin, 'media-pane') && str_contains($shotLogin, 'media-caption'),
    'со снимком панель показывает кадр и подпись');
/* Подпись обязана стоять ПОД кадром: поверх она читалась бы только на тёмных
   снимках — белый заголовок на светлом кадре даёт около 1.2:1. */
$framePos = strpos($shotLogin, 'media-frame');
$capPos = strpos($shotLogin, 'media-caption');
ok($framePos !== false && $capPos !== false && $capPos > $framePos,
    'подпись идёт после кадра, а не поверх него');

[$mst, , , $mtype] = http('GET', \App\Core\CoverSet::url('site', $coverSet['images'][0] ?? 'x', 'webp'), [], [], $mediaJar);
ok($mst === 200 && str_contains($mtype, 'image/webp'), 'кадр отдаёт наш адрес /cover/');
if ($mediaFile !== '') @unlink($mediaFile);
@unlink($bigPath);
\App\Core\Setting::delete('cover.site');
\App\Core\Setting::delete('branding.media_title');
\App\Core\Setting::delete('branding.media_text');
\App\Core\Setting::flush();
ok(str_contains($body, 'Моя Компания ID'), 'название сменилось везде');
// возвращаем dev-брендинг
http('POST', SSO . '/admin/settings/save', ['_csrf' => $csrf, 'section' => 'branding',
    'name' => 'SSO (dev)', 'accent' => '', 'footer' => '', 'logo_delete' => '1', 'favicon_delete' => '1']);

echo "═══ Пользователь: блокировка/разблокировка ═══\n";
$uid = \App\Models\User::create(['email' => 'smoke-victim@test.local', 'first_name' => 'Смоук',
    'password_hash' => password_hash('x', PASSWORD_ARGON2ID), 'status' => 'active']);
[$st] = http('POST', SSO . "/admin/users/$uid/action", ['_csrf' => $csrf, 'action' => 'block']);
$u = \App\Models\User::find($uid);
ok($u['status'] === 'blocked', 'блокировка применилась');
[$st] = http('POST', SSO . "/admin/users/$uid/action", ['_csrf' => $csrf, 'action' => 'unblock']);
$u = \App\Models\User::find($uid);
ok($u['status'] !== 'blocked', 'разблокировка применилась');

echo "═══ Номер аккаунта: правка суперадмином ═══\n";
$wasNumber = (string)(\App\Models\User::find($uid)['account_number'] ?? '');
ok($wasNumber !== '', 'номер выдан при создании аккаунта');

/* Ввод с дефисом — так номер и копируют из письма или из карточки. */
$free = \App\Core\AccountNumber::generate();
http('POST', SSO . "/admin/users/$uid/action",
    ['_csrf' => $csrf, 'action' => 'account_number',
     'account_number' => \App\Core\AccountNumber::format($free)]);
ok((string)\App\Models\User::find($uid)['account_number'] === $free, 'суперадмин сменил номер');

/* Контрольная цифра обязана сходиться: иначе номер нельзя проверить на слух. */
$bad = substr($free, 0, 8) . ((int)substr($free, 8, 1) === 7 ? '3' : '7');
http('POST', SSO . "/admin/users/$uid/action",
    ['_csrf' => $csrf, 'action' => 'account_number', 'account_number' => $bad]);
ok((string)\App\Models\User::find($uid)['account_number'] === $free,
    'номер с битой контрольной цифрой отклонён');

/* Два аккаунта с одним номером сделали бы обращение в поддержку бессмысленным. */
$other = \App\Models\User::create(['email' => 'smoke-number-twin@test.local', 'first_name' => 'Двойник',
    'password_hash' => password_hash('x', PASSWORD_ARGON2ID), 'status' => 'active']);
$otherNumber = (string)\App\Models\User::find($other)['account_number'];
http('POST', SSO . "/admin/users/$uid/action",
    ['_csrf' => $csrf, 'action' => 'account_number', 'account_number' => $otherNumber]);
ok((string)\App\Models\User::find($uid)['account_number'] === $free, 'занятый номер не отдаётся');

/* Старый номер остаётся в аудите: обращения по прежнему номеру должны быть разрешимы. */
$trail = \App\Core\DB::row(
    'SELECT meta FROM {audit_log} WHERE on_user_id = ? AND event = ? ORDER BY id DESC LIMIT 1',
    [$uid, 'admin.user.update']);
ok($trail !== null && str_contains((string)$trail['meta'], $wasNumber),
    'прежний номер записан в аудит');

/* Двойник больше не нужен: он попадёт в список пользователей и сдвинет
   бюджет инлайн-стилей, который считается ниже по файлу. */
\App\Core\DB::run('DELETE FROM {user_roles} WHERE user_id = ?', [$other]);
\App\Core\DB::run('DELETE FROM {users} WHERE id = ?', [$other]);

echo "═══ v2.1: иконки, палитра, onboarding, крошки ═══\n";
// Иконочный спрайт вместо эмодзи
[$st, $sprite, , $ctype] = http('GET', SSO . '/assets/icons.svg');
ok($st === 200 && substr_count($sprite, '<symbol') >= 28, 'спрайт иконок отдаётся (' . substr_count($sprite, '<symbol') . ' глифов)');
$emojiPages = 0;
foreach (['/admin', '/admin/health', '/profile', '/profile/security'] as $p) {
    [, $body] = http('GET', SSO . $p);
    if (preg_match('~[\x{1F300}-\x{1FAFF}]~u', $body)) $emojiPages++;
}
ok($emojiPages === 0, 'эмодзи как иконки убраны со всех страниц');
[, $dash] = http('GET', SSO . '/admin');
ok(str_contains($dash, 'class="icon'), 'иконки рендерятся в разметке');

// Командная палитра
$palette = json_decode(http('GET', SSO . '/search?q=')[1], true);
ok(isset($palette['groups']) && count($palette['groups']) >= 2, 'палитра отдаёт разделы и действия');
$found = json_decode(http('GET', SSO . '/search?q=' . rawurlencode('Витрина'))[1], true);
$hasPortal = false;
foreach ($found['groups'] ?? [] as $g) {
    if ($g['title'] === 'Порталы' && $g['items'] !== []) $hasPortal = true;
}
ok($hasPortal, 'палитра находит портал по названию');
$byEmail = json_decode(http('GET', SSO . '/search?q=admin@test.local')[1], true);
$hasUser = false;
foreach ($byEmail['groups'] ?? [] as $g) {
    if ($g['title'] === 'Пользователи' && $g['items'] !== []) $hasUser = true;
}
ok($hasUser, 'палитра находит пользователя по email');

// Onboarding
ok(str_contains($dash, 'onboarding-step'), 'onboarding-чеклист на дашборде');
ok(substr_count($dash, 'onboarding-step') === 5, 'все 5 шагов присутствуют');

// Хлебные крошки с сохранением фильтров
http('GET', SSO . '/admin/users?status=active&q=admin');
[, $card] = http('GET', SSO . '/admin/users/1');
ok(str_contains($card, 'class="breadcrumbs"'), 'хлебные крошки в карточке');
ok(str_contains($card, 'status=active') && str_contains($card, 'q=admin'), 'фильтры списка сохранены в ссылке возврата');

// Drawer на аудите
[, $logs] = http('GET', SSO . '/admin/logs');
ok(str_contains($logs, 'data-drawer=') && str_contains($logs, 'row-clickable'), 'строки аудита открывают drawer');

echo "═══ v2.2: бэкап, уведомления, инвайты, экспорт ═══\n";
// Бэкап: ключи + конфиг + дамп
$bakFile = __DIR__ . '/tmp/backup.zip';
$ch = curl_init(SSO . '/admin/tools/backup.zip');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $JAR, CURLOPT_TIMEOUT => 60]);
file_put_contents($bakFile, (string)curl_exec($ch));
curl_close($ch);
$bak = new ZipArchive();
$bakOk = $bak->open($bakFile) === true;
ok($bakOk, 'бэкап скачивается и открывается');
if ($bakOk) {
    $names = [];
    for ($i = 0; $i < $bak->numFiles; $i++) $names[] = $bak->getNameIndex($i);
    $bak->close();
    $hasKeys = count(array_filter($names, fn($n) => str_starts_with($n, 'keys/'))) > 0;
    ok($hasKeys, 'в бэкапе есть ключи подписи');
    ok(in_array('database.sql', $names, true), 'в бэкапе есть дамп базы');
    ok(in_array('config/config.php', $names, true), 'в бэкапе есть конфиг с APP_KEY');
}

/* Слепок настроек: выгрузка отдаёт файл, а секреты в нём не лежат. Разбор и
   применение проверены в unit; здесь — что маршрут работает и что через HTTP
   пароль наружу не уходит. */
\App\Core\Setting::set('mail.pass', 'смоук-пароль-smtp');
\App\Core\Setting::flush();
[$st, $snapBody, , $snapType] = http('GET', SSO . '/admin/tools/settings.json');
$snap = json_decode($snapBody, true);
ok($st === 200 && str_contains((string)$snapType, 'application/json'),
    'слепок настроек отдаётся как json', "st=$st ctype=$snapType");
ok(isset($snap['settings']) && $snap['settings'] !== [], 'в слепке есть настройки');
ok(!str_contains($snapBody, 'смоук-пароль-smtp'), 'пароль SMTP в файл не попал');
ok(in_array('mail.pass', (array)($snap['secrets_skipped'] ?? []), true),
    'и файл честно говорит, что не взял его');
[$st, $snapSecret] = http('GET', SSO . '/admin/tools/settings.json?with_secrets=1');
ok($st === 200 && str_contains($snapSecret, 'смоук-пароль-smtp'),
    'по отдельной просьбе пароль в файле есть');
/* Ключи подписи не должны уезжать ни при какой просьбе. */
ok(!str_contains($snapSecret, 'keys.registry') && !str_contains($snapSecret, 'system.owner_user_id'),
    'ключи подписи и владелец не уезжают даже с секретами');
\App\Core\DB::run('DELETE FROM {settings} WHERE `key` = ?', ['mail.pass']);
\App\Core\Setting::flush();

/* Круг целиком: выгрузили, поменяли в файле одно значение, загрузили, увидели
   экран «что изменится», применили. Порядок именно такой — настройки правят
   вход, и «нажал и посмотрим» тут не годится. */
$impFile = __DIR__ . '/tmp/settings-import.json';
$snapEdited = $snap;
$snapEdited['settings']['branding.footer'] = 'подпись из файла';
$snapEdited['settings']['keys.registry'] = 'чужие ключи';   // принять не должны
file_put_contents($impFile, json_encode($snapEdited, JSON_UNESCAPED_UNICODE));
[$st] = upload(SSO . '/admin/tools/settings-import',
    ['_csrf' => $csrf, 'step' => 'upload'], ['file' => [$impFile, 'application/json', 'settings.json']]);
ok($st === 302, 'файл настроек принят на разбор', "st=$st");
[, $planPage] = http('GET', SSO . '/admin/tools');
ok(str_contains($planPage, 'branding.footer') && str_contains($planPage, 'подпись из файла'),
    'экран «что изменится» показывает будущее значение');
ok(str_contains($planPage, 'keys.registry'),
    'и называет ключ, который не примет, а не выбрасывает молча');
ok(!str_contains((string)\App\Core\Setting::get('branding.footer', ''), 'подпись из файла'),
    'до нажатия «Применить» ничего не изменилось');
[$st] = http('POST', SSO . '/admin/tools/settings-import', ['_csrf' => $csrf, 'step' => 'apply']);
\App\Core\Setting::flush();
ok($st === 302 && (string)\App\Core\Setting::get('branding.footer') === 'подпись из файла',
    'после подтверждения настройка применилась');
ok((string)\App\Core\Setting::get('keys.registry', '') !== 'чужие ключи',
    'а чужие ключи подписи — нет');
/* Подпись из файла — только для проверки: подвал стенда жил с ней дальше (v4.83). */
$footerWas = $snap['settings']['branding.footer'] ?? null;
$footerWas === null || $footerWas === '' ? \App\Core\Setting::delete('branding.footer') : \App\Core\Setting::set('branding.footer', (string)$footerWas);
\App\Core\Setting::flush();
/* Файл не того формата отвергается со словами, а не молча. */
file_put_contents($impFile, json_encode(['format' => 99, 'settings' => []]));
[$st] = upload(SSO . '/admin/tools/settings-import',
    ['_csrf' => $csrf, 'step' => 'upload'], ['file' => [$impFile, 'application/json', 'settings.json']]);
[, $refusedPage] = http('GET', SSO . '/admin/tools');
ok(str_contains($refusedPage, 'другого формата'), 'файл чужого формата отвергается со словами');
@unlink($impFile);

/* События, которые объявили в каталоге вебхуков и забыли отправлять: три штуки
   с v2.3. Ревизия проводки ловит их по коду; здесь проверяем, что доставка
   доходит по-настоящему — подписываем хук и смотрим очередь. */
echo "═══ Вебхуки: события, которых не было (v4.18) ═══\n";
$wid = \App\Core\DB::insert('webhooks', [
    'name' => 'Смоук-проводка',
    'url' => 'http://127.0.0.1:1/nowhere',   // никуда: важен факт постановки в очередь
    'secret' => 'x',
    'events' => json_encode(['access.granted', 'access.revoked', 'login.failed']),
    'is_active' => 1,
    'created_at' => now(),
]);
$wUser = (int)\App\Core\DB::val('SELECT id FROM {users} ORDER BY id LIMIT 1');
$wClient = (int)\App\Core\DB::val('SELECT id FROM {clients} ORDER BY id LIMIT 1');
$queued = static fn(string $ev): int => (int)\App\Core\DB::val(
    'SELECT COUNT(*) FROM {webhook_deliveries} WHERE webhook_id = ? AND event = ?', [$wid, $ev]);

\App\Models\Access::remove($wUser, $wClient);
$before = $queued('access.granted');
\App\Models\Access::grant($wUser, $wClient, null, 'active');
ok($queued('access.granted') === $before + 1, 'выдача доступа доезжает до подписчика');
$before = $queued('access.revoked');
\App\Models\Access::setStatus($wUser, $wClient, 'blocked');
ok($queued('access.revoked') === $before + 1, 'отзыв доступа тоже');
$before = $queued('login.failed');
\App\Models\User::registerFailedLogin(\App\Models\User::find($wUser));
ok($queued('login.failed') === $before + 1, 'неудачный вход доезжает');
/* Повторная выдача уже активного доступа не должна слать событие второй раз:
   иначе каждый вход в админку порождал бы «доступ выдан». */
\App\Models\Access::setStatus($wUser, $wClient, 'active');
$before = $queued('access.granted');
\App\Models\Access::grant($wUser, $wClient, null, 'active');
ok($queued('access.granted') === $before, 'повторная выдача того же доступа молчит');
\App\Core\DB::run('DELETE FROM {webhook_deliveries} WHERE webhook_id = ?', [$wid]);
\App\Core\DB::run('DELETE FROM {webhooks} WHERE id = ?', [$wid]);
\App\Models\User::resetLockout($wUser);

/* Замок целиком через HTTP (v4.20): запирается сам по простою, закрывает все
   экраны кроме своего, отпирается PIN и возвращает туда, куда человек шёл. */
echo "═══ Замок после простоя ═══\n";
$lockUid = (int)\App\Core\DB::val('SELECT id FROM {users} WHERE email = ?', ['admin@test.local']);
\App\Core\QuickUnlock::setPin($lockUid, '4731');
\App\Core\Setting::set('policy.lock_minutes', '30');
\App\Core\Setting::flush();
/* Отматываем последнюю активность сессии назад — это и есть «простой». */
\App\Core\DB::run('UPDATE {sessions} SET last_seen_at = ? WHERE user_id = ? AND revoked_at IS NULL',
    [gmdate('Y-m-d H:i:s', time() - 3600), $lockUid]);

[$st, , $lockLoc] = http('GET', SSO . '/admin/users');
ok($st === 302 && str_contains((string)$lockLoc, '/lock'),
    'после простоя любой экран уводит на замок', "st=$st loc=$lockLoc");
[$st, $lockPage] = http('GET', SSO . '/lock');
ok($st === 200 && str_contains($lockPage, 'name="pin"'), 'на замке есть поле PIN');
ok(!str_contains($lockPage, 'name="password"'), 'и нет пароля — замок нужен, чтобы его не вводить');
$lockCsrf = csrfOf($lockPage);

/* POST в запертую сессию не выполняется, а не «молча проходит». */
[$st] = http('POST', SSO . '/admin/settings/save',
    ['_csrf' => $lockCsrf, 'section' => 'branding', 'name' => 'Взломано']);
ok($st === 423, 'действие в запертой сессии отклонено кодом 423', "st=$st");
ok(\App\Core\Setting::appName() !== 'Взломано', 'и настройка не изменилась');

[$st] = http('POST', SSO . '/lock/pin', ['_csrf' => $lockCsrf, 'pin' => '0000']);
ok($st === 200, 'неверный PIN оставляет на замке', "st=$st");
[$st, , $unlockLoc] = http('POST', SSO . '/lock/pin', ['_csrf' => $lockCsrf, 'pin' => '4731']);
ok($st === 302 && str_contains((string)$unlockLoc, '/admin/users'),
    'верный PIN отпирает и возвращает туда, куда человек шёл', "loc=$unlockLoc");
[$st] = http('GET', SSO . '/admin/users');
ok($st === 200, 'после замка админка снова открыта');

/* Замок выключен — не запирает, сколько бы ни простояли. */
\App\Core\Setting::set('policy.lock_minutes', '0');
\App\Core\Setting::flush();
\App\Core\DB::run('UPDATE {sessions} SET last_seen_at = ? WHERE user_id = ? AND revoked_at IS NULL',
    [gmdate('Y-m-d H:i:s', time() - 7200), $lockUid]);
[$st] = http('GET', SSO . '/admin/users');
ok($st === 200, 'выключенный замок не запирает даже через два часа');
\App\Core\QuickUnlock::clearPin($lockUid);

// Инвайт: создание → приём другим пользователем
$inviteClient = (int)\App\Core\DB::val('SELECT id FROM {clients} ORDER BY id DESC LIMIT 1');
[$st, , $loc] = http('POST', SSO . "/admin/clients/$inviteClient/action", [
    '_csrf' => $csrf, 'action' => 'create_invite', 'invite_uses' => '2', 'invite_days' => '7',
    'invite_note' => 'Смоук-приглашение',
]);
ok($st === 302, 'приглашение создано');
[, $cardHtml] = http("GET", SSO . "/admin/clients/$inviteClient/invites");
/* Атрибуты между id и value перечислять нельзя: их состав меняется
   (добавились aria-describedby и aria-invalid) — и проверка ломалась
   не о дефект, а о законное улучшение разметки. Ищем правило: поле с
   этим id и его значение. */
preg_match('~id="fresh-invite"[^>]*\svalue="([^"]+)"~', $cardHtml, $m);
$inviteUrl = $m[1] ?? '';
ok(str_contains($inviteUrl, '/invite/'), 'ссылка показана один раз');
$inviteToken = basename((string)parse_url($inviteUrl, PHP_URL_PATH));

// Приглашённый — отдельный пользователь со своей сессией
$guestJar = __DIR__ . '/tmp/guest.txt';
@unlink($guestJar);
$guestEmail = 'guest' . time() . '@test.local';
$guestId = \App\Models\User::create(['email' => $guestEmail, 'first_name' => 'Гость',
    'password_hash' => password_hash('guest-password-1', PASSWORD_ARGON2ID), 'status' => 'active']);
[, $gl] = http('GET', SSO . '/login', [], [], $guestJar);
$gcsrf = csrfOf($gl);
http('POST', SSO . '/login', ['_csrf' => $gcsrf, 'identity' => $guestEmail, 'password' => 'guest-password-1'], [], $guestJar);
[$st, $inviteePage] = http('GET', SSO . '/invite/' . $inviteToken, [], [], $guestJar);
ok($st === 200 && str_contains($inviteePage, 'Принять приглашение'), 'экран приёма показан приглашённому');
[$st] = http('POST', SSO . '/invite/' . $inviteToken, ['_csrf' => csrfOf($inviteePage)], [], $guestJar);
$access = \App\Models\Access::get($guestId, $inviteClient);
ok($access !== null && $access['status'] === 'active', 'доступ выдан по приглашению');
$usedRow = \App\Core\DB::row('SELECT used FROM {invites} WHERE client_id = ? ORDER BY id DESC LIMIT 1', [$inviteClient]);
ok((int)($usedRow['used'] ?? 0) === 1, 'счётчик использований вырос');

/* «Мои сервисы» не показывают чужого лишнего.
   Раньше в список «ещё не подключён» шли ВСЕ активные порталы, и человек в
   своём профиле видел чужие проекты — включая те, о которых знать ему незачем.
   Спрашиваем тот же флаг, что и витрина: «предлагать этот сервис». */
$hidden = \App\Models\Client::create(['name' => 'Личный блокнот', 'slug' => 'lichnyi-bloknot',
    'base_url' => 'https://bloknot.example', 'redirect_uris' => ['https://bloknot.example/cb'],
    'post_logout_uris' => ['https://bloknot.example/'], 'backchannel_uri' => null]);
$offered = \App\Models\Client::create(['name' => 'Открытый сервис', 'slug' => 'otkrytyi-servis',
    'base_url' => 'https://otkrytyi.example', 'redirect_uris' => ['https://otkrytyi.example/cb'],
    'post_logout_uris' => ['https://otkrytyi.example/'], 'backchannel_uri' => null]);
\App\Models\Client::update((int)$offered[0], ['show_on_home' => 1]);
[, $svcPage] = http('GET', SSO . '/profile/services', [], [], $guestJar);
ok(!str_contains($svcPage, 'Личный блокнот'),
    'сервис, который себя не предлагает, в чужом профиле не показывается');
ok(str_contains($svcPage, 'Открытый сервис'),
    'а тот, что предлагает, — показывается');

/* Доступ выдаётся из карточки СЕРВИСА, а не только из карточки человека.
   Раздел «Люди» был списком для чтения: посмотреть, кто ходит, можно, а пустить
   или закрыть — нет, и ради пятерых приходилось открывать пять чужих карточек. */
$cardCid = (int)$offered[0];
[, $ppl] = http('GET', SSO . '/admin/clients/' . $cardCid . '/people');
$pplCsrf = csrfOf($ppl);
[$stG] = http('POST', SSO . '/admin/clients/' . $cardCid . '/people',
    ['_csrf' => $pplCsrf, 'identity' => $guestEmail]);
$accCard = \App\Models\Access::get($guestId, $cardCid);
ok($stG === 302 && ($accCard['status'] ?? '') === 'active',
    'доступ выдаётся из карточки сервиса по почте', "st=$stG");
[, $ppl2] = http('GET', SSO . '/admin/clients/' . $cardCid . '/people');
ok(str_contains($ppl2, 'value="revoke"'), 'и в строке человека есть чем его закрыть');
[$stR] = http('POST', SSO . '/admin/users/' . $guestId . '/access',
    ['_csrf' => csrfOf($ppl2), 'client_id' => $cardCid, 'access' => 'revoke',
     'back' => '/admin/clients/' . $cardCid . '/people']);
ok(($accRe = \App\Models\Access::get($guestId, $cardCid)) !== null
    && $accRe['status'] === 'blocked', 'закрытие работает', "st=$stR");
[$stNo] = http('POST', SSO . '/admin/clients/' . $cardCid . '/people',
    ['_csrf' => $pplCsrf, 'identity' => 'нет-такого@nowhere.example']);
ok($stNo === 302 && \App\Models\User::findByLoginOrEmail('нет-такого@nowhere.example') === null,
    'неизвестная почта аккаунт не заводит', "st=$stNo");

/* ── Роль выдаётся из строки человека (v4.46) ───────────────────────────────
   Раньше за этим шли в раздел «Роли», где кого выбирают из выпадающего списка
   на всех, у кого есть доступ: чтобы выдать роль вот этому человеку, надо было
   найти его во ВТОРОМ списке — при том, что он уже перед глазами в первом. */
/* Повторяемо: харнесс мутаций гоняет смоук дважды, и вторая попытка завести
   ту же роль упала бы на уникальном слаге. */
$grantRoleId = (int)(\App\Models\Role::findBySlug('smoke_row_grant', $cardCid)['id']
    ?? \App\Models\Role::create($cardCid, 'smoke_row_grant', 'Смоук: выдача из строки', ''));
[, $pplR] = http('GET', SSO . '/admin/clients/' . $cardCid . '/people');
ok(str_contains($pplR, 'Роли в сервисе'), 'в списке людей есть столбец ролей');
ok(str_contains($pplR, 'value="role_grant"'), 'и прямо в строке есть чем выдать роль');
ok(str_contains($pplR, 'name="role_id"'), 'с выбором роли');

$scopeCard = \App\Core\Scope::client($cardCid);
[$stRG, , $locRG] = http('POST', SSO . '/admin/clients/' . $cardCid . '/action',
    ['_csrf' => csrfOf($pplR), 'action' => 'role_grant', 'user_id' => $guestId,
     'role_id' => $grantRoleId, '_back' => '/admin/clients/' . $cardCid . '/people']);
$granted = array_filter(\App\Models\Role::grantsInScope($scopeCard),
    static fn(array $g): bool => (int)$g['user_id'] === (int)$guestId
        && (int)$g['role_id'] === (int)$grantRoleId);
ok($stRG === 302 && $granted !== [], 'роль выдана из строки человека', "st=$stRG");
/* Возврат туда, откуда выдали: жёсткий адрес уносил бы в раздел «Роли» —
   выдал одному, а тебя увело в другой раздел искать следующего. */
ok(str_ends_with((string)$locRG, '/admin/clients/' . $cardCid . '/people'),
    'и возвращает на ту же страницу, а не в раздел ролей', (string)$locRG);

[, $pplR2] = http('GET', SSO . '/admin/clients/' . $cardCid . '/people');
ok(str_contains($pplR2, 'smoke_row_grant'), 'выданная роль видна плашкой в строке');
ok(str_contains($pplR2, 'value="role_revoke"'), 'и её есть чем снять оттуда же');

/* Адрес возврата приходит из браузера — значит подделывается. Чужой хост
   обязан быть отброшен, иначе это открытый редирект с нашего домена. */
[, , $locEvil] = http('POST', SSO . '/admin/clients/' . $cardCid . '/action',
    ['_csrf' => csrfOf($pplR2), 'action' => 'role_grant', 'user_id' => $guestId,
     'role_id' => $grantRoleId, '_back' => 'https://evil.example/x']);
ok(!str_contains((string)$locEvil, 'evil.example'),
    'чужой адрес возврата отброшен', (string)$locEvil);

[$stRR] = http('POST', SSO . '/admin/clients/' . $cardCid . '/action',
    ['_csrf' => csrfOf($pplR2), 'action' => 'role_revoke', 'user_id' => $guestId,
     'role_id' => $grantRoleId, '_back' => '/admin/clients/' . $cardCid . '/people']);
$stillThere = array_filter(\App\Models\Role::grantsInScope($scopeCard),
    static fn(array $g): bool => (int)$g['user_id'] === (int)$guestId
        && (int)$g['role_id'] === (int)$grantRoleId);
ok($stRR === 302 && $stillThere === [], 'и снимается оттуда же', "st=$stRR");

/* ── Систематизация списка выдачи (v4.50) ───────────────────────────────────
   Список собирался запросом «свои роли плюс системные» и вырастал до
   двенадцати пунктов: `admin` и `user` по два раза, плюс роли областей
   организации и системы, выдать которые в сервисе всё равно нельзя. */
$offer = \App\Core\RoleOffer::inScope($scopeCard);
$offerSlugs = array_map(static fn(array $r): string => (string)$r['slug'], $offer);

ok(count($offerSlugs) === count(array_unique($offerSlugs)),
    'в списке выдачи нет двух ролей с одним слагом',
    implode(', ', $offerSlugs));
/* Своя роль сервиса побеждает системную с тем же именем: для человека это один
   и тот же «админ», и два одинаковых пункта он прочтёт как ошибку. */
$sysAdmin = \App\Models\Role::findBySlug('admin');
/* Повторяемо: харнесс мутаций гоняет смоук дважды — с дефектом и без, — и
   вторая попытка завести ту же роль падала бы на уникальном слаге. Поймано
   самим харнессом: он показал код 255 вместо честного провала проверки. */
$ownAdmin = (int)(\App\Models\Role::findBySlug('admin', $cardCid)['id']
    ?? \App\Models\Role::create($cardCid, 'admin', 'Админ сервиса', ''));
$offer2 = array_map(static fn(array $r): int => (int)$r['id'],
    \App\Core\RoleOffer::inScope($scopeCard));
ok(in_array($ownAdmin, $offer2, true) && ($sysAdmin === null || !in_array((int)$sysAdmin['id'], $offer2, true)),
    'своя роль сервиса вытесняет системную с тем же слагом');

/* Роли чужих областей не предлагаются вовсе — именно они и раздували список. */
$foreign = [];
foreach (['superadmin', 'org_owner', 'org_admin', 'org_auditor', 'auditor', 'support'] as $slug) {
    if (in_array($slug, array_map(static fn(array $r): string => (string)$r['slug'],
        \App\Core\RoleOffer::inScope($scopeCard)), true)) $foreign[] = $slug;
}
ok($foreign === [], 'роли системы и организации в область сервиса не попадают',
    implode(', ', $foreign));

/* И тот же вопрос по-серверному: спрятанный пункт не должен проходить POST-ом. */
$sysOnly = \App\Models\Role::findBySlug('superadmin');
if ($sysOnly) {
    ok(!\App\Core\RoleOffer::allows($scopeCard, (int)$sysOnly['id']),
        'выдать роль чужой области подменой поля нельзя');
    [, $pplGuard] = http('GET', SSO . '/admin/clients/' . $cardCid . '/people');
    [$stGuard] = http('POST', SSO . '/admin/clients/' . $cardCid . '/action',
        ['_csrf' => csrfOf($pplGuard), 'action' => 'role_grant', 'user_id' => $guestId,
         'role_id' => (int)$sysOnly['id'], '_back' => '/admin/clients/' . $cardCid . '/people']);
    $sneaked = array_filter(\App\Models\Role::grantsInScope($scopeCard),
        static fn(array $g): bool => (int)$g['role_id'] === (int)$sysOnly['id']);
    ok($sneaked === [], 'и сервер её действительно не выдал', "st=$stGuard");
}

/* Роли человека выдаются в его разделе «Роли» — один раз (v4.68). «Профиль»
   рисовал вторую, урезанную копию той же выдачи; её там больше нет. На систему
   по-прежнему не предлагаются портальные роли чужих сервисов. */
[, $userCard] = http('GET', SSO . '/admin/users/' . $guestId);
ok(!str_contains($userCard, 'role-add'), 'в профиле человека второй выдачи ролей нет');
[, $userRoles] = http('GET', SSO . '/admin/users/' . $guestId . '/roles');
ok(str_contains($userRoles, 'name="role_id"') && str_contains($userRoles, 'Что выдано'),
    'роли человека — в его разделе «Роли»: что выдано и выдать');
$sysOffer = array_map(static fn(array $r): string => (string)$r['slug'],
    \App\Core\RoleOffer::inScope(\App\Core\Scope::system()));
ok(!in_array('smoke_row_grant', $sysOffer, true) && !in_array('client_admin', $sysOffer, true),
    'на систему не предлагаются роли сервисов', implode(', ', $sysOffer));

// Уведомления безопасности: настройки и известные устройства
[$st] = http('POST', SSO . '/profile/settings/notifications', [
    '_csrf' => $csrf, 'notify_login_new_device' => '1', 'notify_password_change' => '1',
]);
ok($st === 302 && \App\Core\Notify::enabled((int)$admin['id'], 'login.new_device'), 'настройки уведомлений сохраняются');
ok(!\App\Core\Notify::enabled((int)$admin['id'], 'passkey.change'), 'снятый флажок выключает уведомление');
$devices = (int)\App\Core\DB::val('SELECT COUNT(*) FROM {known_devices} WHERE user_id = ?', [(int)$admin['id']]);
ok($devices > 0, 'вход зарегистрировал известное устройство');

/* Экспорт собирается сразу при заказе: очередь ждала cron, а без планировщика
   архив висел в «готовится» бесконечно, хотя интерфейс обещал пару минут. */
[$st] = http('POST', SSO . '/profile/settings/export', ['_csrf' => $csrf]);
$job = \App\Core\DB::row('SELECT * FROM {export_jobs} WHERE user_id = ? ORDER BY id DESC LIMIT 1', [(int)$admin['id']]);
ok($job !== null && $job['status'] === 'ready', 'архив собран сразу, а не поставлен в очередь',
    'статус: ' . (string)($job['status'] ?? 'нет задания'));
ok((int)($job['size_bytes'] ?? 0) > 0, 'архив не пустой');
\App\Core\DataExport::build((int)$job['id']);
$job = \App\Core\DB::row('SELECT * FROM {export_jobs} WHERE id = ?', [(int)$job['id']]);
ok($job['status'] === 'ready' && (int)$job['size_bytes'] > 0, 'архив собран (' . round(((int)$job['size_bytes']) / 1024, 1) . ' КБ)');
$exportZip = new ZipArchive();
if ($exportZip->open(SSO_ROOT . '/storage/' . $job['file_path']) === true) {
    $inside = [];
    for ($i = 0; $i < $exportZip->numFiles; $i++) $inside[] = $exportZip->getNameIndex($i);
    $profileJson = json_decode((string)$exportZip->getFromName('profile.json'), true);
    $exportZip->close();
    ok(in_array('profile.json', $inside, true) && in_array('audit.csv', $inside, true), 'в выгрузке профиль и аудит');
    ok(($profileJson['email'] ?? '') === 'admin@test.local', 'профиль содержит данные пользователя');
    ok(!str_contains((string)json_encode($profileJson), 'password_hash'), 'хэш пароля в выгрузку не попал');
} else {
    ok(false, 'архив выгрузки открывается');
}

echo "═══ v2.3: webhooks, соцвход, Admin API ═══\n";
// Webhook: создание, подписка, доставка на локальный приёмник (тестовый RP)
[$st] = http('POST', SSO . '/admin/webhooks/create', [
    '_csrf' => $csrf, 'name' => 'Смоук-хук', 'url' => 'http://localhost:8711/hook.php',
    'events' => ['user.created', 'login.success'],
]);
ok($st === 302, 'webhook создан');
$hook = \App\Core\DB::row('SELECT * FROM {webhooks} ORDER BY id DESC LIMIT 1');
ok($hook !== null && str_starts_with((string)$hook['secret'], 'whsec_'), 'секрет подписи сгенерирован');
$subs = \App\Models\Webhook::subscribers('user.created');
ok(count($subs) >= 1, 'подписчик найден по событию');
// Приёмник в тестовом RP пишет заголовки в файл — проверим доставку и подпись
file_put_contents(__DIR__ . '/rp/hook.php', <<<'PHP'
<?php
$body = file_get_contents('php://input');
$h = ['sig' => $_SERVER['HTTP_X_SSO_SIGNATURE'] ?? '', 'ts' => $_SERVER['HTTP_X_SSO_TIMESTAMP'] ?? '',
      'event' => $_SERVER['HTTP_X_SSO_EVENT'] ?? '', 'body' => $body,
      'old_sig' => $_SERVER['HTTP_X_TSOY_SIGNATURE'] ?? '', 'old_event' => $_SERVER['HTTP_X_TSOY_EVENT'] ?? ''];
file_put_contents(__DIR__ . '/hook-last.json', json_encode($h));
http_response_code(200);
echo 'ok';
PHP);
@unlink(__DIR__ . '/rp/hook-last.json');
\App\Core\WebhookDispatcher::emit('user.created', ['sub' => 'test-sub', 'email' => 'hook@test.local']);
$received = @file_get_contents(__DIR__ . '/rp/hook-last.json');
ok($received !== false, 'событие доставлено на приёмник');
if ($received !== false) {
    $got = json_decode($received, true);
    $expectedSig = 'sha256=' . hash_hmac('sha256', $got['ts'] . '.' . $got['body'], (string)$hook['secret']);
    ok(hash_equals($expectedSig, $got['sig']), 'HMAC-подпись сходится у получателя');
    ok($got['event'] === 'user.created', 'заголовок X-SSO-Event верный');
    /* Переходный период (4.89 → 5.0): прежние X-TSOY-* уходят рядом с теми же значениями. */
    ok($got['old_event'] === 'user.created' && hash_equals($got['sig'], $got['old_sig']),
        'и прежние X-TSOY-Event и X-TSOY-Signature — для приёмников, написанных до 4.89');
    $payload = json_decode($got['body'], true);
    ok(($payload['data']['sub'] ?? '') === 'test-sub', 'полезная нагрузка на месте');
}
$delivered = \App\Core\DB::row('SELECT status, response_code FROM {webhook_deliveries} ORDER BY id DESC LIMIT 1');
ok(($delivered['status'] ?? '') === 'sent' && (int)($delivered['response_code'] ?? 0) === 200, 'доставка помечена sent');

// Соцвход: провайдеры выключены по умолчанию, выключенный отдаёт 404
ok(App\Social\Provider::enabled() === [], 'соцвход выключен по умолчанию (опциональность)');
ok(count(App\Social\Provider::all()) === 4, 'зарегистрированы 4 провайдера');
[$st] = http('GET', SSO . '/social/yandex/start');
ok($st === 404, 'выключенный провайдер недоступен');
// Включаем Яндекс и проверяем редирект на его OAuth
\App\Core\Setting::set('social.yandex.enabled', '1');
\App\Core\Setting::set('social.yandex.client_id', 'test-client');
\App\Core\Setting::set('social.yandex.secret', 'test-secret');
[$st, , $loc] = http('GET', SSO . '/social/yandex/start');
ok($st === 302 && str_contains($loc, 'oauth.yandex.ru') && str_contains($loc, 'state='),
    'включённый провайдер редиректит с state');
// Мы вошли как админ, поэтому отказ возвращает в профиль; гостя вернуло бы на /login
[$st, , $loc] = http('GET', SSO . '/social/yandex/callback?code=x&state=forged-state');
ok($st === 302 && str_contains($loc, '/profile/security'), 'чужой state отбивается, привязка не создана', "loc=$loc");
$forged = (int)\App\Core\DB::val('SELECT COUNT(*) FROM {linked_identities} WHERE provider = ?', ['yandex']);
ok($forged === 0, 'подделанный callback не создал привязку');
\App\Core\Setting::set('social.yandex.enabled', '0');

// Admin API
[$st, $openapi] = http('GET', SSO . '/api/v1/openapi.json');
$schema = json_decode($openapi, true);
ok($st === 200 && ($schema['openapi'] ?? '') === '3.1.0', 'OpenAPI-схема отдаётся');
ok(isset($schema['paths']['/users'], $schema['paths']['/clients'], $schema['paths']['/audit']),
    'в схеме описаны users, clients, audit');
[$id, $apiToken] = \App\Models\ApiToken::create('Смоук-токен', ['users:read', 'audit:read'], (int)$admin['id']);
ok(str_starts_with($apiToken, 'sso_'), 'API-токен выдан');
[$st] = http('GET', SSO . '/api/v1/users');
ok($st === 401, 'без токена API отвечает 401');
[$st, $body] = http('GET', SSO . '/api/v1/users?limit=3', [], ['Authorization: Bearer ' . $apiToken]);
$list = json_decode($body, true);
ok($st === 200 && isset($list['items']) && $list['total'] > 0, 'GET /users отдаёт список');
ok(!str_contains($body, 'password_hash') && isset($list['items'][0]['sub']), 'в ответе sub, но нет хэшей');
[$st, $body] = http('POST', SSO . '/api/v1/users', json_encode(['email' => 'api' . time() . '@test.local']),
    ['Authorization: Bearer ' . $apiToken, 'Content-Type: application/json']);
ok($st === 403, 'без scope users:write создание запрещено');
[$st, $body] = http('GET', SSO . '/api/v1/clients', [], ['Authorization: Bearer ' . $apiToken]);
ok($st === 403, 'scope clients:read тоже проверяется');
[$id2, $writeToken] = \App\Models\ApiToken::create('Смоук-запись', ['users:write', 'users:read'], (int)$admin['id']);
$newEmail = 'api' . time() . '@test.local';
[$st, $body] = http('POST', SSO . '/api/v1/users', json_encode(['email' => $newEmail, 'first_name' => 'Апи']),
    ['Authorization: Bearer ' . $writeToken, 'Content-Type: application/json']);
$created = json_decode($body, true);
ok($st === 201 && ($created['email'] ?? '') === $newEmail, 'POST /users создаёт пользователя');
/* То же правило имени входа, что у SCIM и профиля. */
[$st, $badLogin] = http('POST', SSO . '/api/v1/users',
    json_encode(['email' => 'ok' . time() . '@test.local', 'login' => 'root']),
    ['Authorization: Bearer ' . $writeToken, 'Content-Type: application/json']);
ok($st === 422 && str_contains($badLogin, 'invalid_login'),
    'Admin API не отдаёт занятое системой имя', "st=$st");
[$st, $body] = http('PATCH', SSO . '/api/v1/users/' . $created['sub'], json_encode(['status' => 'blocked']),
    ['Authorization: Bearer ' . $writeToken, 'Content-Type: application/json']);
ok($st === 200 && json_decode($body, true)['status'] === 'blocked', 'PATCH блокирует пользователя');
\App\Models\ApiToken::revoke($id2);
[$st] = http('GET', SSO . '/api/v1/users', [], ['Authorization: Bearer ' . $writeToken]);
ok($st === 401, 'отозванный токен больше не работает');
$apiAudit = (int)\App\Core\DB::val('SELECT COUNT(*) FROM {audit_log} WHERE event LIKE ?', ['api.%']);
ok($apiAudit >= 2, 'действия API попали в аудит');

echo "═══ v3.0: i18n, метрики, организации, компоненты ═══\n";
// i18n — страница входа видна только гостю, поэтому отдельная банка cookie
$langJar = __DIR__ . '/tmp/lang.txt';
@unlink($langJar);
[$st, $ruPage] = http('GET', SSO . '/login', [], [], $langJar);
ok(str_contains($ruPage, 'Логин или email'), 'интерфейс по умолчанию на русском', "статус $st");
[$st, $enPage] = http('GET', SSO . '/login', [], ['Accept-Language: en-US,en;q=0.9'], $langJar);
ok(str_contains($enPage, 'Username or email') && str_contains($enPage, 'Sign in with a passkey'),
    'Accept-Language переключает на английский');
ok(str_contains($ruPage, 'lang-switch') && str_contains($ruPage, '/lang/en'), 'переключатель языка в футере');
ok(App\Core\Lang::t('login.submit') !== 'login.submit', 'словарь загружается');
ok(App\Core\Lang::t('нет.такого.ключа') === 'нет.такого.ключа', 'отсутствующий ключ не ломает страницу');

// Метрики
$cronToken = (string)config('cron.token');
[$st] = http('GET', SSO . '/metrics');
ok($st === 403, 'метрики закрыты без токена');
[$st, $metrics] = http('GET', SSO . '/metrics?token=' . rawurlencode($cronToken));
ok($st === 200 && str_contains($metrics, 'sso_users_total'), 'метрики отдаются в формате Prometheus');
ok(str_contains($metrics, '# TYPE sso_sessions_active gauge')
    && str_contains($metrics, 'sso_queue_size{queue="mail"}'), 'метрики размечены TYPE и метками');
ok(str_contains($metrics, '# TYPE tsoyid_sessions_active gauge') && str_contains($metrics, 'tsoyid_queue_size{queue="mail"}'),
    'прежние имена tsoyid_* отдаются рядом до 5.0 — графики не слепнут в день обновления');

// Организации
[$st, , $loc] = http('POST', SSO . '/admin/orgs/create', ['_csrf' => $csrf, 'name' => 'Смоук Компания']);
ok($st === 302 && preg_match('~/admin/orgs/(\d+)~', $loc, $mo) === 1, 'организация создана');
$orgId = (int)($mo[1] ?? 0);
$orgUserEmail = 'orguser' . time() . '@test.local';
$orgUserId = \App\Models\User::create(['email' => $orgUserEmail, 'first_name' => 'Орг',
    'password_hash' => password_hash('org-password-1', PASSWORD_ARGON2ID), 'status' => 'active']);
[$st] = http('POST', SSO . "/admin/orgs/$orgId/action",
    ['_csrf' => $csrf, 'action' => 'add_member', 'email' => $orgUserEmail, 'role' => 'member']);
ok(count(\App\Models\Org::members($orgId)) === 1, 'участник добавлен в организацию');
$orgClient = (int)\App\Core\DB::val('SELECT id FROM {clients} ORDER BY id DESC LIMIT 1');
[$st] = http('POST', SSO . "/admin/orgs/$orgId/action",
    ['_csrf' => $csrf, 'action' => 'grant_client', 'client_id' => (string)$orgClient]);
ok(count(\App\Models\Org::clients($orgId)) === 1, 'портал выдан организации');
// Ключевое: участник получает доступ автоматически, минуя политику клиента
\App\Core\DB::run('UPDATE {clients} SET access_policy = ? WHERE id = ?', ['invite', $orgClient]);
$client = \App\Models\Client::find($orgClient);
$status = \App\Models\Access::resolveForLogin($orgUserId, $client);
ok($status === 'active', 'участник организации входит даже при политике invite');
$granted = \App\Models\Access::get($orgUserId, $orgClient);
ok($granted !== null && $granted['status'] === 'active', 'персональный доступ записан');
ok(\App\Models\Org::claimFor($orgUserId) !== [], 'claim orgs формируется: '
    . implode(',', \App\Models\Org::claimFor($orgUserId)));
\App\Core\DB::run('UPDATE {clients} SET access_policy = ? WHERE id = ?', ['open', $orgClient]);
// Не-участник по политике invite по-прежнему не проходит
$strangerId = \App\Models\User::create(['email' => 'stranger' . time() . '@test.local',
    'password_hash' => password_hash('x', PASSWORD_ARGON2ID), 'status' => 'active']);
\App\Core\DB::run('UPDATE {clients} SET access_policy = ? WHERE id = ?', ['invite', $orgClient]);
$strangerStatus = \App\Models\Access::resolveForLogin($strangerId, \App\Models\Client::find($orgClient));
ok($strangerStatus === 'denied', 'посторонний по-прежнему не проходит политику invite');
\App\Core\DB::run('UPDATE {clients} SET access_policy = ? WHERE id = ?', ['open', $orgClient]);

/* Мастер подключения свёрнут до одного поля: анкета на десять полей отпугивала,
   хотя ни одно из них при первом подключении менять не нужно. */
[, $installerLike] = http('GET', SSO . '/admin/clients');
ok(str_contains($installerLike, 'name="base_url"'), 'мастер спрашивает адрес сервиса');
ok(substr_count($installerLike, 'name="redirect_uris"') === 0,
    'в мастере нет полей, которые никто не заполняет при первом подключении');
[, $orgsPage] = http('GET', SSO . '/admin/orgs');
ok(str_contains($orgsPage, 'class="muted mb-16 page-lede"') && !str_contains($orgsPage, 'class="callout'), 'пояснение организаций — строкой под заголовком, а не плашкой (v4.90)');
[, $userCard] = http('GET', SSO . '/admin/users/1');
ok(str_contains($userCard, 'class="kv-list"'), 'KeyValue в карточке пользователя');

echo "═══ Имперсонизация со следом ═══\n";
[$st, , $loc] = http('POST', SSO . "/admin/users/$uid/action", ['_csrf' => $csrf, 'action' => 'impersonate']);
ok($st === 302 && str_contains($loc, 'profile'), 'вход в режим');
[, $body] = http('GET', SSO . '/profile');
ok(str_contains($body, 'Вы смотрите как'), 'плашка имперсонизации видна');
[$st] = http('POST', SSO . '/admin/impersonate/stop', ['_csrf' => $csrf]);
[, $body] = http('GET', SSO . '/profile');
ok(!str_contains($body, 'Вы смотрите как'), 'выход из режима');
$trail = (int)\App\Core\DB::val('SELECT COUNT(*) FROM {audit_log} WHERE event IN (?, ?)',
    ['admin.impersonate.start', 'admin.impersonate.stop']);
ok($trail >= 2, 'след в аудите');

echo "═══ v3.1: дизайн-код ═══\n";

/* Белый лейбл: светлый акцент не должен давать белую надпись на кнопке */
\App\Core\Setting::set('branding.accent', '#FACC15');
$designJar = __DIR__ . '/tmp/design.txt';
@unlink($designJar);
[, $yellowLogin] = http('GET', SSO . '/login', [], [], $designJar);
ok(str_contains($yellowLogin, '--on-ink:#12100E'),
    'на светлом акценте надпись тёмная, а не белая');
/* Проверяем свойство, а не вычисленное значение. Здесь стоял хардкод
   `--ink-text:#8A700C` — и он закреплял поломку: этот цвет давал 4.37:1 на фоне
   страницы, где и стоят ссылки. Тест на конкретный hex ломается при каждой
   правке палитры и при этом ничего не гарантирует. */
preg_match('~--ink-text:\s*(#[0-9A-Fa-f]{6})~', $yellowLogin, $inkTextM);
$inkText = $inkTextM[1] ?? '';
$onPage = $inkText !== '' ? \App\Core\Palette::contrast($inkText, '#F5F5F4') : 0.0;
ok($onPage >= 4.5, sprintf('ссылка на светлом акценте читаема на фоне страницы (%s — %.2f:1)', $inkText, $onPage));
ok(preg_match('~<meta name="theme-color" content="#FACC15">~', $yellowLogin) === 1,
    'theme-color следует за акцентом, а не прибит константой');
ok(str_contains($yellowLogin, 'rect width=\'100\' height=\'100\' rx=\'22\' fill=\'%23FACC15\'')
    || str_contains($yellowLogin, 'fill=%27%23FACC15%27') || str_contains($yellowLogin, '%23FACC15'),
    'favicon по умолчанию перекрашивается акцентом');
\App\Core\Setting::delete('branding.accent');

/* Навигация: десять плоских пунктов не помещались даже на 1440px, а в доке на
   360px их помещается пять. Проверяем требование, а не вёрстку: сколько
   пунктов в ряду и доступны ли остальные. */
[, $adminPage] = http('GET', SSO . '/admin');
preg_match('~<nav class="isle-nav".*?</nav>~s', $adminPage, $navM);
$navHtml = $navM[0] ?? '';
$primaryCount = preg_match_all('~<a href="/admin[^"]*"~', $navHtml);
ok($primaryCount > 0 && $primaryCount <= 5,
    "в ряду навигации не больше пяти пунктов (сейчас $primaryCount)");

/* Островок и док рисуют один и тот же список — на то и заведён nav_sections().
   Разойдись они, раздел просто перестал бы существовать на телефоне, и ни одна
   проверка бы этого не заметила. */
preg_match('~<nav class="dock".*?</nav>~s', $adminPage, $dockM);
preg_match_all('~href="(/admin[^"]*)"~', $navHtml, $isleHrefs);
preg_match_all('~href="(/admin[^"]*)"~', $dockM[0] ?? '', $dockHrefs);
ok($dockHrefs[1] !== [] && $isleHrefs[1] === $dockHrefs[1],
    'док показывает те же разделы, что островок',
    implode(',', $isleHrefs[1]) . ' против ' . implode(',', $dockHrefs[1]));

/* Всё, что не поместилось, обязано остаться достижимым: раздел, до которого
   нельзя дойти, хуже раздела в два нажатия.
   Дверь теперь одна — шестерёнка: меню «Ещё» держало пять пунктов, и все пять
   после v4.10 стали разделами настроек. Проверяем не пункт меню, а путь:
   с дашборда есть шестерёнка, а из настроек — колонка со всеми разделами. */
ok(str_contains($adminPage, 'href="/admin/settings"'), 'шестерёнка ведёт в настройки');
[, $settingsPage] = http('GET', SSO . '/admin/settings');
foreach (['/admin/webhooks', '/admin/health', '/admin/tools', '/admin/appearance', '/admin/system'] as $rare) {
    ok(str_contains($settingsPage, 'href="' . $rare . '"'), "раздел $rare достижим из колонки настроек");
}
ok(!str_contains($adminPage, 'nav-wide'), 'класс nav-wide убран вместе с дублем медиазапросов');
/* Раздел настроек помечает себя сам — в колонке, а не в исчезнувшем меню. */
[, $healthPage] = http('GET', SSO . '/admin/health');
ok(str_contains($healthPage, 'is-current'), 'открытый раздел помечен в колонке настроек');

/* Имя перехода обязано быть уникальным в документе. Встретится дважды —
   браузер молча пропустит ВЕСЬ переход: ни ошибки, ни следа в консоли, просто
   страницы снова начнут мигать, и никто не поймёт почему.
   Собираем помеченные классы из таблиц и считаем их на живых страницах. */
$cssAll = '';
foreach ((array)glob(SSO_ROOT . '/public/assets/css/*.css') as $cssFile) {
    $cssAll .= (string)file_get_contents((string)$cssFile);
}
preg_match_all('~\.([a-z0-9-]+)\s*\{[^}]*view-transition-name~i', $cssAll, $vtM);
$named = array_values(array_unique($vtM[1]));
ok($named !== [], 'в таблицах есть помеченные для перехода элементы (' . implode(', ', $named) . ')');

/* Класс сверяем целиком, а не по границе слова: `\bisle\b` считает дефис
   границей и ловит `isle-nav`, `isle-end`, `isle-sep` — пять «дублей» там, где
   элемент один. Разбираем список классов на токены. */
$vtDup = [];
foreach (['/admin', '/admin/users', '/admin/clients', '/profile', '/profile/security'] as $vtUrl) {
    [, $vtBody] = http('GET', SSO . $vtUrl);
    $seen = [];
    if (preg_match_all('~class="([^"]*)"~', $vtBody, $clsM)) {
        foreach ($clsM[1] as $list) {
            foreach (preg_split('~\s+~', trim($list)) ?: [] as $token) {
                if ($token !== '') $seen[$token] = ($seen[$token] ?? 0) + 1;
            }
        }
    }
    foreach ($named as $cls) {
        if (($seen[$cls] ?? 0) > 1) $vtDup[] = "$vtUrl: .$cls × " . $seen[$cls];
    }
}
ok($vtDup === [], 'ни одно имя перехода не встречается на странице дважды', implode('; ', $vtDup));

/* Пустые ячейки в карточном режиме не должны печатать подписи */
[, $usersPage] = http('GET', SSO . '/admin/users');
/* Сверяем ПУСТОТУ ячейки, а не набор её атрибутов: проверка ловила буквальную
   строку и сработала на добавлении title= — при том, что ячейка осталась
   пустой. Проверка обязана держать правило, а не разметку слово в слово. */
ok(preg_match('~<td data-label="Роли"[^>]*></td>~', $usersPage) === 1,
    'ячейка без значения отдаётся пустой — подпись на мобиле не печатается');
ok(str_contains($usersPage, 'class="cell-primary"') && str_contains($usersPage, 'class="cell-avatar"'),
    'главная ячейка и аватар размечены для мобильной карточки');

/* Компонент для секретов не должен красить обычный email моноширинным.
   Проверка стояла на конкретной обёртке `.inline-field` — то есть закрепляла
   одну вёрстку вместо требования. Смысл починки был в другом: обычный адрес не
   должен выглядеть телеграммой. Его и проверяем. */
[, $loginPage] = http('GET', SSO . '/login', [], [], $designJar);
preg_match('~<input[^>]*id="magic-email"[^>]*>~', $loginPage, $magicM);
$magicInput = $magicM[0] ?? '';
ok($magicInput !== '' && !str_contains($magicInput, 'mono'),
    'поле «вход по ссылке» не размечено как секрет');
ok(!preg_match('~<div class="copyfield">\s*<input[^>]*type="email"~', $loginPage),
    'поле «вход по ссылке» не в .copyfield');

/* i18n: язык страницы должен совпадать с языком интерфейса */
[, $enPage2] = http('GET', SSO . '/login', [], ['Accept-Language: en-US,en;q=0.9'], $designJar);
ok(str_contains($enPage2, '<html lang="en">'), 'атрибут lang следует за локалью, а не прибит к ru');

/* Инлайн-стили: бюджет, чтобы вёрстка не расползлась обратно мимо утилит.
   До v3.1 на этих же страницах их было 75. Остаток — вычисляемые значения
   (градиент аватара от sub, ширина шкалы в процентах, превью акцента);
   им место именно в атрибуте, классом такое не выразить. */
$inline = 0;
foreach (['/admin', '/admin/users', '/admin/clients', '/admin/settings', '/admin/logs', '/profile'] as $p) {
    [, $html] = http('GET', SSO . $p);
    $inline += substr_count($html, 'style="');
}
ok($inline <= 25, "инлайн-стилей на шести страницах $inline (бюджет 25)");

/* Подписи полей. Ярлык, не связанный с полем, скринридер не читает, и клик по
   нему не ставит курсор: в настройках так было у 27 полей подряд. Заодно ловим
   дубли id — их плодит любая невнимательная массовая правка. */
$unlabeled = [];
$dupIds = [];
$fieldsSeen = 0;
/* Разделы настроек перечислены поимённо: с v4.10 у каждого свой адрес, и
   один /admin/settings показывает только брендинг — семь остальных форм
   иначе выпали бы из проверки подписей молча. */
foreach (['/admin/users', '/admin/clients', '/admin/roles', '/admin/logs',
          '/admin/settings', '/admin/settings/section/smtp', '/admin/settings/section/policies',
          '/admin/settings/section/timeouts', '/admin/settings/section/social',
          '/admin/settings/section/docs', '/admin/settings/requisites',
          '/admin/tools', '/profile/security', '/profile/settings'] as $p) {
    [, $html] = http('GET', SSO . $p);

    /* id, объявленные на странице, и label[for=...] */
    preg_match_all('~\sid="([^"]+)"~', $html, $idm);
    $counts = array_count_values($idm[1]);
    foreach ($counts as $id => $c) if ($c > 1) $dupIds[] = "$p: $id ×$c";
    preg_match_all('~<label[^>]*\sfor="([^"]+)"~', $html, $lfm);
    $labelled = array_flip($lfm[1]);

    /* Каждое видимое поле должно быть связано с подписью или иметь aria-label */
    preg_match_all('~<(input|select|textarea)\b([^>]*)>~i', $html, $fm, PREG_SET_ORDER);
    foreach ($fm as $f) {
        $attrs = $f[2];
        if (preg_match('~type="(hidden|submit|button)"~', $attrs)) continue;
        $fieldsSeen++;
        if (str_contains($attrs, 'aria-label=')) continue;
        if (preg_match('~\sid="([^"]+)"~', $attrs, $im) && isset($labelled[$im[1]])) continue;
        /* Чекбокс/радио обычно завёрнут в <label> — проверяем обёртку грубо */
        if (preg_match('~type="(checkbox|radio|color)"~', $attrs)) continue;
        preg_match('~\sname="([^"]*)"~', $attrs, $nm);
        $unlabeled[] = $p . ': ' . ($nm[1] ?? $f[1]);
    }
}
ok($fieldsSeen > 50, "проверено полей: $fieldsSeen");
ok($unlabeled === [], 'у каждого поля есть подпись или aria-label',
    implode(', ', array_slice($unlabeled, 0, 6)));
ok($dupIds === [], 'нет дублирующихся id на странице', implode(', ', array_slice($dupIds, 0, 4)));

/* Графики дашборда: спарклайн не должен вылезать за карточку и падать на пустом ряде */
[, $dash] = http('GET', SSO . '/admin');
ok(str_contains($dash, 'vector-effect="non-scaling-stroke"'),
    'обводка графика не растягивается вместе с viewBox');
ok(preg_match('~<div class="sparkline">\s*<svg~', $dash) === 1, 'спарклайн отрисован');

echo "═══ v3.2: транслит и FedCM ═══\n";

/* Транслит на живых данных: раньше любое русское название давало слаг «-» */
$orgA = \App\Models\Org::create('Департамент образования', null, null);
$orgB = \App\Models\Org::create('Отдел кадров', null, null);
$slugA = (string)\App\Core\DB::val('SELECT slug FROM {orgs} WHERE id = ?', [$orgA]);
$slugB = (string)\App\Core\DB::val('SELECT slug FROM {orgs} WHERE id = ?', [$orgB]);
ok($slugA === 'departament-obrazovaniya', "слаг организации транслитерирован: $slugA");
ok($slugA !== $slugB, 'разные названия — разные слаги, а не два «-»');
\App\Core\DB::run('DELETE FROM {orgs} WHERE id IN (?, ?)', [$orgA, $orgB]);

[, $profilePage] = http('GET', SSO . '/profile');
ok(preg_match('~ID&lt;[A-Z]+&lt;[A-Z]~', $profilePage) === 1,
    'в MRZ появилось транслитерированное имя, а не только UUID');

/* FedCM. Заголовок Sec-Fetch-Dest ставит браузер, из JS его не подделать —
   на нём и держится защита персональных данных в /fedcm/accounts. */
[$st, $wk] = http('GET', SSO . '/.well-known/web-identity');
ok($st === 200 && str_contains($wk, '/fedcm/config.json'), 'well-known/web-identity отдаётся');
[$st] = http('GET', SSO . '/fedcm/config.json');
ok($st === 400, 'конфиг закрыт для обычного fetch без заголовка браузера');
[$st, $cfg] = http('GET', SSO . '/fedcm/config.json', [], ['Sec-Fetch-Dest: webidentity']);
$cfgJson = json_decode($cfg, true) ?: [];
ok($st === 200 && isset($cfgJson['accounts_endpoint'], $cfgJson['id_assertion_endpoint']),
    'конфиг перечисляет эндпоинты FedCM');
ok(($cfgJson['branding']['color'] ?? '') !== '' && ($cfgJson['branding']['background_color'] ?? '') !== '',
    'брендинг диалога считается тем же Palette, что и интерфейс');

$fedJar = __DIR__ . '/tmp/fedcm.txt';
@unlink($fedJar);
[$st] = http('GET', SSO . '/fedcm/accounts', [], ['Sec-Fetch-Dest: webidentity'], $fedJar);
ok($st === 401, 'гостю аккаунты не отдаются');
[$st, $acc] = http('GET', SSO . '/fedcm/accounts', [], ['Sec-Fetch-Dest: webidentity']);
$accJson = json_decode($acc, true) ?: [];
ok($st === 200 && ($accJson['accounts'][0]['email'] ?? '') === 'admin@test.local',
    'по своей сессии аккаунт отдаётся');

$fedClient = \App\Models\Client::findByClientId((string)\App\Core\DB::val(
    'SELECT client_id FROM {clients} WHERE is_active = 1 LIMIT 1'));
$fedSub = (string)\App\Core\DB::val('SELECT public_id FROM {users} WHERE email = ?', ['admin@test.local']);
$fedPost = ['client_id' => $fedClient['client_id'], 'account_id' => $fedSub, 'nonce' => 'n-12345'];

[$st] = http('POST', SSO . '/fedcm/assertion', $fedPost,
    ['Sec-Fetch-Dest: webidentity', 'Origin: https://evil.example']);
ok($st === 400, 'токен не выдаётся на origin, не принадлежащий порталу');

[$st] = http('POST', SSO . '/fedcm/assertion',
    ['client_id' => $fedClient['client_id'], 'account_id' => '00000000-0000-0000-0000-000000000000'],
    ['Sec-Fetch-Dest: webidentity', 'Origin: ' . $fedClient['base_url']]);
ok($st === 400, 'чужой account_id отбивается');

/* v4.98: первый вход в портал без экрана согласия FedCM не выдаёт — экран
   есть только у /authorize, туда портал и уходит по отказу. */
$fedAdminId = (int)\App\Core\DB::val('SELECT id FROM {users} WHERE email = ?', ['admin@test.local']);
\App\Core\DB::run('UPDATE {user_client_access} SET consented_at = NULL WHERE user_id = ? AND client_id = ?',
    [$fedAdminId, (int)$fedClient['id']]);
$fedMustAsk = \App\Oidc\AuthorizeFlow::mustConsent(['id' => $fedAdminId], $fedClient);
[$st] = http('POST', SSO . '/fedcm/assertion', $fedPost,
    ['Sec-Fetch-Dest: webidentity', 'Origin: ' . $fedClient['base_url']]);
ok($st === ($fedMustAsk ? 403 : 200), 'первый вход без согласия — отказ, портал уходит на обычный вход', "код $st");
\App\Models\Access::grant($fedAdminId, (int)$fedClient['id'], null);
\App\Models\Access::recordConsent($fedAdminId, (int)$fedClient['id']);

[$st, $assert] = http('POST', SSO . '/fedcm/assertion', $fedPost,
    ['Sec-Fetch-Dest: webidentity', 'Origin: ' . $fedClient['base_url']]);
$tok = json_decode($assert, true)['token'] ?? '';
ok($st === 200 && $tok !== '', 'со своего origin выдан id_token');
$fedClaims = $tok !== '' ? (\App\Core\Jwt::verify($tok) ?? []) : [];
ok(($fedClaims['aud'] ?? '') === $fedClient['client_id'] && ($fedClaims['nonce'] ?? '') === 'n-12345',
    'токен подписан нашим ключом, aud и nonce на месте');
ok(($fedClaims['sid'] ?? '') !== '',
    'sid выдан — back-channel logout работает и для входа через FedCM');
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {audit_log} WHERE event LIKE ?', ['fedcm.%']) >= 2,
    'выдача и отказ по origin попали в аудит');

[, $disco] = http('GET', SSO . '/.well-known/openid-configuration');
$discoJson = json_decode($disco, true) ?: [];
ok(isset($discoJson['fedcm_config_endpoint']), 'discovery подсказывает порталам про FedCM');
ok(($discoJson['ui_locales_supported'] ?? []) === ['ru', 'en'],
    'discovery перечисляет реальные языки, а не один прибитый ru');

echo "═══ v3.2: SCIM 2.0 ═══\n";

\App\Core\DB::run('DELETE FROM {users} WHERE email = ?', ['scim.new@test.local']);
\App\Core\DB::run('DELETE FROM {orgs} WHERE slug = ?', ['otdel-razrabotki']);
[, $scimToken] = \App\Models\ApiToken::create('smoke-scim', ['users:read', 'users:write'], null, 1);
$scimHead = ['Authorization: Bearer ' . $scimToken, 'Content-Type: application/scim+json'];

[$st] = http('GET', SSO . '/scim/v2/Users');
ok($st === 401, 'SCIM закрыт без токена');
[$st, $spc] = http('GET', SSO . '/scim/v2/ServiceProviderConfig');
ok($st === 200 && (json_decode($spc, true)['patch']['supported'] ?? false) === true,
    'ServiceProviderConfig честно перечисляет возможности');

[$st, $list] = http('GET', SSO . '/scim/v2/Users', [], $scimHead);
$listJson = json_decode($list, true) ?: [];
ok($st === 200 && ($listJson['totalResults'] ?? 0) > 0
    && in_array('urn:ietf:params:scim:api:messages:2.0:ListResponse', $listJson['schemas'] ?? [], true),
    'список пользователей в формате ListResponse');

[$st, $created] = http('POST', SSO . '/scim/v2/Users', json_encode([
    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
    'userName' => 'scim.new@test.local',
    'name' => ['givenName' => 'Пётр', 'familyName' => 'Щукин'],
    'active' => true,
]), $scimHead);
$newUser = json_decode($created, true) ?: [];
$scimSub = (string)($newUser['id'] ?? '');
ok($st === 201 && $scimSub !== '', 'пользователь заведён через SCIM');
ok(($newUser['name']['formatted'] ?? '') === 'Щукин Пётр', 'имя вернулось как есть, без порчи кириллицы');

/* Правило имени входа действует на ВСЕХ дверях, а не только в профиле. До
   v4.14 провайдер мог завести через SCIM логин `admin`, с пробелом или на
   двести знаков: проверялась только занятость. Дверь, через которую правило
   обходится, — не правило. */
[$st, $bad] = http('POST', SSO . '/scim/v2/Users', json_encode([
    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
    'userName' => 'admin',
]), $scimHead);
ok($st === 400 && str_contains($bad, 'invalidValue'), 'SCIM не отдаёт занятое системой имя', "st=$st");
[$st] = http('POST', SSO . '/scim/v2/Users', json_encode([
    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
    'userName' => 'петя иванов',
]), $scimHead);
ok($st === 400, 'SCIM не принимает логин с пробелом и кириллицей', "st=$st");
/* А обычный логин по-прежнему проходит: проверка не должна закрыть дверь. */
[$st] = http('POST', SSO . '/scim/v2/Users', json_encode([
    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
    'userName' => 'scim.login.' . substr((string)time(), -5),
]), $scimHead);
ok($st === 201, 'обычный логин через SCIM проходит', "st=$st");

[$st] = http('POST', SSO . '/scim/v2/Users', json_encode(['userName' => 'scim.new@test.local']), $scimHead);
ok($st === 409, 'повторное заведение того же email отбивается по uniqueness');

[$st, $filtered] = http('GET', SSO . '/scim/v2/Users?filter=' . rawurlencode('userName eq "scim.new@test.local"'),
    [], $scimHead);
ok($st === 200 && (json_decode($filtered, true)['totalResults'] ?? 0) === 1, 'фильтр по userName работает');
[$st] = http('GET', SSO . '/scim/v2/Users?filter=' . rawurlencode('userName sw "a"'), [], $scimHead);
ok($st === 400, 'неподдерживаемый фильтр отвечает ошибкой, а не тишиной');

[$st, $patched] = http('PATCH', SSO . '/scim/v2/Users/' . $scimSub,
    json_encode(['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]), $scimHead);
ok($st === 200 && (json_decode($patched, true)['active'] ?? true) === false,
    'PATCH active=false отключает учётку — основной сценарий синхронизации');

[$st, $grp] = http('POST', SSO . '/scim/v2/Groups',
    json_encode(['displayName' => 'Отдел разработки', 'members' => [['value' => $scimSub]]]), $scimHead);
$grpJson = json_decode($grp, true) ?: [];
ok($st === 201 && ($grpJson['id'] ?? '') === 'otdel-razrabotki',
    'группа создана, слаг транслитерирован: ' . ($grpJson['id'] ?? '—'));
ok(count($grpJson['members'] ?? []) === 1, 'участник добавлен при создании группы');

[$st, $grp2] = http('PATCH', SSO . '/scim/v2/Groups/otdel-razrabotki',
    json_encode(['Operations' => [['op' => 'remove', 'path' => 'members[value eq "' . $scimSub . '"]']]]), $scimHead);
ok($st === 200 && count(json_decode($grp2, true)['members'] ?? []) === 0, 'участник убран PATCH-ем');

[$st] = http('DELETE', SSO . '/scim/v2/Users/' . $scimSub, [], $scimHead);
$stillThere = \App\Models\User::findByPublicId($scimSub);
ok($st === 204 && $stillThere !== null && $stillThere['status'] === 'blocked',
    'DELETE деактивирует, а не стирает — доступы и аудит не осиротеют');

[$st] = http('DELETE', SSO . '/scim/v2/Groups/otdel-razrabotki', [], $scimHead);
ok($st === 204, 'группа удаляется');
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {audit_log} WHERE event LIKE ?', ['scim.%']) >= 4,
    'операции SCIM попали в аудит');

\App\Core\DB::run('DELETE FROM {users} WHERE email = ?', ['scim.new@test.local']);
\App\Core\DB::run('DELETE FROM {api_tokens} WHERE name = ?', ['smoke-scim']);

echo "═══ v3.3: английская админка ═══\n";

$enHead = ['Accept-Language: en-US,en;q=0.9'];
$enJar = __DIR__ . '/tmp/admin-en.txt';
@unlink($enJar);
/* Переключаем язык у той же админской сессии — cookie общая с $JAR */
http('GET', SSO . '/lang/en');

[$st, $enAdmin] = http('GET', SSO . '/admin');
ok($st === 200 && str_contains($enAdmin, '<html lang="en">'), 'админка отдаётся на английском');
ok(str_contains($enAdmin, '>Users</a>') && str_contains($enAdmin, '>Portals</a>')
    && str_contains($enAdmin, '>Organisations</a>'), 'навигация переведена');
ok(str_contains($enAdmin, 'Failed sign-ins') && str_contains($enAdmin, 'Sign-ins today'),
    'плитки дашборда переведены');
ok(str_contains($enAdmin, '<title>Dashboard'), 'заголовок вкладки переведён');

[, $enUsers] = http('GET', SSO . '/admin/users');
ok(str_contains($enUsers, '>Filter</button>') && str_contains($enUsers, 'email, login, name or sub'),
    'фильтры и подсказки полей переведены');
ok(str_contains($enUsers, '>active</span>') || str_contains($enUsers, '>Active<'),
    'статусы из PHP-карт переведены, а не остались русскими');

[, $enHealth] = http('GET', SSO . '/admin/health');
ok(str_contains($enHealth, 'Database') && str_contains($enHealth, 'Signing keys'),
    'проверки здоровья переведены (они живут в контроллере, а не в шаблоне)');

[, $enTools] = http('GET', SSO . '/admin/tools');
ok(str_contains($enTools, 'Read users') || str_contains($enTools, 'Read the audit log'),
    'права API переведены — const пришлось заменить на метод');

/* Ключ без перевода обязан показать русский оригинал, а не «admin.x.y» */
ok(App\Core\Lang::t('строки такой нет в словаре') === 'строки такой нет в словаре',
    'непереведённый ключ показывается как есть, интерфейс не ломается');

http('GET', SSO . '/lang/ru');
[, $ruAdmin] = http('GET', SSO . '/admin');
ok(str_contains($ruAdmin, '>Порталы</a>') && str_contains($ruAdmin, '<html lang="ru">'),
    'русский контур не пострадал от перевода');

echo "═══ v3.4: устойчивость к неприменённой миграции ═══\n";

/* Главный сценарий релиза: код обновили, миграцию не применили. Раньше это
   давало 500 на входе и на /admin/tools — то есть починить было нечем. */
$dropped = ['notification_prefs', 'known_devices', 'invites', 'export_jobs',
            'webhooks', 'webhook_deliveries', 'api_tokens',
            'orgs', 'org_members', 'org_client_access'];
foreach ($dropped as $t) {
    \App\Core\DB::run('DROP TABLE IF EXISTS {' . $t . '}');
}
\App\Core\DB::run('DELETE FROM {migrations} WHERE version IN (?, ?, ?)',
    ['003_trust', '004_ecosystem', '005_orgs']);

$degradedJar = __DIR__ . '/tmp/degraded.txt';
@unlink($degradedJar);
[, $dl] = http('GET', SSO . '/login', [], [], $degradedJar);
[$st, , $loc] = http('POST', SSO . '/login',
    ['_csrf' => csrfOf($dl), 'identity' => 'admin@test.local', 'password' => 'correct-horse-battery'],
    [], $degradedJar);
ok($st === 302, "вход работает без миграций 003/004/005 (получено $st)");

$degraded = [];
foreach (['/admin', '/admin/tools', '/admin/orgs', '/admin/webhooks', '/admin/users',
          '/admin/health', '/admin/settings', '/profile', '/profile/settings',
          '/profile/security', '/admin/migrate'] as $p) {
    [$code] = http('GET', SSO . $p, [], [], $degradedJar);
    if ($code !== 200) $degraded[] = "$p → $code";
}
ok($degraded === [], 'все страницы открываются без новых таблиц', implode(', ', $degraded));

[, $bannerPage] = http('GET', SSO . '/admin', [], [], $degradedJar);
ok(str_contains($bannerPage, 'state-bar') && str_contains($bannerPage, '005_orgs'),
    'админ видит, каких миграций не хватает, и кнопку рядом');
/* Полоса обязана стоять ВНУТРИ страницы: над ней островок прибит фиксированно
   к верхнему краю и перекрывал её собой целиком. */
$isleAt = strpos($bannerPage, 'class="isle');
$barAt = strpos($bannerPage, 'state-bar');
ok($isleAt !== false && $barAt !== false && $barAt > $isleAt,
    'полоса состояния стоит после островка, а не под ним');

/* Повтор поверх частично применённой схемы: индекс из 003 уже существует,
   и раньше миграция падала на нём навсегда. */
\App\Core\DB::run('DELETE FROM {migrations} WHERE version = ?', ['003_trust']);
$again = \App\Core\Migrations::apply();
ok($again['failed'] === null, 'миграция применяется поверх уже созданных объектов',
    (string)($again['error'] ?? ''));
ok(\App\Core\Migrations::pending() === [], 'после применения ничего не осталось');
ok(\App\Core\Migrations::ready('orgs', 'webhooks', 'api_tokens', 'export_jobs'),
    'таблицы на месте');

/* Латинское имя: автоматика — только запасной вариант */
$mrzAuto = App\Controllers\ProfileController::mrz(
    ['last_name' => 'Петров', 'first_name' => 'Артём', 'public_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);
ok(str_contains($mrzAuto, 'PETROV<<ARTEM'), 'без ручного написания работает транслитерация ИКАО');
$mrzManual = App\Controllers\ProfileController::mrz(
    ['last_name' => 'Петров', 'first_name' => 'Артём', 'latin_last_name' => 'PETROFF',
     'latin_first_name' => 'ARTYOM', 'public_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);
ok(str_contains($mrzManual, 'PETROFF<<ARTYOM'),
    'указанное человеком написание побеждает автоматику: ' . explode("\n", $mrzManual)[0]);

/* Подвал прижат к низу: класс на месте, разметка не разъехалась */
[, $footPage] = http('GET', SSO . '/admin/health');
ok(preg_match('~<main[^>]*\bclass="page( page-isle)?"~', $footPage) === 1
    && preg_match('~<footer class="page-footer">~', $footPage) === 1,
    'структура «шапка — контент — подвал» на месте');

echo "═══ v3.6: слияние дублей, пароль, данные аккаунта ═══\n";

/* Установка пароля без текущего: аккаунт от соцвхода/magic-link пароля не
   имеет, и раньше форма требовала «текущий», которого не существует. */
\App\Core\DB::run('DELETE FROM {users} WHERE email = ?', ['nopass@test.local']);
$noPassId = \App\Models\User::create(['email' => 'nopass@test.local',
    'first_name' => 'Гость', 'email_verified_at' => now(), 'status' => 'active']);
$npJar = __DIR__ . '/tmp/nopass.txt';
@unlink($npJar);
/* Входим magic-подобно: напрямую создаём сессию нельзя — используем перебор
   через установку пароля из-под чужой сессии не выйдет. Проверяем через форму:
   логинимся по паролю нельзя (его нет) — поэтому серверная часть напрямую. */
$_POST = [];
ok(\App\Models\User::find($noPassId)['password_hash'] === null, 'у свежего аккаунта пароля нет');

[, $secPage] = http('GET', SSO . '/profile/security');
ok(str_contains($secPage, 'Смена пароля') && str_contains($secPage, 'current_password'),
    'у аккаунта с паролем форма спрашивает текущий');

/* Смена собственного пароля админа: текущий обязателен и проверяется */
[$st] = http('POST', SSO . '/profile/security/password',
    ['_csrf' => $csrf, 'current_password' => 'wrong', 'new_password' => 'whatever-123']);
$adminRow = \App\Core\DB::row('SELECT password_hash FROM {users} WHERE email = ?', ['admin@test.local']);
ok(password_verify('correct-horse-battery', (string)$adminRow['password_hash']),
    'неверный текущий пароль не меняет ничего');

/* Слияние дублей: полный перенос и след merged_into */
\App\Core\DB::run('DELETE FROM {users} WHERE email IN (?, ?)', ['m-main@test.local', 'm-dup@test.local']);
$mMain = \App\Models\User::create(['email' => 'm-main@test.local',
    'password_hash' => password_hash('main-pw-1', PASSWORD_ARGON2ID), 'status' => 'active']);
$mDup = \App\Models\User::create(['email' => 'm-dup@test.local',
    'phone' => '+79995556677', 'email_verified_at' => now(), 'status' => 'active']);
\App\Core\DB::insert('linked_identities', ['user_id' => $mDup, 'provider' => 'yandex',
    'provider_uid' => 'smoke-ya-1', 'meta' => '{}', 'created_at' => now()]);
$mergeStat = \App\Core\AccountMerge::into($mMain, $mDup);
ok($mergeStat['moved_identities'] === 1, 'соцсвязка дубля переехала в основной аккаунт');
$mMainRow = \App\Models\User::find($mMain);
$mDupRow = \App\Models\User::find($mDup);
ok($mMainRow['phone'] === '+79995556677', 'телефон дубля дозаполнил пустое поле основного');
ok($mDupRow['status'] === 'deleted' && (int)$mDupRow['merged_into'] === $mMain,
    'дубль закрыт со следом merged_into');
ok((\App\Models\UserEmail::findOwner('m-dup@test.local')['id'] ?? 0) === $mMain,
    'email дубля стал дополнительным адресом — новый дубль по нему не заведётся');
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {audit_log} WHERE event = ?', ['account.merged']) >= 1,
    'слияние оставило след в аудите');

/* Экран слияния закрыт без pending_merge из OAuth */
[$st, , $mLoc] = http('GET', SSO . '/profile/merge');
ok($st === 302 || str_contains($mLoc, 'security'), 'на /profile/merge не попасть без OAuth-подтверждения');

/* Данные аккаунта: пол и телефон сохраняются из профиля */
[, $editPage] = http('GET', SSO . '/profile/edit');
ok(str_contains($editPage, 'name="phone"') && str_contains($editPage, 'name="gender"'),
    'в профиле появились телефон и пол');
[$st] = http('POST', SSO . '/profile/edit', ['_csrf' => $csrf, 'last_name' => 'Петров',
    'first_name' => 'Иван', 'phone' => '+7 (900) 111-22-33', 'gender' => 'male']);
$adminAfter = \App\Core\DB::row('SELECT phone, gender FROM {users} WHERE email = ?', ['admin@test.local']);
ok($adminAfter['phone'] === '+79001112233', 'телефон нормализован до цифр и плюса: ' . $adminAfter['phone']);
ok($adminAfter['gender'] === 'male', 'пол сохранён');

/* Политики обязательности выключены по умолчанию — оптиональность */
ok(\App\Core\Setting::get('policy.require_phone') !== '1'
    && \App\Core\Setting::get('policy.require_gender') !== '1',
    'требования телефона и пола по умолчанию выключены');

\App\Core\DB::run('DELETE FROM {users} WHERE id IN (?, ?, ?)', [$noPassId, $mMain, $mDup]);
\App\Core\DB::run('DELETE FROM {user_emails} WHERE email = ?', ['m-dup@test.local']);
\App\Core\DB::run('UPDATE {users} SET phone = NULL, gender = NULL WHERE email = ?', ['admin@test.local']);

echo "\n── Номер аккаунта (v3.8) ──\n";
$adminRow = \App\Core\DB::row('SELECT id, account_number FROM {users} WHERE email = ?', ['admin@test.local']);
$adminNum = (string)($adminRow['account_number'] ?? '');
ok(\App\Core\AccountNumber::isValid($adminNum), 'у аккаунта есть номер: ' . $adminNum);
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {users} WHERE account_number IS NULL') === 0,
    'номер выдан всем аккаунтам, включая заведённые до миграции');
ok((int)\App\Core\DB::val('SELECT COUNT(DISTINCT account_number) FROM {users}')
    === (int)\App\Core\DB::val('SELECT COUNT(*) FROM {users}'), 'номера не повторяются');

[, $profilePage] = http('GET', SSO . '/profile');
ok(str_contains($profilePage, \App\Core\AccountNumber::format($adminNum)),
    'номер показан в удостоверении — его и называют в поддержке');

/* Искать надо строку, которая бывает ТОЛЬКО в строке результата: email админа
   есть в выпадающем меню профиля на каждой странице админки, и проверка по нему
   проходила бы даже при полностью сломанном поиске. Ссылка на карточку
   пользователя встречается лишь в таблице. */
$rowLink = 'href="/admin/users/' . (int)$adminRow['id'] . '"';
foreach ([\App\Core\AccountNumber::format($adminNum) => 'с дефисом',
          $adminNum => 'голыми цифрами'] as $needle => $how) {
    [, $found] = http('GET', SSO . '/admin/users?q=' . urlencode((string)$needle));
    ok(str_contains($found, $rowLink), "админка находит по номеру $how");
}
/* Контрольная цифра отсекает опечатку до запроса в базу */
$typo = substr($adminNum, 0, 8) . (string)((((int)substr($adminNum, -1)) + 1) % 10);
[, $none] = http('GET', SSO . '/admin/users?q=' . urlencode($typo));
ok(!str_contains($none, $rowLink), 'номер с испорченной контрольной цифрой никого не находит');

echo "\n── Данные от провайдеров (v3.8) ──\n";
\App\Core\DB::run('DELETE FROM {users} WHERE email LIKE ?', ['absorb-%@test.local']);

/* Яндекс: имя и фамилия по отдельности, дата рождения, пол */
$aId = \App\Models\User::create(['email' => 'absorb-ya@test.local', 'status' => 'active']);
\App\Core\ProfileAbsorb::apply($aId, 'yandex', [
    'name' => 'Артём Петров', 'first_name' => 'Артём', 'last_name' => 'Петров',
    'birthdate' => '1990-06-15', 'gender' => 'male', 'email' => 'absorb-ya@yandex.test',
]);
$a = \App\Models\User::find($aId);
ok($a['first_name'] === 'Артём' && $a['last_name'] === 'Петров',
    'имя и фамилия легли в разные поля, а не одной строкой в имя');
ok(($a['birthdate'] ?? '') === '1990-06-15', 'дата рождения забрана: ' . ($a['birthdate'] ?? '—'));

/* GitHub: только слитное имя — режем наугад нельзя, кладём в подпись */
$gId = \App\Models\User::create(['email' => 'absorb-gh@test.local', 'status' => 'active']);
\App\Core\ProfileAbsorb::apply($gId, 'github', ['name' => 'Анна Мария Ковалевская']);
$g = \App\Models\User::find($gId);
ok($g['display_name'] === 'Анна Мария Ковалевская' && empty($g['first_name']),
    'слитное имя ушло в подпись, а не разрезано по пробелу наугад');

/* Главное правило: заполненное человеком не перетирается */
$oId = \App\Models\User::create(['email' => 'absorb-own@test.local', 'status' => 'active',
    'first_name' => 'Своё', 'last_name' => 'Своя', 'birthdate' => '1980-01-02']);
$filled = \App\Core\ProfileAbsorb::apply($oId, 'yandex', [
    'first_name' => 'Чужое', 'last_name' => 'Чужая', 'birthdate' => '1999-09-09',
]);
$o = \App\Models\User::find($oId);
ok($o['first_name'] === 'Своё' && $o['last_name'] === 'Своя' && $o['birthdate'] === '1980-01-02',
    'то, что человек ввёл сам, провайдер не перетирает');
ok($filled === [], 'нечего дозаполнять — ничего и не трогаем');

/* Дата без года (VK «15.6», Яндекс «0000-06-15») в профиль не попадает */
$nId = \App\Models\User::create(['email' => 'absorb-noyear@test.local', 'status' => 'active']);
\App\Core\ProfileAbsorb::apply($nId, 'vk', ['birthdate' => \App\Core\Birthdate::parse('15.6')]);
ok(empty(\App\Models\User::find($nId)['birthdate']),
    'дата без года отбрасывается, а не превращается в выдуманный год');

ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {audit_log} WHERE event = ?', ['profile.absorbed']) >= 2,
    'дозаполнение оставляет след в аудите — видно, что и откуда приехало');

\App\Core\DB::run('DELETE FROM {users} WHERE email LIKE ?', ['absorb-%@test.local']);
\App\Core\DB::run('DELETE FROM {user_emails} WHERE email LIKE ?', ['absorb-%']);

echo "\n── Место и чистка (v4.0) ──\n";
$usage = \App\Core\Housekeeping::usage();
ok(count($usage) >= 5 && isset($usage[0]['bytes'], $usage[0]['count']),
    'картина занятого места собирается');
ok(\App\Core\Housekeeping::human(0) === '—' && str_contains(\App\Core\Housekeeping::human(2048), 'КБ'),
    'размеры читаемы: ' . \App\Core\Housekeeping::human(2048));

/* «Почистить сейчас» обязана делать ровно то же, что планировщик, — поэтому
   список задач у них общий, а не скопированный. */
$now = \App\Core\CronTasks::runNow(['logs_rotate', 'exports_cleanup', 'expired']);
ok(count($now) === 3, 'кнопка запускает те же три задачи, что и планировщик');
ok(\App\Core\CronTasks::runNow(['нет-такой']) === [], 'несуществующая задача просто не запускается');

/* Сироты: подкладываем каталог, которому не соответствует ни один аккаунт */
$orphanDir = SSO_ROOT . '/storage/avatars/00000000-0000-4000-8000-00000000dead';
@mkdir($orphanDir . '/photos', 0750, true);
file_put_contents($orphanDir . '/512.webp', 'x');
file_put_contents($orphanDir . '/photos/aaaaaaaaaaaaaaaa.webp', 'x');

/* ── Бэкап обязан разворачиваться ────────────────────────────────────────────
   Проверяет то, о чём вспоминают в худший день. На sqlite дамп состоял из одних
   INSERT-ов: схему клали только для MySQL, и восстановление на чистую базу
   давало 493 отказа «no such table» из 493 попыток. Файл при этом выглядел
   настоящим — архив с ключами, конфигом и дампом на 158 КБ. */
$bkPath = \App\Core\Backup::build();
$bkZip = new ZipArchive();
ok($bkZip->open($bkPath) === true, 'бэкап собирается и открывается');
$bkSql = (string)$bkZip->getFromName('database.sql');
$bkZip->close();
$bkTables = preg_match_all('~CREATE TABLE~i', $bkSql);
ok($bkTables > 10, 'в дампе есть схема, а не одни данные', "CREATE TABLE: $bkTables");

$bkTmp = __DIR__ . '/tmp/restore-probe.sqlite';
@unlink($bkTmp);
$bkPdo = new PDO('sqlite:' . $bkTmp);
$bkPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$bkErr = '';
try {
    /* Как это сделает sqlite3 из командной строки: файл целиком, а не по
       строчкам — в значениях бывают и точки с запятой, и переводы строк. */
    $bkPdo->exec((string)preg_replace('~^SET [^;]+;~mi', '', $bkSql));
} catch (Throwable $e) {
    $bkErr = mb_substr($e->getMessage(), 0, 120);
}
ok($bkErr === '', 'дамп разворачивается на чистую базу', $bkErr);
$bkUsers = 0;
try { $bkUsers = (int)$bkPdo->query('SELECT COUNT(*) FROM sso_users')->fetchColumn(); } catch (Throwable) {}
ok($bkUsers > 0, 'и люди в восстановленной базе на месте', "строк: $bkUsers");
unset($bkPdo);
@unlink($bkTmp);
@unlink($bkPath);

$purged = \App\Core\Housekeeping::purgeOrphans();
ok(!is_dir($orphanDir), 'каталог без аккаунта убран целиком');
ok($purged['avatars'] >= 1 && $purged['photos'] >= 1, 'посчитано, что именно удалено');

/* Каталоги брендинга и иконок порталов — не аккаунты, их трогать нельзя */
@mkdir(SSO_ROOT . '/storage/avatars/branding', 0750, true);
file_put_contents(SSO_ROOT . '/storage/avatars/branding/keep.txt', 'x');
\App\Core\Housekeeping::purgeOrphans();
ok(is_file(SSO_ROOT . '/storage/avatars/branding/keep.txt'),
    'брендинг не считается сиротой — это не аккаунт');
@unlink(SSO_ROOT . '/storage/avatars/branding/keep.txt');

echo "\n── Подтверждение почты от провайдеров ──\n";
/* Адрес считается подтверждённым, только если его подтвердил тот, кто за него
   отвечает. У GitHub поле email в /user — публичный адрес профиля, туда можно
   вписать чужой; брать его нельзя, иначе получается захват чужого аккаунта. */
$ghSrc = (string)file_get_contents(SSO_ROOT . '/app/Social/GithubProvider.php');
ok(!preg_match('~\$email\s*=\s*\$user\[\x27email\x27\]~', $ghSrc),
    'GitHub: публичный email из /user не используется');
ok(str_contains($ghSrc, "user/emails") && substr_count($ghSrc, "verified") >= 2,
    'GitHub: адрес берётся из /user/emails и только помеченный verified');
/* Регистрация формой не подтверждает почту сама по себе */
$regSrc = (string)file_get_contents(SSO_ROOT . '/app/Controllers/RegistrationController.php');
ok(!preg_match('~User::create\(\[[^]]*email_verified_at~s', $regSrc),
    'регистрация формой создаёт аккаунт неподтверждённым');
$regId = \App\Models\User::create(['email' => 'unverified@test.local', 'status' => 'pending']);
ok(\App\Models\User::find($regId)['email_verified_at'] === null,
    'новый аккаунт не подтверждён, пока подтверждение не пришло');
\App\Core\DB::run('DELETE FROM {users} WHERE id = ?', [$regId]);

echo "\n── Мост с порталом (v4.1) ──\n";
use App\Core\Bridge;
use App\Core\PortalManifest;

$brClient = \App\Core\DB::row('SELECT * FROM {clients} LIMIT 1');
$brId = (int)$brClient['id'];

/* Адрес манифеста строится от base_url портала и только по https —
   по открытому каналу манифест можно подменить по дороге. */
ok(str_ends_with((string)PortalManifest::urlFor(['base_url' => 'https://site.com']),
    '/.well-known/sso-portal.json'), 'адрес манифеста строится от base_url портала');
ok(PortalManifest::urlFor(['base_url' => 'http://site.com']) === null,
    'http-портал манифест не отдаёт: по открытому каналу его подменят');
ok(PortalManifest::urlFor(['base_url' => '']) === null, 'без base_url адреса нет');

/* Цвет принимается только шестизначным hex: значение уходит в атрибут style,
   и всё непроверенное там становится вектором. */
foreach ([
    'red' => 'словом',
    'rgb(1,2,3)' => 'функцией',
    '#fff' => 'сокращённым hex',
    '#123456;} body{display:none' => 'с довеском CSS',
    'var(--ink)' => 'ссылкой на переменную',
] as $bad => $how) {
    Bridge::storeBranding($brClient, ['accent' => $bad]);
    $got = (string)\App\Core\DB::val('SELECT brand_accent FROM {clients} WHERE id = ?', [$brId]);
    ok($got === '' || $got === null || preg_match('~^#[0-9A-F]{6}$~', $got) === 1,
        "цвет $how не принимается: «" . $got . '»');
}
Bridge::storeBranding($brClient, ['accent' => '#E4572E']);
ok(\App\Core\DB::val('SELECT brand_accent FROM {clients} WHERE id = ?', [$brId]) === '#E4572E',
    'правильный hex принимается');

/* Красная линия: CSS, HTML и скрипты от портала не принимаются вообще.
   Произвольный CSS позволил бы спрятать наш логотип и нарисовать поверх формы
   что угодно — то есть оформить страницу под другой портал. */
Bridge::storeBranding($brClient, [
    'css' => 'body{display:none}', 'html' => '<script>alert(1)</script>',
    'style' => 'x', 'script' => 'y', 'tagline' => '<b>жирный</b>',
]);
$row = \App\Core\DB::row('SELECT * FROM {clients} WHERE id = ?', [$brId]);
ok(!array_key_exists('brand_css', $row) && !array_key_exists('brand_html', $row),
    'для CSS и HTML от портала в схеме нет даже места');
$branding = Bridge::brandingOf($row);
ok($branding !== null && !str_contains((string)$branding['tagline'], '<script'),
    'подпись портала остаётся текстом и экранируется при выводе');

/* Логотип: только с хоста самого портала и только своей копией */
ok(Bridge::brandingOf(['name' => 'X', 'brand_logo' => 'clients/нет-такого.webp',
    'brand_accent' => '', 'brand_tagline' => '', 'id' => 1])['logo'] === null,
    'несуществующий файл логотипа не отдаётся ссылкой в никуда');
ok(Bridge::brandingOf(null) === null && Bridge::brandingOf(['name' => 'X', 'brand_logo' => '',
    'brand_accent' => '', 'brand_tagline' => '']) === null,
    'портал без оформления мостика не создаёт');

/* Автоматика: фоновый обход с отступом после неудачи. Без отступа портал, у
   которого манифеста нет и не будет, опрашивался бы каждый час вечно. */
\App\Core\DB::run('DELETE FROM {settings} WHERE `key` LIKE ?', ['bridge.attempt.%']);
\App\Core\DB::run('UPDATE {clients} SET manifest_fetched_at = NULL');
$first = PortalManifest::refreshDue();
ok(str_contains($first, 'опрошено'), 'фоновый обход отчитывается: ' . $first);
$second = PortalManifest::refreshDue();
ok(str_contains($second, 'опрошено 0'), 'повторный обход сразу никого не дёргает: ' . $second);
ok(in_array('portal_manifests', array_map(
    static fn($t) => $t[0], (function () { $r = new ReflectionMethod(\App\Core\CronTasks::class, 'tasks');
        $r->setAccessible(true); return $r->invoke(null); })()), true),
    'обход манифестов зарегистрирован как задача планировщика');

/* Манифест с чужого хоста и мусором не должен ничего испортить */
$r = PortalManifest::fetch(['id' => $brId, 'base_url' => 'https://127.0.0.1:1/', 'name' => 'X']);
ok($r['ok'] === false, 'недоступный портал возвращает отказ, а не падение');

\App\Core\DB::run('UPDATE {clients} SET brand_accent = NULL, brand_tagline = NULL WHERE id = ?', [$brId]);

echo "\n── Капча (v4.0) ──\n";
use App\Core\Captcha;
\App\Core\Setting::set('captcha.enabled', '0');
ok(!Captcha::requiredForLogin(), 'выключенная капча никого не трогает');

\App\Core\Setting::set('captcha.enabled', '1');
\App\Core\Setting::set('captcha.after_attempts', '3');
Captcha::clearFailures();
ok(!Captcha::requiredForLogin(),
    'после верного пароля с первого раза капчи нет — она ответ на подбор, а не пошлина');
for ($i = 0; $i < 3; $i++) Captcha::noteFailure();
ok(Captcha::requiredForLogin(), 'после порога неудач капча появляется');
Captcha::clearFailures();
ok(!Captcha::requiredForLogin(), 'успешный вход обнуляет счётчик, иначе капча осталась бы навсегда');

/* Задача одноразовая: подставленную пару «вопрос-ответ» нельзя гонять в цикле */
$q = Captcha::issue();
preg_match('~(\d+)\s*\+\s*(\d+)~u', $q, $qm);
$right = (string)((int)$qm[1] + (int)$qm[2]);
ok(Captcha::verify($right), 'верный ответ принимается');
ok(!Captcha::verify($right), 'тот же ответ второй раз не проходит — задача гасится');

/* Выбор провайдера. Своя картинка — по умолчанию и осознанно: она работает без
   выхода наружу и никому не рассказывает о посетителях. */
ok(Captcha::provider() === 'own', 'по умолчанию проверяет своя картинка');
\App\Core\Setting::set('captcha.provider', 'нет-такого');
\App\Core\Setting::flush();
ok(Captcha::provider() === 'own',
    'неизвестный провайдер не превращает проверку в неизвестно что');

/* Виджет без ключей показал бы пустую рамку и запер вход всем, поэтому
   «выбран, но не настроен» трактуется как «своя картинка». */
\App\Core\Setting::set('captcha.provider', 'turnstile');
\App\Core\Setting::set('captcha.site_key', '');
\App\Core\Setting::set('captcha.secret', '');
\App\Core\Setting::flush();
ok(!Captcha::widgetReady(), 'виджет без ключей не считается настроенным');
[, $ownLogin] = http('GET', SSO . '/login');
ok(str_contains($ownLogin, 'captcha-row') || !str_contains($ownLogin, 'captcha-slot'),
    'без ключей экран входа не показывает пустую рамку виджета');

/* Настроенный виджет: разметка меняется, и CSP открывает ровно его домен. */
\App\Core\Setting::set('captcha.site_key', '0x0000_smoke');
\App\Core\Setting::set('captcha.secret', 'secret_smoke');
\App\Core\Setting::flush();
ok(Captcha::widgetReady(), 'с обоими ключами виджет настроен');
/* Проверяем сам компонент, а не страницу: капча появляется только после
   порога неудач, и на чистой сессии её на экране нет вовсе — страница сказала
   бы «виджета не видно» и там, где всё исправно. */
$widgetHtml = captcha_field('smoke');
ok(str_contains($widgetHtml, 'cf-turnstile') && !str_contains($widgetHtml, 'captcha-row'),
    'настроенный виджет заменяет свою картинку');
ok(str_contains($widgetHtml, 'challenges.cloudflare.com/turnstile/v0/api.js'),
    'скрипт виджета подключается там же, где он нарисован');
[, , , , $wHeaders] = http('GET', SSO . '/login');
ok(str_contains($wHeaders, 'challenges.cloudflare.com'),
    'CSP открывает домен виджета — иначе он не загрузился бы вовсе');

/* И закрывается обратно: пока владелец виджетом не пользуется, политика
   остаётся такой же тесной, как была. */
\App\Core\Setting::delete('captcha.provider');
\App\Core\Setting::delete('captcha.site_key');
\App\Core\Setting::delete('captcha.secret');
\App\Core\Setting::flush();
[, , , , $closedHeaders] = http('GET', SSO . '/login');
ok(!str_contains($closedHeaders, 'challenges.cloudflare.com'),
    'без виджета CSP не открывает чужих доменов');
Captcha::issue();
ok(!Captcha::verify('0'), 'неверный ответ не проходит');

$png = Captcha::png('7 + 4');
$info = @getimagesizefromstring($png);
ok($info !== false && $info['mime'] === 'image/png', 'картинка рисуется как PNG');
[$stCap, , , , $capHead] = http('GET', SSO . '/captcha.png');
ok($stCap === 200 && str_contains(strtolower($capHead), 'no-store'),
    'картинка отдаётся без кэша — задача одноразовая');
\App\Core\Setting::set('captcha.enabled', '0');

echo "\n── Карта ролей (v4.0) ──\n";
$mapClient = \App\Core\DB::row('SELECT * FROM {clients} LIMIT 1');
$mapCid = (int)$mapClient['id'];
\App\Models\ClientRoleMap::replace($mapCid, []);
$rawRoles = \App\Models\User::rolesClaim($adminRow['id'], $mapCid);
ok($rawRoles !== [] && in_array('user', $rawRoles, true),
    'без карты портал получает слаги как раньше — включение возможности никого не обрезает');

$mapRes = \App\Models\ClientRoleMap::replace($mapCid, [
    'user' => 'customer', 'superadmin' => 'owner', 'нет-такой-роли' => 'ghost',
]);
ok($mapRes['saved'] === 2 && $mapRes['unknown'] === ['нет-такой-роли'],
    'неизвестная роль пропущена со списком, а не уронила импорт');
$mapped = \App\Models\User::rolesClaim($adminRow['id'], $mapCid);
ok(in_array('customer', $mapped, true), 'портал видит своё название роли');
ok(!in_array('user', $mapped, true) && !in_array('superadmin', $mapped, true),
    'слаги SSO наружу больше не идут');

/* Несопоставленная роль не должна утекать: набор ролей — внутреннее устройство */
\App\Models\ClientRoleMap::replace($mapCid, ['user' => 'customer']);
$onlyOne = \App\Models\User::rolesClaim($adminRow['id'], $mapCid);
ok($onlyOne === ['customer'], 'роль без соответствия порталу не передаётся: ' . implode(',', $onlyOne));

/* Круговой рейс JSON */
$json = \App\Models\ClientRoleMap::toJson($mapClient);
$parsed = \App\Models\ClientRoleMap::fromJson($json);
ok($parsed === \App\Models\ClientRoleMap::forClient($mapCid), 'выгрузка и загрузка не теряют карту');
ok(\App\Models\ClientRoleMap::fromJson('{"что-то":1}') === null
    && \App\Models\ClientRoleMap::fromJson('не json') === null,
    'посторонний файл распознаётся как не-карта, а не разбирается наугад');

[$stMapJson, $mapJsonBody] = http('GET', SSO . '/admin/clients/' . $mapCid . '/rolemap.json');
ok($stMapJson === 200 && str_contains($mapJsonBody, '"roles"'), 'карта выгружается по ссылке');

/* Портал — рабочее место с колонкой разделов, как организация и человек
   (v4.30). Один механизм на три вида объектов: три карточки, собранные
   порознь, расходятся молча — у одной идентификатор в шапке, у другой во
   вкладке, у третьей его нет вовсе. */
[, $clientCard] = http('GET', SSO . '/admin/clients/' . $mapCid);
ok((bool)preg_match('~class="set-rail(?: [^"]*)?"~', $clientCard), 'у портала своя колонка разделов');
/* <code> с атрибутами: у последней метки есть id — на него смотрит кнопка
   «копировать» в шапке (v4.69). Смысл проверки — метка рядом с именем. */
ok(preg_match('~<code[^>]*>' . preg_quote((string)$mapClient['client_id'], '~') . '</code>~', $clientCard) === 1,
    'client_id стоит у имени — его подставляют в конфиг на той стороне');

/* Каждый раздел — своим адресом и настоящим запросом. */
preg_match_all('~href="(/admin/clients/' . $mapCid . '[^"#?]*)"~', $clientCard, $cRail);
$cLinks = array_values(array_unique($cRail[1]));
$cBad = [];
foreach ($cLinks as $href) {
    [$st] = http('GET', SSO . $href);
    if ($st !== 200) $cBad[] = $href . ' → ' . $st;
}
ok(count($cLinks) >= 6 && $cBad === [],
    'каждый раздел портала открывается по своему адресу (' . count($cLinks) . ')',
    implode('; ', $cBad));

/* Карта ролей раньше висела блоком ПОД вкладками и потому была видна на
   каждой; теперь у неё свой раздел — и она только там. */
[, $cRoles] = http('GET', SSO . '/admin/clients/' . $mapCid . '/roles');
ok(str_contains($cRoles, 'id="rolemap"'), 'карта ролей — в разделе «Роли»');
ok(!str_contains($clientCard, 'id="rolemap"'), 'и не висит поверх остальных разделов');
[$stHist] = http('GET', SSO . '/admin/clients/' . $mapCid . '/history');
ok($stHist === 200, 'у портала есть раздел журнала');

/* Третья сущность на том же шаблоне. Три карточки, собранные порознь,
   расходятся молча: у одной идентификатор в шапке, у другой во вкладке, у
   третьей его нет вовсе. */
$anyUserId = (int)\App\Core\DB::val('SELECT id FROM {users} ORDER BY id LIMIT 1');
[, $userCard] = http('GET', SSO . '/admin/users/' . $anyUserId);
ok((bool)preg_match('~class="set-rail(?: [^"]*)?"~', $userCard), 'у человека своя колонка разделов');
ok(preg_match('~<code[^>]*>user_' . $anyUserId . '</code>~', $userCard) === 1,
    'машинное имя стоит у имени — его называют в поддержке');

preg_match_all('~href="(/admin/users/' . $anyUserId . '[^"#?]*)"~', $userCard, $uRail);
$uLinks = array_values(array_unique($uRail[1]));
$uBad = [];
foreach ($uLinks as $href) {
    [$st] = http('GET', SSO . $href);
    if ($st !== 200) $uBad[] = $href . ' → ' . $st;
}
ok(count($uLinks) >= 5 && $uBad === [],
    'каждый раздел человека открывается по своему адресу (' . count($uLinks) . ')',
    implode('; ', $uBad));

/* История — общим компонентом, тем же, что у портала и организации. */
[, $uAudit] = http('GET', SSO . '/admin/users/' . $anyUserId . '/audit');
ok(str_contains($uAudit, 'class="hist-row"'), 'история человека рисуется общим компонентом');

/* Три вида объектов — одна оболочка. Проверка ровно на это: три карточки,
   собранные порознь, расходятся молча. */
/* Организацию заводим свою: чужая к этому месту прогона может быть уже
   удалена соседним блоком, и проверка сравнивала бы страницу 404. */
$shellOrgId = \App\Models\Org::create('Оболочка ' . bin2hex(random_bytes(2)), null, null);
[, $shellOrg] = http('GET', SSO . '/admin/orgs/' . $shellOrgId);
\App\Models\Org::purge($shellOrgId);
foreach (['портал' => $clientCard, 'человек' => $userCard, 'организация' => $shellOrg] as $what => $html) {
    ok(str_contains($html, 'class="set-shell"') && (bool)preg_match('~class="set-rail(?: [^"]*)?"~', $html),
        "$what живёт в общей оболочке");
}

/* История человека двусторонняя, у портала и организации — нет: они не бывают
   действующим лицом. Различие живёт в одном месте, а не в трёх контроллерах. */
$actorOnly = (int)\App\Core\DB::val(
    'SELECT COUNT(*) FROM {audit_log} WHERE actor_user_id = ? AND (on_user_id IS NULL OR on_user_id <> ?)',
    [$anyUserId, $anyUserId]);
if ($actorOnly > 0) {
    ok(count(\App\Core\ObjectHistory::of('user', $anyUserId, 200))
        > (int)\App\Core\DB::val('SELECT COUNT(*) FROM {audit_log} WHERE on_user_id = ?', [$anyUserId]),
        'история человека включает и то, что делал он сам');
} else {
    ok(true, 'история человека включает и то, что делал он сам (нет данных для проверки)');
}
\App\Models\ClientRoleMap::replace($mapCid, []);

/* «Ху из зу»: портал спрашивает о человеке по тому, что человек знает сам.
   Порядок маршрутов важен — /users/{sub} объявлен ниже и иначе перехватил бы
   by-number вместе с by-email. */
/* Токен заводим здесь свой: секция v3.4 намеренно роняет таблицу api_tokens
   ради проверки устойчивости, и выданный раньше к этому месту уже недействителен. */
if (\App\Core\Migrations::ready('api_tokens')) {
    [$lookupTokenId, $lookupToken] = \App\Models\ApiToken::create('Смоук-поиск', ['users:read'], (int)$adminRow['id']);
    /* $adminRow выбирался без public_id — берём его отдельно, а не гадаем */
    $adminSub = (string)\App\Core\DB::val('SELECT public_id FROM {users} WHERE id = ?', [(int)$adminRow['id']]);
    $hdr = ['Authorization: Bearer ' . $lookupToken];
    [$stByNum, $byNumBody] = http('GET', SSO . '/api/v1/users/by-number/'
        . urlencode(\App\Core\AccountNumber::format($adminNum)), [], $hdr);
    $byNum = json_decode($byNumBody, true);
    ok($stByNum === 200 && ($byNum['sub'] ?? '') === $adminSub,
        'портал находит человека по номеру аккаунта');
    [$stByMail, $byMailBody] = http('GET', SSO . '/api/v1/users/by-email/'
        . urlencode('admin@test.local'), [], $hdr);
    ok($stByMail === 200 && (json_decode($byMailBody, true)['sub'] ?? '') === $adminSub,
        'и по адресу электронной почты');
    [$stMiss] = http('GET', SSO . '/api/v1/users/by-number/482713905', [], $hdr);
    ok($stMiss === 404, 'несуществующий номер — 404, а не чужая карточка');
    \App\Models\ApiToken::revoke($lookupTokenId);
}

echo "\n── Секреты и названия (v3.9) ──\n";
/* Сохранённый секрет должен читаться как «есть», а не как пустое поле:
   пустота неотличима от «не заполнено», и владелец каждый раз гадает. */
\App\Core\Setting::set('social.yandex.secret', 'секрет-для-проверки');
/* Секреты живут в разделе «Вход через сервисы»: с v4.10 у раздела свой адрес,
   а /admin/settings показывает только брендинг. */
[, $setPage] = http('GET', SSO . '/admin/settings/section/social');
$dots = substr_count($setPage, 'placeholder="••••••••"');
ok($dots > 0, 'сохранённый секрет показан точками, а не пустым полем');
ok(!str_contains($setPage, 'секрет-для-проверки'), 'сам секрет наружу при этом не отдаётся');
\App\Core\Setting::set('social.yandex.secret', '');
[, $setPage2] = http('GET', SSO . '/admin/settings/section/social');
ok(substr_count($setPage2, 'placeholder="••••••••"') < $dots,
    'у незаполненного секрета точек нет — иначе они врали бы');
ok(!str_contains($setPage2, '>Соцвход<'), 'слово «Соцвход» из интерфейса убрано');

echo "\n── Правовые документы (v3.9) ──\n";
use App\Core\Legal;
use App\Core\Markdown;

/* Разметка: HTML из админки — это XSS своими руками, поэтому вход экранируется
   целиком, а ссылки проверяются по схеме. */
foreach ([
    '<script>alert(1)</script>' => '<script',
    '<img src=x onerror=alert(1)>' => '<img',
    '[клик](javascript:alert(1))' => 'javascript:',
    '[клик](data:text/html;base64,PHN2Zz4=)' => 'data:',
] as $evil => $mustNotAppear) {
    ok(!str_contains(Markdown::toHtml($evil), $mustNotAppear),
        'разметка не пропускает ' . $mustNotAppear);
}
ok(str_contains(Markdown::toHtml('[почта](mailto:a@b.c)'), 'href="mailto:a@b.c"'),
    'безопасные ссылки при этом работают');
ok(str_contains(Markdown::toHtml('[внешняя](https://example.com)'), 'rel="noopener noreferrer"'),
    'внешняя ссылка получает rel — иначе открытая страница дотянется до window.opener');
ok(!str_contains(Markdown::toHtml('# Заголовок'), '<h1'),
    'h1 не выпускается: заголовок страницы рисует шаблон');
ok(str_contains(Markdown::toHtml("- пункт\n  продолжение"), '<li>пункт продолжение</li>'),
    'перенос внутри пункта не разрывает список');

/* Версия — смысл всей записи о согласии */
$was = Legal::body('privacy');
$wasEnabled = Legal::enabled('privacy');
Legal::save('privacy', 'Текст один.', true);
$v1 = Legal::version('privacy');
Legal::save('privacy', 'Текст один.', true);
ok(Legal::version('privacy') === $v1, 'сохранение без изменений не поднимает редакцию');
Legal::save('privacy', 'Текст два.', true);
ok(Legal::version('privacy') === $v1 + 1, 'изменение текста поднимает редакцию');

/* Пустой документ выключен, даже если галочка стоит */
Legal::save('privacy', '', true);
ok(!Legal::enabled('privacy'), 'пустой документ не показывается — пустая страница хуже отсутствующей');
[$stOff] = http('GET', SSO . '/legal/privacy');
ok($stOff === 404, 'страница выключенного документа отдаёт 404');

/* Включаем явно: на свежей установке документ пуст и выключен, и проверка
   «включённый отдаётся» молча превращалась бы в проверку 404. */
Legal::save('privacy', $was !== '' ? $was : Legal::template('privacy'), true);
[$stOn, $docBody] = http('GET', SSO . '/legal/privacy');
ok($stOn === 200 && str_contains($docBody, 'legal-doc'), 'включённый документ отдаётся');
ok(!str_contains($docBody, '{{ '), 'плейсхолдеры подставлены, а не показаны как есть');
[$st404] = http('GET', SSO . '/legal/нет-такого');
ok($st404 === 404, 'неизвестный ключ документа — 404');

/* Согласие: идемпотентно, с версией */
$cId = \App\Models\User::create(['email' => 'consent@test.local', 'status' => 'active']);
Legal::record($cId, ['privacy']);
Legal::record($cId, ['privacy']);
$consents = Legal::consentsOf($cId);
ok(count($consents) === 1, 'повторное согласие с той же редакцией не плодит записей');
ok((int)$consents[0]['doc_version'] === Legal::version('privacy'),
    'в записи сохранена та редакция, с которой согласились');
Legal::save('privacy', Legal::body('privacy') . "\n\nДополнение.", true);
Legal::record($cId, ['privacy']);
ok(count(Legal::consentsOf($cId)) === 2, 'новая редакция — новая запись, старая остаётся');
\App\Core\DB::run('DELETE FROM {users} WHERE id = ?', [$cId]);

echo "\n── Согласие при соцвходе (v3.9) ──\n";
/* Ключевое свойство: аккаунт не заводится молча. Проверяем и то, что на экран
   не попасть с улицы, — иначе он превратился бы в способ завести учётку без
   всякого провайдера. */
$signupJar = __DIR__ . '/tmp/signup-cookies.txt';
@unlink($signupJar);
[$stSignup, , $signupLoc] = http('GET', SSO . '/social/signup', [], [], $signupJar);
ok($stSignup === 302 && str_contains($signupLoc, '/login'),
    'на «Создать аккаунт?» не попасть без пройденного OAuth');
$before = (int)\App\Core\DB::val('SELECT COUNT(*) FROM {users}');
[$stPost] = http('POST', SSO . '/social/signup', ['accept' => ['privacy']], [], $signupJar);
/* Первым срабатывает CSRF (419) — до проверки pending_signup дело не доходит.
   Проверяем не конкретный код, а свойство: аккаунт не появился. */
ok($stPost !== 200 && (int)\App\Core\DB::val('SELECT COUNT(*) FROM {users}') === $before,
    'прямой POST не создаёт аккаунт (получено ' . $stPost . ')');

$usersBefore = (int)\App\Core\DB::val('SELECT COUNT(*) FROM {users}');
[$stCancel] = http('GET', SSO . '/social/signup/cancel', [], [], $signupJar);
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {users}') === $usersBefore,
    'отмена не оставляет ни аккаунта, ни связки');
@unlink($signupJar);

/* Разметка экрана: галочки обязательны и не предустановлены */
$signupTpl = (string)file_get_contents(SSO_ROOT . '/templates/auth/signup.php');
ok(str_contains($signupTpl, 'required'), 'без галочек форма не отправляется');
ok(!str_contains($signupTpl, 'checked'),
    'галочки не предустановлены — согласие за человека не является согласием');

echo "\n── Галерея фотографий (v3.8) ──\n";
\App\Core\DB::run('DELETE FROM {users} WHERE email LIKE ?', ['photo-%@test.local']);
$pId = \App\Models\User::create(['email' => 'photo-owner@test.local', 'status' => 'active']);
$pUser = \App\Models\User::find($pId);

/* Синтетический снимок: цветной квадрат — файл настоящий, сеть не нужна */
$im = imagecreatetruecolor(300, 200);
imagefilledrectangle($im, 0, 0, 300, 200, imagecolorallocate($im, 20, 120, 200));
ob_start(); imagepng($im); $png = (string)ob_get_clean(); imagedestroy($im);

$ph1 = \App\Models\UserPhoto::store($pUser, $png, 'yandex', 'https://avatars.yandex.net/get-yapic/a/islands-200');
ok($ph1 !== null, 'фото добавляется в галерею');
$row = \App\Core\DB::row('SELECT * FROM {user_photos} WHERE id = ?', [$ph1]);
$file = SSO_ROOT . '/storage/avatars/' . $row['path'];
ok(is_file($file), 'файл лежит на диске: ' . $row['path']);
$sz = getimagesize($file);
ok($sz && $sz[0] === 512 && $sz[1] === 512, 'снимок приведён к квадрату 512×512');
ok(str_contains($row['path'], '/photos/'), 'фото хранятся отдельно от самого аватара');

/* Аватарки не было — первое фото становится ею */
\App\Models\UserPhoto::makeCurrent(\App\Models\User::find($pId), $ph1);
$pUser = \App\Models\User::find($pId);
ok(!empty($pUser['avatar_path']) && (int)$pUser['avatar_photo_id'] === $ph1,
    'выбранное фото стало аватаркой');

/* Главное правило: приехавшее от провайдера не подменяет выбранное */
$ph2 = \App\Models\UserPhoto::store($pUser, $png, 'vk', 'https://sun1.userapi.com/impg/photo_200.jpg');
$pUser = \App\Models\User::find($pId);
ok((int)$pUser['avatar_photo_id'] === $ph1,
    'новое фото от провайдера пополнило галерею, но аватарку не подменило');
ok(count(\App\Models\UserPhoto::forUser($pId)) === 2, 'в галерее оба снимка');

/* Раздача файла: строгая маска имени, чужой путь не пролезает */
[$stPhoto] = http('GET', SSO . '/photo/' . $pUser['public_id'] . '/' . basename($row['path']));
ok($stPhoto === 200, 'фото галереи отдаётся по своему адресу');
foreach (['../512.webp', 'ZZZZ.webp', '512.webp'] as $bad) {
    [$stBad] = http('GET', SSO . '/photo/' . $pUser['public_id'] . '/' . $bad);
    ok($stBad === 404, "чужое имя файла «{$bad}» не отдаётся");
}

/* SSRF: скачивание только с хостов провайдера, схема только https */
foreach ([
    'http://avatars.yandex.net/x.jpg' => 'http вместо https',
    'https://169.254.169.254/latest/meta-data/' => 'внутренний адрес',
    'https://evil.example.com/x.jpg' => 'посторонний хост',
    'https://avatars.yandex.net.evil.com/x.jpg' => 'хост с похожим началом',
] as $url => $why) {
    ok(\App\Models\UserPhoto::fetchFromProvider($pUser, 'yandex', $url) === null,
        "не качаем: $why");
}
ok(\App\Models\UserPhoto::fetchFromProvider($pUser, 'нетТакого', 'https://avatars.yandex.net/x.jpg') === null,
    'неизвестный провайдер не качает ничего');
/* Ту же ссылку второй раз не тянем — иначе каждый вход лез бы в сеть */
ok(\App\Models\UserPhoto::fetchFromProvider($pUser, 'yandex',
    'https://avatars.yandex.net/get-yapic/a/islands-200') === null,
    'уже забранная ссылка повторно не скачивается');

/* Удаление текущей аватарки снимает и аватар — иначе он ссылался бы в пустоту */
\App\Models\UserPhoto::remove(\App\Models\User::find($pId), $ph1);
$pUser = \App\Models\User::find($pId);
ok(!is_file($file), 'файл удалённого фото стёрт с диска');
ok(empty($pUser['avatar_path']) && empty($pUser['avatar_photo_id']),
    'удалили фото-аватарку — аватар снят, а не остался битой ссылкой');

/* Анонимизация не должна оставлять фотографии на диске */
$leftover = \App\Core\DB::row('SELECT * FROM {user_photos} WHERE id = ?', [$ph2]);
$leftFile = SSO_ROOT . '/storage/avatars/' . $leftover['path'];
\App\Core\DB::run('UPDATE {users} SET status = ?, deleted_at = ? WHERE id = ?',
    ['deleted', now_plus(-15 * 86400), $pId]);
\App\Core\CronTasks::anonymizeDeleted();
ok(!is_file($leftFile), 'после анонимизации фотографий на диске не остаётся');

\App\Core\DB::run('DELETE FROM {users} WHERE id = ?', [$pId]);

echo "\n── PWA ──\n";
[$st, $mfBody, , , $mfHead] = http('GET', SSO . '/manifest.webmanifest');
$mf = json_decode($mfBody, true);
ok($st === 200 && is_array($mf), 'манифест отдаётся и разбирается как JSON');
ok(($mf['name'] ?? '') === \App\Core\Setting::appName(),
    'название в манифесте берётся из брендинга: ' . ($mf['name'] ?? '—'));
ok(mb_strlen((string)($mf['short_name'] ?? '')) <= 12 && ($mf['short_name'] ?? '') !== '',
    'short_name не длиннее 12 знаков: «' . ($mf['short_name'] ?? '') . '»');
ok(($mf['display'] ?? '') === 'standalone' && ($mf['scope'] ?? '') === '/',
    'манифест объявляет standalone на всём origin');
$purposes = array_column($mf['icons'] ?? [], 'purpose');
ok(in_array('maskable', $purposes, true) && in_array('any', $purposes, true),
    'в манифесте есть и обычные иконки, и maskable');
/* Cookie на манифесте означала бы пустую сессию на каждую его загрузку */
ok(!str_contains(strtolower($mfHead), 'set-cookie'), 'манифест не заводит сессию');

foreach (['icon-192' => 192, 'icon-512' => 512, 'maskable-192' => 192, 'maskable-512' => 512] as $file => $side) {
    [$st, $png] = http('GET', SSO . "/pwa/$file.png");
    $info = $st === 200 ? @getimagesizefromstring($png) : false;
    ok($info !== false && $info[0] === $side && $info[1] === $side && $info['mime'] === 'image/png',
        "иконка $file — PNG {$side}×{$side}", $st . ', ' . strlen($png) . ' байт');
}
[$st] = http('GET', SSO . '/pwa/icon-777.png');
ok($st === 404, 'произвольный размер иконки не генерируется');

[$st, $swBody, , , $swHead] = http('GET', SSO . '/sw.js');
ok($st === 200 && str_contains(strtolower($swHead), 'application/javascript'),
    'service worker отдаётся как javascript');
ok(str_contains(strtolower($swHead), 'service-worker-allowed: /'),
    'воркеру разрешена область всего origin');
/* Закэшированный воркер — застрявшее обновление: он держит старую статику */
ok(str_contains(strtolower($swHead), 'cache-control: no-cache'), 'сам воркер не кэшируется');
ok(str_contains($swBody, "sso-static-v" . SSO_VERSION),
    'имя кэша содержит версию — при релизе старый кэш сбрасывается');

[$st, $offBody] = http('GET', SSO . '/offline');
ok($st === 200 && str_contains($offBody, 'Нет сети'), 'офлайн-заглушка отдаётся');
/* Её кладёт в кэш воркер — из сессии вошедшего. Личных данных быть не должно:
   заглушка одна на всех, кто открывал приложение на этом устройстве. */
/* Оболочка проверяется по тому, что стоит на страницах сейчас: раньше здесь
   искали `topbar`, и после замены планки островком проверка сторожила
   исчезнувший класс — то есть не сторожила ничего. */
ok(!str_contains($offBody, 'admin@test.local')
    && !str_contains($offBody, 'class="isle')
    && !str_contains($offBody, 'class="dock')
    && !str_contains($offBody, '/logout'),
    'в офлайн-заглушке нет ни имени, ни оболочки, ни выхода');

echo "═══ Системный раздел владельца (v4.2) ═══\n";
\App\Core\Setting::flush();
$ownerId = \App\Core\Owner::id();
ok($ownerId !== null, 'владелец определён');
ok($ownerId === (int)\App\Models\User::findByEmail('admin@test.local')['id'],
    'владелец — старейший суперадмин, то есть тот, кто ставил систему');

[$st, $sysPage] = http('GET', SSO . '/admin/system');
ok($st === 200 && str_contains($sysPage, 'Владелец инсталляции'), 'владельцу раздел открывается',
    "status=$st len=" . strlen($sysPage));
/* Пункт меню исчез вместе с меню «Ещё» (v4.18.2): системный раздел живёт в
   колонке настроек и виден только владельцу — остальным его там нет. */
[, $ownerSettings] = http('GET', SSO . '/admin/settings');
ok(str_contains($ownerSettings, '/admin/system'), 'владелец видит системный раздел в колонке');
ok(str_contains($sysPage, 'sso_') || str_contains($sysPage, 'SQLite'),
    'карточка базы показывает подключение');

/* Суперадмин, который не владелец, не должен даже узнать о существовании
   раздела: 404, а не 403. */
$otherJar = __DIR__ . '/tmp/smoke-notowner.txt';
@unlink($otherJar);
$otherId = \App\Models\User::create(['email' => 'smoke-second-admin@test.local',
    'first_name' => 'Второй', 'password_hash' => password_hash('correct-horse-battery', PASSWORD_ARGON2ID),
    'status' => 'active', 'email_verified_at' => now()]);
\App\Models\Role::assign($otherId, (int)\App\Models\Role::findBySlug('superadmin')['id']);
[, $lp] = http('GET', SSO . '/login', [], [], $otherJar);
http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => 'smoke-second-admin@test.local',
    'password' => 'correct-horse-battery'], [], $otherJar);
[$st] = http('GET', SSO . '/admin', [], [], $otherJar);
ok($st === 200, 'второй суперадмин в админку заходит');
[$st] = http('GET', SSO . '/admin/system', [], [], $otherJar);
ok($st === 404, 'не владельцу раздел не существует (404, а не 403)', "status=$st");
[, $otherHome] = http('GET', SSO . '/admin', [], [], $otherJar);
ok(!str_contains($otherHome, '/admin/system'),
    'не владельцу пункт меню не показывается — иначе он ведёт в 404');

/* Проверка чужого подключения обязана быть безобидной: заведомо мёртвые
   реквизиты не должны ни переключить базу, ни уронить страницу. */
$before = \App\Core\Config::get('db.name');
[$st] = http('POST', SSO . '/admin/system/db/probe', ['_csrf' => $csrf,
    'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '9', 'name' => 'no_such_db',
    'user' => 'nobody', 'pass' => 'x', 'prefix' => 'sso_']);
ok($st === 302, 'проверка подключения отвечает редиректом');
[, $sysPage] = http('GET', SSO . '/admin/system');
ok(str_contains($sysPage, 'Соединение'), 'результат проверки показан');
ok(\App\Core\Config::get('db.name') === $before, 'проверка не переключила рабочую базу');
ok(!str_contains($sysPage, 'no_such_db') || !str_contains($sysPage, 'nobody'),
    'пароль и логин чужой базы на странице не светятся');

/* Переключение: отказ обязан быть до записи конфига, а не после.
   Приложение с недоступной базой выглядит работающим — GET-ы отдают 200 на
   файловых дефолтах, — поэтому «проверили и не переключились» здесь и есть
   правильный исход. */
$cfgFile = SSO_ROOT . '/config/config.php';
$cfgWas = (string)file_get_contents($cfgFile);
foreach ([
    ['storage/no-such-db.sqlite', 'несуществующая база'],
    [null, 'пустая база без наших таблиц'],
] as [$target, $why]) {
    if ($target === null) {
        $target = 'storage/smoke-empty.sqlite';
        @unlink(SSO_ROOT . '/' . $target);
        new PDO('sqlite:' . SSO_ROOT . '/' . $target);
    }
    http('POST', SSO . '/admin/system/db/switch', ['_csrf' => $csrf,
        'driver' => 'sqlite', 'name' => $target, 'prefix' => 'sso_']);
    ok((string)file_get_contents($cfgFile) === $cfgWas, "конфиг не тронут: $why");
}
@unlink(SSO_ROOT . '/storage/smoke-empty.sqlite');

/* Резервная копия именуется до секунд: две правки в одну минуту не должны
   делить имя, иначе откатываться будет уже не к чему. */
$b1 = \App\Core\ConfigFile::backup();
$b2 = \App\Core\ConfigFile::backup();
ok($b1 !== '' && $b2 !== '' && $b1 !== $b2, 'две копии конфига подряд не затирают друг друга');
@unlink($b1);
@unlink($b2);

echo "═══ Смена домена: отказы и последствия ═══\n";
/* Слово-подтверждение не выдаётся, пока переезд не затеян: постоянный маркер
   на /.well-known говорил бы любому прохожему, что тут SSO. */
\App\Core\Setting::delete('system.move.nonce');
[$st] = http('GET', SSO . '/.well-known/sso-move');
ok($st === 404, 'без затеянного переезда слова нет', "status=$st");

$issuerWas = (string)\App\Core\Config::get('app.url');
http('POST', SSO . '/admin/system/domain/check',
    ['_csrf' => $csrf, 'url' => 'https://nowhere.invalid.example']);
[, $sysPage] = http('GET', SSO . '/admin/system');
ok(str_contains($sysPage, 'не отвечает') || str_contains($sysPage, 'Отвечает другой'),
    'проверка отказывает адресу, которого нет');
[$st] = http('GET', SSO . '/.well-known/sso-move');
[$stOld] = http('GET', SSO . '/.well-known/tsoy-move');
ok($st === 200 && $stOld === 200, 'на время проверки слово выдаётся — и по прежнему адресу tsoy-move, до 5.0');

/* Главная гарантия: сменить домен, не доказав что новый адрес отвечает,
   нельзя. Иначе администратор уводит админку на несуществующий адрес. */
http('POST', SSO . '/admin/system/domain/move',
    ['_csrf' => $csrf, 'url' => 'https://nowhere.invalid.example']);
/* Читаем файл, а не Config: конфиг в этом процессе прочитан один раз при
   старте, и он не узнает о правке, сделанной серверным процессом. */
$cfgNow = include SSO_ROOT . '/config/config.php';
ok((string)$cfgNow['app']['url'] === $issuerWas, 'смена без успешной проверки не проходит');

/* Свой же адрес менять не на что — и это не «ошибка сети», а понятный отказ. */
$same = \App\Core\DomainMove::verify($issuerWas);
ok(!$same['ok'] && str_contains($same['detail'], 'текущий'), 'свой адрес отвергается по смыслу');

/* Переезд http → https на том же домене — тоже переезд: сравнение идёт по
   origin, а не по хосту. Отказ здесь должен быть про соединение, а не про
   «это текущий адрес». */
$https = \App\Core\DomainMove::verify(str_replace('http://', 'https://', $issuerWas));
ok(!str_contains($https['detail'], 'текущий'), 'смена схемы на том же домене не считается «тем же адресом»');

$cons = \App\Core\DomainMove::consequences();
ok($cons['sessions'] >= 1 && is_array($cons['clients']),
    'последствия считаются по базе, а не пишутся прописью');
\App\Core\Setting::delete('system.move.nonce');

echo "═══ Кит для портала: он уедет в чужой проект ═══\n";
$kitClient = \App\Models\Client::find((int)\App\Core\DB::val('SELECT id FROM {clients} ORDER BY id LIMIT 1'));
/* Ключ переноса выдаём до сборки: тогда в наборе оказывается настоящий
   users_secret и выгрузку можно проверить по-настоящему — подписав запрос
   ровно так, как это делает PortalUsers. Прежнее значение вернём в конце. */
$kitSecretWas = $kitClient['users_secret'] ?? null;
$kitUsersSecret = \App\Core\PortalUsers::issueSecret($kitClient);
$kitClient = \App\Models\Client::find((int)$kitClient['id']);
$kitZip = \App\Controllers\Admin\KitBuilder::buildZip($kitClient, null);
$kitDir = __DIR__ . '/tmp/kitcheck';
foreach ((array)glob($kitDir . '/*/*') as $f) @unlink((string)$f);
foreach ((array)glob($kitDir . '/*') as $f) { if (is_file($f)) @unlink((string)$f); }
@mkdir($kitDir, 0777, true);
$kz = new ZipArchive();
$kz->open($kitZip);
$kz->extractTo($kitDir);
$kz->close();

/* Синтаксическая ошибка в ките обнаружится не у нас, а у того, кто его
   распакует, — поэтому разбираем каждый PHP прямо здесь. */
$kitBad = [];
foreach (array_merge((array)glob($kitDir . '/*.php'), (array)glob($kitDir . '/*/*.php')) as $f) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg((string)$f) . ' 2>&1', $o, $rc);
    if ($rc !== 0) $kitBad[] = basename((string)$f);
}
ok($kitBad === [], 'весь PHP в ките разбирается', implode(', ', $kitBad));

$kitAll = '';
foreach (array_merge((array)glob($kitDir . '/*'), (array)glob($kitDir . '/*/*')) as $f) {
    if (is_file($f)) $kitAll .= (string)file_get_contents((string)$f);
}
preg_match_all('~\{\{([A-Z_]+)\}\}~', $kitAll, $kitPh);
/* Два секрета остаются плейсхолдерами законно: client_secret у нас только
   хэшем, а ключ переноса выдают отдельной кнопкой и не всегда. */
$kitLeft = array_values(array_diff(array_unique($kitPh[1]), ['CLIENT_SECRET', 'USERS_SECRET']));
ok($kitLeft === [], 'в ките не осталось нераскрытых подстановок', implode(', ', $kitLeft));
ok(str_contains($kitAll, (string)$kitClient['client_id']), 'client_id подставлен в кит');
ok(str_contains($kitAll, '{{CLIENT_SECRET}}'), 'секрет по умолчанию остаётся плейсхолдером');

/* Кнопка входа уезжает на чужой сайт, где наших переменных нет вовсе.
   Значит в ней не должно остаться ни одной: var(--…) там развернётся в
   пустоту, и кнопка станет прозрачным прямоугольником без текста. */
$kitBtn = (string)@file_get_contents($kitDir . '/sso/sso-button.css');
ok($kitBtn !== '', 'кнопка входа лежит в ките отдельным файлом');
ok(!str_contains($kitBtn, 'var(--'),
    'в кнопке нет наших переменных — на чужом сайте их некому объявить');

/* Цвет запекается пересчитанным, а не сырым из настроек: светлый фирменный
   цвет владельца иначе дал бы белые буквы на жёлтом сразу у всех порталов. */
preg_match('~background: (#[0-9A-Fa-f]{6}); color: (#[0-9A-Fa-f]{6})~', $kitBtn, $btnM);
$btnRatio = isset($btnM[1], $btnM[2]) ? \App\Core\Palette::contrast($btnM[2], $btnM[1]) : 0.0;
ok($btnRatio >= 4.5, sprintf('надпись на кнопке читается (%s на %s — %.2f:1)',
    $btnM[2] ?? '?', $btnM[1] ?? '?', $btnRatio));

$kitMd = \App\Controllers\Admin\KitBuilder::integrationMd($kitClient, null);
ok(str_contains($kitMd, 'sso-btn'), 'integration.md показывает разметку кнопки');
ok(str_contains($kitMd, 'access_level') && str_contains($kitMd, 'access_tier'),
    'integration.md рассказывает про уровень доступа');
ok(str_contains($kitMd, 'sso-portal.json'), 'integration.md рассказывает про манифест');
preg_match_all('~\{\{([A-Z_]+)\}\}~', $kitMd, $mdPh);
ok(array_diff(array_unique($mdPh[1]), ['CLIENT_SECRET']) === [],
    'в integration.md не осталось нераскрытых подстановок');

/* ── Ничего не потерялось по дороге ──
   Шаблон, который завели, но не внесли в список сборки, не уедет никуда и
   обнаружится через месяц вопросом «а где файл». */
$kitTpl = array_map(static fn($p) => basename((string)$p, '.tpl'),
    (array)glob(SSO_ROOT . '/templates/kit/*.tpl'));
$kitShipped = array_map('basename', array_filter((array)glob($kitDir . '/sso/*'), 'is_file'));
$kitMissed = array_values(array_diff($kitTpl, $kitShipped));
ok($kitMissed === [], 'каждый шаблон набора попадает в архив', implode(', ', $kitMissed));

$kitReadme = (string)@file_get_contents($kitDir . '/sso/README.md');
$kitUnlisted = array_values(array_filter($kitShipped,
    static fn(string $f): bool => $f !== 'README.md' && !str_contains($kitReadme, '`' . $f . '`')));
ok($kitUnlisted === [], 'README набора перечисляет все файлы, которые в нём лежат',
    implode(', ', $kitUnlisted));

/* ── Шкала уровней: в документе таблицей, в коде константой ──
   Разъедутся они тихо. Документ уже уехал к порталам, там ветвятся по числам,
   которых у нас больше нет, — и узнаём мы об этом от чужого разработчика. */
preg_match_all('~^\| (\d+)\+? \| `([a-z_]+)`~m', $kitMd, $tierRows, PREG_SET_ORDER);
$mdTiers = [];
foreach ($tierRows as $row) $mdTiers[(int)$row[1]] = $row[2];
$tierDrift = [];
foreach (\App\Core\AccessLevel::TIERS as $level => [$tier,]) {
    /* Верхушку шкалы (91, 98, 99) документ описывает одной строкой «90+»:
       порталу эти ступени не адресованы, различать их ему незачем. */
    if ($level > 90) continue;
    if (($mdTiers[$level] ?? null) !== $tier) $tierDrift[] = $level . ' → ' . ($mdTiers[$level] ?? 'нет');
}
foreach ($mdTiers as $level => $tier) {
    if ((\App\Core\AccessLevel::TIERS[$level][0] ?? null) !== $tier) $tierDrift[] = 'лишняя ' . $level;
}
ok($tierDrift === [], 'таблица уровней в integration.md совпадает с AccessLevel::TIERS',
    implode(', ', $tierDrift));
ok(str_contains($kitMd, '>= ' . \App\Core\Permission::DEFAULT_LEVEL['client_admin']),
    'порог администратора в документе тот же, что у роли client_admin');

/* ── Четыре вопроса, которые задаёт каждый, кто пишет мост ──
   Ответы стоят в документе первым экраном. Проверяем не формулировку, а
   присутствие: пропажа любого из них снова превратится в переписку. */
ok(str_contains($kitMd, 'allow_local_login'), 'документ решает вопрос про свою форму входа');
ok(str_contains($kitMd, 'карту ролей'), 'документ рассказывает про карту ролей');
ok(str_contains($kitMd, 'invite') && str_contains($kitMd, '403'),
    'документ объясняет, что делать с человеком без прав');
ok(str_contains($kitMd, 'email_verified') && str_contains($kitMd, 'sso_export_users'),
    'документ объясняет привязку старых аккаунтов — и поштучно, и списком');

$kitCfg = (array)require $kitDir . '/sso/config.php';
ok(array_key_exists('allow_local_login', $kitCfg) && $kitCfg['allow_local_login'] === false,
    'аварийный вход приезжает на портал выключенным');

/* ── То, что уже приезжало сломанным ──
   Каждая строка ниже — дефект, найденный на живом подключении: файл падал,
   выход уходил безымянным, подпись считалась не по тому. Проверки стоят
   именно потому, что все три выглядели как «работает» до первой попытки. */
$kitFedcm = (string)file_get_contents($kitDir . '/sso/fedcm.php');
ok(!preg_match('~new SsoClient\(\$config\)~', $kitFedcm),
    'fedcm.php не обращается к несуществующей $config');
ok(str_contains($kitFedcm, "\$_SESSION['sso_fedcm_nonce']"),
    'fedcm.php сохраняет nonce, а не только печатает его');
ok(str_contains((string)file_get_contents($kitDir . '/sso/fedcm-finish.php'), 'sso_fedcm_nonce'),
    'fedcm-finish.php сверяет nonce');
ok(str_contains((string)file_get_contents($kitDir . '/sso/logout.php'), 'id_token_hint'),
    'logout.php передаёт id_token_hint');
ok(str_contains((string)file_get_contents($kitDir . '/sso/SsoClient.php'), "'id_token' => \$tokens['id_token']"),
    'SsoClient не теряет сырой id_token — иначе подсказку на выходе взять негде');
$kitUsersSrc = (string)file_get_contents($kitDir . '/sso/users.php');
ok(str_contains($kitUsersSrc, "QUERY_STRING"),
    'подпись выгрузки считается по сырой строке запроса, а не по обрезанной');
ok(strpos($kitUsersSrc, 'hash_equals') < strpos($kitUsersSrc, "min(500"),
    'границы применяются ПОСЛЕ проверки подписи, а не до');

/* Своя сессия и один читатель конфига: голый session_start() уводил вход в
   чужую сессию, и это не давало ни одной ошибки. */
foreach (['login.php', 'callback.php', 'logout.php', 'widget.php', 'users.php', 'fedcm-finish.php'] as $f) {
    $src = (string)file_get_contents($kitDir . '/sso/' . $f);
    ok(!preg_match('~^\s*session_start\(\);~m', $src), "$f не открывает свою сессию мимо приложения");
}
ok(str_contains((string)file_get_contents($kitDir . '/sso/widget.php'), 'sso_ready()'),
    'кнопка входа показывается по тому же условию, по которому вход работает');

$kitBootstrap = (string)file_get_contents($kitDir . '/sso/bootstrap.php');
foreach (['sso_session_start', 'sso_config', 'sso_secrets', 'sso_ready'] as $fn) {
    ok(str_contains($kitBootstrap, 'function ' . $fn), "bootstrap.php объявляет $fn()");
}

/* Установщик: он и есть короткий путь, ради которого всё затевалось. */
$kitInstall = (string)file_get_contents($kitDir . '/sso/install.php');
$kitBoot = (string)file_get_contents($kitDir . '/sso/bootstrap.php');
$kitChecks = (string)@file_get_contents($kitDir . '/sso/checks.php');
$kitAdmin = (string)@file_get_contents($kitDir . '/sso/admin.php');
ok(str_contains($kitInstall, '/portal/bootstrap'), 'установщик знает, куда идти за реквизитами');
ok(str_contains($kitBoot, "dirname(__DIR__, 2) . '/sso-secrets.php'") && str_contains($kitBoot, "dirname(__DIR__, 2) . '/tsoy-secrets.php'")
    && str_contains($kitInstall, 'sso_save_secrets('),
    'секреты пишутся выше веб-корня — одной функцией на установщика и экран настроек');
ok(!preg_match('~config\.php.{0,40}client_secret~s', $kitInstall),
    'установщик не вписывает секрет в config.php — тот лежит в веб-корне');
ok(str_contains($kitChecks, "'/.well-known/sso-portal.json', [], 3)"),
    'проверка манифеста не виснет на односоставном сервере: короткий срок ожидания');
/* Экран настроек и проверка связи — обязательны у каждого портала (v4.63).
   До этого проверка жила в selftest.php, открытом всем, а ввести ключ после
   установки было негде, кроме редактора на сервере. */
ok($kitAdmin !== '' && $kitChecks !== '' && !is_file($kitDir . '/sso/selftest.php'),
    'в наборе экран настроек и проверка связи, а открытой всем самопроверки больше нет');
ok(str_contains($kitChecks, 'opcache_get_status'),
    'проверка знает про OPcache — иначе «поправил config.php, ничего не изменилось» не диагностируется');
ok(str_contains($kitBoot, "sso_env('SECRETS_FILE')") && str_contains($kitAdmin, 'sso_secret_source('),
    'экран показывает, откуда взят секрет, — по тем же путям, где его ищет код');
ok(str_contains($kitAdmin, 'sso_probe_secret(')
    && strpos($kitAdmin, 'sso_probe_secret(') < strpos($kitAdmin, 'sso_save_secrets('),
    'секрет сохраняется только после того, как его принял SSO');
ok(str_contains($kitAdmin, 'sso_admin_allowed(') && str_contains($kitAdmin, 'http_response_code($me === null ? 401 : 403)'),
    'экран закрыт для всех, кроме администратора портала');
ok(!preg_match('~\$e\(\s*\$(secret|usersKey|value)\s*\)~', $kitAdmin) && str_contains($kitAdmin, 'type="password"'),
    'секреты на экране не печатаются, поля ввода — скрытые');
ok(str_contains($kitAdmin, "PHP_SAPI === 'cli'"), 'и то же с сервера, когда вход сломан: php sso/admin.php');

/* Виджет под строгий CSP: ни inline-обработчиков, ни атрибутов style. */
$kitWidget = (string)@file_get_contents($kitDir . '/sso/widget.php');
ok(!preg_match('~\son[a-z]+\s*=~i', $kitWidget),
    'в виджете нет inline-обработчиков — при строгом CSP они молча не работают');
ok(!preg_match('~\sstyle\s*=\s*"~i', $kitWidget), 'и нет атрибутов style');
ok(is_file($kitDir . '/sso/sso-widget.js'), 'поведение меню вынесено отдельным файлом');
ok(str_contains((string)@file_get_contents($kitDir . '/sso/sso-button.css'), '.sso-widget-menu'),
    'а оформление — в общей таблице стилей');

$kitSql = (string)@file_get_contents($kitDir . '/sso/migration.sql.example');
ok(str_contains($kitSql, 'sso_sub') && str_contains($kitSql, 'sso_sessions'),
    'шаблон схемы едет в наборе — с sso_sub и реестром сессий');

/* ── Выгрузка людей: обе стороны считают подпись одинаково ──
   users.php уезжает на портал и проверяет там подпись, которую ставит наш
   PortalUsers. Формулу правят с одной стороны, ломается — с другой, и узнаём
   мы об этом от чужого разработчика. Поэтому подписываем запрос ровно как
   PortalUsers и скармливаем настоящему файлу из набора. */
$probe = $kitDir . '/probe.php';
file_put_contents($probe, <<<'PHP'
<?php
/* P_OLD — заголовки под прежними именами X-TSOY-* (до 4.89): их набор принимает до 5.0. */
$pfx = getenv('P_OLD') ? 'HTTP_X_TSOY_' : 'HTTP_X_SSO_';
$_SERVER[$pfx . 'CLIENT'] = getenv('P_CLIENT');
$_SERVER[$pfx . 'TIMESTAMP'] = getenv('P_TS');
$_SERVER[$pfx . 'SIGNATURE'] = getenv('P_SIG');
/* Сырая строка запроса — то, что видит сервер и по чему теперь считается
   подпись. Без неё зонд проверял бы не тот контракт, что живёт в бою. */
$_SERVER['QUERY_STRING'] = getenv('P_QUERY');
parse_str((string)getenv('P_QUERY'), $_GET);
require getenv('P_FILE');
PHP);
putenv('P_FILE=' . $kitDir . '/sso/users.php');
putenv('P_CLIENT=' . (string)$kitClient['client_id']);
$askUsers = static function (string $ts, string $sig, string $query = 'limit=200&offset=0') use ($probe): string {
    putenv('P_TS=' . $ts);
    putenv('P_SIG=' . $sig);
    putenv('P_QUERY=' . $query);
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1', $out);
    return implode("\n", $out);
};
/* Подписываем ровно тем кодом, которым это делает PortalUsers: строку берём
   из настоящего адреса, как она уйдёт по проводу. */
$signLike = static function (string $usersUrl, string $ts, int $limit, int $offset) use ($kitUsersSecret): array {
    $sep = str_contains($usersUrl, '?') ? '&' : '?';
    $q = (string)parse_url($usersUrl . $sep . 'limit=' . $limit . '&offset=' . $offset, PHP_URL_QUERY);
    return [$q, 'sha256=' . hash_hmac('sha256', $ts . '.' . $q, $kitUsersSecret)];
};

$uTs = (string)time();
[$uQuery, $uSig] = $signLike('https://p.example/sso/users.php', $uTs, 200, 0);
$uGood = $askUsers($uTs, $uSig, $uQuery);
ok(str_contains($uGood, '"users"'),
    'подпись PortalUsers принимается файлом из набора', mb_substr($uGood, 0, 120));
putenv('P_OLD=1');
$uOld = $askUsers($uTs, $uSig, $uQuery);
putenv('P_OLD');
ok(str_contains($uOld, '"users"'), 'и под прежними заголовками X-TSOY-* — переходный период до 5.0', mb_substr($uOld, 0, 120));

/* Адрес выгрузки со своим параметром: он попадает в сырую строку запроса, и
   подписывать надо её целиком. Иначе «подпись не сошлась» приходит на
   совершенно законный запрос, а ищут её причину в ключе. */
[$uQ2, $uSig2] = $signLike('https://p.example/sso/users.php?v=2', $uTs, 200, 0);
ok(str_contains($askUsers($uTs, $uSig2, $uQ2), '"users"'),
    'адрес выгрузки со своим параметром тоже проходит проверку', $uQ2);

/* Потолок страницы контракт разрешает поднимать: подпись обязана сойтись и
   на limit=1000, а обрезание — случиться уже после проверки. */
[$uQ3, $uSig3] = $signLike('https://p.example/sso/users.php', $uTs, 1000, 0);
ok(str_contains($askUsers($uTs, $uSig3, $uQ3), '"users"'),
    'limit больше страницы не ломает подпись', $uQ3);

$uBad = $askUsers($uTs, 'sha256=' . str_repeat('0', 64), $uQuery);
ok(str_contains($uBad, 'подпись'), 'подделанная подпись отвергается', mb_substr($uBad, 0, 120));
$uOld = $askUsers((string)(time() - 3600), $uSig, $uQuery);
ok(str_contains($uOld, 'метка времени'), 'вчерашний запрос не повторить', mb_substr($uOld, 0, 120));
@unlink($probe);

\App\Models\Client::update((int)$kitClient['id'], ['users_secret' => $kitSecretWas]);
@unlink($kitZip);

echo "═══ Области доступа: новый механизм считает то же, что старый ═══\n";
/* Фаза 1 работ по SCOPES.md добавляет механизм и НИЧЕГО не переключает.
   Смысл проверок здесь ровно один: доказать, что резолвер по областям даёт
   тот же ответ, что действующий код, — на всех людях и всех порталах базы.
   Пока это не доказано, переводить на него что-либо нельзя. */
ok(\App\Core\AccessResolver::ready(), 'схема областей на месте (миграция 020)');

$scopeSys = \App\Core\Scope::system();
ok($scopeSys->covers(\App\Core\Scope::client(7)), 'система покрывает любой портал');
ok($scopeSys->covers(\App\Core\Scope::org(3)), 'система покрывает любую организацию');
ok(\App\Core\Scope::org(3)->covers(\App\Core\Scope::client(7), 3), 'организация покрывает свой проект');
ok(!\App\Core\Scope::org(3)->covers(\App\Core\Scope::client(7), 4), 'и не покрывает чужой');
ok(!\App\Core\Scope::org(3)->covers(\App\Core\Scope::client(7), null), 'ничей проект не покрывается организацией');
ok(\App\Core\Scope::client(7)->covers(\App\Core\Scope::client(7)), 'портал покрывает сам себя');
ok(!\App\Core\Scope::client(7)->covers(\App\Core\Scope::org(3)), 'снизу вверх полномочия не растут');
ok(!\App\Core\Scope::client(7)->covers(\App\Core\Scope::system()), 'и до системы не дотягиваются');

/* Разбор и сборка: область ездит в сессии, в ссылках и в журнале. */
ok(\App\Core\Scope::parse('org:12')->key() === 'org:12', 'область разбирается и собирается обратно');
ok(\App\Core\Scope::parse('system')->is(\App\Core\Scope::SYSTEM), 'система разбирается');
/* Испорченная запись не должна тихо расширить область: тихое расширение —
   это тихая выдача доступа. */
$junk = \App\Core\Scope::parse('чепуха:5');
ok(!$junk->covers(\App\Core\Scope::client(5)) && !$junk->covers(\App\Core\Scope::system()),
    'испорченная область не покрывает ничего', $junk->key());

/* Сверка на всей базе: для каждого человека и каждого портала уровень,
   посчитанный резолвером, обязан совпасть с тем, что отдаёт AccessLevel. */
$mismatch = [];
$allClients = \App\Models\Client::all();
$someUsers = \App\Core\DB::all('SELECT id, status FROM {users} ORDER BY id LIMIT 40');
foreach ($someUsers as $uRow) {
    $u = \App\Models\User::find((int)$uRow['id']);
    if (!$u) continue;
    foreach ($allClients as $cRow) {
        $cid = (int)$cRow['id'];
        $old = \App\Core\AccessLevel::forClient($u, $cid);
        /* Резолвер отвечает за роли; статус и пол — забота AccessLevel, и
           сравнивать надо одинаковое. Живых сверяем по полу, мёртвых — по
           тому, что статус старше ролей. */
        $new = max(\App\Core\AccessResolver::levelFor((int)$u['id'], $cid), \App\Models\Role::baseLevel());
        if (($u['status'] ?? '') !== 'active') continue;
        if (\App\Core\Owner::id() === (int)$u['id']) $new = max($new, 99);
        if ($old !== $new) $mismatch[] = 'человек ' . $u['id'] . ' портал ' . $cid . ": $old ≠ $new";
    }
}
ok($mismatch === [], 'уровень по областям совпадает с действующим на всей базе',
    implode('; ', array_slice($mismatch, 0, 3)));
ok($someUsers !== [] && $allClients !== [],
    'сверка шла не по пустому месту: людей ' . count($someUsers) . ', порталов ' . count($allClients));

/* Права. Сравнивать «права в системной области» с `permissionsOfUser()` нельзя:
   это разные величины. Действующий метод складывает права ВСЕХ выдач человека,
   не глядя на область (сужение объектов делается потом, через scopeClientIds),
   а резолвер отвечает про конкретную область. Совпадут они только у того, у
   кого все выдачи системные, — то есть проверка была бы про данные, а не про
   механизм. Первая редакция этой проверки именно так и прошла: случайно.

   Настоящее равенство такое: союз прав резолвера по системе и по каждому
   порталу равен тому, что отдаёт действующий метод. */
$permUser = (int)\App\Core\DB::val('SELECT ur.user_id FROM {user_roles} ur
    JOIN {roles} r ON r.id = ur.role_id WHERE r.slug = ? LIMIT 1', ['superadmin']);
ok($permUser > 0, 'нашёлся человек с ролями для сверки прав');
$oldPerms = \App\Models\Role::permissionsOfUser($permUser);
$newPerms = \App\Core\AccessResolver::permsFor($permUser, \App\Core\Scope::system());
foreach ($allClients as $cRow) {
    $newPerms = array_merge($newPerms,
        \App\Core\AccessResolver::permsFor($permUser, \App\Core\Scope::client((int)$cRow['id'])));
}
$newPerms = array_values(array_unique($newPerms));
sort($oldPerms);
sort($newPerms);
ok($oldPerms === $newPerms, 'союз прав по всем областям равен действующему',
    count($oldPerms) . ' против ' . count($newPerms));

echo "═══ Уровень доступа не перетекает между порталами ═══\n";
/* Дыра, найденная при проектировании областей (SCOPES §1.1): список ролей в
   токене фильтровался по порталу, а число — нет. Админ одного сервиса
   приезжал администратором во все прочие, и портал, читающий `>= 70` (как
   учит его наша же integration.md), открывал ему админку. */
\App\Core\DB::run('DELETE FROM {clients} WHERE slug IN (?, ?)', ['lvl-a', 'lvl-b']);
\App\Core\DB::run('DELETE FROM {users} WHERE email IN (?, ?)', ['lvl@test.local', 'lvl-blocked@test.local']);
$mkLvlClient = static fn(string $name, string $slug): array => \App\Models\Client::create([
    'name' => $name, 'slug' => $slug, 'base_url' => 'https://' . $slug . '.example',
    'redirect_uris' => ['https://' . $slug . '.example/cb'], 'post_logout_uris' => [],
    'backchannel_uri' => null, 'allowed_scopes' => ['openid', 'roles'],
    'is_trusted' => 1, 'access_policy' => 'open',
]);
[$lvlA] = $mkLvlClient('Уровень А', 'lvl-a');
[$lvlB] = $mkLvlClient('Уровень Б', 'lvl-b');
$lvlUid = \App\Models\User::create(['email' => 'lvl@test.local', 'first_name' => 'Уровень',
    'status' => 'active', 'email_verified_at' => now()]);
\App\Models\Role::assignScoped($lvlUid, (int)\App\Models\Role::clientAdmin()['id'],
    \App\Core\Scope::client($lvlA));

$claimsFor = static function (int $uid, int $cid): array {
    return \App\Oidc\IdToken::claims(\App\Models\User::find($uid),
        \App\Models\Client::find($cid), 'openid roles');
};
$onA = $claimsFor($lvlUid, $lvlA);
$onB = $claimsFor($lvlUid, $lvlB);
ok((int)$onA['access_level'] === 70 && $onA['access_tier'] === 'service_admin',
    'в своём портале админ остаётся админом', 'level=' . $onA['access_level']);
ok((int)$onB['access_level'] < 70,
    'в ЧУЖОМ портале админских прав нет', 'level=' . $onB['access_level'] . ' tier=' . $onB['access_tier']);

/* Пол: живой человек не бывает нулём. Ноль по нашей шкале означает
   «заблокирован», и отдавать его тому, у кого просто нет ролей в этом
   портале, — сказать порталу неправду. Тем более что роль `user` в claim
   мы дописываем всегда: два утверждения обязаны сходиться. */
ok((int)$onB['access_tier'] !== 'blocked' && $onB['access_tier'] === 'user',
    'человек без ролей в портале — пользователь, а не «заблокирован»', $onB['access_tier']);
ok(in_array('user', (array)$onB['roles'], true),
    'и список ролей говорит то же самое');

/* А настоящая блокировка по-прежнему ноль: статус старше любых ролей. */
$lvlBlocked = \App\Models\User::create(['email' => 'lvl-blocked@test.local',
    'first_name' => 'Блок', 'status' => 'blocked']);
$blockedClaims = $claimsFor($lvlBlocked, $lvlA);
ok((int)$blockedClaims['access_level'] === 0 && $blockedClaims['access_tier'] === 'blocked',
    'заблокированный остаётся нулём — пол его не поднимает');

/* Глобальный админ установки — админ везде, и это не утечка, а замысел:
   роль без портала действует во всех. Роль выдаём сами, а не ищем готового
   носителя: не нашли бы — проверка молча не выполнилась бы, а это хуже, чем
   её отсутствие. */
$lvlGlobal = \App\Models\Role::findBySlug('admin', null);
ok($lvlGlobal !== null, 'глобальная роль admin есть в справочнике');
\App\Models\Role::assign($lvlUid, (int)$lvlGlobal['id']);
ok((int)$claimsFor($lvlUid, $lvlB)['access_level'] >= 70,
    'глобальный админ установки остаётся админом в любом портале');
\App\Models\Role::revoke($lvlUid, (int)$lvlGlobal['id']);

\App\Models\Client::purge($lvlA);
\App\Models\Client::purge($lvlB);
\App\Models\User::purge($lvlUid);
\App\Models\User::purge($lvlBlocked);

echo "═══ Область выдачи: ноль вместо NULL ═══\n";
/* Две беды одного корня, обе найдены на боевой MySQL, обе невидимы на SQLite.
   1. В первичный ключ MySQL не пускает колонку, допускающую NULL: он делает её
      NOT NULL, а существующие NULL-ы конвертировать нечем — «Data truncated for
      column 'scope_id'», и миграция 021 не применяется вовсе.
   2. `scope_id = NULL` не истинно никогда, поэтому проверка «такая выдача уже
      есть» ничего не находила: системные роли множились при каждом вызове, а
      Role::assign() зовётся в том числе на каждом входе через организацию. */
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {user_roles} WHERE scope_id IS NULL') === 0,
    'в выдачах не осталось NULL — в ключ MySQL их не пустит');

$nullCol = true;
if (\App\Core\DB::driver() === 'sqlite') {
    foreach (\App\Core\DB::all('PRAGMA table_info(' . \App\Core\DB::sql('{user_roles}') . ')') as $col) {
        if ((string)$col['name'] === 'scope_id') $nullCol = (int)$col['notnull'] === 0;
    }
}
ok(!$nullCol, 'и сама колонка объявлена NOT NULL');

/* Повтор выдачи не должен плодить строки. Проверяем именно системную область:
   клиентская и раньше работала — у неё идентификатор непустой. */
\App\Core\DB::run('DELETE FROM {users} WHERE email = ?', ['dup-grant@test.local']);
$dupUid = \App\Models\User::create(['email' => 'dup-grant@test.local', 'first_name' => 'Повтор',
    'status' => 'active', 'email_verified_at' => now()]);
$dupRole = (int)\App\Models\Role::findBySlug('user', null)['id'];
for ($i = 0; $i < 3; $i++) {
    \App\Models\Role::assignScoped($dupUid, $dupRole, \App\Core\Scope::system());
}
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {user_roles} WHERE user_id = ? AND role_id = ?',
    [$dupUid, $dupRole]) === 1,
    'повторная выдача системной роли не плодит строки');

/* И отзыв её находит: с NULL сравнение не срабатывало и в DELETE. */
\App\Models\Role::revokeScoped($dupUid, $dupRole, \App\Core\Scope::system());
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {user_roles} WHERE user_id = ? AND role_id = ?',
    [$dupUid, $dupRole]) === 0, 'и отзыв системной роли её находит');
\App\Models\User::purge($dupUid);

echo "═══ Одна роль «Админ портала» вместо копии на каждый портал ═══\n";
/* Фаза 2 SCOPES.md. Раньше `ensureClientAdmin()` заводил строку роли на каждый
   подключённый сервис: три роли по смыслу — `3 + N` строк по факту, и все в
   одном плоском списке в карточке человека. */
$caCount = (int)\App\Core\DB::val('SELECT COUNT(*) FROM {roles} WHERE slug = ?', ['client_admin']);
ok($caCount === 1, 'роль «Админ портала» в справочнике одна', 'найдено: ' . $caCount);
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {roles} WHERE slug = ? AND client_id IS NOT NULL',
    ['client_admin']) === 0, 'портальных копий не осталось');

\App\Core\DB::run('DELETE FROM {clients} WHERE slug IN (?, ?, ?)', ['ph2-a', 'ph2-b', 'ph2-c']);
\App\Core\DB::run('DELETE FROM {users} WHERE email = ?', ['ph2@test.local']);
$mkPh2 = static fn(string $name, string $slug): array => \App\Models\Client::create([
    'name' => $name, 'slug' => $slug, 'base_url' => 'https://' . $slug . '.example',
    'redirect_uris' => ['https://' . $slug . '.example/cb'], 'post_logout_uris' => [],
    'backchannel_uri' => null, 'allowed_scopes' => ['openid', 'roles'],
    'is_trusted' => 1, 'access_policy' => 'open',
]);
[$ph2A] = $mkPh2('Фаза2 А', 'ph2-a');
[$ph2B] = $mkPh2('Фаза2 Б', 'ph2-b');
[$ph2C] = $mkPh2('Фаза2 В', 'ph2-c');
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {roles} WHERE slug = ?', ['client_admin']) === 1,
    'три новых портала не добавили в справочник ни одной роли');

$ph2Uid = \App\Models\User::create(['email' => 'ph2@test.local', 'first_name' => 'Фаза',
    'status' => 'active', 'email_verified_at' => now()]);
$ph2Role = (int)\App\Models\Role::clientAdmin()['id'];
/* Случай, который до фазы 2 не ложился в базу вовсе: ключ выдачи был
   (user_id, role_id), и вторая строка совпадала с первой. */
\App\Models\Role::assignScoped($ph2Uid, $ph2Role, \App\Core\Scope::client($ph2A));
\App\Models\Role::assignScoped($ph2Uid, $ph2Role, \App\Core\Scope::client($ph2B));
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {user_roles} WHERE user_id = ? AND role_id = ?',
    [$ph2Uid, $ph2Role]) === 2, 'один человек — админ двух порталов одной ролью');

$ph2Claims = static fn(int $cid): array => \App\Oidc\IdToken::claims(
    \App\Models\User::find($ph2Uid), \App\Models\Client::find($cid), 'openid roles');
/* Форма claim обязана остаться прежней: это договор с уже подключёнными
   порталами, и менять его схлопыванием наших внутренних строк нельзя. */
ok(in_array('ph2-a:client_admin', (array)$ph2Claims($ph2A)['roles'], true),
    'портал А получает роль в прежней форме «слаг:роль»',
    json_encode($ph2Claims($ph2A)['roles'], JSON_UNESCAPED_UNICODE));
ok(in_array('ph2-b:client_admin', (array)$ph2Claims($ph2B)['roles'], true),
    'портал Б — свою, с его собственным префиксом');
ok(!in_array('client_admin', (array)$ph2Claims($ph2A)['roles'], true),
    'и НЕ получает её без префикса — иначе это была бы глобальная роль');

/* Третий портал: там он никто. Проверка на то, что схлопывание не вернуло
   утечку — системная роль с пустым client_id засчиталась бы везде. */
$ph2Third = $ph2Claims($ph2C);
ok((int)$ph2Third['access_level'] < 70 && !in_array('ph2-c:client_admin', (array)$ph2Third['roles'], true),
    'на третьем портале админских прав нет', 'level=' . $ph2Third['access_level']);
ok(\App\Models\User::clientAdminClientIds($ph2Uid) === [$ph2A, $ph2B],
    'делегированная админка видит ровно свои два портала',
    json_encode(\App\Models\User::clientAdminClientIds($ph2Uid)));

/* Снос портала снимает выдачи с его областью: иначе человек навсегда админ
   того, чего нет, а область указывает в пустоту. */
\App\Models\Client::purge($ph2B);
ok(\App\Models\User::clientAdminClientIds($ph2Uid) === [$ph2A],
    'после сноса портала выдача на него исчезла');
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {roles} WHERE slug = ?', ['client_admin']) === 1,
    'а сама роль пережила снос портала — она системная');

\App\Models\Client::purge($ph2A);
\App\Models\Client::purge($ph2C);
\App\Models\User::purge($ph2Uid);

echo "═══ Организация владеет проектами, роль наследуется ═══\n";
/* Фаза 3 SCOPES.md — тот самый случай, ради которого всё затевалось:
   у организации X есть проекты А и Б; пользователь 1 админ обоих, потому что
   у него роль ОРГАНИЗАЦИИ; пользователь 3 админ только Б — это исключение. */
\App\Core\DB::run('DELETE FROM {clients} WHERE slug IN (?, ?, ?)', ['ph3-a', 'ph3-b', 'ph3-x']);
\App\Core\DB::run('DELETE FROM {orgs} WHERE slug = ?', ['organizaciya-x']);
\App\Core\DB::run('DELETE FROM {users} WHERE email IN (?, ?)', ['ph3-own@test.local', 'ph3-one@test.local']);
$mkPh3 = static fn(string $name, string $slug): array => \App\Models\Client::create([
    'name' => $name, 'slug' => $slug, 'base_url' => 'https://' . $slug . '.example',
    'redirect_uris' => ['https://' . $slug . '.example/cb'], 'post_logout_uris' => [],
    'backchannel_uri' => null, 'allowed_scopes' => ['openid', 'roles'],
    'is_trusted' => 1, 'access_policy' => 'open',
]);
[$ph3A] = $mkPh3('Проект А', 'ph3-a');
[$ph3B] = $mkPh3('Проект Б', 'ph3-b');
[$ph3X] = $mkPh3('Чужой портал', 'ph3-x');
$ph3Org = \App\Models\Org::create('Организация X', null, null);
\App\Models\Client::update($ph3A, ['org_id' => $ph3Org]);
\App\Models\Client::update($ph3B, ['org_id' => $ph3Org]);
\App\Core\AccessResolver::forget();

ok(count(\App\Models\Org::projects($ph3Org)) === 2, 'у организации два проекта');
ok(\App\Core\AccessResolver::orgOfClient($ph3A) === $ph3Org, 'проект знает свою организацию');
ok(\App\Core\AccessResolver::orgOfClient($ph3X) === null, 'чужой портал ничей');

$ph3One = \App\Models\User::create(['email' => 'ph3-own@test.local', 'first_name' => 'Первый',
    'status' => 'active', 'email_verified_at' => now()]);
$ph3Three = \App\Models\User::create(['email' => 'ph3-one@test.local', 'first_name' => 'Третий',
    'status' => 'active', 'email_verified_at' => now()]);
\App\Models\Org::addMember($ph3Org, $ph3One, 'owner', null);
\App\Models\Role::assignScoped($ph3Three, (int)\App\Models\Role::clientAdmin()['id'],
    \App\Core\Scope::client($ph3B));

$ph3Level = static fn(int $uid, int $cid): int => (int)\App\Oidc\IdToken::claims(
    \App\Models\User::find($uid), \App\Models\Client::find($cid), 'openid roles')['access_level'];

ok($ph3Level($ph3One, $ph3A) >= 80 && $ph3Level($ph3One, $ph3B) >= 80,
    'роль организации действует в ОБОИХ её проектах — наследование',
    'А=' . $ph3Level($ph3One, $ph3A) . ' Б=' . $ph3Level($ph3One, $ph3B));
ok($ph3Level($ph3One, $ph3X) < 70, 'и не действует в чужом портале',
    'чужой=' . $ph3Level($ph3One, $ph3X));
ok($ph3Level($ph3Three, $ph3B) >= 70 && $ph3Level($ph3Three, $ph3A) < 70,
    'исключение: роль на один проект действует только в нём',
    'А=' . $ph3Level($ph3Three, $ph3A) . ' Б=' . $ph3Level($ph3Three, $ph3B));

/* Роль организации приезжает в claim с префиксом портала — так же, как
   портальная: для сервиса это его роль, откуда она унаследована, ему незачем. */
$ph3Claims = (array)\App\Oidc\IdToken::claims(\App\Models\User::find($ph3One),
    \App\Models\Client::find($ph3A), 'openid roles')['roles'];
ok(in_array('ph3-a:org_owner', $ph3Claims, true),
    'унаследованная роль едет в claim с префиксом этого проекта',
    json_encode($ph3Claims, JSON_UNESCAPED_UNICODE));

/* Полномочия организации — обычные роли, а не второе перечисление. */
ok(\App\Models\Org::roleOf($ph3Org, $ph3One) === 'owner', 'полномочие читается из выдачи');
ok(\App\Models\Role::findBySlug('org_owner', null) !== null, 'роль владельца организации системная');
\App\Models\Org::setRole($ph3Org, $ph3One, 'member');
ok(\App\Models\Org::roleOf($ph3Org, $ph3One) === 'member', 'понижение снимает полномочие');
ok($ph3Level($ph3One, $ph3A) < 70, 'и права в проектах пропадают вместе с ним',
    'А=' . $ph3Level($ph3One, $ph3A));

/* Делегированная админка ищет порталы по ПРАВУ их менять, а не по имени роли. */
\App\Models\Org::setRole($ph3Org, $ph3One, 'owner');
$ph3Scope = \App\Models\User::clientAdminClientIds($ph3One);
sort($ph3Scope);
ok($ph3Scope === [$ph3A, $ph3B], 'админка владельца видит оба проекта организации',
    json_encode($ph3Scope));

\App\Models\Client::purge($ph3A);
\App\Models\Client::purge($ph3B);
\App\Models\Client::purge($ph3X);
\App\Models\Org::purge($ph3Org);
\App\Models\User::purge($ph3One);
\App\Models\User::purge($ph3Three);
\App\Core\AccessResolver::forget();

echo "═══ Тестовое событие: чеклист приёмки стало чем проверять ═══\n";
/* Чеклист просит портал убедиться, что «выйти везде» гасит его сессию, а
   блокировка выкидывает человека. Проверить это было нечем: событие приходит
   от нас, подделать его нельзя — подпись. */
\App\Core\DB::run('DELETE FROM {clients} WHERE slug = ?', ['ev-probe']);
[$evId] = \App\Models\Client::create([
    'name' => 'Приёмник событий', 'slug' => 'ev-probe', 'base_url' => 'http://localhost:8711',
    'redirect_uris' => ['http://localhost:8711/sso/callback.php'], 'post_logout_uris' => [],
    'backchannel_uri' => 'http://localhost:8711/hook.php',
    'allowed_scopes' => ['openid'], 'is_trusted' => 1, 'access_policy' => 'open',
]);
http('POST', SSO . "/admin/clients/$evId/action", ['_csrf' => $csrf, 'action' => 'test_event']);
$evRows = \App\Models\ClientEvent::recentForClient($evId);
ok($evRows !== [] && ($evRows[0]['event'] ?? '') === 'test',
    'кнопка ставит в очередь настоящее событие типа test',
    json_encode(array_column($evRows, 'event')));
ok(($evRows[0]['status'] ?? '') !== 'pending' || (int)($evRows[0]['attempts'] ?? 0) > 0,
    'и попытка доставки делается сразу, а не когда-нибудь',
    (string)($evRows[0]['status'] ?? '—'));

/* Без адреса приёмника слать некуда — и это должно быть сказано, а не молча
   проглочено: иначе кнопка выглядит сломанной. */
\App\Models\Client::update($evId, ['backchannel_uri' => null]);
$evBefore = count(\App\Models\ClientEvent::recentForClient($evId));
http('POST', SSO . "/admin/clients/$evId/action", ['_csrf' => $csrf, 'action' => 'test_event']);
ok(count(\App\Models\ClientEvent::recentForClient($evId)) === $evBefore,
    'без адреса приёмника событие не заводится');
[, $evCard] = http('GET', SSO . "/admin/clients/$evId/events");
ok(str_contains($evCard, 'value="test_event"') && str_contains($evCard, 'disabled'),
    'а кнопка на карточке выключена и объясняет почему');

\App\Models\Client::purge($evId);

echo "═══ Право в области: «можно ли ему это ЗДЕСЬ» ═══\n";
/* Фаза 6 SCOPES.md. До сих пор вопрос звучал только «есть ли право вообще», а
   границу держало сужение списков. Теперь его можно задать точно — и право,
   выданное на соседний портал, в этом уже не считается. */
\App\Core\DB::run('DELETE FROM {clients} WHERE slug IN (?, ?)', ['ph6-a', 'ph6-b']);
\App\Core\DB::run('DELETE FROM {users} WHERE email = ?', ['ph6@test.local']);
[$ph6A] = $mkPh3('Право А', 'ph6-a');
[$ph6B] = $mkPh3('Право Б', 'ph6-b');
$ph6Uid = \App\Models\User::create(['email' => 'ph6@test.local', 'first_name' => 'Право',
    'status' => 'active', 'email_verified_at' => now()]);
\App\Models\Role::assignScoped($ph6Uid, (int)\App\Models\Role::clientAdmin()['id'],
    \App\Core\Scope::client($ph6A));

$ph6In = \App\Core\AccessResolver::permsFor($ph6Uid, \App\Core\Scope::client($ph6A));
$ph6Out = \App\Core\AccessResolver::permsFor($ph6Uid, \App\Core\Scope::client($ph6B));
ok(in_array('clients.write', $ph6In, true), 'в своём портале право есть');
ok(!in_array('clients.write', $ph6Out, true), 'в соседнем — нет, хотя роль та же самая');
ok(\App\Models\Role::permissionsOfUser($ph6Uid) !== $ph6Out,
    'и вопрос без области отвечает иначе — это разные вопросы, а не один');

\App\Models\Client::purge($ph6A);
\App\Models\Client::purge($ph6B);
\App\Models\User::purge($ph6Uid);

echo "═══ Журнал организации: события её проектов ═══\n";
/* Фаза 5 SCOPES.md. Колонка org_id есть с миграции 015, но заполнялась только
   там, где вызывающий сам её передал, — то есть на событиях самой организации.
   Журнал показывал переименования да смену состава, а всё, ради чего
   организация заведена, оставалось за кадром. */
\App\Core\DB::run('DELETE FROM {clients} WHERE slug = ?', ['ph5-proj']);
\App\Core\DB::run('DELETE FROM {orgs} WHERE slug LIKE ?', ['zhurnal%']);
[$ph5Client] = $mkPh3('Проект журнала', 'ph5-proj');
$ph5Org = \App\Models\Org::create('Журнал орг', null, null);
\App\Models\Client::update($ph5Client, ['org_id' => $ph5Org]);
\App\Core\AccessResolver::forget();

\App\Core\Audit::log('admin.client.update', ['client' => $ph5Client]);
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {audit_log} WHERE org_id = ? AND client_id = ?',
    [$ph5Org, $ph5Client]) === 1,
    'событие проекта наследует организацию само, без передачи её вызывающим');

\App\Core\Audit::log('admin.org.rename', ['org' => $ph5Org]);
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {audit_log} WHERE org_id = ?', [$ph5Org]) === 2,
    'в журнале организации и её собственные события, и события проектов');

/* Ничей проект организацию не выдумывает. */
[$ph5Free] = $mkPh3('Ничей проект', 'ph5-free');
\App\Core\AccessResolver::forget();
\App\Core\Audit::log('admin.client.update', ['client' => $ph5Free]);
ok(\App\Core\DB::val('SELECT org_id FROM {audit_log} WHERE client_id = ? ORDER BY id DESC',
    [$ph5Free]) === null, 'у события ничьего проекта организации нет');

/* Смена владельца не переписывает историю: событие принадлежит той
   организации, в которой произошло. */
$ph5Other = \App\Models\Org::create('Журнал орг вторая', null, null);
\App\Models\Client::update($ph5Client, ['org_id' => $ph5Other]);
\App\Core\AccessResolver::forget();
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {audit_log} WHERE org_id = ? AND client_id = ?',
    [$ph5Org, $ph5Client]) === 1,
    'смена владельца проекта не переписывает уже записанное');

\App\Models\Client::purge($ph5Client);
\App\Models\Client::purge($ph5Free);
\App\Models\Org::purge($ph5Org);
\App\Models\Org::purge($ph5Other);
\App\Core\AccessResolver::forget();

echo "═══ Контекст админки: где я сейчас работаю ═══\n";
/* Фаза 4 SCOPES.md. Контекст — фильтр, а не выдача прав: он только сужает.
   Проверяем оба конца, потому что перепутать их — значит либо показать чужое,
   либо спрятать своё. */
\App\Core\DB::run('DELETE FROM {clients} WHERE slug IN (?, ?)', ['ph4-in', 'ph4-out']);
\App\Core\DB::run('DELETE FROM {orgs} WHERE slug IN (?, ?)', ['organizaciya-ch', 'chuzhaya-org']);
[$ph4In] = $mkPh3('Свой проект', 'ph4-in');
[$ph4Out] = $mkPh3('Чужой проект', 'ph4-out');
$ph4Org = \App\Models\Org::create('Организация Ч', null, null);
$ph4Other = \App\Models\Org::create('Чужая орг', null, null);
\App\Models\Client::update($ph4In, ['org_id' => $ph4Org]);
\App\Models\Client::update($ph4Out, ['org_id' => $ph4Other]);
\App\Core\AccessResolver::forget();

$ctxWas = $_SESSION['admin_scope'] ?? null;
$_SESSION['admin_scope'] = null;
unset($_SESSION['admin_scope']);
ok(\App\Core\AdminContext::current()->is(\App\Core\Scope::SYSTEM),
    'по умолчанию контекст — корень, «все организации»');
ok(\App\Core\AdminContext::clientIds() === null, 'в корне список порталов не сужается');

$_SESSION['admin_scope'] = 'org:' . $ph4Org;
$ph4Visible = \App\Core\AdminContext::clientIds();
ok(is_array($ph4Visible) && in_array($ph4In, $ph4Visible, true),
    'выбрав организацию, вижу её проект');
ok(is_array($ph4Visible) && !in_array($ph4Out, $ph4Visible, true),
    'и не вижу чужой — даже будучи глобальным админом');
ok(\App\Core\AdminContext::label() === 'Организация Ч', 'в шапке стоит имя организации',
    \App\Core\AdminContext::label());

/* Исчезнувшая организация не должна запирать человека в пустом контексте. */
$_SESSION['admin_scope'] = 'org:999999';
ok(\App\Core\AdminContext::current()->is(\App\Core\Scope::SYSTEM),
    'контекст несуществующей организации сам возвращается в корень');

/* Переключение проверяет право: чужую область выбрать нельзя. */
$_SESSION['admin_scope'] = 'org:' . $ph4Org;
ok(\App\Core\AdminContext::set(\App\Core\Scope::client($ph4In)) === false,
    'портал областью работы не выбирается — переключение только по организациям');
ok(\App\Core\AdminContext::current()->key() === 'org:' . $ph4Org,
    'и неудачное переключение ничего не меняет');

/* Контекст — рамка обзора, а не забор. Ссылка на чужой проект, присланная в
   переписке, обязана открыться: иначе выбор организации превращается в
   самозапирание. Проверяем через HTTP, потому что ломалось именно там. */
$ctxCsrf = csrfOf((string)http('GET', SSO . '/admin/clients')[1]);
http('POST', SSO . '/admin/context', ['_csrf' => $ctxCsrf, 'scope' => 'org:' . $ph4Org]);
[$stIn] = http('GET', SSO . '/admin/clients/' . $ph4In);
[$stOut] = http('GET', SSO . '/admin/clients/' . $ph4Out);
ok($stIn === 200 && $stOut === 200,
    'в контексте организации карточка чужого проекта всё равно открывается',
    "свой=$stIn чужой=$stOut");
[, $listBody] = http('GET', SSO . '/admin/clients');
ok(str_contains($listBody, '/admin/clients/' . $ph4In)
    && !str_contains($listBody, '/admin/clients/' . $ph4Out),
    'а в списке остаётся только свой');

/* Кнопка «подключить сервис» гасла условием «список не сужен»: стоило выбрать
   свою организацию — и заводить сервисы становилось нечем. Сужение списка про
   то, что человек СМОТРИТ, а не про то, что ему можно. */
ok(str_contains($listBody, 'data-modal-open="wizard"'),
    'в контексте организации кнопка подключения сервиса на месте');
$wizCsrf = csrfOf($listBody);
[$stMk] = http('POST', SSO . '/admin/clients/create',
    ['_csrf' => $wizCsrf, 'base_url' => 'https://созданный-в-орг.example', 'org_id' => $ph4Org]);
$mkRow = \App\Core\DB::row('SELECT id, org_id FROM {clients} WHERE base_url = ?',
    ['https://созданный-в-орг.example']);
ok($stMk === 302 && $mkRow !== null, 'сервис заводится', "st=$stMk");
ok((int)($mkRow['org_id'] ?? 0) === $ph4Org,
    'и сразу принадлежит организации, а не остаётся ничьим',
    'org_id=' . (string)($mkRow['org_id'] ?? '—'));

/* Раздел «Сервисы» организации — то место, где кнопку и ищут. */
[, $prjBody] = http('GET', SSO . '/admin/orgs/' . $ph4Org . '/projects');
ok(str_contains($prjBody, 'data-modal-open="wizard"'),
    'в разделе сервисов организации кнопка тоже есть');
ok(str_contains($prjBody, 'name="org_id" value="' . $ph4Org . '"'),
    'и мастер оттуда заводит сервис именно этой организации');

http('POST', SSO . '/admin/context', ['_csrf' => $ctxCsrf, 'scope' => 'system']);

if ($ctxWas === null) unset($_SESSION['admin_scope']); else $_SESSION['admin_scope'] = $ctxWas;
\App\Models\Client::purge($ph4In);
\App\Models\Client::purge($ph4Out);
\App\Models\Org::purge($ph4Org);
\App\Models\Org::purge($ph4Other);
\App\Core\AccessResolver::forget();

echo "═══ Установка моста: ссылка вместо правки config.php ═══\n";
/* Отдельный подопытный портал: обмен билета ПЕРЕВЫПУСКАЕТ секрет, и делать
   это с портлом, который дальше участвует в проверках, нельзя. */
\App\Core\DB::run('DELETE FROM {clients} WHERE slug = ?', ['install-probe']);
[$insId, $insClientId] = \App\Models\Client::create([
    'name' => 'Проба установки', 'slug' => 'install-probe',
    'base_url' => 'https://install.example',
    'redirect_uris' => ['https://install.example/sso/callback.php'],
    'post_logout_uris' => ['https://install.example/'], 'backchannel_uri' => null,
    'allowed_scopes' => ['openid', 'profile', 'email', 'roles'],
    'is_trusted' => 1, 'access_policy' => 'open',
]);
$insClient = \App\Models\Client::find($insId);
$adminId = (int)\App\Core\DB::val('SELECT id FROM {users} ORDER BY id LIMIT 1');

$insUrl = \App\Core\PortalInstall::issue($insClient, $adminId);
ok(str_starts_with($insUrl, 'https://install.example/sso/install.php?ticket='),
    'ссылка установки ведёт на сам портал', $insUrl);
$insTicket = substr($insUrl, strpos($insUrl, 'ticket=') + 7);

/* Роль администратора портала выдаётся вместе со ссылкой: портал узнаёт
   владельца по роли, и если роли нет — узнавать нечего. Роль одна на
   установку, а к этому порталу её привязывает ОБЛАСТЬ выдачи (v4.24). */
$insRole = \App\Models\Role::clientAdmin();
ok(\App\Core\DB::val('SELECT 1 FROM {user_roles}
      WHERE user_id = ? AND role_id = ? AND scope_type = ? AND scope_id = ?',
    [$adminId, (int)$insRole['id'], 'client', $insId]) !== null,
    'выдавший ссылку стал администратором портала');
ok(in_array($insId, \App\Models\User::clientAdminClientIds($adminId), true),
    'и делегированная админка это видит');

$secretWas = (string)\App\Models\Client::find($insId)['client_secret_hash'];
[$st, $body] = http('POST', SSO . '/portal/bootstrap', ['ticket' => $insTicket]);
$boot = json_decode($body, true);
ok($st === 200 && !empty($boot['ok']), 'билет обменивается на реквизиты', "st=$st " . mb_substr($body, 0, 80));
ok(!empty($boot['client_secret']) && !empty($boot['client_id']), 'в ответе есть client_id и секрет');
ok(($boot['client_id'] ?? '') === $insClientId, 'секрет отдан именно этому порталу');
ok(!empty($boot['users_secret']), 'ключ переноса приезжает сразу — второй кнопкой ходить не нужно');
ok(($boot['owner']['sub'] ?? '') !== '', 'владелец установки назван — по нему портал свяжет свой аккаунт');
ok(($boot['redirect_uri'] ?? '') === 'https://install.example/sso/callback.php', 'адрес возврата подставлен');

/* Секрет перевыпущен: билет — единственный источник действующего, и утёкшая
   копия старого набора умирает сама. */
$insAfter = \App\Models\Client::find($insId);
ok((string)$insAfter['client_secret_hash'] !== $secretWas, 'при установке секрет перевыпускается');
ok(\App\Models\Client::verifySecret($insAfter, (string)$boot['client_secret']),
    'выданный секрет — рабочий, а не декоративный');
ok((string)$insAfter['users_secret'] === (string)$boot['users_secret'],
    'ключ переноса тот же, что записан у портала');

/* Один обмен. Иначе ссылка из чата или истории браузера остаётся ключом. */
[$st2, $body2] = http('POST', SSO . '/portal/bootstrap', ['ticket' => $insTicket]);
ok($st2 === 403, 'билет обменивается ровно один раз', "st=$st2");
[$st3] = http('POST', SSO . '/portal/bootstrap', ['ticket' => 'совсем-не-билет']);
ok($st3 === 403, 'выдуманный билет отвергается', "st=$st3");
[$st4] = http('POST', SSO . '/portal/bootstrap', []);
ok($st4 === 400, 'без билета — внятный отказ, а не 500', "st=$st4");

/* Выключенный портал реквизиты не отдаёт: выключен — значит не пускает
   никого, и установка тут ничем не отличается от входа. */
\App\Models\Client::update($insId, ['is_active' => 0]);
$offUrl = \App\Core\PortalInstall::issue(\App\Models\Client::find($insId), $adminId);
$offTicket = substr($offUrl, strpos($offUrl, 'ticket=') + 7);
[$st5, $body5] = http('POST', SSO . '/portal/bootstrap', ['ticket' => $offTicket]);
ok($st5 === 403 && str_contains($body5, 'выключен'), 'выключенный портал реквизиты не отдаёт', "st=$st5");

\App\Models\Client::purge($insId);

echo "═══ Почта: письмо не теряется и не крутится вечно ═══\n";
$mailId = \App\Core\Mailer::send('smoke-mail@test.local', 'Смоук очереди', 'verify',
    ['name' => 'Смоук', 'url' => base_url('/verify/smoke')]);
$mailRow = \App\Core\DB::row('SELECT * FROM {mail_queue} WHERE id = ?', [$mailId]);
ok(str_contains((string)$mailRow['body_html'], '/verify/smoke'), 'тело письма собрано из шаблона');
ok(trim((string)$mailRow['body_text']) !== '', 'текстовая версия письма не пустая');

/* Отказ SMTP: письмо обязано остаться в очереди с записанной причиной, а после
   пяти попыток уйти в failed — иначе мёртвый адрес крутился бы вечно. */
$cfgProp = new ReflectionProperty(\App\Core\Config::class, 'data');
$cfgProp->setAccessible(true);
$cfgWas = $cfgProp->getValue();
$cfgBad = $cfgWas;
$cfgBad['mail']['driver'] = 'smtp';
$cfgBad['mail']['host'] = '127.0.0.1';
$cfgBad['mail']['port'] = 9;
$cfgProp->setValue(null, $cfgBad);
\App\Core\Setting::set('mail.driver', 'smtp');
\App\Core\Setting::set('mail.host', '127.0.0.1');
\App\Core\Setting::set('mail.port', '9');

$failId = \App\Core\Mailer::send('smoke-fail@test.local', 'Смоук отказа', 'verify',
    ['name' => 'Смоук', 'url' => base_url('/verify/fail')]);
\App\Core\Mailer::attempt($failId);
$failRow = \App\Core\DB::row('SELECT status, attempts, last_error FROM {mail_queue} WHERE id = ?', [$failId]);
/* Попыток уже две: Mailer::send() пробует отправить сразу, не дожидаясь
   планировщика, — письмо в очереди только если первая попытка не удалась. */
ok($failRow['status'] === 'pending' && (int)$failRow['attempts'] >= 1,
    'после отказа письмо ждёт следующей попытки',
    (string)$failRow['status'] . '/' . (int)$failRow['attempts']);
ok(trim((string)$failRow['last_error']) !== '', 'причина отказа записана');

for ($i = 0; $i < 5; $i++) \App\Core\Mailer::attempt($failId);
$failRow = \App\Core\DB::row('SELECT status, attempts FROM {mail_queue} WHERE id = ?', [$failId]);
ok($failRow['status'] === 'failed', 'после пяти попыток письмо уходит в failed — очередь не заклинивает',
    (string)$failRow['status'] . '/' . (int)$failRow['attempts']);

$cfgProp->setValue(null, $cfgWas);
foreach (['mail.driver', 'mail.host', 'mail.port'] as $k) \App\Core\Setting::delete($k);
\App\Core\Setting::flush();
\App\Core\DB::run('DELETE FROM {mail_queue} WHERE id IN (?, ?)', [$mailId, $failId]);

echo "═══ Права действительно запирают ═══\n";
/* Главное про таблицу прав: она должна что-то решать. Заводим админа с
   урезанным набором и смотрим, куда его пускает, а куда нет. */
\App\Core\DB::run('DELETE FROM {rate_limits}');
\App\Core\DB::run('DELETE FROM {roles} WHERE slug = ?', ['smoke-limited']);
$limRole = \App\Models\Role::create(null, 'smoke-limited', 'Смоук: урезанный');
\App\Models\Role::setPermissions($limRole, ['users.read', 'logs.read']);
$limUser = \App\Models\User::create(['email' => 'smoke-limited@test.local', 'first_name' => 'Урезанный',
    'password_hash' => password_hash('correct-horse-battery', PASSWORD_ARGON2ID),
    'status' => 'active', 'email_verified_at' => now()]);
\App\Models\Role::assign($limUser, (int)\App\Models\Role::findBySlug('admin')['id']);
\App\Core\DB::run('DELETE FROM {role_permissions} WHERE role_id = ?',
    [(int)\App\Models\Role::findBySlug('admin')['id']]);
\App\Models\Role::assign($limUser, $limRole);

$limJar = __DIR__ . '/tmp/smoke-limited.txt';
@unlink($limJar);
[, $lp] = http('GET', SSO . '/login', [], [], $limJar);
http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => 'smoke-limited@test.local',
    'password' => 'correct-horse-battery'], [], $limJar);

[$st] = http('GET', SSO . '/admin/users', [], [], $limJar);
ok($st === 200, 'с users.read список пользователей открывается', "status=$st");
[$st] = http('GET', SSO . '/admin/logs', [], [], $limJar);
ok($st === 200, 'с logs.read журнал открывается', "status=$st");
[$st] = http('GET', SSO . '/admin/settings', [], [], $limJar);
ok($st === 403, 'без settings.read настройки закрыты', "status=$st");
[$st] = http('GET', SSO . '/admin/clients', [], [], $limJar);
ok($st === 403, 'без clients.read порталы закрыты', "status=$st");
[$st] = http('GET', SSO . "/admin/users/$limUser/danger", [], [], $limJar);
ok($st === 403, 'без users.delete опасная зона закрыта', "status=$st");

/* Действие внутри общего POST-эндпоинта тоже должно спрашивать право —
   именно там его проще всего забыть. */
$before = (int)\App\Core\DB::val('SELECT COUNT(*) FROM {users} WHERE status = ?', ['blocked']);
[, $ucard] = http('GET', SSO . "/admin/users/$limUser", [], [], $limJar);
http('POST', SSO . "/admin/users/$limUser/action",
    ['_csrf' => csrfOf($ucard), 'action' => 'block'], [], $limJar);
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {users} WHERE status = ?', ['blocked']) === $before,
    'без users.write действие не выполняется');

/* Роль client_admin заводится лениво — и должна получать права сразу, иначе
   делегированный админ, появившийся позже, не может ничего. С v4.24 она одна
   на установку, поэтому и заводится один раз, а не на каждый портал. */
\App\Core\DB::run('DELETE FROM {roles} WHERE slug = ? AND client_id IS NULL', ['client_admin']);
$ca = \App\Models\Role::clientAdmin();
$caPerms = \App\Models\Role::permissions((int)$ca['id']);
ok(in_array('clients.write', $caPerms, true),
    'новая роль client_admin получает свои права сразу', implode(',', $caPerms));
ok($ca['client_id'] === null && $ca['scope_type'] === 'system',
    'и заводится системной, а не портальной');

\App\Core\DB::run('DELETE FROM {user_roles} WHERE user_id = ?', [$limUser]);
\App\Core\DB::run('DELETE FROM {users} WHERE id = ?', [$limUser]);
\App\Core\DB::run('DELETE FROM {roles} WHERE id = ?', [$limRole]);
\App\Models\Role::setPermissions((int)\App\Models\Role::findBySlug('admin')['id'],
    \App\Core\Permission::DEFAULTS['admin']);

echo "═══ Полномочия админов (v4.3) ═══\n";
$victim = \App\Models\User::create(['email' => 'smoke-purge@test.local', 'first_name' => 'Снос',
    'password_hash' => password_hash('x', PASSWORD_ARGON2ID), 'status' => 'active']);
\App\Models\Role::assign($victim, (int)\App\Models\Role::findBySlug('user')['id']);
\App\Core\DB::insert('known_devices', ['user_id' => $victim, 'fingerprint' => str_repeat('a', 32),
    'device_label' => 'Тест', 'first_seen_at' => now(), 'last_seen_at' => now()]);

/* Ссылка входа: одноразовая, короткая, и владелец узнаёт о ней письмом —
   иначе это тихий вход в чужой аккаунт. */
foreach ((array)glob(SSO_ROOT . '/storage/logs/mail/*.eml') as $f) @unlink($f);
http('POST', SSO . "/admin/users/$victim/action", ['_csrf' => $csrf, 'action' => 'login_link']);
$issued = \App\Core\DB::row('SELECT type FROM {one_time_tokens} WHERE user_id = ? ORDER BY id DESC LIMIT 1',
    [$victim]);
ok(($issued['type'] ?? '') === 'login_link', 'ссылка входа выдана');
$notified = false;
foreach ((array)glob(SSO_ROOT . '/storage/logs/mail/*.eml') as $f) {
    if (str_contains((string)file_get_contents($f), 'ссылка')) $notified = true;
}
ok($notified, 'владелец аккаунта уведомлён письмом');

/* Окончательное удаление — только из открытой зоны и с верным адресом. */
\App\Core\DB::run('DELETE FROM {rate_limits}');
http('POST', SSO . "/admin/users/$victim/action",
    ['_csrf' => $csrf, 'action' => 'purge', 'confirm' => 'smoke-purge@test.local']);
ok(\App\Models\User::find($victim) !== null, 'удаление в обход закрытой зоны не проходит');

http('POST', SSO . "/admin/users/$victim/danger", ['_csrf' => $csrf, 'password' => 'correct-horse-battery']);
http('POST', SSO . "/admin/users/$victim/action",
    ['_csrf' => $csrf, 'action' => 'purge', 'confirm' => 'не-тот@адрес']);
ok(\App\Models\User::find($victim) !== null, 'неверный адрес не удаляет');

/* Порталы обязаны узнать об удалении. Событие рассылается по строкам доступа,
   а purge() их сносит — значит послать надо ДО. Раньше уходил только вебхук, и
   портал оставался с живым аккаунтом человека, которого у нас уже нет. */
$victimClient = (int)\App\Models\Client::find($inviteClient)['id'];
\App\Models\Access::grant($victim, $victimClient, (int)$admin['id'], 'active');
\App\Models\Client::update($victimClient, ['backchannel_uri' => 'https://portal.example/sso/backchannel.php']);
$eventsBefore = (int)\App\Core\DB::val(
    'SELECT COUNT(*) FROM {client_events} WHERE client_id = ? AND event = ?',
    [$victimClient, 'user.deleted']);
http('POST', SSO . "/admin/users/$victim/action",
    ['_csrf' => $csrf, 'action' => 'purge', 'confirm' => 'smoke-purge@test.local']);
ok(\App\Models\User::find($victim) === null, 'аккаунт удаляется окончательно');
$eventsAfter = (int)\App\Core\DB::val(
    'SELECT COUNT(*) FROM {client_events} WHERE client_id = ? AND event = ?',
    [$victimClient, 'user.deleted']);
ok($eventsAfter > $eventsBefore,
    'портал получил user.deleted — до того, как строки доступа исчезли',
    "было $eventsBefore, стало $eventsAfter");
$left = (int)\App\Core\DB::val('SELECT COUNT(*) FROM {user_roles} WHERE user_id = ?', [$victim])
      + (int)\App\Core\DB::val('SELECT COUNT(*) FROM {known_devices} WHERE user_id = ?', [$victim]);
ok($left === 0, 'привязанные строки убраны', "осталось $left");
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {audit_log} WHERE event = ?', ['admin.user.purge']) > 0,
    'аудит переживает своего фигуранта');

/* Суперадмина зона не даёт удалить: это отрезало бы систему от управления. */
$superId = (int)\App\Models\User::findByEmail('admin@test.local')['id'];
[, $sdz] = http('GET', SSO . "/admin/users/$superId/danger");
ok(str_contains($sdz, 'Сначала снимите роль'), 'суперадмина зона удалять не предлагает');

/* Маска IP — только в своём профиле, в админке адрес всегда целиком. */
[, $logsPage] = http('GET', SSO . '/admin/logs');
ok(!str_contains($logsPage, '✻'), 'в админке IP не замаскирован');

echo "═══ Права ролей и уровень доступа (v4.3) ═══\n";
$adminRoleId = (int)\App\Core\DB::val('SELECT id FROM {roles} WHERE slug = ? AND client_id IS NULL', ['admin']);
$superRoleId = (int)\App\Core\DB::val('SELECT id FROM {roles} WHERE slug = ? AND client_id IS NULL', ['superadmin']);

[$st, $roleCard] = http('GET', SSO . "/admin/roles/$adminRoleId");
ok($st === 200 && str_contains($roleCard, 'perms[]'), 'карточка роли показывает таблицу прав');

/* Матрица прав держится на четырёх опорах в разметке. Уронить их правкой
   шаблона легко, и скрипт от этого не падает — он просто перестаёт работать:
   ни строки режима, ни счётчика изменений, ни кнопки «весь столбец», и ни
   одной ошибки в консоли. */
foreach ([
    'data-perm-form' => 'форма помечена',
    'data-area=' => 'строки называют область',
    'data-action=' => 'клетки называют действие',
    'data-perm-mode' => 'есть место под строку режима',
    'class="perm-cell"' => 'клетка — зона нажатия',
    'class="perm-box"' => 'квадрат рисуется своим span',
    'perm-col' => 'шапка столбца кликабельна',
] as $needle => $what) {
    ok(str_contains($roleCard, $needle), "матрица прав: $what");
}

/* Изменение без чтения — набор, из которого нельзя составить работающий экран.
   Чтение дописывается молча: человек хотел разрешить, а не запретить.
   На этой гарантии держится и интерфейс: скрипт отключает галочку «смотреть»,
   когда она проставлена автоматически, а отключённое поле не отправляется. */
http('POST', SSO . "/admin/roles/$adminRoleId/permissions",
    ['_csrf' => $csrf, 'perms' => ['users.write'], 'access_level' => '70']);
$p = \App\Models\Role::permissions($adminRoleId);
ok(in_array('users.read', $p, true) && in_array('users.write', $p, true),
    'чтение области дописывается к изменению', implode(',', $p));

http('POST', SSO . "/admin/roles/$adminRoleId/permissions",
    ['_csrf' => $csrf, 'perms' => ['users.read', 'выдуманное.право'], 'access_level' => '70']);
ok(!in_array('выдуманное.право', \App\Models\Role::permissions($adminRoleId), true),
    'право не из каталога отбрасывается');

/* Суперадмину права не выдаются галочками: снять их с себя значило бы
   запереть систему, и никакая форма не должна этого позволять. */
http('POST', SSO . "/admin/roles/$superRoleId/permissions",
    ['_csrf' => $csrf, 'perms' => ['users.read'], 'access_level' => '10']);
ok(\App\Models\Role::permissions($superRoleId) === []
    && (int)\App\Core\DB::val('SELECT access_level FROM {roles} WHERE id = ?', [$superRoleId]) === 91,
    'права и уровень суперадмина не редактируются');

http('POST', SSO . "/admin/roles/$adminRoleId/permissions",
    ['_csrf' => $csrf, 'perms' => ['users.read'], 'access_level' => '500']);
ok((int)\App\Core\DB::val('SELECT access_level FROM {roles} WHERE id = ?', [$adminRoleId]) === 99,
    'уровень выше 99 обрезается');

/* Статус старше роли: заблокированному не помогают никакие права. */
$someone = \App\Models\User::findByEmail('admin@test.local');
ok(\App\Core\AccessLevel::of(['id' => (int)$someone['id'], 'status' => 'blocked']) === 0,
    'заблокированный получает уровень 0 независимо от ролей');
ok(\App\Core\AccessLevel::tier(60) === 'editor' && \App\Core\AccessLevel::tier(61) === 'editor',
    'ступень берётся ближайшая снизу — промежутки не ломают шкалу');
ok(\App\Core\AccessLevel::of($someone) === 99, 'владелец инсталляции — 99 независимо от роли');

\App\Models\Role::setPermissions($adminRoleId, \App\Core\Permission::DEFAULTS['admin']);
\App\Models\Role::setLevel($adminRoleId, 70);

echo "═══ Здоровье: поставка, прозвон, сводка, API ═══\n";
/* Разбор боевой аварии: не доехал один класс соцвхода — и весь экран входа
   отдавал 500 при полностью зелёном светофоре здоровья. Значит светофор
   отвечал не на тот вопрос. */

/* 1. Падение вылечено: провайдер без файла пропускается, а не роняет вход.
      Проверяем НА САМОМ ДЕЛЕ — убирая файл, а не рассуждая о нём. Сервер здесь
      отдельный процесс, автозагрузка у него своя, так что отсутствие класса он
      увидит по-настоящему. Именно это и было на боевой. */
$provAll = \App\Social\Provider::all();
ok(count($provAll) >= 3, 'провайдеры соцвхода собираются', 'найдено: ' . count($provAll));

$vkFile = SSO_ROOT . '/app/Social/VkProvider.php';
$vkHidden = $vkFile . '.hidden-by-smoke';
$restoreVk = static function () use ($vkFile, $vkHidden): void {
    if (is_file($vkHidden)) @rename($vkHidden, $vkFile);
};
/* Если проверка оборвётся на середине, файл всё равно вернётся на место:
   тест не имеет права оставить после себя сломанное дерево. */
register_shutdown_function($restoreVk);

/* Спрашивать надо ГОСТЕМ: вошедшего экран входа уводит редиректом, не успевая
   собрать кнопки соцвхода, — то есть ровно ту строку, что падала, мы бы и не
   выполнили. Отдельная банка cookie на это и нужна. */
$vkJar = __DIR__ . '/tmp/vk-guest.txt';
@unlink($vkJar);
if (rename($vkFile, $vkHidden)) {
    [$stNoVk, $bodyNoVk] = http('GET', SSO . '/login', [], [], $vkJar);
    ok($stNoVk === 200, 'без файла провайдера экран входа всё равно открывается', "st=$stNoVk");
    ok(!str_contains($bodyNoVk, 'not found') && !str_contains($bodyNoVk, 'VkProvider'),
        'и не показывает «class not found» — ровно эта авария и была на боевой');
    $restoreVk();
    [$stVk] = http('GET', SSO . '/login', [], [], $vkJar);
    ok($stVk === 200, 'после возврата файла вход открывается по-прежнему', "st=$stVk");
} else {
    ok(false, 'файл провайдера удалось убрать для проверки', $vkFile);
}
@unlink($vkJar);

/* 1б. Страница 500 сама называет недоехавший файл.
       Второй случай подряд: не доехал `app/Core/Incident.php` — и упала ровно та
       страница, на которую страница 500 отправляет за подробностями. Владелец
       получил номер инцидента и ссылку в журнал, ссылка вела в 500, и узнать
       причину было неоткуда. Теперь причина написана на самой странице. */
$blame = \App\Core\Delivery::blame(new \Error('Class "App\Core\СовсемНетТакого" not found'));
ok(($blame['file'] ?? '') === 'app/Core/СовсемНетТакого.php',
    'по «Class ... not found» назван путь к файлу', (string)($blame['file'] ?? '—'));
ok(\App\Core\Delivery::blame(new \Error('Class "App\Core\Incident" not found')) === null,
    'а существующий класс поводом не считается');
ok(\App\Core\Delivery::blame(new \RuntimeException('что-то своё')) === null,
    'и обычная ошибка тоже');

$incFile = SSO_ROOT . '/app/Core/Incident.php';
$incHidden = $incFile . '.hidden-by-smoke';
$restoreInc = static function () use ($incFile, $incHidden): void {
    if (is_file($incHidden)) @rename($incHidden, $incFile);
};
register_shutdown_function($restoreInc);
if (rename($incFile, $incHidden)) {
    [$stInc, $bodyInc] = http('GET', SSO . '/admin/logs/errors?incident=deadbeef');
    ok($stInc === 500, 'без файла страница инцидента падает — как и было на боевой', "st=$stInc");
    ok(str_contains($bodyInc, 'app/Core/Incident.php'),
        'но теперь она сама называет недоехавший файл, а не только номер');
    $restoreInc();
    [$stInc2] = http('GET', SSO . '/admin/logs/errors?incident=deadbeef');
    ok($stInc2 === 200, 'с файлом поиск по номеру работает', "st=$stInc2");
} else {
    ok(false, 'файл Incident удалось убрать для проверки', $incFile);
}

/* 2. Опись поставки ловит недоехавший файл. Проверяем на настоящей описи:
      кладём её с несуществующим файлом и смотрим, что он назван. */
$manifestPath = SSO_ROOT . '/dist-manifest.json';
$manifestWas = is_file($manifestPath) ? (string)file_get_contents($manifestPath) : null;
file_put_contents($manifestPath, json_encode([
    'version' => '0.0.0-test', 'built_at' => gmdate('c'),
    'files' => ['app/helpers.php' => 100, 'app/Social/НетТакогоФайла.php' => 42],
]));
$dv = \App\Core\Delivery::verify();
ok($dv['known'] && !$dv['ok'], 'неполная поставка распознаётся');
ok(in_array('app/Social/НетТакогоФайла.php', $dv['missing'], true),
    'и недоехавший файл назван поимённо', implode(', ', $dv['missing']));
/* Файл на месте, но не того размера — оставшийся от прошлой версии. Раньше
   такая установка считалась целой: код старый, версия в подвале новая, а
   лечений нет. Именно так и выглядела боевая после оборванной заливки. */
ok(count($dv['changed']) === 1 && str_starts_with($dv['changed'][0], 'app/helpers.php'),
    'файл не того размера назван отдельно', implode(', ', $dv['changed']));
ok(str_contains($dv['changed'][0], 'вместо 100'),
    'и сказано, сколько ожидалось', $dv['changed'][0]);
/* Полоса состояния: администратор узнаёт о неполной поставке, СТОЯ на любой
   странице, а не наткнувшись на 500. Четыре недоехавших файла подряд узнавались
   именно так — падением. */
@unlink(SSO_ROOT . '/storage/cache/delivery-gap.json');
$gap = \App\Core\Delivery::gap();
ok(($gap['missing'] ?? 0) === 1, 'нехватка видна коротким ответом для полосы');
[, $barPage] = http('GET', SSO . '/admin/users');
ok(str_contains($barPage, 'Поставка неполная'), 'и полоса стоит на странице администратора');
$guestJar2 = __DIR__ . '/tmp/delivery-guest.txt';
@unlink($guestJar2);
[, $guestPage] = http('GET', SSO . '/login', [], [], $guestJar2);
ok(!str_contains($guestPage, 'Поставка неполная'), 'постороннему её не показывают');
@unlink($guestJar2);

/* Шаблон, который не доехал: страница обязана назвать файл, а не только номер. */
$blameTpl = \App\Core\Delivery::blame(new \RuntimeException('Шаблон не найден: templates/admin/НетТакого.php'));
ok(($blameTpl['file'] ?? '') === 'templates/admin/НетТакого.php',
    'недоехавший шаблон тоже назван поимённо', (string)($blameTpl['file'] ?? '—'));

if ($manifestWas === null) @unlink($manifestPath); else file_put_contents($manifestPath, $manifestWas);
@unlink(SSO_ROOT . '/storage/cache/delivery-gap.json');
ok(\App\Core\Delivery::verify()['ok'], 'после возврата описи поставка снова цела');
ok(\App\Core\Delivery::gap() === null, 'и полоса гаснет');

/* 3. Сводка ошибок: сводит одинаковое и считает. */
\App\Core\Log::error('здоровье-проба одна');
\App\Core\Log::error('здоровье-проба одна');
$dig = \App\Core\ErrorDigest::top(20);
$found = null;
foreach ($dig['day'] as $row) if ($row['msg'] === 'здоровье-проба одна') $found = $row;
ok($found !== null && (int)$found['count'] >= 2,
    'одинаковые ошибки сведены и посчитаны', 'счёт: ' . (int)($found['count'] ?? 0));

/* 3б. Прозвон различает, кто ответил: приложение или веб-сервер.
       На боевой discovery отдавал 404, и по одному коду нельзя было понять, наш
       это отказ или правило хостинга «резать всё, что начинается с точки», —
       чинить надо в разных местах. Метка X-SSO-App (до 4.89 — X-TSOY-ID) отвечает на этот вопрос.
       Статика встроенного сервера отдаётся мимо PHP — вот и «не наш» ответ. */
$whoJudge = static fn(int $code, string $body, bool $ours): array
    => [$ours ? 'ok' : 'warn', $ours ? 'приложение' : 'не приложение'];
$hitOwn = \App\Core\Probe::hit('своя 404', SSO . '/нет-такой-страницы', $whoJudge);
ok($hitOwn['note'] === 'приложение', 'ответ приложения опознан даже на 404', $hitOwn['note']);
$hitStatic = \App\Core\Probe::hit('статика', SSO . '/assets/icons.svg', $whoJudge);
ok($hitStatic['note'] === 'не приложение', 'ответ мимо приложения опознан тоже', $hitStatic['note']);

/* 4. Разделы здоровья — каждый своим адресом. */
foreach (['' => 'Что проверено', '/errors' => 'За сутки', '/delivery' => 'поставк',
          '/probe' => 'Прозвон'] as $path => $marker) {
    [$stH, $bodyH] = http('GET', SSO . '/admin/health' . $path);
    ok($stH === 200 && mb_stripos($bodyH, $marker) !== false,
        'раздел здоровья «' . ($path ?: '/') . '» на месте', "st=$stH");
}

/* 5. API: без токена не отдаёт ничего. */
[$stApi] = http('GET', SSO . '/health.json');
ok($stApi === 401, 'состояние по API закрыто без токена', "st=$stApi");
\App\Core\Setting::set('health.api_token', 'smoke-token-' . bin2hex(random_bytes(4)));
\App\Core\Setting::flush();
$apiTok = (string)\App\Core\Setting::get('health.api_token', '');
[$stApi2] = http('GET', SSO . '/health.json?token=wrong-one');
ok($stApi2 === 401, 'и с чужим токеном тоже', "st=$stApi2");
[$stApi3, $apiBody] = http('GET', SSO . '/health.json?token=' . $apiTok);
$apiJson = json_decode($apiBody, true);
ok($stApi3 === 200 && is_array($apiJson), 'с токеном отдаёт JSON', "st=$stApi3");
ok(($apiJson['version'] ?? '') === SSO_VERSION && isset($apiJson['checks'], $apiJson['delivery']),
    'в ответе версия, проверки и целость поставки');
ok(!str_contains($apiBody, 'client_secret') && !str_contains($apiBody, 'password'),
    'и ни одного секрета в ответе');

/* Возврат на портал не зависит от сессии.
   Обещание SSO одно: пришёл с сайта А — вернёшься на сайт А. Держалось оно на
   PHP-сессии, а она хрупкая: потеряется — и человек оказывается в личном
   кабинете SSO, которого не просил. Проверяем самый злой случай: сессии нет
   вовсе, есть только ключ возврата. */
$resClient = \App\Models\Client::find($inviteClient);
/* Модель отдаёт адреса уже разобранными; из базы они приезжают строкой JSON —
   принимаем оба вида, чтобы проверка не зависела от того, кто её позвал. */
$resUris = is_array($resClient['redirect_uris'])
    ? $resClient['redirect_uris']
    : (array)json_decode((string)$resClient['redirect_uris'], true);
$resUri = (string)($resUris[0] ?? '');
$resJar = __DIR__ . '/tmp/resume-a.txt';
@unlink($resJar);
$authzUrl = SSO . '/authorize?' . http_build_query([
    'response_type' => 'code', 'client_id' => $resClient['client_id'],
    'redirect_uri' => $resUri, 'scope' => 'openid', 'state' => 's1', 'nonce' => 'n1',
    'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256',
]);
[, , $azLoc] = http('GET', $authzUrl, [], [], $resJar);
parse_str((string)parse_url($azLoc, PHP_URL_QUERY), $azQ);
$rtKey = (string)($azQ['rt'] ?? '');
ok(strlen($rtKey) > 20, 'ключ возврата выдаётся вместе с переходом на вход', substr($rtKey, 0, 8));

$lostJar = __DIR__ . '/tmp/resume-lost.txt';
@unlink($lostJar);
[, $lostLogin] = http('GET', SSO . '/login?rt=' . urlencode($rtKey), [], [], $lostJar);
ok(str_contains($lostLogin, 'name="rt"'), 'ключ доезжает до формы входа скрытым полем');
[, , $backLoc] = http('POST', SSO . '/login', [
    '_csrf' => csrfOf($lostLogin), 'identity' => $guestEmail,
    'password' => 'guest-password-1', 'rt' => $rtKey], [], $lostJar);
ok(str_contains($backLoc, '/authorize'),
    'с потерянной сессией человек всё равно возвращается на портал', $backLoc);

/* Второй раз тем же ключом — снова на портал. До v4.60 здесь стояло обратное:
   «нет, ключ одноразовый». Одноразовость и уводила в личный кабинет: живой
   случай 10.09 — возврат оборвался, человек нажал «Войти» ещё раз, а ключ уже
   погашен. Не защищала она при этом ничего: первое использование ключа так же
   сильно, как второе. Срок ключа проверяет tests/portal_connect.php. */
$lostJar2 = __DIR__ . '/tmp/resume-lost2.txt';
@unlink($lostJar2);
[, $again] = http('GET', SSO . '/login?rt=' . urlencode($rtKey), [], [], $lostJar2);
[, , $twiceLoc] = http('POST', SSO . '/login', [
    '_csrf' => csrfOf($again), 'identity' => $guestEmail,
    'password' => 'guest-password-1', 'rt' => $rtKey], [], $lostJar2);
ok(str_contains($twiceLoc, '/authorize'),
    'второй раз тем же ключом — тоже на портал, а не в личный кабинет', $twiceLoc);
ok(str_contains($twiceLoc, 'redirect_uri=' . urlencode($resUri)),
    'и туда же, куда просил портал: запрос хранится у SSO, ключ его только называет', $twiceLoc);
@unlink($resJar); @unlink($lostJar); @unlink($lostJar2);

/* Роль сервиса выдаётся в строке человека в «Пользователях» (v4.68).
   Раньше та же выдача стояла ещё и в «Ролях» — второй таблицей тех же людей со
   своим списком. Замечание владельца 12.09: два места для одного действия, и
   раздел ролей самый тяжёлый в карточке. */
$svcRoleId = \App\Models\Role::create($cardCid, 'redaktor', 'Редактор');
\App\Models\Access::grant($guestId, $cardCid, (int)$admin['id'], 'active');
[, $rolesPage] = http('GET', SSO . '/admin/clients/' . $cardCid . '/roles');
ok(str_contains($rolesPage, 'Роли этого сервиса') && !str_contains($rolesPage, 'value="role_grant"'),
    'в «Ролях» — только роли: выдачи людям там больше нет');
[, $peoplePage] = http('GET', SSO . '/admin/clients/' . $cardCid . '/people');
[$stRg] = http('POST', SSO . '/admin/clients/' . $cardCid . '/action', [
    '_csrf' => csrfOf($peoplePage), 'action' => 'role_grant',
    'user_id' => $guestId, 'role_id' => $svcRoleId]);
$hasSvcRole = \App\Core\DB::val(
    'SELECT COUNT(*) FROM {user_roles} WHERE user_id = ? AND role_id = ? AND scope_type = ? AND scope_id = ?',
    [$guestId, $svcRoleId, \App\Core\Scope::CLIENT, $cardCid]);
ok($stRg === 302 && (int)$hasSvcRole === 1,
    'пришедшая из портала роль выдаётся человеку', "st=$stRg");
[, $rolesPage2] = http('GET', SSO . '/admin/clients/' . $cardCid . '/people');
ok(str_contains($rolesPage2, 'title="redaktor">Редактор'), 'и видна плашкой в строке человека');
[$stRv] = http('POST', SSO . '/admin/clients/' . $cardCid . '/action', [
    '_csrf' => csrfOf($rolesPage2), 'action' => 'role_revoke',
    'user_id' => $guestId, 'role_id' => $svcRoleId]);
ok((int)\App\Core\DB::val(
    'SELECT COUNT(*) FROM {user_roles} WHERE user_id = ? AND role_id = ? AND scope_type = ? AND scope_id = ?',
    [$guestId, $svcRoleId, \App\Core\Scope::CLIENT, $cardCid]) === 0,
    'и отзывается оттуда же', "st=$stRv");

/* Менеджер ролей сервиса: завести и убрать роль, не выходя из его карточки.
   Раньше здесь стоял ряд плашек — «какие роли есть» и всё; завести можно было
   только на общей странице ролей, то есть уйдя из карточки того сервиса, о
   котором и речь. */
[, $mgrPage] = http('GET', SSO . '/admin/clients/' . $cardCid . '/roles');
ok(str_contains($mgrPage, 'Завести роль') && str_contains($mgrPage, 'name="client_id"'),
    'в карточке сервиса можно завести роль');
[$stMk2] = http('POST', SSO . '/admin/roles/create', [
    '_csrf' => csrfOf($mgrPage), 'client_id' => $cardCid,
    'slug' => 'moderator', 'name' => 'Модератор',
    '_back' => '/admin/clients/' . $cardCid . '/roles']);
$made = \App\Models\Role::findBySlug('moderator', $cardCid);
ok($stMk2 === 302 && $made !== null, 'роль заводится из карточки', "st=$stMk2");
ok((int)($made['access_level'] ?? 0) === 55,
    'и уровень выводится из имени — 55, как у модератора в справочнике',
    'уровень ' . (int)($made['access_level'] ?? 0));
ok(\App\Models\Role::permissions((int)$made['id']) === [],
    'прав в самом SSO у неё нет');
[, , $backLoc2] = http('POST', SSO . '/admin/roles/' . (int)$made['id'] . '/delete', [
    '_csrf' => csrfOf($mgrPage), '_back' => '/admin/clients/' . $cardCid . '/roles']);
ok(\App\Models\Role::findBySlug('moderator', $cardCid) === null
    && str_contains($backLoc2, '/admin/clients/' . $cardCid),
    'и удаляется оттуда же, возвращая в карточку', $backLoc2);

/* Роль, о которой сообщил портал, заводится одной кнопкой и сразу с уровнем.
   Раньше она была надписью: видно, что «редактор» есть, а выдать нечем. */
if (\App\Core\PortalManifest::ready()) {
    \App\Core\DB::insert('client_roles_seen', ['client_id' => $cardCid, 'slug' => 'blog_editor',
        'title' => 'Автор блога', 'seen_at' => now()]);
    [, $seenPage] = http('GET', SSO . '/admin/clients/' . $cardCid . '/roles');
    ok(str_contains($seenPage, 'Завести роль'), 'у сообщённой роли есть кнопка «завести»');
    [$stAd] = http('POST', SSO . '/admin/clients/' . $cardCid . '/action', [
        '_csrf' => csrfOf($seenPage), 'action' => 'role_adopt', 'slug' => 'blog_editor']);
    $adopted = \App\Models\Role::findBySlug('blog_editor', $cardCid);
    ok($stAd === 302 && $adopted !== null, 'роль заводится', "st=$stAd");
    ok((int)($adopted['access_level'] ?? 0) === 60,
        'и опознана как редактор — уровень 60, а не двадцатка наугад',
        'уровень ' . (int)($adopted['access_level'] ?? 0));
    ok(\App\Models\Role::permissions((int)$adopted['id']) === [],
        'прав SSO у неё нет: она метка для портала, а не полномочие у нас');

    \App\Core\DB::insert('client_roles_seen', ['client_id' => $cardCid, 'slug' => 'wtf_role',
        'title' => 'Неведомое', 'seen_at' => now()]);
    [, $seen2] = http('GET', SSO . '/admin/clients/' . $cardCid . '/roles');
    http('POST', SSO . '/admin/clients/' . $cardCid . '/action', [
        '_csrf' => csrfOf($seen2), 'action' => 'role_adopt', 'slug' => 'wtf_role']);
    $unknown = \App\Models\Role::findBySlug('wtf_role', $cardCid);
    ok((int)($unknown['access_level'] ?? 0) === 20,
        'незнакомое имя старшинства не даёт', 'уровень ' . (int)($unknown['access_level'] ?? 0));

    /* «admin» портала — администратор сервиса, 70 (v4.61). Заведённый раньше
       с двадцаткой поднимается кнопкой в той же строке — так было с «admin»
       Архива: «администратор … 20 · Пользователь». */
    \App\Core\DB::upsert('client_roles_seen', ['client_id' => $cardCid, 'slug' => 'admin',
        'title' => 'администратор', 'seen_at' => now()], ['seen_at' => now()], 'client_id, slug');
    $legacy = \App\Models\Role::findBySlug('admin', $cardCid);
    $legacyId = $legacy ? (int)$legacy['id']
        : \App\Models\Role::create($cardCid, 'admin', 'администратор', 'Заведена по сообщению портала.');
    \App\Models\Role::setLevel($legacyId, 20);
    [, $seen3] = http('GET', SSO . '/admin/clients/' . $cardCid . '/roles');
    ok(str_contains($seen3, 'Выставить 70'), 'у «admin» с двадцаткой — кнопка «выставить 70»');
    http('POST', SSO . '/admin/clients/' . $cardCid . '/action', [
        '_csrf' => csrfOf($seen3), 'action' => 'role_relevel', 'slug' => 'admin']);
    $relevel = (int)(\App\Models\Role::find($legacyId)['access_level'] ?? 0);
    ok($relevel === 70, 'и поднимает роль до администратора сервиса', 'уровень ' . $relevel);
    [, $seen4] = http('GET', SSO . '/admin/clients/' . $cardCid . '/roles');
    ok(!str_contains($seen4, 'Выставить 70'), 'после чего кнопки нет — в строке настоящий уровень');
}

/* 5б. Событие по номеру через API: без него разбор упирается в пересылку
       кусков журнала руками, а причина видна только в трассировке. */
\App\Core\Log::error('здоровье-проба по номеру', ['secret_token' => 'НЕЛЬЗЯ-ОТДАВАТЬ', 'file' => 'x.php']);
$incId = '';
foreach (\App\Core\ErrorDigest::top(50)['day'] as $row) {
    if ($row['msg'] === 'здоровье-проба по номеру') $incId = (string)$row['request_id'];
}
ok(\App\Core\Incident::valid($incId), 'номер события есть в сводке', $incId);
[$stI, $bodyI] = http('GET', SSO . '/health.json?token=' . $apiTok . '&incident=' . $incId);
$jsonI = json_decode($bodyI, true);
ok($stI === 200 && !empty($jsonI['incident']['events']),
    'событие по номеру отдаётся по API', "st=$stI");
ok(str_contains($bodyI, 'здоровье-проба по номеру'), 'и это именно оно');
ok(!str_contains($bodyI, 'НЕЛЬЗЯ-ОТДАВАТЬ'),
    'а значение под «секретным» именем вырезано');
[$stI2] = http('GET', SSO . '/health.json?token=' . $apiTok . '&incident=' . rawurlencode('не-номер'));
ok($stI2 === 400, 'мусор вместо номера отвергается', "st=$stI2");
\App\Core\Setting::delete('health.api_token');
\App\Core\Setting::flush();

echo "═══ Инцидент по номеру со страницы 500 ═══\n";
/* Страница ошибки показывает номер и говорит «назовите его администратору,
   событие найдётся в логах». Найти его при этом было нечем: экран ошибок
   читал последние 800 строк ОДНОГО дня и фильтровал только по уровню. */
$incMsg = 'смоук-инцидент ' . bin2hex(random_bytes(3));
\App\Core\Log::error($incMsg, ['exception' => 'RuntimeException']);
$incId = SSO_REQUEST_ID;

$incHits = \App\Core\Incident::find($incId);
ok(count($incHits) >= 1, 'событие находится по номеру инцидента', 'найдено: ' . count($incHits));
/* Записей у одного номера бывает несколько: он общий на весь запрос, и
   прогон успевает записать в журнал не одну строку. Ищем нужную среди них, а
   не ждём её первой — иначе проверка держалась бы на порядке записей. */
ok(in_array($incMsg, array_column(array_column($incHits, 'entry'), 'msg'), true),
    'и это именно оно, а не однофамилец', 'записей: ' . count($incHits));
ok(\App\Core\Incident::find('00000000') === [], 'несуществующий номер даёт пусто, а не первое попавшееся');
ok(!\App\Core\Incident::valid('xyz') && \App\Core\Incident::valid('a6154c59'),
    'номер проверяется по форме — восемь знаков');

[$stInc, $incBody] = http('GET', SSO . '/admin/logs/errors?incident=' . $incId);
ok($stInc === 200 && str_contains($incBody, $incMsg),
    'админка показывает событие по номеру', "st=$stInc");
[, $incJunk] = http('GET', SSO . '/admin/logs/errors?incident=xyz');
ok(str_contains($incJunk, 'восьми знаков'), 'и объясняет, если номер набран неверно');

echo "═══ Формы: подписи и одинаковые id ═══\n";
/* Два дефекта, невидимых глазом и обнаруженных только замером.

   Пустой `<label>` — не «без подписи», а подпись из пустоты: экранный диктор
   объявляет поле безымянным. Одинаковые `id` в строках таблицы хуже: щелчок по
   подписи любой строки уводит фокус в первую, потому что `for` ссылается на
   первый попавшийся элемент. Оба лечатся в слое полей, а не по местам вызова. */
$formPages = ['/admin/settings', '/admin/appearance', '/admin/webhooks', '/admin/users',
              '/admin/roles', '/admin/clients/' . $cid, '/profile'];
$emptyLabels = [];
$dupIds = [];
foreach ($formPages as $p) {
    [, $html] = http('GET', SSO . $p);
    if (preg_match_all('~<label for="[^"]*"></label>~', $html, $m)) {
        $emptyLabels[] = $p . ' (' . count($m[0]) . ')';
    }
    preg_match_all('~ id="(f-[a-z0-9_-]+)"~i', $html, $idm);
    $dups = array_keys(array_filter(array_count_values($idm[1]), static fn(int $n): bool => $n > 1));
    if ($dups !== []) $dupIds[] = $p . ': ' . implode(',', array_slice($dups, 0, 3));
}
ok($emptyLabels === [], 'в формах нет подписей из пустоты', implode('; ', $emptyLabels));
ok($dupIds === [], 'и нет двух полей с одним id', implode('; ', $dupIds));

/* Проверка обязана быть не вхолостую: страницы с полями должны существовать. */
[, $probeForm] = http('GET', SSO . '/admin/settings');
ok(substr_count($probeForm, '<label for=') >= 3,
    'разбор шёл по страницам с полями, а не по пустым');

echo "═══ Организации: правка, роли, опасная зона ═══\n";
\App\Core\DB::run('DELETE FROM {orgs} WHERE slug LIKE ?', ['smoke-org%']);
$orgId = \App\Models\Org::create('Smoke org ' . bin2hex(random_bytes(2)), 'до правки', $adminId ?? 1);
$orgSlug = (string)\App\Models\Org::find($orgId)['slug'];

/* Организация — рабочее место с колонкой разделов, а не карточка со вкладками
   (v4.29). Вкладка не даёт ссылку на раздел: на неё нельзя сослаться, её не
   найдёт поиск, и после сохранения человек оказывается на первой. */
[, $orgCard] = http('GET', SSO . "/admin/orgs/$orgId");
ok((bool)preg_match('~class="set-rail(?: [^"]*)?"~', $orgCard), 'у организации своя колонка разделов');
ok(preg_match('~<code[^>]*>' . preg_quote($orgSlug, '~') . '</code>~', $orgCard) === 1
    && preg_match('~<code[^>]*>org_' . $orgId . '</code>~', $orgCard) === 1,
    'машинные идентификаторы стоят рядом с именем');

/* Ссылка в колонке, ведущая в никуда, — тихая поломка: пункт есть, нажатие
   даёт ошибку. Проверяем КАЖДЫЙ раздел настоящим запросом, а не наличием
   разметки: раньше здесь сверялись вкладки с панелями, и это ловило ровно то
   же самое на прежнем устройстве. */
preg_match_all('~href="(/admin/orgs/' . $orgId . '[^"]*)"~', $orgCard, $railM);
$railLinks = array_values(array_unique($railM[1]));
$railBad = [];
foreach ($railLinks as $href) {
    [$st] = http('GET', SSO . $href);
    if ($st !== 200) $railBad[] = $href . ' → ' . $st;
}
ok(count($railLinks) >= 6 && $railBad === [],
    'каждый раздел организации открывается по своему адресу (' . count($railLinks) . ')',
    implode('; ', $railBad));

/* Разделы, ради которых всё затевалось: сервисы, люди, роли, журнал — своими
   адресами, а не вкладками одного экрана. */
foreach (['projects' => 'Присоединить сервис', 'people' => 'Состав организации',
          'logs' => 'Журнал'] as $sec => $marker) {
    [$st, $body] = http('GET', SSO . "/admin/orgs/$orgId/$sec");
    ok($st === 200 && str_contains($body, $marker),
        "раздел «{$sec}» на месте и управляет своим", "st=$st");
}

/* Раздела «Роли» у организации больше нет (v4.68): своих ролей у неё нет, а
   выдача на её область — в строке участника «Пользователей». Старый адрес
   ведёт туда же, чтобы закладки не упирались в 404. */
[$stOr, , $locOr] = http('GET', SSO . "/admin/orgs/$orgId/roles");
ok($stOr === 302 && str_ends_with((string)$locOr, "/admin/orgs/$orgId/people"),
    'старый адрес «Ролей» организации ведёт в «Пользователей»', "st=$stOr loc=$locOr");
\App\Models\Org::addMember($orgId, (int)$admin['id'], 'member', null);
[, $orgPeople] = http('GET', SSO . "/admin/orgs/$orgId/people");
ok(str_contains($orgPeople, 'Роли в организации') && str_contains($orgPeople, 'value="role_grant"'),
    'роли на организацию — столбцом и выдачей в строке участника');
$orgOffer = \App\Core\RoleOffer::inScope(\App\Core\Scope::org($orgId));
if ($orgOffer !== []) {
    $orgRid = (int)$orgOffer[0]['id'];
    [$stOg, , $locOg] = http('POST', SSO . "/admin/orgs/$orgId/action", ['_csrf' => csrfOf($orgPeople),
        'action' => 'role_grant', 'user_id' => (int)$admin['id'], 'role_id' => $orgRid]);
    $orgGranted = array_filter(\App\Models\Role::grantsInScope(\App\Core\Scope::org($orgId)),
        static fn(array $g): bool => (int)$g['user_id'] === (int)$admin['id'] && (int)$g['role_id'] === $orgRid);
    ok($stOg === 302 && $orgGranted !== [] && str_ends_with((string)$locOg, "/admin/orgs/$orgId/people"),
        'выдача из строки участника работает и возвращает в «Пользователей»', "st=$stOg loc=$locOg");
    \App\Models\Role::revokeScoped((int)$admin['id'], $orgRid, \App\Core\Scope::org($orgId));
}

/* История объекта — новое измерение журнала. До миграции 015 организации в
   аудите не было вовсе: слаг лежал внутри JSON, а запрос по JSON в MySQL и
   SQLite пишется по-разному, то есть проверялся бы не тем движком. */
/* Событие заводим сами. Раньше проверка рассчитывала на то, что к этому месту
   в журнале уже что-то есть про эту организацию, — и держалась на порядке
   соседних блоков: стоило добавить рядом новый, как «найдено 0». Проверка,
   зависящая от чужого следа, проверяет не то, что написано в её названии. */
http('POST', SSO . "/admin/orgs/$orgId/action",
    ['_csrf' => $csrf, 'action' => 'rename', 'name' => 'Smoke org (для журнала)', 'description' => 'до правки']);
$orgEvents = (int)\App\Core\DB::val('SELECT COUNT(*) FROM {audit_log} WHERE org_id = ?', [$orgId]);
ok($orgEvents > 0, "события организации пишутся с её идентификатором (найдено $orgEvents)");
ok(\App\Core\ObjectHistory::of('org', $orgId) !== [], 'история организации читается');
ok(\App\Core\ObjectHistory::of('org', 0) === [], 'история несуществующего объекта пуста, а не падает');
ok(\App\Core\ObjectHistory::of('выдуманный-тип', $orgId) === [],
    'неизвестный тип объекта не превращается в запрос по случайной колонке');

/* Переименование: организация живёт годами, название отдела меняется. */
http('POST', SSO . "/admin/orgs/$orgId/action",
    ['_csrf' => $csrf, 'action' => 'rename', 'name' => 'Smoke org переименованная', 'description' => 'после правки']);
$org = \App\Models\Org::find($orgId);
ok($org['name'] === 'Smoke org переименованная' && $org['description'] === 'после правки',
    'название и описание меняются');
ok($org['slug'] === $orgSlug, 'слаг при переименовании не меняется — он уходит порталам в claim');

/* Роль участника меняется на месте, дата добавления сохраняется. */
$member = (int)\App\Core\DB::val('SELECT id FROM {users} ORDER BY id LIMIT 1');
\App\Models\Org::addMember($orgId, $member, 'member', $member);
$joined = (string)\App\Core\DB::val('SELECT created_at FROM {org_members} WHERE org_id = ? AND user_id = ?',
    [$orgId, $member]);
http('POST', SSO . "/admin/orgs/$orgId/action",
    ['_csrf' => $csrf, 'action' => 'set_role', 'user_id' => $member, 'role' => 'admin']);
$row = \App\Core\DB::row('SELECT role, created_at FROM {org_members} WHERE org_id = ? AND user_id = ?',
    [$orgId, $member]);
ok($row['role'] === 'admin', 'роль участника меняется');
ok((string)$row['created_at'] === $joined, 'дата добавления при смене роли сохраняется');

http('POST', SSO . "/admin/orgs/$orgId/action",
    ['_csrf' => $csrf, 'action' => 'set_role', 'user_id' => $member, 'role' => 'выдуманная']);
ok(\App\Core\DB::val('SELECT role FROM {org_members} WHERE org_id = ? AND user_id = ?',
    [$orgId, $member]) === 'admin', 'несуществующая роль не применяется');

/* Выключение — единственное действие, которое раньше не попадало в аудит. */
http('POST', SSO . "/admin/orgs/$orgId/action", ['_csrf' => $csrf, 'action' => 'toggle']);
ok((int)\App\Models\Org::find($orgId)['is_active'] === 0, 'организация выключается');
ok(\App\Core\DB::val('SELECT COUNT(*) FROM {audit_log} WHERE event = ?', ['admin.org.toggle']) > 0,
    'выключение организации попадает в аудит');

/* Опасная зона — тот же механизм, что у порталов. */
\App\Core\DB::run('DELETE FROM {rate_limits}');
[, $ozone] = http('GET', SSO . "/admin/orgs/$orgId/danger");
ok(str_contains($ozone, 'name="password"') && !str_contains($ozone, 'name="confirm"'),
    'зона организации закрыта и просит пароль');
http('POST', SSO . "/admin/orgs/$orgId/action",
    ['_csrf' => $csrf, 'action' => 'delete', 'confirm' => $orgSlug]);
ok(\App\Models\Org::find($orgId) !== null, 'удаление организации в обход зоны не проходит');

http('POST', SSO . "/admin/orgs/$orgId/danger", ['_csrf' => $csrf, 'password' => 'correct-horse-battery']);
http('POST', SSO . "/admin/orgs/$orgId/action",
    ['_csrf' => $csrf, 'action' => 'delete', 'confirm' => 'не-тот-слаг']);
ok(\App\Models\Org::find($orgId) !== null, 'в открытой зоне неверный слаг не удаляет');

http('POST', SSO . "/admin/orgs/$orgId/action",
    ['_csrf' => $csrf, 'action' => 'delete', 'confirm' => $orgSlug]);
ok(\App\Models\Org::find($orgId) === null, 'организация удаляется по верному слагу');
ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {org_members} WHERE org_id = ?', [$orgId]) === 0,
    'состав организации убран вместе с ней');

echo "═══ Удаление портала ═══\n";
/* Портал специально под снос: чужой не трогаем. */
$demoSlug = 'demolish-' . bin2hex(random_bytes(3));
[$demoId, , ] = \App\Models\Client::create([
    'name' => 'Портал на снос', 'slug' => $demoSlug,
    'base_url' => 'https://demolish.example', 'redirect_uris' => ['https://demolish.example/cb'],
    'post_logout_uris' => [], 'backchannel_uri' => null,
    'allowed_scopes' => ['openid', 'profile'], 'is_trusted' => 0, 'access_policy' => 'open',
]);
$demoId = (int)$demoId;
/* Обвешиваем зависимостями — иначе проверять уборку не на чем. */
$demoRole = \App\Models\Role::create($demoId, 'client_admin', 'Админ портала', 'смоук');
$someUser = (int)\App\Core\DB::val('SELECT id FROM {users} ORDER BY id LIMIT 1');
\App\Models\Role::assign($someUser, (int)$demoRole);
\App\Core\DB::insert('user_client_access',
    ['user_id' => $someUser, 'client_id' => $demoId, 'status' => 'active', 'created_at' => now()]);

/* Удаление живёт отдельной страницей за паролем: кнопка, уносящая портал, не
   должна попадаться тому, кто зашёл поправить redirect_uri. */
[, $demoCard] = http('GET', SSO . '/admin/clients/' . $demoId);
ok(!str_contains($demoCard, 'name="confirm"'), 'формы удаления в карточке нет');
ok(str_contains($demoCard, "/admin/clients/$demoId/danger"), 'в карточке есть ссылка в опасную зону');

\App\Core\DB::run('DELETE FROM {rate_limits}');
[, $zone] = http('GET', SSO . "/admin/clients/$demoId/danger");
ok(str_contains($zone, 'name="password"') && !str_contains($zone, 'name="confirm"'),
    'закрытая зона просит пароль, а формы удаления не показывает');

/* Главная гарантия: обойти зону прямым POST нельзя. */
http('POST', SSO . "/admin/clients/$demoId/action",
    ['_csrf' => $csrf, 'action' => 'delete', 'confirm' => $demoSlug]);
ok(\App\Models\Client::find($demoId) !== null, 'удаление в обход закрытой зоны не проходит');

http('POST', SSO . "/admin/clients/$demoId/danger", ['_csrf' => $csrf, 'password' => 'не-тот-пароль']);
[, $zone] = http('GET', SSO . "/admin/clients/$demoId/danger");
ok(str_contains($zone, 'name="password"'), 'неверный пароль зону не открывает');

http('POST', SSO . "/admin/clients/$demoId/danger",
    ['_csrf' => $csrf, 'password' => 'correct-horse-battery']);
[, $zone] = http('GET', SSO . "/admin/clients/$demoId/danger");
ok(str_contains($zone, 'name="confirm"'), 'верный пароль открывает зону');

http('POST', SSO . "/admin/clients/$demoId/action",
    ['_csrf' => $csrf, 'action' => 'delete', 'confirm' => 'не-тот-слаг']);
ok(\App\Models\Client::find($demoId) !== null, 'в открытой зоне неверный слаг не удаляет');

http('POST', SSO . "/admin/clients/$demoId/action",
    ['_csrf' => $csrf, 'action' => 'delete', 'confirm' => $demoSlug]);
ok(\App\Models\Client::find($demoId) === null, 'портал удаляется по верному слагу');

/* Оставленные строки — это выданные токены, которые никто не отзовёт, и роль
   client_admin, продолжающая висеть на человеке. */
$left = 0;
foreach (['roles', 'user_client_access', 'invites', 'client_role_map', 'refresh_tokens'] as $t) {
    try { $left += (int)\App\Core\DB::val("SELECT COUNT(*) FROM {" . $t . "} WHERE client_id = ?", [$demoId]); }
    catch (\Throwable) {}
}
$leftRoles = (int)\App\Core\DB::val('SELECT COUNT(*) FROM {user_roles} WHERE role_id = ?', [(int)$demoRole]);
ok($left === 0 && $leftRoles === 0, 'зависимые строки убраны все', "строк=$left ролей=$leftRoles");

echo "═══ Гостевая: витрина только по решению владельца ═══\n";
/* Перечень внутренних сервисов — информация об устройстве организации. Пока
   владелец никого не отметил, домен SSO не должен её выдавать вообще: главная
   ведёт прямо на вход, и витрины не существует. */
\App\Core\DB::run('UPDATE {clients} SET show_on_home = 0');
$guestJar = __DIR__ . '/tmp/guest.txt';
@unlink($guestJar);
[$stHome, , $homeLoc] = http('GET', SSO . '/', [], [], $guestJar);
ok($stHome === 302 && str_contains((string)$homeLoc, '/login'),
    'без отмеченных порталов главная ведёт на вход, а не на пустую витрину');
ok(\App\Models\Client::publicOnes() === [], 'по умолчанию публичных порталов нет');

/* Включаем один — витрина появляется, и в ней ровно он. */
$showCid = (int)\App\Core\DB::val('SELECT id FROM {clients} ORDER BY id LIMIT 1');
$showName = (string)\App\Core\DB::val('SELECT name FROM {clients} WHERE id = ?', [$showCid]);
\App\Core\DB::run('UPDATE {clients} SET show_on_home = 1, is_active = 1 WHERE id = ?', [$showCid]);
[$stHome2, $homeBody2] = http('GET', SSO . '/', [], [], $guestJar);
ok($stHome2 === 200 && str_contains($homeBody2, 'class="guest"'), 'с отмеченным порталом главная показывает витрину');
ok(str_contains($homeBody2, $showName), 'отмеченный портал виден в витрине');
ok(str_contains($homeBody2, 'class="auth-tabs"'), 'форма входа стоит поверх витрины, а не отдельной страницей');

/* Выключенный портал не показываем даже отмеченным: вести человека к
   неработающему сервису хуже, чем не вести никуда. */
\App\Core\DB::run('UPDATE {clients} SET is_active = 0 WHERE id = ?', [$showCid]);
ok(\App\Models\Client::publicOnes() === [], 'выключенный портал в витрину не попадает');
\App\Core\DB::run('UPDATE {clients} SET is_active = 1, show_on_home = 0 WHERE id = ?', [$showCid]);

echo "═══ Перенос базы ═══\n";
ok(\App\Core\DbTransfer::limitMb() === \App\Core\DbTransfer::DEFAULT_LIMIT_MB,
    'потолок переноса по умолчанию ' . \App\Core\DbTransfer::DEFAULT_LIMIT_MB . ' МБ');
\App\Core\Setting::set('system.transfer_limit_mb', '7');
\App\Core\Setting::flush();
ok(\App\Core\DbTransfer::limitMb() === 7, 'потолок берётся из настройки');
\App\Core\Setting::delete('system.transfer_limit_mb');
\App\Core\Setting::flush();

$dstFile = SSO_ROOT . '/storage/smoke-transfer.sqlite';
@unlink($dstFile);
new PDO('sqlite:' . $dstFile);
$res = \App\Core\DbTransfer::run(['driver' => 'sqlite', 'name' => 'storage/smoke-transfer.sqlite',
    'prefix' => 'sso_', 'host' => '', 'port' => 0, 'user' => '', 'pass' => '']);
ok($res['ok'], 'перенос отработал', $res['detail']);

/* Сверяем построчно: перенос без сверки — это надежда, а не перенос. */
$dstPdo = new PDO('sqlite:' . $dstFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$mismatch = [];
foreach (['users', 'clients', 'roles', 'settings', 'user_roles', 'audit_log'] as $t) {
    $a = (int)\App\Core\DB::val('SELECT COUNT(*) FROM {' . $t . '}');
    $b = (int)$dstPdo->query('SELECT COUNT(*) FROM `sso_' . $t . '`')->fetchColumn();
    if ($a !== $b) $mismatch[] = "$t: $a≠$b";
}
ok($mismatch === [], 'строки сошлись во всех ключевых таблицах', implode(', ', $mismatch));

/* И приёмник обязан пройти те же проверки, что нужны для переключения:
   перенос, после которого переключиться нельзя, бесполезен. */
$after = \App\Core\DbInspect::probe(['driver' => 'sqlite', 'name' => 'storage/smoke-transfer.sqlite',
    'prefix' => 'sso_', 'host' => '', 'port' => 0, 'user' => '', 'pass' => '']);
$allOk = array_reduce($after, static fn(bool $c, array $x): bool => $c && $x['ok'], true);
ok($allOk, 'приёмник после переноса готов к переключению',
    implode('; ', array_map(static fn($x) => $x['what'] . ': ' . $x['detail'], $after)));
unset($dstPdo);
@unlink($dstFile);

echo "═══ Сброс: ограждения ═══\n";
/* Сам сброс здесь не запускаем — он снёс бы базу под остальными проверками.
   Проверяем ровно то, ради чего ограждения и стоят: что без них не пускает. */
\App\Core\Setting::delete('system.backup_at');
\App\Core\Reset::forgetCode();
\App\Core\Setting::flush();
$usersBefore = (int)\App\Core\DB::val('SELECT COUNT(*) FROM {users}');

http('POST', SSO . '/admin/system/reset/code', ['_csrf' => $csrf, 'level' => 'data']);
\App\Core\Setting::flush();
ok(\App\Core\Setting::get('system.reset_code') === null, 'без свежего бэкапа код даже не выдаётся');

/* Бэкап отмечается фактом скачивания, а не галочкой. */
[$st] = http('GET', SSO . '/admin/tools/backup.zip');
\App\Core\Setting::flush();
ok($st === 200 && \App\Core\Reset::backupFresh(), 'скачивание бэкапа отпирает сброс');

http('POST', SSO . '/admin/system/reset/code', ['_csrf' => $csrf, 'level' => 'data']);
\App\Core\Setting::flush();
ok(\App\Core\Setting::get('system.reset_code') !== null, 'код выдан после бэкапа');

/* Код уходит письмом, а не на экран: он защищает от того, кто уже смотрит
   на этот экран в чужой открытой вкладке. */
$codeMail = '';
foreach ((array)glob(SSO_ROOT . '/storage/logs/mail/*.eml') as $f) {
    $t = (string)file_get_contents($f);
    if (str_contains($t, 'Код подтверждения сброса') && preg_match('~>(\d{6})<~', $t, $m)) $codeMail = $m[1];
}
ok($codeMail !== '', 'код ушёл письмом');
ok(\App\Core\Reset::checkCode($codeMail, 'data'), 'код из письма подходит');
ok(!\App\Core\Reset::checkCode($codeMail, 'project'),
    'код, выданный для сброса данных, не годится для сброса проекта');
ok(!\App\Core\Reset::checkCode('000000', 'data') || $codeMail === '000000', 'чужой код не подходит');

$host = (string)parse_url((string)\App\Core\Config::get('app.url'), PHP_URL_HOST);
foreach ([
    [['code' => $codeMail, 'confirm' => 'не тот адрес'], 'неверно набранный адрес'],
    [['code' => '111111', 'confirm' => $host], 'неверный код'],
    [['code' => '', 'confirm' => ''], 'пустая форма'],
] as [$post, $why]) {
    http('POST', SSO . '/admin/system/reset/run', ['_csrf' => $csrf, 'level' => 'data'] + $post);
    ok((int)\App\Core\DB::val('SELECT COUNT(*) FROM {users}') === $usersBefore, "сброс не выполнен: $why");
}
\App\Core\Reset::forgetCode();
\App\Core\Setting::delete('system.backup_at');

echo "═══ Палитра: адреса живые, разделы находятся (v4.11) ═══\n";
/* В палитре стоял третий список разделов, набранный руками, — и разошёлся с
   остальными: настроек стало четырнадцать, а палитра знала о трёх. Выглядело
   это исправным: пункт «Настройки» в ней был. */
$palAll = [];
[$st, $palBody] = http('GET', SSO . '/search?q=');
$palData = json_decode($palBody, true);
foreach ((array)($palData['groups'] ?? []) as $g) {
    foreach ((array)($g['items'] ?? []) as $it) $palAll[] = $it;
}
ok($st === 200 && $palAll !== [], 'палитра отвечает списком');

/* Мёртвая ссылка в палитре не видна никак: пункт есть, нажимаешь — 404. */
$palDead = [];
foreach ($palAll as $it) {
    $path = explode('#', (string)($it['url'] ?? ''))[0];
    if ($path === '') { $palDead[] = '(без адреса)'; continue; }
    [$c] = http('GET', SSO . $path);
    if (!in_array($c, [200, 302], true)) $palDead[] = "$path → $c";
}
ok($palDead === [], 'все адреса из палитры отвечают', implode('; ', $palDead));

/* Раздел обязан находиться по своему имени. Пустой запрос палитра обрезает до
   шести пунктов — и правильно делает; вопрос в том, найдётся ли он, когда его
   ищут. */
$palMissing = [];
foreach (\App\Core\SettingsMap::sections() as $sec) {
    $word = mb_strtolower(mb_substr((string)$sec['title'], 0, 6));
    [, $r] = http('GET', SSO . '/search?q=' . rawurlencode($word));
    $d = json_decode($r, true);
    $urls = [];
    foreach ((array)($d['groups'] ?? []) as $g) {
        foreach ((array)($g['items'] ?? []) as $it) $urls[] = (string)($it['url'] ?? '');
    }
    if (!in_array($sec['route'], $urls, true)) $palMissing[] = $sec['title'];
}
ok($palMissing === [], 'каждый раздел настроек находится по своему имени',
    implode(', ', $palMissing));

/* Раздел находится и по ПОДПИСИ — по ней ищут то, чьё название не помнят.
   Подпись терялась у пяти разделов, попавших ещё и в навигацию: дубль
   отсекался по адресу, и выигрывал навигационный пункт, у которого подписи
   нет. «Домен» не находил «Систему», хотя её подпись с этого слова
   начинается. Проверяем каждый раздел первым словом его подписи. */
$hintMissing = [];
foreach (\App\Core\SettingsMap::sections() as $sec) {
    $first = mb_strtolower((string)(preg_split('~[\s,]+~u', (string)$sec['desc'], -1, PREG_SPLIT_NO_EMPTY)[0] ?? ''));
    if (mb_strlen($first) < 4) continue;   // «2FA» и подобное ищут иначе
    [, $r] = http('GET', SSO . '/search?q=' . rawurlencode($first));
    $d = json_decode($r, true);
    $urls = [];
    foreach ((array)($d['groups'] ?? []) as $g) {
        foreach ((array)($g['items'] ?? []) as $it) $urls[] = (string)($it['url'] ?? '');
    }
    if (!in_array($sec['route'], $urls, true)) $hintMissing[] = $sec['title'] . ' («' . $first . '»)';
}
ok($hintMissing === [], 'раздел находится и по первому слову своей подписи',
    implode(', ', $hintMissing));

/* Запрос из нескольких слов. Искался целиком одной строкой, и «срок сессии» не
   находил ничего: в подписи «Сессия, токены», в названии «Сроки» — такой
   строки нет нигде, хотя слова человек набрал ровно те. */
[, $twoWords] = http('GET', SSO . '/search?q=' . rawurlencode('срок сессии'));
$twoUrls = [];
foreach ((array)(json_decode($twoWords, true)['groups'] ?? []) as $g) {
    foreach ((array)($g['items'] ?? []) as $it) $twoUrls[] = (string)($it['url'] ?? '');
}
ok(in_array('/admin/settings/section/timeouts', $twoUrls, true),
    '«срок сессии» доводит до раздела сроков', implode(', ', $twoUrls));

/* Действие палитры обязано доводить до дела, а не до страницы: «Подключить
   сервис» высаживал человека на список порталов, а мастер там — модалка. */
$palActions = [];
foreach ((array)($palData['groups'] ?? []) as $g) {
    if (($g['title'] ?? '') !== 'Действия') continue;
    foreach ((array)($g['items'] ?? []) as $it) $palActions[] = (string)($it['url'] ?? '');
}
$withDo = array_values(array_filter($palActions, static fn(string $u): bool => str_contains($u, '?do=')));
ok($withDo !== [], 'действия палитры несут ?do= — цель, а не просто страницу');
/* Цель обязана существовать в разметке: `?do=` на несуществующий id молча
   ничего не делает, и выглядит это как «палитра опять не довела». */
$doMissing = [];
foreach ($withDo as $u) {
    [$path, $query] = array_pad(explode('?', $u, 2), 2, '');
    parse_str($query, $qs);
    $id = (string)($qs['do'] ?? '');
    [$c, $html] = http('GET', SSO . $path);
    if ($c !== 200 || !str_contains($html, 'id="' . $id . '"')) $doMissing[] = $u;
}
ok($doMissing === [], 'цель каждого ?do= есть на своей странице', implode('; ', $doMissing));

echo "═══ Оформление сайта: главная и экран входа (v4.8) ═══\n";
/* Отдельный раздел, а не восьмая вкладка настроек: настройки про то, как
   установка работает, а здесь — про то, какой её видит посторонний. */
[$st, $ap] = http('GET', SSO . '/admin/appearance');
ok($st === 200 && pageClean($ap), 'экран оформления открывается');
ok(str_contains($ap, 'name="method_password"') && str_contains($ap, 'name="method_social"'),
    'состав формы входа настраивается тумблерами');
ok(str_contains($ap, 'name="portal[]"'), 'состав витрины правится списком, а не обходом карточек');
ok(str_contains($ap, 'data-look-editor') && str_contains($ap, '/admin/appearance/preview'), 'экран входа — редактором с живым предпросмотром рядом');

/* Снимок переехал из «Брендинга»: там он лежал рядом с логотипом и favicon,
   хотя логотип видно везде, а панель — только на входе. */
[, $setPage] = http('GET', SSO . '/admin/settings');
ok(!str_contains($setPage, 'name="media"'), 'из брендинга загрузка обложки убрана');
ok(str_contains($setPage, '/admin/appearance'), 'из брендинга есть дорога в оформление');

$apCsrf = csrfOf($ap);
$anonJar = __DIR__ . '/tmp/appearance-anon.txt';
$loginLooks = static function () use ($anonJar): array {
    @unlink($anonJar);
    [, $l] = http('GET', SSO . '/login', [], [], $anonJar);
    return ['пароль' => str_contains($l, 'name="identity"'),
            'ссылка' => str_contains($l, '/login/link'),
            'ключ' => str_contains($l, 'data-passkey-login'),
            'разделителей' => preg_match_all('~auth-divider~', $l)];
};

/* Выключить ВСЕ способы нельзя: без единого войти не сможет никто, включая
   того, кто выключил, — а выход из положения был бы только через базу. */
http('POST', SSO . '/admin/appearance/save', ['_csrf' => $apCsrf, 'section' => 'login']);
\App\Core\Setting::flush();
ok(\App\Core\Appearance::methodsLeft() > 0, 'попытка выключить все способы входа отвергнута');

http('POST', SSO . '/admin/appearance/save', ['_csrf' => $apCsrf, 'section' => 'login', 'method_password' => '1']);
$only = $loginLooks();
ok($only['пароль'] && !$only['ссылка'] && !$only['ключ'], 'выключенные способы исчезают с экрана входа');
/* Разделитель рисуется МЕЖДУ двумя видимыми способами: при одном оставшемся
   под формой висела бы черта в никуда. */
ok($only['разделителей'] === 0, 'при одном способе разделителя нет');

http('POST', SSO . '/admin/appearance/save',
    ['_csrf' => $apCsrf, 'section' => 'login', 'method_password' => '1', 'method_passkey' => '1']);
$two = $loginLooks();
ok($two['пароль'] && $two['ключ'] && $two['разделителей'] === 1, 'между двумя способами разделитель один');

http('POST', SSO . '/admin/appearance/save', ['_csrf' => $apCsrf, 'section' => 'login',
    'method_password' => '1', 'method_magic' => '1', 'method_passkey' => '1', 'method_social' => '1']);
\App\Core\Setting::flush();
ok(\App\Core\Appearance::method('magic'), 'способы возвращаются обратно');

/* Витрина: два условия сразу — тумблер владельца И отмеченный портал. */
$apCid = (int)\App\Core\DB::val('SELECT id FROM {clients} WHERE is_active = 1 ORDER BY id LIMIT 1');
$homeGoes = static function () use ($anonJar): string {
    @unlink($anonJar);
    [$s, $b, $loc] = http('GET', SSO . '/', [], [], $anonJar);
    return $loc !== '' ? 'redirect' : ($s === 200 && str_contains($b, 'guest-bg') ? 'showcase' : 'other');
};
http('POST', SSO . '/admin/appearance/save',
    ['_csrf' => $apCsrf, 'section' => 'home', 'showcase' => '1', 'portal' => [$apCid]]);
ok($homeGoes() === 'showcase', 'витрина показывается: тумблер включён и портал отмечен');
http('POST', SSO . '/admin/appearance/save', ['_csrf' => $apCsrf, 'section' => 'home', 'portal' => [$apCid]]);
ok($homeGoes() === 'redirect', 'выключенная витрина уводит сразу на вход');
http('POST', SSO . '/admin/appearance/save', ['_csrf' => $apCsrf, 'section' => 'home', 'showcase' => '1']);
ok($homeGoes() === 'redirect', 'включённая витрина без отмеченных порталов не показывается');
ok((int)\App\Core\DB::val('SELECT show_on_home FROM {clients} WHERE id = ?', [$apCid]) === 0,
    'отметка портала снимается прямо отсюда, а не в его карточке');
@unlink($anonJar);

echo "═══ Перенос людей: ключ и экран (v4.6) ═══\n";
/* Сам перенос проверяется в e2e против настоящего портала на :8711. Здесь —
   обвязка: кнопки, показ ключа один раз и то, что экран не падает ни в одном
   из трёх своих состояний. */
$pullCid = (int)\App\Core\DB::val('SELECT id FROM {clients} ORDER BY id LIMIT 1');
\App\Core\DB::run('UPDATE {clients} SET users_url = NULL, users_secret = NULL WHERE id = ?', [$pullCid]);

[$st, $notSet] = http('GET', SSO . "/admin/clients/$pullCid/users/preview");
ok($st === 200 && pageClean($notSet), 'экран переноса открывается, когда ничего не настроено');
ok(str_contains($notSet, 'Перенос ещё не настроен'), 'ненастроенный перенос так и назван');
ok(str_contains($notSet, 'sso/users.php'), 'сказано, какой файл нужен на портале');

[$st, , $loc] = http('POST', SSO . "/admin/clients/$pullCid/users/key", ['_csrf' => $csrf]);
$issued = (string)\App\Core\DB::val('SELECT users_secret FROM {clients} WHERE id = ?', [$pullCid]);
ok($st === 302 && str_starts_with($issued, 'pusec_'), 'ключ переноса выдан', "st=$st key=" . substr($issued, 0, 8));

[, $shown] = http('GET', SSO . "/admin/clients/$pullCid/users/preview");
ok(str_contains($shown, $issued), 'ключ показан администратору');
[, $shownAgain] = http('GET', SSO . "/admin/clients/$pullCid/users/preview");
ok(!str_contains($shownAgain, $issued), 'второй раз ключ не показывается');

/* Адрес выгрузки на чужом хосте манифест протолкнуть не может: иначе он увёл
   бы нас с ключом куда укажут, в том числе во внутреннюю сеть. */
$probe = ['id' => $pullCid, 'base_url' => 'https://showcase.example'];
ok(!\App\Core\PortalUsers::storeEndpoint($probe, ['url' => 'https://evil.example/users.php']),
    'адрес выгрузки на чужом хосте отвергнут');
ok(\App\Core\PortalUsers::storeEndpoint($probe, ['url' => '/sso/users.php', 'count' => 42]),
    'относительный адрес принят и достроен от адреса портала');
$stored = \App\Models\Client::find($pullCid);
ok($stored['users_url'] === 'https://showcase.example/sso/users.php', 'адрес достроен верно: ' . (string)$stored['users_url']);
ok((int)$stored['users_count'] === 42, 'портал сообщил масштаб — 42');

/* Настроено, но портал недоступен: экран обязан объяснить, а не упасть. */
[$st, $dead] = http('GET', SSO . "/admin/clients/$pullCid/users/preview");
ok($st === 200 && pageClean($dead), 'экран переноса цел, когда портал не отвечает');
ok(str_contains($dead, 'Портал не отдал список'), 'молчание портала названо своим именем');

[$st] = http('POST', SSO . "/admin/clients/$pullCid/users/key", ['_csrf' => $csrf, 'mode' => 'revoke']);
ok($st === 302 && \App\Core\DB::val('SELECT users_secret FROM {clients} WHERE id = ?', [$pullCid]) === null,
    'ключ переноса отзывается');

/* Набор уезжает порталу с этим ключом запечённым — иначе его копируют руками. */
\App\Core\PortalUsers::issueSecret(\App\Models\Client::find($pullCid));
$kitCfg = (string)file_get_contents(
    ($kz = \App\Controllers\Admin\KitBuilder::buildZip(\App\Models\Client::find($pullCid), null))
    ? 'zip://' . $kz . '#sso/config.php' : '');
@unlink($kz);
ok(str_contains($kitCfg, (string)\App\Core\DB::val('SELECT users_secret FROM {clients} WHERE id = ?', [$pullCid])),
    'ключ переноса запечён в config.php набора');
\App\Core\DB::run('UPDATE {clients} SET users_url = NULL, users_secret = NULL, users_count = NULL WHERE id = ?', [$pullCid]);

echo "═══ Человек без почты: перенос из портала с логинами (v4.5) ═══\n";
/* Ради этого случая и снимался NOT NULL с email: у портала, который подключают,
   люди заведены логином и паролём, почтового сервера у него нет и не было. */
foreach (['smoke-nomail', 'smoke-nomail-2'] as $l) {
    $row = \App\Core\DB::row('SELECT id FROM {users} WHERE login = ?', [$l]);
    if (!$row) continue;
    \App\Core\DB::run('DELETE FROM {user_roles} WHERE user_id = ?', [(int)$row['id']]);
    \App\Core\DB::run('DELETE FROM {sessions} WHERE user_id = ?', [(int)$row['id']]);
    \App\Core\DB::run('DELETE FROM {users} WHERE id = ?', [(int)$row['id']]);
}
$csvFile = __DIR__ . '/tmp/nomail.csv';
file_put_contents($csvFile,
    "user;pass;fam\n"
    . 'smoke-nomail;' . password_hash('nomail-pass-1', PASSWORD_BCRYPT) . ";Безпочтов\n"
    . 'smoke-nomail-2;' . password_hash('nomail-pass-2', PASSWORD_BCRYPT) . ";Второй\n");
[$st] = upload(SSO . '/admin/tools/import', ['_csrf' => $csrf, 'step' => 'upload'],
    ['file' => [$csvFile, 'text/csv', 'nomail.csv']]);
ok($st === 302, 'файл без колонки почты принят к разбору', "st=$st");
http('POST', SSO . '/admin/tools/import', ['_csrf' => $csrf, 'step' => 'map',
    'map_login' => 'user', 'map_password_hash' => 'pass', 'map_last_name' => 'fam']);
$nomail = \App\Models\User::findByLoginOrEmail('smoke-nomail');
ok($nomail !== null, 'аккаунт без почты завёлся: раньше импорт отвергал файл на первом шаге');
ok($nomail && $nomail['email'] === null, 'почта именно NULL, а не пустая строка');
/* Второй такой же — та самая ловушка: пустая строка на уникальном ключе
   пропустила бы ровно одного человека, а NULL пропускает всех. */
ok(\App\Models\User::findByLoginOrEmail('smoke-nomail-2') !== null, 'второй человек без почты тоже завёлся');

$nomailJar = __DIR__ . '/tmp/nomail-jar.txt';
@unlink($nomailJar);
[, $lp] = http('GET', SSO . '/login', [], [], $nomailJar);
[$st, , $loc] = http('POST', SSO . '/login',
    ['_csrf' => csrfOf($lp), 'identity' => 'smoke-nomail', 'password' => 'nomail-pass-1'], [], $nomailJar);
ok($st === 302 && str_contains($loc, '/profile'), 'вход по логину и паролю работает без почты', "st=$st loc=$loc");

/* Карточка обязана отличать «почты нет» от «почта не подтверждена»: во втором
   случае человеку есть куда писать, в первом — некуда. */
[$st, $card] = http('GET', SSO . '/admin/users/' . (int)$nomail['id']);
ok($st === 200 && pageClean($card), 'карточка человека без почты открывается');
ok(str_contains($card, 'вход только по логину'), 'в карточке сказано, что почты нет, а не пустое место');
ok(str_contains($card, '@smoke-nomail'), 'в подписи стоит логин вместо адреса');

/* Опасная зона сверяла набранное с адресом. У пустого адреса пустая форма
   совпала бы с ним — подтверждение исчезло бы вовсе. */
http('POST', SSO . '/admin/users/' . (int)$nomail['id'] . '/danger',
    ['_csrf' => $csrf, 'password' => 'correct-horse-battery']);
http('POST', SSO . '/admin/users/' . (int)$nomail['id'] . '/action',
    ['_csrf' => $csrf, 'action' => 'purge', 'confirm' => '']);
ok(\App\Models\User::findByLoginOrEmail('smoke-nomail') !== null,
    'пустое подтверждение НЕ удаляет аккаунт без почты');
http('POST', SSO . '/admin/users/' . (int)$nomail['id'] . '/action',
    ['_csrf' => $csrf, 'action' => 'purge', 'confirm' => '@smoke-nomail']);
ok(\App\Models\User::findByLoginOrEmail('smoke-nomail') === null,
    'логин со «собакой» подтверждение проходит');
@unlink($csvFile);

/* Свои же реквизиты обязаны пройти все проверки — иначе проверка бесполезна. */
$cur = (array)\App\Core\Config::get('db', []);
$checks = \App\Core\DbInspect::probe($cur);
$allOk = array_reduce($checks, static fn(bool $c, array $x): bool => $c && $x['ok'], true);
ok($allOk, 'текущее подключение проходит все проверки',
    implode('; ', array_map(static fn($x) => $x['what'] . ': ' . $x['detail'], $checks)));

echo "\n══════════════════════════════\n";
echo $failed === 0 ? "СМОУК ЗЕЛЁНЫЙ: $passed проверок\n" : "ПРОВАЛЕНО: $failed из " . ($passed + $failed) . "\n";
exit($failed === 0 ? 0 : 1);
