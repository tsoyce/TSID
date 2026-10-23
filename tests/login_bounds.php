<?php
/**
 * Вход и токены (v4.97.2) — находки ревизии кода, каждая со своей проверкой.
 *
 * Здесь проверяется, что:
 *   · соцвход спрашивает код 2FA, если он включён. До 4.97.2 развилка «есть ли
 *     второй фактор» была у пароля и ссылки из письма и не было её у соцвхода;
 *   · запертая сессия не выдаёт код портала через /authorize;
 *   · FedCM не обходит требования портала: 2FA и разрешённые scope;
 *   · refresh-токен не живёт после завершения своей сессии, чужой клиент его
 *     не «сжигает», а auth_time в обновлённом id_token — время входа;
 *   · вторая смена почты не гасит ссылку «Это не я» от первой.
 *
 * Запуск: php tests/login_bounds.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\Setting;
use App\Models\Client;
use App\Models\User;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$JAR = sys_get_temp_dir() . '/login-bounds.txt';
@unlink($JAR);
$stamp = bin2hex(random_bytes(3));
$clients = [];
$people = [];

$keys = ['policy.welcome_screen', 'social.telegram.enabled', 'social.telegram.client_id',
    'social.telegram.secret', 'appearance.method_social'];
$was = [];
foreach ($keys as $k) $was[$k] = Setting::get($k);
Setting::set('policy.welcome_screen', '0');
Setting::flush();

$person = static function (string $email, array $extra = []) use (&$people): int {
    $uid = User::create($extra + ['email' => $email, 'first_name' => 'Проба', 'status' => 'active',
        'email_verified_at' => now(), 'password_hash' => password_hash('correct-horse-battery', PASSWORD_ARGON2ID)]);
    $people[] = $uid;
    return $uid;
};
$client = static function (array $extra = []) use (&$clients, $stamp): array {
    $slug = 'lb-' . count($clients) . '-' . $stamp;
    [$cid, $clientId, $secret] = Client::create($extra + ['name' => 'Проба входа', 'slug' => $slug,
        'base_url' => "https://$slug.example", 'redirect_uris' => ["https://$slug.example/cb"],
        'post_logout_uris' => ["https://$slug.example/"], 'backchannel_uri' => null,
        'allowed_scopes' => ['openid', 'profile', 'email', 'roles', 'offline_access'],
        'is_trusted' => 1, 'access_policy' => 'open']);
    $clients[] = (int)$cid;
    return [(int)$cid, $clientId, $secret, "https://$slug.example"];
};
$signIn = static function (string $email, string $jar): void {
    DB::run('DELETE FROM {rate_limits}');
    [, $lp] = http('GET', SSO . '/login', [], [], $jar);
    http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => $email, 'password' => 'correct-horse-battery'], [], $jar);
};
$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$authorize = static function (string $jar, string $clientId, string $base) use ($challenge): array {
    [$st, , $loc] = http('GET', SSO . '/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => "$base/cb",
        'scope' => 'openid profile email offline_access', 'state' => 'st', 'nonce' => 'no',
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]), [], [], $jar);
    parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
    return [$st, (string)$loc, $q];
};
$token = static fn(array $form): array => (static function () use ($form): array {
    [$st, $body] = http('POST', SSO . '/token', $form);
    return [$st, json_decode($body, true) ?: []];
})();
$claims = static fn(string $jwt): array => json_decode(base64_decode(strtr(explode('.', $jwt . '..')[1], '-_', '+/')), true) ?: [];
$path = static fn(string $loc): string => (string)preg_replace('~^' . preg_quote(SSO, '~') . '~', '', $loc);

try {
    echo "── Refresh: чужой клиент, auth_time, завершённая сессия ──\n";
    $uid = $person("lb-$stamp@test.local");
    [$cid, $clientId, $secret, $base] = $client();
    [, $otherId, $otherSecret] = $client();
    $signIn("lb-$stamp@test.local", $JAR);
    [, , $q] = $authorize($JAR, $clientId, $base);
    [$st, $tok] = $token(['grant_type' => 'authorization_code', 'code' => (string)($q['code'] ?? ''),
        'redirect_uri' => "$base/cb", 'client_id' => $clientId, 'client_secret' => $secret, 'code_verifier' => $verifier]);
    $rt = (string)($tok['refresh_token'] ?? '');
    ok($st === 200 && $rt !== '', 'код обменян, refresh выдан', "код $st");

    [$st] = $token(['grant_type' => 'refresh_token', 'refresh_token' => $rt, 'client_id' => $otherId, 'client_secret' => $otherSecret]);
    ok($st === 400, 'чужой клиент токен не обновляет', "код $st");
    [$st, $tok2] = $token(['grant_type' => 'refresh_token', 'refresh_token' => $rt, 'client_id' => $clientId, 'client_secret' => $secret]);
    ok($st === 200, 'и не сжигает его: свой клиент обновляет как ни в чём не бывало', "код $st");
    $sid = (int)DB::val('SELECT session_id FROM {refresh_tokens} WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid]);
    $created = (int)strtotime((string)DB::val('SELECT created_at FROM {sessions} WHERE id = ?', [$sid]) . ' UTC');
    DB::update('sessions', ['created_at' => gmdate('Y-m-d H:i:s', $created - 3600)], 'id = ?', [$sid]);
    [$st, $tok3] = $token(['grant_type' => 'refresh_token', 'refresh_token' => (string)($tok2['refresh_token'] ?? ''),
        'client_id' => $clientId, 'client_secret' => $secret]);
    $at = (int)($claims((string)($tok3['id_token'] ?? ''))['auth_time'] ?? 0);
    ok($st === 200 && $at === $created - 3600, 'auth_time после обновления — время входа, а не обновления', "$at против " . ($created - 3600));

    echo "\n── Замок ──\n";
    \App\Core\QuickUnlock::lock($sid);
    [$st, $loc] = $authorize($JAR, $clientId, $base);
    ok($st === 302 && str_starts_with($path($loc), '/lock'), 'запертая сессия кода не выдаёт — ведёт на замок', "$st $loc");
    \App\Core\QuickUnlock::unlock($sid);

    \App\Models\SessionModel::revoke($sid);
    [$st] = $token(['grant_type' => 'refresh_token', 'refresh_token' => (string)($tok3['refresh_token'] ?? ''),
        'client_id' => $clientId, 'client_secret' => $secret]);
    ok($st === 400, 'сессия завершена — refresh больше не работает', "код $st");
    ok((int)DB::val('SELECT COUNT(*) FROM {refresh_tokens} WHERE session_id = ? AND revoked_at IS NULL', [$sid]) === 0,
        'и вся семья отозвана');

    echo "\n── FedCM ──\n";
    @unlink($JAR);
    $signIn("lb-$stamp@test.local", $JAR);
    $pub = (string)DB::val('SELECT public_id FROM {users} WHERE id = ?', [$uid]);
    $fedcm = static function (string $clientId, string $origin) use ($pub): array {
        [$st, $body] = http('POST', SSO . '/fedcm/assertion', ['client_id' => $clientId, 'account_id' => $pub, 'nonce' => 'n'],
            ['Sec-Fetch-Dest: webidentity', 'Origin: ' . $origin]);
        return [$st, json_decode($body, true) ?: []];
    };
    [, $c2fa, , $b2fa] = $client(['require_2fa' => 1]);
    [$st] = $fedcm($c2fa, $b2fa);
    ok($st === 403, 'портал требует 2FA, а её нет — FedCM отказывает', "код $st");
    [, $cNoRoles, , $bNoRoles] = $client(['allowed_scopes' => ['openid', 'profile', 'email']]);
    [$st, $b] = $fedcm($cNoRoles, $bNoRoles);
    ok($st === 200 && !array_key_exists('roles', $claims((string)($b['token'] ?? ''))),
        'роли не разрешены порталу — в токене FedCM их нет', "код $st");

    echo "\n── Соцвход и 2FA ──\n";
    $tgSecret = '123456789:AAH' . str_repeat('y', 32);
    Setting::set('social.telegram.enabled', '1');
    Setting::set('social.telegram.client_id', 'probe_bot');
    Setting::set('social.telegram.secret', $tgSecret);
    Setting::set('appearance.method_social', '1');
    Setting::flush();
    $u2 = $person("lb2-$stamp@test.local", ['totp_secret' => 'JBSWY3DPEHPK3PXP', 'totp_enabled_at' => now()]);
    $tgId = (string)random_int(100000000, 999999999);
    DB::insert('linked_identities', ['user_id' => $u2, 'provider' => 'telegram', 'provider_uid' => $tgId, 'created_at' => now()]);
    $tgJar = sys_get_temp_dir() . '/login-bounds-tg.txt';
    @unlink($tgJar);
    [, , $startLoc] = http('GET', SSO . '/social/telegram/start', [], [], $tgJar);
    parse_str((string)parse_url($startLoc, PHP_URL_QUERY), $sq);
    parse_str((string)parse_url((string)($sq['return_to'] ?? ''), PHP_URL_QUERY), $back);
    $data = ['id' => $tgId, 'first_name' => 'Проба', 'auth_date' => (string)time()];
    $pairs = [];
    foreach ($data as $k => $v) $pairs[] = "$k=$v";
    sort($pairs);
    $data['hash'] = hash_hmac('sha256', implode("\n", $pairs), hash('sha256', $tgSecret, true));
    [$st, , $loc] = http('GET', SSO . '/social/telegram/callback?' . http_build_query($data + ['state' => (string)($back['state'] ?? '')]), [], [], $tgJar);
    ok($st === 302 && str_starts_with($path($loc), '/login/2fa'), 'вход через Telegram при включённой 2FA спрашивает код', "$st $loc");
    @unlink($tgJar);

    echo "\n── «Это не я» переживает вторую смену почты ──\n";
    $u3 = $person("lb3-$stamp@test.local");
    $j3 = sys_get_temp_dir() . '/login-bounds-3.txt';
    @unlink($j3);
    $signIn("lb3-$stamp@test.local", $j3);
    foreach (['a', 'b'] as $n) {
        [, $sp] = http('GET', SSO . '/profile/settings', [], [], $j3);
        http('POST', SSO . '/profile/settings/email', ['_csrf' => csrfOf($sp), 'new_email' => "lb3$n-$stamp@test.local",
            'password' => 'correct-horse-battery'], [], $j3);
    }
    $live = (int)DB::val("SELECT COUNT(*) FROM {one_time_tokens} WHERE user_id = ? AND consumed_at IS NULL
        AND payload LIKE '%revert%'", [$u3]);
    ok($live === 2, 'обе ссылки «Это не я» живы: вторая смена первую не гасит', "живых: $live");
    @unlink($j3);
} finally {
    foreach ($was as $k => $v) {
        if ($v === null) DB::run('DELETE FROM {settings} WHERE `key` = ?', [$k]);
        else Setting::set($k, (string)$v);
    }
    Setting::flush();
    foreach ($clients as $cid) Client::purge($cid);
    foreach ($people as $uid) User::purge($uid);
    @unlink($JAR);
}

echo "\n══ Итог ══\nПройдено: $passed, провалено: $failed\n";
exit($failed > 0 ? 1 : 0);
