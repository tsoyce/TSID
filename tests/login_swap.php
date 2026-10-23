<?php
/**
 * Вкладки входа без перезагрузки и вход на алиасе портала (v4.97).
 *
 * Владелец: «между вход / регистрация / забыл пароль не должно быть
 * перезагрузки — плавные переходы, как в приложении». И: на портале, открытом
 * по алиасу, окно входа показывало «сайт не позволяет установить соединение».
 * Замер на живом портале: на алиасе набор отдаёт адрес возврата на основной
 * адрес — окно SSO браузер на алиасе не показывает, а возврат на основной адрес
 * не находит сессию входа, оставшуюся на алиасе. Здесь проверяется, что:
 *   · вне фрейма переходы между тремя вкладками подменяют только колонку формы,
 *     пишутся в историю и возвращаются «назад»; прочие ссылки — как были;
 *   · переход идёт через View Transitions — в сторону выбранной вкладки;
 *   · вкладки подгружаются заранее, при наведении;
 *   · sso.js на алиасе переводит на основной адрес и там сам начинает вход;
 *   · набор портала со списком алиасов возвращает на тот же алиас.
 *
 * Запуск: php tests/login_swap.php  (сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

$passed = 0;
$failed = 0;
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);
$app = $src('public/assets/js/app.js');
$css = $src('public/assets/css/app.css');
$sso = $src('public/assets/js/sso.js');

echo "── Вкладки входа без перезагрузки ──\n";
ok(str_contains($app, "var TABS = ['/login', '/register', '/recover'];")
    && str_contains($app, "if (!framed && !tabLink(a)) return;"),
    'вне фрейма перехватываются только переходы между тремя вкладками');
ok(str_contains($app, "document.getElementById('main').innerHTML = next.getElementById('main').innerHTML;"),
    'подменяется колонка формы — панель с обложкой и видео не начинается заново');
ok(str_contains($app, "if (push) history.pushState({ sso: 1 }, '', url);")
    && str_contains($app, "if (appShell ? appPath(location.pathname) : tabOf(location.pathname) !== -1) go(location.href, null, false);"),
    'адрес настоящий и в истории: «назад» возвращает вкладку');
ok(str_contains($app, "if (!framed && (to === -1 || !/class=\"auth-tabs/.test(res.html))) { leave(res.r.url); return; }"),
    'ответ без вкладок (вошёл и уехал в кабинет) — обычным переходом');
ok(str_contains($app, "document.startViewTransition(fn)")
    && str_contains($app, "(to > from ? 'fwd' : 'back')")
    && str_contains($app, "matchMedia('(prefers-reduced-motion: reduce)').matches"),
    'переход — View Transitions в сторону вкладки; меньше движения — мгновенно');
ok(str_contains($css, ':root[data-swap] #main { view-transition-name: screen-main; }')
    && str_contains($css, ':root[data-swap="fwd"]::view-transition-new(screen-main) { animation-name: swap-in-end; }')
    && str_contains($css, 'height: 100%; object-fit: none; object-position: 50% 0;'),
    'колонка формы уходит и приходит сбоку, высота меняется без растяжения');
ok(str_contains($css, '.auth-tabs > a:nth-of-type(2) { view-transition-name: screen-tab-2; }'),
    'подписи вкладок — своими слоями поверх плашки: на время перехода не пропадают');
ok(str_contains($app, "document.addEventListener('pointerover', warm, { passive: true });")
    && str_contains($app, "early[key].at > Date.now() - 15000"),
    'вкладка подгружается при наведении — к щелчку ответ уже здесь');
ok(str_contains($app, "animate(function () { swapPage(res.html, res.r.url, push); },"),
    'подмена идёт через animate() — и во фрейме (там без View Transitions, v5.3.1)');

echo "\n── Вход на алиасе портала ──\n";
ok(str_contains($sso, "if (back && back !== 'null' && back !== location.origin && !e.auto) {")
    && str_contains($sso, "location.href = back + location.pathname + location.search + '#sso-login';"),
    'sso.js: возврат на другой адрес — переход на ту же страницу основного адреса');
ok(str_contains($sso, "if (location.hash === '#sso-login') {")
    && str_contains($sso, "start({ preventDefault: function () {}, currentTarget: first, auto: true });"),
    'sso.js: там вход начинается сам, без второго щелчка');
ok(str_contains($sso, "(e.auto && ui.display !== 'popup')"),
    'sso.js: начатый без щелчка — страницей, а не окном, которое браузер не откроет');

/* Набор портала: класс из шаблона, плейсхолдеры — пустыми словами. */
$tpl = str_replace(['{{APP_NAME}}', '{{CLIENT_NAME}}'], ['SSO', 'Проба'], $src('templates/kit/SsoClient.php.tpl'));
$tmp = sys_get_temp_dir() . '/login-swap-kit-' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($tmp, $tpl);
require $tmp;
@unlink($tmp);
$cfg = ['issuer' => 'https://sso.example', 'client_id' => 'cli_x', 'client_secret' => 's', 'scopes' => 'openid',
    'redirect_uri' => 'https://portal.example/sso/callback.php', 'aliases' => ['alias.example'], 'var_dir' => sys_get_temp_dir()];
$_SESSION = [];
$redirectOf = static function (string $host) use ($cfg): string {
    $_SERVER['HTTP_HOST'] = $host;
    parse_str((string)parse_url((new SsoClient($cfg))->buildAuthorizeUrl(), PHP_URL_QUERY), $q);
    return (string)($q['redirect_uri'] ?? '');
};
ok($redirectOf('alias.example') === 'https://alias.example/sso/callback.php',
    'набор: открыт по алиасу из списка — возврат на тот же алиас тем же путём');
ok($redirectOf('portal.example') === 'https://portal.example/sso/callback.php'
    && $redirectOf('stranger.example') === 'https://portal.example/sso/callback.php',
    'набор: основной адрес и чужой хост — возврат как настроен');
ok(str_contains($src('templates/kit/config.php.tpl'), "'aliases'       => [],"),
    'набор: список алиасов — в config.php, по умолчанию пустой');

echo "\n══ Итог ══\nПройдено: $passed, провалено: $failed\n";
exit($failed > 0 ? 1 : 0);
