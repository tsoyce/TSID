<?php
/**
 * Admin API уровня суперадмина: может ли программа делать то же, что человек.
 *
 * До v4.51 у токена было пять прав — люди, порталы и журнал. Ни ролей, ни
 * организаций, ни доступов: внешний агент мог завести человека и портал, но не
 * мог соединить их между собой. То есть автоматизировать можно было половину
 * работы, а вторую половину всё равно доделывали руками.
 *
 * Проверка проходит сценарий целиком — от «нет ничего» до «человек в портале с
 * ролью» и обратно, — и заодно смотрит на границы: право `*` покрывает всё,
 * узкий токен упирается в 403, а роль чужой области не выдаётся даже
 * суперадминским токеном (систематизация обязана действовать и для машины).
 *
 * Повторяема: заводит своё с уникальными именами и за собой убирает.
 *
 * Запуск (сервер поднят): php tests/api_superadmin.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\Setting;
use App\Core\SettingsTransfer;
use App\Models\ApiToken;

$passed = 0;
$failed = 0;
$touchedSettings = false;

const API = 'http://localhost:8710/api/v1';

/** Запрос к API с токеном. Возвращает [код, разобранный JSON]. */
function api(string $method, string $path, array $body = [], string $token = ''): array
{
    $ch = curl_init(API . $path);
    $headers = ['Accept: application/json'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    if ($body !== []) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== []) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true) ?: []];
}

$stamp = (string)time();
[$idAll, $tokenAll] = ApiToken::create('проба суперадмина ' . $stamp, [ApiToken::SCOPE_ALL], null, 1);
[$idThin, $tokenThin] = ApiToken::create('проба узкая ' . $stamp, ['users:read'], null, 1);

$made = ['client' => null, 'user' => null, 'org' => null, 'role' => null];

