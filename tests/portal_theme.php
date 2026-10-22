<?php
/**
 * Экран входа в оформлении портала (v4.65).
 *
 * Владелец захотел, чтобы вход в Портфолио выглядел как Портфолио. Форма остаётся на SSO —
 * пароль видит только он, — а портал присылает в манифесте параметры: цвета
 * обеих тем, скругление, шрифт из нашего списка, цитату для левой панели.
 * CSS собираем мы. Здесь проверяется, что:
 *   · тема Портфолио (живые значения из его style.css) принимается и собирается;
 *   · контраст текста, ссылок и рамок гарантирован на ЕГО фоне и карточке;
 *   · в CSS нет ни одной строки от портала — только hex, числа и наши стеки;
 *   · непригодная тема не применяется целиком, а причина названа;
 *   · подделка прямо в базе не проходит — разбор повторяется на показе;
 *   · цитата — только текст, знак SSO остаётся на панели;
 *   · тема видна на настоящем экране входа из потока /authorize и на соседних
 *     экранах того же потока, а без портала экран родной.
 *
 * Проверка повторяемая: заводит свой портал и убирает его за собой.
 *
 * Запуск: php tests/portal_theme.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Bridge;
use App\Core\DB;
use App\Core\Palette;
use App\Models\Client;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$JAR = sys_get_temp_dir() . '/portal-theme.txt';
@unlink($JAR);

DB::run('DELETE FROM {rate_limits}');
$slug = 'theme-probe';
$old = DB::row('SELECT id FROM {clients} WHERE slug = ?', [$slug]);
if ($old) Client::purge((int)$old['id']);
[$id, $clientId] = Client::create([
    'name' => 'Проба темы', 'slug' => $slug, 'base_url' => 'https://' . $slug . '.example',
    'redirect_uris' => ['https://' . $slug . '.example/cb'],
    'post_logout_uris' => ['https://' . $slug . '.example/'],
    'backchannel_uri' => null, 'is_trusted' => 1, 'access_policy' => 'open',
]);
$id = (int)$id;
/* Здесь проверяется разбор темы портала и её вид на входе — при «Портал
   главнее». По умолчанию с 4.85 главнее дизайн сайта, и шрифты Портфолио на входе
   не встают нарочно (это проверяет look_builder). */
$policyWas = App\Core\Setting::get('appearance.portal_look');
App\Core\Setting::set('appearance.portal_look', 'portal');
App\Core\Setting::flush();

/** Живые значения Портфолио (style.css, :root и тёмная тема) — 12.09. */
$cv = [
    'accent' => '#A53718', 'accent_dark' => '#FF8054',
    'background' => '#F1ECDC', 'background_dark' => '#16171A',
    'surface' => '#FFFFFF', 'surface_dark' => '#1E1F23',
    'text' => '#0E0B07', 'text_dark' => '#F4F4F0',
    'radius' => 6, 'font' => 'inter', 'font_display' => 'garamond', 'wash' => true,
    'headline' => 'Резюме — это разговор <script>alert(1)</script> с тем, кого вы ещё не встретили.',
    'headline_note' => '— resume builder, 2026',
];

function authorizeUrl(string $clientId, string $redirect): string
{
    $v = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    return SSO . '/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect,
        'scope' => 'openid profile', 'state' => 'st', 'nonce' => 'no',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ]);
}

