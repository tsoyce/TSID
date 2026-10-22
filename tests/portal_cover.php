<?php
/**
 * Обложка портала на витрине главной (v4.76).
 *
 * Владелец 25.09: «Карточки проектов на главной не содержат обложку, а
 * загрузить её негде. Задумывалось, что SSO сам будет подтягивать». Здесь —
 * всё, что SSO делает сам: берёт картинку превью ссылок (og:image) с главной
 * портала, не ходит по ней туда, куда нельзя (чужой http, внутренние адреса,
 * картинка-бомба), и уступает своей картинке администратора. Карточка без
 * картинки — в цвете портала с его знаком, а не пустая штриховка.
 *
 * Сайт портала поднимается здесь же — встроенным сервером PHP на свободном
 * порту. Запуск: php tests/portal_cover.php  (SSO-сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\PortalCover;
use App\Core\Setting;
use App\Core\View;
use App\Models\Client;

$passed = 0;
$failed = 0;

/* ---- Сайт портала ---- */
$site = sys_get_temp_dir() . '/cover-site-' . getmypid();
@mkdir($site . '/img', 0777, true);
$png = static function (int $w, int $h, array $rgb): string {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
    ob_start(); imagepng($im); imagedestroy($im);
    return (string)ob_get_clean();
};
file_put_contents("$site/img/cover.png", $png(1600, 900, [200, 60, 20]));
file_put_contents("$site/img/other.png", $png(800, 400, [20, 120, 200]));
file_put_contents("$site/img/text.png", 'это не картинка');
/* PNG, который обещает 5000×5000: заголовок честный, данных нет. Разбирать
   такое GD нельзя — сотни мегабайт памяти ради одной карточки. */
