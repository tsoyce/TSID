<?php
/**
 * Удаление и отзыв доступа доходят до порталов — и портал знает, что именно
 * случилось (v4.62).
 *
 * Живой случай 11.09: человека «убрали» из Архива кнопкой в карточке
 * портала, а Архив не узнал ничего — в его очереди событий за всё время
 * лежали одни logout. Удаление же уходило одним и тем же user.deleted на
 * четыре разных случая, и портал не мог отличить «человек может вернуться»
 * от «стереть всё». Отмена удаления и окончательное обезличивание не уходили
 * вовсе.
 *
 * Смотрим не на доставку — адрес приёмника здесь заведомо мёртвый, — а на то,
 * ЧТО и КОМУ поставлено в очередь. Доставку проверяет e2e.php.
 *
 * Проверка повторяемая: заводит своих людей и порталы и убирает за собой —
 * харнесс мутаций гоняет её с дефектом и без.
 *
 * Запуск: php tests/deletion_events.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Models\Access;
use App\Models\Client;
use App\Models\User;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$JAR = sys_get_temp_dir() . '/deletion-events-admin.txt';
$SELF = sys_get_temp_dir() . '/deletion-events-self.txt';
@unlink($JAR);
@unlink($SELF);
$stamp = time() . bin2hex(random_bytes(2));
$people = [];
$portals = [];

/** Портал с приёмником: без backchannel_uri событие не ставится в очередь вовсе. */
function probePortal(string $slug): int
{
    $old = DB::row('SELECT id FROM {clients} WHERE slug = ?', [$slug]);
    if ($old) Client::purge((int)$old['id']);
    [$id] = Client::create([
        'name' => 'Проба удаления', 'slug' => $slug, 'base_url' => 'https://' . $slug . '.example',
        'redirect_uris' => ['https://' . $slug . '.example/cb'],
        'post_logout_uris' => ['https://' . $slug . '.example/'],
        /* Мёртвый адрес: попытка доставки падает сразу, строка в очереди остаётся. */
        'backchannel_uri' => 'http://127.0.0.1:9/backchannel',
        'is_trusted' => 1, 'access_policy' => 'open',
    ]);
    return (int)$id;
}

function person(string $tag, array $clientIds): int
{
    global $stamp, $people;
    $uid = User::create(['email' => "del-$tag-$stamp@test.local", 'first_name' => 'Проба',
        'password_hash' => password_hash('correct-horse-battery', PASSWORD_ARGON2ID),
        'status' => 'active', 'email_verified_at' => now()]);
    foreach ($clientIds as $cid) Access::grant($uid, $cid, null, 'active');
    $people[] = $uid;
    return $uid;
}

function mark(): int
{
    return (int)DB::val('SELECT COALESCE(MAX(id), 0) FROM {client_events}');
}

/** @return list<array{0: string, 1: array}> события портала после отметки */
function eventsOf(int $clientId, int $after): array
{
    $out = [];
    foreach (DB::all('SELECT event, payload FROM {client_events} WHERE client_id = ? AND id > ? ORDER BY id',
                     [$clientId, $after]) as $r) {
        $out[] = [(string)$r['event'], json_decode((string)$r['payload'], true) ?: []];
    }
    return $out;
}

/** Первое событие такого вида — или null. */
function first(array $events, string $name): ?array
{
    foreach ($events as [$e, $p]) if ($e === $name) return $p;
    return null;
}

function count_of(array $events, string $name): int
{
    return count(array_filter($events, static fn(array $x): bool => $x[0] === $name));
}

DB::run('UPDATE {users} SET totp_secret = NULL, totp_enabled_at = NULL WHERE email = ?', ['admin@test.local']);
DB::run('DELETE FROM {rate_limits}');
[, $lp] = http('GET', SSO . '/login', [], [], $JAR);
http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => 'admin@test.local',
    'password' => 'correct-horse-battery'], [], $JAR);
/* Токен — со страницы, где есть форма: у списка людей её нет, и пустой
   токен молча ронял каждое действие администратора. */
[, $adminPage] = http('GET', SSO . '/admin/clients', [], [], $JAR);
$csrf = csrfOf($adminPage);

echo "═══ Удаление доходит до порталов ═══\n";

