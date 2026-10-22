<?php
/**
 * E2E: полный цикл SSO против dev-сервера (sqlite).
 * Требует: SSO на :8710 (php -S), установленный конфиг. RP поднимается сам на :8711.
 * Запуск: php -d extension=pdo_sqlite -d extension=sodium tests/e2e.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';   // ok(), http(), upload(), csrfOf(), pageClean() — общие со смоуком

use App\Core\Totp;

const SSO = 'http://localhost:8710';
const RP = 'http://localhost:8711';

$JAR = __DIR__ . '/tmp/e2e-cookies.txt';
@unlink($JAR);
@unlink(__DIR__ . '/tmp/api-jar.txt');
$passed = 0;
$failed = 0;

/* Сброс состояния прошлых прогонов: тест идемпотентен.
   Обязательную 2FA для админов включаем политикой — тестируем опциональный enforcement. */
\App\Core\Setting::set('policy.admin_require_2fa', '1');
/* Экран первого входа проверяется здесь, поэтому включаем его явно, а не
   надеемся на умолчание: состязательный прогон выключает его у себя, и после
   него в базе остаётся ноль. Тест, зависящий от порядка запуска, — не тест. */
\App\Core\Setting::set('policy.welcome_screen', '1');
\App\Core\DB::run('UPDATE {users} SET totp_secret = NULL, totp_enabled_at = NULL WHERE email = ?', ['admin@test.local']);
\App\Core\DB::run('DELETE FROM {recovery_codes}');
\App\Core\DB::run('DELETE FROM {clients}');
\App\Core\DB::run('DELETE FROM {sessions}');
\App\Core\DB::run('DELETE FROM {rate_limits}');
foreach ((array)glob(__DIR__ . '/rp/sso/var/sid-*') as $f) @unlink($f);
foreach ((array)glob(__DIR__ . '/rp/sso/var/sub-*') as $f) @unlink($f);
@unlink(__DIR__ . '/rp/sso/var/sso-events.ndjson');
/* Секреты, которые пишет экран настроек набора (раздел 6в): тестовый портал
   лежит в tests/rp/sso, и «выше веб-корня» для него — это tests/ и tests/rp/. */
foreach ([__DIR__ . '/sso-secrets.php', __DIR__ . '/rp/sso-secrets.php', __DIR__ . '/tsoy-secrets.php', __DIR__ . '/rp/tsoy-secrets.php', __DIR__ . '/rp/sso/var/secrets.php'] as $f) @unlink($f);
foreach ((array)glob(SSO_ROOT . '/storage/logs/mail/*.eml') as $f) @unlink($f);

/**
 * Пройти экран первого входа, если он показался.
 *
 * С v4.19 доверенный портал тоже спрашивает — «доверенный» про данные, а не
 * про то, что человека можно не спрашивать. В потоке это лишний прыжок, и
 * тесты, которые проверяют протокол, должны через него просто пройти.
 */
function passConsent(string $loc): string
{
    if (!str_contains($loc, '/consent')) return $loc;
    [, $page] = http('GET', SSO . '/consent');
    [, , $next] = http('POST', SSO . '/consent',
        ['_csrf' => csrfOf($page), 'decision' => 'allow', 'agree' => '1']);
    $next = str_starts_with((string)$next, 'http') ? (string)$next : SSO . $next;
    [, , $after] = http('GET', $next);
    return (string)$after;
}

function followAll(string $url, int $max = 6): array
{
    $last = [0, '', '', '', ''];
    for ($i = 0; $i < $max && $url !== ''; $i++) {
        $last = http('GET', $url);
        if ($last[2] === '') break;
        $url = str_starts_with($last[2], 'http') ? $last[2] : SSO . $last[2];
    }
    return $last;
}

echo "═══ 1. Вход суперадмина ═══\n";
[, $loginPage] = http('GET', SSO . '/login');
$csrf = csrfOf($loginPage);
[$st, , $loc] = http('POST', SSO . '/login', [
    '_csrf' => $csrf, 'identity' => 'admin@test.local', 'password' => 'correct-horse-battery',
]);
ok($st === 302 && str_contains($loc, '/profile'), 'вход по паролю → /profile', "status=$st loc=$loc");

echo "═══ 2. Включение 2FA (политика admin_require_2fa включена в тесте) ═══\n";
[$st, , $loc] = http('GET', SSO . '/admin');
ok($st === 302 && str_contains($loc, '/profile/security'), 'при включённой политике без 2FA админка не пускает');
http('POST', SSO . '/profile/security/2fa/start', ['_csrf' => $csrf]);
[, $secPage] = http('GET', SSO . '/profile/security');
preg_match('~id="totp-secret"[^>]*value="([A-Z2-7]+)"~', $secPage, $m);
$secret = $m[1] ?? '';
ok($secret !== '', 'секрет TOTP показан для настройки');
$code = Totp::code($secret, (int)floor(time() / 30));
[$st] = http('POST', SSO . '/profile/security/2fa/confirm', ['_csrf' => $csrf, 'code' => $code]);
[, $secPage2] = http('GET', SSO . '/profile/security');
preg_match_all('~([A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4})~', $secPage2, $rc);
$recoveryCodes = array_unique($rc[1] ?? []);
ok(count($recoveryCodes) === 10, 'выданы 10 recovery-кодов', 'найдено ' . count($recoveryCodes));
[$st] = http('GET', SSO . '/admin');
ok($st === 200, 'админка открылась после включения 2FA');

echo "═══ 3. Повторный вход: пароль + TOTP ═══\n";
[, $lp] = http('GET', SSO . '/logout');
http('POST', SSO . '/logout', ['_csrf' => csrfOf($lp), 'mode' => 'here']);
[, $loginPage] = http('GET', SSO . '/login');
$csrf = csrfOf($loginPage);
[$st, , $loc] = http('POST', SSO . '/login', [
    '_csrf' => $csrf, 'identity' => 'admin', 'password' => 'correct-horse-battery', 'remember' => '1',
]);
ok($st === 302 && str_contains($loc, '/login/2fa'), 'после пароля — шаг 2FA', "loc=$loc");
$code = Totp::code($secret, (int)floor(time() / 30));
[$st, , $loc] = http('POST', SSO . '/login/2fa', ['_csrf' => $csrf, 'code' => $code]);
ok($st === 302 && str_contains($loc, '/profile'), 'TOTP принят, вход завершён');

echo "═══ 4. Мастер «два клика»: создание клиента (§6) ═══\n";
[$st, , $loc] = http('POST', SSO . '/admin/clients/create', [
    '_csrf' => $csrf,
    'name' => 'Тестовый портал',
    'base_url' => RP,
    'redirect_uris' => RP . '/sso/callback.php',
    'post_logout_uris' => RP . '/',
    'backchannel_uri' => RP . '/sso/backchannel.php',
    'is_trusted' => '1',
    'access_policy' => 'open',
    'allow_offline' => '1',
]);
ok($st === 302 && preg_match('~/admin/clients/(\d+)~', $loc, $m) === 1, 'клиент создан', "loc=$loc");
$clientDbId = (int)($m[1] ?? 0);
/* Реквизиты показываются один раз и живут в разделе «Подключение» — туда же
   ведёт редирект после создания (v4.30). Раньше они были в шапке карточки. */
