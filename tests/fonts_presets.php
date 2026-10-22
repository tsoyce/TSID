<?php
/**
 * Шрифты из Google Fonts у себя и готовые схемы оформления (v4.78).
 *
 * Владелец 25.09: «подвязать Google Fonts, чтобы больше шрифтов», «коллажи
 * для цветовых схем». Google здесь поддельный — встроенный сервер PHP на
 * свободном порту отвечает за каталог, css2 и файлы шрифтов, — а путь тот же:
 * каталог с кириллицей, скачивание к себе только кириллицы и латиницы,
 * @font-face со своего домена, запрет убрать шрифт, который используется.
 *
 * Запуск: php tests/fonts_presets.php  (SSO-сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Bridge;
use App\Core\FontLibrary;
use App\Core\LookSchemes;
use App\Core\Setting;
use App\Core\View;

$passed = 0;
$failed = 0;

/* ---- Поддельный Google ---- */
$site = sys_get_temp_dir() . '/gfonts-' . getmypid();
@mkdir($site, 0777, true);
file_put_contents("$site/router.php", <<<'ROUTER'
<?php
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$q = (string)($_GET['family'] ?? '');
if ($path === '/fonts.google.com/metadata/fonts') {
    echo ")]}'\n" . json_encode(['familyMetadataList' => [
        ['family' => 'Manrope', 'category' => 'Sans Serif', 'subsets' => ['menu', 'cyrillic', 'greek', 'latin', 'latin-ext'], 'popularity' => 31],
        ['family' => 'Lora', 'category' => 'Serif', 'subsets' => ['cyrillic', 'latin'], 'popularity' => 50],
        ['family' => 'Latin Only', 'category' => 'Sans Serif', 'subsets' => ['latin'], 'popularity' => 1],
        ['family' => 'Broken Font', 'category' => 'Display', 'subsets' => ['cyrillic', 'latin'], 'popularity' => 90],
    ]]);
    return true;
}
if ($path === '/fonts.googleapis.com/css2') {
    $block = static fn(string $subset, string $fam, string $file, string $style = 'normal', string $w = '400 700'): string =>
        "/* $subset */\n@font-face {\n  font-family: '$fam';\n  font-style: $style;\n  font-weight: $w;\n  font-display: swap;\n"
        . "  src: url(https://fonts.gstatic.com/s/$file) format('woff2');\n  unicode-range: U+0400-045F;\n}\n";
    header('Content-Type: text/css');
    if (str_starts_with($q, 'Manrope')) {
        echo $block('cyrillic', 'Manrope', 'manrope/c.woff2') . $block('greek', 'Manrope', 'manrope/g.woff2')
            . $block('latin-ext', 'Manrope', 'manrope/le.woff2') . $block('latin', 'Manrope', 'manrope/l.woff2')
            . $block('latin', 'Manrope', 'manrope/i.woff2', 'italic');
        return true;
    }
    /* У Lora нет всех начертаний — Google отвечает ошибкой на 400–700. */
    if ($q === 'Lora:wght@400;500;600;700') { http_response_code(400); echo 'bad'; return true; }
    if ($q === 'Lora:wght@400') { echo $block('cyrillic', 'Lora', 'lora/c.woff2', 'normal', '400') . $block('latin', 'Lora', 'lora/l.woff2', 'normal', '400'); return true; }
    if (str_starts_with($q, 'Broken Font')) { echo $block('cyrillic', 'Broken Font', 'broken/c.woff2'); return true; }
    http_response_code(400);
    return true;
}
if (str_starts_with($path, '/fonts.gstatic.com/s/')) {
    echo str_contains($path, '/broken/') ? 'это не шрифт' : 'wOF2' . str_repeat("\0", 60) . $path;
    return true;
}
http_response_code(404);
return true;
ROUTER);

