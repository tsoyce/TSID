<?php
/**
 * Целостность шаблонов и статики.
 *
 * Появился после того, как скрипт массовой замены обнулил три файла макетов, а
 * заметил я это не сразу: страницы отдавали пустой HTML, тесты в тот момент не
 * гоняли, и порча жила несколько правок. Проверки ниже стоят доли секунды и
 * ловят ровно тот класс поломок, который трудно увидеть глазами.
 *
 * Запуск: php tests/templates_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$fails = 0;
function check(bool $ok, string $name, string $detail = ''): void
{
    global $fails;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok ? '' : " — $detail") . "\n";
    if (!$ok) $fails++;
}

/** @return list<string> */
function walk(string $dir, array $ext): array
{
    if (!is_dir($dir)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $f) {
        if ($f->isFile() && in_array($f->getExtension(), $ext, true)) {
            $out[] = str_replace('\\', '/', $f->getPathname());
        }
    }
    sort($out);
    return $out;
}

$templates = walk("$root/templates", ['php']);
$assets = walk("$root/public/assets/css", ['css']);
$scripts = walk("$root/public/assets/js", ['js']);
$php = array_merge($templates, walk("$root/app", ['php']), walk("$root/tools", ['php']));

echo "Файлы на месте:\n";
check(count($templates) > 30, 'шаблоны найдены', 'их всего ' . count($templates));
check(count($assets) >= 2, 'таблицы стилей найдены');

echo "Ни один файл не пуст:\n";
$empty = [];
foreach (array_merge($php, $assets, $scripts) as $f) {
    if (filesize($f) === 0 || trim((string)file_get_contents($f)) === '') $empty[] = basename($f);
}
check($empty === [], 'нет файлов нулевой длины', implode(', ', $empty));

echo "Синтаксис PHP:\n";
$broken = [];
foreach ($php as $f) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
    if ($code !== 0) $broken[] = basename($f);
}
check($broken === [], 'все PHP-файлы разбираются', implode(', ', $broken));

echo "Разметка:\n";
/* Дубль атрибута — тихая поломка: браузер берёт первый, второй игнорирует.
   Именно так наивная замена style= на class= «применялась» без эффекта. */
$dups = [];
foreach ($templates as $f) {
    $src = (string)file_get_contents($f);
    if (!preg_match_all('~<[a-zA-Z][^>]*>~s', $src, $m)) continue;
    foreach ($m[0] as $tag) {
        foreach (['class', 'id', 'style', 'for'] as $attr) {
            if (substr_count($tag, ' ' . $attr . '="') > 1) {
                $dups[] = basename($f) . ': ' . $attr;
            }
        }
    }
}
check($dups === [], 'нет дублей атрибутов в тегах', implode(', ', array_unique($dups)));

/* Незакрытый <?php или <?= рвёт весь вывод страницы */
$unbalanced = [];
foreach ($templates as $f) {
    $src = (string)file_get_contents($f);
    $open = preg_match_all('~<\?(php|=)~', $src);
    $close = substr_count($src, '?>');
    /* Хвостовой <?php без ?> — норма для файлов, кончающихся кодом */
    if ($close > $open) $unbalanced[] = basename($f) . " (?> больше, чем <?php)";
}
check($unbalanced === [], 'PHP-теги сбалансированы', implode(', ', $unbalanced));

$markers = [];
foreach (array_merge($php, $assets, $scripts) as $f) {
    $src = (string)file_get_contents($f);
    if (preg_match('~^(<<<<<<<|>>>>>>>|=======$)~m', $src)) $markers[] = basename($f);
}
check($markers === [], 'нет следов конфликтов слияния', implode(', ', $markers));

echo "Ссылки на ассеты:\n";
/* asset() отдаёт путь с версией от mtime; несуществующий файл он не поймает */
$missing = [];
foreach ($templates as $f) {
    $src = (string)file_get_contents($f);
    if (!preg_match_all("~asset\('([^']+)'\)~", $src, $m)) continue;
    foreach ($m[1] as $a) {
        if (!is_file("$root/public/" . ltrim($a, '/'))) $missing[] = $a;
    }
}
check($missing === [], 'все asset() существуют на диске', implode(', ', array_unique($missing)));

echo "CSS:\n";
$css = '';
foreach ($assets as $f) $css .= file_get_contents($f);
check(substr_count($css, '{') === substr_count($css, '}'), 'скобки в CSS сбалансированы',
    substr_count($css, '{') . ' открывающих против ' . substr_count($css, '}'));

/* Переменная, которую используют, но нигде не объявляют, — это опечатка,
   и браузер молча отрисует элемент без цвета */
preg_match_all('~var\(\s*(--[a-z0-9-]+)~i', $css, $used);
preg_match_all('~^\s*(--[a-z0-9-]+)\s*:~mi', $css, $declared);
$undeclared = array_values(array_diff(array_unique($used[1]), array_unique($declared[1])));
/* --s-N и прочие объявляются пачкой в одной строке — добираем их отдельно */
preg_match_all('~(--[a-z0-9-]+)\s*:~i', $css, $declaredAll);
$undeclared = array_values(array_diff($undeclared, array_unique($declaredAll[1])));
check($undeclared === [], 'все CSS-переменные объявлены', implode(', ', $undeclared));

/* Браузер прячет hidden правилом [hidden] { display: none } с той же
   специфичностью, что у любого нашего класса, а авторский стиль бьёт
   браузерный. Поэтому .bento-card с display: flex показывал карточку,
   помеченную hidden, как ни в чём не бывало — и увидеть это можно было только
   в браузере. Правило со !important обязано быть, пока hidden используется. */
$usesHidden = false;
foreach ($templates as $f) {
    if (preg_match('~<[^>]*\shidden(\s|>)~', (string)file_get_contents($f))) { $usesHidden = true; break; }
}
check(!$usesHidden || preg_match('~\[hidden\]\s*\{[^}]*display:\s*none\s*!important~', $css) === 1,
    'атрибут hidden побеждает классы с display');

/* Один класс — два разных компонента (v4.70). В 4.69 шапка карточки объекта
   получила классы .obj-*, а в identity.css под теми же именами давно жила
   карточка идентичности. identity.css грузится позже и побеждает — шапка на
   боевой развалилась, а в браузере это не ошибка, а просто «так нарисовалось».

   Ловим класс, который ОБА листа объявляют сам по себе, без контекста:
   `.x {…}` здесь и `.x {…}` там. Уточнения вида `.rel-item .badge` — не
   столкновение, а доработка компонента в своём месте.

   Исключения — один и тот же компонент, а не два: app.css задаёт им только
   view-transition-name для перехода между экранами, вид — в identity.css.
   Исключение, которое перестало быть общим, тоже провал: список не должен
   прикрывать то, чего уже нет. */
$standalone = static function (string $file): array {
    $src = (string)preg_replace('~/\*.*?\*/~s', '', (string)file_get_contents($file));
    preg_match_all('~(?:^|[{};])\s*([^{};@][^{};]*)\{~', $src, $m);
    $out = [];
    foreach ($m[1] as $list) {
        foreach (explode(',', $list) as $sel) {
            if (preg_match('#^\s*\.([a-zA-Z_][\w-]*)(?:[:\[][^\s>+~]*)?\s*$#', $sel, $cm)) $out[$cm[1]] = true;
        }
    }
    return $out;
};
$sameComponent = ['isle', 'dock', 'auth-tabs', 'auth-tabs-ink', 'auth-brandline', 'auth-split-media'];
$shared = array_keys(array_intersect_key(
    $standalone("$root/public/assets/css/app.css"), $standalone("$root/public/assets/css/identity.css")));
$clash = array_values(array_diff($shared, $sameComponent));
check($clash === [], 'app.css и identity.css не делят имя класса между разными компонентами',
    implode(', ', array_map(static fn($c) => ".$c", $clash)));
$staleAllow = array_values(array_diff($sameComponent, $shared));
check($staleAllow === [], 'исключения из проверки выше ещё действительны', implode(', ', $staleAllow));

