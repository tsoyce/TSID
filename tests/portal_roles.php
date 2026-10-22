<?php
/**
 * Роли портала при переносе людей (v4.66).
 *
 * Живой случай 12.09: людей Портфолио перенесли кнопкой «Забрать людей», а в «Людях»
 * портала у них не оказалось ни одной роли. Перенос ролей не читал вовсе, а
 * выгрузка Портфолио их и не отдавала — в контракте §11 поля не было.
 *
 * Проверяем, что:
 *   · роль портала выдаётся по одноимённой роли, заведённой за порталом, и по
 *     карте ролей — и всегда в области ЭТОГО портала;
 *   · общая роль SSO не выдаётся никогда, даже сопоставленная в карте, — кроме
 *     «Админа портала» в его же области;
 *   · слаги без пары и отвергнутые называются в итоге переноса;
 *   · повторный перенос роли только добавляет и не снимает выданное руками;
 *   · мусор в поле ролей отбрасывается.
 *
 * Выгрузку отдаёт заглушка на тестовом RP; подпись она не проверяет — её
 * проверку держит e2e.php на настоящем файле из набора.
 *
 * Запуск: php tests/portal_roles.php  (нужны dev-серверы на :8710 и :8711)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\PortalUsers;
use App\Core\Scope;
use App\Models\Client;
use App\Models\ClientRoleMap;
use App\Models\Role;
use App\Models\User;

const RP = 'http://localhost:8711';
$passed = 0;
$failed = 0;
@mkdir(__DIR__ . '/rp', 0777, true);
$stub = __DIR__ . '/rp/roles-export.php';
$rowsFile = __DIR__ . '/rp/roles-export.json';
file_put_contents($stub, '<?php header("Content-Type: application/json");'
    . ' $u = json_decode((string)file_get_contents(__DIR__ . "/roles-export.json"), true);'
    . ' echo json_encode(["users" => $u, "total" => count($u)]);');

$slug = 'roles-probe';
$old = DB::row('SELECT id FROM {clients} WHERE slug = ?', [$slug]);
if ($old) Client::purge((int)$old['id']);
[$cid] = Client::create([
    'name' => 'Проба ролей', 'slug' => $slug, 'base_url' => 'https://roles-probe.example',
    'redirect_uris' => ['https://roles-probe.example/cb'], 'post_logout_uris' => ['https://roles-probe.example/'],
    'backchannel_uri' => null, 'is_trusted' => 1, 'access_policy' => 'open',
]);
$cid = (int)$cid;
DB::update('clients', ['base_url' => RP, 'users_url' => RP . '/roles-export.php',
    'users_secret' => 'pusec_' . str_repeat('r', 32)], 'id = ?', [$cid]);

$stamp = bin2hex(random_bytes(3));
$ids = [];

try {
    echo "── Подготовка: роли портала и карта ──\n";
    $redactor = Role::create($cid, 'redactor', 'Редактор');
    $reader = Role::create($cid, 'reader', 'Читатель');
    $clientAdmin = Role::clientAdmin();
    $map = ClientRoleMap::replace($cid, ['client_admin' => 'editor', 'superadmin' => 'boss']);
    ok($map['saved'] === 2, 'карта: «Админ портала» → editor, суперадмин → boss', json_encode($map));

    file_put_contents($rowsFile, json_encode([
        ['id' => 'r1', 'email' => "roles-1-$stamp@test.local", 'roles' => ['redactor']],
        ['id' => 'r2', 'login' => "roles2$stamp", 'roles' => ['editor', 'reader']],
        ['id' => 'r3', 'email' => "roles-3-$stamp@test.local", 'roles' => ['boss', 'ghost']],
        ['id' => 'r4', 'email' => "roles-4-$stamp@test.local"],
        ['id' => 'r5', 'email' => "roles-5-$stamp@test.local", 'role' => 'reader'],
    ], JSON_UNESCAPED_UNICODE));

    echo "\n── Перенос с ролями ──\n";
    $r = PortalUsers::pull(Client::find($cid));
    ok($r['ok'] && $r['created'] === 5, 'перенос завёл всех пятерых', json_encode($r, JSON_UNESCAPED_UNICODE));
    foreach (['r1', 'r2', 'r3', 'r4', 'r5'] as $ext) {
        $ids[$ext] = (int)DB::val('SELECT user_id FROM {client_user_links} WHERE client_id = ? AND external_id = ?', [$cid, $ext]);
    }
    $scope = Scope::client($cid);
    $grants = static function (int $uid): array {
        return array_map(static fn($g) => $g['slug'] . '@' . $g['scope_type'] . ':' . (int)$g['scope_id'],
            DB::all('SELECT r.slug, ur.scope_type, ur.scope_id FROM {user_roles} ur
                       JOIN {roles} r ON r.id = ur.role_id WHERE ur.user_id = ?', [$uid]));
    };
    $here = static fn(string $role): string => $role . '@' . $scope->type . ':' . $cid;

    ok(in_array($here('redactor'), $grants($ids['r1']), true),
        'роль портала выдана по одноимённой роли, заведённой за порталом', json_encode($grants($ids['r1'])));
    ok(in_array($here('client_admin'), $grants($ids['r2']), true)
        && !in_array('client_admin@' . Scope::system()->type . ':0', $grants($ids['r2']), true),
        '«Админ портала» по карте — в области этого портала, а не на всю систему', json_encode($grants($ids['r2'])));
    ok(in_array($here('reader'), $grants($ids['r2']), true), 'и вторая роль того же человека');
    ok(in_array($here('reader'), $grants($ids['r5']), true), 'роль одной строкой в поле role тоже понята');
    ok(array_filter($grants($ids['r3']), static fn($g) => str_starts_with($g, 'superadmin@')) === [],
        'общая роль SSO не выдана, хоть и сопоставлена в карте: портал не раздаёт права на весь SSO',
        json_encode($grants($ids['r3'])));
    ok(isset($r['roles_refused']['boss']), 'отвергнутая роль названа в итоге', json_encode($r['roles_refused']));
    ok(isset($r['roles_unmapped']['ghost']), 'роль без пары названа в итоге', json_encode($r['roles_unmapped']));
    ok(array_filter($grants($ids['r4']), static fn($g) => str_contains($g, '@' . $scope->type . ':' . $cid)) === [],
        'человек без поля ролей ролей портала не получил');
    ok($r['roles'] === 4, 'в итоге посчитаны именно выданные роли: 4', (string)$r['roles']);
    $inScope = array_column(Role::grantsInScope($scope), 'slug', 'user_id');
    ok(($inScope[$ids['r1']] ?? '') === 'redactor', 'выдача видна в области портала — там, откуда её показывают «Люди»');

    echo "\n── Повторный перенос ──\n";
    Role::assignScoped($ids['r1'], (int)$reader, $scope);
    $r2 = PortalUsers::pull(Client::find($cid));
    ok($r2['ok'] && $r2['created'] === 0 && $r2['roles'] === 0, 'повтор ничего не задвоил и лишнего не выдал',
        json_encode($r2, JSON_UNESCAPED_UNICODE));
    ok(in_array($here('reader'), $grants($ids['r1']), true) && in_array($here('redactor'), $grants($ids['r1']), true),
        'роль, выданная руками, после повтора на месте: перенос роли только добавляет');

    echo "\n── Мусор в поле ролей ──\n";
    $n = PortalUsers::normalize(['id' => 'x', 'email' => 'g@test.local',
        'roles' => ['ok', '<script>', ['x'], 123, str_repeat('a', 101), 'ok', 'a b']]);
    ok($n !== null && $n['roles'] === ['ok'], 'из мусора осталась одна годная роль', json_encode($n['roles'] ?? null));
    ok(PortalUsers::normalize(['id' => 'y', 'email' => 'h@test.local'])['roles'] === [], 'нет поля — нет ролей');
} finally {
    Client::purge($cid);
    foreach ($ids as $uid) if ($uid > 0) User::purge($uid);
    @unlink($stub);
    @unlink($rowsFile);
}

echo "\n══ Итог ══\nПройдено: $passed, провалено: $failed\n";
exit($failed > 0 ? 1 : 0);
