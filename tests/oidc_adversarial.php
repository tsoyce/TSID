<?php
/**
 * Состязательная проверка OIDC: то, что должно быть отвергнуто.
 * Каждая строка — попытка сделать не то, и ожидание, что нас не пустят.
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';   // ok(), http(), csrfOf() — общие с e2e и смоуком

use App\Core\DB;

const SSO = 'http://localhost:8710';
$JAR = sys_get_temp_dir() . '/oidc-probe.txt';
@unlink($JAR);

$passed = 0; $failed = 0;

/* Подопытный портал: свой, чтобы не портить существующие. */
DB::run('DELETE FROM {clients} WHERE slug = ?', ['probe']);
$secret = null;
[$cid, $clientId, $secret] = App\Models\Client::create([
    'name' => 'Проба OIDC', 'slug' => 'probe', 'base_url' => 'https://probe.example',
    'redirect_uris' => ['https://probe.example/cb'], 'post_logout_uris' => ['https://probe.example/'],
    'backchannel_uri' => null, 'allowed_scopes' => ['openid', 'profile', 'email', 'roles', 'offline_access'],
    'is_trusted' => 1, 'access_policy' => 'open',
]);
echo "портал: $clientId\n\n";

/* Экран первого входа выключаем: здесь проверяется протокол, а не UX, и лишний
   прыжок на /consent сбивал бы каждую вторую проверку. Сам экран проверяется в
   e2e — там, где он и живёт по смыслу. */
DB::run('DELETE FROM {settings} WHERE `key` = ?', ['policy.welcome_screen']);
$welcomeWas = (string)App\Core\Setting::get('policy.welcome_screen', '1');
App\Core\Setting::set('policy.welcome_screen', '0');
App\Core\Setting::flush();
/* Возвращаем как было, чем бы прогон ни кончился: иначе выключенный экран
   остаётся в базе стенда, и разработчик видит тумблер не в том положении. */
register_shutdown_function(static function () use ($welcomeWas): void {
    App\Core\Setting::set('policy.welcome_screen', $welcomeWas);
    App\Core\Setting::flush();
});

/* Входим СВОИМ человеком, а не админом: политика может требовать от админ-ролей
   2FA, и тогда вход паролем застрянет на втором шаге — проверка развалилась бы
   не по делу. Обычному пользователю для OIDC-флоу большего и не нужно. */
DB::run('DELETE FROM {users} WHERE email = ?', ['oidc-probe@test.local']);
$probeUid = App\Models\User::create([
    'email' => 'oidc-probe@test.local', 'first_name' => 'Проба',
    'password_hash' => password_hash('correct-horse-battery', PASSWORD_ARGON2ID),
    'status' => 'active', 'email_verified_at' => now(),
]);
$userRole = App\Models\Role::findBySlug('user');
if ($userRole) App\Models\Role::assign($probeUid, (int)$userRole['id']);
DB::run('DELETE FROM {rate_limits}');

[, $lp] = http('GET', SSO . '/login');
http('POST', SSO . '/login', ['_csrf' => csrfOf($lp),
    'identity' => 'oidc-probe@test.local', 'password' => 'correct-horse-battery']);

$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$authUrl = static fn(array $q): string => SSO . '/authorize?' . http_build_query($q + [
    'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => 'https://probe.example/cb',
    'scope' => 'openid profile email roles offline_access', 'state' => 'st', 'nonce' => 'no',
    'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
]);

echo "── Чужой redirect_uri ──\n";
[$st, $body] = http('GET', $authUrl(['redirect_uri' => 'https://evil.example/cb']));
ok($st !== 302 || !str_contains($body, 'evil.example'), 'чужой redirect_uri не принимается', "status=$st");
[$st, $body] = http('GET', $authUrl(['redirect_uri' => 'https://probe.example/cb/../../evil']));
ok(!str_contains($body, 'code='), 'обход через ../ не проходит');

echo "\n── Неизвестный клиент ──\n";
[$st, $body] = http('GET', $authUrl(['client_id' => 'cli_nonexistent']));
ok($st !== 302, 'неизвестный client_id не редиректит', "status=$st");