echo "Классы, которых нет:\n";
/* Невалидный класс не даёт ошибки в консоли: браузер молча игнорирует
   неизвестное имя, поэтому поймать его можно только сверкой, а не глазами —
   элемент выглядит «просто чуть иначе». Так в системе жили четыре штуки:
   `.callout-warn` вместо `.callout-warning` (предупреждение в настройках
   рисовалось обычной информационной плашкой), `.footer-about` без правила
   (копирайт светился акцентом рядом с приглушёнными соседями), плюс мёртвые
   `.page-title` и `.release`.

   Собираем объявленные в таблицах и использованные в шаблонах, вычитаем. */
$declaredClasses = [];
if (preg_match_all('~\.([a-zA-Z][\w-]*)~', $css, $dc)) $declaredClasses = array_flip($dc[1]);
$usedClasses = [];
foreach ($templates as $f) {
    $src = (string)file_get_contents($f);
    if (!preg_match_all('~class="([^"<>]*)"~', $src, $cm)) continue;
    foreach ($cm[1] as $list) {
        /* Значение бывает собрано из PHP — выкидываем вставки, остальное разбираем. */
        $list = (string)preg_replace('~<\?.*?\?>~s', ' ', $list);
        foreach (preg_split('~\s+~', trim($list)) ?: [] as $c) {
            if ($c !== '' && preg_match('~^[a-zA-Z][\w-]*$~', $c)) {
                $usedClasses[$c] = basename($f);
            }
        }
    }
}
$noSuchClass = [];
foreach ($usedClasses as $c => $file) {
    if (!isset($declaredClasses[$c])) $noSuchClass[] = ".$c ($file)";
}
check($noSuchClass === [],
    'все классы из шаблонов объявлены в таблицах (' . count($usedClasses) . ' имён)',
    implode(', ', $noSuchClass));

echo "Текст мимо словаря:\n";
/* Покрытие переводов считало долю переведённых среди строк, УЖЕ прошедших
   через t(). Строка, написанная в разметке напрямую, в знаменатель не
   попадала вовсе — и отчёт показывал 100% при непереведённом экране согласия,
   который видит каждый человек на каждом недоверенном портале.
   Здесь считается обратное: сколько видимого текста идёт мимо словаря. */
$bypass = [];
foreach ($templates as $f) {
    $rel = str_replace('\\', '/', $f);
    /* Письма — другая среда: там табличная вёрстка и свой набор строк,
       который живёт в шаблонах писем целиком. */
    if (str_contains($rel, '/templates/emails/')) continue;
    $src = (string)file_get_contents($f);
    /* Убираем код и комментарии: интересует только то, что видит человек. */
    $s = (string)preg_replace('~<\?php.*?\?>~su', '', $src);
    $s = (string)preg_replace('~<\?=.*?\?>~su', '', $s);
    $s = (string)preg_replace('~/\*.*?\*/~su', '', $s);
    if (!preg_match_all('~>\s*([^<>]*[А-Яа-яЁё][^<>]*)~u', $s, $tm)) continue;
    foreach ($tm[1] as $text) {
        $text = trim((string)preg_replace('~\s+~u', ' ', $text));
        /* Названия языков в переключателе пишутся на своём языке нарочно. */
        if (mb_strlen($text) < 3 || $text === 'Русский') continue;
        $bypass[basename($f) . ': ' . mb_substr($text, 0, 40)] = true;
    }
}
check($bypass === [], 'весь видимый текст идёт через словарь',
    implode('; ', array_slice(array_keys($bypass), 0, 6))
    . (count($bypass) > 6 ? ' … и ещё ' . (count($bypass) - 6) : ''));

echo "Словарь событий истории:\n";
/* Тот же класс ошибки, что и несуществующий CSS-класс: ключ, которого нет
   среди настоящих событий, ничего не ломает — подпись просто никогда не
   покажется, а в истории останется машинный идентификатор. Из первой версии
   словаря таких оказалось три: `admin.client.secret` вместо
   `…secret_rotate`, `login.ok` вместо `login.success`, и `admin.org.*`
   угаданные наугад. */
$realEvents = [];
foreach (walk("$root/app", ['php']) as $f) {
    $src = (string)file_get_contents($f);
    if (preg_match_all("~Audit::log\('([a-z0-9._]+)'~", $src, $em)) {
        foreach ($em[1] as $e) $realEvents[$e] = true;
    }
    /* Событие входа приезжает параметром со значением по умолчанию. */
    if (preg_match_all("~auditEvent = '([a-z0-9._]+)'~", $src, $dm)) {
        foreach ($dm[1] as $e) $realEvents[$e] = true;
    }
}
require_once "$root/app/Core/ObjectHistory.php";
$known = (new ReflectionMethod('App\Core\ObjectHistory', 'title'))->getFileName();
preg_match_all("~'([a-z0-9._]+)' => '~", (string)file_get_contents($known), $km);
$ghost = [];
foreach ($km[1] as $key) {
    if (str_contains($key, '.') && !isset($realEvents[$key])) $ghost[] = $key;
}
check($ghost === [], 'все подписи в истории ссылаются на существующие события',
    implode(', ', $ghost));

echo "Словари:\n";
/* Повторное объявление ключа в массиве PHP не ошибка: остаётся последнее, а
   предыдущее исчезает молча. Так «Сейчас» с дашборда («Right now») было затёрто
   «Сейчас» из настроек («Currently») — перевод потерялся, и заметить это можно
   было только на английской странице, куда никто не заходит каждый день. */
foreach (['en-admin', 'en', 'ru'] as $dict) {
    $path = "$root/app/Lang/$dict.php";
    if (!is_file($path)) continue;
    $src = (string)file_get_contents($path);
    preg_match_all("~^\s{4}'((?:[^'\\\\]|\\\\.)*)'\s*=>~m", $src, $km);
    $dups = array_filter(array_count_values($km[1]), static fn(int $c): bool => $c > 1);
    check($dups === [], "в словаре $dict нет повторяющихся ключей",
        implode(', ', array_map(static fn($k) => "«{$k}»", array_slice(array_keys($dups), 0, 5))));
}

echo "Контраст текста (WCAG AA):\n";
/* Подсказки стоят под каждым полем интерфейса, а --text-3 давал 2.52:1 в
   светлой теме и 3.16 в тёмной — вдвое ниже нормы. Глазами это выглядит просто
   «бледновато», поэтому проверяется числом, а не на вид. */
require_once "$root/app/Core/Palette.php";
$tokens = (string)file_get_contents("$root/public/assets/css/tokens.css");

/* Читаем каждую тему как отдельный блок, а не «первое вхождение — светлая,
   второе — тёмная». Порядковый разбор требовал, чтобы темы стояли в файле в
   заданном порядке и чтобы цвет был записан ровно шестизначным hex, — то есть
   тест диктовал оформление вместо того, чтобы проверять результат. */
$themeBlock = static function (string $src, string $selector): string {
    $i = strpos($src, $selector);
    if ($i === false) return '';
    $open = strpos($src, '{', $i);
    $close = strpos($src, '}', (int)$open);
    return $open === false || $close === false ? '' : substr($src, $open, $close - $open);
};
$blocks = [
    'светлая' => $themeBlock($tokens, ':root {'),
    'тёмная'  => $themeBlock($tokens, ':root[data-theme="dark"]'),
];
$grab = static function (string $block, string $name): ?string {
    return preg_match('~' . preg_quote($name, '~') . '\s*:\s*(#[0-9A-Fa-f]{3,8})~', $block, $m)
        ? $m[1] : null;
};
$cases = [
    ['светлая', '--text-3', '--bg'],       // подсказка на фоне страницы
    ['светлая', '--text-2', '--surface'],  // вторичный текст на карточке
    ['тёмная',  '--text-3', '--surface'],
    ['тёмная',  '--text-2', '--surface'],
];
foreach ($cases as [$theme, $fgName, $bgName]) {
    $fg = $grab($blocks[$theme], $fgName);
    $bg = $grab($blocks[$theme], $bgName);
    if ($fg === null || $bg === null) {
        check(false, "$theme: токены $fgName и $bgName найдены в своём блоке");
        continue;
    }
    $r = App\Core\Palette::contrast($fg, $bg);
    check($r >= 4.5, sprintf('%s: %s на %s — %.2f:1', $theme, $fgName, $bgName, $r),
        sprintf('нужно 4.5, получено %.2f', $r));
}

