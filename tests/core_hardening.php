<?php
/**
 * Ядро после ревизии v4.70: одноразовое — одноразово, повтор — тревога.
 *
 * Ревизия нашла одно семейство дефектов в разных местах: «проверить, потом
 * пометить» двумя запросами. Код авторизации, одноразовые ссылки, коды
 * восстановления, ротация refresh-токена — всё гасилось безусловным UPDATE
 * после проверки, и два одновременных запроса проходили оба. Теперь гашение
 * условное, и выигрывает ровно один. Гонку встроенный сервер PHP не
 * воспроизведёт (он однопоточный), поэтому гашение проверяется прямым вызовом:
 * второй вызов обязан проиграть.
 *
 * Здесь же — остальное из ревизии: повтор кода отзывает refresh-токены,
 * pending-человек держит токены на всех путях, лимит на адрес для писем со
 * ссылкой входа, TOTP-код годится один раз, открытые редиректы, стандартное
 * событие выхода, iss в ответе авторизации, ссылка из письма не гаснет от
 * GET, удаление организации не оставляет выдач на пустоту.
 *
 * Запуск: php tests/core_hardening.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Scope;
use App\Core\Setting;
use App\Core\Totp;
use App\Models\AuthCode;
use App\Models\Client;
use App\Models\OneTimeToken;
use App\Models\Org;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\User;
use App\Oidc\IdToken;

const SSO = 'http://localhost:8710';
$JAR = sys_get_temp_dir() . '/core-hardening.txt';
@unlink($JAR);
$passed = 0;
$failed = 0;

/* Экран первого входа выключен на время прогона — здесь проверяется протокол.
   Возвращаем как было, чем бы прогон ни кончился. */
$welcomeWas = (string)Setting::get('policy.welcome_screen', '1');
Setting::set('policy.welcome_screen', '0');
Setting::flush();
register_shutdown_function(static function () use ($welcomeWas): void {
    Setting::set('policy.welcome_screen', $welcomeWas);
    Setting::flush();
});

DB::run('DELETE FROM {clients} WHERE slug = ?', ['hardening']);
[$cid, $clientId, $secret] = Client::create([
    'name' => 'Проба ядра', 'slug' => 'hardening', 'base_url' => 'https://hardening.example',
    'redirect_uris' => ['https://hardening.example/cb'], 'post_logout_uris' => ['https://hardening.example/'],
    'backchannel_uri' => null, 'allowed_scopes' => ['openid', 'profile', 'email', 'offline_access'],
    'is_trusted' => 1, 'access_policy' => 'open',
]);
$cid = (int)$cid;

/** Человек для прогона: свой, чтобы не зависеть от админа и его политик. */
function probeUser(string $email, string $status): int
{
    DB::run('DELETE FROM {users} WHERE email = ?', [$email]);
    $uid = User::create([
        'email' => $email, 'first_name' => 'Проба',
        'password_hash' => password_hash('correct-horse-battery', PASSWORD_ARGON2ID),
        'status' => $status, 'email_verified_at' => $status === 'active' ? now() : null,
    ]);
    $role = Role::findBySlug('user');
    if ($role) Role::assign($uid, (int)$role['id']);
    return $uid;
}

/** Вход паролем в свою банку cookie. Лимиты входа снимаем: вход здесь — подготовка. */
function signIn(string $email, string $jar): array
{
    DB::run("DELETE FROM {rate_limits} WHERE bucket LIKE 'login:%'");
    [, $lp] = http('GET', SSO . '/login', [], [], $jar);
    return http('POST', SSO . '/login', ['_csrf' => csrfOf($lp),
        'identity' => $email, 'password' => 'correct-horse-battery'], [], $jar);
}

