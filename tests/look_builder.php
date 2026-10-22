<?php
/**
 * Конструктор оформления и свои схемы (v4.79).
 *
 * Владелец 25.09: «давай делай что не доделал» — после 4.78 были предложены
 * три шага: толщина шрифта по ролям, форма кнопок и полей, свои схемы
 * целиком (со шрифтами и формой) для сайта и порталов. Здесь — что каждый
 * параметр проверен списком и доходит до экрана, что схема хранит вид без
 * содержания и применяется одним щелчком у сайта и у порталов. И дефект
 * редактора: в одну колонку предпросмотр прилипал и закрывал настройки.
 *
 * v4.80: тема для новых посетителей, узор фона, карточки (стекло, плоские) и
 * правки интерфейса, найденные по снимкам страниц («кривости могут быть и в
 * других местах»).
 *
 * Запуск: php tests/look_builder.php  (сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Bridge;
use App\Core\LoginLook;
use App\Core\LookSchemes;
use App\Core\Setting;
use App\Core\View;

$passed = 0;
$failed = 0;
$was = ['look.schemes' => Setting::get('look.schemes'), 'look.theme' => Setting::get('look.theme'),
    'appearance.look_everywhere' => Setting::get('appearance.look_everywhere')];
register_shutdown_function(static function () use ($was): void {
    foreach ($was as $k => $v) $v === null ? Setting::delete($k) : Setting::set($k, $v);
    Setting::flush();
});
Setting::delete('look.schemes');
Setting::delete('look.theme');
Setting::flush();

echo "── Параметры — только из списка ──\n";
$ok = Bridge::sanitize(['weight_heading' => '700', 'weight_button' => '600', 'button' => 'outline', 'field_border' => '1.5',
    'shadows' => 'none', 'control' => 'large'], 'site');
ok(count(array_intersect_key($ok, Bridge::BUILDER)) === 6, 'все шесть параметров конструктора принимаются');
$bad = Bridge::sanitize(['weight_heading' => '900', 'button' => 'neon', 'field_border' => '3', 'shadows' => 'drop',
    'control' => 'huge', 'weight_button' => 'bold'], 'site');
ok(array_intersect_key($bad, Bridge::BUILDER) === [], 'значение не из списка молча отбрасывается — CSS через них не пройдёт');
$form = LoginLook::fromForm(['weight_heading' => '500', 'button' => 'soft', 'control' => 'compact', 'shadows' => ''], 'site');
ok(($form['weight_heading'] ?? '') === '500' && ($form['button'] ?? '') === 'soft' && ($form['control'] ?? '') === 'compact'
    && !isset($form['shadows']), 'редактор сохраняет выбранное, пустое — «как у сайта»');

echo "\n── До экрана ──\n";
Setting::set('look.theme', (string)json_encode(['weight_heading' => '700', 'weight_button' => '600', 'field_border' => '2',
    'control' => 'large', 'shadows' => 'none', 'button' => 'outline']));
Setting::flush();
$css = Bridge::themeStyle(null);
ok(str_contains($css, '--fw-heading:700') && str_contains($css, '--fw-button:600') && str_contains($css, '--control-border:2px'),
    'толщина заголовков, кнопок и рамка полей — токенами');
ok(str_contains($css, '--control-h:48px') && str_contains($css, '--control-h-s:38px'), 'высота полей и кнопок — «крупная»');
ok(substr_count($css, '--shadow-1:none') === 2, 'без теней — и в светлой, и в тёмной теме');
ok(str_contains($css, '.btn-primary{background:transparent;color:var(--ink-text);border-color:var(--ink)'),
    'контурная главная кнопка — текстом ссылки, у которого контраст проверен палитрой');
Setting::set('look.theme', (string)json_encode(['button' => 'soft', 'shadows' => 'strong']));
Setting::flush();
$css = Bridge::themeStyle(null);
ok(str_contains($css, '.btn-primary{background:var(--ink-soft);color:var(--ink-text)') && str_contains($css, '--shadow-2:0 2px 6px'),
    'мягкая кнопка и заметные тени');
Setting::set('look.theme', (string)json_encode(['accent' => '#A53718']));
Setting::flush();
ok(!str_contains(Bridge::themeStyle(null), '.btn-primary{'), 'не выбрано — правил кнопки нет, всё как у установки');
$app = (string)file_get_contents(SSO_ROOT . '/public/assets/css/app.css');
ok(str_contains($app, 'h1, h2, h3 { line-height: 1.2; margin: 0 0 12px; font-weight: var(--fw-heading, 600); }')
    && str_contains($app, 'font: var(--fw-button, 500) var(--fs-14)/1 var(--font-ui);')
    && str_contains($app, 'border: var(--control-border, 1px) solid var(--control-line);'),
    'заголовки, кнопки и поля читают эти токены');

echo "\n── Свои схемы: вид целиком, без содержания ──\n";
ok(!LookSchemes::save('', ['accent' => '#A53718'])['ok'], 'без имени — не сохраняется');
ok(!LookSchemes::save('Пусто', ['headline' => 'Только слова'])['ok'], 'одни слова без вида — не схема');
$full = Bridge::sanitize(['accent' => '#0B6E99', 'background' => '#EEF5F8', 'surface' => '#FFFFFF', 'text' => '#0F1D24',
    'accent_dark' => '#5FB7DE', 'background_dark' => '#0B1419', 'surface_dark' => '#122028', 'text_dark' => '#E6F1F6',
    'radius' => 12, 'font' => 'inter', 'font_display' => 'garamond', 'wash' => false, 'button' => 'soft', 'control' => 'large',
    'headline' => 'Заголовок портала', 'tagline' => 'Подпись', 'layout' => 'right'], 'site');
$r = LookSchemes::save('Морская', $full);
$all = LookSchemes::all();
ok($r['ok'] && count($all) === 1 && ($all[0]['theme']['font_display'] ?? '') === 'garamond' && ($all[0]['theme']['button'] ?? '') === 'soft'
    && ($all[0]['theme']['wash'] ?? null) === false, 'схема хранит палитру обеих тем, шрифты, скругление, подкраску и форму', $r['detail']);
ok(!isset($all[0]['theme']['headline']) && !isset($all[0]['theme']['tagline']) && !isset($all[0]['theme']['layout']),
    'заголовок, подпись и раскладка в схему не входят — это слова портала, а не вид');
LookSchemes::save('морская', ['accent' => '#2F6B3A']);
ok(count(LookSchemes::all()) === 1 && (LookSchemes::all()[0]['theme']['accent'] ?? '') === '#2F6B3A', 'то же имя в любом регистре — замена, а не вторая плитка');
$fv = LookSchemes::formValues(LookSchemes::all()[0]['theme']);
ok(array_key_exists('font', $fv) && $fv['font'] === '' && $fv['wash'] === true && $fv['dark'] === false && !array_key_exists('accent_dark', $fv),
    'применение сбрасывает всё, чего в схеме нет: схема не смешивается с тем, что было в форме');
for ($i = 0; $i < 30; $i++) LookSchemes::save('Схема ' . $i, ['accent' => '#1D4ED8']);
ok(count(LookSchemes::all()) === 24, 'не больше 24 схем');
$id = LookSchemes::all()[0]['id'];
ok(LookSchemes::remove($id) && count(LookSchemes::all()) === 23 && !LookSchemes::remove($id), 'схема убирается, дважды — нет');

echo "\n── В редакторе — у сайта и у порталов ──\n";
Setting::delete('look.schemes');
LookSchemes::save('Морская', $full);
$site = View::fetch('admin/_look_editor', ['scope' => 'site', 'theme' => [], 'previewUrl' => '/admin/appearance/preview', 'before' => '', 'paneExtra' => '']);
$portal = View::fetch('admin/_look_editor', ['scope' => 'portal', 'theme' => [], 'previewUrl' => '/admin/clients/1/look/preview', 'before' => '', 'paneExtra' => '']);
ok(str_contains($site, 'data-look-mine=') && str_contains($portal, 'data-look-mine=') && str_contains($portal, 'title="Морская"'),
    'своя схема плиткой и в редакторе сайта, и в редакторе портала');
ok((bool)preg_match('~data-look-adopt="[^"]*&quot;button&quot;:&quot;soft&quot;[^"]*&quot;control&quot;:&quot;large&quot;~', $site),
    'щелчок по плитке подставляет и палитру, и форму');
foreach (['weight_heading', 'weight_button', 'button', 'field_border', 'control', 'shadows'] as $k) {
    if (!str_contains($site, 'name="look[' . $k . ']"')) ok(false, "в редакторе есть поле $k");
}
ok(true, 'в редакторе все шесть полей конструктора');
$js = (string)file_get_contents(SSO_ROOT . '/public/assets/js/app.js');
ok(str_contains($js, "post('/admin/look/schemes', fd)") && str_contains($js, "post('/admin/look/schemes/drop', dfd)")
    && str_contains($js, "mineList.insertAdjacentHTML('beforeend', j.html);"), 'сохранить и убрать — без перезагрузки: несохранённая форма цела');
$routes = (string)file_get_contents(SSO_ROOT . '/app/routes.php');
ok(str_contains($routes, "'/admin/look/schemes'") && str_contains($routes, "'/admin/look/schemes/drop'"), 'маршруты схем на месте');

echo "\n── Тема для новых, узор фона, карточки (v4.80) ──\n";
$ok = Bridge::sanitize(['theme_default' => 'dark', 'pattern' => 'grid', 'card' => 'glass'], 'site');
$bad = Bridge::sanitize(['theme_default' => 'sepia', 'pattern' => 'url(x)', 'card' => 'neon'], 'site');
ok(($ok['theme_default'] ?? '') === 'dark' && ($ok['pattern'] ?? '') === 'grid' && ($ok['card'] ?? '') === 'glass'
    && array_intersect_key($bad, ['theme_default' => 1, 'pattern' => 1, 'card' => 1]) === [],
    'три новых параметра — тоже только из списка');
Setting::set('look.theme', (string)json_encode(['pattern' => 'grid', 'card' => 'flat']));
Setting::flush();
$css = Bridge::themeStyle(null);
ok(str_contains($css, '--pattern:repeating-linear-gradient(0deg,var(--pat) 0 1px,transparent 1px 28px)')
    && str_contains($css, '.bento-card,.auth-card,.pcard{box-shadow:none}'), 'сетка — токеном узора, плоские карточки — без тени');
Setting::set('look.theme', (string)json_encode(['pattern' => 'lines', 'card' => 'glass']));
Setting::flush();
$css = Bridge::themeStyle(null);
ok(str_contains($css, '--pattern:repeating-linear-gradient(-45deg,var(--pat)') && str_contains($css, 'backdrop-filter:blur(16px)')
    && str_contains($css, 'background:color-mix(in srgb,var(--surface) 72%,transparent)'), 'линии и стекло');
Setting::set('look.theme', (string)json_encode(['accent' => '#A53718']));
Setting::flush();
$css = Bridge::themeStyle(null);
ok(!str_contains($css, '--pattern:') && !str_contains($css, 'backdrop-filter') && Bridge::defaultTheme(null) === null,
    'не выбрано — узора нет, карточки и тема как у установки');
$tokens = (string)file_get_contents(SSO_ROOT . '/public/assets/css/tokens.css');
$ident = (string)file_get_contents(SSO_ROOT . '/public/assets/css/identity.css');
ok(str_contains($tokens, '--pattern: none;') && str_contains($tokens, '--pat: color-mix(in srgb, var(--text) 7%, transparent);')
    && str_contains($app, 'background-image: var(--pattern); background-attachment: fixed;')
    && substr_count($ident, 'background-image: var(--pattern), var(--wash);') === 2,
    'узор ложится на фон кабинета, главной и экрана входа; по умолчанию его нет');

Setting::set('look.theme', (string)json_encode(['theme_default' => 'dark']));
Setting::flush();
$guest = View::fetch('layouts/guest', ['content' => '', 'portals' => [], 'pageTitle' => 'Тема']);
ok(Bridge::defaultTheme(null) === 'dark' && str_contains($guest, '<html lang="ru" data-theme-default="dark">'),
    'тема для новых посетителей — атрибутом на странице');
$scriptOk = [];
foreach (['base', 'guest', 'auth', 'minimal'] as $l) {
    $src = (string)file_get_contents(SSO_ROOT . "/templates/layouts/$l.php");
    if (str_contains($src, "localStorage.getItem('sso-theme')||localStorage.getItem('tsoy-theme')||document.documentElement.getAttribute('data-theme-default')||'auto'")) $scriptOk[] = $l;
}
ok(count($scriptOk) === 4 && str_contains($js, "var fallback = document.documentElement.getAttribute('data-theme-default') || 'auto';"),
    'выбор человека главнее: сохранённая тема, потом тема установки, потом устройство — во всех макетах и в переключателе',
    implode(', ', $scriptOk));
/* Явно, а не по умолчанию: сохранение оформления в смоуке без галочки
   оставляет в базе «0» (батарея 25.09). Прежнее значение вернёт выход. */
