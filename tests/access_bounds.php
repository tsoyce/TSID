<?php
/**
 * Границы прав (v4.98) — находки ревизии кода, каждая со своей проверкой.
 *
 * Здесь проверяется, что:
 *   · приглашение портала не выдаёт роль, которой в портале не место, — ни при
 *     создании, ни при приёме старого инвайта. До 4.98 Role::assign() выдавал
 *     роль без client_id на всю систему, и админ портала делал себя
 *     суперадмином одной ссылкой;
 *   · admin (не суперадмин) не делает ссылку входа в аккаунт суперадмина и не
 *     скачивает настройки с секретами;
 *   · SCIM пускает токен «*», не блокирует суперадмина, блокировка гасит
 *     refresh-токены, удаление группы снимает её роли (purge, а не delete);
 *   · Admin API: roles:write не выдаёт superadmin, settings:write не пишет
 *     владельца и служебные ключи, правка логина проверяет правила;
 *   · админ портала не перезапускает события чужого портала и не сохраняет
 *     адрес сервиса вида javascript:.
 *
 * Запуск: php tests/access_bounds.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\Scope;
use App\Models\ApiToken;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$JAR = sys_get_temp_dir() . '/access-bounds.txt';
$ADMIN_JAR = sys_get_temp_dir() . '/access-bounds-admin.txt';
@unlink($JAR);
@unlink($ADMIN_JAR);
$stamp = bin2hex(random_bytes(3));
$clients = [];
$people = [];
$orgs = [];
$tokens = [];

/** Запрос к API/SCIM с токеном: [код, JSON]. */
function bearer(string $method, string $url, string $token, array $body = []): array
{
    $ch = curl_init($url);
    $h = ['Accept: application/json', 'Authorization: Bearer ' . $token];
    if ($body !== []) $h[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h]);
    if ($body !== []) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true) ?: []];
}

$person = static function (array $fields) use (&$people): int {
    $uid = User::create($fields + ['first_name' => 'Граница', 'status' => 'active']);
    $people[] = $uid;
    return $uid;
};
$client = static function (string $name) use (&$clients, $stamp): int {
    $slug = 'bounds-' . count($clients) . '-' . $stamp;
    [$cid] = Client::create(['name' => $name, 'slug' => $slug, 'base_url' => "https://$slug.example",
        'redirect_uris' => ["https://$slug.example/cb"], 'post_logout_uris' => ["https://$slug.example/"],
        'backchannel_uri' => null, 'is_trusted' => 1, 'access_policy' => 'open']);
    $clients[] = (int)$cid;
    return (int)$cid;
};
$login = static function (string $identity, string $password, string $jar): void {
    DB::run('DELETE FROM {rate_limits}');
    [, $lp] = http('GET', SSO . '/login', [], [], $jar);
    http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => $identity, 'password' => $password], [], $jar);
};
$superId = (int)DB::val('SELECT id FROM {users} WHERE email = ?', ['admin@test.local']);
$superRole = Role::findBySlug('superadmin');