$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$authorize = static function (string $jar, array $extra = []) use ($clientId, $challenge): array {
    [, , $loc] = http('GET', SSO . '/authorize?' . http_build_query($extra + [
        'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => 'https://hardening.example/cb',
        'scope' => 'openid profile email offline_access', 'state' => 'st', 'nonce' => 'no',
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]), [], [], $jar);
    parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
    return [$loc, $q];
};
$exchange = static fn(string $code): array => http('POST', SSO . '/token', [
    'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://hardening.example/cb',
    'client_id' => $clientId, 'client_secret' => $secret, 'code_verifier' => $verifier]);
$refresh = static fn(string $rt): array => http('POST', SSO . '/token', [
    'grant_type' => 'refresh_token', 'refresh_token' => $rt, 'client_id' => $clientId, 'client_secret' => $secret]);

$uid = probeUser('core-probe@test.local', 'active');
signIn('core-probe@test.local', $JAR);

echo "── Ответ авторизации называет себя (RFC 9207) ──\n";
[$loc, $q] = $authorize($JAR);
ok(($q['iss'] ?? '') === base_url() && ($q['code'] ?? '') !== '', 'в редиректе с кодом есть iss',
    substr((string)$loc, 0, 120));
[$errLoc, $eq] = $authorize($JAR, ['response_type' => 'token']);
ok(($eq['error'] ?? '') !== '' && ($eq['iss'] ?? '') === base_url(), 'в редиректе с ошибкой тоже',
    substr((string)$errLoc, 0, 120));
$callbackKit = (string)file_get_contents(SSO_ROOT . '/templates/kit/callback.php.tpl');
ok(str_contains($callbackKit, "\$_GET['iss']"), 'набор портала сверяет iss до обмена кода');

echo "\n── Повтор кода отзывает выданное по нему (RFC 6749 §4.1.2) ──\n";
$code1 = (string)$q['code'];
[$st, $body] = $exchange($code1);
$tok = json_decode($body, true) ?: [];
ok($st === 200 && !empty($tok['refresh_token']), 'код обменян, refresh выдан', "status=$st");
[$st] = $exchange($code1);
ok($st === 400, 'второй обмен того же кода отвергнут', "status=$st");
[$st] = $refresh((string)($tok['refresh_token'] ?? ''));
ok($st === 400, 'refresh, выданный по перехваченному коду, больше не работает', "status=$st");
$alive = (int)DB::val('SELECT COUNT(*) FROM {refresh_tokens} WHERE user_id = ? AND client_id = ? AND revoked_at IS NULL',
    [$uid, $cid]);
ok($alive === 0, 'в базе живых refresh этой связки не осталось', "живых: $alive");
$replay = DB::row("SELECT meta FROM {audit_log} WHERE event = 'code.replay' AND on_user_id = ? ORDER BY id DESC LIMIT 1", [$uid]);
ok(str_contains((string)($replay['meta'] ?? ''), 'refresh_revoked'), 'в аудите — сколько отозвано');

echo "\n── Одноразовое гасится один раз ──\n";
DB::run("DELETE FROM {rate_limits} WHERE bucket LIKE 'login:%'");
[, $q2] = $authorize($JAR, ['state' => 's2']);
$row = AuthCode::findByCode((string)($q2['code'] ?? ''));
ok($row !== null, 'код авторизации выдан');
if ($row) {
    ok(AuthCode::consume((int)$row['id']) === true, 'код: первое гашение выигрывает');
    ok(AuthCode::consume((int)$row['id']) === false, 'код: второе гашение проигрывает');
    [$st] = $exchange((string)$q2['code']);
    ok($st === 400, 'погашенный код не обменивается', "status=$st");
}

$link = OneTimeToken::issue($uid, 'login_link');
$peek = OneTimeToken::peek($link, 'login_link');
ok($peek !== null, 'одноразовая ссылка выдана');
if ($peek) {
    ok(OneTimeToken::claim((int)$peek['id']) === true, 'ссылка: первое гашение выигрывает');
    ok(OneTimeToken::claim((int)$peek['id']) === false, 'ссылка: второе гашение проигрывает');
    ok(OneTimeToken::consume($link, 'login_link') === null, 'погашенная ссылка не принимается');
}