$port = 0;
for ($try = 0; $try < 20 && $port === 0; $try++) {
    $p = random_int(18901, 19800);
    $s = @fsockopen('127.0.0.1', $p, $e, $es, 0.2);
    if ($s) { fclose($s); continue; }
    $port = $p;
}
$devnull = DIRECTORY_SEPARATOR === "\\" ? "NUL" : "/dev/null";
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$site/router.php"], [1 => ['file', $devnull, 'w'], 2 => ['file', $devnull, 'w']], $pipes);
for ($i = 0; $i < 50; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $e, $es, 0.2);
    if ($s) { fclose($s); break; }
    usleep(100000);
}

$cache = SSO_ROOT . '/storage/cache/google-fonts.json';
$cacheWas = is_file($cache) ? (string)file_get_contents($cache) : null;
$was = [];
foreach (['fonts.installed', 'look.theme'] as $k) $was[$k] = Setting::get($k);
register_shutdown_function(static function () use ($was, $proc, $site, $cache, $cacheWas): void {
    foreach (['manrope', 'lora', 'broken-font'] as $slug) {
        foreach ((array)glob(FontLibrary::dir() . "/$slug/*") as $f) @unlink((string)$f);
        @rmdir(FontLibrary::dir() . "/$slug");
    }
    foreach ($was as $k => $v) $v === null ? Setting::delete($k) : Setting::set($k, $v);
    Setting::flush();
    $cacheWas === null ? @unlink($cache) : file_put_contents($cache, $cacheWas);
    if (is_resource($proc)) proc_terminate($proc);
    @unlink("$site/router.php");
    @rmdir($site);
});
putenv("SSO_FONTS_BASE=http://127.0.0.1:$port");
Setting::delete('fonts.installed');
Setting::delete('look.theme');
Setting::flush();
@unlink($cache);

echo "── Страница оформления каталог не ждёт ──\n";
/* 2,7 МБ от Google — секунды. Страница берёт каталог из кеша, а свежий
   качается после ответа. */
/* В консоли Defer выполняет работу сразу (откладывать некуда), поэтому
   замеряем то, что видит страница: список — из кеша, каким он был до вызова. */
$list = FontLibrary::catalogCached();
$fl = (string)file_get_contents(SSO_ROOT . '/app/Core/FontLibrary.php');
$ap = (string)file_get_contents(SSO_ROOT . '/templates/admin/appearance.php');
ok($list === [] && str_contains($fl, 'Defer::after(static fn() => self::catalog(true));')
    && str_contains($ap, 'FontLibrary::catalogCached()') && !str_contains($ap, 'FontLibrary::catalog()'),
    'без кеша страница открывается сразу, каталог качается после ответа');
@unlink($cache);

echo "\n── Каталог Google — только с кириллицей ──\n";
$cat = array_column(FontLibrary::catalog(true), 'family');
ok(in_array('Manrope', $cat, true) && in_array('Lora', $cat, true) && !in_array('Latin Only', $cat, true),
    'в каталоге семейства с кириллицей, без чисто латинских', implode(', ', $cat));

echo "\n── Установка: файлы — к нам, только кириллица и латиница ──\n";
$r = FontLibrary::install('manrope');
$m = FontLibrary::installed()['manrope'] ?? null;
ok($r['ok'] && $m !== null && ($r['key'] ?? '') === 'g-manrope', 'Manrope установлен по названию в любом регистре', $r['detail']);
$files = (array)glob(FontLibrary::dir() . '/manrope/*.woff2');
ok(count($files) === 3, 'скачаны кириллица, латиница и расширенная латиница — без греческого и курсива', count($files) . ' файлов');
ok($files !== [] && array_reduce($files, static fn(bool $ok, $f): bool => $ok && str_starts_with((string)file_get_contents((string)$f), 'wOF2'), true),
    'каждый файл — настоящий woff2');
