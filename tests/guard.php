<?php
/**
 * Защита v4.72: подтверждение почты новыми, блокировка адресов, журнал
 * активности, логин из почты — и вход окном поверх портала с «Вы вошли».
 *
 * Живой случай 21.09: знакомый владельца завёл аккаунт на почтовый псевдоним
 * и настойчиво входил с одного адреса. Закрыть адрес было нечем, заставить
 * подтвердить почту — тоже, а что он делал между входами, не было видно.
 *
 * Запуск: php tests/guard.php  (нужен dev-сервер на :8710; SSO=… — другой)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\ActivityLog;
use App\Core\DB;
use App\Core\EmailGate;
use App\Core\IpBlock;
use App\Core\LoginName;
use App\Core\Setting;
use App\Models\Client;
use App\Models\User;

define('SSO', getenv('SSO') ?: 'http://localhost:8710');
$JAR = sys_get_temp_dir() . '/guard-admin.txt';
@unlink($JAR);
$passed = 0;
$failed = 0;

/* Политики трогаем на время прогона и возвращаем, чем бы он ни кончился.
   Экран первого входа выключен: здесь проверяется не он. */
$was = [];
foreach (['policy.verify_email' => 'off', 'policy.signup_open' => '1', 'policy.welcome_screen' => '0'] as $k => $v) {
    $was[$k] = Setting::get($k);
    Setting::set($k, $v);
}
Setting::flush();
$blockedRules = [];
register_shutdown_function(static function () use ($was, &$blockedRules): void {
    foreach ($was as $k => $v) Setting::set($k, $v);
    Setting::flush();
    /* Заблокированный ::1 после провала уронил бы всю батарею дальше. */
    foreach ($blockedRules as $r) DB::run('DELETE FROM {ip_blocks} WHERE rule = ?', [$r]);
});
DB::run('DELETE FROM {rate_limits}');

$mailDir = SSO_ROOT . '/storage/logs/mail';
/** Последнее письмо на адрес — раскодированным текстом. */
function lastMailTo(string $email): string
{
    global $mailDir;
    $files = (array)glob($mailDir . '/*.eml');
    usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));
    foreach ($files as $f) {
        $m = (string)file_get_contents($f);
        if (stripos($m, 'To: ' . $email) !== false || stripos($m, '<' . $email . '>') !== false) {
            $subject = preg_match('~^Subject: ([^\r\n]+)~m', $m, $s) ? mb_decode_mimeheader($s[1]) : '';
            return $subject . "\n" . quoted_printable_decode($m);
        }
    }
    return '';
}

/** Регистрация формой. Возвращает [статус, Location, id]. */
function register(string $email, string $jar): array
{
    [, $rp] = http('GET', SSO . '/register', [], [], $jar);
    preg_match_all('~name="accept\[\]" value="([^"]+)"~', $rp, $am);
    [$st, , $loc] = http('POST', SSO . '/register', ['_csrf' => csrfOf($rp), 'first_name' => 'Проба',
        'email' => $email, 'password' => 'very-long-password-1', 'accept' => $am[1] ?? []], [], $jar);
    return [$st, $loc, (int)DB::val('SELECT id FROM {users} WHERE email = ?', [$email])];
}

function signIn(string $email, string $password, string $jar): array
{
    DB::run("DELETE FROM {rate_limits} WHERE bucket LIKE 'login:%'");
    [, $lp] = http('GET', SSO . '/login', [], [], $jar);
    return http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => $email, 'password' => $password], [], $jar);
}

$stamp = substr((string)time(), -6);

echo "── Логин из почты для новых аккаунтов ──\n";
$none = static fn(string $l): bool => false;
ok(LoginName::fromEmail('ivan.petrov+news@gmail.com', $none) === 'ivan.petrov', 'метка после «+» отрезана');
ok(LoginName::fromEmail('Иван.Петров@mail.ru', $none) === 'ivan.petrov', 'кириллица — латиницей, регистр — нижний',
    (string)LoginName::fromEmail('Иван.Петров@mail.ru', $none));