echo "\n── PKCE ──\n";
[, , $loc] = http('GET', $authUrl([]));
parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
$code = (string)($q['code'] ?? '');
ok($code !== '', 'код авторизации выдан');

if ($code !== '') {
    [$st, $body] = http('POST', SSO . '/token', ['grant_type' => 'authorization_code',
        'code' => $code, 'redirect_uri' => 'https://probe.example/cb',
        'client_id' => $clientId, 'client_secret' => $secret,
        'code_verifier' => 'НЕ-ТОТ-verifier-совсем-другой-длинный-текст']);
    ok($st >= 400, 'неверный code_verifier отвергнут', "status=$st " . mb_substr($body, 0, 60));

    /* Тот же код с верным verifier — после неудачной попытки он ещё жив? */
    [$st, $body] = http('POST', SSO . '/token', ['grant_type' => 'authorization_code',
        'code' => $code, 'redirect_uri' => 'https://probe.example/cb',
        'client_id' => $clientId, 'client_secret' => $secret, 'code_verifier' => $verifier]);
    $tok = json_decode($body, true);
    ok($st === 200 && !empty($tok['id_token']), 'верный verifier обменивается на токен', "status=$st");

    echo "\n── Повторное использование кода ──\n";
    [$st2, $body2] = http('POST', SSO . '/token', ['grant_type' => 'authorization_code',
        'code' => $code, 'redirect_uri' => 'https://probe.example/cb',
        'client_id' => $clientId, 'client_secret' => $secret, 'code_verifier' => $verifier]);
    ok($st2 >= 400, 'код нельзя обменять дважды', "status=$st2");

    echo "\n── Секрет клиента ──\n";
    [, , $loc] = http('GET', $authUrl(['state' => 's2']));
    parse_str((string)parse_url($loc, PHP_URL_QUERY), $q2);
    $code2 = (string)($q2['code'] ?? '');
    [$st, $body] = http('POST', SSO . '/token', ['grant_type' => 'authorization_code',
        'code' => $code2, 'redirect_uri' => 'https://probe.example/cb',
        'client_id' => $clientId, 'client_secret' => 'не-тот-секрет', 'code_verifier' => $verifier]);
    ok($st >= 400, 'неверный client_secret отвергнут', "status=$st");

    echo "\n── id_token ──\n";
    if (!empty($tok['id_token'])) {
        [$h, $p, $s] = explode('.', $tok['id_token']);
        $claims = json_decode((string)base64_decode(strtr($p, '-_', '+/')), true);
        $header = json_decode((string)base64_decode(strtr($h, '-_', '+/')), true);
        ok(($header['alg'] ?? '') === 'RS256', 'подпись RS256, а не none', (string)($header['alg'] ?? '—'));
        ok(($claims['iss'] ?? '') === rtrim(base_url(), '/'), 'iss совпадает с issuer');
        ok(($claims['aud'] ?? '') === $clientId, 'aud — этот клиент');
        ok(($claims['nonce'] ?? '') === 'no', 'nonce возвращён как прислали');
        ok(isset($claims['access_level'], $claims['access_tier']), 'уровень доступа в токене');
        /* Подпись портим — проверка обязана развалиться. */
        $bad = $h . '.' . $p . '.' . strrev($s);
        [$stU] = http('GET', SSO . '/userinfo', [], ['Authorization: Bearer ' . $bad]);
        ok($stU >= 400, '/userinfo не принимает битую подпись', "status=$stU");
    }

    echo "\n── Refresh ──\n";
    if (!empty($tok['refresh_token'])) {
        /* Токен первого кода отозван его повтором выше (RFC 6749 §4.1.2,
           v4.70). Ротацию проверяем на токене свежего кода. */
        [$st] = http('POST', SSO . '/token', ['grant_type' => 'refresh_token',
            'refresh_token' => (string)$tok['refresh_token'], 'client_id' => $clientId, 'client_secret' => $secret]);
        ok($st >= 400, 'refresh по повторённому коду отозван', "status=$st");
        [, , $loc] = http('GET', $authUrl(['state' => 's3']));
        parse_str((string)parse_url($loc, PHP_URL_QUERY), $q3);
        [, $body] = http('POST', SSO . '/token', ['grant_type' => 'authorization_code',
            'code' => (string)($q3['code'] ?? ''), 'redirect_uri' => 'https://probe.example/cb',
            'client_id' => $clientId, 'client_secret' => $secret, 'code_verifier' => $verifier]);
        $rt = (string)(json_decode($body, true)['refresh_token'] ?? '');
        [$st, $body] = http('POST', SSO . '/token', ['grant_type' => 'refresh_token',
            'refresh_token' => $rt, 'client_id' => $clientId, 'client_secret' => $secret]);
        $new = json_decode($body, true);
        ok($st === 200 && !empty($new['refresh_token']), 'refresh обменивается и ротируется');
        ok(($new['refresh_token'] ?? '') !== $rt, 'выдан НОВЫЙ refresh, старый не переиспользуется');
        [$st] = http('POST', SSO . '/token', ['grant_type' => 'refresh_token',
            'refresh_token' => $rt, 'client_id' => $clientId, 'client_secret' => $secret]);
        ok($st >= 400, 'старый refresh после ротации отвергнут', "status=$st");
    } else {
        echo "  (offline_access не запрашивался — refresh не выдан)\n";
    }
}

