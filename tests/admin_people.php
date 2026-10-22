<?php
/**
 * Экраны людей в админке (v4.92) — следующий шаг переработки основных экранов.
 *
 * Здесь проверяется, что:
 *   · «Отозвать» в «Ролях» снимает роль только в своей строке. До 4.92 форма
 *     слала одну роль, и снятие «Админа портала» с одного сервиса снимало его
 *     со всех сервисов разом;
 *   · в «Ролях» название впереди, область — ссылкой, выдача на удалённый
 *     сервис названа прямо и её можно убрать;
 *   · «Сессии» и «Устройства» сложены по устройству, у каждого — «Завершить»,
 *     свою текущую сессию так не закрыть, чужую — тоже;
 *   · «Действия» — карточки со словами, а не стопка кнопок; удаление — не
 *     сплошной красной кнопкой; суперадмину блокировку не предлагают;
 *   · длинное имя в списке людей — одной строкой, полное — в подсказке.
 *
 * Запуск: php tests/admin_people.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\Scope;
use App\Models\Client;
use App\Models\Role;
use App\Models\SessionModel;
use App\Models\User;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$JAR = sys_get_temp_dir() . '/admin-people.txt';
@unlink($JAR);
$stamp = bin2hex(random_bytes(3));
$clients = [];
$people = [];
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);

DB::run('UPDATE {users} SET totp_secret = NULL, totp_enabled_at = NULL WHERE email = ?', ['admin@test.local']);
DB::run('DELETE FROM {rate_limits}');
[, $lp] = http('GET', SSO . '/login');
http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => 'admin@test.local',
    'password' => 'correct-horse-battery']);
$adminId = (int)DB::val('SELECT id FROM {users} WHERE email = ?', ['admin@test.local']);
/* Сессия этого прогона — последняя выданная админу: прогоны идут по одному. */
$mySid = (int)DB::val('SELECT MAX(id) FROM {sessions} WHERE user_id = ? AND revoked_at IS NULL', [$adminId]);