ok(FontLibrary::install('Manrope')['ok'] && count((array)glob(FontLibrary::dir() . '/manrope/*.woff2')) === 3, 'повторная установка ничего не дублирует');
ok(!FontLibrary::install('Latin Only')['ok'] && !FontLibrary::install('Nope Sans')['ok'], 'шрифт без кириллицы и выдуманный — отказ');
$r = FontLibrary::install('Lora');
ok($r['ok'] && count((array)glob(FontLibrary::dir() . '/lora/*.woff2')) === 2, 'нет всех начертаний — берётся обычное, шрифт всё равно ставится', $r['detail']);
$r = FontLibrary::install('Broken Font');
ok(!$r['ok'] && !is_dir(FontLibrary::dir() . '/broken-font') && !isset(FontLibrary::installed()['broken-font']),
    'файл пришёл не шрифтом — установка откатывается без мусора', $r['detail']);

echo "\n── В оформлении ──\n";
ok(FontLibrary::forUi('g-manrope') && !FontLibrary::forUi('g-lora'), 'для текста интерфейса — только рубленые, антиква — для заголовков');
$t = Bridge::sanitize(['font' => 'g-manrope', 'font_display' => 'g-lora'], 'site');
ok(($t['font'] ?? '') === 'g-manrope' && ($t['font_display'] ?? '') === 'g-lora', 'оформление сайта и портала принимает установленные шрифты');
ok(!isset(Bridge::sanitize(['font' => 'g-lora'], 'site')['font']), 'антиква в текст интерфейса не проходит');
ok(Bridge::sanitize(['font' => 'g-manrope', 'font_display' => 'g-lora'], 'manifest') === [], 'манифест портала их назвать не может: ставить шрифты в чужую установку портал не вправе');
Setting::set('look.theme', (string)json_encode(['font' => 'g-manrope', 'font_display' => 'g-lora']));
Setting::flush();
$css = Bridge::themeStyle(null);
ok(str_contains($css, "font-family:'Manrope'") && str_contains($css, 'url(/fonts/manrope/') && str_contains($css, "--font-ui:'Manrope'")
    && str_contains($css, "--font-display:'Lora'"), 'экран входа грузит шрифт с нашего домена и ставит его в текст и заголовки');
ok(!str_contains($css, 'gstatic') && !str_contains($css, 'googleapis'), 'ни одного адреса Google на странице');

echo "\n── Убрать можно только неиспользуемый ──\n";
$r = FontLibrary::remove('manrope');
ok(!$r['ok'] && is_dir(FontLibrary::dir() . '/manrope'), 'шрифт оформления сайта не убирается — экран сменил бы шрифт молча', $r['detail']);
Setting::set('look.theme', (string)json_encode(['font_display' => 'g-lora']));
Setting::flush();
$r = FontLibrary::remove('manrope');
ok($r['ok'] && !is_dir(FontLibrary::dir() . '/manrope') && !isset(FontLibrary::installed()['manrope']), 'неиспользуемый убирается вместе с файлами');

echo "\n── Отдача файлов ──\n";
$lora = (array)glob(FontLibrary::dir() . '/lora/*.woff2');
ok($lora !== [] && FontLibrary::file('lora', basename((string)$lora[0])) !== null, 'файл установленного шрифта отдаётся');
/* Оба обхода указывают на настоящий config/config.php — без проверки имени
   он бы и отдался. */
ok(is_file(SSO_ROOT . '/config/config.php') && FontLibrary::file('../../config', 'config.php') === null
    && FontLibrary::file('lora', '../../../config/config.php') === null
    && FontLibrary::file('lora', 'x.woff2') === null, 'путь разбирается по шаблону — выйти из каталога шрифтов нельзя');
$routes = (string)file_get_contents(SSO_ROOT . '/app/routes.php');
ok(str_contains($routes, "'/fonts/{slug}/{file}'") && str_contains($routes, "'/admin/appearance/fonts'"), 'маршруты шрифтов на месте');

echo "\n── Готовые схемы ──\n";
$bad = [];
foreach (Bridge::PRESETS as $key => [$name, $pal]) {
    Bridge::preview(null, 'off', Bridge::sanitize($pal, 'site'), null, ['title' => '', 'text' => '']);
    $th = Bridge::themeOf(null);
    if ($th === null || ($th['why'] ?? []) !== [] || $th['light'] === null || $th['dark'] === null) $bad[] = $name;
}
ok(count(Bridge::PRESETS) >= 20 && $bad === [], 'каждая схема применяется целиком в обеих темах — контраст проходит', implode(', ', $bad));

