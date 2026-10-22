<?php
/**
 * Систематизация выдачи ролей: что предлагать в этой области, а что нет.
 *
 * Список выдачи собирался запросом «свои роли плюс системные» и на живом
 * портале выглядел так: `moderator, admin, user, superadmin, auditor,
 * org_owner, org_admin, admin, client_admin, org_auditor, support, user` —
 * двенадцать пунктов, из них `admin` и `user` по два раза, а семь штук из
 * чужих областей, где выдать их всё равно нельзя.
 *
 * Проверка повторяемая: заводит своё и за собой убирает. Это не придирка —
 * харнесс мутаций гоняет проверку дважды, с дефектом и без, и одноразовая
 * падала бы на втором заходе с кодом 255 вместо честного «красная».
 *
 * Запуск: php tests/role_offer.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\RoleOffer;
use App\Core\Scope;
use App\Models\Client;
use App\Models\Role;

$passed = 0;
$failed = 0;

/** Слаги предложения — в том виде, в котором их увидит человек. */
function slugs(Scope $scope): array
{
    return array_map(static fn(array $r): string => (string)$r['slug'], RoleOffer::inScope($scope));
}

echo "═══ Что предлагать к выдаче ═══\n";

/* Подопытный портал: свой, чтобы не зависеть от того, что осталось от других
   проверок, и чтобы было что убрать за собой. */
$slug = 'roleoffer-probe';
$existing = DB::row('SELECT * FROM {clients} WHERE slug = ?', [$slug]);
if ($existing) {
    Client::purge((int)$existing['id']);
}
[$cid] = Client::create([
    'name' => 'Проба выдачи', 'slug' => $slug,
    'base_url' => 'https://roleoffer.example',
    'redirect_uris' => ['https://roleoffer.example/cb'],
    'post_logout_uris' => ['https://roleoffer.example/'],
    'backchannel_uri' => null,
]);
$cid = (int)$cid;
$scope = Scope::client($cid);

