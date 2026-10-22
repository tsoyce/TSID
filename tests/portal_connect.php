<?php
/**
 * Подключение сервиса: всё, что сломалось, когда подключали Архива.
 *
 * Четыре дефекта одного вечера, и ни один не был виден из батареи:
 *
 * 1. Адрес возврата угадывался как «…/sso/callback.php», а сервис слал
 *    «…/sso/callback». Вход кончался страницей «адрес не совпадает» без
 *    подсказки, какой адрес пришёл.
 * 2. Манифест не читался, а карточка говорила «не отвечает» — без причины.
 *    Причин было две: nginx отдавал /.well-known с диска, .htaccess резал
 *    пути с точкой. После неудачи SSO ждал сутки.
 * 3. Кнопка входа молчала на сайте с hx-boost: переход уходил фоновым
 *    запросом и упирался в CORS.
 * 4. Наша же инструкция советовала nginx-правило, которое и роняет манифест.
 *
 * Повторяемая: заводит своих и за собой убирает.
 *
 * Запуск (сервер поднят): php tests/portal_connect.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Controllers\Admin\KitBuilder;
use App\Core\DB;
use App\Core\PortalInstall;
use App\Core\PortalManifest;
use App\Core\RedirectMiss;
use App\Core\SelfWatch;
use App\Core\Setting;
use App\Models\ApiToken;
use App\Models\Client;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$root = dirname(__DIR__);
$JAR = sys_get_temp_dir() . '/portal-connect-admin.txt';
$GUEST = sys_get_temp_dir() . '/portal-connect-guest.txt';
@unlink($JAR);
@unlink($GUEST);

$stamp = (string)time();
$host = 'connect-' . $stamp . '.example';
$base = 'https://' . $host;
$made = [];

/** Завести портал так, как это делает подключение без догадок. */
function portal(string $slug, string $base, array $redirects): array
{
    DB::run('DELETE FROM {clients} WHERE slug = ?', [$slug]);
    [$id, $clientId] = Client::create([
        'name' => 'Проба подключения', 'slug' => $slug, 'base_url' => $base,
        'redirect_uris' => $redirects, 'post_logout_uris' => [$base . '/'],
        'backchannel_uri' => null, 'is_trusted' => 1, 'access_policy' => 'open',
    ]);
    return Client::find($id) + ['client_id' => $clientId];
}

/** Адрес /authorize так, как его собирает сервис. */
function authorizeUrl(string $clientId, string $redirect): string
{
    $v = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    return SSO . '/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect,
        'scope' => 'openid profile', 'state' => 'st', 'nonce' => 'no',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ]);
}

/**
 * Портал из базы — заново, мимо кэша настроек.
 *
 * Адреса и причины пишет HTTP-сервер, а тест читает их в своём процессе, где
 * Setting держит кэш. Без сброса проверка «чужой адрес не запомнен» была
 * зелёной потому, что тест смотрел на свой старый кэш, а не потому, что адрес
 * не записан. Выдало себя соседнее: повтор «не считался», хотя сервер его
 * посчитал.
 */
function fresh(int $id): array
{
    Setting::flush();
    return Client::find($id) ?? [];
}

DB::run('UPDATE {users} SET totp_secret = NULL, totp_enabled_at = NULL WHERE email = ?', ['admin@test.local']);
DB::run('DELETE FROM {rate_limits}');
$adminId = (int)DB::val('SELECT id FROM {users} WHERE email = ?', ['admin@test.local']);
[, $lp] = http('GET', SSO . '/login', [], [], $JAR);
http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => 'admin@test.local',
    'password' => 'correct-horse-battery'], [], $JAR);
[, $adminPage] = http('GET', SSO . '/admin/clients', [], [], $JAR);
$csrf = csrfOf($adminPage);

echo "═══ Подключение сервиса ═══\n";

