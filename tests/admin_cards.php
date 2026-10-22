<?php
/**
 * Карточки объектов в админке (v4.69) — по замечаниям владельца 13.09.
 *
 *   1. Карта ролей показывала admin и user по два раза: в ней стояли и общие
 *      роли SSO, и роли самого портала с теми же слагами, а поля формы
 *      назывались по слагу — второе при сохранении затирало первое. Теперь в
 *      карте только общие роли, а роли портала уходят ему как есть.
 *   2. В «Пользователях» организации «Полномочие» и «Роли» показывали одно и то
 *      же, а людям её сервисов роль на организацию выдать было нельзя. Теперь
 *      один список; выданная роль делает человека из сервиса участником.
 *   3. Шапка карточки: имя объекта стояло и в крошках, и строкой ниже. Теперь
 *      крошки ведут к родителю, в шапке — лицо, имя и копируемый идентификатор.
 *
 * Запуск: php tests/admin_cards.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\RoleOffer;
use App\Core\Scope;
use App\Models\Access;
use App\Models\Client;
use App\Models\ClientRoleMap;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$JAR = sys_get_temp_dir() . '/admin-cards.txt';
@unlink($JAR);
$stamp = bin2hex(random_bytes(3));
$cid = 0;
$orgId = 0;
$people = [];

DB::run('UPDATE {users} SET totp_secret = NULL, totp_enabled_at = NULL WHERE email = ?', ['admin@test.local']);
DB::run('DELETE FROM {rate_limits}');
[, $lp] = http('GET', SSO . '/login');
http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => 'admin@test.local',
    'password' => 'correct-horse-battery']);

$person = static function (array $fields) use (&$people): int {
    $uid = User::create($fields + ['first_name' => 'Проба', 'status' => 'active']);
    $people[] = $uid;
    return $uid;
};
$isMember = static fn(int $org, int $uid): bool
    => (bool)DB::val('SELECT 1 FROM {org_members} WHERE org_id = ? AND user_id = ?', [$org, $uid]);

try {
    [$cid] = Client::create([
        'name' => 'Проба карточек', 'slug' => 'cards-' . $stamp, 'base_url' => 'https://cards-' . $stamp . '.example',
        'redirect_uris' => ['https://cards-' . $stamp . '.example/cb'],
        'post_logout_uris' => ['https://cards-' . $stamp . '.example/'],
        'backchannel_uri' => null, 'is_trusted' => 1, 'access_policy' => 'open',
    ]);
    $cid = (int)$cid;

    echo "── Карта ролей: только общие роли SSO ──\n";
    $ownAdmin = Role::create($cid, 'admin', 'Администратор сервиса');
    Role::create($cid, 'user', 'Пользователь');
    ClientRoleMap::replace($cid, ['admin' => 'boss', 'user' => 'member']);
    ok((int)DB::val('SELECT COUNT(*) FROM {client_role_map} WHERE client_id = ?', [$cid]) === 2,
        'в карте две строки — общие admin и user, а не четыре вместе с одноимёнными ролями портала');
    $map = ClientRoleMap::forClient($cid);
    ksort($map);
    ok($map === ['admin' => 'boss', 'user' => 'member'], 'карта читается по общим ролям', json_encode($map));

    $u1 = $person(['email' => "cards-map-$stamp@test.local", 'email_verified_at' => now()]);
    Role::assign($u1, (int)Role::findBySlug('user')['id']);
    Role::assign($u1, $ownAdmin);
    $claim = User::rolesClaim($u1, $cid);
    ok(in_array('admin', $claim, true) && in_array('member', $claim, true) && !in_array('boss', $claim, true),
        'роль портала уходит ему как есть, общая роль — по карте', json_encode($claim));

    [, $rolesPg] = http('GET', SSO . "/admin/clients/$cid/roles");
    ok(substr_count($rolesPg, 'name="map[admin]"') === 1 && substr_count($rolesPg, 'name="map[user]"') === 1,
        'в форме карты каждая роль один раз: поля больше не затирают друг друга');

    echo "\n── Люди организации: один список ──\n";
    $orgId = Org::create('Проба карточек ' . $stamp, null, null);
    Client::update($cid, ['org_id' => $orgId]);
    $member = $person(['email' => "cards-member-$stamp@test.local", 'email_verified_at' => now()]);
    Org::addMember($orgId, $member, 'member', null);
    $svc = $person(['email' => "cards-svc-$stamp@test.local", 'email_verified_at' => now()]);
    Access::grant($svc, $cid, null, 'active');
    $svc2 = $person(['email' => "cards-svc2-$stamp@test.local", 'email_verified_at' => now()]);
    Access::grant($svc2, $cid, null, 'active');
    $byLogin = $person(['login' => 'cards' . $stamp, 'email' => null]);

    [, $op] = http('GET', SSO . "/admin/orgs/$orgId/people");
    ok(str_contains($op, 'Люди организации') && str_contains($op, "cards-member-$stamp@test.local")
        && str_contains($op, "cards-svc-$stamp@test.local"),
        'участники и люди её сервисов — в одном списке');
    ok(!str_contains($op, 'value="set_role"'),
        '«Полномочие» отдельным органом не рисуется: это те же роли, что в столбце рядом');

    $offer = RoleOffer::inScope(Scope::org($orgId));
    $pick = null;
    foreach ($offer as $r) if ($r['slug'] === 'org_admin') $pick = $r;
    $pick ??= $offer[0] ?? null;
    ok($pick !== null, 'на организацию есть что выдать', json_encode(array_column($offer, 'slug')));
    if ($pick !== null) {
        [$st, , $loc] = http('POST', SSO . "/admin/orgs/$orgId/action", ['_csrf' => csrfOf($op),
            'action' => 'role_grant', 'user_id' => $svc, 'role_id' => (int)$pick['id']]);
        $granted = array_filter(Role::grantsInScope(Scope::org($orgId)),
            static fn(array $g): bool => (int)$g['user_id'] === $svc && (int)$g['role_id'] === (int)$pick['id']);
        ok($st === 302 && $granted !== [] && $isMember($orgId, $svc),
            'роль на организацию выдана человеку из сервиса — и он стал участником', "st=$st");
        ok(str_ends_with((string)$loc, "/admin/orgs/$orgId/people"), 'и возвращает в «Пользователей»', (string)$loc);
    }

    http('POST', SSO . "/admin/orgs/$orgId/action", ['_csrf' => csrfOf($op),
        'action' => 'member_add', 'user_id' => $svc2]);
    ok($isMember($orgId, $svc2), 'человека из сервиса можно взять в состав кнопкой в его строке');

    http('POST', SSO . "/admin/orgs/$orgId/action", ['_csrf' => csrfOf($op),
        'action' => 'member_add', 'email' => 'cards' . $stamp, 'role' => 'member']);
    ok($isMember($orgId, $byLogin),
        'в состав добавляют и по логину: форма обещала «почта или логин», а искала только по почте');

    echo "\n── Шапка карточки ──\n";
    [, $op2] = http('GET', SSO . "/admin/orgs/$orgId/people");
    preg_match('~<nav class="breadcrumbs"[^>]*>(.*?)</nav>~s', $op2, $bc);
    ok(isset($bc[1]) && str_contains($bc[1], 'Организации') && !str_contains($bc[1], 'Проба карточек'),
        'крошки ведут к родителю, имя объекта в них не повторяется');
    ok(str_contains($op2, '<span class="rail-badge"><span aria-hidden="true" style="width:40px;height:40px;border-radius:10px') && str_contains($op2, 'data-copy="rail-mark-'),
        'в шапке — знак организации и кнопка копирования идентификатора');
    [, $cp] = http('GET', SSO . "/admin/clients/$cid/people");
    ok(str_contains($cp, 'class="rail-badge"'), 'у портала в шапке — его лицо');

    echo "\n── Экраны после ревизии v4.70 ──\n";
    /* Выдача роли раскрывалась <details> в самой строке: строка вырастала
       вдвое, соседние уезжали вниз. Теперь — окно в верхнем слое. */
    ok(str_contains($op2, 'popovertarget="role-add-')
        && preg_match('~<div class="role-add" id="role-add-[^"]+" popover>~', $op2) === 1,
        'выдача роли — всплывающее окно, строка таблицы не растёт');
    ok(!str_contains($op2, '<details class="role-add'), 'раскрытия внутри строки больше нет');

    /* Плитка портала: метки — только отличия от обычного. Режем свою плитку
       из списка, чтобы чужие порталы стенда не влияли на ответ. */
    $tileOf = static function (string $html, string $name): string {
        $at = strpos($html, '>' . $name . '<');
        if ($at === false) return '';
        $from = strrpos(substr($html, 0, $at), '<div class="bento-card');
        $to = strpos($html, '<div class="bento-card', $at);
        return substr($html, (int)$from, ($to === false ? strlen($html) : $to) - (int)$from);
    };
    [, $list] = http('GET', SSO . '/admin/clients');
    $tile = $tileOf($list, 'Проба карточек');
    ok(str_contains($tile, 'list-card') && !str_contains($tile, 'Карточка →'),
        'портал — плиткой, щёлкаемой целиком, без повторной ссылки «Карточка →»');
    ok(!str_contains($tile, 'first-party') && !str_contains($tile, '>open<') && !str_contains($tile, 'badge'),
        'у обычного портала меток нет — ни «first-party», ни «open», ни «активен»');
    DB::update('clients', ['is_trusted' => 0, 'access_policy' => 'invite', 'is_active' => 0], 'id = ?', [$cid]);
    [, $list] = http('GET', SSO . '/admin/clients');
    $tile = $tileOf($list, 'Проба карточек');
    ok(str_contains($tile, 'сторонний') && str_contains($tile, 'По приглашению')
        && str_contains($tile, 'выключен') && str_contains($tile, 'is-dimmed'),
        'особенный — словами: сторонний, по приглашению, выключен', strip_tags($tile));
    DB::update('clients', ['is_trusted' => 1, 'access_policy' => 'open', 'is_active' => 1], 'id = ?', [$cid]);

    /* v4.91: карточка портала открывается обзором, реквизиты — на /req. */
    [$st, $ov] = http('GET', SSO . "/admin/clients/$cid");
    ok($st === 200 && str_contains($ov, '<div class="bc-title">Как дела</div>') && str_contains($ov, 'class="bento-card col-3 list-card stat-tile')
        && str_contains($ov, "href=\"/admin/clients/$cid/req\""), 'карточка портала открывается обзором: плитки, «Как дела», дорога к реквизитам');
    [, $req] = http('GET', SSO . "/admin/clients/$cid/req");
    ok(str_contains($req, 'name="access_policy" value="approval"') && str_contains($req, 'Человек просит, вы одобряете'),
        '«Кого пускать» — карточками с объяснением в каждой: пункты списка обрезались на полуслове');
    [, , $loc] = http('POST', SSO . "/admin/clients/$cid/update", ['_csrf' => csrfOf($req), 'name' => 'Проба карточек ' . $stamp,
        'base_url' => 'https://cards-' . $stamp . '.example', 'slug' => 'cards-' . $stamp, 'redirect_uris' => 'https://cards-' . $stamp . '.example/cb',
        'access_policy' => 'open', 'is_active' => '1', 'is_trusted' => '1']);
    ok(str_ends_with((string)$loc, "/admin/clients/$cid/req"), 'после сохранения реквизитов — обратно в реквизиты, а не в обзор', (string)$loc);

    /* v4.91: «Выключить организацию» в обзоре с 4.29 слало действие, которого
       нет, — выключение молча не работало. Теперь тумблер в той же форме. */
    [, $oo] = http('GET', SSO . "/admin/orgs/$orgId");
    http('POST', SSO . "/admin/orgs/$orgId/action", ['_csrf' => csrfOf($oo), 'action' => 'rename', 'name' => 'Проба карточек ' . $stamp,
        'description' => '', 'active_present' => '1']);
    $off = (int)DB::val('SELECT is_active FROM {orgs} WHERE id = ?', [$orgId]);
    http('POST', SSO . "/admin/orgs/$orgId/action", ['_csrf' => csrfOf($oo), 'action' => 'rename', 'name' => 'Проба карточек ' . $stamp,
        'description' => '', 'active_present' => '1', 'is_active' => '1']);
    ok($off === 0 && (int)DB::val('SELECT is_active FROM {orgs} WHERE id = ?', [$orgId]) === 1
        && str_contains($oo, 'name="is_active"') && !str_contains($oo, "value=\"disable\"") && !str_contains($oo, "value=\"enable\""),
        'организацию выключают и включают тумблером в обзоре — и это правда срабатывает');
    ok(!str_contains($req, 'Человек не увидит экран'),
        'подсказка «Доверенный» не обещает, что экрана первого входа не будет');

    [, $dash] = http('GET', SSO . '/admin');
    ok(str_contains($dash, 'за 14 дней:'), 'у неудачных входов на дашборде есть подпись срока');
    ok(str_contains($dash, 'class="event-label'), 'события на дашборде — словами');
    [, $logs] = http('GET', SSO . '/admin/logs');
    ok(str_contains($logs, 'class="event-label'), 'журнал аудита — словами');
    ok(\App\Core\Audit::metaText('{"reason":"password","fresh":true,"to":"/a/b"}')
        === 'reason: password · fresh: да · to: /a/b', 'детали события — строкой «ключ: значение», а не сырым JSON');
    ok(\App\Core\Audit::title('client.users_secret') === 'Выдан ключ переноса'
        && \App\Core\Audit::title('zzz.unknown') === 'zzz.unknown',
        'словарь событий один; незнакомое событие остаётся машинным именем');

    [, $roles] = http('GET', SSO . '/admin/roles');
    ok(!str_contains($roles, '>системная<'), 'у встроенных ролей нет метки на каждой строке');
} finally {
    if ($orgId > 0) Org::purge($orgId);
    if ($cid > 0) Client::purge($cid);
    foreach ($people as $uid) User::purge($uid);
    @unlink($JAR);
}

echo "\n══ Итог ══\nПройдено: $passed, провалено: $failed\n";
exit($failed > 0 ? 1 : 0);