ok(LoginName::fromEmail('a@b.c', $none) === 'a01', 'короткое дополнено до допустимой длины',
    (string)LoginName::fromEmail('a@b.c', $none));
$busy = ['ivan' => true, 'ivan2' => true];
ok(LoginName::fromEmail('ivan@x.ru', static fn(string $l): bool => isset($busy[$l])) === 'ivan3',
    'занятое — со следующим свободным номером');
ok(LoginName::fromEmail('admin@x.ru', $none) !== 'admin', 'служебное имя не выдаётся',
    (string)LoginName::fromEmail('admin@x.ru', $none));
DB::run('DELETE FROM {users} WHERE email IN (?, ?)', ["auto$stamp@x.test", "auto$stamp@y.test"]);
$a1 = User::create(['email' => "auto$stamp@x.test", 'first_name' => 'А', 'status' => 'active']);
$a2 = User::create(['email' => "auto$stamp@y.test", 'first_name' => 'Б', 'status' => 'active']);
ok((string)User::find($a1)['login'] === "auto$stamp", 'новый аккаунт получил логин из почты',
    (string)User::find($a1)['login']);
ok((string)User::find($a2)['login'] === "auto{$stamp}2", 'второй с той же частью до @ — с номером',
    (string)User::find($a2)['login']);
$a3 = User::create(['email' => "auto$stamp@z.test", 'login' => "own$stamp", 'first_name' => 'В', 'status' => 'active']);
ok((string)User::find($a3)['login'] === "own$stamp", 'заданный логин не подменяется');
DB::run('DELETE FROM {users} WHERE id IN (?, ?, ?)', [$a1, $a2, $a3]);

echo "\n── Подтверждение почты кодом ──\n";
/* Один режим (v4.74): в письме и код, и ссылка. */
Setting::set('policy.verify_email', 'on');
Setting::flush();
$gjar = sys_get_temp_dir() . '/guard-code.txt';
@unlink($gjar);
$email = "gate$stamp@test.local";
DB::run('DELETE FROM {users} WHERE email = ?', [$email]);
[$st, $loc, $gid] = register($email, $gjar);
ok($st === 302 && str_ends_with($loc, '/verify/wait'), 'после регистрации — экран кода, не кабинет', "$st $loc");
$g = User::find($gid);
ok((int)($g['email_gate'] ?? 0) === 1 && $g['email_verified_at'] === null, 'аккаунт помечен: до подтверждения не пускать');
[$st, , $loc] = http('GET', SSO . '/profile', [], [], $gjar);
ok($st === 302 && !str_contains($loc, '/profile'), 'кабинет закрыт до подтверждения', "$st $loc");
[$st, $wait] = http('GET', SSO . '/verify/wait', [], [], $gjar);
ok($st === 200 && str_contains($wait, 'name="code"'), 'на экране — поле для кода', "status=$st");
$mail = lastMailTo($email);
$code = preg_match('~Код подтверждения: (\d{6})~u', $mail, $m) ? $m[1] : '';
ok($code !== '', 'письмо с кодом ушло');
[$st, , $loc] = http('POST', SSO . '/verify/code', ['_csrf' => csrfOf($wait), 'code' => $code === '000000' ? '111111' : '000000'], [], $gjar);
ok($st === 302 && str_ends_with($loc, '/verify/wait'), 'неверный код — назад на экран', "$st $loc");
ok(User::find($gid)['email_verified_at'] === null, 'неверный код почту не подтвердил');
[$st, , $loc] = http('POST', SSO . '/verify/code', ['_csrf' => csrfOf($wait), 'code' => $code], [], $gjar);
ok($st === 302 && !str_contains($loc, '/verify'), 'верный код — вход', "$st $loc");
$g = User::find($gid);
ok($g['email_verified_at'] !== null && (int)$g['email_gate'] === 0 && $g['status'] === 'active',
    'почта подтверждена, пометка снята, аккаунт активен');