DB::run('DELETE FROM {recovery_codes} WHERE user_id = ?', [$uid]);
DB::insert('recovery_codes', ['user_id' => $uid, 'code_hash' => hash('sha256', 'ABCD1234EF56')]);
ok(User::useRecoveryCode($uid, 'abcd-1234-ef56') === true, 'код восстановления: принят (регистр и дефисы не важны)');
ok(User::useRecoveryCode($uid, 'ABCD-1234-EF56') === false, 'код восстановления: второй раз — нет');

echo "\n── Ротация refresh: второй раз — повтор, семья отзывается ──\n";
$rt = RefreshToken::issueFamily($uid, $cid, null, 'openid offline_access');
[$old, $new] = RefreshToken::rotate($rt, $cid);
ok($old !== null && is_string($new) && $new !== '', 'первая ротация выдала новый токен');
[$old2, $new2] = RefreshToken::rotate($rt, $cid);
ok($old2 === null && $new2 === null, 'вторая ротация того же токена отвергнута');
ok(RefreshToken::findValid((string)$new) === null, 'и новый токен семьи отозван — повтор значит утечку');

echo "\n── Человек, ждущий подтверждения почты, держит токены на всех путях ──\n";
$pending = probeUser('core-pending@test.local', 'pending');
$pjar = sys_get_temp_dir() . '/core-pending.txt';
@unlink($pjar);
signIn('core-pending@test.local', $pjar);
[, $pq] = $authorize($pjar);
[$st, $body] = $exchange((string)($pq['code'] ?? ''));
$ptok = json_decode($body, true) ?: [];
ok($st === 200 && !empty($ptok['refresh_token']), 'pending: код обменивается', "status=$st");
[$st, $body] = $refresh((string)($ptok['refresh_token'] ?? ''));
ok($st === 200, 'pending: refresh работает — раньше выкидывал через 15 минут', "status=$st " . substr($body, 0, 80));
$ptok2 = json_decode($body, true) ?: [];
[$st, $body] = http('POST', SSO . '/introspect', ['token' => (string)($ptok2['access_token'] ?? ''),
    'client_id' => $clientId, 'client_secret' => $secret]);
ok($st === 200 && (json_decode($body, true)['active'] ?? false) === true, 'pending: introspect отвечает active',
    substr($body, 0, 80));
User::update($pending, ['status' => 'blocked']);
[$st] = $refresh((string)($ptok2['refresh_token'] ?? ''));
ok($st === 400, 'заблокированный — уже нет', "status=$st");

echo "\n── Ссылка входа из письма: GET показывает, POST входит ──\n";
$mjar = sys_get_temp_dir() . '/core-magic.txt';
@unlink($mjar);
$magic = OneTimeToken::issue($uid, 'login_link');
[$st, $page] = http('GET', SSO . '/magic/' . $magic, [], [], $mjar);
ok($st === 200 && str_contains($page, 'action="/magic/' . $magic . '"') && str_contains($page, 'confirm-form'),
    'GET отдаёт форму подтверждения', "status=$st");
ok(OneTimeToken::peek($magic, 'login_link') !== null, 'GET ссылку не гасит — почтовый сканер её не съест');
[$st, , $loc] = http('POST', SSO . '/magic/' . $magic, ['_csrf' => csrfOf($page)], [], $mjar);
ok($st === 302 && str_contains($loc, '/profile'), 'POST входит', "status=$st loc=$loc");
ok(OneTimeToken::peek($magic, 'login_link') === null, 'после входа ссылка погашена');

