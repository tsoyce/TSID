<?php
/**
 * Дубли аккаунтов (v4.88).
 *
 * Владелец 27.09 вошёл через второй аккаунт Яндекса с другой почтой, и SSO
 * предложил завести новый аккаунт, хотя фамилия, имя и дата рождения совпали.
 * Попросил и объединение из админки. Здесь проверяется, что:
 *   · похожий аккаунт находится по телефону или по фамилии, имени и дате
 *     рождения — и не находится по одному имени или без года;
 *   · найденный аккаунт назван, но не раскрыт (почта с одной буквой);
 *   · «Войти и привязать»: после входа в свой аккаунт сервис привязывается к
 *     нему — сквозным путём по HTTP, с входом по паролю; чужую связку не перебивает;
 *   · админка объединяет аккаунты: за паролем, в выбранную сторону, с записью
 *     в журнале от имени администратора; суперадмина не вливает.
 *
 * Проверка повторяемая: заводит свои аккаунты и убирает их за собой.
 *
 * Запуск: php tests/duplicates.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\DuplicateHint;
use App\Core\PendingLink;
use App\Core\Setting;
use App\Core\View;
use App\Models\User;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$JAR = sys_get_temp_dir() . '/duplicates-admin.txt';
$ANON = sys_get_temp_dir() . '/duplicates-anon.txt';
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);
$stamp = bin2hex(random_bytes(3));
$made = [];
$mk = static function (array $data) use (&$made, $stamp): int {
    $id = User::create($data + ['email' => 'dup-' . $stamp . '-' . count($made) . '@test.local', 'status' => 'active']);
    $made[] = $id;
    return $id;
};
$keys = ['social.telegram.enabled', 'social.telegram.client_id', 'social.telegram.secret', 'appearance.method_social'];
$was = [];
foreach ($keys as $k) $was[$k] = Setting::get($k);

try {
    DB::run('DELETE FROM {rate_limits}');

    echo "── Похожий аккаунт ──\n";
    $main = $mk(['first_name' => 'Пётр', 'last_name' => 'Ёлкин', 'birthdate' => '1990-06-15',
        'phone' => '+7999' . random_int(1000000, 9999999), 'email' => 'petr-' . $stamp . '@test.local']);
    $mainRow = User::find($main);
    $hit = DuplicateHint::forProfile(['first_name' => 'ПЁТР ', 'last_name' => 'елкин', 'birthdate' => '1990-06-15', 'email' => 'other@test.local']);
    ok($hit !== null && (int)$hit['user']['id'] === $main && $hit['why'] === 'name_birthdate',
        'фамилия, имя и дата рождения — регистр, ё и пробелы не в счёт');
    $hit = DuplicateHint::forProfile(['first_name' => 'Кто-то', 'last_name' => 'Другой', 'phone' => (string)$mainRow['phone']]);
    ok($hit !== null && (int)$hit['user']['id'] === $main && $hit['why'] === 'phone', 'номер телефона — сам по себе');
    ok(DuplicateHint::forProfile(['first_name' => 'Пётр', 'last_name' => 'Ёлкин']) === null, 'одних фамилии и имени мало — тёзки бывают');
    ok(DuplicateHint::forProfile(['first_name' => 'Пётр', 'last_name' => 'Ёлкин', 'birthdate' => '1991-06-15']) === null, 'другая дата — не он');
    User::update($main, ['status' => 'blocked']);
    ok(DuplicateHint::forProfile(['first_name' => 'Пётр', 'last_name' => 'Ёлкин', 'birthdate' => '1990-06-15']) === null,
        'в заблокированный не зовём — войти в него нельзя');
    User::update($main, ['status' => 'active']);
    ok(DuplicateHint::describe($mainRow) === 'p•••@test.local', 'аккаунт назван, но не раскрыт: первая буква и домен');

    $page = View::fetch('auth/signup', ['providerTitle' => 'Яндекс ID', 'willGet' => [], 'docs' => [],
        'dup' => ['user' => $mainRow, 'why' => 'name_birthdate']]);
    ok(str_contains($page, 'data-dup-hint') && str_contains($page, 'href="/social/signup/link"')
        && str_contains($page, 'btn btn-secondary btn-block mt-16" type="submit">Нет, создать новый аккаунт'),
        'на «Создать аккаунт?» главным становится «Войти и привязать», новый аккаунт — запасным');
    $plain = View::fetch('auth/signup', ['providerTitle' => 'Яндекс ID', 'willGet' => [], 'docs' => [], 'dup' => null]);
    ok(!str_contains($plain, 'data-dup-hint') && str_contains($plain, 'btn btn-primary btn-block mt-16" type="submit">Создать аккаунт'),
        'без похожего — как было');
    ok(str_contains($plain, "<p>Яндекс ID подтвердил вашу личность.<br>\nАккаунта в "), 'две мысли — две строки');

    echo "\n── Войти и привязать — сквозным путём ──\n";
    $token = '123456789:AAH' . str_repeat('y', 32);
    Setting::set('social.telegram.enabled', '1');
    Setting::set('social.telegram.client_id', 'probe_bot');
    Setting::set('social.telegram.secret', $token);
    Setting::set('appearance.method_social', '1');
    Setting::flush();
    $pw = 'dup-pass-' . $stamp;
    User::update($main, ['password_hash' => password_hash($pw, PASSWORD_ARGON2ID)]);
    @unlink($ANON);
    [, , $loc] = http('GET', SSO . '/social/telegram/start', [], [], $ANON);
    parse_str((string)parse_url((string)$loc, PHP_URL_QUERY), $q);
    parse_str((string)parse_url((string)($q['return_to'] ?? ''), PHP_URL_QUERY), $back);
    $tgUid = (string)random_int(100000000, 999999999);
    $data = ['id' => $tgUid, 'first_name' => 'Пётр', 'auth_date' => (string)time()];
    $pairs = [];
    foreach ($data as $k => $v) $pairs[] = "$k=$v";
    sort($pairs);
    $data['hash'] = hash_hmac('sha256', implode("\n", $pairs), hash('sha256', $token, true));
    [, , $loc] = http('GET', SSO . '/social/telegram/callback?' . http_build_query($data + ['state' => (string)($back['state'] ?? '')]), [], [], $ANON);
    ok(str_contains((string)$loc, '/social/signup'), 'новый для SSO Telegram — «Создать аккаунт?»', (string)$loc);
    [$st, , $loc] = http('GET', SSO . '/social/signup/link', [], [], $ANON);
    [, $login] = http('GET', SSO . '/login', [], [], $ANON);
    ok(str_contains((string)$loc, '/login') && str_contains($login, 'data-pending-link') && str_contains($login, 'Telegram привяжется к нему'),
        '«Войти и привязать» ведёт на вход, и там объяснено, зачем');
    http('POST', SSO . '/login', ['_csrf' => csrfOf($login), 'identity' => 'petr-' . $stamp . '@test.local', 'password' => $pw], [], $ANON);
    $linked = DB::row('SELECT user_id FROM {linked_identities} WHERE provider = ? AND provider_uid = ?', ['telegram', $tgUid]);
    ok($linked !== null && (int)$linked['user_id'] === $main, 'вошёл в свой аккаунт — Telegram привязан к нему, нового аккаунта нет');
    ok((int)DB::val("SELECT COUNT(*) FROM {audit_log} WHERE event = 'social.linked' AND actor_user_id = ? AND meta LIKE ?", [$main, '%duplicate_hint%']) === 1,
        'в журнале — привязка через подсказку');

    $_SESSION = [];
    $other = $mk(['first_name' => 'Чужой']);
    PendingLink::stash(['provider' => 'telegram', 'provider_title' => 'Telegram', 'uid' => $tgUid, 'profile' => []]);
    PendingLink::applyTo(User::find($other));
    ok((int)DB::val('SELECT user_id FROM {linked_identities} WHERE provider = ? AND provider_uid = ?', ['telegram', $tgUid]) === $main
        && PendingLink::peek() === null, 'связка сервиса с другим аккаунтом не перебивается, отложенное — забыто');
    $_SESSION['pending_link'] = ['provider' => 'telegram', 'provider_title' => 'Telegram', 'uid' => 'x', 'profile' => [], 't' => time() - 601];
    ok(PendingLink::peek() === null, 'отложенная связка живёт десять минут');
    ok(str_contains($src('app/Core/LoginFlow.php'), "Audit::log(\$auditEvent, ['actor' => (int)\$user['id'], 'on' => (int)\$user['id']]);\n        /* «Войти и привязать»")
        && strpos($src('app/Core/LoginFlow.php'), 'PendingLink::applyTo($user);') > strpos($src('app/Core/LoginFlow.php'), 'EmailGate::holds($user)'),
        'привязка — там, где сходятся все пути входа, после второго фактора и подтверждения почты');

    echo "\n── Объединение из админки ──\n";
    DB::run('UPDATE {users} SET totp_secret = NULL, totp_enabled_at = NULL WHERE email = ?', ['admin@test.local']);
    @unlink($JAR);
    [, $lp] = http('GET', SSO . '/login');
    http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => 'admin@test.local', 'password' => 'correct-horse-battery']);
    $adminId = (int)DB::val('SELECT id FROM {users} WHERE email = ?', ['admin@test.local']);
    $dup = $mk(['first_name' => 'Пётр', 'last_name' => 'Ёлкин', 'birthdate' => '1990-06-15', 'email' => 'petr2-' . $stamp . '@test.local']);
    [$st, $mp] = http('GET', SSO . "/admin/users/$main/merge");
    ok($st === 200 && str_contains($mp, 'data-merge-hints') && str_contains($mp, "/admin/users/$main/merge?with=$dup"),
        'в карточке — возможные дубли со ссылкой на сравнение');
    [$st, $mp] = http('GET', SSO . "/admin/users/$main/merge?with=" . urlencode('petr2-' . $stamp . '@test.local'));
    ok($st === 200 && str_contains($mp, 'data-merge-sides') && str_contains($mp, 'petr2-' . $stamp . '@test.local')
        && str_contains($mp, 'f-danger-pw'), 'второй находится по почте, стороны рядом, объединение — за паролем');
    http('POST', SSO . "/admin/users/$main/merge", ['_csrf' => csrfOf($mp), 'with' => $dup, 'keep' => 'this']);
    ok(User::find($dup)['status'] === 'active', 'без пароля не объединяет');
    [, , $loc] = http('POST', SSO . "/admin/users/$main/danger", ['_csrf' => csrfOf($mp), 'password' => 'correct-horse-battery',
        'back' => 'merge', 'with' => $dup]);
    ok(str_ends_with((string)$loc, "/admin/users/$main/merge?with=$dup"), 'пароль возвращает на сравнение, а не в опасную зону');
    [, $mp] = http('GET', SSO . "/admin/users/$main/merge?with=$dup");
    ok(str_contains($mp, 'name="keep" value="this" checked'), 'по умолчанию остаётся этот аккаунт');
    [, , $loc] = http('POST', SSO . "/admin/users/$main/merge", ['_csrf' => csrfOf($mp), 'with' => $dup, 'keep' => 'other']);
    $mainNow = User::find($main);
    $dupNow = User::find($dup);
    ok($mainNow['status'] === 'deleted' && (int)$mainNow['merged_into'] === $dup && $dupNow['status'] === 'active'
        && str_ends_with((string)$loc, "/admin/users/$dup"), '«Оставить второй» — этот вливается туда, и карточка открывается на оставшемся');
    ok((int)DB::val('SELECT user_id FROM {linked_identities} WHERE provider = ? AND provider_uid = ?', ['telegram', $tgUid]) === $dup,
        'сервисы входа переехали к оставшемуся');
    $row = DB::row("SELECT actor_user_id, meta FROM {audit_log} WHERE event = 'account.merged' AND on_user_id = ? ORDER BY id DESC LIMIT 1", [$main]);
    ok($row !== null && (int)$row['actor_user_id'] === $adminId && str_contains((string)$row['meta'], '"by":"admin"'),
        'в журнале — от имени администратора');
    $ctl = $src('app/Controllers/Admin/UsersController.php');
    ok(str_contains($ctl, "if (in_array('superadmin', User::globalRoleSlugs((int)\$dup['id']), true)) {"),
        'суперадмина не вливает — как и не удаляет');
} finally {
    foreach ($was as $k => $v) $v === null ? Setting::delete($k) : Setting::set($k, (string)$v);
    Setting::flush();
    foreach ($made as $id) {
        try { User::purge((int)$id); } catch (\Throwable $e) { /* уже нет */ }
    }
    @unlink($JAR);
    @unlink($ANON);
}

echo "\n" . ($failed === 0 ? "✓ все $passed проверок" : "✗ провалов: $failed из " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