/* Акцент задаёт владелец установки, поэтому проверять надо не один цвет из
   файла, а поведение Palette на произвольном.
   Проверка выше этого не ловила и пропустила настоящую поломку: Palette тянул
   --ink-text до 4.5 против КАРТОЧКИ, а ссылки подвала и подписи стоят на ФОНЕ
   СТРАНИЦЫ, который темнее. Разница съедала весь запас — оранжевый давал 4.34,
   розовый 4.21, жёлтый 4.46. Худший фон в каждой теме свой: в светлой это --bg,
   в тёмной наоборот карточка (она светлее фона). */
$accents = ['#1D4ED8', '#EA580C', '#CA8A04', '#15803D', '#DB2777', '#7C3AED', '#0F766E'];
foreach (['светлая' => false, 'тёмная' => true] as $theme => $dark) {
    $bg = $grab($blocks[$theme], '--bg');
    $surface = $grab($blocks[$theme], '--surface');
    $bad = [];
    $worst = 99.0;
    foreach ($accents as $accent) {
        $d = App\Core\Palette::derive($accent, $dark);
        foreach ([$bg, $surface] as $on) {
            if ($on === null) continue;
            $r = App\Core\Palette::contrast($d['ink_text'], $on);
            $worst = min($worst, $r);
            if ($r < 4.5) $bad[] = sprintf('%s на %s — %.2f', $accent, $on, $r);
        }
        /* Текст поверх заливки: белый или почти чёрный, что контрастнее. */
        $r = App\Core\Palette::contrast($d['on_ink'], $d['ink']);
        if ($r < 4.5) $bad[] = sprintf('%s: --on-ink на заливке — %.2f', $accent, $r);
    }
    check($bad === [], sprintf('%s: акцент как текст держит 4.5 на карточке и на фоне (худший %.2f)', $theme, $worst),
        implode('; ', $bad));
}

echo "Иконки:\n";
/* icon('foo') рисует <use href="#i-foo">. Опечатки в имени браузер не покажет
   никак: вместо иконки просто пустое место нужного размера. */
$sprite = (string)file_get_contents("$root/public/assets/icons.svg");
preg_match_all('~id="i-([a-z0-9-]+)"~', $sprite, $have);
$known = $have[1];
$unknownIcons = [];
foreach ($php as $f) {
    /* Граница слова обязательна: без неё `status_icon('ok')` читается как
       запрос иконки «ok», которой в спрайте нет и не должно быть — эта
       функция сама переводит состояние в имя (check-circle и прочие).
       Проверка объявляла поломкой совершенно правильный код. */
    if (!preg_match_all("~(?<![a-z_])icon\('([a-z0-9-]+)'~", (string)file_get_contents($f), $m)) continue;
    foreach ($m[1] as $name) {
        if (!in_array($name, $known, true)) $unknownIcons[] = "$name (" . basename($f) . ')';
    }
}
check($unknownIcons === [], 'все icon() есть в спрайте', implode(', ', array_unique($unknownIcons)));

echo "PWA:\n";
/* Мета установки живёт в одном помощнике. Если layout перестанет его звать,
   приложение молча перестанет устанавливаться — заметить это по внешнему виду
   страницы невозможно. */
foreach (['base', 'auth'] as $layout) {
    $src = (string)file_get_contents("$root/templates/layouts/$layout.php");
    check(str_contains($src, 'pwa_head()'), "layout $layout зовёт pwa_head()");
}

/* Service worker на origin SSO перехватывает всё. Три свойства ниже — не
   украшение, а условие, при котором его вообще можно держать включённым. */
$sw = (string)file_get_contents("$root/app/Controllers/PwaController.php");
$swBody = substr($sw, (int)strpos($sw, 'const CACHE ='));
foreach (['/authorize', '/token', '/userinfo', '/logout', '/login', '/fedcm/', '/api/', '/scim/'] as $path) {
    check(str_contains($swBody, "'$path'"), "воркер обходит $path стороной");
}
check(str_contains($swBody, "req.method !== 'GET'") && str_contains($swBody, 'return;'),
    'воркер не вмешивается в POST');
/* Единственная запись в кэш должна стоять внутри ветки для /assets/ */
$assetsBranch = strpos($swBody, "url.pathname.startsWith('/assets/')");
$put = strpos($swBody, 'c.put(');
check($assetsBranch !== false && $put !== false && $put > $assetsBranch,
    'в кэш пишется только статика из /assets/');
check(!str_contains($swBody, 'text/html'), 'воркер не пытается кэшировать HTML');

$offline = (string)file_get_contents("$root/templates/offline.php");
check(!str_contains($offline, 'user') && !str_contains($offline, 'Auth::'),
    'офлайн-страница не касается данных пользователя');

echo "Возврат после сохранения:\n";
/* Перенаправление после сохранения ведёт на якорь, а вкладки открываются по
   совпадению якоря с id панели. Разошлись имена — и человек, сохранив
   документы, оказывался на брендинге: «меня выкинуло». Разница была ровно в
   префиксе `tab-`, и глазами в коде она не видна. Собираем id вкладок из
   шаблонов и сверяем с якорями из контроллеров. */
$tabIds = [];
$elementIds = [];
foreach ($templates as $f) {
    $src = (string)file_get_contents($f);
    if (preg_match_all('~data-tab="([\w-]+)"~', $src, $tm)) {
        foreach ($tm[1] as $id) $tabIds[$id] = true;
    }
    /* Якорь может вести и на обычный блок страницы — такие id собираем тоже.
       Без этого проверка ругалась на `#social`, который на странице
       безопасностиуказывает на карточку соцвходов, а вкладка `tab-social`
       живёт совсем на другой странице. Я на этом уже один раз ошибся и едва
       не «починил» рабочий якорь. */
    if (preg_match_all('~\bid="([\w-]+)"~', $src, $im)) {
        foreach ($im[1] as $id) $elementIds[$id] = true;
    }
}
$anchorBad = [];
foreach (walk("$root/app/Controllers", ['php']) as $f) {
    $src = (string)file_get_contents($f);
    /* Якорь, к которому справа приклеивают переменную (`'#tab-' . $section`),
       в исходнике обрывается на префиксе — проверять его бессмысленно.
       Отрицательный просмотр вперёд отсекает такие случаи. */
    if (!preg_match_all("~redirect\('([^']*#([\w-]+))'(?!\s*\.)~", $src, $rm, PREG_SET_ORDER)) continue;
    foreach ($rm as $r) {
        /* Правило простое: якорь обязан куда-то вести — либо на вкладку, либо
           на блок с таким id. Если ни того, ни другого, человек после
           сохранения окажется не там, где был. Именно так и было с `#docs`:
           вкладка называется `tab-docs`, блока `docs` нет, и сохранение
           документов возвращало на брендинг. */
        if (!isset($tabIds[$r[2]]) && !isset($elementIds[$r[2]])) {
            $hint = isset($tabIds['tab-' . $r[2]]) ? " — вкладка называется tab-{$r[2]}" : '';
            $anchorBad[] = basename($f) . ": {$r[1]} — якорь никуда не ведёт" . $hint;
        }
    }
}
check($anchorBad === [], 'якоря перенаправлений совпадают с вкладками',
    implode('; ', $anchorBad));

echo "Переменная в кавычках-ёлочках:\n";
/* «$k»» в двойных кавычках разбирается как переменная `$k»`: закрывающая
   ёлочка многобайтная, а PHP допускает такие байты в имени. Подстановка молча
   исчезает вместе с тем словом, ради которого её писали, — сообщение остаётся
   грамматически целым и бессмысленным. Найдено в отгружаемом SsoClient: «не
   задан «.» вместо «не задан «client_secret».
   Лечится скобками: "{$k}". */
$guillemet = [];
/* Проверки тоже смотрим: в них та же ловушка ведёт себя злее — падает не
   страница, а прогон, и виновником выглядит проверяемый код. Именно так и
   вышло на следующий же день после появления этой проверки. */