echo "\n── Письма со ссылкой входа: лимит на адрес, а не только на IP ──\n";
DB::run("DELETE FROM {rate_limits} WHERE bucket LIKE 'magic:%'");
$before = (int)DB::val("SELECT COUNT(*) FROM {one_time_tokens} WHERE user_id = ? AND type = 'login_link'", [$uid]);
$ljar = sys_get_temp_dir() . '/core-link.txt';
for ($i = 0; $i < 5; $i++) {
    @unlink($ljar);
    /* Каждый заход — «с нового IP»: снимаем только счётчик адреса машины. */
    DB::run("DELETE FROM {rate_limits} WHERE bucket LIKE 'magic:ip:%'");
    [, $lp] = http('GET', SSO . '/login', [], [], $ljar);
    http('POST', SSO . '/login/link', ['_csrf' => csrfOf($lp), 'email' => 'core-probe@test.local'], [], $ljar);
}
$sent = (int)DB::val("SELECT COUNT(*) FROM {one_time_tokens} WHERE user_id = ? AND type = 'login_link'", [$uid]) - $before;
ok($sent === 3, 'на один адрес — не больше трёх писем в час, как ни меняй IP', "выдано: $sent");

echo "\n── TOTP: код годится один раз, лимит — на человека ──\n";
$totpUid = probeUser('core-totp@test.local', 'active');
$totpSecret = 'JBSWY3DPEHPK3PXP';
User::update($totpUid, ['totp_secret' => Crypto::encrypt($totpSecret), 'totp_enabled_at' => now()]);
$step = (int)floor(time() / 30);
ok(Totp::matchStep($totpSecret, Totp::code($totpSecret, $step)) === $step, 'matchStep называет шаг кода');
ok(Totp::matchStep($totpSecret, '000000') === null || Totp::code($totpSecret, $step) === '000000', 'чужой код — не шаг');

$tjar = sys_get_temp_dir() . '/core-totp.txt';
@unlink($tjar);
[, , $loc] = signIn('core-totp@test.local', $tjar);
ok(str_contains((string)$loc, '/login/2fa'), 'после пароля — второй фактор', "loc=$loc");
$code = Totp::code($totpSecret, $step);
[, $p2] = http('GET', SSO . '/login/2fa', [], [], $tjar);
[$st, , $loc] = http('POST', SSO . '/login/2fa', ['_csrf' => csrfOf($p2), 'code' => $code], [], $tjar);
ok($st === 302 && str_contains($loc, '/profile'), 'код принят', "loc=$loc");

@unlink($tjar);
signIn('core-totp@test.local', $tjar);
[, $p2] = http('GET', SSO . '/login/2fa', [], [], $tjar);
[$st, , $loc] = http('POST', SSO . '/login/2fa', ['_csrf' => csrfOf($p2), 'code' => $code], [], $tjar);
ok(!str_contains((string)$loc, '/profile'), 'тот же код второй раз не входит', "loc=$loc");

ok(User::claimTotpStep($totpUid, $step + 1) === true, 'шаг новее принятого — принимается');
ok(User::claimTotpStep($totpUid, $step + 1) === false, 'тот же шаг — нет');
ok(User::claimTotpStep($totpUid, $step) === false, 'шаг старше — нет');

/* Перебор: пять промахов откатывают на пароль, но счётчик на человека живёт
   дальше. Одиннадцатая попытка за четверть часа — отказ, какой бы ни была
   сессия. */
DB::run('DELETE FROM {rate_limits} WHERE bucket = ?', ['2fa:user:' . $totpUid]);
$last = 0;
for ($i = 1; $i <= 11; $i++) {
    if ($i % 4 === 1) {
        @unlink($tjar);
        signIn('core-totp@test.local', $tjar);
    }
    [, $p2] = http('GET', SSO . '/login/2fa', [], [], $tjar);
    [$last] = http('POST', SSO . '/login/2fa', ['_csrf' => csrfOf($p2), 'code' => '000001'], [], $tjar);
    if ($i === 10) ok($last === 302, 'десятый промах — ещё просто промах', "status=$last");
}
ok($last === 429, 'одиннадцатый за 15 минут — отказ, даже с новой сессией', "status=$last");

echo "\n── Согласие: галочка — только когда есть что принимать ──\n";
/* Сторонний портал спрашивает всегда. Документы переключаем на время
   проверки и возвращаем как было: состояние стенда — не наше. */