[$st] = http('GET', SSO . '/profile', [], [], $gjar);
ok($st === 200, 'кабинет открылся', "status=$st");
ok(DB::val("SELECT COUNT(*) FROM {audit_log} WHERE event = 'email.verify' AND on_user_id = ?", [$gid]) > 0,
    'в аудите — подтверждение');

echo "\n── Перебор кода упирается в лимит ──\n";
$bjar = sys_get_temp_dir() . '/guard-brute.txt';
@unlink($bjar);
$bemail = "brute$stamp@test.local";
DB::run('DELETE FROM {users} WHERE email = ?', [$bemail]);
[, , $bid] = register($bemail, $bjar);
$bcode = preg_match('~Код подтверждения: (\d{6})~u', lastMailTo($bemail), $m) ? $m[1] : '';
$wrong = $bcode === '000000' ? '111111' : '000000';
for ($i = 0; $i < 10; $i++) {
    [, $wp] = http('GET', SSO . '/verify/wait', [], [], $bjar);
    http('POST', SSO . '/verify/code', ['_csrf' => csrfOf($wp), 'code' => $wrong], [], $bjar);
}
[, $wp] = http('GET', SSO . '/verify/wait', [], [], $bjar);
[$st, , $loc] = http('POST', SSO . '/verify/code', ['_csrf' => csrfOf($wp), 'code' => $bcode], [], $bjar);
ok($st === 429 && User::find($bid)['email_verified_at'] === null,
    'после десяти промахов не проходит и верный код', "$st $loc");

echo "\n── То же письмо — ссылкой вместо кода ──\n";
/* Значения 4.72 («link», «code») — это тоже «включено»: у установки, где правило
   уже стояло, оно не выключится само после обновления. */
foreach (['link', 'code'] as $legacy) {
    Setting::set('policy.verify_email', $legacy);
    Setting::flush();
    ok(EmailGate::mode() === 'on', "прежнее значение «{$legacy}» — включено");
}
Setting::flush();
DB::run('DELETE FROM {rate_limits}');
$ljar = sys_get_temp_dir() . '/guard-link.txt';
@unlink($ljar);
$lemail = "link$stamp@test.local";
DB::run('DELETE FROM {users} WHERE email = ?', [$lemail]);
[$st, $loc, $lid] = register($lemail, $ljar);
ok($st === 302 && str_ends_with($loc, '/verify/wait'), 'после регистрации — экран ожидания', "$st $loc");
[, $wait] = http('GET', SSO . '/verify/wait', [], [], $ljar);
ok(str_contains($wait, 'name="code"'), 'на экране — поле для кода, ссылка из письма тоже годится');
$lmail = lastMailTo($lemail);
$token = preg_match('~/verify/([A-Za-z0-9_\-]{20,})~', $lmail, $m) ? $m[1] : '';
ok($token !== '' && (bool)preg_match('~Код подтверждения: \d{6}~u', $lmail), 'в одном письме — и код, и ссылка');
/* GET показывает кнопку, POST подтверждает — почтовый сканер ссылку не погасит (v4.70). */
[$st, $confirm] = http('GET', SSO . '/verify/' . $token, [], [], $ljar);
ok($st === 200 && User::find($lid)['email_verified_at'] === null, 'открытие ссылки само по себе не подтверждает', "status=$st");
[$st, , $loc] = http('POST', SSO . '/verify/' . $token, ['_csrf' => csrfOf($confirm)], [], $ljar);
ok($st === 302 && !str_contains($loc, '/verify'), 'кнопка на странице ссылки подтверждает и входит', "$st $loc");
$l = User::find($lid);
ok($l['email_verified_at'] !== null && (int)$l['email_gate'] === 0, 'почта подтверждена ссылкой');

