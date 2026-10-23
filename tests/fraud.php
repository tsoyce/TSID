<?php
/**
 * Антифрод (v4.98).
 *
 * Живой случай 23.10: шестьдесят четыре бота удалили пачкой — и от них не
 * осталось ничего, на чём учиться: аудит пережил аккаунты, но не помнил, что
 * это были боты, журнал активности ушёл вместе с ними. Здесь проверяется, что:
 *   · оценка риска — сумма весов; сильный признак решает один, слабые — вместе;
 *   · отказы ботам и подбор с той же подсети — признаки;
 *   · удаление «как бота» откладывает образец: подсеть, браузер, домен почты,
 *     признаки, поведение — и ни ящика, ни имени, ни адреса;
 *   · архив учит: следующий с той же подсети отмечен сразу;
 *   · раздел «Антифрод» — вкладка журналов, выгрузка — в аудит;
 *   · у архива есть срок хранения.
 *
 * Запуск: php tests/fraud.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\BotSignal;
use App\Core\DB;
use App\Core\FraudArchive;
use App\Core\IpBlock;
use App\Models\User;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$stamp = bin2hex(random_bytes(3));
$JAR = sys_get_temp_dir() . '/fraud-' . $stamp . '.txt';
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);
$audit = [];
$activity = [];
$sampleFrom = (int)DB::val('SELECT COALESCE(MAX(id), 0) FROM {fraud_samples}');
const FRESH = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36';

$event = static function (string $name, string $ip, int $ago = 60) use (&$audit): void {
    $audit[] = DB::insert('audit_log', ['created_at' => now_plus(-$ago), 'event' => $name, 'ip' => inet_pton($ip),
        'user_agent' => FRESH, 'meta' => null]);
};
/** Аккаунт как после регистрации формой — как в admin_bots. */
$reg = static function (string $tag, string $ip, string $ua = FRESH, ?string $at = null) use ($stamp, &$audit): int {
    $uid = User::create(['email' => "fraud-$tag-$stamp@bots.example", 'first_name' => 'Скрытое', 'last_name' => 'Имя', 'status' => 'pending']);
    $audit[] = DB::insert('audit_log', ['created_at' => $at ?? now(), 'actor_user_id' => $uid, 'on_user_id' => $uid,
        'event' => 'register', 'ip' => inet_pton($ip), 'user_agent' => $ua, 'meta' => null]);
    if ($at !== null) DB::run('UPDATE {users} SET created_at = ? WHERE id = ?', [$at, $uid]);
    return $uid;
};
$suspect = static function (int $id): ?array {
    foreach (BotSignal::suspects() as $s) if ((int)$s['id'] === $id) return $s;
    return null;
};