DB::run('DELETE FROM {clients} WHERE slug = ?', ['hardening-3p']);
[$tpId, $tpClientId] = Client::create([
    'name' => 'Сторонний пробный', 'slug' => 'hardening-3p', 'base_url' => 'https://third.example',
    'redirect_uris' => ['https://third.example/cb'], 'post_logout_uris' => ['https://third.example/'],
    'backchannel_uri' => null, 'allowed_scopes' => ['openid', 'profile'], 'is_trusted' => 0, 'access_policy' => 'open',
]);
$docKeys = [];
foreach (['privacy', 'consent', 'terms'] as $d) {
    foreach (["docs.$d.enabled", "docs.$d.body"] as $k) $docKeys[$k] = Setting::get($k);
}
$setDocs = static function (bool $on): void {
    foreach (['privacy', 'consent', 'terms'] as $d) Setting::set("docs.$d.enabled", '0');
    if ($on) {
        if (trim((string)Setting::get('docs.privacy.body', '')) === '') {
            Setting::set('docs.privacy.body', \App\Core\Legal::template('privacy'));
        }
        Setting::set('docs.privacy.enabled', '1');
    }
    Setting::flush();
};
try {
    http('GET', SSO . '/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => $tpClientId, 'redirect_uri' => 'https://third.example/cb',
        'scope' => 'openid profile', 'state' => 'tp', 'nonce' => 'tp', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]));
    $setDocs(true);
    [, $cp] = http('GET', SSO . '/consent');
    ok(str_contains($cp, 'name="agree"') && str_contains($cp, '/legal/privacy'), 'с документами — галочка со ссылкой');
    [$st, , $loc] = http('POST', SSO . '/consent', ['_csrf' => csrfOf($cp), 'decision' => 'allow']);
    ok(str_contains((string)$loc, '/consent'), 'с документами без галочки — назад на экран', "loc=$loc");
    $setDocs(false);
    [, $cp] = http('GET', SSO . '/consent');
    ok(str_contains($cp, 'Сторонний пробный') && !str_contains($cp, 'name="agree"'),
        'без документов галочки нет — она повторяла кнопку «Продолжить»');
    [$st, , $loc] = http('POST', SSO . '/consent', ['_csrf' => csrfOf($cp), 'decision' => 'allow']);
    ok(str_contains((string)$loc, '/authorize'), 'без документов «Продолжить» — и есть согласие', "loc=$loc");
} finally {
    foreach ($docKeys as $k => $v) {
        if ($v === null) DB::run('DELETE FROM {settings} WHERE `key` = ?', [$k]);
        else Setting::set($k, (string)$v);
    }
    Setting::flush();
    Client::purge((int)$tpId);
}