echo "\n── Действующих и пришедших иначе требование не касается ──\n";
$oemail = "old$stamp@test.local";
DB::run('DELETE FROM {users} WHERE email = ?', [$oemail]);
$oid = User::create(['email' => $oemail, 'first_name' => 'Старый', 'status' => 'active',
    'password_hash' => password_hash('correct-horse-battery', PASSWORD_ARGON2ID)]);
$ojar = sys_get_temp_dir() . '/guard-old.txt';
@unlink($ojar);
[$st, , $loc] = signIn($oemail, 'correct-horse-battery', $ojar);
ok($st === 302 && !str_contains($loc, '/verify'), 'действующий с неподтверждённой почтой входит как раньше', "$st $loc");
/* Пометку ставит только регистрация формой: создание администратором, соцвход,
   приглашение и API идут через User::create без неё. */
ok((int)User::find($oid)['email_gate'] === 0, 'аккаунт, заведённый не формой регистрации, без пометки');

echo "\n── Выключенная политика отпускает ──\n";
$hemail = "held$stamp@test.local";
DB::run('DELETE FROM {users} WHERE email = ?', [$hemail]);
$hid = User::create(['email' => $hemail, 'first_name' => 'Ждёт', 'status' => 'pending', 'email_gate' => 1,
    'password_hash' => password_hash('correct-horse-battery', PASSWORD_ARGON2ID)]);
$hjar = sys_get_temp_dir() . '/guard-held.txt';
@unlink($hjar);
[, , $loc] = signIn($hemail, 'correct-horse-battery', $hjar);
ok(str_ends_with($loc, '/verify/wait'), 'при включённой политике помеченный ждёт подтверждения', $loc);
ok(EmailGate::holds(User::find($hid)), 'EmailGate::holds — да');
Setting::set('policy.verify_email', 'off');
Setting::flush();
@unlink($hjar);
[, , $loc] = signIn($hemail, 'correct-horse-battery', $hjar);
ok(!str_contains($loc, '/verify'), 'политику выключили — входит', $loc);
ok(!EmailGate::holds(User::find($hid)), 'EmailGate::holds — нет');

echo "\n── Блокировка адресов: разбор ──\n";
$p = IpBlock::parse('198.51.100.66');
ok(is_array($p) && $p['rule'] === '198.51.100.66' && $p['from'] === $p['to'], 'одиночный адрес — диапазон из одного');
$p = IpBlock::parse('198.51.100.77/24');
ok(is_array($p) && $p['rule'] === '198.51.100.0/24' && inet_ntop($p['to']) === '198.51.100.255',
    'подсеть приводится к началу', is_array($p) ? $p['rule'] : $p);
ok(is_string(IpBlock::parse('10.0.0.0/7')), 'шире /8 не принимается');
ok(is_array(IpBlock::parse('10.0.0.0/8')), '/8 принимается');
ok(is_string(IpBlock::parse('2a01:db8::/31')) && is_array(IpBlock::parse('2a01:db8::/48')), 'IPv6: от /32');
ok(is_string(IpBlock::parse('198.51.100')) && is_string(IpBlock::parse('1.2.3.4/x')), 'мусор — ошибка словами');
$r = IpBlock::parse('198.51.100.0/24');
ok(IpBlock::inRange((string)inet_pton('198.51.100.66'), $r['from'], $r['to'])
    && !IpBlock::inRange((string)inet_pton('198.51.101.1'), $r['from'], $r['to'])
    && !IpBlock::inRange((string)inet_pton('::1'), $r['from'], $r['to']), 'попадание в диапазон — только своё');

