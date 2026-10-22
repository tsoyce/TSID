<?php
/**
 * Экран входа настраивается здесь (v4.71).
 *
 * Владелец попросил, чтобы оформление входа порталов, как у Портфолио, задавалось в
 * самом SSO, а не только манифестом портала. Появился раздел «Экран входа» в
 * карточке портала и тот же редактор в «Оформлении сайта». Проверяем, что:
 *   · разбор один для манифеста, карточки и установки — и каждому своё: портал
 *     не пришлёт обложку, установка не держит цитату;
 *   · режим решает, что действует: присланное порталом, своё или вид установки;
 *     обновление манифеста своё оформление не трогает;
 *   · сохранение — настоящим POST администратора; обложка перекодируется и
 *     удаляется вместе с порталом; «ни одного способа входа» не сохраняется;
 *   · предпросмотр рисует настоящий экран входа с черновиком, во фрейм его
 *     пускают только свои страницы, и в базе он ничего не меняет;
 *   · на живом экране входа — своё оформление, раскладка, подпись, обложка и
 *     только выбранные способы входа; экран установки — её оформление;
 *   · палитра из одного цвета держит контраст обеих тем;
 *   · в стиле нет строк от людей — только hex, числа и наши стеки шрифтов.
 *
 * Запуск: php tests/login_look.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Bridge;
use App\Core\DB;
use App\Core\LoginLook;
use App\Core\Palette;
use App\Core\Setting;
use App\Models\Client;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$JAR = sys_get_temp_dir() . '/login-look.txt';
@unlink($JAR);
$stamp = bin2hex(random_bytes(3));
$cid = 0;
$siteWas = Setting::get('look.theme');
$policyWas = Setting::get('appearance.portal_look');
$socialFirstWas = Setting::get('appearance.social_first');

DB::run('UPDATE {users} SET totp_secret = NULL, totp_enabled_at = NULL WHERE email = ?', ['admin@test.local']);
DB::run('DELETE FROM {rate_limits}');
[, $lp] = http('GET', SSO . '/login');
http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => 'admin@test.local',
    'password' => 'correct-horse-battery']);

/** Живые значения Портфолио — те же, что в portal_theme.php. */
$cv = [
    'accent' => '#A53718', 'accent_dark' => '#FF8054',
    'background' => '#F1ECDC', 'background_dark' => '#16171A',
    'surface' => '#FFFFFF', 'surface_dark' => '#1E1F23',
    'text' => '#0E0B07', 'text_dark' => '#F4F4F0',
];
/** Поля формы редактора из темы. */
$form = static function (array $t, array $extra = []): array {
    $f = ['look[dark]' => '0', 'look[wash]' => '1'];
    foreach ($t as $k => $v) {
        if (str_ends_with($k, '_dark')) $f['look[dark]'] = '1';
        $f['look[' . $k . ']'] = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
    }
    return $extra + $f;
};