[, $card] = http('GET', SSO . '/admin/clients/' . $clientDbId . '/artifacts');
preg_match('~id="cid" readonly value="([^"]+)"~', $card, $m);
$clientId = $m[1] ?? '';
preg_match('~id="csec" readonly value="([^"]+)"~', $card, $m);
$clientSecret = $m[1] ?? '';
preg_match('~id="setup" readonly value="[^"]*/setup/([^"]+)"~', $card, $m);
$setupToken = $m[1] ?? '';
ok(str_starts_with($clientId, 'cli_'), 'client_id выдан: ' . $clientId);
ok(strlen($clientSecret) > 40, 'client_secret показан один раз');
ok($setupToken !== '', 'setup-ссылка для админа портала сгенерирована');
[, $cardAgain] = http('GET', SSO . '/admin/clients/' . $clientDbId . '/artifacts');
ok(!str_contains($cardAgain, $clientSecret), 'при повторном открытии секрет уже не показывается');

echo "═══ 5. Артефакты: integration.md + PHP-kit ═══\n";
[$st, $md] = http('GET', SSO . '/admin/clients/' . $clientDbId . '/integration.md');
ok($st === 200 && str_contains($md, $clientId) && str_contains($md, '{{CLIENT_SECRET}}'),
    'integration.md: реквизиты на месте, секрет — плейсхолдер по умолчанию');
$zipFile = __DIR__ . '/tmp/kit.zip';
$ch = curl_init(SSO . '/admin/clients/' . $clientDbId . '/kit.zip');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $JAR, CURLOPT_TIMEOUT => 15]);
file_put_contents($zipFile, (string)curl_exec($ch));
curl_close($ch);
$zip = new ZipArchive();
ok($zip->open($zipFile) === true, 'kit.zip скачан и открывается');
$rpDir = __DIR__ . '/rp';
@mkdir($rpDir, 0777, true);
$zip->extractTo($rpDir);
$zip->close();
ok(is_file($rpDir . '/sso/SsoClient.php'), 'кит распакован (SsoClient.php на месте)');
// Впишем секрет в конфиг RP (в файлах по умолчанию — плейсхолдер, §6.1)
$cfg = (string)file_get_contents($rpDir . '/sso/config.php');
$cfg = str_replace('{{CLIENT_SECRET}}', $clientSecret, $cfg);
file_put_contents($rpDir . '/sso/config.php', $cfg);
file_put_contents($rpDir . '/index.php', '<?php session_start(); echo isset($_SESSION["sso_user"]) ? "USER:" . $_SESSION["sso_user"]["name"] : "ANON";');

echo "═══ 6. Полный OIDC-флоу через сгенерированный кит ═══\n";
[$st, , $authUrl] = http('GET', RP . '/sso/login.php');
ok($st === 302 && str_contains($authUrl, '/authorize?') && str_contains($authUrl, 'code_challenge='),
    'RP построил authorize_url с PKCE');

/* Первый вход в сервис: экран «куда я попал» показывается и доверенному
   порталу тоже (v4.19). До этого доверенный заводил человеку доступ молча —
   «доверенный» означает, что мы доверяем сервису данные, а не что человека
   можно не спрашивать. */
[$st, , $toConsent] = http('GET', $authUrl);
ok($st === 302 && str_contains($toConsent, '/consent'),
    'первый вход в доверенный сервис спрашивает согласие', "loc=$toConsent");
/* Галочка — только когда установка требует принять документы (v4.70).
   Включаем документ на время проверки: тогда «Продолжить» без отметки —
   не согласие, а промах по кнопке. */
\App\Core\Legal::save('privacy', \App\Core\Legal::template('privacy'), true);
[, $consentPage] = http('GET', SSO . '/consent');
ok(str_contains($consentPage, 'Тестовый портал') && str_contains($consentPage, 'name="agree"')
    && str_contains($consentPage, 'href="/legal/privacy"'),
    'с документами на экране сервис и галочка со ссылкой на документ');
[$st, , $noAgreeLoc] = http('POST', SSO . '/consent',
    ['_csrf' => csrfOf($consentPage), 'decision' => 'allow']);
ok($st === 302 && str_contains((string)$noAgreeLoc, '/consent'),
    'без галочки возвращает на тот же экран, а не выбрасывает из потока',
    "loc=" . substr((string)$noAgreeLoc, 0, 80));

/* Без документов галочка повторяла кнопку «Продолжить» — лишний щелчок на
   самом частом экране. Теперь её нет, и согласие — сама кнопка. */
\App\Core\Legal::save('privacy', \App\Core\Legal::template('privacy'), false);
[, $consentPage] = http('GET', SSO . '/consent');
ok(str_contains($consentPage, 'Тестовый портал') && !str_contains($consentPage, 'name="agree"'),
    'без документов галочки нет — только «Продолжить» и «Не сейчас»');
[$st, , $afterConsent] = http('POST', SSO . '/consent',
    ['_csrf' => csrfOf($consentPage), 'decision' => 'allow']);
ok($st === 302 && str_contains($afterConsent, '/authorize'), 'согласие принято, возвращаемся в поток');
$backToAuthorize = str_starts_with($afterConsent, 'http') ? $afterConsent : SSO . $afterConsent;
[$st, , $cbUrl] = http('GET', $backToAuthorize);
ok($st === 302 && str_contains($cbUrl, '/sso/callback.php?') && str_contains($cbUrl, 'code='),
    'SSO выдал code без ввода пароля (сессия жива)', "st=$st loc=" . substr($cbUrl, 0, 100));
[$st, $cbBody] = http('GET', $cbUrl);
ok($st === 200 && str_contains($cbBody, 'Вход выполнен'), 'callback: обмен кода и проверка id_token прошли');
[, $rpIndex] = http('GET', RP . '/');
ok(str_starts_with($rpIndex, 'USER:'), 'локальная сессия RP создана: ' . $rpIndex);

/* Второй раз не спрашиваем: отметка согласия сохранена у доступа. */
[$st, , $again] = http('GET', RP . '/sso/login.php');
[$st, , $againCb] = http('GET', $again);
ok($st === 302 && str_contains((string)$againCb, 'code='),
    'повторный вход в тот же сервис проходит молча', "loc=" . substr((string)$againCb, 0, 80));