/* Базовые (v4.80): установка как есть и две контрастные. */
$bad = [];
foreach (Bridge::BASE_PRESETS as $key => [$name, $pal]) {
    if ($pal === []) continue;
    Bridge::preview(null, 'off', Bridge::sanitize($pal, 'site'), null, ['title' => '', 'text' => '']);
    $th = Bridge::themeOf(null);
    if ($th === null || ($th['why'] ?? []) !== [] || $th['light'] === null || $th['dark'] === null) $bad[] = $name;
}
ok(isset(Bridge::BASE_PRESETS['base'], Bridge::BASE_PRESETS['mono'], Bridge::BASE_PRESETS['inverse']) && $bad === [],
    'чёрно-белая и бело-чёрная применяются целиком в обеих темах', implode(', ', $bad));
ok(Bridge::sanitize(Bridge::BASE_PRESETS['inverse'][1], 'site')['theme_default'] === 'dark'
    && Bridge::sanitize(Bridge::BASE_PRESETS['mono'][1], 'site')['theme_default'] === 'light',
    'бело-чёрная встречает новых посетителей тёмной темой, чёрно-белая — светлой');
$base = LookSchemes::paletteValues([]);
ok(array_filter(array_intersect_key($base, array_flip(Bridge::COLORS))) === [] && $base['dark'] === false && $base['theme_default'] === '',
    'базовая сбрасывает цвета и тему к установке', json_encode($base));
$terra = LookSchemes::paletteValues(Bridge::PRESETS['terracotta'][1]);
ok($terra['dark'] === true && !array_key_exists('theme_default', $terra) && !array_key_exists('font', $terra) && !array_key_exists('radius', $terra),
    'цветная схема трогает только цвета — шрифты, форма и тема остаются', json_encode(array_keys($terra)));

$editor = View::fetch('admin/_look_editor', ['scope' => 'site', 'theme' => [], 'previewUrl' => '/admin/appearance/preview', 'before' => '', 'paneExtra' => '']);
$missing = [];
foreach (array_keys(Bridge::BASE_PRESETS + Bridge::PRESETS) as $key) if (!str_contains($editor, 'data-look-mine="preset-' . $key . '"')) $missing[] = $key;
ok($missing === [] && substr_count($editor, 'data-look-mine="preset-') === count(Bridge::BASE_PRESETS) + count(Bridge::PRESETS),
    'в редакторе плитка на каждую схему — базовые и цветные', implode(', ', $missing));
ok(!str_contains($editor, 'data-look-drop-scheme="preset-'), 'готовую схему убрать нельзя — крестик только у своих');
preg_match('~data-look-mine="preset-terracotta">\s*<button class="look-preset" type="button" data-look-adopt="([^"]*)"~', $editor, $m);
$adopt = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);
preg_match('~data-look-mine="preset-inverse">\s*<button class="look-preset" type="button" data-look-adopt="([^"]*)"~', $editor, $m);
$adoptInv = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);
ok(is_array($adopt) && ($adopt['accent'] ?? '') === '#A53718' && !isset($adopt['font']) && !isset($adopt['radius']) && !isset($adopt['button'])
    && is_array($adoptInv) && ($adoptInv['theme_default'] ?? '') === 'dark',
    'щелчок по готовой схеме меняет цвета (и тему у контрастных), а выбранные шрифты и форму не сбрасывает',
    json_encode([array_keys((array)$adopt), $adoptInv['theme_default'] ?? null]));
ok(str_contains($editor, 'value="g-lora"') && str_contains($editor, 'url(/fonts/lora/'), 'установленный шрифт — в выборе, с образцом его же файлом');

echo "\n" . ($failed === 0 ? "✓ все $passed проверок" : "✗ провалов: $failed из " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
