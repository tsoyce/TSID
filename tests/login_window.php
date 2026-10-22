<?php
/**
 * Окно входа без лишних окон (v4.87).
 *
 * Владелец 26.09 входил в Портфолио через Telegram из окна поверх сайта и насчитал
 * три окна: вкладка портала, окно SSO со страницей-виджетом и окно, которое
 * открывает сам виджет. И спросил, почему в окне входа «● SSO», а не знак,
 * как в основной форме. Здесь проверяется, что:
 *   · Telegram открывается переходом в том же окне, а возврат (#tgAuthResult)
 *     доходит до обработчика; state сверяется, подделка и чужой вход — нет;
 *   · без номера бота остаётся страница с виджетом;
 *   · щелчок по сервису во фрейме уводит вкладку портала прямо на сервис;
 *   · в окне и во фрейме — знак, как в основной форме, без карточки в
 *     карточке, и окно по высоте содержимого.
 *
 * Проверка повторяемая: включает Telegram с пробным токеном и всё возвращает.
 *
 * Запуск: php tests/login_window.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\Setting;
use App\Core\View;
use App\Social\TelegramProvider;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$JAR = sys_get_temp_dir() . '/login-window.txt';
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);

$keys = ['social.telegram.enabled', 'social.telegram.client_id', 'social.telegram.secret', 'appearance.method_social'];
$was = [];
foreach ($keys as $k) $was[$k] = Setting::get($k);
$token = '123456789:AAH' . str_repeat('x', 32);
$useToken = static function (string $secret): void {
    Setting::set('social.telegram.enabled', '1');
    Setting::set('social.telegram.client_id', 'probe_bot');
    Setting::set('social.telegram.secret', $secret);
    Setting::set('appearance.method_social', '1');
    Setting::flush();
};
/* Данные так, как их подписывает Telegram: поля кроме hash, по алфавиту, «k=v» через \n. */
$signed = static function (array $data, string $secret): array {
    $pairs = [];
    foreach ($data as $k => $v) $pairs[] = "$k=$v";
    sort($pairs);
    $data['hash'] = hash_hmac('sha256', implode("\n", $pairs), hash('sha256', $secret, true));
    return $data;
};
$start = static function () use (&$JAR): array {
    @unlink($JAR);
    [$st, , $loc] = http('GET', SSO . '/social/telegram/start');
    parse_str((string)parse_url((string)$loc, PHP_URL_QUERY), $q);
    parse_str((string)parse_url((string)($q['return_to'] ?? ''), PHP_URL_QUERY), $back);
    return [$st, (string)$loc, $q, (string)($back['state'] ?? '')];
};
$path = static fn(string $loc): string => (string)preg_replace('~^' . preg_quote(SSO, '~') . '~', '', $loc);