echo "═══ 6б. Удаление в SSO гасит все сессии RP по sub (v4.62) ═══\n";
/* События про человека целиком — удалён, заблокирован, доступ закрыт —
   приходят без sid. Мост гасил сессии только по sid, и удалённый в SSO
   человек оставался в портале вошедшим, пока портал не допишет хук. Шлём
   событие настоящим путём: через очередь SSO на приёмник RP. */
ok(count((array)glob($rpDir . '/sso/var/sub-*')) >= 1, 'RP завёл индекс сессий по человеку');
@unlink($rpDir . '/sso/var/sso-events.ndjson');
$adminRow = \App\Models\User::find((int)\App\Core\DB::val('SELECT id FROM {users} WHERE email = ?', ['admin@test.local']));
\App\Oidc\EventDispatcher::userDeleted($adminRow, 'scheduled');
[, $rpIndex] = http('GET', RP . '/');
ok(trim($rpIndex) === 'ANON', 'user.deleted без sid погасил сессию RP по sub: ' . trim($rpIndex));
$queued = (string)@file_get_contents($rpDir . '/sso/var/sso-events.ndjson');
ok(str_contains($queued, '"user.deleted"') && str_contains($queued, '"mode":"scheduled"'),
    'заглушка хука не потеряла событие: оно в очереди RP, и mode при нём', substr($queued, 0, 160));
\App\Oidc\EventDispatcher::userRestored($adminRow);
ok(str_contains((string)@file_get_contents($rpDir . '/sso/var/sso-events.ndjson'), '"user.restored"'),
    'user.restored тоже доезжает до RP');
/* Снятие доступа: набор гасит сессии и отдаёт хуку mode — closed или removed (v4.64). */
$rpClient = \App\Models\Client::find($clientDbId);
\App\Oidc\EventDispatcher::accessRevoked($adminRow, $rpClient, 'removed');
\App\Oidc\EventDispatcher::accessGranted($adminRow, $rpClient);
$queued = (string)@file_get_contents($rpDir . '/sso/var/sso-events.ndjson');
ok(str_contains($queued, '"access.revoked"') && str_contains($queued, '"mode":"removed"')
    && str_contains($queued, '"access.granted"'),
    'снятие доступа доезжает до RP вместе с mode, возврат доступа — тоже');
/* Проверка связи из консоли — так её зовут, когда вход сломан (v4.63). */
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($rpDir . '/sso/admin.php') . ' check 2>&1', $cliOut, $cliRc);
$cliText = implode("\n", $cliOut);
ok(str_contains($cliText, 'hooks.php — заглушка'),
    'проверка связи узнаёт заглушку хука — до v4.62 самопроверка хвалила её как написанный обработчик');
ok($cliRc === 0 && str_contains($cliText, 'Издатель принимает секрет'),
    'из консоли — та же проверка, и в ней нет ни одного «нет»', "код $cliRc");
/* Вернуть RP-сессию для раздела 9: SSO-сессия жива, согласие уже дано. */
[, , $back] = http('GET', RP . '/sso/login.php');
[, , $backCb] = http('GET', (string)$back);
http('GET', (string)$backCb);
[, $rpIndex] = http('GET', RP . '/');
ok(str_starts_with(trim($rpIndex), 'USER:'), 'и вход в RP снова работает: ' . trim($rpIndex));

echo "═══ 6в. Экран настроек SSO в наборе (v4.63) ═══\n";
/* Обязательный экран портала: секрет клиента, ключ переноса, проверка связи.
   До 4.63 ввести ключ после установки было негде, а проверка лежала в
   selftest.php, открытом всем. */
$anonJar = __DIR__ . '/tmp/rp-anon.txt';
@unlink($anonJar);
[$st, $anonPage] = http('GET', RP . '/sso/admin.php', [], [], $anonJar);
ok($st === 401 && str_contains($anonPage, 'login.php?back=') && !str_contains($anonPage, 'длина'),
    'без входа экран настроек закрыт: кнопка входа и ни одной подробности', "st=$st");
@unlink($anonJar);

$checkState = static function (string $page, string $check): string {
    $needle = preg_quote(htmlspecialchars($check, ENT_QUOTES, 'UTF-8'), '~');
    return preg_match('~data-check="' . $needle . '" data-state="([a-z]+)"~u', $page, $m) === 1 ? $m[1] : '—';
};
[$st, $adminPage] = http('GET', RP . '/sso/admin.php?check=1');
ok($st === 200, 'администратор портала видит экран настроек', "st=$st");
foreach (['Издатель принимает секрет', 'Discovery издателя',
          'Ключи подписи (jwks.json)', 'Издатель знает адрес возврата', 'Часы сходятся'] as $check) {
    ok($checkState($adminPage, $check) === 'ok', "проверка связи: «{$check}» — есть", $checkState($adminPage, $check));
}
/* Здесь секрет намеренно вписан прямо в config.php (раздел 5) — ровно то, от
   чего экран обязан предостеречь: файл лежит в веб-корне и, как правило, в git. */
ok($checkState($adminPage, 'Секрет клиента на месте') === 'warn'
    && str_contains($adminPage, 'лежит в config.php, в веб-корне'),
    'секрет в config.php — «не всё»: экран предлагает перенести его вне веб-корня',
    $checkState($adminPage, 'Секрет клиента на месте'));
ok($checkState($adminPage, 'Обработчик удаления написан') === 'warn', 'заглушка хука — «не всё», а не «есть»');
ok(!str_contains($adminPage, $clientSecret), 'секрет клиента на экране не печатается');

$csrfA = preg_match('~name="_csrf" value="([a-f0-9]+)"~', $adminPage, $cm) === 1 ? $cm[1] : '';
$secretFiles = [__DIR__ . '/sso-secrets.php', __DIR__ . '/rp/sso-secrets.php', __DIR__ . '/tsoy-secrets.php', __DIR__ . '/rp/tsoy-secrets.php', $rpDir . '/sso/var/secrets.php'];
http('POST', RP . '/sso/admin.php', ['_csrf' => $csrfA, 'action' => 'secret', 'value' => str_repeat('z', 48)]);
[, $afterWrong] = http('GET', RP . '/sso/admin.php');
ok(str_contains($afterWrong, 'не принял'), 'чужой секрет не сохраняется: его отверг сам SSO');
ok(array_filter($secretFiles, 'is_file') === [], 'и файл секретов не тронут — вход не сломан');

http('POST', RP . '/sso/admin.php', ['_csrf' => $csrfA, 'action' => 'users_key', 'value' => $clientSecret]);
[, $afterMix] = http('GET', RP . '/sso/admin.php');
ok(str_contains($afterMix, 'Не похоже на ключ переноса'), 'секрет клиента в поле ключа переноса не принимается');