echo "\n── Возврат только на себя ──\n";
foreach ([
    ['/admin/users/5', '/admin/users/5'],
    ['/profile?tab=security', '/profile?tab=security'],
    ['//evil.example', '/fb'],
    ['/\\evil.example', '/fb'],
    ["/\t/evil.example", '/fb'],
    ["/profile\r\nSet-Cookie: x=1", '/fb'],
    ['https://evil.example/', '/fb'],
    ['javascript:alert(1)', '/fb'],
    ['', '/fb'],
    ['/' . str_repeat('a', 500), '/fb'],
] as [$in, $want]) {
    ok(local_path($in, '/fb') === $want, 'local_path(' . json_encode(mb_strimwidth($in, 0, 40, '…'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ') → ' . $want);
}
$_SERVER['HTTP_REFERER'] = 'https://evil.example//evil.example/x';
ok(referer_path('/fb') === '/fb', 'Referer с путём «//чужой» — на запасной');
$_SERVER['HTTP_REFERER'] = 'https://sso.example.com.evil.example/admin/users?q=1';
ok(referer_path('/fb') === '/admin/users?q=1', 'с чужого хоста берём только путь — уходим всё равно на себя');
unset($_SERVER['HTTP_REFERER']);

echo "\n── Выход — ещё и под стандартным именем события ──\n";
$client = Client::find($cid);
$decode = static function (string $jwt): array {
    $parts = explode('.', $jwt);
    return json_decode((string)Crypto::b64urlDecode($parts[1] ?? ''), true) ?: [];
};
$logout = $decode(IdToken::issueEvent($client, 'logout', ['sub' => 'u1', 'sid' => 's1']));
ok(isset($logout['events']['logout'], $logout['events'][IdToken::BACKCHANNEL_LOGOUT]),
    'событие выхода несёт и наш ключ, и http://schemas.openid.net/event/backchannel-logout');
ok(($logout['sid'] ?? '') === 's1' && ($logout['sub'] ?? '') === 'u1', 'sub и sid — наверху, как требует спецификация');
$other = $decode(IdToken::issueEvent($client, 'user.blocked', ['sub' => 'u1']));
ok(!isset($other['events'][IdToken::BACKCHANNEL_LOGOUT]), 'у других событий стандартного ключа выхода нет');
/* Портал с нашим манифестом — наш набор или мост: он читает `logout`, а
   незнакомый ключ старый набор писал бы в «необработанные» на каждом выходе. */
$ours = $decode(IdToken::issueEvent(['manifest_fetched_at' => now()] + $client, 'logout', ['sub' => 'u1', 'sid' => 's1']));
ok(isset($ours['events']['logout']) && !isset($ours['events'][IdToken::BACKCHANNEL_LOGOUT]),
    'порталу с нашим манифестом — только `logout`: старый набор не копит «необработанные»');
$bcKit = (string)file_get_contents(SSO_ROOT . '/templates/kit/backchannel.php.tpl');
ok(str_contains($bcKit, IdToken::BACKCHANNEL_LOGOUT), 'набор портала знает стандартный ключ');

echo "\n── Discovery обещает то, что есть в токене ──\n";
[$st, $body] = http('GET', SSO . '/.well-known/openid-configuration');
$disc = json_decode($body, true) ?: [];
ok(($disc['authorization_response_iss_parameter_supported'] ?? false) === true, 'iss в ответе авторизации объявлен');
foreach (['access_level', 'access_tier', 'orgs', 'account_number'] as $claim) {
    ok(in_array($claim, $disc['claims_supported'] ?? [], true), "claims_supported: $claim");
}

echo "\n── Удаление организации не оставляет выдач на пустоту ──\n";
$orgId = Org::create('Проба удаления', null, null);
DB::update('clients', ['org_id' => $orgId], 'id = ?', [$cid]);
$orgRole = Role::findBySlug('org_admin');
if ($orgRole) Role::assignScoped($uid, (int)$orgRole['id'], Scope::org($orgId));
$grants = static fn(): int => (int)DB::val('SELECT COUNT(*) FROM {user_roles} WHERE scope_type = ? AND scope_id = ?',
    [Scope::ORG, $orgId]);
ok($grants() === 1, 'роль на организацию выдана');
Org::purge($orgId);
ok($grants() === 0, 'после удаления роли на неё нет — «организация #N» не висит в карточке');
ok(DB::val('SELECT org_id FROM {clients} WHERE id = ?', [$cid]) === null, 'портал остался, но ничей');

/* Уборка за собой: портал и люди прогона не должны жить в базе стенда. */
Client::purge($cid);
foreach (['core-probe@test.local', 'core-pending@test.local', 'core-totp@test.local'] as $e) {
    DB::run('DELETE FROM {users} WHERE email = ?', [$e]);
}
DB::run("DELETE FROM {rate_limits} WHERE bucket LIKE 'magic:%' OR bucket LIKE '2fa:%' OR bucket LIKE 'login:%'");

echo "\n" . ($failed === 0 ? "ЯДРО OK ($passed)\n" : "ЯДРО FAILED: $failed из " . ($passed + $failed) . "\n");
exit($failed === 0 ? 0 : 1);