Setting::set('appearance.look_everywhere', '1');
Setting::flush();
$minOn = View::fetch('layouts/minimal', ['content' => '', 'pageTitle' => 'Ошибка']);
Setting::set('appearance.look_everywhere', '0');
Setting::flush();
$minOff = View::fetch('layouts/minimal', ['content' => '', 'pageTitle' => 'Ошибка']);
ok(str_contains($minOn, 'data-theme-default="dark"') && !str_contains($minOff, 'data-theme-default="'),
    'системные страницы берут тему установки, только если оформление — на весь сайт');
Setting::set('look.theme', (string)json_encode(['accent' => '#A53718']));
Setting::flush();

foreach (['theme_default', 'pattern', 'card'] as $k) {
    if (!str_contains($site, 'name="look[' . $k . ']"')) ok(false, "в редакторе есть поле $k");
}
ok(true, 'в редакторе поля темы для новых, узора и карточек');

echo "\n── Портал поверх сайта (v4.82) ──\n";
/* Боевая 25.09: у Календаря событий в манифесте один акцент — и на его входе не было ни
   скругления, ни шрифтов, ни стекла сайта. Своя тема портала заменяла тему
   сайта целиком. */
$sea = Bridge::PRESETS['sea'][1];
Setting::set('look.theme', (string)json_encode(['radius' => 16, 'font' => 'system', 'font_display' => 'system', 'card' => 'glass',
    'pattern' => 'grid', 'layout' => 'right', 'theme_default' => 'dark', 'wash' => false,
    'accent' => '#0B6E99', 'background' => $sea['background'], 'surface' => $sea['surface'], 'text' => $sea['text'],
    'accent_dark' => $sea['accent_dark'], 'background_dark' => $sea['background_dark'], 'surface_dark' => $sea['surface_dark'], 'text_dark' => $sea['text_dark']]));
