<?php
/**
 * Кнопки сервисов входа (v4.86).
 *
 * Владелец 26.09 (скриншоты Claude, ChatGPT и X): «сделай по красоте кнопки
 * для авторизации через соцсети с логотипами, красивым правильным текстом».
 * Здесь проверяется, что:
 *   · у каждого сервиса есть свой знак в спрайте, и кнопка рисует его;
 *   · подпись — «Продолжить с …» (той же кнопкой и заводят аккаунт), по-английски
 *     — «Continue with …»; при четырёх сервисах — два столбца и одно имя;
 *   · idp_hint=yandex на /authorize ведёт сразу на сервис — только включённый,
 *     только если портал его показывает и не во фрейме;
 *   · окно входа, открытое из фрейма щелчком по сервису, открывается сразу на нём.
 *
 * Проверка повторяемая: включает сервисы с пробными ключами, заводит свой
 * портал и всё возвращает за собой.
 *
 * Запуск: php tests/social_buttons.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\Lang;
use App\Core\Setting;
use App\Models\Client;
use App\Social\Provider;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$JAR = sys_get_temp_dir() . '/social-buttons.txt';
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);

$ids = array_map(static fn(Provider $p): string => $p->id(), Provider::all());
$was = [];
foreach ($ids as $id) {
    foreach (['enabled', 'client_id', 'secret'] as $k) $was["social.$id.$k"] = Setting::get("social.$id.$k");
}
$was['appearance.method_social'] = Setting::get('appearance.method_social');
$was['appearance.social_first'] = Setting::get('appearance.social_first');
$turn = static function (array $on) use ($ids): void {
    foreach ($ids as $id) {
        $yes = in_array($id, $on, true);
        Setting::set("social.$id.enabled", $yes ? '1' : '0');
        Setting::set("social.$id.client_id", $yes ? "probe-$id" : '');
        Setting::set("social.$id.secret", $yes ? "probe-secret-$id" : '');
    }
    Setting::set('appearance.method_social', '1');
    Setting::flush();
};
$slug = 'social-probe';
$old = DB::row('SELECT id FROM {clients} WHERE slug = ?', [$slug]);
if ($old) Client::purge((int)$old['id']);
[$cid, $clientId] = Client::create([
    'name' => 'Проба сервисов', 'slug' => $slug, 'base_url' => 'https://' . $slug . '.example',
    'redirect_uris' => ['https://' . $slug . '.example/cb'],
    'post_logout_uris' => ['https://' . $slug . '.example/'],
    'backchannel_uri' => null, 'is_trusted' => 1, 'access_policy' => 'open',
]);
$cid = (int)$cid;
$authorize = static function (array $extra) use ($clientId, $slug): string {
    $v = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    @unlink(sys_get_temp_dir() . '/social-buttons-anon.txt');
    [, , $loc] = http('GET', SSO . '/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => 'https://' . $slug . '.example/cb',
        'scope' => 'openid', 'state' => 's', 'nonce' => 'n',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256'] + $extra), [], [], sys_get_temp_dir() . '/social-buttons-anon.txt');
    return (string)preg_replace('~^' . preg_quote(SSO, '~') . '~', '', (string)$loc);
};
$loginPage = static function (): string {
    @unlink(sys_get_temp_dir() . '/social-buttons-anon.txt');
    [, $b] = http('GET', SSO . '/login', [], [], sys_get_temp_dir() . '/social-buttons-anon.txt');
    return $b;
};

try {
    DB::run('DELETE FROM {rate_limits}');

    echo "── Знаки сервисов ──\n";
    $sprite = $src('public/assets/icons.svg');
    $missing = array_filter($ids, static fn(string $id): bool => !str_contains($sprite, '<symbol id="i-brand-' . $id . '" viewBox="0 0 24 24">'));
    ok($missing === [], 'у каждого сервиса свой знак в спрайте', implode(',', $missing));
    ok(str_contains($sprite, 'fill="#2AABEE"') && str_contains($sprite, 'fill="#FC3F1D"') && str_contains($sprite, 'fill="#0077FF"'),
        'Telegram, Яндекс и VK — в фирменных цветах');
    ok((bool)preg_match('~<symbol id="i-brand-github"[^>]*>\s*<path fill="currentColor"~', $sprite),
        'знак GitHub берёт цвет текста — на тёмной теме не пропадает');
    ok((new \App\Social\YandexProvider())->icon() === 'brand-yandex', 'кнопка рисует знак своего сервиса, а не общий «наружу»');

    echo "\n── Два сервиса: во всю ширину, «Продолжить с …» ──\n";
    $turn(['telegram', 'yandex']);
    $page = $loginPage();
    ok(str_contains($page, '<div class="oauth-row">'), 'столбец — по кнопке в строку');
    ok((bool)preg_match('~<a class="oauth-btn" href="/social/yandex/start" data-social="yandex">\s*<svg class="icon" width="20" height="20"[^>]*><use href="[^"]*#i-brand-yandex"></use></svg><span class="oauth-long">Продолжить с Яндекс ID</span><span class="oauth-short">Яндекс ID</span>~u', $page),
        'кнопка Яндекса: знак и «Продолжить с Яндекс ID»');
    ok(str_contains($page, '<span class="oauth-long">Продолжить с Telegram</span>') && str_contains($page, '#i-brand-telegram'), 'и Telegram так же');
    ok(Lang::using('en', static fn(): string => t('Продолжить с :name', ['name' => (new \App\Social\YandexProvider())->title()])) === 'Continue with Yandex ID',
        'по-английски — Continue with Yandex ID');

    echo "\n── Четыре сервиса: два столбца, одно имя ──\n";
    $turn($ids);
    $page = $loginPage();
    ok(str_contains($page, '<div class="oauth-row oauth-row-2">')
        && str_contains($page, '<span class="oauth-long">Продолжить с GitHub</span><span class="oauth-short">GitHub</span>'),
        'у кнопки обе подписи — полная и имя');
    $css = $src('public/assets/css/identity.css');
    ok(str_contains($css, ".oauth-short { display: none; }\n@media (min-width: 480px) {\n  .oauth-row-2 { grid-template-columns: 1fr 1fr; }\n  .oauth-row-2 .oauth-long { display: none; }\n  .oauth-row-2 .oauth-short { display: inline; }"),
        'имя вместо полной — только в два столбца на широком экране; на телефоне и во фрейме столбец один и подпись полная');
    ok(str_contains($css, 'display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; min-width: 0;')
        && str_contains($css, 'height: calc(var(--control-h) + 4px);') && str_contains($css, '.oauth-btn span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }'),
        'знак и подпись по центру, рост от поля; длинное имя не рвёт кнопку');

    echo "\n── «Сервисы — первыми» ──\n";
    $turn(['telegram', 'yandex']);
    Setting::delete('appearance.social_first');
    Setting::flush();
    $page = $loginPage();
    ok(strpos($page, 'class="oauth-row') > strpos($page, '<form method="post" action="/login">') && str_contains($page, 'или через сервис'),
        'по умолчанию сервисы — под формой, с подписью «или через сервис»');
    Setting::set('appearance.social_first', '1');
    Setting::flush();
    $page = $loginPage();
    $row = (int)strpos($page, 'class="oauth-row');
    $form = (int)strpos($page, '<form method="post" action="/login">');
    ok($row > 0 && $row < $form && !str_contains($page, 'или через сервис')
        && (bool)preg_match('~</div>\s*<div class="auth-divider">' . preg_quote(t('login.or'), '~') . '</div>\s*<form method="post" action="/login">~u', $page),
        'включено — кнопки над формой и «или» между ними, как у Google, Apple и X');
    Setting::delete('appearance.social_first');
    Setting::flush();
    \App\Core\Bridge::preview(null, 'off', [], null, null, true);
    ok(\App\Core\Bridge::socialFirst(null) === true && \App\Core\Bridge::socialFirst(['id' => $cid]) === false,
        'предпросмотр «Оформления сайта» показывает порядок из формы, ещё не сохранённый');
    ok(str_contains($src('app/Core/LoginLook.php'), "], !empty(\$get['social_first']));"), 'и берёт его из полей формы');

    echo "\n── Сразу через сервис: idp_hint ──\n";
    $turn(['telegram', 'yandex']);
    ok($authorize(['idp_hint' => 'yandex']) === '/social/yandex/start', 'idp_hint=yandex ведёт сразу на Яндекс');
    ok(str_starts_with($authorize([]), '/login'), 'без подсказки — обычный экран входа');
    ok(str_starts_with($authorize(['idp_hint' => 'github']), '/login'), 'выключенный сервис подсказкой не включается');
    ok(str_starts_with($authorize(['idp_hint' => '../admin']), '/login'), 'чужое имя — обычный экран входа');
    ok(str_starts_with($authorize(['idp_hint' => 'yandex', 'display' => 'frame']), '/login'),
        'во фрейме — нет: страницы сервисов фреймы запрещают');
    DB::update('clients', ['login_methods' => 'password'], 'id = ?', [$cid]);
    ok(str_starts_with($authorize(['idp_hint' => 'yandex']), '/login'), 'портал, которому сервисы не показаны, подсказкой их не получает');
    DB::update('clients', ['login_methods' => null], 'id = ?', [$cid]);
    $flow = $src('app/Oidc/AuthorizeFlow.php');
    ok(str_contains($flow, "unset(\$stored['idp_hint']);") && strpos($flow, "unset(\$stored['idp_hint']);") < strpos($flow, "\$_SESSION['oauth_request'] = \$stored;"),
        'после входа подсказка не срабатывает снова — из сохранённого запроса она убрана');

    echo "\n── Из фрейма — сразу на сервисе ──\n";
    $app = $src('public/assets/js/app.js');
    $sso = $src('public/assets/js/sso.js');
    ok(str_contains($app, "toPortal({ state: 'window', social: out.getAttribute('data-social') || '' });"),
        'фрейм говорит странице портала, какой сервис выбран (куда уходит вкладка — в login_window)');
    ok(substr_count($sso, "backdropFilter: 'blur(8px)'") === 2, 'сайт под окном входа размыт заметно — как у X');
    $kit = $src('templates/kit/integration.md');
    ok(str_contains($kit, '### Сразу через сервис: `idp_hint`') && str_contains($kit, '`telegram`, `yandex`, `vk` или `github`'),
        'порталам описано, как поставить свою кнопку «Войти через Яндекс»');
} finally {
    foreach ($was as $k => $v) $v === null ? Setting::delete($k) : Setting::set($k, (string)$v);
    Setting::flush();
    Client::purge($cid);
    @unlink($JAR);
    @unlink(sys_get_temp_dir() . '/social-buttons-anon.txt');
}

echo "\n" . ($failed === 0 ? "✓ все $passed проверок" : "✗ провалов: $failed из " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