try {
    $a = probePortal('del-a-' . $stamp);
    $b = probePortal('del-b-' . $stamp);
    $portals = [$a, $b];

    echo "\n── Удаление администратором: «помечен, можно вернуть» ──\n";
    $u1 = person('sched', [$a]);
    $m = mark();
    http('POST', SSO . "/admin/users/$u1/action", ['_csrf' => $csrf, 'action' => 'delete'], [], $JAR);
    $ev = eventsOf($a, $m);
    $p = first($ev, 'user.deleted');
    ok(($p['mode'] ?? null) === 'scheduled', 'портал получил user.deleted с mode = scheduled',
       json_encode($ev, JSON_UNESCAPED_UNICODE));
    $until = strtotime((string)($p['restore_until'] ?? '')) ?: 0;
    ok(abs($until - (time() + 14 * 86400)) < 3600, 'и restore_until — через две недели, сколько длится отмена',
       (string)($p['restore_until'] ?? '—'));

    echo "\n── Отмена удаления из письма ──\n";
    $token = \App\Models\OneTimeToken::issue($u1, 'account_delete');
    $m = mark();
    /* v4.98: по ссылке — только кнопка. Почтовый сканер, открывший письмо,
       удаление больше не отменяет; отменяет нажатие. */
    [, $cancelPage] = http('GET', SSO . '/delete/cancel/' . $token);
    ok((string)(User::find($u1)['status'] ?? '') === 'deleted', 'открытая ссылка сама удаление не отменяет');
    http('POST', SSO . '/delete/cancel/' . $token, ['_csrf' => csrfOf($cancelPage)]);
    ok((string)(User::find($u1)['status'] ?? '') === 'active', 'аккаунт снова активен');
    ok(first(eventsOf($a, $m), 'user.restored') !== null,
       'портал получил user.restored — иначе держал бы вернувшегося отключённым');

    echo "\n── Срок вышел: обезличивание ──\n";
    DB::update('users', ['status' => 'deleted', 'deleted_at' => now_plus(-15 * 86400)], 'id = ?', [$u1]);
    $m = mark();
    \App\Core\CronTasks::anonymizeDeleted();
    ok((($p = first(eventsOf($a, $m), 'user.deleted'))['mode'] ?? null) === 'final',
       'после обезличивания портал получил user.deleted с mode = final',
       json_encode(eventsOf($a, $m), JSON_UNESCAPED_UNICODE));

    echo "\n── Администратор стирает насовсем ──\n";
    $u2 = person('purge', [$a]);
    $email2 = (string)User::find($u2)['email'];
    http('POST', SSO . "/admin/users/$u2/danger", ['_csrf' => $csrf, 'password' => 'correct-horse-battery'], [], $JAR);
    $m = mark();
    http('POST', SSO . "/admin/users/$u2/action",
        ['_csrf' => $csrf, 'action' => 'purge', 'confirm' => $email2], [], $JAR);
    ok(User::find($u2) === null, 'аккаунт стёрт');
    ok((first(eventsOf($a, $m), 'user.deleted')['mode'] ?? null) === 'purged',
       'портал получил user.deleted с mode = purged', json_encode(eventsOf($a, $m), JSON_UNESCAPED_UNICODE));

    echo "\n── Человек удаляет себя сам ──\n";
    $u3 = person('self', [$a]);
    $email3 = (string)User::find($u3)['email'];
    DB::run('DELETE FROM {rate_limits}');
    [, $slp] = http('GET', SSO . '/login', [], [], $SELF);
    http('POST', SSO . '/login', ['_csrf' => csrfOf($slp), 'identity' => $email3,
        'password' => 'correct-horse-battery'], [], $SELF);
    [, $settings] = http('GET', SSO . '/profile/settings', [], [], $SELF);
    $m = mark();
    http('POST', SSO . '/profile/settings/delete',
        ['_csrf' => csrfOf($settings), 'password' => 'correct-horse-battery'], [], $SELF);
    ok((string)(User::find($u3)['status'] ?? '') === 'deleted', 'аккаунт помечен удалённым');
    ok((first(eventsOf($a, $m), 'user.deleted')['mode'] ?? null) === 'scheduled',
       'портал получил user.deleted с mode = scheduled — две недели на отмену',
       json_encode(eventsOf($a, $m), JSON_UNESCAPED_UNICODE));

    echo "\n── Доступ: «Закрыть» — в архив, «Убрать» — стереть ──\n";
    /* Решение владельца 12.09: две кнопки — два исхода. Живой случай: test
       «закрыли» и «убрали» из Архива, тот получил одно access.revoked без
       подробностей, закрыл сессию и оставил учётную запись живой; «Убрать» после
       «Закрыть» не отправило ничего. */
    $u4 = person('access', [$a, $b]);
    $m = mark();
    http('POST', SSO . "/admin/users/$u4/access",
        ['_csrf' => $csrf, 'client_id' => $a, 'access' => 'revoke'], [], $JAR);
    $ev = eventsOf($a, $m);
    ok(count_of($ev, 'access.revoked') === 1 && (first($ev, 'access.revoked')['mode'] ?? null) === 'closed',
       '«Закрыть» — одно access.revoked с mode = closed: сервис уводит в архив', json_encode($ev, JSON_UNESCAPED_UNICODE));
    ok(eventsOf($b, $m) === [], 'а соседний портал того же человека — ничего');

    $m = mark();
    http('POST', SSO . "/admin/users/$u4/access",
        ['_csrf' => $csrf, 'client_id' => $a, 'access' => 'grant'], [], $JAR);
    ok(count_of(eventsOf($a, $m), 'access.granted') === 1,
       '«Открыть» снова — access.granted: сервис возвращает из архива', json_encode(eventsOf($a, $m), JSON_UNESCAPED_UNICODE));

    http('POST', SSO . "/admin/users/$u4/access",
        ['_csrf' => $csrf, 'client_id' => $a, 'access' => 'revoke'], [], $JAR);
    $m = mark();
    http('POST', SSO . "/admin/users/$u4/access",
        ['_csrf' => $csrf, 'client_id' => $a, 'access' => 'deny'], [], $JAR);
    $ev = eventsOf($a, $m);
    ok(count_of($ev, 'access.revoked') === 1 && (first($ev, 'access.revoked')['mode'] ?? null) === 'removed',
       '«Убрать» закрытый доступ — access.revoked с mode = removed: сервис стирает (12.09 не ушло ничего)',
       json_encode($ev, JSON_UNESCAPED_UNICODE));

    $m = mark();
    http('POST', SSO . "/admin/users/$u4/access",
        ['_csrf' => $csrf, 'client_id' => $b, 'access' => 'deny'], [], $JAR);
    ok((first(eventsOf($b, $m), 'access.revoked')['mode'] ?? null) === 'removed',
       '«Убрать» открытый доступ — тоже removed', json_encode(eventsOf($b, $m), JSON_UNESCAPED_UNICODE));

    Access::grant($u4, $b, null, 'pending');
    $m = mark();
    http('POST', SSO . "/admin/users/$u4/access",
        ['_csrf' => $csrf, 'client_id' => $b, 'access' => 'deny'], [], $JAR);
    ok(count_of(eventsOf($b, $m), 'access.revoked') === 0,
       'отклонённая заявка — не отзыв: доступа не было, портал о нём не слышал');

    /* Отказ от сервиса в профиле — «закрыть», а не «стереть»: терять данные из-за
       одного клика нельзя. Отказываться дают только от недоверенного портала. */
    $c = probePortal('del-c-' . $stamp);
    $portals[] = $c;
    Client::update($c, ['is_trusted' => 0]);
    $u5 = person('profile', [$c]);
    $email5 = (string)User::find($u5)['email'];
    DB::run('DELETE FROM {rate_limits}');
    @unlink($SELF);
    [, $slp] = http('GET', SSO . '/login', [], [], $SELF);
    http('POST', SSO . '/login', ['_csrf' => csrfOf($slp), 'identity' => $email5,
        'password' => 'correct-horse-battery'], [], $SELF);
    [, $services] = http('GET', SSO . '/profile/services', [], [], $SELF);
    $m = mark();
    http('POST', SSO . '/profile/services/revoke', ['_csrf' => csrfOf($services), 'client_id' => $c], [], $SELF);
    ok((first(eventsOf($c, $m), 'access.revoked')['mode'] ?? null) === 'closed',
       'человек сам отказался от сервиса — closed: сервис уводит в архив, а не стирает',
       json_encode(eventsOf($c, $m), JSON_UNESCAPED_UNICODE));

    echo "\n── Что видит администратор (прогон 12.09, v4.67) ──\n";
    /* Сообщения копятся в сессии, пока их не покажет страница: сначала сливаем
       накопленное прошлыми действиями, иначе проверка увидит чужое. */
    $drain = static fn() => http('GET', SSO . '/admin/clients', [], [], $JAR);
    $peoplePage = static fn(int $cid): string => http('GET', SSO . "/admin/clients/$cid/people", [], [], $JAR)[1];
    $u6 = person('flash', [$a]);

    $drain();
    http('POST', SSO . "/admin/users/$u6/access", ['_csrf' => $csrf, 'client_id' => $a, 'access' => 'revoke',
        'back' => "/admin/clients/$a/people"], [], $JAR);
    ok(str_contains($peoplePage($a), 'ушла в архив'), '«Закрыть» открытого доступа говорит, что будет в сервисе');

    DB::update('user_client_access', ['status' => 'pending'], 'user_id = ? AND client_id = ?', [$u6, $a]);
    $drain();
    http('POST', SSO . "/admin/users/$u6/access", ['_csrf' => $csrf, 'client_id' => $a, 'access' => 'revoke',
        'back' => "/admin/clients/$a/people"], [], $JAR);
    $pp = $peoplePage($a);
    ok(str_contains($pp, 'Заявка отклонена') && !str_contains($pp, 'ушла в архив'),
       'отклонённая заявка — «заявка отклонена», а не «учётная запись ушла в архив»: доступа не было');

    $adminId = (int)User::findByEmail('admin@test.local')['id'];
    Access::grant($adminId, $a, null, 'active');
    $drain();
    http('POST', SSO . "/admin/users/$adminId/access", ['_csrf' => $csrf, 'client_id' => $a, 'access' => 'revoke',
        'back' => "/admin/clients/$a/people"], [], $JAR);
    ok((Access::get($adminId, $a)['status'] ?? '') === 'active',
       'себе доступ из админки не закрыть — запись осталась открытой');
    $pp = $peoplePage($a);
    ok(str_contains($pp, 'это вы') && str_contains($pp, 'Себе доступ здесь не закрывают'),
       'в своей строке — «это вы» вместо кнопок, а сервер объяснил отказ');

    Access::grant($u6, $a, null, 'active');
    $rid = \App\Models\Role::create($a, 'probe_reader', 'Проба: читатель');
    \App\Models\Role::assignScoped($u6, $rid, \App\Core\Scope::client($a));
    ok(str_contains($peoplePage($a), 'title="probe_reader">Проба: читатель'),
       'плашка роли — названием, слаг — в подсказке');

    /* Число у «Доставки» — только недоставленное: ноль не рисуется. */
    $undelivered = (int)DB::val("SELECT COUNT(*) FROM {client_events} WHERE client_id = ? AND status <> 'sent'", [$a]);
    preg_match('~Доставка</span>\s*<span class="set-rail-count">(\d+)</span>~u', $peoplePage($a), $mm);
    ok($undelivered > 0 && (int)($mm[1] ?? 0) === $undelivered,
       'у «Доставки» — число недоставленных событий', "ждали $undelivered, показано " . ($mm[1] ?? 'ничего'));
    DB::run("UPDATE {client_events} SET status = 'sent' WHERE client_id = ?", [$a]);
    ok(!preg_match('~Доставка</span>\s*<span class="set-rail-count">~u', $peoplePage($a)),
       'всё доставлено — число у «Доставки» не висит');

    echo "\n── Разделы карточки портала: одно место для одного дела (v4.68) ──\n";
    /* Замечание владельца 12.09: «Роли» повторяли выдачу из «Пользователей»
       второй таблицей тех же людей и были самым тяжёлым разделом карточки. */
    $rolesPg = http('GET', SSO . "/admin/clients/$a/roles", [], [], $JAR)[1];
    ok(str_contains($rolesPg, 'Роли этого сервиса') && !str_contains($rolesPg, 'value="role_grant"'),
       '«Роли» — только роли: выдачи людям там нет');
    $pplPg = $peoplePage($a);
    ok(str_contains($pplPg, 'value="role_grant"') && str_contains($pplPg, 'Забрать людей'),
       '«Пользователи» — люди, их роли и перенос людей с портала');
    /* Именно кнопка, а не слова: они есть ещё и в подсказке про неудачную
       попытку, и проверка по словам осталась зелёной без кнопки (мутация 60). */
    ok(str_contains(http('GET', SSO . "/admin/clients/$a/artifacts", [], [], $JAR)[1], 'Спросить у портала</button>'),
       'связь с порталом — в «Подключении»: кнопка на месте');
} finally {
    foreach ($people as $uid) {
        if (User::find($uid)) User::purge($uid);
    }
    foreach ($portals as $cid) Client::purge($cid);
    DB::run('DELETE FROM {rate_limits}');
    @unlink($JAR);
    @unlink($SELF);
}

echo "\n══ Итог ══\n";
printf("Пройдено: %d, провалено: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