foreach (array_merge(walk("$root/app", ['php']), walk("$root/templates", ['php', 'tpl']),
                     walk("$root/tools", ['php']), walk("$root/tests", ['php'])) as $file) {
    /* Комментарии выбрасываем, сохраняя нумерацию строк: объяснение ловушки
       обязано содержать её пример, и без этого проверка первым делом объявила
       бы поломкой собственное описание. Ровно так уже вышло с localStorage. */
    $src = (string)file_get_contents($file);
    $src = (string)preg_replace_callback('~/\*.*?\*/~s',
        static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $src);
    $src = (string)preg_replace('~//[^\n]*~', '', $src);
    foreach (explode("\n", $src) as $n => $line) {
        if (preg_match('~\$[a-zA-Z_][a-zA-Z0-9_]*[«»]~u', $line)) {
            $guillemet[] = basename($file) . ':' . ($n + 1);
        }
    }
}
check($guillemet === [], 'переменная не склеена с ёлочкой — иначе подстановка пропадёт',
    implode(', ', $guillemet));

echo "Миграции: в первичном ключе нет NULL-able колонок:\n";
/* MySQL не пускает в PRIMARY KEY колонку, допускающую NULL: он молча делает её
   NOT NULL, а существующие NULL-ы конвертировать нечем — «Data truncated for
   column». SQLite же NULL в ключе терпит, поэтому в разработке зелено, а на
   боевой миграция не применяется. Так уже погибла 014, так погибла и 021.
   Проверяем по тексту миграций — и .sql, и PHP-шагов, где ключ строится
   строкой. */
$nullable = [];   // имя колонки → файл, где объявлена NULL-able
$notNulled = [];  // имена колонок, приведённых к NOT NULL
$pkCols = [];     // имя колонки → файл, где попала в PRIMARY KEY
foreach (walk("$root/migrations", ['sql', 'php']) as $file) {
    $sql = (string)preg_replace('~--[^\n]*~', '', (string)file_get_contents($file));
    $base = basename($file);

    if (preg_match_all('~ADD\s+COLUMN\s+`?(\w+)`?[^,;\n]*?\bNULL\b~i', $sql, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            if (stripos($x[0], 'NOT NULL') !== false) { $notNulled[strtolower($x[1])] = true; continue; }
            $nullable[strtolower($x[1])] = $base;
        }
    }
    if (preg_match_all('~(?:MODIFY|ALTER\s+COLUMN)\s+`?(\w+)`?[^,;\n]*?NOT\s+NULL~i', $sql, $m)) {
        foreach ($m[1] as $c) $notNulled[strtolower($c)] = true;
    }
    /* Колонка, объявленная NOT NULL прямо в пересборке таблицы (SQLite). */
    if (preg_match_all('~`?(\w+)`?\s+[A-Z]+[^,\n]*?NOT\s+NULL~i', $sql, $m)) {
        foreach ($m[1] as $c) $notNulled[strtolower($c)] = true;
    }
    if (preg_match_all('~PRIMARY\s+KEY\s*\(([^)]+)\)~i', $sql, $m)) {
        foreach ($m[1] as $list) {
            foreach (explode(',', $list) as $c) {
                $c = strtolower(trim($c, " `\t\r\n"));
                if ($c !== '') $pkCols[$c] = $base;
            }
        }
    }
}
$pkNullable = [];
foreach ($pkCols as $col => $where) {
    if (isset($nullable[$col]) && !isset($notNulled[$col])) {
        $pkNullable[] = $col . ' (NULL в ' . $nullable[$col] . ', в ключе в ' . $where . ')';
    }
}
check($pkNullable === [], 'в первичный ключ не попадает NULL-able колонка',
    implode('; ', $pkNullable));
check($pkCols !== [] && $nullable !== [],
    'разбор нашёл и ключи, и NULL-able колонки: ' . count($pkCols) . ' / ' . count($nullable));

echo "Миграции: колонка добавляется один раз:\n";
/* Колонка, добавленная дважды разными миграциями, ломает УСТАНОВКУ С НУЛЯ, а
   не обновление: у инсталлятора свой прогонщик, и он, в отличие от
   Migrations::apply(), не прощает «уже существует». Обновлённая база при этом
   зелёная — вторая миграция там просто не выполнялась.
   Найдено на 020, которая добавляла {audit_log}.org_id, заведённую ещё в 015:
   проект писался по 001_init, а промежуточные миграции проверены не были.
   Отладка от «инсталлятор молча показал экран заново» до причины заняла шесть
   шагов — отсюда проверка, называющая виновника сразу. */