Setting::flush();
$kz = ['id' => 990001, 'name' => 'Акцент', 'look_mode' => 'portal', 'brand_accent' => '#D1351C'];
$t = Bridge::look($kz)['theme'];
ok(($t['radius'] ?? 0) === 16 && ($t['font'] ?? '') === 'system' && ($t['font_display'] ?? '') === 'system' && ($t['card'] ?? '') === 'glass'
    && ($t['pattern'] ?? '') === 'grid' && ($t['layout'] ?? '') === 'right' && ($t['theme_default'] ?? '') === 'dark' && ($t['wash'] ?? null) === false,
    'портал с одним акцентом получает скругление, шрифты, стекло, узор, раскладку и тему сайта', json_encode($t));
ok(($t['accent'] ?? '') === '#D1351C' && ($t['background'] ?? '') === $sea['background'] && ($t['surface_dark'] ?? '') === $sea['surface_dark']
    && !isset($t['accent_dark']), 'акцент — портала, подложки — сайта; тёмный акцент выводится из акцента портала, а не берётся у сайта');
$css = Bridge::themeStyle($kz);
ok(str_contains($css, '--radius-s:16px') && str_contains($css, 'backdrop-filter:blur(16px)') && str_contains($css, '--pattern:repeating-linear-gradient(0deg')
    && str_contains($css, '--font-ui:' . Bridge::fontStack('system')), 'и до экрана входа портала это доходит стилем');
