<?php
/**
 * Журналы в админке (v4.94) — следующий шаг переработки основных экранов.
 *
 * Замер на боевой 27.09: в «Запросах» за сутки 6 300 строк, из них ~2 500 —
 * обложки и шрифты (их отдаёт приложение, и они писались как действия), ещё
 * 842 — стуки сканеров: гость по несуществующему адресу, пачками по двести в
 * минуту. Действия людей между ними терялись. Здесь проверяется, что:
 *   · обложки и шрифты в журнал не пишутся, как и прочая статика;
 *   · стуки в «Запросах» скрыты, пока их не попросили, — а над таблицей
 *     сказано, сколько скрыто и кто стучал чаще всех;
 *   · поиск по адресу или по отказам стуки показывает — там их и ищут;
 *   · журнал приложения называется журналом, а не «Ошибками»: на боевой в
 *     нём почти одни служебные записи.
 *
 * Запуск: php tests/admin_logs.php  (сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\ActivityLog;
use App\Core\DB;
use App\Core\View;

$passed = 0;
$failed = 0;
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);
$mark = '/probe-' . bin2hex(random_bytes(4));
$ids = [];
$row = static function (array $r) use (&$ids): void {
    $ids[] = DB::insert('activity_log', $r + ['created_at' => now(), 'user_id' => null, 'method' => 'GET', 'query_keys' => null,
        'ms' => 3, 'user_agent' => 'Mozilla/5.0 test', 'ip' => inet_pton('203.0.113.7'), 'status' => 200]);
};

try {
    echo "── Что не пишется ──\n";
    ok(ActivityLog::quiet('/cover/bg-3e2400e4b918.webp') && ActivityLog::quiet('/fonts/manrope/d0354d66ecf6e590.woff2'),
        'обложки и шрифты — статика, в журнал не пишутся');
    ok(ActivityLog::quiet('/assets/css/app.css') && !ActivityLog::quiet('/login') && !ActivityLog::quiet('/admin/users')
        && !ActivityLog::quiet('/covers') && !ActivityLog::quiet('/'),
        'прочая статика — как раньше; действия людей и похожие адреса пишутся');

    echo "\n── Стуки сканеров ──\n";
    $adminId = (int)DB::val('SELECT id FROM {users} ORDER BY id LIMIT 1');
    for ($i = 0; $i < 5; $i++) $row(['method' => 'POST', 'path' => $mark . '/wp-login.php', 'status' => 404, 'ip' => inet_pton('198.51.100.9')]);
    $row(['method' => 'GET', 'path' => $mark . '/xmlrpc.php', 'status' => 405, 'ip' => inet_pton('198.51.100.10')]);
    $row(['path' => $mark . '/login', 'status' => 200]);
    $row(['path' => $mark . '/admin/nope', 'status' => 404, 'user_id' => $adminId]);
    $all = ActivityLog::find(['path' => $mark], 100);
    $shown = ActivityLog::find(['path' => $mark, 'knocks' => false], 100);
    ok(count($all) === 8 && count($shown) === 2, 'гость по несуществующему адресу скрыт; гость на входе и вошедший с 404 — видны',
        count($all) . '/' . count($shown));
    $k = ActivityLog::knocks();
    /* Первым может оказаться и сам стенд (::1 после прогона «мусора на входе») —
       поэтому ищем среди трёх, а не требуем первого места. */
    $top = array_column($k['top'], 'n', 'ip');
    ok($k['n'] >= 6 && ($top['198.51.100.9'] ?? 0) >= 5,
        'сводка: сколько стуков за сутки и кто стучал чаще всех', json_encode($k));

    $ctl = $src('app/Controllers/Admin/LogsController.php');
    ok(str_contains($ctl, "'knocks' => (\$_GET['show'] ?? '') === 'all' || trim((string)(\$_GET['ip'] ?? '')) !== ''\n                || (\$_GET['status'] ?? '') === 'fail',"),
        'по умолчанию скрыты; поиск по адресу, отказам или «Показать всё» — показывает');

    $_GET = [];
    $page = View::fetch('admin/logs_requests', ['rows' => $shown, 'days' => 30,
        'knocks' => ['n' => 842, 'top' => [['ip' => '198.51.100.9', 'n' => 600], ['ip' => '192.0.2.4', 'n' => 216]]]]);
    ok(str_contains($page, 'Скрыто стуков сканеров за сутки: 842')
        && str_contains($page, '<a class="mono" href="/admin/ip/198.51.100.9">198.51.100.9</a> (600)'),
        'над таблицей — сколько скрыто и кто стучал; адрес ведёт на его страницу, где его закрывают');
    ok(str_contains($page, 'href="/admin/logs/requests?show=all"'), 'одна ссылка — показать всё');
    $_GET = ['show' => 'all', 'user' => 'x'];
    $pageAll = View::fetch('admin/logs_requests', ['rows' => $all, 'days' => 30, 'knocks' => null]);
    ok(str_contains($pageAll, 'Показаны и стуки сканеров.') && str_contains($pageAll, 'href="/admin/logs/requests?user=x"')
        && str_contains($pageAll, 'name="show" value="all"'),
        'со стуками — ссылка «Скрыть», фильтры помнят выбор');
    $_GET = [];
    ok(str_contains($page, 'Хранится 30 дней.') && str_contains(View::fetch('admin/logs_requests', ['rows' => [], 'days' => 0, 'knocks' => null]),
        'Хранится без срока.'), 'срок хранения словом; 0 — «без срока», а не «0 дн.»');

    echo "\n── Журнал приложения ──\n";
    $tpl = $src('templates/admin/logs_errors.php');
    ok(str_contains($tpl, "\$a = fmt_dt(\$g['first'], 'd.m H:i:s');") && str_contains($tpl, "<?= esc(fmt_dt(\$e['ts'] ?? null, 'd.m H:i:s')) ?>")
        && fmt_dt('2026-09-27T04:06:33+00:00', 'Y') === '2026',
        'время записей — местное и коротко, а не ISO в UTC');
    ok(str_contains($tpl, 'class="filter-bar is-search mb-4"') && str_contains($src('public/assets/css/app.css'), '.filter-bar.is-search .field-submit { margin-left: 0; }'),
        '«Найти» — рядом с полем номера, а не у правого края');
    ok(str_contains(logs_tabs('errors'), '>Журнал приложения<') && !str_contains(logs_tabs('errors'), 'Ошибки приложения'),
        'вкладка — «Журнал приложения», а не «Ошибки»: в нём и служебные записи');
} finally {
    if ($ids !== []) DB::run('DELETE FROM {activity_log} WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
}

echo "\n" . ($failed === 0 ? "✓ все $passed проверок" : "✗ провалов: $failed из " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
