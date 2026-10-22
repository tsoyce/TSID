<?php
/**
 * Живая обложка панели входа (v4.81).
 *
 * Владелец 25.09: «добавь возможность в обложку с панелью входа добавлять
 * галерею обложек или постеров — что-то вроде живых обоев, или загрузить
 * видео, например видео из YouTube по ссылке». Здесь — что обложка у
 * установки и у портала одна и та же (набор CoverSet), что в набор попадает
 * только проверенное (картинки перекодируются, видео узнаётся по байтам,
 * YouTube — только его адреса), что прежняя обложка переезжает сама и что
 * экран входа не тянет видео и YouTube разметкой: их подключает скрипт, когда
 * панель видна и человек не просил «без движения».
 *
 * Запуск: php tests/cover_set.php  (сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Bridge;
use App\Core\CoverSet;
use App\Core\Csp;
use App\Core\DB;
use App\Core\Setting;
use App\Core\View;
use App\Models\Client;

$passed = 0;
$failed = 0;

$tmp = sys_get_temp_dir() . '/cover-set-' . getmypid();
@mkdir($tmp, 0777, true);
$img = static function (string $name, int $w, int $h, array $rgb) use ($tmp): string {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
    imagejpeg($im, "$tmp/$name", 85);
    imagedestroy($im);
    return "$tmp/$name";
};
/* Поле формы так, как его собирает PHP: одно (name) или несколько (name[]). */
$one = static fn(string $path, string $name = 'f.bin'): array => [
    'name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => (int)filesize($path)];
$many = static function (array $paths): array {
    $f = ['name' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    foreach ($paths as $i => $p) {
        $f['name'][] = 'pic' . $i . '.jpg'; $f['tmp_name'][] = $p; $f['error'][] = UPLOAD_ERR_OK; $f['size'][] = (int)filesize($p);
    }
    return $f;
};

$was = [];
foreach (['cover.site', 'branding.media_v', 'look.theme'] as $k) $was[$k] = Setting::get($k);
$made = [];
register_shutdown_function(static function () use (&$made, $was, $tmp): void {
    /* Уборка — своими руками, а не проверяемым кодом: мутация, выключившая
       удаление, не должна оставлять файлы следующему прогону. */
    foreach ($made as $id) {
        if (Client::find($id)) Client::purge($id);
        foreach ((array)glob(CoverSet::dir() . '/c' . $id . '-*') as $f) @unlink((string)$f);
        Setting::delete('cover.c' . $id);
    }
    foreach ((array)glob(CoverSet::dir() . '/site-*') as $f) @unlink((string)$f);
    foreach ($was as $k => $v) $v === null ? Setting::delete($k) : Setting::set($k, $v);
    Setting::flush();
    foreach ((array)glob("$tmp/*") as $f) @unlink((string)$f);
    @rmdir($tmp);
});
foreach ((array)glob(CoverSet::dir() . '/site-*') as $f) @unlink((string)$f);
Setting::delete('cover.site');
Setting::delete('branding.media_v');
Setting::delete('look.theme');
Setting::flush();

echo "── Ссылка на YouTube ──\n";
$good = [
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ?t=42', 'youtube.com/shorts/dQw4w9WgXcQ',
    'https://m.youtube.com/watch?feature=share&v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
    'https://www.youtube.com/live/dQw4w9WgXcQ', 'dQw4w9WgXcQ',
];
$bad = [
    'https://evil.example/watch?v=dQw4w9WgXcQ', 'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
    'javascript:alert(1)//youtu.be/dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXc"><x', 'https://www.youtube.com/watch?v=short',
    'https://user@www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ', '',
];
$wrong = [];
foreach ($good as $u) if (CoverSet::youtubeId($u) !== 'dQw4w9WgXcQ') $wrong[] = $u;
ok($wrong === [], 'код видео берётся из любых обычных ссылок YouTube', implode(' | ', $wrong));
$wrong = [];
foreach ($bad as $u) if (CoverSet::youtubeId($u) !== null) $wrong[] = $u;
ok($wrong === [], 'чужие адреса, обманки с youtube в имени и кривые коды — отказ', implode(' | ', $wrong));

echo "\n── Видео узнаётся по байтам ──\n";
file_put_contents("$tmp/a.mp4", "\x00\x00\x00\x18ftypisom" . str_repeat("\x00", 64));
file_put_contents("$tmp/a.webm", "\x1A\x45\xDF\xA3" . str_repeat("\x00", 64));
file_put_contents("$tmp/fake.mp4", '<script>alert(1)</script>' . str_repeat(' ', 64));
ok(CoverSet::videoType("$tmp/a.mp4") === 'mp4' && CoverSet::videoType("$tmp/a.webm") === 'webm'
    && CoverSet::videoType("$tmp/fake.mp4") === null, 'MP4 и WebM — по заголовку файла, а не по имени');
ok(CoverSet::videoLimit() <= CoverSet::VIDEO_MAX_BYTES && CoverSet::videoLimit() > 0, 'потолок видео не выше 40 МБ и лимитов PHP');

echo "\n── Кусок файла по Range ──\n";
ok(http_range('bytes=0-99', 1000) === [0, 99] && http_range('bytes=500-', 1000) === [500, 999]
    && http_range('bytes=-100', 1000) === [900, 999] && http_range('bytes=900-5000', 1000) === [900, 999],
    'начало-конец, «до конца», «последние n», конец за краем — обрезается');
ok(http_range('', 1000) === null && http_range('bytes=0-1,5-9', 1000) === null && http_range('items=0-9', 1000) === null,
    'без Range, несколько кусков или не байты — файл целиком');
ok(http_range('bytes=1000-', 1000) === false && http_range('bytes=9-3', 1000) === false && http_range('bytes=-0', 1000) === false,
    'кусок за пределами файла — 416');

echo "\n── Картинки: перекодирование, потолок, порядок ──\n";
$big = $img('big.jpg', 2400, 2000, [40, 70, 130]);
$paths = [$big];
for ($i = 1; $i <= 8; $i++) $paths[] = $img("p$i.jpg", 320, 200, [20 * $i, 90, 160]);
file_put_contents("$tmp/not.jpg", '<?php echo 1;');
$warn = CoverSet::saveFromForm(null, ['cover_kind' => 'images', 'cover_motion' => 'drift', 'cover_every' => '5'],
    ['cover_add' => $many(array_merge(["$tmp/not.jpg"], $paths))]);
$set = CoverSet::get(null);
ok(count($set['images']) === CoverSet::MAX_IMAGES, 'картинок не больше восьми', (string)count($set['images']));
ok(count($warn) === 2 && str_contains($warn[0], '«pic0.jpg»') && str_contains($warn[1], (string)CoverSet::MAX_IMAGES),
    'не-картинка и лишние — названы, остальное принято', implode(' | ', $warn));
$first = CoverSet::path('site', $set['images'][0], 'webp');
$dim = (array)getimagesize($first);
ok(substr((string)file_get_contents($first), 8, 4) === 'WEBP' && ($dim[0] ?? 9999) <= 1600 && ($dim[1] ?? 9999) <= 1400,
    'картинка перекодирована в webp и уложена в 1600×1400', ($dim[0] ?? 0) . '×' . ($dim[1] ?? 0));
ok($set['motion'] === 'drift' && $set['every'] === 5, 'движение и время кадра сохранены');
$drop = $set['images'][0];
@mkdir(CoverSet::dir(), 0750, true);
file_put_contents(CoverSet::dir() . '/keep.txt', 'не трогать');
CoverSet::saveFromForm(null, ['cover_drop' => [$drop, '../covers/keep', 'keep.txt', '../../config/config']], []);
$after = CoverSet::get(null);
ok(!in_array($drop, $after['images'], true) && !is_file($first) && count($after['images']) === 7
    && $after['images'][0] === $set['images'][1], 'убранная уходит с диска, порядок остальных прежний');
ok(is_file(CoverSet::dir() . '/keep.txt'), 'чужое имя в «убрать» ничего не трогает: убирается только то, что есть в наборе');
@unlink(CoverSet::dir() . '/keep.txt');
Setting::set('cover.site', (string)json_encode(['kind' => 'images', 'images' => array_merge(['../../x', 'ffffffffffff'], $after['images'])]));
Setting::flush();
ok(CoverSet::get(null)['images'] === $after['images'], 'в наборе только наши имена и только файлы, которые есть на диске');

echo "\n── Видео и YouTube ──\n";
$w = CoverSet::saveFromForm(null, ['cover_kind' => 'video'], ['cover_video' => $one("$tmp/fake.mp4", 'fake.mp4')]);
ok(CoverSet::get(null)['video'] === null && count($w) === 2, 'не видео — отказ, и сказано, что на панели будут картинки', implode(' | ', $w));
CoverSet::saveFromForm(null, ['cover_kind' => 'video'], ['cover_video' => $one("$tmp/a.mp4", 'a.mp4')]);
$v1 = CoverSet::get(null)['video'];
CoverSet::saveFromForm(null, [], ['cover_video' => $one("$tmp/a.webm", 'a.webm')]);
$v2 = CoverSet::get(null)['video'];
ok($v1 !== null && $v2 !== null && $v2['ext'] === 'webm' && !is_file(CoverSet::path('site', $v1['id'], 'mp4')),
    'новое видео заменяет прежнее — старый файл не копится');
ok(CoverSet::saveFromForm(null, ['cover_youtube' => 'https://evil.example/?v=dQw4w9WgXcQ'], []) !== []
    && CoverSet::get(null)['youtube'] === '', 'чужая ссылка вместо YouTube не принимается');
CoverSet::saveFromForm(null, ['cover_kind' => 'youtube', 'cover_youtube' => 'https://youtu.be/dQw4w9WgXcQ'], []);
ok(CoverSet::get(null)['youtube'] === 'dQw4w9WgXcQ', 'ссылка хранится кодом видео');

echo "\n── Экран входа ──\n";
$pane = auth_media_pane(null);
ok(str_contains($pane, 'data-cover-yt="dQw4w9WgXcQ"') && !str_contains($pane, '<iframe') && str_contains($pane, '<img src="'),
    'YouTube — без фрейма в разметке: его создаёт скрипт; пока — первая картинка');
/* Звук (v4.83): со звуком видео браузеры не запускают — включает посетитель. */
ok((bool)preg_match('~</div><button class="cover-sound" type="button" hidden data-cover-sound aria-pressed="false"[^>]*aria-label="[^"]+">~', $pane),
    'у видео — кнопка звука, скрытая, пока видео не заиграло');

ok(str_contains(Csp::header(), CoverSet::YOUTUBE_ORIGIN) && !str_contains(Csp::header(), 'https://www.youtube.com'),
    'политике безопасности — только youtube-nocookie.com и только на странице с таким видео');
CoverSet::saveFromForm(null, ['cover_kind' => 'video', 'cover_sound' => '0'], []);
$pane = auth_media_pane(null);
ok(CoverSet::get(null)['sound'] === false && !str_contains($pane, 'data-cover-sound'), 'кнопку звука можно убрать');
CoverSet::saveFromForm(null, ['cover_sound' => '1'], []);
$pane = auth_media_pane(null);
ok(str_contains($pane, '</video><button class="cover-sound"'), 'и вернуть');
ok((bool)preg_match('~<img src="[^"]+" alt="" loading="lazy"><video class="cover-video" muted loop playsinline preload="none" aria-hidden="true" data-cover-video="[^"]+\.webm"></video>~', $pane)
    && !preg_match('~<video[^>]* src=~', $pane), 'видео — без звука, по кругу, без адреса в разметке и с заставкой');
CoverSet::saveFromForm(null, ['cover_kind' => 'images', 'cover_motion' => 'fade', 'cover_every' => '12'], []);
$pane = auth_media_pane(null);
ok(str_contains($pane, '<div class="cover-slides" data-cover-slides="12"><img class="is-on" src="')
    && substr_count($pane, '<img data-src="') === 6 && substr_count($pane, '<img') === 7,
    'картинки по кругу: первая сразу, остальные — когда до них дойдёт очередь');
ok(!str_contains($pane, ' poster=') && preg_match_all('~<img (?:class="is-on" )?src="[^"]+"(?! alt="" loading="lazy")~', $pane) === 0
    && str_contains($pane, '<img class="is-on" src="') && str_contains($pane, '" alt="" loading="lazy">'),
    'первая картинка — ленивая: на телефоне панели нет, и она не качается');
foreach (array_slice(CoverSet::get(null)['images'], 1) as $id) CoverSet::saveFromForm(null, ['cover_drop' => [$id]], []);
CoverSet::saveFromForm(null, ['cover_motion' => 'drift'], []);
$pane = auth_media_pane(null);
ok(str_contains($pane, '<div class="cover-slides is-drift"><img src="') && !str_contains($pane, 'data-cover-slides'),
    'одна картинка — снимок, с «живыми обоями» тоже плывёт');
CoverSet::saveFromForm(null, ['cover_drop' => CoverSet::get(null)['images']], []);
ok(str_contains(auth_media_pane(null), 'media-blank'), 'без картинок и видео — заставка со знаком, как раньше');
CoverSet::saveFromForm(null, ['cover_kind' => 'youtube'], []);
ok(str_contains(auth_media_pane(null), '<span class="cover-veil"></span></div>'), 'YouTube без картинок — под подложкой, а не с подписями плеера на виду');
CoverSet::saveFromForm(null, ['cover_kind' => 'images'], []);

echo "\n── Прежняя обложка переезжает сама ──\n";
Setting::delete('cover.site');
@mkdir(SSO_ROOT . '/storage/avatars/branding', 0750, true);
copy($big, SSO_ROOT . '/storage/avatars/branding/media.webp');
Setting::set('branding.media_v', '1790000000');
Setting::flush();
$adopted = CoverSet::get(null);
ok(count($adopted['images']) === 1 && is_file(CoverSet::path('site', $adopted['images'][0], 'webp'))
    && !is_file(SSO_ROOT . '/storage/avatars/branding/media.webp') && Setting::get('branding.media_v') === null,
    'снимок установки стал первой картинкой набора, старый файл и запись убраны');

[$cid] = Client::create(['name' => 'Обложка набора', 'slug' => 'cover-set-' . getmypid(), 'base_url' => 'https://cover-set.example',
    'redirect_uris' => ['https://cover-set.example/cb'], 'post_logout_uris' => [], 'backchannel_uri' => '']);
$made[] = (int)$cid;
DB::run("UPDATE {clients} SET look_mode = 'custom', look_theme = ? WHERE id = ?", [json_encode(['accent' => '#A53718', 'cover' => '1790000001']), $cid]);
@mkdir(dirname(Bridge::coverPath((int)$cid)), 0750, true);
copy($big, Bridge::coverPath((int)$cid));
$client = Client::find((int)$cid);
$url = Bridge::coverUrl($client);
ok($url !== null && str_starts_with($url, base_url('/cover/c' . $cid . '-')) && !is_file(Bridge::coverPath((int)$cid)),
    'обложка портала — тоже: адрес набора, старый файл убран', (string)$url);
$pp = auth_media_pane($client);
ok(str_contains($pp, 'portal-cover') && str_contains($pp, '/cover/c' . $cid . '-'), 'панель портала показывает его набор');
DB::run("UPDATE {clients} SET look_mode = 'off' WHERE id = ?", [$cid]);
ok(Bridge::coverUrl(Client::find((int)$cid)) === null && !str_contains(auth_media_pane(Client::find((int)$cid)), '/cover/c'),
    'портал на виде установки своей обложки не показывает');

echo "\n── Один механизм у сайта и у порталов ──\n";
$siteEd = View::fetch('admin/_cover_field', ['clientId' => null]);
$portalEd = View::fetch('admin/_cover_field', ['clientId' => (int)$cid]);
$names = static function (string $h): array { preg_match_all('~name="([^"]+)"~', $h, $m); $n = array_unique($m[1]); sort($n); return $n; };
ok($names($siteEd) === $names($portalEd) && in_array('cover_add[]', $names($siteEd), true) && in_array('cover_youtube', $names($siteEd), true),
    'в редакторе сайта и портала — одни и те же поля', implode(',', $names($siteEd)));
ok(str_contains($siteEd, 'multiple accept="image/*"') && str_contains($siteEd, 'accept="video/mp4,video/webm"')
    && substr_count($siteEd, 'data-cover-for="') === 4 && str_contains($siteEd, 'data-cover-for="video youtube"')
    && str_contains($siteEd, 'name="cover_sound"'), 'картинки — несколькими сразу; видео и YouTube — блоками по выбору, кнопка звука — у обоих');
$appearance = (string)file_get_contents(SSO_ROOT . '/templates/admin/appearance.php');
$look = (string)file_get_contents(SSO_ROOT . '/templates/admin/client/look.php');
ok(str_contains($appearance, "View::fetch('admin/_cover_field', ['clientId' => null])")
    && str_contains($look, "View::fetch('admin/_cover_field', ['clientId' => (int)\$c['id']])")
    && !str_contains($appearance, "field_image('media'") && !str_contains($look, "field_image('look_cover'"),
    'старых полей обложки нет ни там, ни там');

echo "\n── Портал удалён — его обложки нет ──\n";
CoverSet::saveFromForm((int)$cid, ['cover_kind' => 'video'], ['cover_add' => $many([$paths[1]]), 'cover_video' => $one("$tmp/a.mp4")]);
$files = (array)glob(CoverSet::dir() . '/c' . $cid . '-*');
Client::purge((int)$cid);
ok(count($files) === 3 && (array)glob(CoverSet::dir() . '/c' . $cid . '-*') === [] && Setting::get('cover.c' . $cid) === null,
    'удаление портала стирает и картинки, и видео, и запись набора', (string)count($files));

echo "\n── Отдача и скрипт ──\n";
$routes = (string)file_get_contents(SSO_ROOT . '/app/routes.php');
$cs = (string)file_get_contents(SSO_ROOT . '/app/Core/CoverSet.php');
ok(str_contains($routes, "\$router->get('/cover/{file}', [ProfileController::class, 'serveCover']);")
    && str_contains($cs, "if (!preg_match('~^(site|bg|c\\d{1,9})-([0-9a-f]{12})\\.(webp|mp4|webm)\$~', \$file, \$m)) ErrorPage::render(404);"),
    'файл обложки — только по шаблону имени: обход каталога невозможен');
$js = (string)file_get_contents(SSO_ROOT . '/public/assets/js/app.js');
ok(str_contains($js, "if (pane.dataset.coverLive || pane.getClientRects().length === 0 || pane.offsetWidth === 0) return;")
    && (bool)preg_match('~if \(still\.matches\) \{.*?v\.preload = .metadata.;\s*v\.src = [^;]+;\s*\}\);\s*return;\s*\}~s', $js)
    && str_contains($js, "if (!pane || document.body.classList.contains('is-preview')) return;"),
    'видео и YouTube включаются, только когда панель видна и движение не запрещено; в предпросмотре — нет');
ok(str_contains($js, "var yt = 'https://www.youtube-nocookie.com';")
    && str_contains($js, "f.src = yt + '/embed/' + id + '?autoplay=1&mute=1&controls=0&playsinline=1&rel=0&disablekb=1&fs=0'")
    && str_contains($js, "&enablejsapi=1&origin=' + encodeURIComponent(location.origin);")
    && str_contains($js, "if (!/^[A-Za-z0-9_-]{11}$/.test(id)) return;"), 'YouTube — без cookie и без звука; код проверен и в скрипте');
/* Боевая 26.09: плейлист из одного ролика рисовал «назад · вперёд», а в
   первые секунды поверх обложки стояли название и пауза. */
ok(!str_contains($js, 'playlist=') && str_contains($js, "if (state === 0) { box.classList.remove('is-playing'); cmd('seekTo', [0, true]); cmd('playVideo'); return; }")
    && str_contains($js, "box.classList.toggle('is-playing', info.currentTime >= 3);")
    && str_contains($js, 'if (e.origin !== yt || e.source !== f.contentWindow) return;'),
    'повтор перемоткой, а не плейлистом; заставка уходит, когда плеер отыграл подписи; сообщения — только от этого фрейма');
$css = (string)file_get_contents(SSO_ROOT . '/public/assets/css/identity.css');
ok(str_contains($css, '.cover-yt > img, .cover-yt > .cover-veil { position: absolute; inset: 0; z-index: 1;')
    && str_contains($css, '.cover-yt.is-playing > img, .cover-yt.is-playing > .cover-veil { opacity: 0; }'),
    'заставка лежит поверх плеера, без картинки — подложка');
ok(str_contains($js, "soundReady(function (on) { v.muted = !on; if (on) { v.volume = 0.7; v.play(); } });")
    && str_contains($js, "if (on) { cmd('unMute'); cmd('setVolume', [70]); } else { cmd('mute'); }"),
    'кнопка звука включает и выключает звук и у файла, и у YouTube');
$css = (string)file_get_contents(SSO_ROOT . '/public/assets/css/identity.css');
ok(str_contains($css, "@media (prefers-reduced-motion: reduce) {\n  .cover-slides.is-drift img { animation: none; }"),
    '«живые обои» стоят у того, кто просил без движения');

echo "\n" . ($failed === 0 ? "✓ все $passed проверок" : "✗ провалов: $failed из " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