$cv = ['id' => 990002, 'name' => 'Своё', 'look_mode' => 'custom', 'look_theme' => json_encode(['radius' => 6, 'font' => 'inter',
    'background' => '#F1ECDC', 'surface' => '#FFFFFF', 'text' => '#1E1812', 'headline' => 'Цитата портала', 'layout' => 'left'])];
$t = Bridge::look($cv)['theme'];
ok(($t['radius'] ?? 0) === 6 && ($t['font'] ?? '') === 'inter' && ($t['layout'] ?? '') === 'left' && ($t['background'] ?? '') === '#F1ECDC'
    && ($t['accent'] ?? '') === '#0B6E99' && ($t['background_dark'] ?? '') === $sea['background_dark'] && ($t['card'] ?? '') === 'glass'
    && ($t['headline'] ?? '') === 'Цитата портала', 'заданное порталом главнее; его светлая палитра не смешивается с сайтовой, тёмная — от сайта', json_encode($t));
$half = ['id' => 990003, 'name' => 'Пол', 'look_mode' => 'custom', 'look_theme' => json_encode(['background' => '#FFFFFF'])];
ok(!isset(Bridge::look($half)['theme']['surface']), 'палитра, заданная порталом частично, сайтом не дополняется — она не применится с причиной, а не соберётся из двух');
$off = ['id' => 990004, 'name' => 'Родное', 'look_mode' => 'off'];
ok(Bridge::look($off)['source'] === 'site' && Bridge::look($off)['theme'] === Bridge::siteLook(), 'портал на виде установки — как было: оформление сайта');
Setting::delete('look.theme');
Setting::flush();
ok(Bridge::look($kz)['theme'] === ['accent' => '#D1351C'], 'у сайта своего оформления нет — у портала только своё');

