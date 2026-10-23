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
        'app/Controllers/Admin/DashboardController.php' => "'logins_today' => Stats::logins(\$today),",
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
} finally {
    if ($ids) DB::run('DELETE FROM {audit_log} WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
    if ($tzWas === null) Setting::delete('app.timezone'); else Setting::set('app.timezone', $tzWas);
}

echo "\nПройдено: $passed, провалено: $failed\n";
exit($failed === 0 ? 0 : 1);