$addedColumns = [];   // «таблица.колонка» → файл
$dupColumns = [];
foreach (walk("$root/migrations", ['sql']) as $file) {
    $sql = (string)preg_replace('~--[^\n]*~', '', (string)file_get_contents($file));
    if (!preg_match_all('~ALTER\s+TABLE\s+\{(\w+)\}\s+ADD\s+COLUMN\s+`?(\w+)`?~i', $sql, $am, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($am as $a) {
        $key = strtolower($a[1] . '.' . $a[2]);
        if (isset($addedColumns[$key])) {
            $dupColumns[] = $key . ': ' . $addedColumns[$key] . ' и ' . basename($file);
            continue;
        }
        $addedColumns[$key] = basename($file);
    }
}
check($dupColumns === [], 'ни одна колонка не добавляется дважды', implode('; ', $dupColumns));
check(count($addedColumns) > 10, 'разбор ALTER-ов что-то нашёл: ' . count($addedColumns));

echo "Миграции: типы внешних ключей:\n";
/* MySQL требует, чтобы тип колонки во внешнем ключе совпадал с типом той, на
   которую он ссылается, — байт в байт. На несовпадении: «errno: 150 Foreign
   key constraint is incorrectly formed», причём таблица не создаётся вовсе.
   SQLite на типы не смотрит, поэтому в разработке всё зелёное, а на боевой
   миграция не применяется. Так и вышло с 014: {roles}.id — BIGINT UNSIGNED,
   а role_id был объявлен INT UNSIGNED. Проверяем по тексту миграций. */
$columnTypes = [];   // таблица → колонка → тип
$fkeys = [];         // [файл, таблица, колонка, целевая таблица, целевая колонка]
foreach (walk("$root/migrations", ['sql']) as $file) {
    $sql = (string)file_get_contents($file);
    if (!preg_match_all('~CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?\{(\w+)\}\s*\((.*?)\n\)~si', $sql, $tm, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($tm as $t) {
        [$whole, $table, $body] = $t;
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if (preg_match('~^(\w+)\s+((?:BIG|SMALL|TINY|MEDIUM)?INT(?:\(\d+\))?(?:\s+UNSIGNED)?)~i', $line, $cm)) {
                $columnTypes[$table][strtolower($cm[1])] = strtoupper(preg_replace('~\s+~', ' ', $cm[2]) ?? '');
            }
            if (preg_match('~FOREIGN\s+KEY\s*\((\w+)\)\s*REFERENCES\s*\{(\w+)\}\s*\((\w+)\)~i', $line, $fm)) {
                $fkeys[] = [basename($file), $table, strtolower($fm[1]), $fm[2], strtolower($fm[3])];
            }
        }
    }
}
$fkBad = [];
foreach ($fkeys as [$file, $table, $col, $refTable, $refCol]) {
    $a = $columnTypes[$table][$col] ?? null;
    $b = $columnTypes[$refTable][$refCol] ?? null;
    if ($a === null || $b === null) continue;   // колонка добавлена ALTER-ом — не сравниваем
    if ($a !== $b) $fkBad[] = "$file: $table.$col ($a) → $refTable.$refCol ($b)";
}
check($fkBad === [], 'типы внешних ключей совпадают с целевыми (' . count($fkeys) . ' связей)',
    implode('; ', $fkBad));

echo "Общие компоненты вместо копий:\n";
/* Восемнадцать кнопок сохранения в девяти шаблонах были написаны пятью разными
   наборами классов, а пятнадцать пустых состояний — четырьмя. Из-за этого одна
   и та же мысль («Порталов пока нет», «Организаций пока нет») выглядела как
   страницы из разных продуктов. Собрано в form_actions() и empty_state();
   проверка держит границу, потому что скопировать разметку соседа быстрее,
   чем вспомнить про хелпер. */
$handSave = [];
foreach ($templates as $f) {
    $src = (string)file_get_contents($f);
    if (!preg_match_all('~<button[^>]*type="submit"[^>]*>(.{0,120}?)</button>~su', $src, $bm)) continue;
    foreach ($bm[1] as $label) {
        if (str_contains($label, 'Сохранить изменения')) $handSave[] = basename($f);
    }
}
check($handSave === [], 'кнопка сохранения только через form_actions()',
    implode(', ', array_unique($handSave)));

/* offline.php — единственное честное исключение: там не «пусто», а «нет сети»,
   со своим заголовком и кнопкой повтора, то есть другая вещь. */
$handEmpty = [];
foreach ($templates as $f) {
    if (basename($f) === 'offline.php') continue;
    $src = (string)file_get_contents($f);
    if (preg_match('~class="empty[^"]*"~', $src)) $handEmpty[] = basename($f);
}
check($handEmpty === [], 'пустое состояние только через empty_state()',
    implode(', ', array_unique($handEmpty)));

/* Шесть экранов входа писали заголовок тремя разными способами
   (`h2.text-center.fs-18`, `h1.fs-22.text-center`, просто `h2`). Разнобой
   виден там, где человек ходит между экранами подряд: подтвердил почту →
   второй фактор → пароль, и каждый раз другой кегль. */
$handHead = [];
foreach ($templates as $f) {
    if (!str_contains(str_replace('\\', '/', $f), '/templates/auth/')) continue;
    if (preg_match('~<h1|<h2~', (string)file_get_contents($f))) $handHead[] = basename($f);
}
check($handHead === [], 'заголовок экрана входа только через auth_head()',
    implode(', ', $handHead));

/* Полоса действий липкая: `position: sticky` без `bottom` не липнет вовсе, а
   молча ведёт себя как statiс — глазами такое отличить нельзя. Прилипает она
   не к нулю: снизу на телефоне стоит док, и запас под него общий с тостами. */
check(preg_match('~\.form-actions\s*\{[^}]*position:\s*sticky[^}]*bottom:\s*calc\(var\(--dock-space\)~s', $css) === 1,
    'полоса действий липкая и отступает от дока');
check(preg_match('~\.toasts\s*\{[^}]*bottom:[^;]*var\(--dock-space\)~s', $css) === 1,
    'облачка уведомлений отступают от дока тем же запасом');
/* Запас обязан быть объявлен и обнулён там, где дока нет вовсе. */
check(preg_match('~:root\s*\{[^}]*--dock-space:\s*0~s', $css) === 1,
    'запас под док по умолчанию нулевой');

echo "Списки не ходят в базу построчно:\n";
/* Запрос внутри перебора — цена, растущая вместе с установкой. На трёх порталах
   её не видно; замер показал двадцать один запрос на десяти и двести один на
   сотне вместо трёх, а список ролей — сорок один вместо двух.

   Ищем только в контроллерах: в Backup и Reset запрос на таблицу — это и есть
   работа, а не небрежность. */
$loopQ = [];
$rxQ = '~(DB::(val|row)\(|::usersCount\(|::ofUser\()~';
$ctrlIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Controllers'));
foreach ($ctrlIt as $cf) {
    if (!$cf->isFile() || !str_ends_with($cf->getFilename(), '.php')) continue;
    $lines = (array)file($cf->getPathname(), FILE_IGNORE_NEW_LINES);
    $depth = -1;
    foreach ($lines as $i => $ln) {
        if (preg_match('~^\s*foreach\s*\(~', (string)$ln)) { $depth = 0; continue; }
        if ($depth < 0) continue;
        $depth += substr_count((string)$ln, '{') - substr_count((string)$ln, '}');
        if ($depth < 0) { $depth = -1; continue; }
        if (preg_match($rxQ, (string)$ln)) {
            $loopQ[] = basename($cf->getPathname()) . ':' . ($i + 1);
            $depth = -1;
        }
    }
}
check($loopQ === [], 'в контроллерах нет запроса к базе внутри перебора',
    implode(', ', $loopQ));

/* Заглушка в корне проверяется целиком отдельной батареей — tests/stub_test.php.
   Три проверки жили здесь и смотрели только на исходник; с v4.52 заглушка ещё и
   чинит, а починку нельзя проверить чтением — её надо запустить. */

echo "Миграции не оставляют открытых выборок:\n";
/* В .sql миграции нечего выбирать: она меняет схему. Оставленный там SELECT —
   почти всегда заглушка «чтобы файл был непустым», и на MySQL он ломает всю
   миграцию: exec() на SELECT оставляет открытый набор строк, следующий запрос
   падает с «Cannot execute queries while other unbuffered queries are active».
   На SQLite этого не видно, поэтому ловим статически. */
$selectInMigration = [];
foreach ((array)glob($root . '/migrations/*.sql') as $f) {
    $sql = (string)file_get_contents($f);
    /* Комментарии убираем: в них SELECT упоминается по делу. */
    $sql = (string)preg_replace('~(^|\n)\s*--[^\n]*~', '', $sql);
    if (preg_match('~(^|;)\s*SELECT\s~i', $sql)) $selectInMigration[] = basename($f);
}
check($selectInMigration === [], 'в .sql миграции нет отдельного SELECT',
    implode(', ', $selectInMigration));
/* И буферизация MySQL объявлена явно: она закрывает этот класс ошибок целиком. */
check(str_contains((string)file_get_contents($root . '/app/Core/DB.php'),
        'PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true'),
    'буферизация MySQL включена явно');

echo "Справочник ролей не расходится с системой:\n";
/* Матрица ролей описана в ROLES.md и в RoleCatalog. Документ и код обязаны
   говорить одно и то же: разойдясь, они станут двумя разными правдами, а
   читатель поверит документу.

   Классы подключаем точечно: тест шаблонов живёт без приложения и без базы, и
   тянуть ради четырёх проверок весь bootstrap незачем. */
require_once "$root/app/Core/Scope.php";
require_once "$root/app/Core/Permission.php";
require_once "$root/app/Core/AccessLevel.php";
require_once "$root/app/Core/RoleCatalog.php";
$catalog = \App\Core\RoleCatalog::ROLES;
$tiers = \App\Core\AccessLevel::TIERS;
$badLevel = [];
foreach ($catalog as $slug => $row) {
    if (!isset($tiers[$row['level']])) $badLevel[] = "$slug: {$row['level']}";
}
check($badLevel === [], 'уровень каждой роли — существующая ступень лестницы',
    implode(', ', $badLevel));

$badPerm = [];
$allPerms = \App\Core\Permission::all();
foreach ($catalog as $slug => $row) {
    if ($row['perms'] === ['*']) continue;
    foreach ($row['perms'] as $p) {
        if (!in_array($p, $allPerms, true)) $badPerm[] = "$slug: $p";
    }
}
check($badPerm === [], 'каждое право из справочника существует', implode(', ', $badPerm));

/* Роль портала не даёт прав в SSO — это граница между двумя семьями, и она
   должна держаться механически, а не обещанием в комментарии. */
$portalWithPerms = [];
foreach ($catalog as $slug => $row) {
    if ($row['family'] === \App\Core\RoleCatalog::FAMILY_PORTAL && $row['perms'] !== []) {
        $portalWithPerms[] = $slug;
    }
}
check($portalWithPerms === [], 'роль портала не несёт прав SSO',
    implode(', ', $portalWithPerms));

/* Приехавшая снаружи роль не выше администратора сервиса. «admin» портала —
   его администратор (70), а ступени управления самим SSO (75 и выше) роль
   портала не получает никогда, как бы она ни называлась. До v4.61 здесь
   требовалась двадцатка для любого «admin» — см. RoleCatalog::levelForIncoming. */
$incomingHigh = [];
foreach (['admin', 'superadmin', 'owner', 'org_owner', 'client_admin', 'support', 'auditor',
          'sso_owner', 'root', 'people_admin', 'org_admin'] as $s) {
    $lvl = \App\Core\RoleCatalog::levelForIncoming($s);
    if ($lvl > \App\Core\RoleCatalog::SERVICE_CEILING) $incomingHigh[] = "$s → $lvl";
}
check($incomingHigh === [], 'роль, приехавшая из портала, не выше администратора сервиса',
    implode(', ', $incomingHigh));

/* Документ описывает те же роли, что и код: слаг из ROLES.md обязан быть в
   справочнике, и наоборот. */
$doc = (string)file_get_contents($root . '/ROLES.md');
$docSlugs = [];
/* Берём только строки таблиц РОЛЕЙ: у них не меньше четырёх колонок. Таблица
   областей выше устроена так же, но в две колонки, и без этого условия
   `system` приезжал сюда как несуществующая роль. */
if (preg_match_all('~^\| `([a-z_]+)` \|[^|\n]*\|[^|\n]*\|~m', $doc, $dm)) {
    $docSlugs = array_unique($dm[1]);
}
/* `owner` в документе есть, а в справочнике нет — намеренно: владелец
   инсталляции получает 99 из владения, а не из выданной роли. */
$onlyDoc = array_diff($docSlugs, array_keys($catalog), ['owner']);
$onlyCode = array_diff(array_keys($catalog), $docSlugs);
check($onlyDoc === [] && $onlyCode === [], 'ROLES.md и справочник описывают одни и те же роли',
    'только в документе: ' . implode(',', $onlyDoc) . ' · только в коде: ' . implode(',', $onlyCode));

echo "Разделы объектов выглядят одинаково:\n";
/* Оболочка разделов одна на организацию, сервис, человека и здоровье, а внутри
   было кто во что горазд: в одном разделе таблица в карточке, в соседнем —
   голая посреди страницы. Замер: на одной странице стояли карточки 720px и
   904px, потому что одна просила `mw-xl`, а другая `col-12`.

   Два правила. Первое: содержимое раздела лежит в карточке. Второе: `col-*`
   имеет смысл только в сетке `.bento` — оболочка её не создаёт, и снаружи
   класс лишь притворяется, что чем-то управляет. */
$sectionDirs = ['admin/org', 'admin/client', 'admin/user', 'admin/health'];
$bareTable = [];
$fakeCol = [];
foreach ($templates as $f) {
    $rel = str_replace('\\', '/', $f);
    $inSection = false;
    foreach ($sectionDirs as $d) if (str_contains($rel, '/templates/' . $d . '/')) $inSection = true;
    if (!$inSection) continue;
    $src = (string)file_get_contents($f);

    /* Таблица вне карточки: карточка обязана открыться выше по тексту. */
    $offset = 0;
    while (($p = strpos($src, '<div class="table-wrap"', $offset)) !== false) {
        $offset = $p + 1;
        $before = substr($src, 0, $p);
        /* Внутри PHP-строки разметку не считаем: там кусок собирается и потом
           кладётся в карточку (сводка ошибок именно так и устроена). */
        if (substr_count($before, "'") % 2 === 1) continue;
        if (!str_contains($before, 'bento-card')) $bareTable[] = basename($f);
    }

    if (preg_match('~class="[^"]*\bcol-\d+~', $src) && !str_contains($src, 'class="bento"')) {
        $fakeCol[] = basename($f);
    }
}
check($bareTable === [], 'таблица раздела лежит в карточке, а не голой',
    implode(', ', array_unique($bareTable)));
check($fakeCol === [], 'col-* стоит только там, где есть сетка .bento',
    implode(', ', array_unique($fakeCol)));

echo "Мёртвых механизмов показа нет:\n";
/* Раздел «Действия» над аккаунтом был ПОЛНОСТЬЮ невидим. Разметка осталась от
   карточки со вкладками: обёртка `.tab-pane` спрятана в CSS, пока скрипт не
   повесит на неё `.active` по нажатию на вкладку. Вкладки убрали в v4.30 —
   разделы стали страницами, — а обёртка осталась. Скрипт был жив, CSS был жив,
   и только переключателя `[data-tab]` не осталось ни одного: включить обёртку
   стало нечем. Страница отдавала 200, подпись рисовалась, содержимого не было
   никогда — ни одна прежняя проверка такого не видит.

   Правило: если скрипт слушает переключатель, этот переключатель обязан
   встречаться в разметке. Иначе механизм мёртв, а всё, что на нём висит, —
   невидимо. */
$js = (string)file_get_contents($root . '/public/assets/js/app.js');
$allMarkup = '';
foreach ($templates as $f) $allMarkup .= (string)file_get_contents($f);
preg_match_all('~\[(data-[a-z-]+)\]~', $js, $tm);
/* Часть переключателей скрипт рисует сам (ящик строит свою кнопку закрытия),
   поэтому из текста скрипта убираем сами селекторы и смотрим, осталось ли
   упоминание: осталось — значит разметка есть, пусть и в скрипте. */
$jsNoSelectors = (string)preg_replace('~\[data-[a-z-]+\]~', '', $js);
$phpMarkup = (string)file_get_contents($root . '/app/helpers.php');
/* Разметку строят и классы ядра — обложку панели входа, например (v4.81). */
foreach ((array)glob($root . '/app/Core/*.php') as $coreFile) $phpMarkup .= (string)file_get_contents((string)$coreFile);
$deadTriggers = [];
foreach (array_unique($tm[1]) as $attr) {
    if (str_contains($allMarkup, $attr)) continue;
    if (str_contains($phpMarkup, $attr)) continue;
    if (str_contains($jsNoSelectors, $attr)) continue;
    $deadTriggers[] = $attr;
}
check($deadTriggers === [], 'у каждого переключателя в скрипте есть разметка',
    implode(', ', $deadTriggers));

/* И обратная сторона: класс, спрятанный вёрсткой БЕЗУСЛОВНО, не должен
   оставаться в шаблоне без того, кто его включит. Медиазапросы вырезаем: там
   «спрятать» — это ответ на ширину экрана, а не поломка (док на настольном
   экране, подпись кнопки на узком). */
$cssTop = $css;
while (($mq = strpos($cssTop, '@media')) !== false) {
    $open = strpos($cssTop, '{', $mq);
    if ($open === false) break;
    $depth = 0;
    $end = $open;
    for ($i = $open, $n = strlen($cssTop); $i < $n; $i++) {
        if ($cssTop[$i] === '{') $depth++;
        elseif ($cssTop[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
    }
    $cssTop = substr($cssTop, 0, $mq) . substr($cssTop, $end + 1);
}
preg_match_all('~(?:^|\})\s*\.([a-z][a-z0-9-]*)\s*\{[^}]*display:\s*none~', $cssTop, $hm);
$orphanHidden = [];
foreach (array_unique($hm[1]) as $cls) {
    if (!preg_match('~class="[^"]*\b' . preg_quote($cls, '~') . '\b~', $allMarkup)) continue;
    /* Включить может скрипт (модалка, ящик), соседнее правило с модификатором
       (`.x.open`) или медиазапрос — «на широком экране показать» это нормальная
       вёрстка, а не поломка. Ищем в ПОЛНОМ тексте CSS: важно лишь то, что
       где-то этот класс снова становится видимым. */
    if (str_contains($js, $cls)) continue;
    if (preg_match('~\.' . preg_quote($cls, '~') . '[^{,]*\{[^}]*display:\s*(?!none)~', $css)) continue;
    $orphanHidden[] = $cls;
}
check($orphanHidden === [], 'спрятанный вёрсткой класс кем-то включается',
    implode(', ', $orphanHidden));

echo "Каждый рисуемый шаблон существует:\n";
/* «Шаблон не найден: admin/orgs» — 500 на весь раздел. На боевой это был
   недоехавший файл, но ровно так же выглядит опечатка в имени, и до сих пор ни
   то, ни другое не ловилось ничем, кроме открытия страницы руками. */
$missingTpl = [];
$phpFiles = [];
foreach ([$root . '/app', $root . '/templates'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) $phpFiles[] = $f->getPathname();
}
foreach ($phpFiles as $f) {
    $src = (string)file_get_contents($f);
    if (!preg_match_all('~View::(?:render|fetch)\(\s*\'([a-z0-9_/-]+)\'~i', $src, $vm)) continue;
    foreach ($vm[1] as $tpl) {
        if (!is_file($root . '/templates/' . $tpl . '.php')) {
            $missingTpl[] = basename($f) . " → $tpl";
        }
    }
}
check($missingTpl === [], 'нет обращений к несуществующему шаблону',
    implode(', ', array_unique($missingTpl)));

echo "Рост элементов управления — один на всех:\n";
/* На одном экране стояли поле 41px, список 40px, кнопка 36px и малая кнопка
   27px: высоты не было ни у кого, каждый складывал свою из отступов и кегля.
   Ни одна пара не совпадала, и строка выглядела нарисованной от руки.
   Теперь высот ровно две и обе объявлены токеном. */
$tokens = (string)file_get_contents($root . '/public/assets/css/tokens.css');
check(preg_match('~--control-h:\s*\d+px~', $tokens) === 1
    && preg_match('~--control-h-s:\s*\d+px~', $tokens) === 1,
    'обе высоты объявлены токенами');
foreach (['.input, .select' => '--control-h', '.btn ' => '--control-h',
          '.btn-s ' => '--control-h-s', '.input-s, .select-s' => '--control-h-s'] as $sel => $token) {
    $rule = '';
    if (preg_match('~' . preg_quote(trim($sel), '~') . '\s*\{([^}]*)\}~s', $css, $rm)) $rule = $rm[1];
    check(str_contains($rule, 'height: var(' . $token . ')'),
        "«" . trim($sel) . "» берёт рост из $token");
}
/* Вертикальных отступов у кнопки быть не должно: с ними рост опять становится
   суммой, и токен перестаёт что-либо решать. */
preg_match('~\.btn\s*\{([^}]*)\}~s', $css, $btnRule);
check(preg_match('~padding:\s*0\s~', $btnRule[1] ?? '') === 1,
    'у кнопки нет вертикальных отступов — рост задан числом');
/* Вес у `.select` и `.select-s` одинаковый, решает порядок в файле. Малое
   правило стояло выше и молча проигрывало: в таблице список оставался 40px
   рядом с кнопкой в 32. Глазами такое отличишь не сразу, поэтому измеряем. */
check(strpos($css, '.input-s, .select-s') > strpos($css, '.input, .select { height'),
    'правило малого размера стоит ПОСЛЕ основного, иначе не сработает');

echo "Размер картинки задан не на словах:\n";
/* Аватар размечается классом `.avatar-N`. Для N, которого в CSS нет, размера не
   задано вовсе: `.avatar img` растянут на 100% родителя, родитель без ширины —
   и фотография рисуется натуральной величиной, на пол-экрана. Проверить это
   глазами можно только там, где у человека загружено фото; у остальных стоит
   буква на градиенте и всё выглядит прилично.
   Так и жило: четыре списка людей просили 28, экран блокировки — 56. */
preg_match_all('~\.avatar-(\d+)\s*\{~', $css, $cssSizes);
$haveSizes = array_map('intval', $cssSizes[1]);
$badAvatar = [];
foreach ($templates as $f) {
    if (!preg_match_all('~avatar_html\([^,()]+,\s*(\d+)\)~', (string)file_get_contents($f), $am)) continue;
    foreach ($am[1] as $n) {
        if (!in_array((int)$n, $haveSizes, true)) $badAvatar[] = basename($f) . ": $n";
    }
}
check($badAvatar === [], 'аватар просит только тот размер, что описан в CSS',
    implode(', ', array_unique($badAvatar)));
/* Список в коде и правила в CSS обязаны совпадать: помощник округляет к
   ближайшему из списка, и разойдись они — округлять будет к несуществующему. */
preg_match('~AVATAR_SIZES = \[([0-9, ]+)\]~', (string)file_get_contents($root . '/app/helpers.php'), $constM);
$constSizes = array_map('intval', array_filter(array_map('trim', explode(',', $constM[1] ?? ''))));
sort($constSizes);
sort($haveSizes);
check($constSizes === $haveSizes, 'список размеров аватара совпадает с CSS',
    'в коде: ' . implode(',', $constSizes) . ' · в CSS: ' . implode(',', $haveSizes));

echo "Контакт печатается один раз:\n";
/* user_title() — это уже «имя · контакт». Под ним печатали user_sub(), то есть
   тот же контакт второй строкой: «Артём Петров · ivan@example.org» и ниже «ivan@example.org».
   Выглядит как порча данных, хотя данные в порядке. */
$doubleHandle = [];
foreach ($templates as $f) {
    $src = (string)file_get_contents($f);
    if (preg_match_all('~user_title\((\$[a-z_]+)\)~i', $src, $tm)) {
        foreach ($tm[1] as $var) {
            if (preg_match('~user_sub\(' . preg_quote($var, '~') . '\)~', $src)) {
                $doubleHandle[] = basename($f) . " ($var)";
            }
        }
    }
}
check($doubleHandle === [], 'рядом с user_title() нет user_sub() того же человека',
    implode(', ', array_unique($doubleHandle)));

echo "Полоса действий не стоит в строке полей:\n";
/* `form_actions()` — НИЖНЯЯ полоса формы: липкая, с чертой сверху, во всю
   ширину. В `<form class="row">` она давала короткую черту в воздухе над
   кнопкой и кнопку, съехавшую относительно полей: `.row` равняет по центру, а
   у полей сверху ещё подпись. Для строки есть field_submit(). */
/* Разбор построчно, а не регуляркой по тегу целиком: в открывающем `<form>`
   почти всегда стоит `<?= ... ?>` с адресом, а внутри него есть «>», и любой
   `[^>]*` обрывается на нём. Первая версия проверки именно поэтому молчала на
   заведомо сломанном шаблоне — поймано на самой себе. */
$barInRow = [];
foreach ($templates as $f) {
    $inRowForm = false;
    foreach (explode("\n", (string)file_get_contents($f)) as $ln) {
        if (str_contains($ln, '<form') && preg_match('~class="[^"]*\brow\b~', $ln)) { $inRowForm = true; }
        if ($inRowForm && str_contains($ln, 'form_actions(')) $barInRow[] = basename($f);
        if (str_contains($ln, '</form>')) $inRowForm = false;
    }
}
check($barInRow === [], 'в строке полей кнопка через field_submit(), а не полосой',
    implode(', ', array_unique($barInRow)));

/* MRZ печатается как есть — `white-space: pre` там обязателен, иначе переводы
   строк в машинной зоне схлопнутся. Но `pre` сохраняет и пробелы САМОЙ
   разметки: перевод строки и отступ перед каждым <span> внутри кнопки уезжали
   на экран, и строка съезжала вправо на десяток знаков, а «скрыть» болталось
   посередине. Флекс-контейнер отбрасывает пробельные узлы между элементами —
   тогда вёрстка не зависит от того, как отформатирован шаблон. */
preg_match('~\.idcard-mrz\s*\{([^}]*)\}~s', $css, $mrzRule);
$mrzCss = $mrzRule[1] ?? '';
check($mrzCss !== '' && (!str_contains($mrzCss, 'white-space: pre')
        || preg_match('~display:\s*(flex|grid)~', $mrzCss) === 1),
    'MRZ с white-space: pre объявлен флексом — отступы разметки не текут на экран');

/* Картинка капчи не должна растягиваться: рисуется она в одном размере, а
   показывалась в другом (148×56 против 150×48) — цифры выходили косыми ровно
   там, где от них требуется читаться. Размер рисования и размер показа лежат в
   разных файлах и разъезжаются молча, поэтому сверяем их друг с другом. */
preg_match('~\$w = (\d+); \$h = (\d+);~', (string)file_get_contents($root . '/app/Core/Captcha.php'), $capGen);
preg_match('~\.captcha-img\s*\{([^}]*)\}~s', $css, $capRule);
preg_match('~width:\s*(\d+)px~', $capRule[1] ?? '', $capW);
preg_match('~height:\s*(\d+)px~', $capRule[1] ?? '', $capH);
check(isset($capGen[1], $capW[1], $capH[1])
        && (int)$capW[1] === (int)$capGen[1] && (int)$capH[1] === (int)$capGen[2],
    sprintf('капча показывается в своём размере: CSS %s×%s, рисуется %s×%s',
        $capW[1] ?? '?', $capH[1] ?? '?', $capGen[1] ?? '?', $capGen[2] ?? '?'));

/* И в разметке те же числа: атрибуты width/height у <img> резервируют место до
   загрузки, и разойдясь с CSS они дают скачок вёрстки на ровном месте. */
preg_match('~captcha-img[^>]*width="(\d+)" height="(\d+)"~', (string)file_get_contents($root . '/app/helpers.php'), $capAttr);
check(isset($capAttr[1], $capGen[1]) && (int)$capAttr[1] === (int)$capGen[1] && (int)$capAttr[2] === (int)$capGen[2],
    sprintf('атрибуты картинки капчи совпадают с тем, что рисуется: %s×%s',
        $capAttr[1] ?? '?', $capAttr[2] ?? '?'));

/* Полосы состояния лежат ВНУТРИ страницы. Островок прибит фиксированно к
   верхнему краю, и всё, что стоит выше него в документе, он закрывает собой. */
$baseTpl = (string)file_get_contents($root . '/templates/layouts/base.php');
$isleAt = strpos($baseTpl, '<header class="isle"');
$barsAt = strpos($baseTpl, '<?= $stateBars ?>');
check($isleAt !== false && $barsAt !== false && $barsAt > $isleAt,
    'полосы состояния выводятся после островка, а не под ним');

/* Отступы только по шкале. Ловилось это раньше только глазами и только
   случайно: `.field` годами стоял на 14px, которых в шкале нет, и ломал ритм
   везде, где рядом оказывался шкальный сосед. Проверяем сами правила, а не
   отрисовку: в браузере то же самое видно, но не в батарее.

   В списке есть и «нешкальные» числа — размеры вещей, а не промежутки:
   ширина клетки, сторона аватара, высота полосы. Отступ и размер записываются
   одним свойством, и разделить их можно только перечислением. */
/* Проверяем ВЕРТИКАЛЬНЫЙ ритм: `margin-top` и `margin-bottom`. Внутренние
   отступы контролов сюда не входят — там свои резоны и своя мелкая пластика.
   Правило: либо кратно четырём (шкала --s-*), либо не больше шести — мелкая
   подгонка подписи к полю. Четырнадцать не проходит ни по одному условию, и
   именно поэтому годами ломало ритм незаметно. */
preg_match_all('~(margin)(-top|-bottom)\s*:\s*([^;{}]+)~i', $css, $mm, PREG_SET_ORDER);
$offScale = [];
foreach ($mm as $decl) {
    if (str_contains($decl[3], 'var(') || str_contains($decl[3], 'calc(') || str_contains($decl[3], '%')) continue;
    preg_match_all('~(\d+(?:\.\d+)?)px~', $decl[3], $px);
    foreach ($px[1] as $v) {
        $n = (float)$v;
        if ($n > 6 && fmod($n, 4) != 0) $offScale[] = trim($decl[0]);
    }
}
$offScale = array_values(array_unique($offScale));
check($offScale === [], 'вертикальные отступы кратны четырём',
    implode('; ', array_slice($offScale, 0, 6)) . (count($offScale) > 6 ? ' …и ещё ' . (count($offScale) - 6) : ''));

/* Вёрстка живёт в CSS, а не в атрибуте `style`. Проверка выше меряет шкалу
   отступов по таблице стилей — и всё, что уехало в атрибут, из-под неё
   выходит: ширина 280 в одном месте и 320 в соседнем, зазор 6 при шкале из
   4 и 8. Заметить это можно было только глазами и только случайно.

   Исключения названы поимённо, а не по признаку: письма (почтовые клиенты
   вырезают <style> — там иначе нельзя) и значения, которые считает PHP —
   акцентный цвет, градиент аватара, ширина полосы прогресса. Всё остальное
   обязано быть классом. */
$styleOffenders = [];
foreach ($templates as $file) {
    if (str_contains(str_replace('\\', '/', $file), '/templates/emails/')) continue;
    $src = file_get_contents($file);
    preg_match_all('~style="([^"]*)"~', $src, $st);
    foreach ($st[1] as $decl) {
        /* Значение из PHP — единственная причина, по которой свойство не может
           жить в таблице стилей. */
        if (str_contains($decl, '<?')) continue;
        $styleOffenders[] = basename($file) . ': style="' . $decl . '"';
    }
}
check($styleOffenders === [], 'вёрстка в классах, а не в атрибуте style',
    implode('; ', array_slice($styleOffenders, 0, 5))
    . (count($styleOffenders) > 5 ? ' …и ещё ' . (count($styleOffenders) - 5) : ''));

/* Хранилище — только внутри try.
   В браузере, где localStorage запрещён (приватный режим с жёсткими
   настройками, встроенный webview, страница как data:), обращение к нему
   БРОСАЕТ. Строка 13 в app.js читала тему без защиты — и одно исключение
   убивало весь скрипт: переставали работать меню, модалки, вкладки,
   копирование, маски. Тема того не стоит. */
$js = (string)file_get_contents($root . '/public/assets/js/app.js');
/* Комментарии вырезаем целиком: в пояснении к самой этой защите слово
   localStorage, разумеется, встречается — и первая версия проверки на нём же
   и споткнулась, объявив поломкой собственный комментарий. */
$jsCode = (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $js);
$bareStorage = [];
foreach (explode("\n", $jsCode) as $n => $line) {
    if (!preg_match('~\b(local|session)Storage\b~', $line)) continue;
    if (!str_contains($line, 'try {')) $bareStorage[] = trim($line);
}
check($bareStorage === [], 'localStorage читается только внутри try',
    implode(' | ', $bareStorage));

/* Каждый шаблон закрывает ровно то, что открыл (v4.83). В «Регистрации»
   лишние два </div> закрывали колонку раздела раньше времени, и полоса
   «Сохранить» уезжала под меню разделов; ещё по одному лишнему было в профиле
   и журнале человека. Браузер такое молча чинит — по-своему. */
$tplFiles = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/templates', FilesystemIterator::SKIP_DOTS)) as $f) {
    if (str_ends_with((string)$f, '.php')) $tplFiles[] = (string)$f;
}
$unbalanced = [];
$eaten = [];
foreach ($tplFiles as $file) {
    $src = (string)file_get_contents($file);
    $open = substr_count($src, '<div');
    $close = substr_count($src, '</div>');
    if ($open !== $close) $unbalanced[] = basename(dirname($file)) . '/' . basename($file) . " ($open/$close)";
    /* PHP съедает перевод строки сразу за «?>»: строка текста, кончающаяся
       на ?>, и ссылка с начала следующей строки склеиваются без пробела —
       «в разделе«Здоровье»» в журнале почты. */
    $lines = explode("\n", str_replace("\r", '', $src));
    for ($i = 0; $i < count($lines) - 1; $i++) {
        if (preg_match('~esc\(t\(.*\)\)\s*\?>$~u', $lines[$i]) && preg_match('~^<(a|b|strong|em|code|span|kbd)\b~', $lines[$i + 1])) {
            $eaten[] = basename($file) . ':' . ($i + 2);
        }
    }
}
check($unbalanced === [], 'в каждом шаблоне <div> открыто столько же, сколько закрыто', implode(', ', $unbalanced));
/* callout() экранирует текст, если не сказано иначе: ссылка внутри без флага
   «сырой» выходит на экран HTML-кодом — так было в «Документах» (v4.83). */
$rawCallouts = [];
foreach ($tplFiles as $file) {
    $src = (string)file_get_contents($file);
    $off = 0;
    while (($pos = strpos($src, 'callout(', $off)) !== false) {
        $off = $pos + 8;
        $depth = 0;
        for ($i = $pos + 7, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '(') $depth++;
            elseif ($src[$i] === ')' && --$depth === 0) break;
        }
        $call = substr($src, $pos, $i - $pos + 1);
        if ((str_contains($call, '<a ') || str_contains($call, '</')) && !preg_match('~,\s*true\s*\)$~', $call)) {
            $rawCallouts[] = basename($file) . ':' . (substr_count(substr($src, 0, $pos), "\n") + 1);
        }
    }
}
check($rawCallouts === [], 'разметка внутри callout() — только с флагом «сырой», иначе она выходит кодом', implode(', ', $rawCallouts));
check($eaten === [], 'пробел перед ссылкой в тексте не съеден переводом строки после ?>', implode(', ', $eaten));

echo "\n" . ($fails === 0 ? "ШАБЛОНЫ OK\n" : "ШАБЛОНЫ FAILED: $fails\n");
exit($fails === 0 ? 0 : 1);