echo "\n── Служебные эндпоинты без авторизации ──\n";
[$st] = http('POST', SSO . '/introspect', ['token' => 'что-угодно']);
ok($st >= 400, '/introspect требует клиента', "status=$st");
[$st] = http('POST', SSO . '/revoke', ['token' => 'что-угодно']);
ok($st >= 400, '/revoke требует клиента', "status=$st");
[$st] = http('GET', SSO . '/userinfo');
ok($st >= 400, '/userinfo без токена не отдаёт данные', "status=$st");

/* ── Свежесть входа: prompt=login и max_age (v4.47) ──────────────────────────
   Сервис страхует опасное действие, прося подтвердить пароль ПРЯМО сейчас.
   Сравнение возраста сессии было строгим (`>`), и `max_age=0` не срабатывал
   никогда: свежая сессия живёт ноль секунд, а `0 > 0` — ложь. Сервис просил
   переспросить, SSO не спрашивал и отвечал кодом как ни в чём не бывало.

   Вторая половина не менее важна: исполненное требование обязано СНИМАТЬСЯ.
   Иначе после переспроса сессия снова нулевого возраста, условие снова
   истинно, и человек ходит по кругу «вход → authorize → вход» без выхода. */
echo "\n── Свежесть входа ──\n";
/* Возраст сессии обнуляем явно. Без этого проверка зависела бы от секундомера:
   пока тест дойдёт сюда, сессии уже секунда-другая, и `1 > 0` истинно даже у
   сломанного сравнения — дефект прошёл бы мимо. Поймано мутацией: она внесла
   поломку, а проверка осталась зелёной. */
/* И не просто обнуляем, а ставим на пять секунд ВПЕРЁД. «Сейчас» с точностью
   до секунды — тоже секундомер: если запрос перешагнёт границу секунды, возраст
   сессии станет 1, и сломанное `$authAge > $maxAge` при max_age=0 даст то же,
   что и правильное. Именно так мутация №9 время от времени проходила
   незамеченной — «слепота без улики» из AUDIT.md. С возрастом меньше нуля
   сломанное сравнение не переспросит никогда, правильное — всегда. */
DB::run('UPDATE {sessions} SET created_at = ? WHERE user_id = ?', [now_plus(5), $probeUid]);
[, , $locFresh] = http('GET', $authUrl(['max_age' => '0']));
ok(str_contains((string)$locFresh, '/login'),
    'max_age=0 отправляет подтвердить пароль', (string)$locFresh);
ok(!str_contains((string)$locFresh, 'code='),
    'и кода при этом не выдаёт');
/* После входа требование снято: разбираем адрес, на который нас вернули. */
parse_str((string)parse_url((string)$locFresh, PHP_URL_QUERY), $qFresh);
$rtFresh = (string)($qFresh['rt'] ?? '');
ok($rtFresh !== '', 'ключ возврата выдан — сервис не потеряется');
$saved = $rtFresh !== '' ? \App\Core\Resume::peek($rtFresh) : null;
ok(is_array($saved) && !isset($saved['max_age']),
    'в сохранённом запросе max_age уже снят — переспрос не зациклится',
    is_array($saved) ? 'ключи: ' . implode(',', array_keys($saved)) : 'запрос не сохранён');