try {
    echo "\n── Чужие области ──\n";
    $foreign = array_values(array_intersect(slugs($scope),
        ['superadmin', 'auditor', 'admin', 'support', 'org_owner', 'org_admin', 'org_auditor']));
    /* `admin` системный — тоже чужой: справочник разрешает ему только систему.
       Если у сервиса появится СВОЙ admin, он встанет на его место (ниже). */
    ok($foreign === [], 'роли системы и организации в область сервиса не попадают',
       implode(', ', $foreign));
    ok(in_array('client_admin', slugs($scope), true),
       'client_admin предлагается всегда: она значит что-то в самом SSO');

    echo "\n── Тождественность ──\n";
    $own = Role::create($cid, 'admin', 'Админ сервиса', '');
    Role::setLevel($own, 70);
    $s = slugs($scope);
    ok(count(array_filter($s, static fn(string $x): bool => $x === 'admin')) === 1,
       'свой admin и системный admin — один пункт, а не два', implode(', ', $s));
    $ids = array_map(static fn(array $r): int => (int)$r['id'], RoleOffer::inScope($scope));
    ok(in_array($own, $ids, true), 'и остаётся именно СВОЯ роль сервиса');
    ok(count($s) === count(array_unique($s)), 'дублей по слагу нет вовсе', implode(', ', $s));

    echo "\n── Чего сервис не знает ──\n";
    ok(!in_array('moderator', slugs($scope), true),
       'модератора, о котором сервис не говорил, не предлагаем');
    if (\App\Core\PortalManifest::ready()) {
        DB::run('INSERT INTO {client_roles_seen} (client_id, slug, title, seen_at) VALUES (?,?,?,?)',
            [$cid, 'moderator', 'Модератор', now()]);
        $mod = Role::findBySlug('moderator');
        if ($mod) {
            ok(in_array('moderator', slugs($scope), true),
               'а после того как портал о ней рассказал — предлагаем');
        } else {
            /* Роли нет в системе вовсе — выдать нечего, и об этом надо сказать. */
            ok(in_array('moderator', RoleOffer::declaredButMissing($cid), true),
               'названная порталом, но не заведённая роль названа отдельно');
        }
    }

    echo "\n── Серверная проверка ──\n";
    $sys = Role::findBySlug('superadmin');
    if ($sys) {
        ok(!RoleOffer::allows($scope, (int)$sys['id']),
           'подменой поля роль чужой области не выдать');
    }
    ok(RoleOffer::allows($scope, $own), 'а свою — можно');

    echo "\n── Другие области ──\n";
    $sysOffer = slugs(Scope::system());
    ok(!in_array('client_admin', $sysOffer, true) && !in_array('org_owner', $sysOffer, true),
       'на всю систему не предлагаются роли сервиса и организации', implode(', ', $sysOffer));
    $orgOffer = slugs(Scope::org(1));
    ok(!in_array('superadmin', $orgOffer, true) && !in_array('client_admin', $orgOffer, true),
       'на организацию — ни системные вершины, ни роли сервиса', implode(', ', $orgOffer));

    echo "\n── Уровень роли портала ──\n";
    /* Живой случай 11.09: Архив сообщил роль «admin», SSO завёл её с
       уровнем 20 — «Пользователь», и выданная человеку, она приезжала к
       порталу обычным пользователем. Двадцатка берегла общий уровень человека
       от чужой метки; теперь его бережёт сам расчёт — это и проверяем. */
    $in = static fn(string $s): int => \App\Core\RoleCatalog::levelForIncoming($s);
    ok($in('admin') === 70, '«admin» портала — администратор сервиса, 70', (string)$in('admin'));
    ok($in('cv-admin') === 70, 'и с приставкой сервиса', (string)$in('cv-admin'));
    ok($in('wtf_role') === 20, 'незнакомое имя — по-прежнему 20', (string)$in('wtf_role'));

    $stale = DB::row('SELECT id FROM {clients} WHERE slug = ?', ['roleoffer-probe-2']);
    if ($stale) Client::purge((int)$stale['id']);
    [$cid2] = Client::create([
        'name' => 'Проба выдачи, сосед', 'slug' => 'roleoffer-probe-2',
        'base_url' => 'https://roleoffer2.example',
        'redirect_uris' => ['https://roleoffer2.example/cb'],
        'post_logout_uris' => ['https://roleoffer2.example/'],
        'backchannel_uri' => null,
    ]);
    $cid2 = (int)$cid2;
    $levelUid = \App\Models\User::create(['email' => 'roleoffer-level-' . bin2hex(random_bytes(4)) . '@test.local',
        'first_name' => 'Проба', 'status' => 'active', 'email_verified_at' => now()]);
    $base = Role::baseLevel();

    Role::assignScoped($levelUid, $own, $scope, null);   // свой «admin», уровень 70
    \App\Core\AccessResolver::forget();
    $lu = \App\Models\User::find($levelUid);
    ok(\App\Core\AccessLevel::forClient($lu, $cid) === 70, 'в своём портале роль даёт 70',
       (string)\App\Core\AccessLevel::forClient($lu, $cid));

    /* Выдача в обход RoleOffer — на всю систему. Интерфейс и API так не дадут;
       проверяем, что уровень держит сам расчёт, а не каждый путь выдачи. */
    Role::assignScoped($levelUid, $own, Scope::system(), null);
    \App\Core\AccessResolver::forget();
    ok(\App\Core\AccessLevel::forClient($lu, $cid2) === $base,
       'в соседнем портале её нет, даже выданной на всю систему',
       (string)\App\Core\AccessLevel::forClient($lu, $cid2));
    ok(\App\Core\AccessResolver::levelFor($levelUid, $cid2) < 70, 'и резолвер областей считает так же',
       (string)\App\Core\AccessResolver::levelFor($levelUid, $cid2));
    ok(\App\Core\AccessLevel::of($lu) === $base,
       'в общем уровне человека её нет: старшинства в SSO чужая метка не даёт',
       (string)\App\Core\AccessLevel::of($lu));
} finally {
    /* За собой убираем всегда: следующая проверка не должна разбираться,
       что осталось от предыдущей. */
    if (isset($levelUid)) \App\Models\User::purge((int)$levelUid);
    if (isset($cid2)) Client::purge((int)$cid2);
    Client::purge($cid);
}

echo "\n══ Итог ══\n";
printf("Пройдено: %d, провалено: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