try {
    echo "── Тема Портфолио принимается и собирается ──\n";
    Bridge::storeBranding(Client::find($id), $cv);
    $row = Client::find($id);
    $th = Bridge::themeOf($row);
    ok($th !== null && $th['light'] !== null && $th['dark'] !== null && $th['why'] === [],
        'обе темы Портфолио применяются', json_encode($th['why'] ?? null, JSON_UNESCAPED_UNICODE));
    $css = Bridge::themeStyle($row);
    ok(str_contains($css, '--bg:#F1ECDC') && str_contains($css, ':root[data-theme="dark"]{--bg:#16171A'),
        'в стиле фон обеих тем Портфолио', substr($css, 0, 160));
    ok(str_contains($css, "--font-ui:'Inter'") && str_contains($css, "--font-display:'EB Garamond'")
        && str_contains($css, '--radius-s:6px'), 'шрифты — нашими стеками, скругление — числом');

    echo "\n── Контраст — на фоне и карточке портала ──\n";
    foreach (['light' => 'светлая', 'dark' => 'тёмная'] as $k => $name) {
        $s = $th[$k];
        $grounds = [$s['bg'], $s['surface'], $s['surface-2']];
        $worst = static fn(string $c): float => min(array_map(static fn($g) => Palette::contrast($c, $g), $grounds));
        ok($worst($s['text-2']) >= 4.5 && $worst($s['text-3']) >= 4.5,
            "$name: приглушённый текст не ниже 4.5 на всех подложках",
            sprintf('%.2f / %.2f', $worst($s['text-2']), $worst($s['text-3'])));
        ok($worst($s['ink-text']) >= 4.5, "$name: ссылка акцентом не ниже 4.5", sprintf('%.2f', $worst($s['ink-text'])));
        ok($worst($s['control-line']) >= 3.0, "$name: рамка поля не ниже 3", sprintf('%.2f', $worst($s['control-line'])));
    }

    echo "\n── В CSS нет строк от портала ──\n";
    ok(preg_match('~^<style>[-\w\s#:;{}()\[\]="\',.%]*</style>$~u', $css) === 1
        && !str_contains($css, 'url(') && !str_contains($css, '@import') && !str_contains($css, '<script'),
        'стиль состоит из hex, чисел и наших стеков шрифтов');

    echo "\n── Непригодная тема не применяется, причина названа ──\n";
    Bridge::storeBranding(Client::find($id), ['background' => '#9A9A9A', 'surface' => '#A0A0A0', 'text' => '#7A7A7A']);
    $th = Bridge::themeOf(Client::find($id));
    ok($th !== null && $th['light'] === null && str_contains(implode(' ', $th['why']), '4.5'),
        'серое на сером — светлая тема не применяется, контраст назван', json_encode($th['why'] ?? null, JSON_UNESCAPED_UNICODE));
    ok(!str_contains(Bridge::themeStyle(Client::find($id)), '--bg:'), 'и в стиль она не попадает');

    Bridge::storeBranding(Client::find($id), ['background_dark' => '#FFFFFF', 'surface_dark' => '#F5F5F5', 'text_dark' => '#111111']);
    $th = Bridge::themeOf(Client::find($id));
    ok($th !== null && $th['dark'] === null, 'светлая палитра под видом тёмной не применяется');

    Bridge::storeBranding(Client::find($id), ['background' => '#F1ECDC']);
    $th = Bridge::themeOf(Client::find($id));
    ok($th !== null && $th['light'] === null && $th['why'] !== [], 'фон без текста и карточки — не применяется, причина названа');

    Bridge::storeBranding(Client::find($id), [
        'background' => '#FFFFFF', 'surface' => '#FFFFFF', 'text' => '#111111',
        'font' => 'https://evil.example/font.woff2', 'font_display' => 'garamond;}body{display:none}',
        'radius' => 99, 'accent' => '#12345G', 'wash' => 'yes', 'headline' => ['x'],
    ]);
    $th = Bridge::themeOf(Client::find($id));
    ok($th !== null && $th['font'] === null && $th['font_display'] === null && $th['radius'] === null
        && $th['wash'] === null && $th['headline'] === null,
        'шрифт адресом, довесок CSS, скругление 99, «yes» и массив вместо текста — отброшены');
    Bridge::storeBranding(Client::find($id), ['background' => '#FFFFFF', 'surface' => '#FFFFFF', 'text' => '#111111', 'radius' => '6']);
    ok(Bridge::themeOf(Client::find($id))['radius'] === null, 'скругление строкой не принимается — только целое');

    echo "\n── Подделка прямо в базе ──\n";
    DB::update('clients', ['brand_theme' => json_encode([
        'background' => '#FFFFFF;}body{display:none', 'surface' => '#FFFFFF', 'text' => '#111111',
        'font' => "inter';}body{display:none}",
    ])], 'id = ?', [$id]);
    $forged = Bridge::themeStyle(Client::find($id));
    ok(!str_contains($forged, 'display:none') && !str_contains($forged, 'body'),
        'значения из базы разбираются заново при показе', $forged);

    echo "\n── Панель портала ──\n";
    Bridge::storeBranding(Client::find($id), $cv);
    $pane = auth_media_pane(Client::find($id));
    ok(str_contains($pane, 'portal-pane-quote') && str_contains($pane, '&lt;script&gt;')
        && !str_contains($pane, '<script>alert'), 'цитата портала — только текст');
    ok(str_contains($pane, 'portal-pane-foot') && (str_contains($pane, 'class="mark') || str_contains($pane, '<img')),
        'знак SSO остаётся на панели портала');
    ok(!str_contains(auth_media_pane(null), 'portal-pane'), 'без портала панель — владельца установки');

    echo "\n── Настоящий экран входа ──\n";
    DB::run('DELETE FROM {rate_limits}');
    [$st, , $loc] = http('GET', authorizeUrl((string)$clientId, 'https://' . $slug . '.example/cb'));
    ok($st === 302 && str_contains((string)$loc, '/login'), '/authorize ведёт гостя на вход', "st=$st loc=$loc");
    $loginUrl = str_starts_with((string)$loc, 'http') ? (string)$loc : SSO . $loc;
    [, $login] = http('GET', $loginUrl);
    ok(str_contains($login, '--bg:#F1ECDC') && str_contains($login, 'portal-pane-quote'),
        'на входе из портала — его тема и его панель');
    ok(str_contains($login, 'connector-node'), 'связка «портал — SSO» над формой на месте');
    [, $register] = http('GET', SSO . '/register');
    ok(str_contains($register, '--bg:#F1ECDC'), 'регистрация в том же потоке — в той же теме');
    /* «Не помню» портал в шаблон не передаёт вовсе — только на нём и видно,
       что тема берётся из потока. Регистрация передаёт сама, и проверка по
       ней была слепа к этому дефекту (мутация 49, 12.09). */
    [, $recover] = http('GET', SSO . '/recover');
    ok(str_contains($recover, '--bg:#F1ECDC'),
        '«не помню» в том же потоке — в той же теме: портал берётся из потока, а не из экрана');
    [, $plain, , , $plainHeaders] = http('GET', SSO . '/login', [], [], sys_get_temp_dir() . '/portal-theme-plain.txt');
    ok(!str_contains($plain, '--bg:#F1ECDC') && !str_contains($plain, 'portal-pane'),
        'без портала экран входа — родной');
    /* Прогон 12.09 (v4.67): каждый ответ боевой нёс «X-Powered-By: PHP/8.4.17». */
    ok(!preg_match('~^X-Powered-By:~mi', (string)$plainHeaders), 'версия PHP наружу не отдаётся');

    echo "\n── Портал отказался от темы ──\n";
    Bridge::storeBranding(Client::find($id), ['accent' => '#E4572E']);
    ok(DB::val('SELECT brand_theme FROM {clients} WHERE id = ?', [$id]) === null,
        'тему, которой больше нет в манифесте, не держим');
} finally {
    $policyWas === null ? App\Core\Setting::delete('appearance.portal_look') : App\Core\Setting::set('appearance.portal_look', $policyWas);
    App\Core\Setting::flush();
    Client::purge($id);
    @unlink($JAR);
    @unlink(sys_get_temp_dir() . '/portal-theme-plain.txt');
}

echo "\n══ Итог ══\nПройдено: $passed, провалено: $failed\n";
exit($failed > 0 ? 1 : 0);
