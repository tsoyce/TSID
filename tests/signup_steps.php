<?php
/**
 * Регистрация по шагам (v4.96): почта → код → имя и пароль.
 *
 * Было: одна форма, аккаунт заводился сразу, почту подтверждали потом. Боты
 * заполняли форму чужими адресами, и в базе копились аккаунты, которых никто не
 * подтвердит. Владелец просил: сначала почта, потом код или ссылка из письма,
 * и только потом имя и аккаунт. Здесь проверяется, что:
 *   · первый шаг — одна почта; аккаунта нет ни после него, ни после неверного кода;
 *   · верный код ведёт к имени и паролю, аккаунт рождается подтверждённым;
 *   · ссылка из письма продолжает регистрацию и в другом браузере — по кнопке, не по GET;
 *   · занятый адрес выглядит так же, а владельцу уходит письмо о попытке;
 *   · «Другой адрес» возвращает к первому шагу с прежним адресом в поле;
 *   · подтверждение выключено — шагов два, аккаунт неподтверждённый, письмо уходит;
 *   · переключатели капчи «при регистрации» и «при восстановлении» работают;
 *   · приглашение доживает до последнего шага и гаснет на нём.
 *
 * Запуск: php tests/signup_steps.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\Setting;
use App\Models\User;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$JAR = sys_get_temp_dir() . '/signup-steps.txt';
$stamp = bin2hex(random_bytes(3));
$jars = [];
$jar = static function (string $name) use (&$jars): string {
    $p = sys_get_temp_dir() . "/signup-$name.txt";
    @unlink($p);
    $jars[] = $p;
    return $p;
};
$mail = static fn(string $tag): string => "su-$tag-$stamp@example.com";
$codeOf = static fn(string $email): string => preg_match('~Код подтверждения: (\d{6})~u', lastMailTo($email), $m) ? $m[1] : '';
$user = static fn(string $email): ?array => User::findByEmail($email);

$was = [];
foreach (['policy.verify_email' => 'on', 'policy.signup_open' => '1', 'policy.welcome_screen' => '0',
          'captcha.enabled' => '0', 'captcha.on_register' => '0', 'captcha.on_recover' => '0'] as $k => $v) {
    $was[$k] = Setting::get($k);
    Setting::set($k, $v);
}
Setting::flush();
DB::run('DELETE FROM {rate_limits}');

try {
    echo "── Шаг 1: только почта ──\n";
    $j = $jar('code');
    [$st, $p] = http('GET', SSO . '/register', [], [], $j);
    ok($st === 200 && str_contains($p, 'name="email"') && !str_contains($p, 'name="password"') && !str_contains($p, 'name="first_name"'),
        'первый шаг — одна почта, без имени и пароля');
    ok(str_contains($p, 'Шаг 1 из 3'), 'номер шага виден: «Шаг 1 из 3»');
    $email = $mail('code');
    [$st, , $loc] = http('POST', SSO . '/register', ['_csrf' => csrfOf($p), 'email' => $email], [], $j);
    ok($st === 302 && str_ends_with($loc, '/register') && $user($email) === null, 'после почты аккаунта нет', "$st $loc");
    $code = $codeOf($email);
    ok($code !== '' && str_contains(lastMailTo($email), '/register/continue/'), 'письмо: код и ссылка продолжения');

    echo "\n── Шаг 2: код ──\n";
    [, $p] = http('GET', SSO . '/register', [], [], $j);
    ok(str_contains($p, 'action="/register/code"') && str_contains($p, esc($email)) && str_contains($p, 'Шаг 2 из 3'),
        'экран кода называет адрес');
    http('POST', SSO . '/register/code', ['_csrf' => csrfOf($p), 'code' => $code === '000000' ? '111111' : '000000'], [], $j);
    [, $p] = http('GET', SSO . '/register', [], [], $j);
    ok(str_contains($p, 'action="/register/code"') && str_contains($p, 'Код не подошёл') && $user($email) === null,
        'неверный код — тот же шаг, аккаунта нет');
    http('POST', SSO . '/register/code', ['_csrf' => csrfOf($p), 'code' => $code], [], $j);
    [, $p] = http('GET', SSO . '/register', [], [], $j);
    ok(str_contains($p, 'action="/register/finish"') && str_contains($p, 'Шаг 3 из 3') && $user($email) === null,
        'верный код — имя и пароль; аккаунта всё ещё нет');
    ok(str_contains($p, 'autocomplete="username"') && str_contains($p, 'value="' . esc($email) . '"'),
        'почта уходит менеджеру паролей вместе с паролем');
    /* Снимок 390: длинный адрес распирал колонку входа до 448px. */
    ok(str_contains((string)file_get_contents(SSO_ROOT . '/public/assets/css/app.css'), "  contain: inline-size; padding: 4px 4px 4px 12px;"),
        'длинный адрес не распирает экран телефона: плашка своей ширины не заявляет');

    echo "\n── Шаг 3: имя и пароль ──\n";
    [$st, $bad] = http('POST', SSO . '/register/finish', ['_csrf' => csrfOf($p), 'first_name' => '', 'password' => 'very-long-password-1'], [], $j);
    ok($st === 200 && str_contains($bad, 'Как к вам обращаться?') && $user($email) === null, 'без имени — ошибка у поля, аккаунта нет');
    preg_match_all('~name="accept\[\]" value="([^"]+)"~', $p, $am);
    [$st, , $loc] = http('POST', SSO . '/register/finish', ['_csrf' => csrfOf($p), 'first_name' => 'Проба', 'last_name' => 'Шагов',
        'password' => 'very-long-password-1', 'accept' => $am[1] ?? []], [], $j);
    $u = $user($email);
    ok($st === 302 && $u !== null, 'аккаунт рождается последним шагом', "$st $loc");
    ok($u !== null && $u['email_verified_at'] !== null && $u['status'] === 'active' && (int)($u['email_gate'] ?? 0) === 0,
        'и сразу с подтверждённой почтой — ждать нечего');
    [$st] = http('GET', SSO . '/profile', [], [], $j);
    ok($st === 200, 'и вошёл', "status $st");

    echo "\n── Ссылка из письма — в другом браузере ──\n";
    DB::run('DELETE FROM {rate_limits}');
    $j1 = $jar('link1');
    $email = $mail('link');
    [, $p] = http('GET', SSO . '/register', [], [], $j1);
    http('POST', SSO . '/register', ['_csrf' => csrfOf($p), 'email' => $email], [], $j1);
    $token = preg_match('~/register/continue/([A-Za-z0-9_\-]{20,})~', lastMailTo($email), $m) ? $m[1] : '';
    $j2 = $jar('link2');
    [$st, $confirm] = http('GET', SSO . '/register/continue/' . $token, [], [], $j2);
    [, $p2] = http('GET', SSO . '/register', [], [], $j2);
    ok($st === 200 && $token !== '' && !str_contains($p2, 'action="/register/finish"'), 'открыть ссылку мало — нужна кнопка', "status $st");
    http('POST', SSO . '/register/continue/' . $token, ['_csrf' => csrfOf($confirm)], [], $j2);
    [, $p2] = http('GET', SSO . '/register', [], [], $j2);
    ok(str_contains($p2, 'action="/register/finish"') && str_contains($p2, esc($email)), 'по кнопке — имя и пароль, в этом браузере');
    [$st] = http('POST', SSO . '/register/continue/' . $token, ['_csrf' => csrfOf($confirm)], [], $jar('link3'));
    [, $p3] = http('GET', SSO . '/register', [], [], sys_get_temp_dir() . '/signup-link3.txt');
    ok(!str_contains($p3, 'action="/register/finish"'), 'ссылка одноразовая');
    preg_match_all('~name="accept\[\]" value="([^"]+)"~', $p2, $am);
    http('POST', SSO . '/register/finish', ['_csrf' => csrfOf($p2), 'first_name' => 'Ссылка',
        'password' => 'very-long-password-1', 'accept' => $am[1] ?? []], [], $j2);
    ok(($user($email)['email_verified_at'] ?? null) !== null, 'аккаунт подтверждён ссылкой');

    echo "\n── Занятый адрес выглядит так же ──\n";
    DB::run('DELETE FROM {rate_limits}');
    $j = $jar('taken');
    [, $p] = http('GET', SSO . '/register', [], [], $j);
    [$st, , $loc] = http('POST', SSO . '/register', ['_csrf' => csrfOf($p), 'email' => $email], [], $j);
    [, $p] = http('GET', SSO . '/register', [], [], $j);
    ok($st === 302 && str_contains($p, 'action="/register/code"'), 'занятый адрес — тот же экран кода: не проверялка «есть ли такой»');
    ok(str_contains(lastMailTo($email), 'Попытка регистрации') && $codeOf($email) === '', 'владельцу — письмо о попытке, кода нет');

    echo "\n── «Другой адрес» ──\n";
    DB::run('DELETE FROM {rate_limits}');
    $j = $jar('retry');
    $email = $mail('typo');
    [, $p] = http('GET', SSO . '/register', [], [], $j);
    http('POST', SSO . '/register', ['_csrf' => csrfOf($p), 'email' => $email], [], $j);
    [, $p] = http('GET', SSO . '/register', [], [], $j);
    http('POST', SSO . '/register/restart', ['_csrf' => csrfOf($p)], [], $j);
    [, $p] = http('GET', SSO . '/register', [], [], $j);
    ok(str_contains($p, 'action="/register"') && str_contains($p, 'value="' . esc($email) . '"') && !str_contains($p, 'action="/register/code"'),
        'назад к почте, прежний адрес — в поле, поправить опечатку');

    echo "\n── Подтверждение выключено — шагов два ──\n";
    Setting::set('policy.verify_email', 'off');
    Setting::flush();
    DB::run('DELETE FROM {rate_limits}');
    $j = $jar('off');
    $email = $mail('off');
    [, $p] = http('GET', SSO . '/register', [], [], $j);
    ok(str_contains($p, 'Шаг 1 из 2'), '«Шаг 1 из 2»');
    [$st] = signup(SSO, $email, ['first_name' => 'Без кода'], $j);
    $u = $user($email);
    ok($u !== null && $u['email_verified_at'] === null && $u['status'] === 'pending', 'аккаунт есть, почта ещё не подтверждена', "status $st");
    ok(str_contains(lastMailTo($email), '/verify/'), 'письмо со ссылкой подтверждения ушло, как раньше');
    Setting::set('policy.verify_email', 'on');
    Setting::flush();

    echo "\n── Капча при регистрации и восстановлении ──\n";
    foreach (['captcha.enabled' => '1', 'captcha.on_register' => '1', 'captcha.on_recover' => '1', 'captcha.provider' => 'own'] as $k => $v) {
        $was[$k] ??= Setting::get($k);
        Setting::set($k, $v);
    }
    Setting::flush();
    DB::run('DELETE FROM {rate_limits}');
    $j = $jar('captcha');
    [, $p] = http('GET', SSO . '/register', [], [], $j);
    ok(str_contains($p, 'name="captcha"') && str_contains($p, 'так владелец сервиса настроил регистрацию'),
        'включена «при регистрации» — проверка на первом шаге, со своим объяснением');
    $email = $mail('captcha');
    http('POST', SSO . '/register', ['_csrf' => csrfOf($p), 'email' => $email, 'captcha' => '-1'], [], $j);
    [, $p] = http('GET', SSO . '/register', [], [], $j);
    ok(!str_contains($p, 'action="/register/code"') && lastMailTo($email) === '', 'без ответа — дальше не пускает, письма нет');
    [, $rp] = http('GET', SSO . '/recover', [], [], $j);
    ok(str_contains($rp, 'name="captcha"'), 'включена «при восстановлении» — проверка на экране восстановления');
    $before = count((array)glob(SSO_ROOT . '/storage/logs/mail/*.eml'));
    http('POST', SSO . '/recover', ['_csrf' => csrfOf($rp), 'email' => 'admin@test.local', 'captcha' => '-1'], [], $j);
    ok(count((array)glob(SSO_ROOT . '/storage/logs/mail/*.eml')) === $before, 'без ответа письмо восстановления не уходит');
    foreach (['captcha.enabled' => '0', 'captcha.on_register' => '0', 'captcha.on_recover' => '0'] as $k => $v) Setting::set($k, $v);
    Setting::flush();

    echo "\n── Приглашение доживает до последнего шага ──\n";
    Setting::set('policy.signup_open', '0');
    Setting::flush();
    DB::run('DELETE FROM {rate_limits}');
    $email = $mail('invite');
    $inv = \App\Core\Signup::issueInvite($email, null);
    [$st, , $id] = signup(SSO, $email, ['invite' => $inv['token'], 'first_name' => 'Званый'], $jar('invite'));
    ok($id > 0, 'по приглашению — через все три шага', "status $st");
    ok(\App\Models\OneTimeToken::peek($inv['token'], \App\Core\Signup::INVITE) === null, 'и оно погашено последним шагом');
    [, $closed] = http('GET', SSO . '/register', [], [], $jar('closed'));
    ok(str_contains($closed, 'Регистрация закрыта'), 'без приглашения — закрыто');
} finally {
    foreach (DB::all('SELECT id FROM {users} WHERE email LIKE ?', ["su-%-$stamp@example.com"]) as $r) User::purge((int)$r['id']);
    foreach ($was as $k => $v) Setting::set($k, $v);
    Setting::flush();
    foreach ($jars as $p) @unlink($p);
    @unlink(sys_get_temp_dir() . '/signup-link3.txt');
}

echo "\n══ Итог ══\nПройдено: $passed, провалено: $failed\n";
exit($failed > 0 ? 1 : 0);