try {
    DB::run('DELETE FROM {rate_limits}');

    echo "── Telegram переходом, в том же окне ──\n";
    $useToken($token);
    ok((new TelegramProvider())->botId() === '123456789', 'номер бота — начало его токена');
    [$st, $loc, $q, $state] = $start();
    ok($st === 302 && str_starts_with($loc, 'https://oauth.telegram.org/auth?'), 'старт ведёт прямо на страницу Telegram, без страницы с виджетом', "$st $loc");
    ok(($q['bot_id'] ?? '') === '123456789' && ($q['origin'] ?? '') === SSO && ($q['request_access'] ?? '') === 'write'
        && str_starts_with((string)($q['return_to'] ?? ''), SSO . '/social/telegram/return?state=') && strlen($state) >= 16,
        'бот, адрес установки и возврат со state — как у виджета, только без окна', json_encode($q));
    [$st, $page] = http('GET', SSO . '/social/telegram/return?state=' . urlencode($state));
    ok($st === 200 && str_contains($page, '<div data-tg-return>') && str_contains($page, 'data-tg-fail hidden'),
        'страница возврата ждёт данные во фрагменте; без них — подсказка, а не пустота');

    $data = $signed(['id' => '424242', 'first_name' => 'Проба', 'username' => 'probe_user', 'auth_date' => (string)time()], $token);
    [$st, , $loc] = http('GET', SSO . '/social/telegram/callback?' . http_build_query($data + ['state' => $state]));
    ok($st === 302 && str_starts_with($path($loc), '/social/signup'), 'подписанный ответ со своим state — вход идёт дальше (новому человеку — «Создать аккаунт?»)', "$st $loc");
    [$st, , $loc] = http('GET', SSO . '/social/telegram/callback?' . http_build_query($data + ['state' => $state]));
    ok(str_starts_with($path($loc), '/login'), 'тот же state второй раз не проходит');

    [, , , $state] = $start();
    [, , $loc] = http('GET', SSO . '/social/telegram/callback?' . http_build_query($data));
    ok(str_starts_with($path($loc), '/login'), 'без state — нет: чужую подписанную ссылку не подсунуть');
    [, , , $state] = $start();
    [, , $loc] = http('GET', SSO . '/social/telegram/callback?' . http_build_query(['first_name' => 'Чужой'] + $data + ['state' => $state]));
    ok(str_starts_with($path($loc), '/login'), 'поле, изменённое после подписи, — нет');

    $useToken('123456789');
    ok((new TelegramProvider())->botId() === '', 'одни цифры — не токен: номер бота из них не берётся');
    $useToken('не-токен');
    ok((new TelegramProvider())->botId() === '', 'токен не того вида — номера бота нет');
    @unlink($JAR);
    [$st, $page] = http('GET', SSO . '/social/telegram/start');
    ok($st === 200 && str_contains($page, 'telegram-widget.js'), 'тогда — страница с виджетом, как до 4.87');

    $js = $src('public/assets/js/app.js');
    ok(str_contains($js, "var tgMatch = location.hash.match(/[#?&]tgAuthResult=([A-Za-z0-9\\-_=]*)\$/), tgData = null;")
        && str_contains($js, 'tgData = JSON.parse(new TextDecoder().decode(tgBytes));')
        && str_contains($js, "tgQuery.set('state', new URLSearchParams(location.search).get('state') || '');")
        && str_contains($js, "location.replace('/social/telegram/callback?' + tgQuery.toString());"),
        'скрипт разбирает #tgAuthResult (base64url, имена по-русски — UTF-8) и несёт state дальше');

    echo "\n── Из фрейма — вкладкой, а не окном ──\n";
    $sso = $src('public/assets/js/sso.js');
    ok(str_contains($sso, "if (typeof d.social === 'string' && /^[a-z]{2,20}\$/.test(d.social)) toService(d.social);\n      else toWindow();"),
        'сервис, выбранный во фрейме, — вкладка уходит на него; отдельное окно — только для остального');
    ok(str_contains($sso, "location.href = url + (url.indexOf('?') === -1 ? '?' : '&') + 'idp_hint=' + hint;")
        && !str_contains($sso, 'function toWindow(hint)'), 'с подсказкой сервиса, а окно подсказок больше не берёт');

    echo "\n── Окно и фрейм — как основная форма ──\n";
    $compact = View::fetch('layouts/auth', ['content' => '<p>форма</p>', 'popup' => true, 'pageTitle' => 'Вход']);
    ok((bool)preg_match('~<main id="main" class="auth-card">\s*<div class="auth-brandline"><span class="mark">[^<]+</span>\s*<span class="wordmark-name">~u', $compact)
        && !str_contains($compact, 'logo-mark'), 'в окне входа знак и название, как над основной формой, а не «● Название»');
    $withPortal = View::fetch('layouts/auth', ['content' => '<p>форма</p>', 'popup' => true, 'pageTitle' => 'Вход',
        'client' => ['id' => 1, 'name' => 'Портал', 'look_mode' => 'off']]);
    ok(!str_contains($withPortal, 'auth-brandline'), 'пришёл с портала — знака нет, вместо него коннектор портала, как в основной форме');
    $css = $src('public/assets/css/identity.css');
    ok(str_contains($css, '.auth-shell.is-popup > .auth-card { background: transparent; border: 0; box-shadow: none; padding: 4px; }'),
        'без карточки в карточке: окно само и есть карточка');
    /* 4.88: владелец заметил, что в окне знак по центру, а над основной формой — слева. */
    ok(str_contains($css, '.auth-shell.is-popup .auth-brandline { margin-bottom: var(--s-6); }')
        && !preg_match('~\.is-popup \.auth-brandline \{[^}]*justify-content: center~', $css),
        'знак в окне — слева, как над основной формой');
    ok(str_contains($css, 'html[data-frame-origin] body, html[data-frame-origin] .auth-wrap { min-height: 0; }')
        && str_contains($js, 'toPortal({ state: \'size\', h: frameWrap ? Math.ceil(frameWrap.getBoundingClientRect().height)')
        && str_contains($sso, 'Math.min(Math.max(d.h, 360), innerHeight - 32)'),
        'окно по высоте содержимого — и растёт, и сжимается');
} finally {
    foreach ($was as $k => $v) $v === null ? Setting::delete($k) : Setting::set($k, (string)$v);
    Setting::flush();
    @unlink($JAR);
}

echo "\n" . ($failed === 0 ? "✓ все $passed проверок" : "✗ провалов: $failed из " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