echo "\n── Чей дизайн главнее на входе в порталы (v4.85) ──\n";
/* Боевая 26.09: Портфолио присылает манифестом палитру, скругление 6, Inter и
   Garamond — и от «Оформления сайта» до его входа доходило одно стекло. */
$policyWas = Setting::get('appearance.portal_look');
Setting::delete('appearance.portal_look');
Setting::set('look.theme', (string)json_encode(['radius' => 16, 'font' => 'system', 'font_display' => 'system', 'card' => 'glass', 'layout' => 'left', 'accent' => '#1F6FEB', 'background' => '#EEF5F8', 'surface' => '#FFFFFF', 'text' => '#0F1D24']));
Setting::flush();
$cvLike = ['id' => 990005, 'name' => 'Похожий на Портфолио', 'look_mode' => 'portal', 'brand_theme' => json_encode([
    'accent' => '#A53718', 'background' => '#F1ECDC', 'surface' => '#FFFFFF', 'text' => '#0E0B07',
    'radius' => 6, 'font' => 'inter', 'font_display' => 'garamond', 'pattern' => 'lines', 'headline' => 'Цитата портала'])];
ok(\App\Core\Appearance::portalLook() === 'site', 'по умолчанию дизайн сайта главнее');
$t = Bridge::look($cvLike)['theme'];
ok(($t['radius'] ?? 0) === 16 && ($t['font'] ?? '') === 'system' && ($t['font_display'] ?? '') === 'system' && ($t['card'] ?? '') === 'glass',
    'скругление, шрифты и карточки — сайта, даже когда портал прислал свои', json_encode($t));
ok(!isset($t['pattern']), 'узора у сайта нет — нет его и на входе портала: чего сайт не задал, стоит по умолчанию');
ok(($t['accent'] ?? '') === '#A53718' && ($t['background'] ?? '') === '#F1ECDC' && ($t['headline'] ?? '') === 'Цитата портала',
    'цвета и цитата — портала: вход в него узнаётся');
Setting::set('appearance.portal_look', 'portal');
Setting::flush();
$t = Bridge::look($cvLike)['theme'];
ok(($t['radius'] ?? 0) === 6 && ($t['font'] ?? '') === 'inter' && ($t['pattern'] ?? '') === 'lines' && ($t['card'] ?? '') === 'glass',
    '«Портал главнее» — как в 4.82: его — его, остальное от сайта');
Setting::set('appearance.portal_look', 'only');
Setting::flush();
ok(Bridge::look($cvLike)['source'] === 'site' && Bridge::look($cvLike)['theme'] === Bridge::siteLook(), '«Только дизайн сайта» — портал выглядит как вход в установку');
$ownCard = ['id' => 990006, 'name' => 'Своё', 'look_mode' => 'custom', 'look_theme' => json_encode(['radius' => 6, 'font' => 'inter'])];
Setting::set('appearance.portal_look', 'site');
Setting::flush();
ok((Bridge::look($ownCard)['theme']['radius'] ?? 0) === 6 && (Bridge::look($ownCard)['theme']['font'] ?? '') === 'inter',
    'оформление, заданное в карточке портала («Своё»), выбор не трогает — его настроили здесь же руками');