echo "\n── Блокировка адресов: в деле ──\n";
DB::run("DELETE FROM {ip_blocks} WHERE rule IN ('::1', '127.0.0.1', '203.0.113.9')");
$_SERVER['REMOTE_ADDR'] = '::1';
ok(IpBlock::add('::1', 'проба', 0, null) !== null, 'свой адрес заблокировать нельзя');
unset($_SERVER['REMOTE_ADDR']);
$blockedRules = ['::1', '127.0.0.1'];
ok(IpBlock::add('::1', 'проба', 1, null) === null && IpBlock::add('127.0.0.1', 'проба', 1, null) === null, 'правила добавлены');
ok(IpBlock::add('::1', 'ещё раз', 0, null) !== null, 'дубль не добавляется');
[$st, $body] = http('GET', SSO . '/login', [], [], sys_get_temp_dir() . '/guard-blocked.txt');
ok($st === 403 && str_contains($body, 'доступ к сервису закрыт'), 'с заблокированного адреса — 403 на входе', "status=$st");
[$st] = http('POST', SSO . '/token', ['grant_type' => 'client_credentials']);
ok($st === 403, 'и на протокольных точках', "status=$st");
$hits = (int)DB::val("SELECT SUM(hits) FROM {ip_blocks} WHERE rule IN ('::1', '127.0.0.1')");
ok($hits >= 2, 'попытки сосчитаны', "hits=$hits");
DB::run("UPDATE {ip_blocks} SET expires_at = ? WHERE rule IN ('::1', '127.0.0.1')", [now_plus(-60)]);
[$st] = http('GET', SSO . '/login', [], [], sys_get_temp_dir() . '/guard-blocked.txt');
ok($st === 200, 'истёкшее правило не действует', "status=$st");
foreach (DB::all("SELECT id FROM {ip_blocks} WHERE rule IN ('::1', '127.0.0.1')") as $b) IpBlock::remove((int)$b['id']);
$blockedRules = [];
ok(DB::val("SELECT COUNT(*) FROM {audit_log} WHERE event = 'admin.ip.unblock'") > 0, 'снятие — в аудите');

echo "\n── Журнал активности ──\n";
DB::run("DELETE FROM {users} WHERE email = 'admin-activity@test.local'");
DB::run('UPDATE {users} SET totp_secret = NULL, totp_enabled_at = NULL WHERE email = ?', ['admin@test.local']);
[$st, , $loc] = signIn('admin@test.local', 'correct-horse-battery', $JAR);
ok($st === 302, 'админ вошёл', "$st $loc");
$admin = User::findByEmail('admin@test.local');
$mark = (int)DB::val('SELECT COALESCE(MAX(id), 0) FROM {activity_log}');
$secretTok = str_repeat('Zq9', 12);
http('GET', SSO . '/magic/' . $secretTok);
http('GET', SSO . '/authorize?client_id=cli_nope&state=S3CRET-STATE&code_challenge=abc');
/* Существующий файл тестовый роутер (как и Apache на боевой) отдаёт мимо
   приложения — такой запрос журнал не увидит в любом случае. Проверяем то, что
   до приложения доходит: несуществующий файл под /assets/ и обычную страницу. */
http('GET', SSO . '/assets/css/nope-probe.css');
http('GET', SSO . '/nope-probe-page');
http('GET', SSO . '/admin/users');
$rows = DB::all('SELECT * FROM {activity_log} WHERE id > ? ORDER BY id', [$mark]);
$paths = array_column($rows, 'path');
ok(in_array('/magic/…', $paths, true) && !str_contains(implode(' ', $paths), $secretTok), 'токен из адреса вырезан',
    implode(' ', $paths));
$auth = array_values(array_filter($rows, static fn($r) => $r['path'] === '/authorize'))[0] ?? null;
ok($auth !== null && $auth['query_keys'] === 'client_id,code_challenge,state', 'из строки запроса — только имена',
    (string)($auth['query_keys'] ?? 'нет строки'));
ok(!str_contains((string)json_encode($rows), 'S3CRET'), 'значения параметров не записаны');
ok(!in_array('/assets/css/nope-probe.css', $paths, true) && in_array('/nope-probe-page', $paths, true),
    'статика не пишется, а обычный адрес — пишется', implode(' ', $paths));