try {
    echo "── Оценка риска ──\n";
    ok(BotSignal::score(['outdated']) >= BotSignal::SURE && BotSignal::score(['blocked']) >= BotSignal::SURE
        && BotSignal::score(['crowd']) >= BotSignal::SURE && BotSignal::score(['known_net']) >= BotSignal::SURE,
        'сильный признак решает один');
    ok(BotSignal::score(['refused_net']) < BotSignal::SURE && BotSignal::score(['fails_net']) < BotSignal::SURE
        && BotSignal::score(['known_ua']) < BotSignal::SURE, 'слабый в одиночку — только показан');
    ok(BotSignal::score(['refused_net', 'fails_net']) >= BotSignal::SURE, 'два слабых вместе — галочка');
    ok(BotSignal::score(array_keys(BotSignal::WEIGHT)) === 100 && BotSignal::score(['outdated', 'outdated']) === 60,
        'шкала до 100, повтор признака не удваивает');

    echo "\n── Шум с подсети ──\n";
    $event('register.refused', '192.0.2.50');
    $x = $reg('noisy', '192.0.2.60');
    $s = $suspect($x);
    ok($s !== null && in_array('refused_net', $s['signals'], true) && !$s['sure'],
        'с подсети уже отказывали ботам — признак, но не галочка', json_encode($s['signals'] ?? null));
    for ($i = 0; $i < BotSignal::FAILS; $i++) $event('login.fail', '192.0.2.' . (70 + $i));
    $s = $suspect($x);
    ok($s !== null && in_array('fails_net', $s['signals'], true) && $s['sure'] && $s['score'] === 50,
        'плюс подбор паролей с той же подсети — галочка', json_encode([$s['signals'] ?? null, $s['score'] ?? null]));

    echo "\n── Образец ──\n";
    $regAt = now_plus(-300);
    $y = $reg('sample', '203.0.113.40', FRESH, $regAt);
    $ipY = inet_pton('203.0.113.40');
    $activity[] = DB::insert('activity_log', ['created_at' => now_plus(-340), 'user_id' => null, 'ip' => $ipY,
        'method' => 'GET', 'path' => '/register', 'status' => 200, 'ms' => 5, 'user_agent' => FRESH]);
    foreach (['/profile', '/admin/users/17', '/admin/users/42'] as $i => $p) {
        $activity[] = DB::insert('activity_log', ['created_at' => now_plus(-200 + $i * 10), 'user_id' => $y, 'ip' => $ipY,
            'method' => 'GET', 'path' => $p, 'status' => $i === 0 ? 200 : 403, 'ms' => 5, 'user_agent' => FRESH]);
    }
    $row = FraudArchive::sample($suspect($y) ?? []);
    $flat = json_encode($row, JSON_UNESCAPED_UNICODE);
    ok(!str_contains($flat, "fraud-sample-$stamp") && !str_contains($flat, 'Скрытое') && !str_contains($flat, 'Имя')
        && !str_contains($flat, '203.0.113.40') && !str_contains($flat, (string)$y . ','),
        'в образце нет ни ящика, ни имени, ни адреса, ни номера аккаунта', $flat);
    ok($row['net'] === '203.0.113.0/24' && $row['email_domain'] === 'bots.example' && $row['user_agent'] === FRESH,
        'подсеть, домен почты и браузер — есть');
    $act = json_decode((string)$row['activity'], true);
    ok(($act['requests'] ?? 0) === 3 && ($act['paths']['GET /admin/users/{n}'] ?? 0) === 2 && ($act['statuses']['403'] ?? 0) === 2,
        'поведение: сколько запросов, куда (номера — {n}), чем ответили', (string)$row['activity']);
    ok(($act['before'] ?? 0) === 1 && ($act['form_s'] ?? null) === 40, 'форма регистрации заполнена за 40 секунд', (string)$row['activity']);

    echo "\n── Удаление как бота и архив ──\n";
    DB::run('UPDATE {users} SET totp_secret = NULL, totp_enabled_at = NULL WHERE email = ?', ['admin@test.local']);
    DB::run('DELETE FROM {rate_limits}');
    [, $lp] = http('GET', SSO . '/login');
    http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => 'admin@test.local', 'password' => 'correct-horse-battery']);
    [, $page] = http('GET', SSO . '/admin/users/bots');
    http('POST', SSO . '/admin/users/bots', ['_csrf' => csrfOf($page), 'ids' => [$x, $y], 'block' => '0']);
    ok(DB::val('SELECT id FROM {users} WHERE id = ?', [$y]) === null, 'бот удалён');
    $kept = DB::all('SELECT * FROM {fraud_samples} WHERE id > ? ORDER BY id', [$sampleFrom]);
    $nets = array_column($kept, 'net');
    ok(count($kept) === 2 && in_array('203.0.113.0/24', $nets, true) && in_array('192.0.2.0/24', $nets, true),
        'на каждого удалённого — образец в архиве', json_encode($nets));
    $mine = array_values(array_filter($kept, static fn($r) => $r['net'] === '203.0.113.0/24'))[0] ?? [];
    ok(str_contains((string)($mine['activity'] ?? ''), '"requests":3'), 'поведение сохранено до того, как журнал ушёл с аккаунтом');
    ok(DB::val('SELECT COUNT(*) FROM {activity_log} WHERE user_id = ?', [$y]) == 0, 'журнал активности аккаунта при этом удалён');

    echo "\n── Архив учит ──\n";
    /* Образцы записал сервер, а этот процесс держит свой снимок архива: в жизни
       запрос — свой процесс, и снимок у него свежий. */
    (new ReflectionProperty(FraudArchive::class, 'known'))->setValue(null, null);
    $z = $reg('next', '203.0.113.99');
    $s = $suspect($z);
    ok($s !== null && in_array('known_net', $s['signals'], true) && $s['sure'],
        'следующий с той же подсети — отмечен сразу, хотя браузер свежий', json_encode($s['signals'] ?? null));
    ok(in_array('known_ua', $s['signals'] ?? [], true), 'браузер из архива — признак');

    echo "\n── Раздел ──\n";
    [$st, $fp] = http('GET', SSO . '/admin/fraud');
    ok($st === 200 && pageClean($fp) && str_contains($fp, '<h1>Антифрод</h1>'), 'раздел открывается', "status $st");
    ok(str_contains($fp, '<a class="active" href="/admin/fraud">'), 'антифрод — вкладка журналов');
    ok(str_contains($fp, '203.0.113.0/24') && str_contains($fp, 'id="archive"') && str_contains($fp, 'bots.example'),
        'архив образцов на странице');
    ok(!str_contains($fp, "fraud-sample-$stamp"), 'почты удалённых на странице нет');
    $auditFrom = (int)DB::val('SELECT MAX(id) FROM {audit_log}');
    [$st, $js] = http('GET', SSO . '/admin/fraud/samples.json');
    $data = json_decode($js, true);
    ok($st === 200 && is_array($data['samples'] ?? null) && count($data['samples']) >= 2 && isset($data['weights']['outdated']),
        'выгрузка JSON: образцы и веса', "status $st");
    ok(!str_contains($js, "fraud-sample-$stamp") && !str_contains($js, 'Скрытое'), 'в выгрузке ни почты, ни имени');
    ok(DB::val("SELECT id FROM {audit_log} WHERE event = 'admin.fraud.export' AND id > ?", [$auditFrom]) !== null,
        'выгрузка — в аудите');
    [$st] = http('GET', SSO . '/admin/fraud', [], [], sys_get_temp_dir() . '/fraud-anon-' . $stamp . '.txt');
    ok($st !== 200, 'без входа раздела не видно', "status $st");

    echo "\n── Срок хранения ──\n";
    $old = DB::insert('fraud_samples', ['archived_at' => now_plus(-3 * 86400), 'label' => 'bot', 'net' => '198.51.100.0/24', 'score' => 0]);
    ok(FraudArchive::purge(2) >= 1 && DB::val('SELECT id FROM {fraud_samples} WHERE id = ?', [$old]) === null,
        'старше срока — убираются');
    ok(FraudArchive::purge(0) === 0, '0 — хранить вечно');
    ok(str_contains($src('app/Core/CronTasks.php'), "FraudArchive::purge(Setting::getInt('retention.fraud_days'")
        && str_contains($src('app/Controllers/Admin/SettingsController.php'), "'mail_days', 'fraud_days']"),
        'срок — в политиках, чистит ежесуточная задача');
} finally {
    foreach (DB::all('SELECT id FROM {users} WHERE email LIKE ?', ["%-$stamp@bots.example"]) as $u) User::purge((int)$u['id']);
    foreach ($audit as $aid) DB::run('DELETE FROM {audit_log} WHERE id = ?', [$aid]);
    foreach ($activity as $aid) DB::run('DELETE FROM {activity_log} WHERE id = ?', [$aid]);
    DB::run('DELETE FROM {fraud_samples} WHERE id > ?', [$sampleFrom]);
    @unlink(sys_get_temp_dir() . '/fraud-anon-' . $stamp . '.txt');
    @unlink($JAR);
}

echo "\n══ Итог ══\nПройдено: $passed, провалено: $failed\n";
exit($failed > 0 ? 1 : 0);
