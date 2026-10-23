<?php
/**
 * Аналитика входа и регистрации (v5.1): воронка, часы недели, причины
 * неудач, браузеры и системы — и экран, который их показывает.
 *
 * До v5.1 регистрация оставляла в аудите только итог: сколько людей ушло на
 * шаге подтверждения почты, видно не было. Причина неудачного входа лежала в
 * meta, но собрать её можно было только глазами по журналу.
 *
 * Запуск: php tests/analytics.php  (сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\Setting;
use App\Core\Stats;
use App\Core\View;

$passed = 0;
$failed = 0;
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);
$ids = [];
$tzWas = Setting::get('app.timezone', null);
$add = static function (string $event, array $extra = []) use (&$ids): void {
    $ids[] = DB::insert('audit_log', $extra + ['event' => $event, 'created_at' => now()]);
};

try {
    echo "── Воронка регистрации ──\n";
    $reg = $src('app/Controllers/RegistrationController.php');
    ok(substr_count($reg, "Audit::log('signup.started'") === 1, 'ввод почты пишет signup.started');
    ok(substr_count($reg, "Audit::log('signup.verified'") === 2, 'подтверждение кодом и ссылкой пишет signup.verified');
    ok(!preg_match("~Audit::log\('signup\.(started|verified)'[^;]*email~", $reg), 'адрес в шаги воронки не пишется');

    $since = Stats::dayStart();
    $was = array_column(Stats::funnel($since), 'n', 'key');
    foreach (['signup.started', 'signup.started', 'signup.started', 'signup.verified', 'signup.verified', 'register'] as $e) $add($e);
    $now = array_column(Stats::funnel($since), 'n', 'key');
    ok(array_keys($now) === ['signup.started', 'signup.verified', 'register'], 'шаги идут по порядку');
    ok($now['signup.started'] - $was['signup.started'] === 3 && $now['signup.verified'] - $was['signup.verified'] === 2
        && $now['register'] - $was['register'] === 1, 'каждый шаг считается своим событием', json_encode([$was, $now]));

    echo "\n── Часы недели по местному времени ──\n";
    Setting::set('app.timezone', 'Etc/GMT-3');   // UTC+3
    $from = '2020-01-05 00:00:00';                // такой старины в тестовой базе нет
    $h0 = Stats::heatmap($from);
    $add('login.passkey', ['created_at' => '2020-01-06 07:15:00']);   // пн 10:15 местного
    $add('login.success', ['created_at' => '2020-01-05 22:30:00']);   // пн 01:30 местного, по UTC — ещё воскресенье
    $add('login.fail', ['created_at' => '2020-01-06 07:20:00']);      // неудачный — не вход
    $h1 = Stats::heatmap($from);
    $dh = static fn(int $wd, int $h): int => $h1[$wd][$h] - $h0[$wd][$h];
    ok(count($h1) === 7 && count($h1[1]) === 24, 'сетка — 7 дней на 24 часа');
    ok($dh(1, 10) === 1 && $dh(1, 7) === 0, 'вход ложится в местный час, а не в UTC');
    ok($dh(1, 1) === 1 && $dh(7, 22) === 0, 'после местной полночи — уже понедельник');

    echo "\n── Почему не вошли ──\n";
    $r0 = Stats::failReasons($since);
    $add('login.fail', ['meta' => json_encode(['reason' => 'password'])]);
    $add('login.fail', ['meta' => json_encode(['reason' => 'password'])]);
    $add('login.fail', ['meta' => json_encode(['reason' => 'identity'])]);
    $add('login.fail');
    $r1 = Stats::failReasons($since);
    $d = static fn(string $k): int => ($r1[$k] ?? 0) - ($r0[$k] ?? 0);
    ok($d('password') === 2 && $d('identity') === 1, 'причины складываются по meta', json_encode($r1));
    ok($d('other') === 1, 'неудача без причины — «прочее», а не пропадает');
    $vals = array_values($r1);
    $sorted = $vals;
    rsort($sorted);
    ok($vals === $sorted, 'причины — по убыванию');

    echo "\n── Чем входят ──\n";
    $v0 = Stats::devices($since);
    $add('login.success', ['user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Gecko/20100101 Firefox/131.0']);
    $add('login.magic_link', ['user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Gecko/20100101 Firefox/131.0']);
    $add('login.social', ['user_agent' => 'curl/8.0']);
    $v1 = Stats::devices($since);
    $dv = static fn(string $g, string $k): int => ($v1[$g][$k] ?? 0) - ($v0[$g][$k] ?? 0);
    ok($dv('browsers', 'Firefox') === 2 && $dv('systems', 'Windows') === 2, 'браузер и система — по device_label()');
    ok($dv('browsers', 'Браузер') === 1 && $dv('systems', t('Другая')) === 1, 'незнакомый агент не теряется');

    echo "\n── Экран ──\n";
    ok(str_contains($src('app/routes.php'), "\$router->get('/admin/analytics', [DashboardController::class, 'analytics'], ['admin']);"),
        'маршрут /admin/analytics');
    ok(preg_match('~function analytics\(\): never\s*\{\s*\$this->requireGlobalAdmin\(\);~', $src('app/Controllers/Admin/DashboardController.php')) === 1,
        'аналитика — только глобальному админу');
    ok(str_contains($src('templates/admin/dashboard.php'), "dash_head('pult', \$days"), 'у пульта те же вкладки');

    $heat = array_fill(1, 7, array_fill(0, 24, 0));
    $heat[1][10] = 8;
    $heat[3][14] = 1;
    $heat[5][9] = 4;
    $page = View::fetch('admin/analytics', [
        'days' => 30,
        'funnel' => [['key' => 'signup.started', 'n' => 0], ['key' => 'signup.verified', 'n' => 0], ['key' => 'register', 'n' => 5]],
        'heat' => $heat, 'heatDays' => 30,
        'reasons' => ['password' => 4, 'zz_new' => 1],
        'devices' => ['browsers' => ['Chrome' => 6, 'Firefox' => 5, 'Safari' => 4, 'Edge' => 3, 'Opera' => 2, 'Яндекс Браузер' => 1, 'Браузер' => 1],
                      'systems' => ['Windows' => 3]],
        'methods' => ['login.success' => 3, 'login.magic_link' => 1, 'login.passkey' => 0, 'login.social' => 0],
    ]);
    ok(str_contains($page, 'class="active" aria-current="page" href="/admin/analytics?p=30"')
        && str_contains($page, 'href="/admin?p=30"'), 'вкладка «Аналитика» выбрана, период едет на «Пульт»');
    ok(str_contains($page, 'href="/admin/analytics?p=7"') && str_contains($page, 'href="/admin/analytics?p=30" aria-current="true"'),
        'переключатель периода остаётся на аналитике');
    ok(str_contains($page, 'Шаги регистрации считаются с версии 5.1'), 'пустая воронка при живых регистрациях объясняется');
    ok(substr_count($page, 'heat-cell l4') === 2 && substr_count($page, 'heat-cell l2') === 2 && substr_count($page, 'heat-cell l1') === 2,
        'ступень — доля от самого людного часа: 8 — тёмная, 4 — средняя, 1 — светлая (и по одной в легенде)');
    ok(str_contains($page, 'title="Пн 10:00 · 8"'), 'у клетки — день, час и число');
    ok(str_contains($page, 'Неверный пароль') && str_contains($page, 'zz_new'), 'причины подписаны; новая не теряется');
    ok(str_contains($page, 'Другие') && str_contains($page, '>Opera<') && !str_contains($page, '>Яндекс Браузер<'), 'хвост браузеров свёрнут в «Другие»');
    ok(str_contains($page, '75 %'), 'доля способа входа');
} finally {
    if ($ids) DB::run('DELETE FROM {audit_log} WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
    if ($tzWas === null) Setting::delete('app.timezone'); else Setting::set('app.timezone', $tzWas);
}

echo "\nПройдено: $passed, провалено: $failed\n";
exit($failed === 0 ? 0 : 1);