[, , $locOld] = http('GET', $authUrl(['max_age' => '3600']));
ok(str_contains((string)$locOld, 'code='),
    'max_age=3600 у свежей сессии пропускает молча', (string)$locOld);

[, , $locPrompt] = http('GET', $authUrl(['prompt' => 'login']));
ok(str_contains((string)$locPrompt, '/login'), 'prompt=login по-прежнему переспрашивает');

echo "\n── Открытый редирект на выходе ──\n";
[$st, $body, $loc] = http('GET', SSO . '/logout?post_logout_redirect_uri=' . urlencode('https://evil.example/'));
ok(!str_contains($loc, 'evil.example'), 'чужой post_logout_redirect_uri не исполняется', $loc);

/* ═══ Подмена стороны и обход PKCE ═══

   Всё, что ниже, спрашивает не «работает ли вход», а «нельзя ли войти не тем
   способом»: обменять чужой код, подменить адрес возврата, отказаться от PKCE,
   попросить неявный поток, получить права, которых порталу не давали. Ни одну
   из этих дверей батарея прежде не дёргала — работало, но не проверялось, а
   это ровно то состояние, из которого дефект появляется молча. */

DB::run('DELETE FROM {clients} WHERE slug = ?', ['probe2']);
[, $clientId2, $secret2] = App\Models\Client::create([
    'name' => 'Проба OIDC — узкий', 'slug' => 'probe2', 'base_url' => 'https://probe2.example',
    'redirect_uris' => ['https://probe2.example/cb'], 'post_logout_uris' => ['https://probe2.example/'],
    /* Порталу нарочно НЕ дано ни offline_access, ни roles: на нём проверяется,
       что попросить их у нас недостаточно. */
    'backchannel_uri' => null, 'allowed_scopes' => ['openid', 'profile'],
    'is_trusted' => 1, 'access_policy' => 'open',
]);

/** Пройти /authorize и разобрать, что вернулось. */
$grab = static function (array $q) use ($authUrl): array {
    [$st, $body, $loc] = http('GET', $authUrl($q));
    parse_str((string)parse_url((string)$loc, PHP_URL_QUERY), $qs);
    return ['st' => $st, 'loc' => (string)$loc, 'code' => (string)($qs['code'] ?? ''),
            'error' => (string)($qs['error'] ?? '')];
};
/** Обмен кода на токены. */
$exchange = static fn(array $f): array => json_decode(
    http('POST', SSO . '/token', $f, [], sys_get_temp_dir() . '/oidc-token.txt')[1], true) ?: [];

echo "\n── Код одного портала в руках другого ──\n";
$g = $grab([]);
ok($g['code'] !== '', 'портал получил код', $g['loc']);
/* Адрес возврата шлём ТОТ ЖЕ, что был в /authorize, и verifier настоящий.
   Иначе обмен отобьётся проверкой адреса — вторым замком, — и проверка
   окажется зелёной даже со снятой привязкой кода к порталу. Так и вышло:
   мутация внесла дефект, а эта строка его не заметила. */
$t = $exchange(['grant_type' => 'authorization_code', 'code' => $g['code'],
    'redirect_uri' => 'https://probe.example/cb', 'code_verifier' => $verifier,
    'client_id' => $clientId2, 'client_secret' => $secret2]);
ok(!isset($t['access_token']), 'чужой портал не может обменять этот код', json_encode($t));

echo "\n── Адрес возврата подменён при обмене ──\n";
$g = $grab([]);
$t = $exchange(['grant_type' => 'authorization_code', 'code' => $g['code'],
    'redirect_uri' => 'https://probe.example/cb2', 'code_verifier' => $verifier,
    'client_id' => $clientId, 'client_secret' => $secret]);
ok(!isset($t['access_token']),
   'redirect_uri при обмене обязан совпасть с тем, что был в /authorize', json_encode($t));