$adm = array_values(array_filter($rows, static fn($r) => $r['path'] === '/admin/users'))[0] ?? null;
ok($adm !== null && (int)$adm['user_id'] === (int)$admin['id'] && (int)$adm['status'] === 200,
    'запрос вошедшего — с человеком и ответом');
ok(ActivityLog::label('POST', '/login') === 'Вход паролем' && ActivityLog::label('GET', '/x/y') === 'GET /x/y',
    'действия — словами, незнакомое — как есть');
DB::insert('activity_log', ['created_at' => now_plus(-40 * 86400), 'user_id' => null, 'ip' => inet_pton('203.0.113.9'),
    'method' => 'GET', 'path' => '/old', 'status' => 200, 'ms' => 1]);
ActivityLog::purge(30);
ok((int)DB::val("SELECT COUNT(*) FROM {activity_log} WHERE path = '/old'") === 0, 'старше срока — удаляется');
ok((int)DB::val('SELECT COUNT(*) FROM {activity_log} WHERE id > ?', [$mark]) >= 3, 'свежее остаётся');

foreach (['/admin/logs/requests' => 'Запросы', '/admin/ip/' . rawurlencode('::1') => 'Адрес ::1',
          '/admin/users/' . (int)$admin['id'] . '/activity' => 'Адреса', '/admin/blocks' => 'Блокировки'] as $url => $needle) {
    [$st, $body] = http('GET', SSO . $url);
    ok($st === 200 && pageClean($body) && str_contains($body, $needle), "страница $url", "status=$st");
}
$csrf = csrfOf(http('GET', SSO . '/admin/blocks')[1]);
[$st] = http('POST', SSO . '/admin/blocks', ['_csrf' => $csrf, 'rule' => '203.0.113.9', 'reason' => 'проба', 'days' => '7']);
$row = DB::row("SELECT * FROM {ip_blocks} WHERE rule = '203.0.113.9'");
ok($st === 302 && $row !== null && $row['expires_at'] !== null, 'блокировка из админки — со сроком');
[$st, $body] = http('GET', SSO . '/admin/ip/203.0.113.9');
ok(str_contains($body, '/admin/blocks/' . (int)($row['id'] ?? 0) . '/remove'), 'на странице адреса — снять блокировку');
http('POST', SSO . '/admin/blocks/' . (int)($row['id'] ?? 0) . '/remove', ['_csrf' => $csrf]);
ok(DB::row("SELECT id FROM {ip_blocks} WHERE rule = '203.0.113.9'") === null, 'снята из админки');

echo "\n── Как портал открывает вход ──\n";
DB::run('DELETE FROM {clients} WHERE slug = ?', ['guard']);
[$cid, $clientId, $secret] = Client::create([
    'name' => 'Проба входа', 'slug' => 'guard', 'base_url' => 'https://guard.example',
    'redirect_uris' => ['https://guard.example/cb'], 'post_logout_uris' => [],
    'backchannel_uri' => null, 'allowed_scopes' => ['openid', 'profile', 'email'],
    'is_trusted' => 1, 'access_policy' => 'open',
]);
$cid = (int)$cid;
[$st, $body, , , $hdr] = http('GET', SSO . '/portal/ui?client_id=' . $clientId, [], [], sys_get_temp_dir() . '/guard-anon.txt');
$ui = json_decode($body, true) ?: [];
ok($st === 200 && array_key_exists('display', $ui) && $ui['display'] === null, 'не настроено — «как решил портал»', $body);
ok((bool)preg_match('~^Access-Control-Allow-Origin: \*~mi', $hdr), 'ответ читается со страницы портала (CORS)');
[, $lookPage] = http('GET', SSO . '/admin/clients/' . $cid . '/look');
ok(str_contains($lookPage, 'name="login_display"') && str_contains($lookPage, 'name="login_welcome"'),
    'в «Экране входа» — как открывается вход и «Вы вошли»');
