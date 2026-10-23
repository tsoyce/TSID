<?php
/**
 * Боты на регистрации (v4.95).
 *
 * Замер на боевой 23.10: с 17.10 — 64 регистрации ботов, по одной в час, с
 * девяти хостинговых подсетей, каждый раз с нового адреса; почта — чужие
 * настоящие ящики. У всех 64 — браузер десятилетней давности, у всех 19 живых
 * людей за то же время — свежий. Удалить ботов можно было только по одному,
 * закрыть подсети — только набирая их руками. Здесь проверяется, что:
 *   · устаревший браузер узнаётся по всем строкам из замера, а свежие — нет;
 *   · регистрация с него отклоняется до создания аккаунта и письма, отказ — в
 *     аудите; переключатель в настройках это выключает;
 *   · «Похоже на ботов» собирает только тех, кто сам завёл аккаунт, не
 *     подтвердил почту и не пришёл в портал; сильный признак — галочкой;
 *   · удаление пачкой трогает только кандидатов — подставленный в форму
 *     живой человек остаётся; подсети закрываются тем же нажатием;
 *   · «Адреса» — вкладка журналов: подсети с галочками, правила снимаются пачкой.
 *
 * Запуск: php tests/admin_bots.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\BotSignal;
use App\Core\DB;
use App\Core\IpBlock;
use App\Core\Setting;
use App\Models\User;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$JAR = sys_get_temp_dir() . '/admin-bots.txt';
@unlink($JAR);
$stamp = bin2hex(random_bytes(3));
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);
$nets = ['192.0.2.0/24', '203.0.113.0/24', '198.51.100.0/24'];
$audit = [];

$was = [];
foreach (['policy.signup_open' => '1', 'policy.refuse_outdated' => '1', 'policy.welcome_screen' => '0',
          'policy.verify_email' => 'off'] as $k => $v) {
    $was[$k] = Setting::get($k);
    Setting::set($k, $v);
}
Setting::flush();

/* Строки браузера из замера на боевой — только сами строки, без адресов и почты. */
const OLD_UA = [
    'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/39.0.2171.95 Safari/537.36',
    'Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/31.0.1650.59 Safari/537.36',
    'Mozilla/5.0 (Windows NT 6.1; WOW64; Trident/7.0; MDDCJS; rv:11.0) like Gecko',
    'Mozilla/5.0 (Windows NT 10.0; WOW64; Trident/7.0; Touch; MDDCJS; rv:11.0) like Gecko',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_5) AppleWebKit/600.8.9 (KHTML, like Gecko) Version/8.0.8 Safari/600.8.9',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_9_2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/36.0.1944.0 Safari/537.36',
    'Mozilla/5.0 (Linux; Android 4.4.4; XT1080 Build/SU6-7.2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/45.0.2454.94 Mobile Safari/537.36',
    'Mozilla/5.0 (iPad; U; CPU OS 3_2 like Mac OS X; en-us) AppleWebKit/531.21.10 (KHTML, like Gecko) Version/4.0.4 Mobile/7B334b Safari/531.21.10',
    'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Win64; x64; Trident/5.0)',
    'Mozilla/5.0 (Linux; U; Android 4.4.3; en-us; KFSAWA Build/KTU84M) AppleWebKit/537.36 (KHTML, like Gecko) Silk/3.68 like Chrome/39.0.2171.93 Safari/537.36',
    'Mozilla/5.0 (iPad; CPU OS 8_3 like Mac OS X) AppleWebKit/600.1.4 (KHTML, like Gecko) Version/8.0 Mobile/12F69 Safari/600.1.4',
    'Mozilla/5.0 (Linux; U; Android 4.4.2; en-us; LG-V410/V41010d Build/KOT49I.V41010d) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/30.0.1599.103 Safari/537.36',
    'Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2224.3 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/42.0.2311.135 Safari/537.36 Edge/12.10136',
    'Mozilla/5.0 (X11; Linux i686; rv:40.0) Gecko/20100101 Firefox/40.0',
    'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:37.0) Gecko/20100101 Firefox/37.0',
    'Mozilla/5.0 (X11; CrOS armv7l 6946.86.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.91 Safari/537.36',
    'Mozilla/5.0 (Linux; Android 5.0; SAMSUNG-SM-G900A Build/LRX21T) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/2.1 Chrome/34.0.1847.76 Mobile Safari/537.36',
    'Mozilla/5.0 (compatible; MSIE 8.0; Windows NT 5.1; WOW64; Trident/4.0)',
    'Mozilla/5.0 (Windows NT 6.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.89 Safari/537.36',
    'Mozilla/5.0 (Linux; U; Android 4.0.3; en-us; KFOT Build/IML74K) AppleWebKit/534.30 (KHTML, like Gecko) Silk/2.2 Safari/534.30',
];
const NEW_UA = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/27.2 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Safari/605.1.15',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 YaBrowser/26.4.0.0 Safari/537.36',
    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36',
    'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/28.0 Chrome/130.0.0.0 Mobile Safari/537.36',
    'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36',
];