$ihdr = 'IHDR' . pack('NNCCCCC', 5000, 5000, 8, 2, 0, 0, 0);
file_put_contents("$site/img/huge.png", "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr)));
$page = static fn(string $head): string => "<!doctype html><html><head><title>p</title>$head</head><body>портал</body></html>";
$pages = [
    'ok'       => '<meta property="og:image" content="/img/cover.png">',
    'gone'     => '<meta property="og:image" content="/img/cover.png">',
    'relative' => "<meta name='twitter:image' content='pic.png'>",
    'httpfar'  => '<meta property="og:image" content="http://169.254.169.254/latest/meta-data/">',
    'private'  => '<meta property="og:image" content="https://10.0.0.1/cover.png">',
    'none'     => '<meta property="og:title" content="без картинки">',
    'text'     => '<meta property="og:image" content="/img/text.png">',
    'huge'     => '<meta property="og:image" content="/img/huge.png">',
    'shotme'   => '<meta property="og:title" content="картинки нет — будет снимок">',
    'shotfail' => '<meta property="og:title" content="картинки нет, и снимок не выйдет">',
];
foreach ($pages as $dir => $head) {
    @mkdir("$site/$dir", 0777, true);
    file_put_contents("$site/$dir/index.html", $page($head));
}
copy("$site/img/other.png", "$site/relative/pic.png");
file_put_contents("$site/img/shot.png", $png(1280, 800, [240, 236, 220]));
/* Сервис снимков — поддельный, со своим протоколом как у Microlink: пишет,
   какой адрес у него попросили, и отдаёт картинку со своего хоста. */
file_put_contents("$site/shotapi.php", <<<'API'
<?php
header('Content-Type: application/json');
$u = (string)($_GET['url'] ?? '');
file_put_contents(__DIR__ . '/last-shot.txt', $u);
if (str_contains($u, 'shotfail')) { echo json_encode(['status' => 'fail', 'message' => 'The URL is not reachable']); exit; }
echo json_encode(['status' => 'success', 'data' => ['screenshot' => ['url' => 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/img/shot.png']]]);
API);

$port = 0;
for ($try = 0; $try < 20 && $port === 0; $try++) {
    $p = random_int(18000, 18900);
    $s = @fsockopen('127.0.0.1', $p, $e, $es, 0.2);
    if ($s) { fclose($s); continue; }
    $port = $p;
}
$devnull = DIRECTORY_SEPARATOR === "\\" ? "NUL" : "/dev/null";
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $site], [1 => ['file', $devnull, 'w'], 2 => ['file', $devnull, 'w']], $pipes);
for ($i = 0; $i < 50; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $e, $es, 0.2);
    if ($s) { fclose($s); break; }
    usleep(100000);
}
$origin = "http://127.0.0.1:$port";
$settingsWas = ['appearance.cover_shots' => Setting::get('appearance.cover_shots')];
putenv("SSO_SHOTS_API=$origin/shotapi.php");
/* Сначала — картинка превью сама по себе: снимок не должен подменять её причины. */
Setting::set('appearance.cover_shots', '0');
Setting::flush();

$made = [];
$mk = static function (string $dir) use (&$made, $origin): array {
    [$id] = Client::create(['name' => 'Обложка ' . $dir, 'slug' => 'cover-' . $dir . '-' . getmypid(),
        'base_url' => "$origin/$dir", 'redirect_uris' => ["$origin/$dir/cb"], 'post_logout_uris' => [], 'backchannel_uri' => '']);
    DB::run('UPDATE {clients} SET show_on_home = 1 WHERE id = ?', [$id]);
    $made[] = (int)$id;
    return Client::find((int)$id);
};
register_shutdown_function(static function () use (&$made, $proc, $site, $settingsWas): void {
    foreach ($made as $id) { PortalCover::remove($id); Client::purge($id); }
    foreach ($settingsWas as $k => $v) $v === null ? Setting::delete($k) : Setting::set($k, $v);
    Setting::flush();
    if (is_resource($proc)) proc_terminate($proc);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($site, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
    }
    @rmdir($site);
});

echo "── Картинка превью ссылок с главной портала ──\n";
$c = $mk('ok');
$r = PortalCover::fetchFromSite($c);
$file = PortalCover::path((int)$c['id']);
ok($r['ok'] && is_file($file), 'og:image с главной портала взята', $r['detail']);
$info = is_file($file) ? getimagesize($file) : false;
ok($info !== false && $info[2] === IMAGETYPE_WEBP && $info[0] <= 1200, 'своя копия — WebP не шире 1200', $info ? "{$info[0]}×{$info[1]} тип {$info[2]}" : 'нет файла');
$meta = PortalCover::meta((int)$c['id']);
ok($meta['source'] === 'site' && $meta['url'] === "$origin/img/cover.png", 'записано, откуда картинка', $meta['source'] . ' ' . $meta['url']);
ok(str_contains((string)PortalCover::url($c), '/brand/card-' . (int)$c['id'] . '.webp?v='), 'адрес для витрины — наша копия, не чужой файл', (string)PortalCover::url($c));

$c = $mk('relative');
$r = PortalCover::fetchFromSite($c);
ok($r['ok'] && PortalCover::meta((int)$c['id'])['url'] === "$origin/relative/pic.png", 'twitter:image с относительным адресом — от адреса страницы', $r['detail']);

echo "\n── Туда, куда нельзя, не ходим ──\n";
/* Причина сверяется целиком: защита двойная (и у страницы, и у запроса), и
   «не взята» по другой причине прятало бы, что одна из проверок снята. */
foreach (['httpfar' => ['картинка с чужого хоста по http', t('Картинка с чужого сайта — только по https.')],
          'private' => ['картинка с внутреннего адреса (10.0.0.1)', t('Картинку не отдали: :why', ['why' => t('адрес во внутренней сети')])],
          'text' => ['не картинка', t('Это не картинка или она слишком большая.')],
          'huge' => ['картинка 5000×5000 — отказ до разбора', t('Это не картинка или она слишком большая.')]] as $dir => [$what, $why]) {
    $c = $mk($dir);
    $r = PortalCover::fetchFromSite($c);
    ok(!$r['ok'] && $r['detail'] === $why && !is_file(PortalCover::path((int)$c['id'])), "$what — не взята", $r['detail']);
}
$c = $mk('none');
$r = PortalCover::fetchFromSite($c);
ok(!$r['ok'] && str_contains($r['detail'], 'og:image') && PortalCover::meta((int)$c['id'])['why'] !== '',
    'страница без картинки — понятная причина, записана для карточки портала', $r['detail']);

echo "\n── Картинки превью нет — снимок главной (v4.77) ──\n";
/* У Портфолио, Архива и Календаря og:image нет, и витрина стояла в цветных
   карточках: «так и не подтягивает проекты». */
Setting::set('appearance.cover_shots', '1');
Setting::flush();
$c = $mk('shotme');
$r = PortalCover::fetchFromSite($c);
$meta = PortalCover::meta((int)$c['id']);
ok($r['ok'] && is_file(PortalCover::path((int)$c['id'])) && $meta['source'] === 'shot', 'без og:image — снимок главной стал обложкой', $r['detail']);
ok((string)@file_get_contents("$site/last-shot.txt") === "$origin/shotme/", 'сервису ушёл только адрес главной портала', (string)@file_get_contents("$site/last-shot.txt"));
$c = $mk('shotfail');
$r = PortalCover::fetchFromSite($c);
ok(!$r['ok'] && str_contains($r['detail'], 'og:image') && str_contains($r['detail'], 'The URL is not reachable'),
    'снимок не вышел — причина и про картинку превью, и про снимок', $r['detail']);
$c2 = Client::find($made[0]);
PortalCover::remove((int)$c2['id']);
PortalCover::fetchFromSite($c2);
ok(PortalCover::meta((int)$c2['id'])['source'] === 'site', 'картинка превью, если есть, главнее снимка');
Setting::set('appearance.cover_shots', '0');
Setting::flush();

echo "\n── Своя картинка главнее сайта ──\n";
$c = Client::find($made[0]);
$tmp = (string)tempnam(sys_get_temp_dir(), 'own');
file_put_contents($tmp, $png(900, 600, [10, 160, 90]));
ok(PortalCover::upload((int)$c['id'], $tmp), 'своя картинка загружена');
$own = sha1_file(PortalCover::path((int)$c['id']));
$r = PortalCover::fetchFromSite($c);
ok(!$r['ok'] && sha1_file(PortalCover::path((int)$c['id'])) === $own, 'сайт её не заменяет', $r['detail']);
file_put_contents($tmp, 'не картинка');
ok(!PortalCover::upload((int)$c['id'], $tmp), 'загрузка не-картинки — отказ');
@unlink($tmp);
PortalCover::remove((int)$c['id']);
ok(!is_file(PortalCover::path((int)$c['id'])) && PortalCover::meta((int)$c['id'])['source'] === '', 'убрать — дальше снова решает сайт');

echo "\n── Раз в сутки ──\n";
foreach ($made as $id) Setting::delete('portal.cover.' . $id);
PortalCover::refreshDue(100);
$tried = array_filter($made, static fn(int $id): bool => PortalCover::meta($id)['tried'] !== '');
ok(count($tried) === count($made), 'фоновая задача прошла все порталы витрины', count($tried) . ' из ' . count($made));
$before = PortalCover::meta($made[0])['tried'];
sleep(1);
PortalCover::refreshDue(100);
ok(PortalCover::meta($made[0])['tried'] === $before, 'второй раз в те же сутки портал не трогается');

echo "\n── Витрина ──\n";
$with = Client::find($made[0]);
$without = Client::find($made[array_key_last($made)]);
PortalCover::remove((int)$without['id']);
$html = View::fetch('layouts/guest', ['content' => '', 'portals' => [$with, $without], 'pageTitle' => 'Витрина']);
ok(str_contains($html, '/brand/card-' . (int)$with['id'] . '.webp?v='), 'карточка портала с картинкой — его картинка');
/* Сколько раз портал встречается на стене — дело раскладки (v4.80: повторы
   до заполнения колонок); проверяется, что КАЖДАЯ его карточка — в цвете. */
$cardsOf = static fn(array $c): int => substr_count($html, '<span class="pcard-name">' . esc($c['name']) . '</span>');
$posters = preg_match_all('~pcard-poster" style="--pc:#[0-9A-F]{6}"~', $html);
ok($posters > 0 && $posters === $cardsOf($without) && substr_count($html, 'pcard-poster') === $posters,
    'карточка без картинки — в цвете портала и на стене, и в ленте на телефоне, а не пустая штриховка',
    "постеров $posters, карточек портала " . $cardsOf($without));
ok(substr_count($html, '/brand/card-' . (int)$with['id'] . '.webp?v=') === $cardsOf($with), 'и каждая карточка портала с картинкой — с ней');

echo "\n── Стена витрины (v4.80) ──\n";
/* Была сетка в четыре колонки: при трёх проектах одна пустовала, а
   единственная карточка колонки тянулась на её высоту. */
$cols = static fn(string $h): array => array_map(
    static fn(string $col): int => substr_count($col, 'class="pcard"'),
    array_slice(explode('<div class="pwall-col">', explode('<div class="guest-strip"', $h)[0]), 1));
$one = View::fetch('layouts/guest', ['content' => '', 'portals' => [$with], 'pageTitle' => 'Витрина']);
$three = View::fetch('layouts/guest', ['content' => '', 'portals' => [$with, $without, $with], 'pageTitle' => 'Витрина']);
ok($cols($one) === [6, 6, 6] && $cols($html) === [6, 6, 6],
    'три колонки, и ни одна не пустует — даже при одном проекте: повторы до трёх, вдвое для бесшовной прокрутки',
    json_encode([$cols($one), $cols($html)]));
$many = array_fill(0, 7, $with);
ok($cols(View::fetch('layouts/guest', ['content' => '', 'portals' => $many, 'pageTitle' => 'В'])) === [6, 6, 6]
    && array_sum($cols($three)) === 18, 'проекты делятся по колонкам, а не копятся в одной');
$idCss = (string)file_get_contents(SSO_ROOT . '/public/assets/css/identity.css');
ok(preg_match('~\.pwall-track \{[^}]*align-content: start~', $idCss) === 1 && preg_match('~\.pwall-col \{[^}]*align-content: start~', $idCss) === 1,
    'карточка не тянется на высоту колонки (align-content: start)');
ok(preg_match('~@keyframes wall \{[^}]*\}[^}]*translateY\(calc\(-50% - 12px\)\)~', $idCss) === 1
    && preg_match('~prefers-reduced-motion: reduce\) \{ \.pwall-track \{ animation: none; \} \}~', $idCss) === 1,
    'колонка едет ровно на половину (содержимое удвоено) и стоит, если человек просил без движения');

echo "\n── Адрес на карточке (v4.81) ──\n";
/* Боевая 25.09: карточка портала на .рф показывала адрес в punycode, и из-за
   его длины название ужималось до «К…». */
$idn = ['base_url' => 'https://xn--e1afmkfd.xn--p1ai/'] + $with;
$idnHtml = View::fetch('layouts/guest', ['content' => '', 'portals' => [$idn], 'pageTitle' => 'Витрина']);
ok(str_contains($idnHtml, '<span class="pcard-host">пример.рф</span>') && !str_contains($idnHtml, 'xn--'),
    'кириллический домен — буквами, а не punycode');
ok(host_label('https://xn--e1afmkfd.xn--p1ai:8443/x') === 'пример.рф' && url_label('https://xn--e1afmkfd.xn--p1ai/xn--a') === 'https://пример.рф/xn--a'
    && url_label('https://portal.example/a') === 'https://portal.example/a', 'хост декодируется только в хосте, остальной адрес как есть');
ok(preg_match('~\.pcard-host \{[^}]*flex: 0 10 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis;~', $idCss) === 1,
    'длинный адрес сжимается раньше названия');
ok(!str_contains($html, 'src="/client-icon/'), 'значок 64×64 больше не растягивается на всю карточку');

echo "\n── Портал удалён — обложки нет ──\n";
$gone = $mk('gone');
PortalCover::fetchFromSite($gone);
$goneFile = PortalCover::path((int)$gone['id']);
$had = is_file($goneFile);
Client::purge((int)$gone['id']);
ok($had && !is_file($goneFile) && Setting::get('portal.cover.' . (int)$gone['id']) === null,
    'удаление портала стирает и его обложку, и запись о ней');

echo "\n── Разбор страницы ──\n";
ok(PortalCover::previewImage('<meta content="/a.png" property="og:image"><meta name="twitter:image" content="/b.png">') === '/a.png',
    'og:image раньше twitter:image, порядок атрибутов не важен');
ok(PortalCover::previewImage("<link rel='image_src' href='/c.png'>") === '/c.png', 'image_src — последний запасной');
ok(PortalCover::resolve('//cdn.example/x.png', 'https://p.example/a/b') === 'https://cdn.example/x.png'
    && PortalCover::resolve('x.png', 'https://p.example/a/b') === 'https://p.example/a/x.png'
    && PortalCover::resolve('/x.png', 'http://p.example:81/a/') === 'http://p.example:81/x.png', 'адреса со страницы — абсолютными');
$routes = (string)file_get_contents(SSO_ROOT . '/app/routes.php');
$profile = (string)file_get_contents(SSO_ROOT . '/app/Controllers/ProfileController.php');
ok(str_contains($routes, "/admin/clients/{id}/cover-fetch") && str_contains($profile, '~^card-(\d+)\.webp$~'),
    'кнопка «Взять с сайта сейчас» и отдача /brand/card-N.webp на месте');

echo "\n" . ($failed === 0 ? "✓ все $passed проверок" : "✗ провалов: $failed из " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
