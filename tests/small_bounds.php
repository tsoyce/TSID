<?php
/**
 * Мелкие находки ревизии (v5.5) — каждая со своей проверкой.
 *
 * Здесь проверяется, что:
 *   · ответ «нет такого аккаунта» по времени не отличается от «неверный
 *     пароль». До 5.5 он приходил в 15 раз быстрее, и по нему перебирали
 *     адреса;
 *   · пароль внутри сессии (смена почты, пароля, удаление, 2FA) проверяется с
 *     лимитом попыток, а секреты не попадают в трассировки;
 *   · человек без пароля выключает 2FA текущим кодом;
 *   · архив бэкапа не остаётся во временной папке;
 *   · журнал по адресу и счёт сессий идут по индексу; миграции — по одной;
 *   · вход по ключу идёт той же дорогой, что остальные (вебхук login.success),
 *     а ключ без проверки пользователя не заменяет включённую 2FA;
 *   · админ портала видит в карточке человека только свой портал, а не его
 *     доступы, сессии и журнал по всей системе; участник организации с
 *     журналом одного портала не читает журнал организации целиком.
 *
 * Запуск: php tests/small_bounds.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Totp;
use App\Core\WebAuthn;
use App\Models\User;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$stamp = bin2hex(random_bytes(3));
$people = [];
$hooks = [];
$clients = [];
$orgs = [];
$jars = [];

$person = static function (string $email, array $extra = []) use (&$people): int {
    $uid = User::create($extra + ['email' => $email, 'first_name' => 'Проба', 'status' => 'active',
        'email_verified_at' => now(), 'password_hash' => password_hash('correct-horse-battery', PASSWORD_ARGON2ID)]);
    $people[] = $uid;
    return $uid;
};
$jar = static function (string $name) use (&$jars): string {
    $j = sys_get_temp_dir() . "/small-bounds-$name.txt";
    @unlink($j);
    $jars[] = $j;
    return $j;
};
$signIn = static function (string $email, string $jar, string $password = 'correct-horse-battery'): void {
    DB::run('DELETE FROM {rate_limits}');
    [, $lp] = http('GET', SSO . '/login', [], [], $jar);
    http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => $email, 'password' => $password], [], $jar);
};
$path = static fn(string $loc): string => (string)preg_replace('~^' . preg_quote(SSO, '~') . '~', '', $loc);

try {
    echo "── Вход: «нет такого» по времени как «неверный пароль» ──\n";
    $person("sb-$stamp@test.local");
    $tj = $jar('t');
    $median = static function (string $identity) use ($tj): float {
        $s = [];
        for ($i = 0; $i < 3; $i++) {
            DB::run('DELETE FROM {rate_limits}');
            [, $lp] = http('GET', SSO . '/login', [], [], $tj);
            $a = microtime(true);
            http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => $identity, 'password' => 'wrong-pass-' . $i], [], $tj);
            $s[] = microtime(true) - $a;
        }
        sort($s);
        return $s[1];
    };
    $known = $median("sb-$stamp@test.local");
    $unknown = $median("nobody-$stamp@test.local");
    DB::run('UPDATE {users} SET failed_logins = 0, locked_until = NULL WHERE email = ?', ["sb-$stamp@test.local"]);
    ok($unknown >= $known * 0.5, 'нет аккаунта — отвечаем не быстрее, чем на неверный пароль',
        sprintf('%.0f мс против %.0f мс', $unknown * 1000, $known * 1000));

    echo "\n── Пароль внутри сессии: лимит и трассировки ──\n";
    $j2 = $jar('reauth');
    $signIn("sb-$stamp@test.local", $j2);
    $codes = [];
    for ($i = 0; $i < 11; $i++) {
        [, $sp] = http('GET', SSO . '/profile/settings', [], [], $j2);
        [$st] = http('POST', SSO . '/profile/settings/email', ['_csrf' => csrfOf($sp), 'new_email' => "sb-new-$stamp@test.local",
            'password' => 'wrong-' . $i], [], $j2);
        $codes[] = $st;
    }
    ok(end($codes) === 429 && count(array_filter($codes, static fn($c) => $c !== 429)) === 10,
        'десять неверных паролей в профиле — одиннадцатый получает 429', implode(',', $codes));
    $sensitive = [
        [\App\Core\Auth::class, 'passwordOk', 1], [\App\Core\PasswordPolicy::class, 'check', 0],
        [\App\Models\Client::class, 'verifySecret', 1], [\App\Core\QuickUnlock::class, 'tryPin', 2],
        [\App\Core\QuickUnlock::class, 'setPin', 1], [\App\Core\Crypto::class, 'encrypt', 0],
        [\App\Core\DangerZone::class, 'unlock', 1], [\App\Models\User::class, 'useRecoveryCode', 1],
    ];
    $bare = [];
    foreach ($sensitive as [$cls, $fn, $arg]) {
        $p = (new ReflectionMethod($cls, $fn))->getParameters()[$arg];
        if ($p->getAttributes(\SensitiveParameter::class) === []) $bare[] = "$cls::$fn(\$" . $p->getName() . ')';
    }
    ok($bare === [], 'пароли, PIN и секреты помечены #[\SensitiveParameter]', implode(', ', $bare));

    echo "\n── 2FA без пароля ──\n";
    $secret = Totp::generateSecret();
    $u3 = $person("sb3-$stamp@test.local", ['totp_secret' => Crypto::encrypt($secret), 'totp_enabled_at' => now()]);
    $j3 = $jar('nopw');
    $signIn("sb3-$stamp@test.local", $j3);
    [, , $loc] = http('GET', SSO . '/login/2fa', [], [], $j3);
    [, $tp] = http('GET', SSO . '/login/2fa', [], [], $j3);
    http('POST', SSO . '/login/2fa', ['_csrf' => csrfOf($tp), 'code' => Totp::code($secret, intdiv(time(), 30))], [], $j3);
    DB::run('UPDATE {users} SET password_hash = NULL, totp_last_step = NULL WHERE id = ?', [$u3]);
    [, $sp] = http('GET', SSO . '/profile/security', [], [], $j3);
    ok(str_contains($sp, 'one-time-code'), 'без пароля форма просит код из приложения');
    http('POST', SSO . '/profile/security/2fa/disable', ['_csrf' => csrfOf($sp),
        'password' => Totp::code($secret, intdiv(time(), 30) + 1)], [], $j3);
    ok(DB::val('SELECT totp_enabled_at FROM {users} WHERE id = ?', [$u3]) === null, 'и выключает 2FA текущим кодом');

    echo "\n── Бэкап не остаётся во временной папке ──\n";
    DB::run('UPDATE {users} SET totp_secret = NULL, totp_enabled_at = NULL WHERE email = ?', ['admin@test.local']);
    $ja = $jar('admin');
    $signIn('admin@test.local', $ja);
    $tmp = static fn(): array => (array)glob(sys_get_temp_dir() . '/ssobak*');
    $before = $tmp();
    [$st, $zip] = http('GET', SSO . '/admin/tools/backup.zip', [], [], $ja);
    usleep(200_000);
    ok($st === 200 && str_starts_with($zip, "PK"), 'бэкап скачан', "код $st");
    ok(array_diff($tmp(), $before) === [], 'а временного архива с ключами не осталось');

    echo "\n── Индексы и миграции ──\n";
    if (DB::driver() === 'sqlite') {
        $plan = static fn(string $sql): string => implode(' | ', array_column(DB::all('EXPLAIN QUERY PLAN ' . $sql, [1]), 'detail'));
        ok(str_contains($plan('SELECT * FROM {audit_log} WHERE ip = ? ORDER BY id DESC LIMIT 50'), 'USING INDEX'),
            'журнал по адресу — по индексу');
        ok(str_contains($plan('SELECT COUNT(*) FROM {sessions} WHERE revoked_at IS NULL AND expires_at > ?'), 'USING INDEX'),
            'живые сессии — по индексу');
    }
    $fh = fopen(SSO_ROOT . '/storage/migrate.lock', 'c');
    flock($fh, LOCK_EX);
    exec('timeout 2 ' . escapeshellarg(PHP_BINARY) . ' -d extension=pdo_sqlite -r ' . escapeshellarg(
        'require ' . var_export(SSO_ROOT . '/app/bootstrap.php', true) . '; \App\Core\Migrations::apply();') . ' 2>/dev/null', $o, $rc);
    flock($fh, LOCK_UN);
    fclose($fh);
    ok($rc === 124, 'пока идёт один прогон миграций, второй ждёт', "код $rc");

    echo "\n── Вход по ключу ──\n";
    $u4 = $person("sb4-$stamp@test.local", ['totp_secret' => Crypto::encrypt(Totp::generateSecret()), 'totp_enabled_at' => now()]);
    $key = openssl_pkey_new(\App\Core\Keys::opensslArgs() + ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $credId = Crypto::b64url(random_bytes(16));
    DB::insert('passkeys', ['user_id' => $u4, 'credential_id' => $credId, 'alg' => -7, 'sign_count' => 0,
        'public_key' => openssl_pkey_get_details($key)['key'], 'created_at' => now()]);
    $hooks[] = DB::insert('webhooks', ['name' => 'Проба', 'url' => 'http://127.0.0.1:9/', 'secret' => 'x',
        'events' => json_encode(['login.success']), 'is_active' => 1, 'created_at' => now()]);
    $passkey = static function (int $flags) use ($credId, $key, $jar): array {
        $j = $jar('pk' . $flags);
        [, $lp] = http('GET', SSO . '/login', [], [], $j);
        [, $opt] = http('POST', SSO . '/passkey/login/options', ['_csrf' => csrfOf($lp)], [], $j);
        $challenge = (string)(json_decode($opt, true)['challenge'] ?? '');
        $cd = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => WebAuthn::origin()]);
        $ad = hash('sha256', WebAuthn::rpId(), true) . chr($flags) . pack('N', 0);
        openssl_sign($ad . hash('sha256', $cd, true), $sig, $key, OPENSSL_ALGO_SHA256);
        [$st, $body] = http('POST', SSO . '/passkey/login', ['_csrf' => csrfOf($lp), 'id' => $credId,
            'clientDataJSON' => Crypto::b64url($cd), 'authenticatorData' => Crypto::b64url($ad),
            'signature' => Crypto::b64url($sig)], [], $j);
        return [$st, json_decode($body, true) ?: []];
    };
    DB::run('DELETE FROM {rate_limits}');
    [$st, $b] = $passkey(0x01);
    ok($st === 200 && ($b['redirect'] ?? '') === '/login/2fa', 'ключ без проверки пользователя при 2FA ведёт за кодом',
        "$st " . json_encode($b, JSON_UNESCAPED_UNICODE));
    $hooked = static fn(): int => (int)DB::val("SELECT COUNT(*) FROM {webhook_deliveries} WHERE webhook_id = ? AND payload LIKE ?",
        [end($hooks), '%' . DB::val('SELECT public_id FROM {users} WHERE id = ?', [$u4]) . '%']);
    $was = $hooked();
    [$st, $b] = $passkey(0x05);
    ok($st === 200 && ($b['redirect'] ?? '') !== '/login/2fa' && ($b['ok'] ?? false), 'ключ с PIN или биометрией — сразу вход',
        "$st " . json_encode($b, JSON_UNESCAPED_UNICODE));
    ok($hooked() === $was + 1, 'и вход по ключу шлёт login.success, как остальные');

    echo "\n── Админ портала: карточка и журнал ──\n";
    $mk = static function (string $name) use (&$clients, $stamp): int {
        $slug = 'sb-' . count($clients) . '-' . $stamp;
        [$cid] = \App\Models\Client::create(['name' => $name, 'slug' => $slug, 'base_url' => "https://$slug.example",
            'redirect_uris' => ["https://$slug.example/cb"], 'post_logout_uris' => [], 'backchannel_uri' => null]);
        $clients[] = (int)$cid;
        return (int)$cid;
    };
    $mine = $mk("Свой портал $stamp");
    $other = $mk("Чужой портал $stamp");
    $p5 = $person("sb5-$stamp@test.local");
    \App\Models\Access::grant($p5, $mine, null, 'active');
    \App\Models\Access::grant($p5, $other, null, 'active');
    $d = $person("sb6-$stamp@test.local");
    \App\Models\Access::grant($d, $mine, null, 'active');
    foreach (['client_admin', 'auditor'] as $slug) {
        \App\Models\Role::assignScoped($d, (int)\App\Models\Role::findBySlug($slug)['id'], \App\Core\Scope::client($mine));
    }
    $org = \App\Models\Org::create("Организация $stamp", null, null);
    $orgs[] = $org;
    DB::update('clients', ['org_id' => $org], 'id = ?', [$mine]);
    \App\Models\Org::addMember($org, $d, 'member', null);
    $marker = 'sb-org-secret-' . $stamp;
    DB::insert('audit_log', ['event' => 'org.updated', 'org_id' => $org, 'meta' => json_encode(['name' => $marker]),
        'created_at' => now()]);
    $jd = $jar('delegate');
    $signIn("sb6-$stamp@test.local", $jd);
    [$st, $page] = http('GET', SSO . "/admin/users/$p5/access", [], [], $jd);
    ok($st === 200 && str_contains($page, "Свой портал $stamp") && !str_contains($page, "Чужой портал $stamp"),
        'в «Сервисах» человека — только свой портал', "код $st");
    [$st] = http('GET', SSO . "/admin/users/$p5/sessions", [], [], $jd);
    ok($st === 403, 'сессии человека по всей системе — не админу портала', "код $st");
    [$st, $page] = http('GET', SSO . "/admin/users/$p5", [], [], $jd);
    ok($st === 200 && !str_contains($page, "/admin/users/$p5/sessions"), 'и раздела «Сессии» у него в карточке нет');
    /* Токен — со страницы профиля: у одной области переключателя в шапке нет. */
    [, $dash] = http('GET', SSO . '/profile/settings', [], [], $jd);
    http('POST', SSO . '/admin/context', ['_csrf' => csrfOf($dash), 'scope' => 'org:' . $org], [], $jd);
    [$st, $page] = http('GET', SSO . '/admin/logs', [], [], $jd);
    ok($st === 200 && str_contains($page, "Организация $stamp") && !str_contains($page, $marker), 'журнал организации целиком участнику с журналом портала не виден', "код $st");
} finally {
    foreach ($orgs as $o) \App\Models\Org::purge($o);
    foreach ($clients as $c) \App\Models\Client::purge($c);
    foreach ($hooks as $h) {
        DB::run('DELETE FROM {webhook_deliveries} WHERE webhook_id = ?', [$h]);
        DB::run('DELETE FROM {webhooks} WHERE id = ?', [$h]);
    }
    foreach ($people as $uid) User::purge($uid);
    foreach ($jars as $j) @unlink($j);
    DB::run('DELETE FROM {rate_limits}');
}

echo "\n══ Итог ══\nПройдено: $passed, провалено: $failed\n";
exit($failed > 0 ? 1 : 0);