$person = static function (array $fields) use (&$people): int {
    $uid = User::create($fields + ['first_name' => 'Проба', 'status' => 'active']);
    $people[] = $uid;
    return $uid;
};
$client = static function (string $name) use (&$clients, $stamp): int {
    $slug = 'people-' . count($clients) . '-' . $stamp;
    [$cid] = Client::create(['name' => $name, 'slug' => $slug, 'base_url' => "https://$slug.example",
        'redirect_uris' => ["https://$slug.example/cb"], 'post_logout_uris' => ["https://$slug.example/"],
        'backchannel_uri' => null, 'is_trusted' => 1, 'access_policy' => 'open']);
    $clients[] = (int)$cid;
    return (int)$cid;
};
/* Сессия напрямую в базу: браузер, адрес и время — те, что нужны строке. */
$session = static function (int $uid, string $label, string $ip, int $ago = 60) : int {
    $at = gmdate('Y-m-d H:i:s', time() - $ago);
    return DB::insert('sessions', ['user_id' => $uid, 'token_hash' => hash('sha256', random_bytes(16)),
        'ip' => inet_pton($ip), 'user_agent' => 'Mozilla/5.0 test', 'device_label' => $label, 'is_remembered' => 0,
        'created_at' => $at, 'last_seen_at' => $at, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
};
$alive = static fn(int $sid): bool => DB::val('SELECT revoked_at FROM {sessions} WHERE id = ?', [$sid]) === null;

try {
    echo "── Роли: «Отозвать» снимает только свою строку ──\n";
    $a = $client('Проба А ' . $stamp);
    $b = $client('Проба Б ' . $stamp);
    $u = $person(['email' => "people-$stamp@test.local", 'display_name' => 'Проба ролей']);
    $ca = Role::findBySlug('client_admin');
    $caId = (int)$ca['id'];
    Role::assignScoped($u, $caId, Scope::client($a));
    Role::assignScoped($u, $caId, Scope::client($b));
    Role::assignScoped($u, $caId, Scope::client(987654));
    $grants = static fn(): array => array_map(static fn(array $r): int => (int)$r['scope_id'], DB::all(
        "SELECT scope_id FROM {user_roles} WHERE user_id = ? AND role_id = ? AND scope_type = 'client' ORDER BY scope_id", [$u, $caId]));

    [, $rp] = http('GET', SSO . "/admin/users/$u/roles");
    ok(str_contains($rp, '<span class="cell-main">Админ портала</span>') && str_contains($rp, '<span class="mono">client_admin</span> · уровень 70'),
        'название роли впереди, машинное имя и уровень — строкой под ним');
    ok(str_contains($rp, '<a href="/admin/clients/' . $a . '">сервис Проба А ' . $stamp . '</a>'), 'область — ссылкой на сервис');
    ok(str_contains($rp, 'удалённый сервис #987654') && str_contains($rp, '>Убрать<') && !str_contains($rp, '>сервис #987654<'),
        'выдача на удалённый сервис названа прямо, и её предлагают убрать');
    ok(str_contains($rp, 'name="scope_type" value="client"') && str_contains($rp, 'name="scope_id" value="' . $b . '"'),
        'форма строки шлёт свою область');

    [$st] = http('POST', SSO . "/admin/users/$u/roles", ['_csrf' => csrfOf($rp), 'action' => 'revoke',
        'role_id' => $caId, 'scope_type' => 'client', 'scope_id' => $a]);
    ok($st === 302 && $grants() === [$b, 987654], 'сняли на сервисе А — на Б и прочих роль осталась', json_encode($grants()));
    http('POST', SSO . "/admin/users/$u/roles", ['_csrf' => csrfOf($rp), 'action' => 'revoke',
        'role_id' => $caId, 'scope_type' => 'client', 'scope_id' => 987654]);
    ok($grants() === [$b], 'выдача на удалённый сервис убирается той же кнопкой');
    $last = DB::row("SELECT meta FROM {audit_log} WHERE event = 'admin.role.revoke' AND on_user_id = ? ORDER BY id DESC LIMIT 1", [$u]);
    ok(str_contains((string)($last['meta'] ?? ''), 'client:987654'), 'в журнале — из какой области сняли', (string)($last['meta'] ?? ''));
    Role::assignScoped($u, $caId, Scope::client($a));
    http('POST', SSO . "/admin/users/$u/roles", ['_csrf' => csrfOf($rp), 'action' => 'revoke', 'role_id' => $caId]);
    ok($grants() === [], 'форма без области (открытая до обновления) снимает, как раньше, все выдачи');

    echo "\n── Сессии: по устройству ──\n";
    $rows = [
        ['id' => 1, 'device_label' => 'Chrome · Windows', 'ip' => inet_pton('10.0.0.1'), 'user_agent' => 'Windows', 'created_at' => '2026-09-20 10:00:00', 'last_seen_at' => '2026-09-26 10:00:00', 'is_remembered' => 0],
        ['id' => 2, 'device_label' => 'Chrome · Windows', 'ip' => inet_pton('10.0.0.1'), 'user_agent' => 'Windows', 'created_at' => '2026-09-19 10:00:00', 'last_seen_at' => '2026-09-27 09:00:00', 'is_remembered' => 1],
        ['id' => 3, 'device_label' => 'Safari · iPhone', 'ip' => inet_pton('10.0.0.2'), 'user_agent' => 'iPhone Mobile', 'created_at' => '2026-09-25 10:00:00', 'last_seen_at' => '2026-09-25 11:00:00', 'is_remembered' => 0],
        ['id' => 4, 'device_label' => 'Chrome · Windows', 'ip' => inet_pton('10.0.0.9'), 'user_agent' => 'Windows', 'created_at' => '2026-09-27 08:00:00', 'last_seen_at' => '2026-09-27 08:00:00', 'is_remembered' => 0],
    ];
    $g = SessionModel::groups($rows, 3);
    ok(count($g) === 3 && $g[0]['ids'] === [3] && $g[0]['current'] && $g[0]['mobile'], 'текущее устройство — первым, телефон узнан');
    ok($g[1]['ids'] === [1, 2] && $g[1]['count'] === 2 && $g[1]['remembered']
        && $g[1]['created_at'] === '2026-09-19 10:00:00' && $g[1]['last_seen_at'] === '2026-09-27 09:00:00',
        'один браузер с одного адреса — одна строка: число, «впервые» — самое раннее, активность — самая свежая');
    ok($g[2]['ids'] === [4], 'тот же браузер с другого адреса — отдельной строкой');

    $s1 = $session($u, 'Chrome · Windows', '10.0.0.1', 30);
    $s2 = $session($u, 'Chrome · Windows', '10.0.0.1', 90);
    $s3 = $session($u, 'Chrome · Windows', '10.0.0.1', 120);
    $s4 = $session($u, 'Firefox · Linux', '10.0.0.2', 600);
    $other = $person(['email' => "people-other-$stamp@test.local"]);
    $foreign = $session($other, 'Chrome · Windows', '10.0.0.1');
    [, $sp] = http('GET', SSO . "/admin/users/$u/sessions");
    ok(str_contains($sp, '4 сессии на 2 устройствах') && str_contains($sp, '>3 сессии<'), 'сверху — сколько сессий и устройств, у строки — число сессий');
    ok(substr_count($sp, 'value="logout_sessions"') === 2 && str_contains($sp, 'name="session_ids" value="' . "$s1,$s2,$s3" . '"'),
        'у каждого устройства своё «Завершить» со всеми его сессиями');
    ok(strpos($sp, 'value="logout_sessions"') < strpos($sp, 'value="logout_everywhere"'), '«Разлогинить везде» — под списком, а не первой кнопкой');

    [$st, , $loc] = http('POST', SSO . "/admin/users/$u/action", ['_csrf' => csrfOf($sp), 'action' => 'logout_sessions',
        'session_ids' => "$s1,$s2,$s3,$foreign"]);
    ok(!$alive($s1) && !$alive($s2) && !$alive($s3) && $alive($s4), 'завершено одно устройство, второе живо');
    ok($alive($foreign), 'чужую сессию в списке номеров не закрыть');
    ok($st === 302 && str_ends_with($loc, "/admin/users/$u/sessions"), 'после — обратно в «Сессии»', $loc);
    $meta = (string)DB::val("SELECT meta FROM {audit_log} WHERE event = 'session.revoke' AND on_user_id = ? ORDER BY id DESC LIMIT 1", [$u]);
    ok(str_contains($meta, '"by":"admin"') && str_contains($meta, (string)$s1), 'в журнале — кто и какие сессии', $meta);

    /* Своя текущая сессия со своей же карточки — только «остальные». */
    $mine2 = $session($adminId, 'Проба · Прогон', '10.9.9.9');
    [, $own] = http('GET', SSO . "/admin/users/$adminId/sessions");
    ok(str_contains($own, '>ваша текущая<'),
        'своё устройство отмечено «ваша текущая»');
    http('POST', SSO . "/admin/users/$adminId/action", ['_csrf' => csrfOf($own), 'action' => 'logout_sessions',
        'session_ids' => "$mySid,$mine2"]);
    [$stAfter] = http('GET', SSO . '/admin');
    ok($alive($mySid) && !$alive($mine2) && $stAfter === 200, 'свою текущую сессию строкой не закрыть — вход не обрывается');

    echo "\n── Устройства в профиле ──\n";
    $p1 = $session($adminId, 'Edge · Windows', '10.8.8.8', 30);
    $p2 = $session($adminId, 'Edge · Windows', '10.8.8.8', 60);
    [, $dev] = http('GET', SSO . '/profile/sessions');
    ok(str_contains($dev, '>текущая<') && str_contains($dev, 'name="session_ids" value="' . "$p1,$p2" . '"'),
        'в «Устройствах» — те же группы: текущая отмечена, у чужого устройства все его сессии');
    http('POST', SSO . '/profile/sessions/revoke', ['_csrf' => csrfOf($dev), 'session_ids' => "$p1,$p2,$mySid,$s4"]);
    ok(!$alive($p1) && !$alive($p2) && $alive($mySid) && $alive($s4),
        'завершилось своё устройство; текущая и сессии другого человека не тронуты');
    $p3 = $session($adminId, 'Edge · Windows', '10.8.8.7');
    [, $dev] = http('GET', SSO . '/profile/sessions');
    http('POST', SSO . '/profile/sessions/revoke', ['_csrf' => csrfOf($dev), 'session_id' => $p3]);
    ok(!$alive($p3), 'прежнее одиночное поле формы тоже работает');

    echo "\n── Действия ──\n";
    [, $ac] = http('GET', SSO . "/admin/users/$u/actions");
    ok(str_contains($ac, '>Помочь войти<') && str_contains($ac, '>Доступ к аккаунту<') && str_contains($ac, '>Номер аккаунта<'),
        'действия разложены по карточкам');
    ok(substr_count($ac, 'class="act-item"') >= 5 && str_contains($ac, 'Уйдёт письмом на people-' . $stamp . '@test.local'),
        'у каждого действия строкой сказано, что оно сделает');
    ok(!str_contains($ac, 'btn-block') && !preg_match('~class="btn btn-s? ?btn-danger"~', $ac)
        && str_contains($ac, 'value="delete"') && str_contains($ac, "/admin/users/$u/danger"),
        'удаление — не сплошной красной кнопкой; рядом — дорога в опасную зону');
    ok(pageClean($ac) && str_contains($ac, 'data-digits="account_number"'), 'номер аккаунта — клетками, страница целая');
    [, $acSuper] = http('GET', SSO . "/admin/users/$adminId/actions");
    ok(str_contains($acSuper, 'Это суперадмин') && !str_contains($acSuper, 'value="block"') && !str_contains($acSuper, 'value="delete"'),
        'суперадмину блокировку и удаление не предлагают — объясняют, почему');
    DB::run("UPDATE {users} SET status = 'blocked' WHERE id = ?", [$u]);
    [, $acBlocked] = http('GET', SSO . "/admin/users/$u/actions");
    ok(str_contains($acBlocked, 'value="unblock"') && str_contains($acBlocked, 'Аккаунт заблокирован'), 'заблокированному — «Разблокировать»');

    echo "\n── Список людей ──\n";
    $long = str_repeat('Очень длинное имя бота ', 6) . $stamp;
    $person(['email' => "people-long-$stamp@test.local", 'display_name' => $long]);
    [, $list] = http('GET', SSO . '/admin/users?q=' . urlencode($stamp));
    ok(str_contains($list, 'class="cell-main truncate"') && str_contains($list, 'title="' . esc($long) . '"'),
        'длинное имя — одной строкой, полное — в подсказке');
    $css = $src('public/assets/css/app.css');
    ok(str_contains($css, '.name-line { display: flex; align-items: center; gap: 4px; max-width: 26rem;'),
        'ширина строки имени — в rem: процент от ячейки колонку не держит');
    ok(str_contains($css, '.table-cards .cell-primary:first-child { grid-column: 1 / -1; }'),
        'на телефоне строка без аватара начинается от края');
    ok(str_contains($css, 'flex: 0 1 38px; min-width: 24px;'), 'клетки номера сжимаются в половинной карточке');
} finally {
    foreach ($clients as $cid) Client::purge($cid);
    foreach ($people as $uid) User::purge($uid);
    DB::run('DELETE FROM {sessions} WHERE user_id = ? AND id <> ? AND device_label IN (?, ?)', [$adminId, $mySid, 'Проба · Прогон', 'Edge · Windows']);
    @unlink($JAR);
}

echo "\n══ Итог ══\nПройдено: $passed, провалено: $failed\n";
exit($failed > 0 ? 1 : 0);