[$st] = http('POST', SSO . '/admin/clients/' . $cid . '/look', ['_csrf' => csrfOf($lookPage), 'look_mode' => 'off',
    'methods_mode' => 'all', 'login_display' => 'popup', 'login_welcome' => '1']);
$c = Client::find($cid);
ok($st === 302 && $c['login_display'] === 'popup' && (int)$c['login_welcome'] === 1, 'сохранено: окно и «Вы вошли»',
    "status=$st display=" . var_export($c['login_display'], true));
$ui = json_decode(http('GET', SSO . '/portal/ui?client_id=' . $clientId)[1], true) ?: [];
ok(($ui['display'] ?? null) === 'popup' && !empty($ui['texts']['show']), 'портал узнаёт: окном, с подписями карточки');
http('POST', SSO . '/admin/clients/' . $cid . '/look', ['_csrf' => csrfOf($lookPage), 'look_mode' => 'off',
    'methods_mode' => 'all', 'login_display' => 'evil', 'login_welcome' => '1']);
ok(Client::find($cid)['login_display'] === null, 'незнакомое значение — «как решил портал»');
$ui = json_decode(http('GET', SSO . '/portal/ui?client_id=cli_nobody')[1], true) ?: [];
ok(array_key_exists('display', $ui) && $ui['display'] === null && !isset($ui['app']), 'неизвестный портал — ничего о нём');
$js = (string)file_get_contents(SSO_ROOT . '/public/assets/js/sso.js');
ok(str_contains($js, "/portal/ui?client_id=") && str_contains($js, "ui.display === 'page'")
    && !preg_match('~setAttribute\(\s*[\'"]style~', $js), 'sso.js спрашивает настройку и не пишет атрибут style');
/* Архив 24.09: старт входа под CSRF, токен — в форме кнопки, а скрипт слал
   пустой POST. Поля формы обязаны уходить вместе со стартом. */
ok(str_contains($js, "new FormData(form).forEach(function (v, k) { if (typeof v === 'string') body.append(k, v); });")
    && str_contains($js, "headers: { 'X-Requested-With': 'sso.js' }, body: form ? body : undefined"),
    'sso.js отправляет со стартом входа поля формы кнопки (CSRF-токен портала)');

echo "\n── «Вы вошли» перед возвратом ──\n";
$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$authUrl = static fn(array $extra = []): string => SSO . '/authorize?' . http_build_query($extra + [
    'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => 'https://guard.example/cb',
    'scope' => 'openid profile', 'state' => 'st-guard', 'nonce' => 'n', 'code_challenge' => $challenge,
    'code_challenge_method' => 'S256']);
$wemail = "welcome$stamp@test.local";
DB::run('DELETE FROM {users} WHERE email = ?', [$wemail]);
User::create(['email' => $wemail, 'first_name' => 'Гость', 'status' => 'active', 'email_verified_at' => now(),
    'password_hash' => password_hash('correct-horse-battery', PASSWORD_ARGON2ID)]);
$wjar = sys_get_temp_dir() . '/guard-welcome.txt';
@unlink($wjar);
/* Как по-настоящему: портал ведёт на /authorize, оттуда — на вход, после входа — обратно. */
[$st, , $loc] = http('GET', $authUrl(), [], [], $wjar);
ok($st === 302 && str_contains($loc, '/login'), 'не вошёл — на вход', "$st $loc");
[$st, , $loc] = signIn($wemail, 'correct-horse-battery', $wjar);
ok($st === 302 && str_contains($loc, '/authorize'), 'после входа — назад в авторизацию', "$st $loc");
[$st, $body] = http('GET', str_starts_with($loc, 'http') ? $loc : SSO . $loc, [], [], $wjar);
ok($st === 200 && str_contains($body, 'data-welcome-go') && str_contains($body, 'Проба входа'),
    'после настоящего входа — «Вы вошли» со знаком портала', "status=$st");