/** Аккаунт как после регистрации формой: пользователь и событие `register` с адресом и браузером. */
$reg = static function (string $tag, string $ip, string $ua, array $extra = []) use ($stamp, &$audit): int {
    $uid = User::create($extra + ['email' => "bot-$tag-$stamp@example.com", 'first_name' => 'Проба', 'status' => 'pending']);
    $audit[] = DB::insert('audit_log', ['created_at' => now(), 'actor_user_id' => $uid, 'on_user_id' => $uid,
        'event' => 'register', 'ip' => inet_pton($ip), 'user_agent' => $ua, 'meta' => null]);
    return $uid;
};

try {
    echo "── Устаревший браузер ──\n";
    $missed = array_values(array_filter(OLD_UA, static fn(string $ua): bool => BotSignal::outdated($ua) === null));
    ok($missed === [], 'все строки браузера ботов из замера узнаны', implode(' | ', $missed));
    $wrong = array_values(array_filter(NEW_UA, static fn(string $ua): bool => BotSignal::outdated($ua) !== null));
    ok($wrong === [], 'свежие браузеры людей — не боты, включая Chrome 109 на Windows 7',
        implode(' | ', array_map(static fn($u) => (string)BotSignal::outdated($u), $wrong)));
    ok(BotSignal::outdated(OLD_UA[0]) === 'Chrome 39' && BotSignal::outdated(OLD_UA[2]) === 'Internet Explorer'
        && BotSignal::outdated(OLD_UA[20]) === 'Android 4' && BotSignal::outdated(OLD_UA[7]) === 'iOS 3',
        'причина — словами: «Chrome 39», «Internet Explorer», «Android 4»');
    ok(BotSignal::outdated('') === null, 'пустая строка — не признак: так ходят свои проверки');

    echo "\n── Отказ на регистрации ──\n";
    $signup = static function (string $email, string $ua): array {
        DB::run("DELETE FROM {rate_limits} WHERE bucket LIKE 'register:%'");
        $jar = sys_get_temp_dir() . '/admin-bots-reg.txt';
        @unlink($jar);
        [$st, , $id, $body] = signup(SSO, $email, [], $jar, ['User-Agent: ' . $ua]);
        @unlink($jar);
        return [$st, $body, $id];
    };
    $refusedBefore = (int)DB::val("SELECT COUNT(*) FROM {audit_log} WHERE event = 'register.refused'");
    [$st, $body, $id] = $signup("old-$stamp@example.com", OLD_UA[0]);
    ok($st === 200 && $id === 0 && str_contains($body, 'Ваш браузер устарел (Chrome 39)'),
        'с устаревшего браузера аккаунт не заводится, человеку сказано почему', "status $st, id $id");
    ok((int)DB::val("SELECT COUNT(*) FROM {audit_log} WHERE event = 'register.refused'") === $refusedBefore + 1,
        'отказ записан в аудит');
    [$st, , $id] = $signup("new-$stamp@example.com", NEW_UA[0]);
    ok($id > 0, 'со свежего браузера — регистрируется', "status $st");
    if ($id > 0) User::purge($id);
    Setting::set('policy.refuse_outdated', '0');
    Setting::flush();
    [, , $id] = $signup("off-$stamp@example.com", OLD_UA[0]);
    ok($id > 0, 'переключатель выключен — устаревшим тоже можно');
    if ($id > 0) User::purge($id);
    Setting::set('policy.refuse_outdated', '1');
    Setting::flush();

    echo "\n── Похоже на ботов ──\n";
    $a = $reg('old', '192.0.2.10', OLD_UA[2]);
    $b = $reg('crowd1', '203.0.113.11', NEW_UA[0]);
    $c = $reg('crowd2', '203.0.113.12', NEW_UA[0]);
    $d = $reg('crowd3', '203.0.113.13', NEW_UA[1]);
    $e = $reg('alone', '198.51.100.200', NEW_UA[0]);
    $f = $reg('human', '198.51.100.201', NEW_UA[0], ['email_verified_at' => now(), 'status' => 'active']);
    $g = $reg('portal', '198.51.100.202', NEW_UA[0]);
    $anyClient = (int)DB::val('SELECT id FROM {clients} ORDER BY id LIMIT 1');
    DB::insert('user_client_access', ['user_id' => $g, 'client_id' => $anyClient, 'status' => 'active', 'created_at' => now()]);
    $h = User::create(['email' => "bot-import-$stamp@example.com", 'first_name' => 'Проба', 'status' => 'pending']);
    $s = [];
    foreach (BotSignal::suspects() as $row) $s[(int)$row['id']] = $row;
    ok(isset($s[$a], $s[$b], $s[$e]) && !isset($s[$f]) && !isset($s[$g]) && !isset($s[$h]),
        'в списке — сами завели и не подтвердили; подтвердивший, пришедший в портал и перенесённый — нет');
    ok(($s[$a]['sure'] ?? false) && in_array('браузер: Internet Explorer', $s[$a]['why'] ?? [], true),
        'устаревший браузер — сильный признак, галочка сразу');
    ok(($s[$b]['sure'] ?? false) && in_array('с подсети ещё 2', $s[$b]['why'] ?? [], true),
        'три регистрации с одной подсети — сильный признак', json_encode($s[$b]['why'] ?? null, JSON_UNESCAPED_UNICODE));
    ok(isset($s[$e]) && !$s[$e]['sure'], 'одна регистрация со свежего браузера — показана, но без галочки');
    IpBlock::add('198.51.100.200', 'проба', 0, null);
    $s2 = array_column(BotSignal::suspects(), null, 'id');
    ok(($s2[$e]['sure'] ?? false) && in_array('адрес закрыт', $s2[$e]['why'] ?? [], true), 'закрытый адрес — сильный признак');
    DB::run('DELETE FROM {ip_blocks} WHERE rule = ?', ['198.51.100.200']);
    ok(BotSignal::suspectCount() >= 5, 'счётчик для строки над списком людей');

    echo "\n── Страница и удаление пачкой ──\n";
    DB::run('UPDATE {users} SET totp_secret = NULL, totp_enabled_at = NULL WHERE email = ?', ['admin@test.local']);
    DB::run('DELETE FROM {rate_limits}');
    [, $lp] = http('GET', SSO . '/login');
    http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => 'admin@test.local', 'password' => 'correct-horse-battery']);
    [$st, $page] = http('GET', SSO . '/admin/users/bots');
    ok($st === 200 && pageClean($page) && str_contains($page, 'Похоже на ботов'), 'страница открывается', "status $st");
    ok((bool)preg_match('~name="ids\[\]" value="' . $a . '"[^>]*checked~', $page)
        && (bool)preg_match('~name="ids\[\]" value="' . $e . '"\s+aria-label="[^"]*">~', $page),
        'сильный признак — отмечен, слабый — нет');
    ok(str_contains($page, 'data-bulk') && str_contains($page, 'data-check-all') && str_contains($page, 'name="block" value="1"'),
        'галочки, «выбрать всех», «удалить и закрыть их подсети»');
    [, $list] = http('GET', SSO . '/admin/users');
    ok(str_contains($list, 'href="/admin/users/bots"'), 'со списка людей — строка к ботам');

    http('POST', SSO . '/admin/users/bots', ['_csrf' => csrfOf($page), 'ids' => [$a, $b, $c, $d, $f, $g], 'block' => '1']);
    $left = static fn(int $id): bool => DB::val('SELECT id FROM {users} WHERE id = ?', [$id]) !== null;
    ok(!$left($a) && !$left($b) && !$left($d), 'выбранные боты удалены');
    ok($left($f) && $left($g), 'подставленные в форму живые люди не тронуты: удаляются только кандидаты');
    ok($left($e), 'невыбранный — на месте');
    $rules = array_column(IpBlock::all(), 'rule');
    ok(in_array('192.0.2.0/24', $rules, true) && in_array('203.0.113.0/24', $rules, true),
        'подсети удалённых закрыты тем же нажатием', implode(', ', $rules));
    ok((string)DB::val("SELECT meta FROM {audit_log} WHERE event = 'admin.bulk.purge' ORDER BY id DESC LIMIT 1") !== ''
        && str_contains((string)DB::val("SELECT meta FROM {audit_log} WHERE event = 'admin.bulk.purge' ORDER BY id DESC LIMIT 1"), '"kind":"bots"'),
        'одна запись аудита на пачку');

    echo "\n── Адреса ──\n";
    /* Правила добавил сервер, а этот процесс держит свой снимок с прошлого
       вопроса: в жизни запрос — свой процесс, и снимок у него свежий. */
    (new ReflectionProperty(IpBlock::class, 'active'))->setValue(null, null);
    $sub = array_column(BotSignal::subnets(), null, 'net');
    ok(($sub['203.0.113.0/24']['unconfirmed'] ?? 0) >= 3 && ($sub['203.0.113.0/24']['blocked'] ?? false),
        'подсеть: сколько регистраций без подтверждения и что она закрыта', json_encode($sub['203.0.113.0/24'] ?? null));
    ok(BotSignal::subnet(inet_pton('203.0.113.77')) === '203.0.113.0/24'
        && BotSignal::subnet(inet_pton('2001:db8:1:2::5')) === '2001:db8:1::/48', 'подсеть: /24 у IPv4, /48 у IPv6');
    [$st, $ips] = http('GET', SSO . '/admin/blocks');
    ok($st === 200 && pageClean($ips) && str_contains($ips, '<a class="active" href="/admin/blocks">'),
        '«Адреса» — вкладка журналов', "status $st");
    ok(str_contains($ips, 'name="nets[]" value="198.51.100.0/24"') && !str_contains($ips, 'name="nets[]" value="203.0.113.0/24"'),
        'незакрытую подсеть можно выбрать, закрытую — нет');
    $ids = array_map('intval', array_column(DB::all('SELECT id FROM {ip_blocks} WHERE rule IN (?, ?)', ['192.0.2.0/24', '203.0.113.0/24']), 'id'));
    http('POST', SSO . '/admin/blocks/remove', ['_csrf' => csrfOf($ips), 'ids' => $ids]);
    ok((int)DB::val('SELECT COUNT(*) FROM {ip_blocks} WHERE rule IN (?, ?)', ['192.0.2.0/24', '203.0.113.0/24']) === 0,
        'правила снимаются пачкой');
    http('POST', SSO . '/admin/blocks/bulk', ['_csrf' => csrfOf($ips), 'nets' => ['198.51.100.0/24'], 'days' => '7']);
    ok(DB::val('SELECT expires_at FROM {ip_blocks} WHERE rule = ?', ['198.51.100.0/24']) !== null,
        'подсети закрываются пачкой и на выбранный срок');
    ok(!str_contains($src('templates/admin/blocks.php'), 'settings_page') && str_contains($src('app/helpers.php'), "\$tabs['blocks'] = ['/admin/blocks', t('Адреса')]"),
        'адреса — не в настройках, а рядом с журналами');
} finally {
    foreach (DB::all('SELECT id FROM {users} WHERE email LIKE ?', ["%-$stamp@example.com"]) as $u) User::purge((int)$u['id']);
    foreach ($audit as $aid) DB::run('DELETE FROM {audit_log} WHERE id = ?', [$aid]);
    foreach (array_merge($nets, ['198.51.100.200']) as $r) DB::run('DELETE FROM {ip_blocks} WHERE rule = ?', [$r]);
    foreach ($was as $k => $v) Setting::set($k, $v);
    Setting::flush();
    @unlink($JAR);
}

echo "\n══ Итог ══\nПройдено: $passed, провалено: $failed\n";
exit($failed > 0 ? 1 : 0);
