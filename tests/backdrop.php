<?php
/**
 * Фон страниц (v4.84): картинки и видео за формами, кабинетом и админкой.
 *
 * Владелец 26.09: «было бы неплохо выносить изображения/видео на фон форм,
 * ЛК, админки — чтобы было и смотрелось интереснее и интерактивнее». Здесь —
 * что фон задаётся тем же набором, что обложка (CoverSet, владелец 'bg'),
 * показывается только там, где отмечено, лежит под вуалью цвета фона, не
 * тянет видео на телефон и в окно входа на странице портала, а «живой фон»
 * не двигается у того, кто просил без движения.
 *
 * Запуск: php tests/backdrop.php  (сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Backdrop;
use App\Core\CoverSet;
use App\Core\Setting;
use App\Core\View;

$passed = 0;
$failed = 0;
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);

$tmp = sys_get_temp_dir() . '/backdrop-' . getmypid();
@mkdir($tmp, 0777, true);
$was = ['appearance.backdrop' => Setting::get('appearance.backdrop'), 'cover.bg' => Setting::get('cover.bg')];
register_shutdown_function(static function () use ($was, $tmp): void {
    foreach ((array)glob(CoverSet::dir() . '/bg-*') as $f) @unlink((string)$f);
    foreach ($was as $k => $v) $v === null ? Setting::delete($k) : Setting::set($k, $v);
    Setting::flush();
    foreach ((array)glob("$tmp/*") as $f) @unlink((string)$f);
    @rmdir($tmp);
});
foreach ((array)glob(CoverSet::dir() . '/bg-*') as $f) @unlink((string)$f);
Setting::delete('appearance.backdrop');
Setting::delete('cover.bg');
Setting::flush();
$img = static function (string $name, array $rgb) use ($tmp): string {
    $im = imagecreatetruecolor(640, 400);
    imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
    imagejpeg($im, "$tmp/$name", 85);
    imagedestroy($im);
    return "$tmp/$name";
};
$files = static function (array $paths): array {
    $f = ['name' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    foreach ($paths as $i => $p) { $f['name'][] = "p$i.jpg"; $f['tmp_name'][] = $p; $f['error'][] = 0; $f['size'][] = (int)filesize($p); }
    return $f;
};

echo "── Настройки — только из списка ──\n";
ok(Backdrop::settings() === ['areas' => [], 'veil' => 'strong', 'blur' => 'none', 'parallax' => false],
    'по умолчанию фона нет нигде, вуаль сильная, без размытия и движения');
Backdrop::saveFromForm(['backdrop_areas' => ['admin', 'чужое', 'auth'], 'backdrop_veil' => 'none', 'backdrop_blur' => 'dizzy', 'backdrop_parallax' => '1'], []);
$s = Backdrop::settings();
ok($s['areas'] === ['auth', 'admin'] && $s['veil'] === 'strong' && $s['blur'] === 'none' && $s['parallax'] === true,
    'чужая область, вуаль и размытие не из списка отбрасываются', json_encode($s));
$stored = json_decode((string)Setting::get('appearance.backdrop'), true);
ok(($stored['areas'] ?? null) === ['auth', 'admin'], 'и в базу чужое не попадает, а не только прячется при чтении', json_encode($stored));

echo "\n── Набор — тот же механизм, что у обложки ──\n";
ok(CoverSet::scope(CoverSet::BACKDROP) === 'bg' && CoverSet::scope(null) === 'site' && CoverSet::scope(7) === 'c7', 'три набора: сайт, портал, фон');
$w = Backdrop::saveFromForm(['backdrop_areas' => ['auth', 'cabinet', 'admin'], 'backdrop_veil' => 'medium', 'backdrop_blur' => 'soft', 'backdrop_parallax' => '1',
    'bg_kind' => 'images', 'bg_motion' => 'drift', 'bg_every' => '5', 'cover_kind' => 'youtube'],
    ['bg_add' => $files([$img('a.jpg', [30, 60, 110]), $img('b.jpg', [120, 40, 30])])]);
$bg = CoverSet::get(CoverSet::BACKDROP);
ok($w === [] && count($bg['images']) === 2 && $bg['motion'] === 'drift' && $bg['every'] === 5 && $bg['kind'] === 'images',
    'поля фона — с приставкой bg_: поля обложки (cover_) его не трогают', json_encode($bg));
ok(count((array)glob(CoverSet::dir() . '/bg-*.webp')) === 2 && (Setting::get('cover.site') === null || CoverSet::get(null)['kind'] !== 'youtube'),
    'файлы фона лежат своими именами, обложка установки не тронута');
$cs = $src('app/Core/CoverSet.php');
ok(str_contains($cs, "'~^(site|bg|c\\d{1,9})-([0-9a-f]{12})\\.(webp|mp4|webm)\$~'"), 'файлы фона отдаются тем же адресом /cover/ по шаблону имени');

echo "\n── Слой ──\n";
$layer = Backdrop::layer('admin');
ok(str_starts_with($layer, '<div class="page-backdrop pb-veil-medium pb-blur-soft" aria-hidden="true" data-backdrop data-parallax><div class="pb-media"><div class="cover-slides is-drift" data-cover-slides="5">'),
    'слой: вуаль и размытие классами, «живой фон» — меткой, кадры — теми же слайдами, что у обложки');
Backdrop::saveFromForm(['backdrop_areas' => ['auth'], 'backdrop_veil' => 'strong', 'backdrop_blur' => 'none', 'backdrop_parallax' => '0'], []);
ok(Backdrop::layer('admin') === '' && Backdrop::layer('cabinet') === '' && Backdrop::layer('auth') !== '', 'только там, где отмечено');
file_put_contents("$tmp/v.webm", "\x1A\x45\xDF\xA3" . str_repeat("\x00", 64));
CoverSet::saveFromForm(CoverSet::BACKDROP, ['bg_kind' => 'video', 'bg_sound' => '1'],
    ['bg_video' => ['name' => 'v.webm', 'tmp_name' => "$tmp/v.webm", 'error' => 0, 'size' => 68]], 'bg');
$layer = Backdrop::layer('auth');
ok(str_contains($layer, 'data-cover-video=') && !str_contains($layer, 'data-cover-sound') && !preg_match('~<video[^>]* src=~', $layer),
    'видео на фоне — без кнопки звука и без адреса в разметке');
$guest = View::fetch('layouts/guest', ['content' => '', 'portals' => [], 'pageTitle' => 'Фон']);
ok(str_contains($guest, '<body class="has-backdrop">') && str_contains($guest, '<div class="page-backdrop'), 'главная получает фон и прозрачную страницу');
/* 4.85 пускал фон во фрейм портала, но условие «не попап» его отсекало: фрейм —
   та же компактная раскладка, что и окно входа. Владелец увидел это 26.09. */