$usersKey = 'pusec_' . bin2hex(random_bytes(16));
http('POST', RP . '/sso/admin.php', ['_csrf' => $csrfA, 'action' => 'users_key', 'value' => $usersKey]);
[, $afterKey] = http('GET', RP . '/sso/admin.php?check=1');
ok(str_contains($afterKey, 'Ключ переноса сохранён') && $checkState($afterKey, 'Ключ переноса') === 'ok',
    'ключ переноса сохраняется с экрана и сразу виден проверке', $checkState($afterKey, 'Ключ переноса'));
ok(!str_contains($afterKey, $usersKey), 'и тоже не печатается');
/* Снимок экрана для глаз — tests/tmp в git не попадает. */
@file_put_contents(__DIR__ . '/tmp/rp-admin.html', $afterKey);
foreach ($secretFiles as $f) @unlink($f);

echo "═══ 7. Протокол напрямую: token/userinfo/introspect/refresh ═══\n";
require $rpDir . '/sso/SsoClient.php';
$rpCfg = require $rpDir . '/sso/config.php';
$sso = new SsoClient($rpCfg);
// Ручной флоу с offline_access
$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$state = bin2hex(random_bytes(8));
$nonce = bin2hex(random_bytes(8));
[$st, , $cbUrl] = http('GET', SSO . '/authorize?' . http_build_query([
    'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => RP . '/sso/callback.php',
    'scope' => 'openid profile email roles offline_access', 'state' => $state, 'nonce' => $nonce,
    'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
]));
parse_str((string)parse_url($cbUrl, PHP_URL_QUERY), $qs);
ok(($qs['state'] ?? '') === $state, 'state вернулся без изменений');
$code = (string)($qs['code'] ?? '');

$tokenReq = fn(array $extra) => json_decode(http('POST', SSO . '/token', $extra + [
    'client_id' => $clientId, 'client_secret' => $clientSecret,
], [], __DIR__ . '/tmp/api-jar.txt')[1], true);

// Неверный verifier → invalid_grant, код при этом не сгорает? (сгорает — не проверяем повторно)
$bad = $tokenReq(['grant_type' => 'authorization_code', 'code' => $code,
    'redirect_uri' => RP . '/sso/callback.php', 'code_verifier' => str_repeat('x', 50)]);
ok(($bad['error'] ?? '') === 'invalid_grant', 'неверный code_verifier → invalid_grant');

// Новый код (старый мог сгореть при неудаче? — нет, consumed только при успехе; но возьмём свежий)
[$st, , $cbUrl] = http('GET', SSO . '/authorize?' . http_build_query([
    'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => RP . '/sso/callback.php',
    'scope' => 'openid profile email roles offline_access', 'state' => $state, 'nonce' => $nonce,
    'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
]));
parse_str((string)parse_url($cbUrl, PHP_URL_QUERY), $qs);
$code = (string)($qs['code'] ?? '');
$tokens = $tokenReq(['grant_type' => 'authorization_code', 'code' => $code,
    'redirect_uri' => RP . '/sso/callback.php', 'code_verifier' => $verifier]);
ok(isset($tokens['id_token'], $tokens['access_token'], $tokens['refresh_token']),
    'обмен кода: id + access + refresh (offline_access)');

$claims = $sso->verifyJwt($tokens['id_token']);
ok($claims['nonce'] === $nonce, 'nonce в id_token совпал');
ok(($claims['email'] ?? '') === 'admin@test.local', 'claim email на месте');
ok(in_array('superadmin', $claims['roles'] ?? [], true), 'claim roles содержит superadmin');
/* Сводка для портала: число для сравнений, слово для читаемости. Ветвиться по
   ней портал должен без разбора нашего словаря ролей. */
ok(isset($claims['access_level']) && (int)$claims['access_level'] >= 91,
    'claim access_level приехал: ' . ($claims['access_level'] ?? '—'));
ok(in_array($claims['access_tier'] ?? '', ['superadmin', 'superadmin_limited', 'owner'], true),
    'claim access_tier соответствует уровню: ' . ($claims['access_tier'] ?? '—'));
ok(isset($claims['sid']) && $claims['sid'] !== '', 'sid присутствует (для backchannel)');
$sub = (string)$claims['sub'];

// Повтор кода → invalid_grant + аудит code.replay
$replay = $tokenReq(['grant_type' => 'authorization_code', 'code' => $code,
    'redirect_uri' => RP . '/sso/callback.php', 'code_verifier' => $verifier]);
ok(($replay['error'] ?? '') === 'invalid_grant', 'повтор кода → invalid_grant (сигнатура перехвата)');
/* Повтор отзывает и выданное по коду (RFC 6749 §4.1.2, v4.70): тот, кто
   обменял перехваченный код первым, не остаётся с живым refresh. */