try {
    [$cid, $clientId] = Client::create([
        'name' => 'Проба входа', 'slug' => 'look-' . $stamp, 'base_url' => 'https://look-' . $stamp . '.example',
        'redirect_uris' => ['https://look-' . $stamp . '.example/cb'],
        'post_logout_uris' => ['https://look-' . $stamp . '.example/'],
        'backchannel_uri' => null, 'is_trusted' => 1, 'access_policy' => 'open',
    ]);
    $cid = (int)$cid;

    echo "── Разбор один, но каждому своё ──\n";
    $raw = $cv + ['radius' => 6, 'font' => 'system', 'font_display' => 'garamond', 'layout' => 'right',
        'headline' => 'Цитата', 'tagline' => 'Подпись', 'cover' => '123', 'wash' => false];
    $m = Bridge::sanitize($raw, 'manifest');
    ok(($m['layout'] ?? '') === 'right' && ($m['font'] ?? '') === 'system' && !isset($m['cover']) && !isset($m['tagline']),
        'манифест: раскладка и шрифт устройства — да, обложки и подписи в теме — нет');
    $p = Bridge::sanitize($raw, 'portal');
    /* С 4.81 обложка — отдельным набором (CoverSet), номер в теме не хранится. */
    ok(!isset($p['cover']) && ($p['tagline'] ?? '') === 'Подпись', 'карточка портала: подпись — да, обложка — не номером в теме, а набором');
    $s = Bridge::sanitize($raw, 'site');
    ok(!isset($s['headline']) && !isset($s['tagline']) && !isset($s['cover']) && ($s['layout'] ?? '') === 'right',
        'установка: без цитаты, подписи и обложки — у её панели свои поля');
    ok(Bridge::sanitize(['layout' => 'diagonal', 'cover' => '../../x', 'font' => 'garamond'], 'portal') === [],
        'чужая раскладка, путь вместо версии обложки, антиква для интерфейса — отброшены');

    echo "\n── Режим решает, что действует ──\n";
    Bridge::storeBranding(Client::find($cid), $cv + ['headline' => 'От портала']);
    ok(Bridge::look(Client::find($cid))['source'] === 'portal', 'по умолчанию — как прислал портал');
    Client::update($cid, ['look_mode' => 'custom', 'look_theme' => json_encode(['accent' => '#0B6E4F'])]);
    $look = Bridge::themeOf(Client::find($cid));
    ok($look['source'] === 'custom' && $look['light']['ink'] !== null && $look['headline'] === null,
        'своё: действует заданное здесь, присланное порталом — нет');
    ok(str_contains(Bridge::themeStyle(Client::find($cid)), '--ink:#0B6E4F'),
        'один акцент без палитры — кнопки в цвете портала на родном фоне');
    Bridge::storeBranding(Client::find($cid), ['background' => '#000000', 'surface' => '#111111', 'text' => '#FFFFFF']);
    ok(Bridge::look(Client::find($cid))['source'] === 'custom', 'обновление манифеста своё оформление не перебивает');
    Setting::set('look.theme', (string)json_encode(['layout' => 'none', 'radius' => 2]));
    Setting::flush();
    Client::update($cid, ['look_mode' => 'off']);
    $off = Bridge::look(Client::find($cid));
    ok($off['source'] === 'site' && Bridge::layout(Client::find($cid)) === 'none',
        '«как у сайта» — вид установки, а не присланное порталом');
    Setting::set('look.theme', (string)json_encode([]));
    Setting::flush();

    echo "\n── Сохранение из карточки ──\n";
    [$st, $page] = http('GET', SSO . "/admin/clients/$cid/look");
    ok($st === 200 && str_contains($page, 'data-look-editor') && str_contains($page, 'name="look_mode"'),
        'раздел «Экран входа» открывается с редактором', "st=$st");
    [, , , , $hdr] = http('GET', SSO . "/admin/clients/$cid/look");
    ok((bool)preg_match("~frame-src[^;]*'self'~", $hdr), 'странице редактора разрешён фрейм со своего адреса');
    $csrf = csrfOf($page);

    $png = sys_get_temp_dir() . '/look-cover-' . $stamp . '.png';
    $im = imagecreatetruecolor(320, 200);
    imagefill($im, 0, 0, (int)imagecolorallocate($im, 165, 55, 24));
    imagepng($im, $png);
    imagedestroy($im);
    [$st, , $loc] = upload(SSO . "/admin/clients/$cid/look", $form($cv + ['radius' => 6, 'font' => 'inter',
        'font_display' => 'garamond', 'layout' => 'right', 'headline' => 'Резюме — это разговор',
        'headline_note' => '— Портфолио', 'tagline' => 'Своя подпись'], [
        '_csrf' => $csrf, 'look_mode' => 'custom', 'methods_mode' => 'some', 'methods[]' => 'password',
        'cover_kind' => 'images',
    ]), ['cover_add[0]' => [$png, 'image/png', 'cover.png']]);
    $row = Client::find($cid);
    $saved = json_decode((string)$row['look_theme'], true) ?: [];
    ok($st === 302 && $row['look_mode'] === 'custom' && ($saved['background'] ?? '') === '#F1ECDC'
        && ($saved['layout'] ?? '') === 'right' && ($saved['tagline'] ?? '') === 'Своя подпись',
        'сохранено: режим, палитра, раскладка, подпись', "st=$st loc=$loc");
    ok($row['login_methods'] === 'password', 'способы входа портала — только выбранные');
    /* С 4.81 обложка — набором (CoverSet), тем же, что у установки. */
    \App\Core\Setting::flush();
    $coverSet = \App\Core\CoverSet::get($cid);
    $coverFile = $coverSet['images'] !== [] ? \App\Core\CoverSet::path('c' . $cid, $coverSet['images'][0], 'webp') : '';
    ok(!isset($saved['cover']) && $coverFile !== '' && is_file($coverFile) && substr((string)file_get_contents($coverFile), 8, 4) === 'WEBP',
        'обложка перекодирована в webp и лежит нашей копией — в наборе, а не номером в теме');
    [$st, , , $ctype] = http('GET', (string)Bridge::coverUrl(Client::find($cid)));
    ok($st === 200 && str_contains($ctype, 'image/webp'), 'обложку отдаёт наш адрес');

    [$st] = http('POST', SSO . "/admin/clients/$cid/look", $form($cv, [
        '_csrf' => $csrf, 'look_mode' => 'custom', 'methods_mode' => 'some']));
    ok(Client::find($cid)['login_methods'] === 'password', 'ни одного способа входа — не сохраняется');

    echo "\n── Предпросмотр ──\n";
    $q = http_build_query(['look_mode' => 'custom', 'look' => ['background' => '#EFEFEA', 'surface' => '#FFFFFF',
        'text' => '#101010', 'layout' => 'left'], 'scheme' => 'dark']);
    [$st, $pv, , , $pvh] = http('GET', SSO . "/admin/clients/$cid/look/preview?$q");
    ok($st === 200 && str_contains($pv, '--bg:#EFEFEA') && str_contains($pv, 'data-theme="dark"'),
        'предпросмотр рисует черновик и нужную тему', "st=$st");
    ok(str_contains($pv, ' inert') && str_contains($pv, 'name="look-why"'), 'в предпросмотре ничего не нажать, причины — в meta');
    ok((bool)preg_match("~frame-ancestors 'self'~", $pvh) && (bool)preg_match('~X-Frame-Options:\s*SAMEORIGIN~i', $pvh),
        'предпросмотр встраивают только свои страницы');
    ok((json_decode((string)Client::find($cid)['look_theme'], true)['background'] ?? '') === '#F1ECDC',
        'предпросмотр в базе ничего не меняет');
    [, $bad] = http('GET', SSO . "/admin/clients/$cid/look/preview?" . http_build_query([
        'look_mode' => 'custom', 'look' => ['background' => '#9A9A9A', 'surface' => '#A0A0A0', 'text' => '#7A7A7A']]));
    ok(str_contains(html_entity_decode($bad), '4.5'), 'непригодная палитра — причина видна редактору');
    $anon = sys_get_temp_dir() . '/login-look-anon.txt';
    @unlink($anon);
    [$st] = http('GET', SSO . "/admin/clients/$cid/look/preview", [], [], $anon);
    ok($st !== 200, 'без входа в админку предпросмотра нет', "st=$st");
    [, , , , $loginHdr] = http('GET', SSO . '/login', [], [], $anon);
    ok((bool)preg_match("~frame-ancestors 'none'~", $loginHdr) && (bool)preg_match('~X-Frame-Options:\s*DENY~i', $loginHdr),
        'живой экран входа во фрейм не пускают по-прежнему');

    echo "\n── Живой экран входа из портала ──\n";
    DB::run('DELETE FROM {rate_limits}');
    $v = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    [, , $loc] = http('GET', SSO . '/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => 'https://look-' . $stamp . '.example/cb',
        'scope' => 'openid', 'state' => 's', 'nonce' => 'n',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256']), [], [], $anon);
    [, $login] = http('GET', str_starts_with((string)$loc, 'http') ? (string)$loc : SSO . $loc, [], [], $anon);
    ok(str_contains($login, '--bg:#F1ECDC') && str_contains($login, 'is-pane-right'), 'своё оформление и панель справа');
    ok(str_contains($login, 'Своя подпись') && str_contains($login, "/cover/c$cid-"), 'своя подпись и обложка');
    ok(str_contains($login, 'name="identity"') && !str_contains($login, '/login/link')
        && !str_contains($login, 'data-passkey-login'), 'только выбранный способ входа — пароль');
    ok(str_contains($login, 'connector-node') && str_contains($login, 'portal-pane-foot'),
        'знак SSO над формой и на панели остаётся');

    echo "\n── Оформление сайта ──\n";
    [, $ap] = http('GET', SSO . '/admin/appearance');
    $apPost = $form(['background' => '#F4F1EA', 'surface' => '#FFFFFF', 'text' => '#1A1712', 'layout' => 'right'], [
        '_csrf' => csrfOf($ap), 'section' => 'login', 'showcase' => '1',
        'method_password' => '1', 'method_magic' => '1', 'method_passkey' => '1', 'method_social' => '1',
        'media_title' => '', 'media_text' => '']);
    [$st] = http('POST', SSO . '/admin/appearance/save', $apPost);
    [, $plain] = http('GET', SSO . '/login', [], [], sys_get_temp_dir() . '/login-look-plain.txt');
    ok($st === 302 && str_contains($plain, '--bg:#F4F1EA') && str_contains($plain, 'is-pane-right'),
        'вход в установку — в её оформлении', "st=$st");
    [$st, $sp] = http('GET', SSO . '/admin/appearance/preview?' . http_build_query([
        'look' => ['layout' => 'none'], 'method_password' => '1', 'media_title' => 'Черновик панели']));
    ok($st === 200 && str_contains($sp, 'is-pane-none') && !str_contains($sp, '/login/link'),
        'предпросмотр сайта — из формы: раскладка и состав способов');

    /* Чей дизайн главнее на входе в порталы (v4.85): выбор из трёх; чужое
       значение не сохраняется, а форма без поля его не сбрасывает. */
    Setting::delete('appearance.portal_look');
    Setting::flush();
    [, $ap] = http('GET', SSO . '/admin/appearance');
    ok(str_contains($ap, 'name="portal_look" value="site" checked'), 'по умолчанию отмечено «Дизайн сайта главнее»');
    $apPost['_csrf'] = csrfOf($ap);
    http('POST', SSO . '/admin/appearance/save', $apPost + ['portal_look' => 'only']);
    http('POST', SSO . '/admin/appearance/save', $apPost + ['portal_look' => 'чужое']);
    http('POST', SSO . '/admin/appearance/save', $apPost);
    Setting::flush();
    [, $ap] = http('GET', SSO . '/admin/appearance');
    ok(Setting::get('appearance.portal_look') === 'only' && str_contains($ap, 'name="portal_look" value="only" checked'),
        '«Только дизайн сайта» сохраняется; чужое значение и форма без поля его не сбивают');

    /* «Сервисы — первыми» (v4.86): тумблер в «Способах входа на экране». */
    ok(str_contains($ap, 'name="social_first"'), 'тумблер «Сервисы — первыми» — в способах входа');
    $apPost['_csrf'] = csrfOf($ap);
    http('POST', SSO . '/admin/appearance/save', $apPost + ['social_first' => '1']);
    Setting::flush();
    $on = Setting::get('appearance.social_first');
    http('POST', SSO . '/admin/appearance/save', $apPost);
    Setting::flush();
    ok($on === '1' && Setting::get('appearance.social_first') === '0', 'включается и выключается формой', "было $on");

    echo "\n── Палитра из одного цвета ──\n";
    [$st, $pj] = http('GET', SSO . '/admin/look/palette?accent=A53718');
    $pal = json_decode($pj, true) ?: [];
    ok($st === 200 && count($pal) === 8, 'восемь цветов обеих тем');
    /* Замер на разных цветах, а не на одном: тёмно-красное Портфолио держит контраст
       почти при любой формуле, а жёлтый и бирюзовый ломают её первыми
       (мутация 97 была слепа, пока мерили только на Портфолио). */
    $worstText = [];
    $worstInk = 99.0;
    foreach (['A53718', 'F4C542', '22D3EE', '1D4ED8', '16A34A'] as $seed) {
        [, $pj] = http('GET', SSO . '/admin/look/palette?accent=' . $seed);
        $p = json_decode($pj, true) ?: [];
        foreach (['' => false, '_dark' => true] as $sfx => $dark) {
            $bg = $p['background' . $sfx] ?? '#000000';
            $grounds = [$bg, $p['surface' . $sfx] ?? $bg, Palette::blend($p['surface' . $sfx] ?? $bg, $bg, 0.5)];
            $w = min(array_map(static fn($g) => Palette::contrast($p['text' . $sfx] ?? $bg, $g), $grounds));
            $key = $dark ? 'тёмная' : 'светлая';
            $worstText[$key] = min($worstText[$key] ?? 99.0, $w);
        }
        $worstInk = min($worstInk, Palette::contrast($p['accent_dark'] ?? '#000000', $p['background_dark'] ?? '#000000'));
    }
    foreach ($worstText as $key => $w) {
        ok($w >= 7.0, "$key: текст не ниже 7 на всех подложках — для пяти разных цветов", sprintf('худший %.2f', $w));
    }
    ok($worstInk >= 3.0, 'тёмный акцент заметен на тёмном фоне — для всех пяти', sprintf('худший %.2f', $worstInk));
    [$st] = http('GET', SSO . '/admin/look/palette?accent=red;}');
    ok($st === 400, 'не цвет — не палитра');

    echo "\n── В стиле нет строк от людей ──\n";
    DB::update('clients', ['look_theme' => json_encode([
        'background' => '#FFFFFF;}body{display:none', 'surface' => '#FFFFFF', 'text' => '#111111',
        'font' => "inter';}body{display:none}", 'layout' => 'left" onload="x', 'cover' => '../../config',
    ])], 'id = ?', [$cid]);
    $forged = Bridge::themeStyle(Client::find($cid));
    ok(!str_contains($forged, 'display:none') && !str_contains($forged, 'body'), 'значения из базы разобраны заново');
    $forgedCover = Bridge::coverUrl(Client::find($cid));
    /* Отброшенная раскладка — как у сайта (4.82), а не обязательно «слева». */
    ok(Bridge::layout(Client::find($cid)) === Bridge::layout(null) && ($forgedCover === null || preg_match('~/cover/c' . $cid . '-[0-9a-f]{12}\.webp$~', $forgedCover) === 1),
        'подделанные раскладка и обложка — отброшены');

    echo "\n── Манифест из своего оформления ──\n";
    $json = json_decode(LoginLook::manifestJson(Bridge::sanitize($saved, 'portal')), true);
    ok(isset($json['branding']) && !isset($json['branding']['cover'])
        && ($json['branding']['tagline'] ?? '') === 'Своя подпись'
        && Bridge::sanitize($json['branding']) === array_diff_key(Bridge::sanitize($saved, 'manifest'), []),
        'блок branding проходит разбор манифеста без потерь, обложки в нём нет');

    echo "\n── Экраны после ревизии интерфейса ──\n";
    [, $users] = http('GET', SSO . '/admin/users');
    ok(str_contains($users, '<noscript>') && !preg_match('~badge-muted mono">(user|superadmin)<~', $users),
        'пользователи: роли названиями, фильтр без лишней кнопки');
    [, $logs] = http('GET', SSO . '/admin/logs');
    ok(str_contains($logs, '<option value="admin.">') && !str_contains($logs, 'login. / admin. / token.'),
        'журнал: события выбирают группами словами');
    [, $services] = http('GET', SSO . '/profile/services');
    ok(str_contains($services, 'list-card') && !str_contains($services, '>подключён<'),
        'мои сервисы: плитка щёлкается целиком, «подключён» на каждой не стоит');

    /* Живой случай 24.09: быстрые щелчки по карточкам роняли вкладку владельца
       (STATUS_BREAKPOINT), а «Назад» перебирал состояния предпросмотра. Поведение
       фрейма здесь не прогнать — нет браузера; сверяем устройство редактора. */
    /* 4.73.1: и переходы по одному не спасли — семь дампов Chrome 153 в одном
       месте, в процессе страницы четыре расширения, внедряющие скрипты в каждый
       новый документ фрейма. Предпросмотр больше не создаёт документов вовсе. */
    echo "\n── Предпросмотр: без новых документов во фрейме, история не растёт ──\n";
    $appJs = (string)file_get_contents(SSO_ROOT . '/public/assets/js/app.js');
    $from = (int)strpos($appJs, "document.querySelectorAll('[data-look-editor]')");
    $editor = substr($appJs, $from, (int)strpos($appJs, '---------- Карта ролей', $from) - $from);
    ok(!str_contains($editor, 'location.replace') && !preg_match('~frame\.src\s*=~', $editor)
        && !str_contains($editor, 'location.href'), 'фрейм не переходит никуда после первой загрузки');
    ok(str_contains($editor, 'new DOMParser().parseFromString(html') && str_contains($editor, 'doc.body.innerHTML = next.body.innerHTML'),
        'изменения формы подменяют экран в том же документе');
    ok(str_contains($editor, 'if (busy) { again = true; return; }') && str_contains($editor, 'if (again) refresh();'),
        'пока фрейм грузится, новый адрес ждёт, а не обрывает загрузку');
    ok(str_contains($editor, "if (url === current) return;"), 'тот же адрес второй раз не грузится');
    /* 4.74: app.js во фрейме предпросмотра применял тему администратора и
       красил «светлый» экран в тёмный до первой подмены документа. */
    ok((bool)preg_match('~var isPreview = !!document\.querySelector\(\'meta\[name="look-why"\]\'\);\s*if \(!isPreview\) \{\s*applyTheme\(themeGet\(\)\);~', $appJs)
        && substr_count($appJs, 'applyTheme(themeGet());') === 2,
        'в предпросмотре тема администратора не применяется — схема та, что выбрана в редакторе');

    echo "\n── Портал удалён — обложки нет ──\n";
    /* Значок, логотип из манифеста и обложка прежнего вида (до 4.81) лежат
       рядом — их стирает удаление портала, а не набор обложки. */
    $clientDir = SSO_ROOT . '/storage/avatars/clients/';
    $side = [$clientDir . $cid . '.webp', $clientDir . 'brand-' . $cid . '.webp', Bridge::coverPath($cid)];
    foreach ($side as $f) copy($png, $f);
    Client::purge($cid);
    ok(!is_file($coverFile) && array_filter($side, 'is_file') === [], 'обложка, значок и логотип удалены вместе с порталом');
    $cid = 0;
    @unlink($png);
} finally {
    if ($cid > 0) Client::purge($cid);
    if ($siteWas === null) DB::run('DELETE FROM {settings} WHERE `key` = ?', ['look.theme']);
    else Setting::set('look.theme', (string)$siteWas);
    $policyWas === null ? Setting::delete('appearance.portal_look') : Setting::set('appearance.portal_look', (string)$policyWas);
    $socialFirstWas === null ? Setting::delete('appearance.social_first') : Setting::set('appearance.social_first', (string)$socialFirstWas);
    Setting::flush();
    @unlink($JAR);
}

echo "\n══ Итог ══\nПройдено: $passed, провалено: $failed\n";
exit($failed > 0 ? 1 : 0);
