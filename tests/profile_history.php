<?php
/**
 * История аккаунта в кабинете (v5.3).
 *
 * До v5.3 кабинет показывал пять последних входов. Чужой вход неделю назад,
 * перебор пароля, отключённый второй фактор человек видел только в письме —
 * если письма включены.
 *
 * Запуск: php tests/profile_history.php  (сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\Setting;
use App\Core\UserHistory;
use App\Core\View;
use App\Models\User;

$passed = 0;
$failed = 0;
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);
$stamp = bin2hex(random_bytes(3));
$uids = [];
$tzWas = Setting::get('app.timezone', null);

try {
    echo "── Что попадает в историю ──\n";
    $me = $uids[] = User::create(['email' => "hist-$stamp@example.com", 'first_name' => 'Проба', 'status' => 'active']);
    $other = $uids[] = User::create(['email' => "hist2-$stamp@example.com", 'first_name' => 'Другой', 'status' => 'active']);
    $log = static function (string $event, ?int $actor, ?int $on, int $ago = 0): int {
        return DB::insert('audit_log', ['event' => $event, 'actor_user_id' => $actor, 'on_user_id' => $on,
            'created_at' => gmdate('Y-m-d H:i:s', time() - $ago), 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Firefox/131.0']);
    };
    $ids = [
        'success' => $log('login.success', $me, $me, 50),
        'fail' => $log('login.fail', null, $me, 40),
        'twofa' => $log('2fa.disabled', $me, null, 30),
        'admin' => $log('admin.client.update', $me, null, 20),     // его действие в админке — не его история
        'refresh' => $log('token.refresh', $me, $me, 10),          // шум
        'foreign' => $log('login.success', $other, $other, 5),     // чужой вход
        'about' => $log('admin.user.block', $other, $me, 1),       // админ сделал с ним — его история
    ];
    $page = UserHistory::page($me);
    $got = array_map('intval', array_column($page['rows'], 'id'));
    ok($got === [$ids['about'], $ids['twofa'], $ids['fail'], $ids['success']], 'свои входы, неудачи, изменения защиты и действия над ним — новые сверху',
        json_encode($got));
    ok(!in_array($ids['admin'], $got, true), 'его действия в админке — не его история');
    ok(!in_array($ids['refresh'], $got, true), 'обновление токена — шум, его нет');
    ok(!in_array($ids['foreign'], $got, true), 'чужих событий нет');

    $logins = array_map('intval', array_column(UserHistory::page($me, 'logins')['rows'], 'id'));
    ok($logins === [$ids['success']], '«Входы» — только успешные входы');
    $alerts = array_map('intval', array_column(UserHistory::page($me, 'alerts')['rows'], 'id'));
    ok($alerts === [$ids['fail']], '«Неудачи» — тот же признак, что красит журнал');

    $p1 = UserHistory::page($me, 'all', null, 2);
    ok(count($p1['rows']) === 2 && $p1['more'] === $ids['twofa'], 'страница по два — и id, с которого продолжить');
    $p2 = UserHistory::page($me, 'all', $p1['more'], 2);
    ok(array_map('intval', array_column($p2['rows'], 'id')) === [$ids['fail'], $ids['success']] && $p2['more'] === null,
        'продолжение без повторов, и больше нечего');

    $log('login.fail', null, $me, 60);
    $log('login.fail', null, $me, 8 * 86400);
    ok(UserHistory::recentFails($me) === 2, 'неудачи за неделю — без старых');

    echo "\n── Дни по местному времени ──\n";
    Setting::set('app.timezone', 'Etc/GMT-3');
    $now = gmmktime(22, 30, 0, 10, 23, 2026);   // 24.10 01:30 по UTC+3
    ok(UserHistory::dayLabel('2026-10-23 21:10:00', $now) === 'Сегодня', 'после местной полуночи — уже «сегодня»');
    ok(UserHistory::dayLabel('2026-10-23 20:50:00', $now) === 'Вчера', 'до неё — «вчера»');
    ok(UserHistory::dayLabel('2026-10-21 12:00:00', $now) === '21.10.2026', 'раньше — датой');

    echo "\n── Экран ──\n";
    ok(str_contains($src('app/routes.php'), "\$router->get('/profile/activity', [ProfileController::class, 'activity'], ['auth']);"),
        'маршрут /profile/activity — только вошедшему');
    ok(str_contains($src('templates/profile/index.php'), 'href="/profile/activity"'), 'из профиля — «Вся история»');
    ok(str_contains($src('app/Controllers/PwaController.php'), "'url' => '/profile/activity'"), 'ярлык приложения ведёт в историю');

    $rows = [
        ['id' => 9, 'created_at' => now(), 'event' => 'login.fail', 'ip' => inet_pton('203.0.113.7'), 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Firefox/131.0', 'meta' => null, 'client_id' => null, 'client_name' => null],
        ['id' => 8, 'created_at' => now(), 'event' => 'login.passkey', 'ip' => null, 'user_agent' => 'cli', 'meta' => null, 'client_id' => 3, 'client_name' => 'Портал проб'],
    ];
    $html = View::fetch('profile/activity', ['kind' => 'all', 'page' => ['rows' => $rows, 'more' => 8], 'fails' => 4, 'continued' => false]);
    ok(str_contains($html, 'is-alarm') && str_contains($html, 'Неудачная попытка входа'), 'неудача — красной строкой и словами');
    ok(str_contains($html, 'Вход: passkey') && str_contains($html, 'Портал проб'), 'вход — со способом и сервисом');
    ok(str_contains($html, esc(ip_masked(inet_pton('203.0.113.7')))), 'адрес — той же маской, что в «Устройствах»');
    ok(str_contains($html, 'Firefox · Windows') && substr_count($html, ' · cli') === 0, 'устройство словами; служебный агент не показывается');
    ok(str_contains($html, 'href="/profile/activity?before=8"'), '«Раньше» продолжает с последней строки');
    ok(str_contains($html, '4 неудачных попыток') && str_contains($html, 'href="/profile/security"'), 'перебор — предупреждение и куда идти');
    $calm = View::fetch('profile/activity', ['kind' => 'alerts', 'page' => ['rows' => [], 'more' => null], 'fails' => 2, 'continued' => false]);
    ok(!str_contains($calm, 'callout-warning') && str_contains($calm, 'Неудачных попыток не было'), 'две неудачи — без тревоги; пустой фильтр — словами');
} finally {
    foreach ($uids as $u) {
        DB::run('DELETE FROM {audit_log} WHERE on_user_id = ? OR actor_user_id = ?', [$u, $u]);
        User::purge($u);
    }
    if ($tzWas === null) Setting::delete('app.timezone'); else Setting::set('app.timezone', $tzWas);
}

echo "\nПройдено: $passed, провалено: $failed\n";
exit($failed === 0 ? 0 : 1);