$compact = View::fetch('layouts/auth', ['content' => '', 'popup' => true, 'pageTitle' => 'Вход']);
ok(str_contains($compact, '<body class="popup has-backdrop">') && str_contains($compact, '<div class="page-backdrop'),
    'фон и в компактной раскладке — во фрейме на странице портала и в окне входа (v4.87)');
Setting::delete('cover.bg');
Setting::flush();
foreach ((array)glob(CoverSet::dir() . '/bg-*') as $f) @unlink((string)$f);
ok(Backdrop::layer('auth') === '' && !str_contains(View::fetch('layouts/guest', ['content' => '', 'portals' => [], 'pageTitle' => 'Фон']), 'has-backdrop'),
    'показывать нечего — слоя и прозрачности нет');

echo "\n── Где фона не бывает ──\n";
$auth = $src('templates/layouts/auth.php');
ok(str_contains($auth, "\$backdrop = App\\Core\\Backdrop::layer('auth');"),
    'у экранов входа фон везде — в полной и в компактной раскладке (v4.87)');
ok(str_contains($src('templates/layouts/base.php'), "\$backdrop = \\App\\Core\\Backdrop::layer(\$isAdminArea ? 'admin' : 'cabinet');"),
    'кабинет и админка — каждый своей галочкой');
ok(!str_contains($src('templates/layouts/minimal.php'), 'Backdrop'), 'страницы ошибок — без фона');