Setting::set('appearance.portal_look', 'чужое');
Setting::flush();
ok(\App\Core\Appearance::portalLook() === 'site', 'мусор в настройке — как по умолчанию');
$policyWas === null ? Setting::delete('appearance.portal_look') : Setting::set('appearance.portal_look', $policyWas);
Setting::flush();
$appearanceTpl = (string)file_get_contents(SSO_ROOT . '/templates/admin/appearance.php');
ok(str_contains($appearanceTpl, "field_choices('portal_look', t('Вход в порталы'), [")
    && str_contains((string)file_get_contents(SSO_ROOT . '/app/Controllers/Admin/AppearanceController.php'), "if (in_array(\$_POST['portal_look'] ?? '', Appearance::PORTAL_LOOKS, true)) {"),
    'выбор — в «Оформлении сайта», сохраняется только из списка');
Setting::set('look.theme', (string)json_encode(['radius' => 16, 'background' => '#EEF5F8', 'surface' => '#FFFFFF', 'text' => '#0F1D24']));
Setting::flush();
$portalEd = View::fetch('admin/_look_editor', ['scope' => 'portal', 'theme' => [], 'previewUrl' => '/admin/clients/1/look/preview', 'before' => '', 'paneExtra' => '']);
ok(str_contains($portalEd, 'placeholder="#EEF5F8"') && str_contains($portalEd, 'placeholder="#0F1D24"'),
    'в редакторе портала пустое поле подсказывает цвет сайта — его и получит портал');
Setting::set('look.theme', (string)json_encode(['accent' => '#A53718']));
Setting::flush();

echo "\n── Интерфейс редактора (v4.80) ──\n";
ok(str_contains($app, '.choices-seg .choices { display: flex; flex-wrap: wrap; gap: 6px; }')
    && str_contains($app, '.choices-seg .choice-title { white-space: nowrap; }')
    && substr_count($site, 'field-group choices-seg') === 7,
    'короткие варианты — рядом пилюль, подпись целиком: «Обычная», а не «Обычна/я»');
$appear = (string)file_get_contents(SSO_ROOT . '/templates/admin/appearance.php');
ok((bool)preg_match('~<div class="font-add-row">\s*<input class="input" id="f-family"[^>]*>\s*<button class="btn btn-primary" type="submit">~', $appear)
    && str_contains($app, '.font-add-row { display: flex; gap: 8px; align-items: center; }'),
    'поле шрифта и кнопка «Установить» — одной строкой, подсказка под обоими');
ok(str_contains($app, '.look-schemes { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-top: 8px; align-items: start; }'),
    'выключенная тёмная тема не тянется пустым прямоугольником на высоту светлой');
ok(str_contains($app, '.page-head + .page-lede { margin-top: -12px; }')
    && str_contains((string)file_get_contents(SSO_ROOT . '/templates/admin/settings_shell.php'), '<p class="muted mb-16 page-lede">'),
    'пояснение раздела — под заголовком, а не абзацем в 40px от него');
ok(!str_contains($appear, "t('К брендингу →')") && str_contains((string)file_get_contents(SSO_ROOT . '/app/Controllers/Admin/AppearanceController.php'), "esc(t('К брендингу →'))"),
    'ссылка на брендинг — кнопкой в шапке, второго пояснения под первым нет');
ok(str_contains($app, '.checkbox .hint.mono { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;'),
    'адрес портала в списке не рвётся на дефисе');
ok(str_contains($app, '.choices-rows .choice-body { gap: 2px; padding: 8px 12px; }') && !preg_match('~\.choices-rows \.choice-body \{[^}]*flex-direction: row~', $app),
    'варианты строками — подпись над пояснением у всех, а не «короткий в строку, длинный в две»');

echo "\n── Предпросмотр не закрывает настройки ──\n";
ok(str_contains($app, '.look-preview { position: static; min-width: 0; order: -1; }')
    && str_contains($app, '.look-preview { order: 0; position: sticky; top: calc(var(--isle-h, 56px) + 24px); }'),
    'прилипает только рядом с настройками, а в одну колонку стоит на месте');

echo "\n" . ($failed === 0 ? "✓ все $passed проверок" : "✗ провалов: $failed из " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