ok((bool)preg_match('~href="https://guard\.example/cb\?code=[^"]+state=st-guard~', html_entity_decode($body)),
    'кнопка ведёт в портал с кодом и state');
ok(str_contains($body, 'data-handoff') && str_contains($body, 'data-delay="1400"') && !str_contains($body, 'data-origin'),
    'переход сам через 1,4 с — общим механизмом app.js, страницей, не во фрейм');
[$st, , $loc] = http('GET', $authUrl(['state' => 's2']), [], [], $wjar);
ok($st === 302 && str_starts_with($loc, 'https://guard.example/cb'), 'повторный заход уже вошедшего — сразу в портал', "$st");
Client::update($cid, ['login_welcome' => 0]);
@unlink($wjar);
http('GET', $authUrl(), [], [], $wjar);
[, , $loc] = signIn($wemail, 'correct-horse-battery', $wjar);
[$st, , $loc] = http('GET', str_starts_with($loc, 'http') ? $loc : SSO . $loc, [], [], $wjar);
ok($st === 302 && str_starts_with($loc, 'https://guard.example/cb'), 'выключено — как было, без остановки', "$st");
Client::update($cid, ['login_welcome' => 1]);
@unlink($wjar);
signIn($wemail, 'correct-horse-battery', $wjar);
[$st, , $loc] = http('GET', $authUrl(), [], [], $wjar);
ok($st === 302 && str_starts_with($loc, 'https://guard.example/cb'), 'вход в кабинет SSO, а не ради портала, — без «Вы вошли»', "$st");
/* Вход начат с другого портала — «Вы вошли» этого портала не про него. */
DB::run('DELETE FROM {clients} WHERE slug = ?', ['guard-other']);
[$oid2, $otherId] = Client::create([
    'name' => 'Другой портал', 'slug' => 'guard-other', 'base_url' => 'https://other.example',
    'redirect_uris' => ['https://other.example/cb'], 'post_logout_uris' => [],
    'backchannel_uri' => null, 'allowed_scopes' => ['openid'], 'is_trusted' => 1, 'access_policy' => 'open',
]);
@unlink($wjar);
http('GET', $authUrl(['client_id' => $otherId, 'redirect_uri' => 'https://other.example/cb', 'scope' => 'openid']), [], [], $wjar);
signIn($wemail, 'correct-horse-battery', $wjar);
[$st, , $loc] = http('GET', $authUrl(), [], [], $wjar);
ok($st === 302 && str_starts_with($loc, 'https://guard.example/cb'), 'вошёл ради другого портала — здесь без «Вы вошли»', "$st");
DB::run('DELETE FROM {clients} WHERE id = ?', [(int)$oid2]);
/* prompt=none открыт только своим порталам (same_site_family) — иначе ответ
   с ошибкой тоже 302 в портал, и проверка прошла бы при любом коде. */
Client::update($cid, ['same_site_family' => 1]);
@unlink($wjar);
http('GET', $authUrl(), [], [], $wjar);
[, , $loc] = signIn($wemail, 'correct-horse-battery', $wjar);
[$st, , $loc] = http('GET', $authUrl(['prompt' => 'none']), [], [], $wjar);
ok($st === 302 && str_starts_with($loc, 'https://guard.example/cb?code='), 'prompt=none — всегда без остановки', "$st $loc");
[$st, $body] = http('GET', SSO . '/admin/clients/' . $cid . '/look/preview?screen=welcome');
ok($st === 200 && str_contains($body, 'data-welcome-go') && !str_contains($body, 'data-handoff'),
    'предпросмотр «Вы вошли» в админке — без перехода');

DB::run('DELETE FROM {clients} WHERE id = ?', [$cid]);
DB::run('DELETE FROM {users} WHERE email IN (?, ?, ?, ?, ?, ?)', [$email, $bemail, $lemail, $oemail, $hemail, $wemail]);

echo "\n" . ($failed === 0 ? "✓ все $passed проверок" : "✗ провалов: $failed из " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