try {
    echo "═══ Права токена ═══\n";
    [$c] = api('GET', '/users', [], $tokenThin);
    ok($c === 200, 'узкий токен читает людей — на это право есть', "код $c");
    [$c, $b] = api('GET', '/roles', [], $tokenThin);
    ok($c === 403 && ($b['error'] ?? '') === 'insufficient_scope',
       'и упирается в 403 там, где права нет', "код $c");
    [$c] = api('GET', '/roles', [], $tokenAll);
    ok($c === 200, 'право «всё» открывает то же самое', "код $c");
    [$c] = api('GET', '/users', []);
    ok($c === 401, 'без токена не пускают вовсе', "код $c");

    echo "\n═══ Сценарий целиком ═══\n";
    /* 1. Портал. */
    [$c, $portal] = api('POST', '/clients', [
        'name' => 'API-проба ' . $stamp,
        'base_url' => 'https://api-probe-' . $stamp . '.example',
    ], $tokenAll);
    ok($c === 201 && !empty($portal['client_id']), 'портал заведён', "код $c");
    ok(!empty($portal['client_secret']), 'секрет отдан один раз при создании');
    $cid = (string)($portal['client_id'] ?? '');
    $made['client'] = $cid;

    /* 2. Своя роль портала. */
    [$c, $role] = api('POST', '/roles', [
        'slug' => 'api_probe_editor', 'name' => 'Редактор пробы', 'client_id' => $cid,
    ], $tokenAll);
    ok($c === 201 && !empty($role['id']), 'роль сервиса заведена', "код $c");
    $made['role'] = (int)($role['id'] ?? 0);
    ok((int)($role['access_level'] ?? 0) === 60,
       'уровень взят из справочника по имени: «editor» — это 60',
       'уровень ' . ($role['access_level'] ?? '—'));

    /* 3. Человек. */
    [$c, $user] = api('POST', '/users', [
        'email' => 'api-probe-' . $stamp . '@test.local', 'first_name' => 'Проба',
    ], $tokenAll);
    ok($c === 201 && !empty($user['sub']), 'человек заведён', "код $c");
    $sub = (string)($user['sub'] ?? '');
    $made['user'] = $sub;

    /* 4. Доступ к порталу. */
    [$c] = api('POST', '/users/' . $sub . '/access', ['client_id' => $cid], $tokenAll);
    ok($c === 201, 'доступ к порталу открыт', "код $c");

    /* 5. Выдача роли в области портала. */
    [$c, $g] = api('POST', '/users/' . $sub . '/roles', [
        'role' => 'api_probe_editor', 'scope' => 'client', 'client_id' => $cid,
    ], $tokenAll);
    ok($c === 201 && ($g['granted'] ?? false), 'роль выдана в области портала', "код $c");

    /* 6. Читаем обратно — то же самое, что увидит человек в интерфейсе. */
    [$c, $list] = api('GET', '/users/' . $sub . '/roles', [], $tokenAll);
    $found = array_filter($list['items'] ?? [],
        static fn(array $r): bool => ($r['slug'] ?? '') === 'api_probe_editor');
    ok($c === 200 && $found !== [], 'выдача видна в ответе API', "код $c");
    $one = reset($found) ?: [];
    ok(($one['scope']['type'] ?? '') === 'client', 'и область названа верно',
       json_encode($one['scope'] ?? null));

    echo "\n═══ Систематизация действует и для машины ═══\n";
    /* Список в области отвечает то же, что видит человек. */
    [$c, $offer] = api('GET', '/roles?scope=client&client_id=' . urlencode($cid), [], $tokenAll);
    $slugs = array_map(static fn(array $r): string => (string)$r['slug'], $offer['items'] ?? []);
    ok($c === 200 && in_array('api_probe_editor', $slugs, true),
       'своя роль портала в списке есть', implode(', ', $slugs));
    $foreign = array_values(array_intersect($slugs, ['superadmin', 'org_owner', 'org_admin', 'support']));
    ok($foreign === [], 'роли чужих областей в список не попали', implode(', ', $foreign));

    /* И выдать такую нельзя даже правом «всё»: систематизация — не про права,
       а про смысл. Суперадмин тоже не может сделать бессмысленное. */
    [$c, $err] = api('POST', '/users/' . $sub . '/roles', [
        'role' => 'superadmin', 'scope' => 'client', 'client_id' => $cid,
    ], $tokenAll);
    ok($c === 422, 'роль чужой области не выдаётся и суперадминским токеном', "код $c");

    echo "\n═══ Организации ═══\n";
    [$c, $org] = api('POST', '/orgs', ['name' => 'API-организация ' . $stamp], $tokenAll);
    ok($c === 201 && !empty($org['id']), 'организация заведена', "код $c");
    $made['org'] = (int)($org['id'] ?? 0);
    [$c] = api('POST', '/orgs/' . $made['org'] . '/members', ['sub' => $sub, 'role' => 'admin'], $tokenAll);
    ok($c === 201, 'человек добавлен в состав', "код $c");
    [$c, $mem] = api('GET', '/orgs/' . $made['org'] . '/members', [], $tokenAll);
    ok($c === 200 && count($mem['items'] ?? []) === 1, 'состав читается', "код $c");

    echo "\n═══ Портал: изменение и секрет ═══\n";
    [$c, $upd] = api('PATCH', '/clients/' . $cid, ['name' => 'API-проба переименована'], $tokenAll);
    ok($c === 200 && ($upd['name'] ?? '') === 'API-проба переименована', 'портал переименован', "код $c");
    [$c, $upd] = api('PATCH', '/clients/' . $cid, [
        'same_site_family' => true, 'require_2fa' => true, 'is_trusted' => false,
        'description' => 'Проба полей', 'show_on_home' => false,
    ], $tokenAll);
    ok($c === 200 && ($upd['same_site_family'] ?? null) === true
       && ($upd['require_2fa'] ?? null) === true && ($upd['is_trusted'] ?? null) === false,
       'тумблеры портала переключаются — включая семейство (prompt=none)',
       json_encode(array_intersect_key($upd, array_flip(['same_site_family', 'require_2fa', 'is_trusted']))));
    ok(($upd['description'] ?? '') === 'Проба полей' && isset($upd['redirect_uris']),
       'портал отдаётся целиком, а не шестью полями из пятнадцати');

    /* Занятое машинное имя — отказ, а не тихая замена: портал с чужим слагом
       начал бы получать чужие роли (они идут префиксом «слаг:роль»). */
    [$c] = api('PATCH', '/clients/' . $cid, ['slug' => 'api-probe-taken-' . $stamp], $tokenAll);
    ok($c === 200, 'машинное имя меняется', "код $c");
    [$c] = api('PATCH', '/clients/' . $cid, ['org_id' => 999999], $tokenAll);
    ok($c === 404, 'портал не уезжает в несуществующую организацию', "код $c");

    [$c, $rot] = api('POST', '/clients/' . $cid . '/secret', [], $tokenAll);
    ok($c === 200 && !empty($rot['client_secret']) && $rot['client_secret'] !== ($portal['client_secret'] ?? ''),
       'секрет перевыпущен и отличается от прежнего', "код $c");

    echo "\n═══ Карточка и API правят одни и те же поля ═══\n";
    /* Дефект, найденный прогоном по живой установке: карточка умела включать
       `same_site_family`, API — нет, и разошлись они молча. Никакой проверки
       на это не было, потому что списка полей не существовало: и там и там
       ключи перечислялись руками. Теперь список один, и эта проверка следит,
       чтобы карточка не ушла вперёд. */
    $written = [];
    foreach ((array)glob(dirname(__DIR__) . '/app/Controllers/Admin/*.php') as $file) {
        $src = (string)file_get_contents($file);
        $off = 0;
        while (($p = strpos($src, 'Client::', $off)) !== false) {
            $off = $p + 8;
            if (!preg_match('~^(create|update)\(~', substr($src, $off, 8), $m)) continue;
            $i = $off + strlen($m[1]);
            for ($j = $i, $depth = 0; $j < strlen($src); $j++) {
                if ($src[$j] === '(') $depth++;
                elseif ($src[$j] === ')' && --$depth === 0) break;
            }
            preg_match_all('~\'([a-z_]+)\'\s*=>~', substr($src, $i, $j - $i), $km);
            foreach ($km[1] as $k) $written[$k] = basename($file);
        }
    }
    ok(count($written) > 10, 'поля портала, правимые из админки, найдены: ' . count($written));
    $known = array_keys(\App\Models\Client::EDITABLE);
    $gap = array_diff(array_keys($written), $known, \App\Models\Client::NOT_A_FIELD);
    ok($gap === [], 'всё, что правит карточка, доступно и через API',
       implode(', ', array_map(static fn($k): string => $k . ' (' . $written[$k] . ')', $gap)));

    echo "\n═══ Настройки ═══\n";
    /* Что считать секретом, знает один список — SettingsTransfer::secret().
       Прежняя проверка повторяла здесь то же правило, что и код, то есть
       сверяла реализацию с её же копией и поймать не могла ничего. На живой
       установке это правило маскировало `appearance.method_password` — тумблер
       «показывать ли вход по паролю», — а проверка оставалась зелёной. */
    ok(!SettingsTransfer::secret('appearance.method_password')
       && !SettingsTransfer::secret('appearance.method_passkey')
       && !SettingsTransfer::secret('policy.password_min'),
       'тумблеры входа и политика паролей — не секреты');
    ok(SettingsTransfer::secret('social.telegram.secret')
       && SettingsTransfer::secret('mail.pass')
       && SettingsTransfer::secret('health.api_token'),
       'а secret соцвхода, пароль SMTP и токен здоровья — секреты');

    [$c] = api('PATCH', '/settings', ['appearance.method_password' => '1',
                                      'social.telegram.secret' => 'проба-секрета'], $tokenAll);
    ok($c === 200, 'настройки пишутся', "код $c");
    $touchedSettings = true;

    [$c, $st] = api('GET', '/settings', [], $tokenAll);
    ok($c === 200 && isset($st['items']), 'настройки читаются', "код $c");
    ok(($st['items']['social.telegram.secret'] ?? '') === '***',
       'секрет наружу не уходит даже суперадминскому токену');
    ok(($st['items']['appearance.method_password'] ?? '') === '1',
       'а тумблер входа отдаётся значением, а не маской',
       var_export($st['items']['appearance.method_password'] ?? null, true));

    /* Прочитал → поправил соседнее → записал всё обратно: обычный приём для
       машины. Прими маску за значение — и настоящий секрет стёрт строкой
       `***`, которая вдобавок читается как «включено» у любого тумблера. */
    [$c] = api('PATCH', '/settings', ['social.telegram.secret' => '***'], $tokenAll);
    ok($c === 422, 'запись маски вместо секрета отбита', "код $c");
    ok((string)DB::val('SELECT `value` FROM {settings} WHERE `key` = ?',
                       ['social.telegram.secret']) === 'проба-секрета',
       'и настоящий секрет остался на месте');

    echo "\n═══ Аудит помнит, кто это сделал ═══\n";
    $trace = DB::all("SELECT event, meta FROM {audit_log}
                       WHERE event LIKE 'api.%' ORDER BY id DESC LIMIT 20");
    $named = array_filter($trace, static fn(array $r): bool
        => str_contains((string)$r['meta'], 'проба суперадмина'));
    ok($named !== [], 'действия записаны с именем токена',
       'записей api.*: ' . count($trace));

    echo "\n═══ Уборка ═══\n";
    [$c] = api('DELETE', '/users/' . $sub . '/roles',
        ['role' => 'api_probe_editor', 'scope' => 'client', 'client_id' => $cid], $tokenAll);
    ok($c === 200, 'роль отозвана', "код $c");
    [$c] = api('DELETE', '/orgs/' . $made['org'], [], $tokenAll);
    ok($c === 200, 'организация удалена', "код $c");
    $made['org'] = null;
    [$c] = api('DELETE', '/clients/' . $cid, [], $tokenAll);
    ok($c === 200, 'портал удалён вместе со своими ролями', "код $c");
    $made['client'] = null;
    $made['role'] = null;
    /* Удаление в два шага. В интерфейсе от промаха защищает набранное руками
       имя аккаунта; у bearer-токена такой защиты нет, поэтому защитой работает
       порядок: пометить, потом стереть. Украденный токен не сносит живого
       человека одним запросом. */
    [$c] = api('DELETE', '/users/' . $sub . '?purge=1', [], $tokenAll);
    ok($c === 409, 'живого стереть насовсем нельзя — сначала пометь', "код $c");

    [$c] = api('DELETE', '/users/' . $sub, [], $tokenAll);
    ok($c === 200 || $c === 204, 'человек помечен удалённым', "код $c");

    /* Расхождение с живой установки: админка удалённых прячет, а API отвечал
       «людей 7» там, где человек видел троих. */
    [, $list] = api('GET', '/users?limit=200', [], $tokenAll);
    ok(!in_array($sub, array_column($list['items'] ?? [], 'sub'), true),
       'в общем списке удалённого нет — ровно как в админке');
    [, $all] = api('GET', '/users?limit=200&status=all', [], $tokenAll);
    ok(in_array($sub, array_column($all['items'] ?? [], 'sub'), true),
       'а по запросу status=all он виден');
    [, $del] = api('GET', '/users?limit=200&status=deleted', [], $tokenAll);
    ok(in_array($sub, array_column($del['items'] ?? [], 'sub'), true),
       'и по status=deleted тоже');

    [$c, $purged] = api('DELETE', '/users/' . $sub . '?purge=1', [], $tokenAll);
    ok($c === 200 && !empty($purged['purged']), 'помеченный стирается насовсем', "код $c");
    [$c] = api('GET', '/users/' . $sub, [], $tokenAll);
    ok($c === 404, 'и его действительно нет', "код $c");
    $made['user'] = null;
} finally {
    /* За собой убираем в любом случае: упавшая проверка не должна оставлять
       в базе портал с живым секретом. */
    if ($made['client'] !== null) {
        $c = \App\Models\Client::findByClientId($made['client']);
        if ($c) \App\Models\Client::purge((int)$c['id']);
    }
    if ($made['org'] !== null) \App\Models\Org::purge($made['org']);
    if ($made['user'] !== null) {
        $u = \App\Models\User::findByPublicId($made['user']);
        if ($u) \App\Models\User::purge((int)$u['id']);
    }
    if ($touchedSettings) {
        /* Проба настроек не должна оседать в базе: следующая проверка не обязана
           разбираться, откуда взялся secret телеграма. */
        Setting::delete('social.telegram.secret');
        Setting::delete('appearance.method_password');
        Setting::flush();
    }
    ApiToken::revoke($idAll);
    ApiToken::revoke($idThin);
}

echo "\n══ Итог ══\n";
printf("Пройдено: %d, провалено: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
