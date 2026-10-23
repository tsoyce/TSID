<?php
/**
 * Аналитика портала и системы (v5.2).
 *
 * До v5.2 карточка портала знала «сколько людей и когда последний вход», но
 * не «как идёт»: ни входов по дням, ни активных за неделю и месяц, ни того,
 * сколько событий портал не принял. Скорость и ошибки приложения лежали в
 * журнале запросов, и сложить их можно было только руками.
 *
 * Запуск: php tests/analytics_portal.php  (сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\Stats;
use App\Core\View;
use App\Models\Client;
use App\Models\User;

$passed = 0;
$failed = 0;
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);
$stamp = bin2hex(random_bytes(3));
$cid = $uid = 0;
$rows = ['audit_log' => [], 'activity_log' => [], 'mail_queue' => []];

try {
    echo "── Портал за период ──\n";
    $slug = 'stats-' . $stamp;
    [$cid] = Client::create(['name' => 'Проба аналитики', 'slug' => $slug, 'base_url' => "https://$slug.example",
        'redirect_uris' => ["https://$slug.example/cb"], 'post_logout_uris' => ["https://$slug.example/"],
        'backchannel_uri' => null, 'is_trusted' => 1, 'access_policy' => 'open']);
    $cid = (int)$cid;
    $uid = User::create(['email' => "stats-$stamp@example.com", 'first_name' => 'Проба', 'status' => 'active']);
    $ago = static fn(int $days): string => gmdate('Y-m-d H:i:s', time() - $days * 86400);
    $sess = DB::insert('sessions', ['user_id' => $uid, 'token_hash' => hash('sha256', random_bytes(16)), 'user_agent' => 'test',
        'is_remembered' => 0, 'created_at' => now(), 'last_seen_at' => now(), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    /* Три входа в этом периоде (7 дней), один — в прошлом. */
    foreach ([now(), now(), $ago(2), $ago(9)] as $at) {
        DB::insert('session_clients', ['session_id' => $sess, 'client_id' => $cid, 'sid' => bin2hex(random_bytes(18)), 'created_at' => $at]);
    }
    DB::insert('user_client_access', ['user_id' => $uid, 'client_id' => $cid, 'status' => 'active', 'last_login_at' => $ago(3)]);
    foreach (['token.refresh', 'token.refresh', 'token.refresh_reuse', 'access.requested', 'consent.denied'] as $e) {
        $rows['audit_log'][] = DB::insert('audit_log', ['event' => $e, 'client_id' => $cid, 'created_at' => now()]);
    }
    $rows['audit_log'][] = DB::insert('audit_log', ['event' => 'token.refresh', 'client_id' => null, 'created_at' => now()]);
    foreach (['sent', 'sent', 'sent', 'failed'] as $st) {
        DB::insert('client_events', ['client_id' => $cid, 'event' => 'logout', 'payload' => '{}', 'status' => $st,
            'last_error' => $st === 'failed' ? 'HTTP 502' : null, 'created_at' => now()]);
    }

    $a = Stats::portal($cid, 7);
    ok($a['logins'] === [3, 1], 'входы в портал за период и за прошлый такой же', json_encode($a['logins']));
    ok(count($a['series']) === 7 && array_sum($a['series']) === 3, 'ряд по дням — те же три входа');
    ok($a['active'] === ['day' => 0, 'week' => 1, 'month' => 1], 'активные: вход три дня назад — в неделе и месяце, не сегодня', json_encode($a['active']));
    ok($a['refresh'] === 2 && $a['reuse'] === 1, 'токены — только этого портала');
    ok($a['delivery'] === ['failed' => 1, 'sent' => 3] || $a['delivery'] === ['sent' => 3, 'failed' => 1], 'доставка по состояниям', json_encode($a['delivery']));
    ok($a['errors'] === [['error' => 'HTTP 502', 'n' => 1]], 'причина недоставки — с числом');
    ok($a['refusals'] === ['access.requested' => 1, 'access.denied' => 0, 'consent.denied' => 1], 'отказы и запросы доступа');

    echo "\n── Скорость и ошибки приложения ──\n";
    ok(Stats::quantileBucket([0, 0, 0], 0.5) === null, 'нет запросов — нет процентиля');
    ok(Stats::quantileBucket([50, 30, 15, 5], 0.5) === 0 && Stats::quantileBucket([50, 30, 15, 5], 0.95) === 2,
        'медиана и 95-й — по накопленной доле');
    ok(Stats::quantileBucket([0, 0, 10], 0.95) === 2, 'всё в последней корзине — «дольше»');

    $since = Stats::dayStart();
    $s0 = Stats::speed($since);
    foreach ([[1, 200, 30], [1, 200, 150], [1, 200, 160], [1, 500, 3000], [null, 404, 9000], [null, 405, 9000], [null, 200, 70]] as [$u, $st, $ms]) {
        $rows['activity_log'][] = DB::insert('activity_log', ['created_at' => now(), 'user_id' => $u, 'method' => 'GET',
            'path' => '/zz-stats-' . $stamp, 'status' => $st, 'ms' => $ms]);
    }
    $rows['activity_log'][] = DB::insert('activity_log', ['created_at' => now(), 'user_id' => 1, 'method' => 'GET',
        'path' => '/zz-rare-' . $stamp, 'status' => 200, 'ms' => 99999]);
    $s1 = Stats::speed($since);
    $db = array_map(static fn($a, $b) => $a - $b, $s1['buckets'], $s0['buckets']);
    ok($db === [1, 1, 2, 0, 0, 0, 2], 'запросы разложены по корзинам; стук сканеров не считается', json_encode($db));
    ok($s1['total'] - $s0['total'] === 6, 'всего — шесть запросов');
    $slow = Stats::slowPaths($since, 50);
    ok(in_array('/zz-stats-' . $stamp, array_column($slow, 'path'), true), 'адрес от пяти запросов попадает в медленные');
    ok(!in_array('/zz-rare-' . $stamp, array_column($slow, 'path'), true), 'единичный долгий — нет');

    $m0 = Stats::byStatus('mail_queue', '1 = 1', [], $since);
    foreach (['sent', 'failed'] as $st) {
        $rows['mail_queue'][] = DB::insert('mail_queue', ['to_email' => 'x@example.com', 'subject' => 's', 'body_html' => '', 'body_text' => '',
            'status' => $st, 'created_at' => now()]);
    }
    $m1 = Stats::byStatus('mail_queue', '1 = 1', [], $since);
    ok(($m1['sent'] ?? 0) - ($m0['sent'] ?? 0) === 1 && ($m1['failed'] ?? 0) - ($m0['failed'] ?? 0) === 1, 'почта по состояниям');

    echo "\n── Экраны ──\n";
    $routes = $src('app/routes.php');
    ok(str_contains($routes, '$router->get("/admin/clients/{id}/analytics", [ClientsController::class, "analytics"], ["admin"]);')
        && str_contains($routes, "\$router->get('/admin/analytics/system', [DashboardController::class, 'system'], ['admin']);"),
        'маршруты аналитики портала и системы');
    ok(in_array('analytics', array_column(App\Core\ClientMap::sections(), 'key'), true), 'у портала есть раздел «Аналитика»');
    ok(preg_match('~function system\(\): never\s*\{\s*\$this->requireGlobalAdmin\(\);~', $src('app/Controllers/Admin/DashboardController.php')) === 1,
        '«Система» — только глобальному админу');

    $page = View::fetch('admin/analytics_system', [
        'days' => 7,
        'requests' => array_fill_keys(['2026-10-17', '2026-10-18'], 10),
        'errors' => array_fill_keys(['2026-10-17', '2026-10-18'], 0),
        'speed' => ['buckets' => [5, 3, 1, 0, 2, 1, 0], 'total' => 12, 'p50' => 1, 'p95' => 5],
        'slow' => [['path' => '/authorize', 'n' => 9, 'ms' => 1234]],
        'mail' => ['sent' => 4, 'failed' => 1],
    ]);
    ok(str_contains($page, 'class="active" aria-current="page" href="/admin/analytics/system?p=7"'), 'вкладка «Система» выбрана');
    ok(str_contains($page, '500 мс – 1 с') && str_contains($page, '1–2 с') && str_contains($page, 'дольше 2 с'),
        'корзины подписаны одной единицей на диапазон');
    ok(str_contains($page, '>до 2 с<') && str_contains($page, 'половина — до 100 мс'), 'процентили — верхней границей корзины');
    ok(str_contains($page, 'ответов 5xx не было') && !str_contains($page, 'data-names="Ошибки сервера"'),
        'без ошибок второй график не рисуется');
    ok(str_contains($page, '1 234 мс'), 'медленный адрес со средним временем');

    $card = View::fetch('admin/client/analytics', ['c' => ['id' => 7], 'days' => 7, 'a' => [
        'logins' => [12, 6], 'series' => array_fill_keys(['2026-10-17', '2026-10-18'], 6),
        'active' => ['day' => 1, 'week' => 4, 'month' => 9], 'refresh' => 3, 'reuse' => 1,
        'delivery' => ['sent' => 2, 'failed' => 1], 'errors' => [['error' => 'timeout', 'n' => 1]],
        'refusals' => ['access.requested' => 0, 'access.denied' => 0, 'consent.denied' => 0],
    ]]);
    ok(str_contains($card, 'href="/admin/clients/7/analytics?p=7" aria-current="true"'), 'период остаётся в карточке портала');
    ok(str_contains($card, 'is-good">+100 %'), 'разница к прошлому периоду');
    ok(str_contains($card, '66 %') && str_contains($card, 'доставлено 2 из 3'), 'доля доставленных — вниз, без «округления до успеха»');
    ok(str_contains($card, 'повторов refresh-токена: 1') && str_contains($card, 'Ни запросов доступа, ни отказов.') && str_contains($card, 'timeout'),
        'повтор токена виден, пустые отказы — словами, причина недоставки — на месте');
} finally {
    foreach ($rows as $table => $ids) {
        if ($ids) DB::run('DELETE FROM {' . $table . '} WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
    }
    if ($cid) Client::purge($cid);
    if ($uid) User::purge($uid);
}

echo "\nПройдено: $passed, провалено: $failed\n";
exit($failed === 0 ? 0 : 1);