try {
    echo "\n── Адрес возврата не угадывается ──\n";
    [$st, , $loc] = http('POST', SSO . '/admin/clients/create',
        /* Свой адрес, а не $base: иначе на одном origin окажутся два портала, и
           проверки выхода ниже законно откажутся угадывать, какой из них. */
        ['_csrf' => $csrf, 'name' => 'Проба быстрого подключения', 'base_url' => 'https://quick-' . $host], [], $JAR);
    preg_match('~/admin/clients/(\d+)~', (string)$loc, $m);
    $quickId = (int)($m[1] ?? 0);
    if ($quickId > 0) $made[] = $quickId;
    $quick = $quickId > 0 ? Client::find($quickId) : null;
    ok($quick !== null, 'портал подключён из админки', "код $st, loc=$loc");
    ok($quick !== null && $quick['redirect_uris'] === [],
       'адрес возврата не подставлен догадкой «…/sso/callback.php»',
       $quick !== null ? json_encode($quick['redirect_uris'], JSON_UNESCAPED_SLASHES) : '—');

    [$tid, $token] = ApiToken::create('проба подключения ' . $stamp, [ApiToken::SCOPE_ALL], null, 1);
    $ch = curl_init(SSO . '/api/v1/clients');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['name' => 'Проба API', 'base_url' => 'https://api-' . $host])]);
    $apiMade = json_decode((string)curl_exec($ch), true) ?: [];
    curl_close($ch);
    ApiToken::revoke($tid);
    $apiClient = !empty($apiMade['client_id']) ? Client::findByClientId((string)$apiMade['client_id']) : null;
    if ($apiClient) $made[] = (int)$apiClient['id'];
    ok($apiClient !== null && $apiClient['redirect_uris'] === [],
       'и через API — тоже без догадки',
       $apiClient !== null ? json_encode($apiClient['redirect_uris'], JSON_UNESCAPED_SLASHES) : json_encode($apiMade));

    echo "\n── Первый вход с неизвестным адресом ──\n";
    $p = portal('connect-probe', $base, []);
    $made[] = (int)$p['id'];
    $cb = $base . '/sso/callback';

    [$st, $body] = http('GET', authorizeUrl($p['client_id'], $cb), [], [], $GUEST);
    ok($st === 400, 'такой вход не пропущен — сравнение байт-в-байт осталось законом', "код $st");
    ok(str_contains($body, esc($cb)), 'страница называет адрес, который прислал сервис');
    ok(str_contains($body, 'ждёт в карточке'), 'и говорит администратору, где его добавить');

    $pending = RedirectMiss::pending(fresh((int)$p['id']));
    ok(count($pending) === 1 && $pending[0]['uri'] === $cb, 'адрес запомнен', json_encode($pending, JSON_UNESCAPED_SLASHES));
    http('GET', authorizeUrl($p['client_id'], $cb), [], [], $GUEST);
    $pending = RedirectMiss::pending(fresh((int)$p['id']));
    ok(($pending[0]['n'] ?? 0) === 2, 'повтор не плодит строк, а считает попытки');

    /* Чужой адрес прислать может кто угодно. Запоминай мы его — карточку
       заваливали бы мусором, и однажды «добавить» нажали бы не глядя. */
    [$st, $body] = http('GET', authorizeUrl($p['client_id'], 'https://evil.example/cb'), [], [], $GUEST);
    ok($st === 400 && !str_contains($body, 'evil.example'),
       'чужой адрес не показан на странице SSO');
    http('GET', authorizeUrl($p['client_id'], 'http://' . $host . '/sso/callback'), [], [], $GUEST);
    $uris = array_column(RedirectMiss::pending(fresh((int)$p['id'])), 'uri');
    ok(!in_array('https://evil.example/cb', $uris, true) && count($uris) === 1,
       'и не запомнен — ни чужой хост, ни тот же хост по http', implode(', ', $uris));

    /* С 4.91 карточка открывается обзором: там — предупреждение и дорога в
       реквизиты, а сам адрес с кнопками «Добавить» и «Забыть» — в реквизитах. */
    [, $card] = http('GET', SSO . '/admin/clients/' . (int)$p['id'], [], [], $JAR);
    ok(str_contains($card, 'Сервис просит вернуть на адрес, которого здесь нет'), 'обзор предупреждает о присланном адресе');
    [, $card] = http('GET', SSO . '/admin/clients/' . (int)$p['id'] . '/req', [], [], $JAR);
    ok(str_contains($card, esc($cb)) && str_contains($card, 'redirect-miss'),
       'реквизиты показывают присланный адрес с кнопкой');

    http('POST', SSO . '/admin/clients/' . (int)$p['id'] . '/redirect-miss',
        ['_csrf' => $csrf, 'mode' => 'accept', 'uri' => 'https://' . $host . '/other'], [], $JAR);
    ok(fresh((int)$p['id'])['redirect_uris'] === [],
       'адрес, которого сервис не присылал, кнопкой не добавить');

    http('POST', SSO . '/admin/clients/' . (int)$p['id'] . '/redirect-miss',
        ['_csrf' => $csrf, 'mode' => 'accept', 'uri' => $cb], [], $JAR);
    $after = fresh((int)$p['id']);
    ok($after['redirect_uris'] === [$cb], 'присланный адрес добавлен одной кнопкой',
       json_encode($after['redirect_uris'], JSON_UNESCAPED_SLASHES));
    ok(RedirectMiss::pending($after) === [], 'и больше не висит в ожидающих');

    [$st, , $loc] = http('GET', authorizeUrl($p['client_id'], $cb), [], [], $GUEST);
    ok($st === 302, 'следующий вход проходит дальше', "код $st, loc=" . substr((string)$loc, 0, 70));

    echo "\n── Возврат на портал формой: CSP ──\n";
    /* Живой дефект: «Продолжить» на экране согласия крутило спиннер вечно.
       CSP стояла form-action 'self', а форма согласия уводит 302 на портал —
       Chrome сверяет form-action с каждым шагом редиректа и молча обрывает.
       curl CSP не исполняет, поэтому ни одна проверка этого не видела.
       Проверяем сам заголовок на странице с формой. */
    $formAction = static fn(string $h): string
        => preg_match('~content-security-policy:[^\r\n]*?form-action ([^;\r\n]*)~i', $h, $fa) ? trim($fa[1]) : '';
    $flowJar = sys_get_temp_dir() . '/portal-connect-flow.txt';
    @unlink($flowJar);
    [, , $toLogin] = http('GET', authorizeUrl($p['client_id'], $cb), [], [], $flowJar);
    [, , , , $h] = http('GET', str_starts_with((string)$toLogin, 'http') ? (string)$toLogin : SSO . $toLogin, [], [], $flowJar);
    ok(str_contains($formAction($h), $base),
       'посреди потока входа форма пускает на портал, куда человек возвращается', $formAction($h));

    $plainJar = sys_get_temp_dir() . '/portal-connect-plain.txt';
    @unlink($plainJar);
    [, , , , $h] = http('GET', SSO . '/login', [], [], $plainJar);
    ok($formAction($h) === "'self'", 'вне потока — только свой origin, как и было', $formAction($h));

    [, , , , $h] = http('GET', SSO . '/login?rt=' . str_repeat('A', 32), [], [], $plainJar);
    ok($formAction($h) === "'self'", 'выдуманный ключ возврата ничего не добавляет', $formAction($h));
    @unlink($flowJar);
    @unlink($plainJar);

    echo "\n── Двойное нажатие «Войти» ──\n";
    /* Живой случай 10.09: два входа подряд, у второго нет выдачи кода — человек
       в личном кабинете SSO вместо портала. Первое нажатие гасило ключ
       возврата, второе приходило ни с чем. */
    $loginMail = 'double-' . $stamp . '@test.local';
    $loginUid = App\Models\User::create(['email' => $loginMail, 'first_name' => 'Проба',
        'password_hash' => password_hash('correct-horse-battery', PASSWORD_ARGON2ID),
        'status' => 'active', 'email_verified_at' => now()]);
    $userRole = App\Models\Role::findBySlug('user');
    if ($userRole) App\Models\Role::assign($loginUid, (int)$userRole['id']);
    $dblJar = sys_get_temp_dir() . '/portal-connect-double.txt';
    @unlink($dblJar);
    $abs = static fn(string $u): string => str_starts_with($u, 'http') ? $u : SSO . $u;
    [, , $toLogin] = http('GET', authorizeUrl($p['client_id'], $cb), [], [], $dblJar);
    $loginUrl = $abs((string)$toLogin);
    [, $lpage] = http('GET', $loginUrl, [], [], $dblJar);
    preg_match('~name="rt" value="([^"]+)"~', $lpage, $rtm);
    $loginForm = ['_csrf' => csrfOf($lpage), 'identity' => $loginMail, 'password' => 'correct-horse-battery',
        'rt' => html_entity_decode($rtm[1] ?? '')];
    [$s1, , $l1] = http('POST', SSO . '/login', $loginForm, [], $dblJar);
    [$s2, , $l2] = http('POST', SSO . '/login', $loginForm, [], $dblJar);
    ok(str_contains((string)$l1, '/authorize'), 'первое нажатие — в поток', "$s1 $l1");
    ok(str_contains((string)$l2, '/authorize'), 'второе нажатие — тоже в поток, а не в личный кабинет', "$s2 $l2");
    [$s3, , $l3] = http('GET', $loginUrl, [], [], $dblJar);
    ok(!str_contains((string)$l3, '/profile'),
       'вошедший вернулся на вход с ключом возврата — не выброшен в кабинет', "$s3 $l3");

    /* Одноразовости у ключа больше нет — держит его срок. Проверяем без сессии,
       одним ключом: сначала ещё возвращает, после срока — нет. Контроль нужен,
       чтобы «нет» означало срок, а не то, что без сессии возврата не бывает. */
    $keyOnly = static function () use ($loginForm): string {
        $jar = sys_get_temp_dir() . '/portal-connect-keyonly.txt';
        @unlink($jar);
        [, $page] = http('GET', SSO . '/login?rt=' . urlencode($loginForm['rt']), [], [], $jar);
        [, , $to] = http('POST', SSO . '/login', ['_csrf' => csrfOf($page)] + $loginForm, [], $jar);
        @unlink($jar);
        return (string)$to;
    };
    $keyAlive = $keyOnly();
    ok(str_contains($keyAlive, '/authorize'), 'без сессии, одним ключом — на портал (контроль)', $keyAlive);
    DB::run('UPDATE {one_time_tokens} SET expires_at = ? WHERE token_hash = ?',
        [now_plus(-60), App\Core\Crypto::hashToken($loginForm['rt'])]);
    $keyLate = $keyOnly();
    ok(!str_contains($keyLate, '/authorize'), 'но срок у ключа есть: истёк — возврата по нему нет', $keyLate);

    echo "\n── Экран согласия ──\n";
    [, , $toConsent] = http('GET', $abs((string)$l2), [], [], $dblJar);
    [, $consentPage] = http('GET', $abs((string)$toConsent), [], [], $dblJar);
    ok(str_contains($consentPage, 'работает через'),
       'экран говорит, что портал работает через SSO, а не что он «сервис SSO»');
    ok(!str_contains($consentPage, 'Пароль сервису не передаётся') && !str_contains($consentPage, 'Больше ничего'),
       'у доверенного портала нет строки про «перечисленные данные» — перечня на экране нет');
    $docLinks = preg_match('~class="consent-docs">(.*?)</span>~s', $consentPage, $dm)
        ? preg_match_all('~<a [^>]*href="/legal/~', $dm[1]) : 0;
    ok($docLinks === count(App\Core\Legal::requiringConsent()) && !str_contains($dm[1] ?? '', '</a>, <a'),
       'документы — каждый своей строкой, а не одной фразой через запятую', "ссылок в блоке: $docLinks");
    Client::update((int)$p['id'], ['is_trusted' => 0]);
    [, , $toConsent2] = http('GET', authorizeUrl($p['client_id'], $cb), [], [], $dblJar);
    [, $consentPage2] = http('GET', $abs((string)$toConsent2), [], [], $dblJar);
    Client::update((int)$p['id'], ['is_trusted' => 1]);
    ok(str_contains($consentPage2, 'Сервис получит') && str_contains($consentPage2, 'Больше ничего'),
       'у недоверенного — перечень данных и строка о пароле прямо под ним');

    echo "\n── Выход с портала возвращает на портал ──\n";
    /* Живой дефект: после «Выйти» на портале — вход SSO. Портал узнавали только
       по id_token_hint, а Архив подсказку не доносил и слал адрес без
       завершающего слэша. */
    $anonJar = sys_get_temp_dir() . '/portal-connect-anon.txt';
    @unlink($anonJar);
    $leave = static fn(array $q): string => (string)(http('GET', SSO . '/logout?' . http_build_query($q), [], [], $anonJar)[2]);
    ok($leave(['post_logout_redirect_uri' => $base . '/', 'client_id' => $p['client_id']]) === $base . '/',
       'client_id и зарегистрированный адрес — обратно на портал');
    ok($leave(['post_logout_redirect_uri' => $base . '/']) === $base . '/',
       'без подсказки и client_id — портал узнан по своему адресу, обратно на портал');
    ok($leave(['post_logout_redirect_uri' => $base]) === $base,
       'адрес без слэша, как у Архива, — на сам портал, а не на вход SSO');
    ok(in_array($base, array_column(RedirectMiss::pending(fresh((int)$p['id']), 'logout'), 'uri'), true),
       'и присланный адрес ждёт в карточке — добавить одной кнопкой');
    $evil = $leave(['post_logout_redirect_uri' => 'https://evil.example/']);
    ok(!str_contains($evil, 'evil.example'), 'чужой адрес не исполняется — никакого открытого редиректа', $evil);
    /* Два портала на одном origin — угадывать нельзя: вернули бы не туда. */
    [$twinId] = Client::create(['name' => 'Двойник', 'slug' => 'connect-twin-' . $stamp, 'base_url' => $base,
        'redirect_uris' => [], 'post_logout_uris' => [], 'backchannel_uri' => null,
        'is_trusted' => 1, 'access_policy' => 'open']);
    $made[] = (int)$twinId;
    $twinOut = $leave(['post_logout_redirect_uri' => $base]);
    ok(!str_starts_with($twinOut, $base), 'два портала на одном origin — не угадываем, какой из них', $twinOut);
    Client::purge((int)$twinId);

    [, $logoutPage] = http('GET', SSO . '/logout?' . http_build_query(['client_id' => $p['client_id'],
        'post_logout_redirect_uri' => $base . '/']), [], [], $dblJar);
    ok(str_contains($logoutPage, 'Проба подключения. Выйти'), 'на экране выхода фразы разделены пробелом');
    ok(str_contains($logoutPage, 'href="' . esc($base . '/') . '"'), '«Отмена» ведёт в портал, а не в профиль SSO');
    [, , $afterOut] = http('POST', SSO . '/logout', ['_csrf' => csrfOf($logoutPage), 'mode' => 'here',
        'post_logout' => $base . '/', 'client_id' => $p['client_id']], [], $dblJar);
    ok((string)$afterOut === $base . '/', '«Выйти» с портала — обратно на портал', (string)$afterOut);
    @unlink($dblJar);
    @unlink($anonJar);

    echo "\n── Установка нашего набора ──\n";
    $k = portal('connect-kit', 'https://kit-' . $host, []);
    $made[] = (int)$k['id'];
    parse_str((string)parse_url(PortalInstall::issue($k, $adminId), PHP_URL_QUERY), $q);
    $r = PortalInstall::redeem((string)($q['ticket'] ?? ''));
    $kitCb = 'https://kit-' . $host . '/sso/callback.php';
    ok($r['ok'] && ($r['data']['redirect_uri'] ?? '') === $kitCb, 'набор получил свой адрес возврата',
       (string)($r['error'] ?? ''));
    ok(Client::find((int)$k['id'])['redirect_uris'] === [$kitCb],
       'и он записан — у набора callback.php не догадка, а факт');

    $own = portal('connect-own', 'https://own-' . $host, ['https://own-' . $host . '/auth/back']);
    $made[] = (int)$own['id'];
    parse_str((string)parse_url(PortalInstall::issue($own, $adminId), PHP_URL_QUERY), $q);
    PortalInstall::redeem((string)($q['ticket'] ?? ''));
    ok(Client::find((int)$own['id'])['redirect_uris'] === ['https://own-' . $host . '/auth/back'],
       'а уже записанный адрес установка не трогает');

    echo "\n── Почему манифеста нет — словами ──\n";
    $nginx404 = "<html>\r\n<head><title>404 Not Found</title></head>\r\n<body>\r\n<center><h1>404 Not Found</h1></center>\r\n<hr><center>nginx/1.27.1</center>\r\n</body>\r\n</html>";
    $apache404 = '<!DOCTYPE HTML><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1>'
        . '<address>Apache/2.4.58 Server</address></body></html>';
    $d = PortalManifest::diagnose(404, "Server: nginx\r\n", $nginx404);
    ok(str_contains($d, 'nginx') && str_contains($d, 'acme-challenge'),
       '404 от самого nginx — названа причина и строка, которую править', $d);
    $d = PortalManifest::diagnose(404, "Server: nginx\r\n", $apache404);
    ok(!str_contains($d, 'acme-challenge'),
       'а 404 от приложения за nginx-прокси — не он: заголовку Server тут верить нельзя', $d);
    $d = PortalManifest::diagnose(403, '', '<html>Forbidden</html>');
    ok(str_contains($d, 'well-known') && str_contains($d, 'FilesMatch'), '403 — названо исключение для .well-known', $d);
    $d = PortalManifest::diagnose(302, "Location: https://x.example/login\r\n", '');
    ok(str_contains($d, 'редирект') && str_contains($d, 'https://x.example/login'), 'редирект — назван, куда ведёт', $d);
    $d = PortalManifest::diagnose(200, '', '<!doctype html><html><body>Главная</body></html>');
    ok(str_contains($d, 'страница сайта'), 'HTML вместо JSON — назван перехват маршрутом', $d);
    $d = PortalManifest::diagnose(0, '', '', 'Could not resolve host: x.example');
    ok(str_contains($d, 'не ответил') && str_contains($d, 'resolve'), 'молчание — с причиной от curl', $d);

    echo "\n── После неудачи ждём час, а не сутки ──\n";
    Setting::delete('portal.manifest_error.' . (int)$p['id']);
    ok(PortalManifest::retryAfter((int)$p['id']) === 86400, 'без неудач — раз в сутки');
    PortalManifest::fetch(Client::find((int)$p['id']));
    $e = PortalManifest::lastError((int)$p['id']);
    ok($e !== null && (int)$e['fails'] === 1, 'неудача запомнена с причиной', (string)($e['why'] ?? '—'));
    ok(PortalManifest::retryAfter((int)$p['id']) === 3600, 'первая неудача — снова через час');
    PortalManifest::fetch(Client::find((int)$p['id']));
    ok(PortalManifest::retryAfter((int)$p['id']) === 7200, 'вторая подряд — через два');
    Setting::set('portal.manifest_error.' . (int)$p['id'],
        json_encode(['at' => now(), 'why' => 'x', 'fails' => 40]));
    ok(PortalManifest::retryAfter((int)$p['id']) === 86400,
       'и не чаще раза в сутки у того, у кого манифеста нет и не будет');

    PortalManifest::fetch(Client::find((int)$p['id']));
    /* Связь с порталом живёт в «Подключении» (v4.68), а не в «Ролях». */
    [, $roles] = http('GET', SSO . '/admin/clients/' . (int)$p['id'] . '/artifacts', [], [], $JAR);
    ok(str_contains($roles, 'Последняя попытка') && str_contains($roles, 'Спросить у портала'),
       'карточка называет причину рядом с кнопкой, которой проверяют снова');

    echo "\n── Сторож собственных адресов ──\n";
    ok(SelfWatch::transition('ok', 'bad') === 'broken', 'сломалось — повод написать');
    ok(SelfWatch::transition('bad', 'bad') === null, 'сломано второй час — не повод писать снова');
    ok(SelfWatch::transition('bad', 'ok') === 'recovered', 'починилось — замечено');
    $was = Setting::get('notify.admin.health.self', null);
    Setting::delete('notify.admin.health.self');
    Setting::flush();
    ok(\App\Core\AdminNotify::enabled('health.self'),
       'письмо сторожа включено без настройки — сторож, который молчит, не сторож');
    if ($was !== null) Setting::set('notify.admin.health.self', (string)$was);
    ok(array_key_exists('code', \App\Core\Probe::hit('проба', SSO . '/login', static fn(): array => ['ok', ''], true)),
       'прозвон сообщает код ответа — сторож отличает молчание от поломки');

    echo "\n── Перенос людей: телефон уже занят ──\n";
    /* На users.phone стоит UNIQUE, а импорт его не проверял: второй человек с
       тем же номером падал на ограничении, строка терялась, а в отчёт уходил
       сырой «SQLSTATE[23000]…». Второй номер записан с пробелом —
       нормализация обязана признать его тем же. */
    $ph = '+7999' . substr($stamp, -7);
    $phMails = ['phone-a-' . $stamp . '@test.local', 'phone-b-' . $stamp . '@test.local'];
    $phCsv = sys_get_temp_dir() . '/portal-connect-phone.csv';
    file_put_contents($phCsv, "email;tel\n{$phMails[0]};$ph\n{$phMails[1]};" . str_replace('+7999', '+7 999 ', $ph) . "\n");
    [, $toolsPage] = http('GET', SSO . '/admin/tools', [], [], $JAR);
    upload(SSO . '/admin/tools/import', ['_csrf' => csrfOf($toolsPage), 'step' => 'upload'],
        ['file' => [$phCsv, 'text/csv', 'phone.csv']]);
    http('POST', SSO . '/admin/tools/import', ['_csrf' => csrfOf($toolsPage), 'step' => 'map',
        'map_email' => 'email', 'map_phone' => 'tel'], [], $JAR);
    [, $toolsAfter] = http('GET', SSO . '/admin/tools', [], [], $JAR);
    $pa = \App\Models\User::findByLoginOrEmail($phMails[0]);
    $pb = \App\Models\User::findByLoginOrEmail($phMails[1]);
    ok($pa !== null && $pb !== null, 'оба человека с одним телефоном заведены — ни один не потерян');
    ok($pa && $pa['phone'] === $ph && $pb && $pb['phone'] === null,
       'номер остался у первого, второй заведён без него',
       'a=' . ($pa['phone'] ?? '—') . ' b=' . ($pb['phone'] ?? '—'));
    ok(!str_contains($toolsAfter, 'SQLSTATE') && str_contains($toolsAfter, 'без телефона'),
       'отчёт говорит словами, а не текстом базы');
    [, $toolsAgain] = http('GET', SSO . '/admin/tools', [], [], $JAR);
    ok(!str_contains($toolsAgain, 'без телефона'),
       'и показывается один раз — отчёт не висит на странице до конца сессии');
    foreach ($phMails as $pm) DB::run('DELETE FROM {users} WHERE email = ?', [$pm]);
    @unlink($phCsv);

    ok(str_contains(\App\Controllers\Admin\ToolsController::importReason(new PDOException(
        'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: users.phone')), 'телефон'),
       'если ограничение всё же сработает — названа колонка, а не угадана');

    echo "\n── Набор ──\n";
    $md = KitBuilder::integrationMd($p, null);
    preg_match_all('~```nginx\n(.*?)```~s', $md, $blocks);
    $broad = array_filter($blocks[1], static fn(string $b): bool
        => (bool)preg_match('#location\s+(~|\^~)\s*/\.well-known/?\s*\{#', $b));
    ok($blocks[1] !== [] && $broad === [],
       'ни одна nginx-заготовка не забирает весь /.well-known себе', implode(' | ', $broad));
    ok(str_contains($md, 'location ^~ /.well-known/acme-challenge/'), 'сертификатам — только их каталог');
    ok(str_contains($md, 'form-action'), 'в разделе про CSP есть form-action');

    $links = [];
    foreach ([$md, (string)file_get_contents($root . '/templates/kit/sso-button.css.tpl'),
              (string)file_get_contents($root . '/templates/kit/callback.php.tpl')] as $src) {
        preg_match_all('~<a class=\\\\?"sso-btn[^>]*href=\\\\?"/sso/login\.php\\\\?"[^>]*>~', $src, $lm);
        array_push($links, ...$lm[0]);
    }
    $bare = array_filter($links, static fn(string $a): bool
        => !str_contains($a, 'data-turbo=') || !str_contains($a, 'hx-boost='));
    ok(count($links) >= 4 && $bare === [],
       'каждая кнопка входа в наборе переживает htmx и Turbo (' . count($links) . ')', implode(' | ', $bare));
} finally {
    if (isset($loginUid)) App\Models\User::purge((int)$loginUid);
    foreach ($made as $id) {
        Setting::delete('portal.redirect_miss.' . $id);
        Setting::delete('portal.logout_miss.' . $id);
        Setting::delete('portal.manifest_error.' . $id);
        Setting::delete('bridge.attempt.' . $id);
        $c = Client::find($id);
        if ($c) Client::purge($id);
    }
}

echo "\n══ Итог ══\n";
printf("Пройдено: %d, провалено: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