try {
    DB::run('UPDATE {users} SET totp_secret = NULL, totp_enabled_at = NULL WHERE email = ?', ['admin@test.local']);
    $login('admin@test.local', 'correct-horse-battery', $JAR);

    echo "── Приглашение: роль только из области портала ──\n";
    $a = $client('Граница А ' . $stamp);
    [, $inv] = http('GET', SSO . "/admin/clients/$a/invites");
    ok(!preg_match('~<option value="' . (int)$superRole['id'] . '"~', $inv), 'суперадмина в списке ролей приглашения нет');
    $before = (int)DB::val('SELECT COUNT(*) FROM {invites} WHERE client_id = ?', [$a]);
    http('POST', SSO . "/admin/clients/$a/action", ['_csrf' => csrfOf($inv), 'action' => 'create_invite',
        'invite_role' => (int)$superRole['id'], 'invite_uses' => 1, 'invite_days' => 1]);
    ok((int)DB::val('SELECT COUNT(*) FROM {invites} WHERE client_id = ?', [$a]) === $before,
        'приглашение с ролью суперадмина подделкой формы не создаётся');

    /* Старый инвайт, созданный до починки: приём не выдаёт системную роль. */
    $guest = $person(['email' => "bounds-guest-$stamp@test.local"]);
    $token = \App\Models\Invite::create($a, ['role_id' => (int)$superRole['id']], $superId);
    \App\Models\Invite::accept(\App\Models\Invite::findUsable($token), $guest);
    ok(!in_array('superadmin', User::globalRoleSlugs($guest), true), 'приём старого инвайта суперадмином не делает');
    ok(\App\Models\Access::get($guest, $a) !== null, 'а доступ к порталу по нему выдаётся, как раньше');

    $ca = Role::clientAdmin();
    $token = \App\Models\Invite::create($a, ['role_id' => (int)$ca['id']], $superId);
    \App\Models\Invite::accept(\App\Models\Invite::findUsable($token), $guest);
    ok((int)DB::val("SELECT COUNT(*) FROM {user_roles} WHERE user_id = ? AND role_id = ? AND scope_type = 'client' AND scope_id = ?",
        [$guest, (int)$ca['id'], $a]) === 1, 'уместная роль выдаётся в области портала');

    echo "\n── Админ без суперадмина ──\n";
    $adminRole = Role::findBySlug('admin');
    $plain = $person(['email' => "bounds-admin-$stamp@test.local",
        'password_hash' => password_hash('bounds-password-1', PASSWORD_ARGON2ID)]);
    Role::assignScoped($plain, (int)$adminRole['id'], Scope::system());
    $login("bounds-admin-$stamp@test.local", 'bounds-password-1', $ADMIN_JAR);
    [$st, $card] = http('GET', SSO . "/admin/users/$superId/actions", [], [], $ADMIN_JAR);
    $csrf = csrfOf($card);
    ok($st === 200 && $csrf !== '', 'admin открывает карточку', "код $st");
    $links = static fn(): int => (int)DB::val("SELECT COUNT(*) FROM {one_time_tokens} WHERE user_id = ? AND type = 'login_link'", [$superId]);
    $was = $links();
    [$st] = http('POST', SSO . "/admin/users/$superId/action", ['_csrf' => $csrf, 'action' => 'login_link'], [], $ADMIN_JAR);
    ok($st === 403 && $links() === $was, 'ссылку входа в аккаунт суперадмина admin не получает', "код $st");
    [$st] = http('GET', SSO . '/admin/tools/settings.json?with_secrets=1', [], [], $ADMIN_JAR);
    ok($st === 403, 'настройки с секретами admin не скачивает', "код $st");

    echo "\n── SCIM ──\n";
    [$tid, $all] = ApiToken::create('границы ' . $stamp, [ApiToken::SCOPE_ALL], null, 1);
    $tokens[] = $tid;
    [$st] = bearer('GET', SSO . '/scim/v2/Users?count=1', $all);
    ok($st === 200, 'токен «*» пускают в SCIM', "код $st");
    $superPub = (string)DB::val('SELECT public_id FROM {users} WHERE id = ?', [$superId]);
    [$st] = bearer('PATCH', SSO . '/scim/v2/Users/' . $superPub, $all,
        ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);
    ok($st === 403 && DB::val('SELECT status FROM {users} WHERE id = ?', [$superId]) === 'active',
        'суперадмина SCIM не блокирует', "код $st");

    $victim = $person(['email' => "bounds-victim-$stamp@test.local"]);
    DB::insert('refresh_tokens', ['token_hash' => hash('sha256', random_bytes(16)), 'family_id' => bin2hex(random_bytes(8)),
        'user_id' => $victim, 'client_id' => $a, 'scope' => 'openid', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        'created_at' => now()]);
    $vPub = (string)DB::val('SELECT public_id FROM {users} WHERE id = ?', [$victim]);
    [$st] = bearer('PATCH', SSO . '/scim/v2/Users/' . $vPub, $all,
        ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);
    ok($st === 200 && DB::val('SELECT status FROM {users} WHERE id = ?', [$victim]) === 'blocked', 'обычного — блокирует', "код $st");
    ok((int)DB::val('SELECT COUNT(*) FROM {refresh_tokens} WHERE user_id = ? AND revoked_at IS NULL', [$victim]) === 0,
        'и его refresh-токены отозваны: портал больше не обновит доступ');

    $org = \App\Models\Org::create('Граница ' . $stamp, null, null);
    $orgs[] = $org;
    Role::assignScoped($guest, (int)Role::findBySlug('org_admin')['id'], Scope::org($org));
    [$st] = bearer('DELETE', SSO . '/scim/v2/Groups/' . $org, $all);
    ok($st === 204 && (int)DB::val("SELECT COUNT(*) FROM {user_roles} WHERE scope_type = 'org' AND scope_id = ?", [$org]) === 0,
        'удаление группы снимает выдачи ролей на неё', "код $st");

    echo "\n── Admin API ──\n";
    [$tid, $rolesTok] = ApiToken::create('границы роли ' . $stamp, ['roles:write', 'settings:write'], null, 1);
    $tokens[] = $tid;
    $gPub = (string)DB::val('SELECT public_id FROM {users} WHERE id = ?', [$guest]);
    [$st] = bearer('POST', SSO . "/api/v1/users/$gPub/roles", $rolesTok, ['role' => 'superadmin', 'scope' => 'system']);
    ok($st === 403 && !in_array('superadmin', User::globalRoleSlugs($guest), true), 'roles:write не выдаёт superadmin', "код $st");
    $owner = DB::val('SELECT `value` FROM {settings} WHERE `key` = ?', ['system.owner_user_id']);
    [$st] = bearer('PATCH', SSO . '/api/v1/settings', $all, ['system.owner_user_id' => (string)$guest]);
    ok($st === 403 && DB::val('SELECT `value` FROM {settings} WHERE `key` = ?', ['system.owner_user_id']) === $owner,
        'владелец через API не меняется даже токеном «*»', "код $st");
    [$st] = bearer('PATCH', SSO . '/api/v1/settings', $rolesTok, ['system.backup_at' => '2000-01-01 00:00:00']);
    ok($st === 403, 'служебный ключ settings:write не пишет', "код $st");
    [$st] = bearer('PATCH', SSO . "/api/v1/users/$gPub", $all, ['login' => 'Не логин!']);
    ok($st === 422, 'недопустимый логин при правке отбит', "код $st");

    echo "\n── Портал: чужие события и адрес ──\n";
    $b = $client('Граница Б ' . $stamp);
    $ev = DB::insert('client_events', ['client_id' => $b, 'event' => 'test', 'payload' => '{}',
        'status' => 'failed', 'attempts' => 9, 'created_at' => now()]);
    [, $req] = http('GET', SSO . "/admin/clients/$a/req");
    [$st] = http('POST', SSO . "/admin/clients/$a/action", ['_csrf' => csrfOf($req), 'action' => 'retry_event', 'event_id' => $ev]);
    ok($st === 404 && (int)DB::val('SELECT attempts FROM {client_events} WHERE id = ?', [$ev]) === 9,
        'событие чужого портала из карточки этого не перезапускается', "код $st");
    $was = (string)DB::val('SELECT base_url FROM {clients} WHERE id = ?', [$a]);
    http('POST', SSO . "/admin/clients/$a/update", ['_csrf' => csrfOf($req), 'name' => 'Граница А ' . $stamp,
        'base_url' => 'javascript:alert(1)', 'backchannel_uri' => 'gopher://127.0.0.1:6379/_x', 'is_active' => 1]);
    $row = DB::row('SELECT base_url, backchannel_uri FROM {clients} WHERE id = ?', [$a]);
    ok($row['base_url'] === $was, 'адрес javascript: не сохраняется', (string)$row['base_url']);
    ok($row['backchannel_uri'] === null, 'приёмник gopher:// — тоже', (string)$row['backchannel_uri']);
} finally {
    foreach ($tokens as $t) DB::run('DELETE FROM {api_tokens} WHERE id = ?', [$t]);
    foreach ($orgs as $o) if (\App\Models\Org::find($o)) \App\Models\Org::purge($o);
    foreach ($clients as $cid) Client::purge($cid);
    foreach ($people as $uid) User::purge($uid);
    @unlink($JAR);
    @unlink($ADMIN_JAR);
}

echo "\n══ Итог ══\nПройдено: $passed, провалено: $failed\n";
exit($failed > 0 ? 1 : 0);
