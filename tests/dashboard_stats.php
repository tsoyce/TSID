<?php
/**
 * Цифры дашборда (v4.98): один «вход» на всю установку и местные сутки.
 *
 * До v4.98 вход был определён трижды: дашборд не видел passkey и соцсети,
 * /metrics — ссылку из письма, профиль — как дашборд. Сутки резались по UTC
 * и показывались по поясу установки: для UTC+3 «сегодня» шло с 03:00.
 *
 * Запуск: php tests/dashboard_stats.php  (сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Controllers\Admin\DashboardController;
use App\Core\Audit;
use App\Core\DB;
use App\Core\Setting;
use App\Core\Stats;

$passed = 0;
$failed = 0;
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);
$ids = [];
$tzWas = Setting::get('app.timezone', null);

try {
    echo "── Что такое вход ──\n";
    ok(Stats::LOGIN_EVENTS === ['login.success', 'login.magic_link', 'login.passkey', 'login.social'],
        'вход — пароль, ссылка из письма, passkey и соцсети', implode(',', Stats::LOGIN_EVENTS));

    $before = Stats::logins(Stats::dayStart());
    foreach (Stats::LOGIN_EVENTS as $e) $ids[] = DB::insert('audit_log', ['event' => $e, 'created_at' => now()]);
    $ids[] = DB::insert('audit_log', ['event' => 'login.fail', 'created_at' => now()]);
    $after = Stats::logins(Stats::dayStart());
    ok($after - $before === 4, 'каждый способ входа попадает в счёт, неудачный — нет', "$before → $after");

    foreach ([
        'app/Controllers/Admin/DashboardController.php' => "\$login = [Stats::loginIn(), Stats::LOGIN_EVENTS];",
        'app/Controllers/MetricsController.php' => "\\App\\Core\\Stats::loginIn()",
        'app/Controllers/ProfileController.php' => "\\App\\Core\\Stats::loginIn()",
    ] as $file => $needle) {
        ok(str_contains($src($file), $needle), "$file считает вход через Stats");
    }
    $dash = $src('app/Controllers/Admin/DashboardController.php');
    ok(!str_contains($dash, "gmdate('Y-m-d 00:00:00')") && !str_contains($dash, "'login.magic_link'"),
        'в дашборде не осталось своих суток и своего списка событий');

    echo "\n── Местные сутки ──\n";
    Setting::set('app.timezone', 'Etc/GMT-3');   // UTC+3
    $at = gmmktime(1, 30, 0, 10, 23, 2026);       // 23.10 01:30 UTC = 04:30 по UTC+3
    ok(Stats::dayStart(0, $at) === '2026-10-22 21:00:00', '«сегодня» начинается в местную полночь', Stats::dayStart(0, $at));
    ok(Stats::dayStart(1, $at) === '2026-10-21 21:00:00', '«вчера» — тоже по местному времени', Stats::dayStart(1, $at));

    /* Ряд по дням за январь 2020 — такой старины в тестовой базе нет. */
    $at = gmmktime(12, 0, 0, 1, 10, 2020);
    $ids[] = DB::insert('audit_log', ['event' => 'test.stats', 'created_at' => '2020-01-09 22:30:00']); // 10.01 01:30 местного
    $ids[] = DB::insert('audit_log', ['event' => 'test.stats', 'created_at' => '2020-01-09 20:30:00']); // 09.01 23:30 местного
    $ids[] = DB::insert('audit_log', ['event' => 'test.stats', 'created_at' => '2020-01-10 11:00:00']);
    $row = Stats::perDay('audit_log', 'event = ?', ['test.stats'], 3, $at);
    ok($row === ['2020-01-08' => 0, '2020-01-09' => 1, '2020-01-10' => 2], 'ночной вход ложится в местный день, пустой день — ноль',
        json_encode($row));

    echo "\n── Подписи ──\n";
    ok(Audit::title('install.done') !== 'install.done' && Audit::title('login.social') !== 'login.social',
        'установка и вход через соцсеть подписаны по-человечески');
    $tpl = $src('templates/admin/dashboard.php');
    ok(str_contains($tpl, "t('активных сессий · кривая — новые за день')"), 'у «Сейчас в сети» подписаны и число, и кривая');

    echo "\n── Период (v4.99) ──\n";
    ok(Stats::period('30') === 30 && Stats::period('1') === 1 && Stats::period('5') === 14 && Stats::period(null) === 14,
        'период — из списка, незнакомое — 14 дней');
    $at = gmmktime(1, 30, 0, 10, 23, 2026);
    ok(Stats::bounds(7, $at) === ['2026-10-16 21:00:00', '2026-10-09 21:00:00'], '7 дней и прошлые 7 — по местным суткам',
        json_encode(Stats::bounds(7, $at)));
    ok(Stats::delta(120, 100) === 20 && Stats::delta(80, 100) === -20 && Stats::delta(5, 0) === null,
        'разница к прошлому периоду; с нулём не сравниваем');

    /* Сегодня по часам: 23.10.2020, UTC+3. */
    $at = gmmktime(12, 0, 0, 10, 23, 2020);
    $ids[] = DB::insert('audit_log', ['event' => 'test.stats', 'created_at' => '2020-10-22 21:10:00']); // 00:10 местного
    $ids[] = DB::insert('audit_log', ['event' => 'test.stats', 'created_at' => '2020-10-23 06:40:00']); // 09:40
    $ids[] = DB::insert('audit_log', ['event' => 'test.stats', 'created_at' => '2020-10-23 06:50:00']); // 09:50
    $h = Stats::series('audit_log', 'event = ?', ['test.stats'], 1, $at);
    ok(count($h) === 24 && $h['00'] === 1 && $h['09'] === 2 && array_sum($h) === 3, '«сегодня» — 24 часа по местному времени',
        json_encode(array_filter($h)));
    ok(count(Stats::series('audit_log', 'event = ?', ['test.stats'], 7, $at)) === 7, 'остальные периоды — по дням');

    echo "\n── Требует внимания ──\n";
    $ok = [['label' => 'База данных', 'status' => 'ok', 'note' => '']];
    $bad = [['label' => 'Cron', 'status' => 'fail', 'note' => 'не настроен'], ['label' => 'Гигиена', 'status' => 'warn', 'note' => 'x']];
    $calm = DashboardController::attention($ok, 0, 14);
    $alarm = DashboardController::attention($bad, 0, 14);
    ok(array_filter($calm, fn($a) => $a['url'] === '/admin/health') === [], 'зелёное здоровье в «Требует внимания» не попадает');
    ok(count(array_filter($alarm, fn($a) => $a['url'] === '/admin/health')) === 2 && $alarm[0]['level'] === 'fail' && $alarm[1]['level'] === 'warn',
        'красное и жёлтое — попадают, со ссылкой на здоровье');
    $spike = fn($n) => array_filter(DashboardController::attention($ok, $n, 1), fn($a) => str_contains($a['url'], 'login.fail')) !== [];
    ok($spike(500) && !$spike(19), 'всплеск неудачных входов виден; два десятка — ещё не тревога');

    echo "\n── График по способам ──\n";
    $by = ['login.success' => ['2026-10-22' => 3, '2026-10-23' => 37], 'login.magic_link' => ['2026-10-22' => 0, '2026-10-23' => 1],
        'login.passkey' => ['2026-10-22' => 1, '2026-10-23' => 0], 'login.social' => ['2026-10-22' => 0, '2026-10-23' => 0]];
    $svg = DashboardController::barsSvg($by, 2, 'x');
    ok(str_contains($svg, 'data-values="3,0,1,0"') && str_contains($svg, 'data-tip="23.10"'), 'столбец несёт свои числа и дату для подсказки');
    ok(str_contains($svg, '>40</text>') && str_contains($svg, '>20</text>') && !str_contains($svg, '>38</text>'),
        'шкала круглая: 0, 10, 20, 30, 40 — а не до максимума 38');
    ok(substr_count($svg, 'class="s1"') === 2 && substr_count($svg, 'class="s3"') === 1, 'пустой слой не рисуется');

    echo "\n── Экран ──\n";
    $_SERVER['REQUEST_URI'] = '/admin?p=7';
    $vars = ['days' => 7, 'kpi' => ['logins' => [120, 100], 'users_new' => [3, 0], 'fails' => [5, 10], 'sessions_active' => 4, 'users_total' => 9],
        'byMethod' => $by, 'failSpark' => [0, 1], 'loginSpark' => [1, 2], 'sessionSpark' => [0, 0], 'regSpark' => [1, 0],
        'portals' => [['id' => 7, 'name' => 'Портал заказов', 'n' => 12]], 'protection' => ['total' => 4, 'protected' => 1],
        'attention' => [], 'audit' => [],
        'onboarding' => ['done' => 2, 'total' => 5, 'hidden' => false, 'steps' => array_fill(0, 5,
            ['label' => 'Шаг', 'hint' => '', 'done' => false, 'url' => '/admin', 'cta' => 'Сделать', 'icon' => 'mail'])]];
    $page = \App\Core\View::fetch('admin/dashboard', $vars);
    ok(str_contains($page, '<a href="/admin?p=7" aria-current="true">'), 'выбранный период отмечен; остальные — ссылки');
    ok(str_contains($page, '<details class="bento-card onboarding-fold mb-16">') && substr_count($page, 'onboarding-step') === 5,
        'первые шаги свёрнуты, когда что-то уже сделано; все пять на месте');
    $vars['onboarding']['done'] = 0;
    ok(str_contains(\App\Core\View::fetch('admin/dashboard', $vars), 'onboarding-fold mb-16" open>'), 'на свежей установке — открыты');
    ok(str_contains($page, 'is-good">+20 %</span>') && str_contains($page, 'is-good">-50 %</span>'),
        'рост входов и падение неудач — оба «хорошо»');
    ok(str_contains($page, 'Всё в порядке') && str_contains($page, 'data-names="Пароль|') && str_contains($page, '25 %'),
        'пустое «Требует внимания» говорит «всё в порядке»; подписи рядов и доля защищённых — на месте');
} finally {
    if ($ids) DB::run('DELETE FROM {audit_log} WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
    if ($tzWas === null) Setting::delete('app.timezone'); else Setting::set('app.timezone', $tzWas);
}

echo "\nПройдено: $passed, провалено: $failed\n";
exit($failed === 0 ? 0 : 1);