echo "\n── Отказ от PKCE ──\n";
/* Без code_challenge код выдавать нельзя: иначе перехваченный код обменивает
   кто угодно, и весь смысл S256 отменяется одним отсутствующим параметром. */
$noPkce = SSO . '/authorize?' . http_build_query([
    'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => 'https://probe.example/cb',
    'scope' => 'openid profile', 'state' => 'st', 'nonce' => 'no']);
[, , $locNoPkce] = http('GET', $noPkce);
ok(!str_contains((string)$locNoPkce, 'code='), 'без code_challenge код не выдаётся',
   substr((string)$locNoPkce, 0, 90));

echo "\n── Неявные потоки ──\n";
/* Токен в адресной строке — это токен в истории браузера, в логах прокси и в
   Referer. Мы их не поддерживаем, и «не поддерживаем» обязано означать «не
   отдаём», а не «не описали в документации». */
foreach (['token', 'id_token', 'id_token token', 'code token'] as $rt) {
    $g = $grab(['response_type' => $rt]);
    ok(!str_contains($g['loc'], 'access_token=') && !str_contains($g['loc'], 'id_token='),
       "response_type=$rt не отдаёт токен в адресной строке", substr($g['loc'], 0, 90));
}

echo "\n── Права, которых порталу не давали ──\n";
$verifier2 = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$challenge2 = rtrim(strtr(base64_encode(hash('sha256', $verifier2, true)), '+/', '-_'), '=');
[, , $locNarrow] = http('GET', SSO . '/authorize?' . http_build_query([
    'response_type' => 'code', 'client_id' => $clientId2, 'redirect_uri' => 'https://probe2.example/cb',
    'scope' => 'openid profile email roles offline_access', 'state' => 'st', 'nonce' => 'no',
    'code_challenge' => $challenge2, 'code_challenge_method' => 'S256']));
parse_str((string)parse_url((string)$locNarrow, PHP_URL_QUERY), $qNarrow);
ok(!empty($qNarrow['code']), 'узкий портал получил код', substr((string)$locNarrow, 0, 90));
$tNarrow = $exchange(['grant_type' => 'authorization_code', 'code' => (string)($qNarrow['code'] ?? ''),
    'redirect_uri' => 'https://probe2.example/cb', 'code_verifier' => $verifier2,
    'client_id' => $clientId2, 'client_secret' => $secret2]);
ok(!isset($tNarrow['refresh_token']), 'offline_access, которого порталу не разрешали, не выдан',
   'ключи: ' . implode(', ', array_keys($tNarrow)));
$cl = isset($tNarrow['id_token'])
    ? json_decode((string)base64_decode(strtr(explode('.', $tNarrow['id_token'])[1], '-_', '+/')), true)
    : [];
ok(!isset($cl['roles']), 'и roles, которых порталу не разрешали, в id_token нет',
   'поля: ' . implode(', ', array_keys((array)$cl)));

echo "\n── Срок жизни кода ──\n";
/* Код — предъявитель. Час жизни означает час, в течение которого перехваченная
   ссылка остаётся ключом от аккаунта. */
$grab([]);
$codeRow = DB::row('SELECT expires_at FROM {auth_codes} ORDER BY id DESC LIMIT 1');
$ttl = $codeRow ? strtotime((string)$codeRow['expires_at'] . ' UTC') - time() : -1;
ok($ttl > 0 && $ttl <= 600, "код живёт не дольше десяти минут (сейчас $ttl с)");

echo "\n── Человек заблокирован ──\n";
App\Models\User::update($probeUid, ['status' => 'blocked']);
$gBlocked = $grab([]);
ok($gBlocked['code'] === '', 'заблокированному код не выдаётся', substr($gBlocked['loc'], 0, 90));
App\Models\User::update($probeUid, ['status' => 'active']);

DB::run('DELETE FROM {clients} WHERE slug = ?', ['probe2']);
DB::run('DELETE FROM {clients} WHERE slug = ?', ['probe']);
DB::run('DELETE FROM {user_roles} WHERE user_id = ?', [$probeUid]);
DB::run('DELETE FROM {users} WHERE id = ?', [$probeUid]);
echo "\nитог: $passed прошло, $failed провалено\n";
exit($failed === 0 ? 0 : 1);