$afterReplay = $tokenReq(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
ok(($afterReplay['error'] ?? '') === 'invalid_grant', 'после повтора кода его refresh отозван');

// userinfo
$ui = json_decode(http('GET', SSO . '/userinfo', [], ['Authorization: Bearer ' . $tokens['access_token']],
    __DIR__ . '/tmp/api-jar.txt')[1], true);
ok(($ui['sub'] ?? '') === $sub, '/userinfo отвечает по Bearer');
/* Номер аккаунта приезжает порталу в scope profile: по нему портал найдёт того
   же человека, когда тот назовёт номер в поддержке. Идентификатором субъекта
   остаётся sub — номер лишь дополняет его. */
ok(\App\Core\AccountNumber::isValid((string)($ui['account_number'] ?? '')),
    '/userinfo отдаёт номер аккаунта: ' . ($ui['account_number'] ?? '—'));
ok(($ui['account_number'] ?? '') !== ($ui['sub'] ?? ''), 'номер не подменяет собой sub');

// introspect (Basic)
$basic = base64_encode($clientId . ':' . $clientSecret);
$intro = json_decode(http('POST', SSO . '/introspect', ['token' => $tokens['access_token']],
    ['Authorization: Basic ' . $basic], __DIR__ . '/tmp/api-jar.txt')[1], true);
ok(($intro['active'] ?? false) === true, '/introspect: access-токен активен');

// refresh: ротация — на токене из свежего кода: прежний отозван повтором выше
[, , $cbUrl] = http('GET', SSO . '/authorize?' . http_build_query([
    'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => RP . '/sso/callback.php',
    'scope' => 'openid profile email roles offline_access', 'state' => $state, 'nonce' => $nonce,
    'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
]));
parse_str((string)parse_url($cbUrl, PHP_URL_QUERY), $qs);
$tokens = $tokenReq(['grant_type' => 'authorization_code', 'code' => (string)($qs['code'] ?? ''),
    'redirect_uri' => RP . '/sso/callback.php', 'code_verifier' => $verifier]);
ok(isset($tokens['refresh_token']), 'новый код — новый refresh');
$fresh = $tokenReq(['grant_type' => 'refresh_token', 'refresh_token' => (string)($tokens['refresh_token'] ?? '')]);
ok(isset($fresh['refresh_token']) && $fresh['refresh_token'] !== $tokens['refresh_token'],
    'refresh ротировался (новый токен)');
// reuse detection: старый refresh повторно → отзыв семьи
$reuse = $tokenReq(['grant_type' => 'refresh_token', 'refresh_token' => (string)($tokens['refresh_token'] ?? '')]);
ok(($reuse['error'] ?? '') === 'invalid_grant', 'повтор старого refresh → invalid_grant');
$afterReuse = $tokenReq(['grant_type' => 'refresh_token', 'refresh_token' => (string)($fresh['refresh_token'] ?? '')]);
ok(($afterReuse['error'] ?? '') === 'invalid_grant', 'вся семья отозвана после reuse (§3.3)');

echo "═══ 8. Ошибки протокола ═══\n";
[$st, $body] = http('GET', SSO . '/authorize?client_id=cli_nonexistent&redirect_uri=' . urlencode('https://evil.example/cb'));
ok($st === 400 && str_contains($body, 'Неизвестный сервис'), 'левый client_id → страница на месте, БЕЗ редиректа');
[$st, , $loc] = http('GET', SSO . '/authorize?' . http_build_query([
    'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => RP . '/sso/callback.php',
    'scope' => 'openid', 'state' => 's1', 'nonce' => 'n1', 'prompt' => 'none',
    'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
]));
ok(str_contains($loc, 'error=invalid_request'), 'prompt=none без same_site_family запрещён');

echo "═══ 9. Backchannel: «выйти везде» гасит сессию RP (§2.4) ═══\n";
$sidFiles = (array)glob($rpDir . '/sso/var/sid-*');
ok(count($sidFiles) >= 1, 'RP сохранил sid-реестр для logout');
[, $lp] = http('GET', SSO . '/logout');
[$st, , $loc] = http('POST', SSO . '/logout', ['_csrf' => csrfOf($lp), 'mode' => 'everywhere']);
ok($st === 302, '«выйти везде» отработал');
sleep(1); // немедленная доставка событий
$sidFilesAfter = (array)glob($rpDir . '/sso/var/sid-*');
ok(count($sidFilesAfter) < count($sidFiles), 'backchannel logout дошёл: sid-файл удалён RP',
    count($sidFiles) . ' → ' . count($sidFilesAfter));
[, $rpIndex] = http('GET', RP . '/');
ok(trim($rpIndex) === 'ANON', 'локальная сессия RP погашена: ' . trim($rpIndex));
[$st, , $loc] = http('GET', SSO . '/profile');
ok($st === 302 && str_contains($loc, '/login'), 'центральная SSO-сессия тоже завершена');

echo "═══ 10. Регистрация + подтверждение почты (mail-драйвер log) ═══\n";
@unlink($JAR);
/* Галочки появляются только у включённых документов (опциональность §1):
   на свежей установке их нет вовсе. Включаем документ, чтобы проверить путь. */
\App\Core\Legal::save('privacy', \App\Core\Legal::template('privacy'), true);
[, $regPage] = http('GET', SSO . '/register');
$csrf = csrfOf($regPage);
$email = 'user' . time() . '@test.local';

/* Галочки согласия обязательны: сначала пробуем без них — форма должна отказать. */
preg_match_all('~name="accept\[\]" value="([^"]+)"~', $regPage, $am);
$acceptKeys = $am[1] ?? [];
ok($acceptKeys !== [], 'в форме регистрации есть галочки согласия');
[$st, $noAgree] = http('POST', SSO . '/register', [
    '_csrf' => $csrf, 'first_name' => 'Пётр', 'email' => 'noagree' . time() . '@test.local',
    'password' => 'very-long-password-1',
]);
ok($st === 200 && str_contains($noAgree, 'нужно принять'), 'без галочек аккаунт не создаётся');

[$st, , $loc] = http('POST', SSO . '/register', [
    '_csrf' => $csrf, 'first_name' => 'Пётр', 'last_name' => 'Тестов',
    'email' => $email, 'password' => 'very-long-password-1', 'accept' => $acceptKeys,
]);
ok($st === 302 && str_contains($loc, '/profile'), 'регистрация сразу логинит (pending)');

/* Ошибка поля показывается У ПОЛЯ и красит его. Раньше `is-invalid` ставили
   руками рядом с выводом текста, и половина мест ставила только одно из двух:
   поле краснело без объяснения либо объяснение висело под обычным полем.
   Теперь это делает само поле, и проверять надо оба признака сразу.

   Берём опечатку в адресе, а не занятый адрес: занятый нарочно не подсвечивается
   — иначе форма регистрации становится проверялкой «есть у вас такой человек». */
[, $badPage] = http('POST', SSO . '/register', [
    '_csrf' => $csrf, 'first_name' => 'Пётр', 'email' => 'не-адрес',
    'password' => 'very-long-password-1', 'accept' => $acceptKeys,
]);
ok((bool)preg_match('~<input class="input is-invalid" type="email" id="email"~', $badPage),
   'ошибка в адресе красит своё поле');
/* Не строка, а правило: объяснение есть, оно привязано к своему полю и
   объявляется сразу. Сверка байт в байт ломалась о добавление
   aria-describedby — то есть о починку, а не о поломку. */
ok(preg_match('~<div class="field-error" id="email-error" role="alert">Похоже, в адресе опечатка\.~u', $badPage) === 1,
   'и объясняет причину рядом с ним');
ok(preg_match('~id="email"[^>]*aria-describedby="email-error"~', $badPage) === 1
   && preg_match('~id="email"[^>]*aria-invalid="true"~', $badPage) === 1,
   'и поле связано со своим объяснением, а не просто покрашено');

/* ── Политика новых аккаунтов (v4.41) ──────────────────────────────────────
   Роль новичка была вписана в код пятью местами, а закрыть регистрацию было
   нельзя вовсе. Проверяем оба: настройка роли действует, закрытая дверь
   закрыта, приглашение её открывает — и только тому, кому выдано. */
\App\Core\Setting::set('policy.signup_open', '0');
\App\Core\Setting::flush();
[, $closedPage] = http('GET', SSO . '/register');
ok(str_contains($closedPage, 'Регистрация закрыта'),
    'закрытая регистрация встречает отдельным экраном, а не пустой формой');
$deniedEmail = 'denied' . time() . '@test.local';
[, $deniedPost] = http('POST', SSO . '/register', [
    '_csrf' => $csrf, 'first_name' => 'Никто', 'email' => $deniedEmail,
    'password' => 'very-long-password-1', 'accept' => $acceptKeys,
]);
ok(\App\Models\User::findByEmail($deniedEmail) === null,
    'и не пускает даже отправкой формы мимо экрана');

/* Приглашение с привязкой к почте: чужому не подходит, своему — да. */
\App\Core\DB::run('DELETE FROM {rate_limits}');
$invEmail = 'invited' . time() . '@test.local';
$inv = \App\Core\Signup::issueInvite($invEmail, null);
$wrongEmail = 'wrong' . time() . '@test.local';
http('POST', SSO . '/register', [
    '_csrf' => $csrf, 'first_name' => 'Чужой', 'email' => $wrongEmail,
    'password' => 'very-long-password-1', 'accept' => $acceptKeys, 'invite' => $inv['token'],
]);
ok(\App\Models\User::findByEmail($wrongEmail) === null,
    'приглашение на чужой адрес не срабатывает');
\App\Core\DB::run('DELETE FROM {rate_limits}');
[$stInv] = http('POST', SSO . '/register', [
    '_csrf' => $csrf, 'first_name' => 'Званый', 'email' => $invEmail,
    'password' => 'very-long-password-1', 'accept' => $acceptKeys, 'invite' => $inv['token'],
]);
$invited = \App\Models\User::findByEmail($invEmail);
ok($invited !== null, 'а на свой — открывает дверь', "st=$stInv");
ok(\App\Models\OneTimeToken::peek($inv['token'], \App\Core\Signup::INVITE) === null,
    'и гаснет после использования: ссылка одноразовая');

/* Роль новичка берётся из настройки, а не из имени «user». */
\App\Core\Setting::set('policy.signup_open', '1');
$guestRole = \App\Models\Role::findBySlug('guest')
    ?? ['id' => \App\Models\Role::create(null, 'guest', 'Гость', null, true)];
\App\Models\Role::setLevel((int)$guestRole['id'], 10);
\App\Core\Setting::set('policy.default_role', 'guest');
\App\Core\Setting::flush();
\App\Core\DB::run('DELETE FROM {rate_limits}');
$roleEmail = 'newrole' . time() . '@test.local';
http('POST', SSO . '/register', [
    '_csrf' => $csrf, 'first_name' => 'Ролевой', 'email' => $roleEmail,
    'password' => 'very-long-password-1', 'accept' => $acceptKeys,
]);
$roleUser = \App\Models\User::findByEmail($roleEmail);
$gotRoles = $roleUser ? array_column(\App\Models\Role::ofUser((int)$roleUser['id']), 'slug') : [];
ok(in_array('guest', $gotRoles, true),
    'новый аккаунт получает роль из настройки, а не «user» из кода',
    implode(', ', $gotRoles));
\App\Core\Setting::set('policy.default_role', 'user');
\App\Core\Setting::flush();

$emls = (array)glob(SSO_ROOT . '/storage/logs/mail/*.eml');
rsort($emls);
$verifyUrl = '';
foreach ($emls as $eml) {
    $c = (string)file_get_contents($eml);
    if (str_contains($c, $email) && preg_match('~(http://[^"\s]+/verify/[A-Za-z0-9_-]+)~', $c, $m)) {
        $verifyUrl = $m[1];
        break;
    }
}
ok($verifyUrl !== '', 'письмо верификации легло в лог-очередь');

/* GET по ссылке ничего не подтверждает: так по ней ходят сканеры и антивирусы.
   Проверяем именно это — сначала заходим чужой сессией, как предзагрузчик. */
[$st, $body] = http('GET', $verifyUrl, [], [], __DIR__ . '/tmp/scanner-jar.txt');
$u = \App\Core\DB::row('SELECT status, email_verified_at FROM {users} WHERE email = ?', [$email]);
ok($st === 200 && $u['email_verified_at'] === null, 'GET по ссылке не подтверждает почту');

$vcsrf = csrfOf($body);
[$st] = http('POST', $verifyUrl, ['_csrf' => $vcsrf], [], __DIR__ . '/tmp/scanner-jar.txt');
ok($st === 302, 'подтверждение почты сработало');
$u = \App\Core\DB::row('SELECT status, email_verified_at FROM {users} WHERE email = ?', [$email]);
ok($u !== null && $u['status'] === 'active' && $u['email_verified_at'] !== null, 'статус active, почта подтверждена');

/* ── Письмо не дошло (v4.18.2) ──────────────────────────────────────────────
   Самый частый провал регистрации: письмо в спаме или опечатка в адресе.
   Выхода не было вовсе — человек мог только зарегистрироваться заново.
   Проверяем на ОТДЕЛЬНОМ аккаунте и в отдельной банке cookie: основной сеанс
   идёт дальше, и подменять его в середине прогона нельзя. */
echo "═══ 11.1 Письмо не дошло: прислать ещё раз и сменить адрес ═══\n";
$mainJar = $JAR;
$JAR = __DIR__ . '/tmp/verify-jar.txt';
@unlink($JAR);
/* Счётчик регистраций на IP уже израсходован выше — тремя попытками подряд.
   Здесь нужен ещё один аккаунт, и это не обход защиты, а сброс стенда. */
\App\Core\DB::run('DELETE FROM {rate_limits}');

[, $regPage2] = http('GET', SSO . '/register');
$rc2 = csrfOf($regPage2);
preg_match_all('~name="accept\[\]" value="([^"]+)"~', $regPage2, $am2);
$typo = 'typo' . time() . '@test.local';
[$st] = http('POST', SSO . '/register', [
    '_csrf' => $rc2, 'first_name' => 'Опечатка', 'email' => $typo,
    'password' => 'very-long-password-1', 'accept' => $am2[1] ?? [],
]);
ok($st === 302, 'завели аккаунт с «неудачным» адресом', "st=$st");

[, $profilePage] = http('GET', SSO . '/profile');
ok(str_contains($profilePage, '/verify/resend') && str_contains($profilePage, '/verify/change'),
   'у неподтверждённой почты на экране оба выхода: прислать ещё раз и сменить адрес');
$pCsrf = csrfOf($profilePage);
$before = count((array)glob(SSO_ROOT . '/storage/logs/mail/*.eml'));
[$st] = http('POST', SSO . '/verify/resend', ['_csrf' => $pCsrf]);
$after = count((array)glob(SSO_ROOT . '/storage/logs/mail/*.eml'));
ok($st === 302 && $after === $before + 1, 'письмо уходит ещё раз', "st=$st было $before стало $after");

/* Смена адреса до подтверждения: пароль не спрашиваем — подтверждать нечем. */
$fixed = 'fixed' . time() . '@test.local';
[$st] = http('POST', SSO . '/verify/change', ['_csrf' => $pCsrf, 'email' => $fixed]);
$row = \App\Core\DB::row('SELECT email, email_verified_at FROM {users} WHERE email = ?', [$fixed]);
ok($st === 302 && $row !== null, 'адрес заменён без пароля', "st=$st");
ok($row !== null && $row['email_verified_at'] === null, 'и остался неподтверждённым');

$emls = (array)glob(SSO_ROOT . '/storage/logs/mail/*.eml');
rsort($emls);
$newVerify = '';
foreach ($emls as $eml) {
    $c = (string)file_get_contents($eml);
    if (str_contains($c, $fixed) && preg_match('~(http://[^"\s]+/verify/[A-Za-z0-9_-]+)~', $c, $mv)) {
        $newVerify = $mv[1];
        break;
    }
}
ok($newVerify !== '', 'письмо ушло на новый адрес');
if ($newVerify !== '') {
    [, $vp] = http('GET', $newVerify);
    [$st] = http('POST', $newVerify, ['_csrf' => csrfOf($vp)]);
    $row = \App\Core\DB::row('SELECT email_verified_at FROM {users} WHERE email = ?', [$fixed]);
    ok($st === 302 && $row['email_verified_at'] !== null, 'новый адрес подтверждается', "st=$st");
}
/* Подтверждённый адрес этим путём уже не меняется: там свои правила и пароль. */
[$st] = http('GET', SSO . '/verify/change');
ok($st === 302, 'у подтверждённой почты быстрый путь закрыт', "st=$st");

$JAR = $mainJar;


echo "═══ 12. Попап-режим целиком ═══\n";
/* Попап-режим целиком. Цепочка была проверена по частям — экран входа отдельно,
   колбэк отдельно, — но ни разу от начала до конца. А ломается она именно на
   стыке: заглушка колбэка должна сообщить открывшей странице и закрыться.

   Банки cookie свежие: у уже вошедшего /authorize сразу выдаёт код, экран входа
   не показывается вовсе, и проверять было бы нечего. */
$popupRpJar = __DIR__ . '/tmp/popup-rp.txt';
$popupSsoJar = __DIR__ . '/tmp/popup-sso.txt';
@unlink($popupRpJar);
@unlink($popupSsoJar);

[, , $popupAuth] = http('GET', RP . '/sso/login.php', [], [], $popupRpJar);
/* Со свежей сессией /authorize сначала отвечает редиректом на /login —
   запоминает запрос и уводит человека вводить пароль. Проверять тело этого
   редиректа бессмысленно: оно пустое. */
[, , $popupToLogin] = http('GET', $popupAuth . '&display=popup', [], [], $popupSsoJar);
[, $popupLogin] = http('GET', $popupToLogin ?: SSO . '/login', [], [], $popupSsoJar);
ok(str_contains($popupLogin, '<body class="popup">'), 'попап: окно помечено, вёрстка компактная');
ok(str_contains($popupLogin, 'auth-shell is-popup') && !str_contains($popupLogin, 'class="auth-split"'),
    'попап: карточка вместо двух колонок — в окне 480px панели не место');
ok(!str_contains($popupLogin, 'auth-footer-bar'),
    'попап: подвала нет — окно живёт секунды, документы и тема в нём лишние');
ok(str_contains($popupLogin, 'class="connector"'),
    'попап: коннектор портал↔SSO остаётся — человек должен видеть, куда попал');

/* Вторую половину цепочки гоняем на уже авторизованной сессии: к этому месту
   теста включена обязательная 2FA для админов, и вход одним паролем до конца
   не доходит — это проверяется отдельно и раньше. Здесь важно другое: что
   попап-режим доводит до колбэка и что колбэк умеет закрыть окно. */
$popupRpJar2 = __DIR__ . '/tmp/popup-rp2.txt';
@unlink($popupRpJar2);
[, , $popupAuth2] = http('GET', RP . '/sso/login.php', [], [], $popupRpJar2);
[$st, , $popupCbUrl] = http('GET', $popupAuth2 . '&display=popup');
$popupCbUrl = passConsent((string)$popupCbUrl);
ok(str_contains((string)$popupCbUrl, '/sso/callback.php') && str_contains((string)$popupCbUrl, 'code='),
    'попап: доводит до колбэка портала с кодом', "st=$st loc=" . substr((string)$popupCbUrl, 0, 90));

[, $cbPage] = http('GET', (string)$popupCbUrl, [], [], $popupRpJar2);
ok(str_contains($cbPage, "type: 'sso:done'"), 'попап: колбэк сообщает открывшей странице');
ok(str_contains($cbPage, 'window.close()'), 'попап: колбэк закрывает окно');
/* С v4.63 адрес подставляется (login.php?back=…); без него — корень сайта. */
ok(str_contains($cbPage, 'location.href = "/"'), 'попап: без opener есть запасной путь, окно не зависает');
/* Адресация сообщения: `'*'` означало бы «кому угодно, кто слушает». */
ok(str_contains($cbPage, "postMessage({ type: 'sso:done' }, location.origin)"),
    'попап: сообщение адресовано своему origin, а не всем подряд');

[, $popupRpIndex] = http('GET', RP . '/', [], [], $popupRpJar2);
ok(str_starts_with($popupRpIndex, 'USER:'), 'попап: сессия на портале создана — ' . $popupRpIndex);
/* Слушатель на стороне портала обязан проверять источник: без этого любое окно,
   дотянувшееся до страницы, гоняло бы перезагрузку или уводило по data-done. */
$ssoJs = (string)file_get_contents(SSO_ROOT . '/public/assets/js/sso.js');
ok(str_contains($ssoJs, 'e.origin !== location.origin'),
    'попап: виджет портала принимает сообщение только со своего origin');

echo "\n══════════════════════════════\n";
echo "═══ 13. Перенос людей с портала кнопкой (v4.6) ═══\n";
/* Проверяем НАСТОЯЩИЙ файл из набора, а не копию его логики: подпись, окно
   времени и формат ответа должны работать ровно в том виде, в каком уезжают
   на чужой сервер. Дописываем только тело функции выгрузки — то единственное,
   что портал и так пишет руками. */
$usersFile = $rpDir . '/sso/users.php';
ok(is_file($usersFile), 'модуль выгрузки приехал в наборе');

$pullSecret = 'pusec_' . str_repeat('t', 32);
$cfg = (string)file_get_contents($rpDir . '/sso/config.php');
$cfg = str_replace('{{USERS_SECRET}}', $pullSecret, $cfg);
file_put_contents($rpDir . '/sso/config.php', $cfg);

$fixture = <<<'PHPX'
function sso_export_users(int $limit, int $offset): array
{
    $all = [
        ['id' => '501', 'email' => 'pull-one@test.local', 'login' => 'pull-one',
         'email_verified' => true, 'first_name' => 'Первый', 'last_name' => 'Перенесённый',
         'password_hash' => '$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ012345',
         'created_at' => '2020-05-05 12:00:00'],
        ['id' => '502', 'login' => 'pull-nomail', 'email' => null,
         'first_name' => 'Второй', 'last_name' => 'Безпочтов',
         'password_hash' => '$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ012345',
         'created_at' => '2021-06-06 12:00:00'],
        ['id' => '503', 'email' => 'not-an-email', 'login' => '',
         'first_name' => 'Третий', 'last_name' => 'Никакой'],
    ];
    return array_slice($all, $offset, $limit);
}
function sso_export_total(): ?int { return 3; }
PHPX;
/* Замена через callback, а не строкой: в bcrypt-хэше есть «$2y$10$», и
   preg_replace принял бы это за обратные ссылки — подстановка съела бы начало
   хэша, а провалилась бы при этом проверка продукта, а не теста. */
$src = (string)file_get_contents($usersFile);
$src = preg_replace_callback('~function sso_export_users\(int \$limit, int \$offset\): array\s*\{.*?\n\}\s*/\*\* Сколько всего.*?\n\}~su',
    static fn(): string => $fixture, $src, 1);
file_put_contents($usersFile, $src);
ok(str_contains((string)file_get_contents($usersFile), 'pull-nomail'), 'выгрузка на портале заполнена данными');

/* Клиент-подопытный: адрес выгрузки обязан вести на его же хост, иначе SSO
   откажется — это защита от манифеста, уводящего нас с ключом куда укажут. */
\App\Core\DB::run('UPDATE {clients} SET base_url = ?, users_url = ?, users_secret = ? WHERE id = ?',
    [RP, RP . '/sso/users.php', $pullSecret, $clientDbId]);
$pullClient = \App\Models\Client::find($clientDbId);

$foreign = ['id' => $clientDbId, 'base_url' => RP, 'users_secret' => $pullSecret,
            'users_url' => 'http://127.0.0.1:9/sso/users.php', 'client_id' => $pullClient['client_id']];
$bad = \App\Core\PortalUsers::page($foreign, 0);
ok(!$bad['ok'], 'выгрузка на чужом хосте отвергается до запроса');

$wrongKey = ['users_secret' => 'pusec_' . str_repeat('x', 32)] + $pullClient;
$deny = \App\Core\PortalUsers::page($wrongKey, 0);
/* Именно отказ по ключу, а не «не ok»: «не ok» вернётся и от лежащего сервера,
   и такая проверка зеленела бы, не проверив подпись вовсе. */
ok(!$deny['ok'] && str_contains((string)$deny['error'], 'ключ'),
    'портал не отдаёт людей по неверному ключу', (string)$deny['error']);

$prev = \App\Core\PortalUsers::preview($pullClient);
ok($prev['ok'], 'предпросмотр получил список', (string)$prev['error']);
ok($prev['total'] === 3, 'портал сообщил, сколько у него всего: ' . var_export($prev['total'], true));
ok($prev['skipped'] === 1, 'строка без пригодных почты и логина пропущена ещё в предпросмотре');
ok($prev['new'] === 2, 'предпросмотр обещает завести двоих, а не троих');

$r1 = \App\Core\PortalUsers::pull($pullClient);
ok($r1['ok'] && $r1['created'] === 2, 'перенос завёл двоих', json_encode($r1, JSON_UNESCAPED_UNICODE));
$one = \App\Models\User::findByEmail('pull-one@test.local');
$two = \App\Models\User::findByLoginOrEmail('pull-nomail');
ok($one !== null && $two !== null, 'оба человека на месте');
ok($two && $two['email'] === null, 'человек без почты перенесён без почты, а не с пустой строкой');
ok($one && (int)$one['password_legacy'] === 1, 'чужой bcrypt принят и помечен legacy');

/* Человек, забранный ИЗ сервиса, обязан в этом сервисе и быть. Раньше перенос
   заводил аккаунт и записывал связь — и всё: в списке людей портала его не
   было, и в людях его организации тоже (тот считается по доступам). Забрали
   людей сервиса, а в сервисе их нет. */
$accOne = \App\Models\Access::get((int)$one['id'], $clientDbId);
ok(($accOne['status'] ?? '') === 'active',
    'перенесённый человек получил доступ к сервису, откуда пришёл',
    (string)($accOne['status'] ?? 'записи нет'));
/* Видимость в карточке — тем же запросом, каким её строит раздел «Люди».
   Через HTTP тут не проверить: к этому месту в сценарии банка cookie уже не
   админская, и страница честно отвечает 403. Отрисовку того же списка
   проверяет смоук, где сессия администратора живая. */
$inCard = (int)\App\Core\DB::val(
    'SELECT COUNT(*) FROM {users} u JOIN {user_client_access} a ON a.user_id = u.id
       WHERE a.client_id = ? AND u.status <> ? AND u.id = ?',
    [$clientDbId, 'deleted', (int)$one['id']]);
ok($inCard === 1, 'и попадает в список людей этого портала');

/* Но чужое решение перенос не отменяет: закрытый доступ повтором не открывается. */
\App\Models\Access::setStatus((int)$one['id'], $clientDbId, 'blocked');
\App\Core\PortalUsers::pull(\App\Models\Client::find($clientDbId));
ok((\App\Models\Access::get((int)$one['id'], $clientDbId)['status'] ?? '') === 'blocked',
    'повторный перенос не снимает блокировку, поставленную руками');
\App\Models\Access::grant((int)$one['id'], $clientDbId, null, 'active');

/* Ради этого всё и затевалось: повтор обязан узнать своих, а не задвоить. */
$r2 = \App\Core\PortalUsers::pull(\App\Models\Client::find($clientDbId));
ok($r2['ok'] && $r2['created'] === 0 && $r2['updated'] === 2,
    'повторный перенос никого не задвоил', json_encode($r2, JSON_UNESCAPED_UNICODE));
ok(\App\Core\PortalUsers::linkedCount($clientDbId) === 2, 'связей ровно две, а не четыре');

/* Узнавание по внешнему номеру сильнее почты: портал сменил адрес человеку —
   мы обязаны попасть в того же, а не завести второго. */
\App\Core\DB::run('UPDATE {users} SET email = ? WHERE id = ?', ['pull-renamed@test.local', (int)$one['id']]);
$norm = \App\Core\PortalUsers::normalize(['id' => '501', 'email' => 'pull-one@test.local', 'login' => 'pull-one']);
[$found, $how] = \App\Core\PortalUsers::match($clientDbId, $norm);
ok($found && (int)$found['id'] === (int)$one['id'] && $how === 'link',
    'после смены почты человек узнан по номеру у портала');

/* А вот по логину сводить безадресного с адресным нельзя: одинаковый логин у
   разных людей на разных сервисах — обычное дело. */
$byLogin = \App\Core\PortalUsers::normalize(['id' => '999', 'login' => 'pull-one']);
[$f2, $how2] = \App\Core\PortalUsers::match($clientDbId, $byLogin);
ok($f2 === null && $how2 === 'none', 'чужой логин не склеивается с аккаунтом, у которого есть почта');

echo $failed === 0 ? "ВСЕ ТЕСТЫ ЗЕЛЁНЫЕ: $passed\n" : "ПРОВАЛЕНО: $failed из " . ($passed + $failed) . "\n";
exit($failed === 0 ? 0 : 1);