echo "\n── Поведение и вид ──\n";
$js = $src('public/assets/js/app.js');
ok(str_contains($js, 'liveCover(bd, function () { return wideNow.matches && !saveData && !framed; });')
    && str_contains($js, "var framed = document.documentElement.hasAttribute('data-frame-origin');")
    && substr_count($js, 'if (!videoOk()) return;') === 2,
    'видео и YouTube на фоне — только на широком экране, не при экономии трафика и не во фрейме портала: там только картинки');
ok(str_contains($js, "if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;")
    && str_contains($js, "if (!media || !bd.hasAttribute('data-parallax')) return;"),
    '«живой фон» — только если включён, мышью и не при «без движения»');
$css = $src('public/assets/css/identity.css');
ok(str_contains($css, '.page-backdrop { --pb-veil: .92; position: fixed; inset: 0; z-index: -1;')
    && str_contains($css, '.page-backdrop::after { content: \'\'; position: absolute; inset: 0; background: var(--bg); opacity: var(--pb-veil); }')
    && str_contains($css, '.pb-veil-light { --pb-veil: .74; }'),
    'слой под страницей, вуаль цвета фона темы; самая лёгкая — 74%');
ok(str_contains($css, 'body.has-backdrop { background: transparent; }') && str_contains($css, '.has-backdrop .guest, .has-backdrop .auth-split-form { background: transparent; }'),
    'страница становится прозрачной только при фоне');
ok(str_contains($css, '@media (prefers-reduced-motion: reduce) { .pb-media { transform: none !important; } }'), 'и CSS держит фон на месте при «без движения»');
/* С 4.89 у полосы «Сохранить» одно правило на все случаи (ui_fixes): отдельное
   «на фоне» и «в карточке» давало белую плиту в стеклянной карточке. */
ok(!str_contains($css, 'body.has-backdrop .form-actions'), 'полоса «Сохранить» на фоне — без своего правила: пилюля одна на всё');
/* Боевая 26.09: у длинной формы входа панель с обложкой тянулась на всю
   страницу, и кнопка звука в её углу была ниже первого экрана. */
ok((bool)preg_match('~\.auth-split-media \{\s*display: block; position: sticky; top: 0; align-self: start; height: 100vh; height: 100dvh;~', $css),
    'панель входа — ровно в экран и на месте при прокрутке формы: кнопка звука всегда видна');

echo "\n── Редактор ──\n";
$ed = View::fetch('admin/_cover_field', ['owner' => CoverSet::BACKDROP, 'prefix' => 'bg', 'label' => 'Фон', 'sound' => false]);
preg_match_all('~name="([^"]+)"~', $ed, $m);
ok(in_array('bg_kind', $m[1], true) && in_array('bg_add[]', $m[1], true) && !in_array('bg_sound', $m[1], true)
    && !preg_grep('~^cover_~', $m[1]) && str_contains($ed, 'data-cover-edit="bg"'),
    'у фона свои поля (bg_*), без кнопки звука; скрипт различает редакторы по приставке', implode(',', $m[1]));
ok(str_contains($js, "var kindName = (box.getAttribute('data-cover-edit') || 'cover') + '_kind';"), 'блоки видео и YouTube переключаются у каждого редактора свои');
$appearance = $src('templates/admin/appearance.php');
ok(str_contains($appearance, "field_checks('backdrop_areas'") && str_contains($appearance, "View::fetch('admin/_cover_field', ['owner' => App\\Core\\CoverSet::BACKDROP, 'prefix' => 'bg',"),
    'в «Оформлении сайта» — карточка фона: где, как приглушить, что показывать');
ok(str_contains($src('app/Controllers/Admin/AppearanceController.php'), "if (isset(\$_POST['backdrop_veil'])) {"),
    'сохранение фона — только когда форма его прислала');

echo "\n" . ($failed === 0 ? "✓ все $passed проверок" : "✗ провалов: $failed из " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
