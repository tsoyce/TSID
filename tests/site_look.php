<?php
/**
 * Оформление из /admin/appearance — на главной всегда, на всём сайте по
 * выбору владельца (v4.77).
 *
 * Владелец 25.09: «Форма на главной не слушает правила, заданные в
 * /admin/appearance — шрифты, обрамление» и «раздел сделан правильно, но
 * только под форму авторизации — можно распространить на проект
 * глобально». Главная брала только акцент, а кабинет и панель — тоже.
 * Здесь же — положение формы на главной: по правому краю островка, а не
 * окна, — и закрытие окна входа на странице портала щелчком мимо.
 *
 * Запуск: php tests/site_look.php  (сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Setting;
use App\Core\View;

$passed = 0;
$failed = 0;

$was = ['look.theme' => Setting::get('look.theme'), 'appearance.look_everywhere' => Setting::get('appearance.look_everywhere')];
register_shutdown_function(static function () use ($was): void {
    foreach ($was as $k => $v) $v === null ? Setting::delete($k) : Setting::set($k, $v);
    Setting::flush();
});
/* Оформление сайта: шрифт, скругление, палитра обеих тем — как из редактора. */
Setting::set('look.theme', (string)json_encode([
    'font' => 'inter', 'font_display' => 'garamond', 'radius' => 14,
    'accent' => '#A53718', 'background' => '#F1ECDC', 'surface' => '#FFFFFF', 'text' => '#0E0B07',
], JSON_UNESCAPED_UNICODE));
Setting::flush();
$mark = static fn(string $html): bool => str_contains($html, '--radius-s:14px') && str_contains($html, "--font-ui:'Inter'");

echo "── Главная — это экран входа: оформление на ней всегда ──\n";
Setting::set('appearance.look_everywhere', '0');
Setting::flush();
$guest = View::fetch('layouts/guest', ['content' => '', 'portals' => [], 'pageTitle' => 'Главная']);
ok($mark($guest), 'форма на главной слушает шрифты и скругление из оформления, даже если оно не «на весь сайт»');

echo "\n── На весь сайт — по выбору владельца ──\n";
$base = View::fetch('layouts/base', ['content' => '', 'pageTitle' => 'Профиль']);
$minimal = View::fetch('layouts/minimal', ['content' => '', 'pageTitle' => 'Ошибка']);
ok(!$mark($base) && !$mark($minimal), 'выключено — кабинет, панель и страницы ошибок в родном оформлении');
Setting::set('appearance.look_everywhere', '1');
Setting::flush();
$base = View::fetch('layouts/base', ['content' => '', 'pageTitle' => 'Профиль']);
$minimal = View::fetch('layouts/minimal', ['content' => '', 'pageTitle' => 'Ошибка']);
ok($mark($base), 'включено — кабинет и панель в тех же шрифтах, скруглениях и цветах');
ok($mark($minimal), 'включено — и страницы ошибок');
ok(str_contains($base, '--text-2:') && str_contains($base, '--control-line:') && str_contains($base, '--on-ink:'),
    'панели хватает токенов: приглушённый текст, рамки полей, текст на кнопке — все из палитры, с проверкой контраста');
Setting::delete('appearance.look_everywhere');
Setting::flush();
ok(App\Core\Appearance::lookEverywhere(), 'по умолчанию — на весь сайт');

echo "\n── Форма на главной — по краю островка, а не окна ──\n";
$css = (string)file_get_contents(SSO_ROOT . '/public/assets/css/identity.css');
ok(str_contains($css, '.guest-body { justify-content: flex-end; padding: 24px max(var(--gutter), calc((100% - 1120px) / 2)) 40px; }'),
    'на широком экране правый край формы совпадает с правым краем островка (1120px по центру)');
ok(!str_contains($css, '.guest-body { justify-content: flex-end; padding: 24px 64px 40px; }'), 'отступ в 64px от края окна снят');

echo "\n── Окно входа на странице портала закрывается щелчком мимо ──\n";
$sso = (string)file_get_contents(SSO_ROOT . '/public/assets/js/sso.js');
ok(str_contains($sso, "wrap.addEventListener('mousedown', function (ev) { downOnVeil = ev.target === wrap; });")
    && str_contains($sso, 'if (ev.target === wrap && downOnVeil) closeModal();'),
    'щелчок по затемнению закрывает окно — если и нажали, и отпустили на затемнении');

echo "\n" . ($failed === 0 ? "✓ все $passed проверок" : "✗ провалов: $failed из " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
