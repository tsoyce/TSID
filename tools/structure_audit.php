<?php
/**
 * Устройство страницы для того, кто на неё не смотрит.
 *
 * Заголовки, ориентиры и поведение фокуса — три вещи, которыми человек
 * понимает, куда попал и куда идти, не видя экрана. Проверяются вместе,
 * потому что порознь каждая выглядит мелочью, а вместе они и есть
 * навигация по странице.
 *
 * Заголовки — это не размер шрифта, а оглавление страницы. По ним ходит
 * программа чтения с экрана, режим чтения в браузере и поисковик; человеку с
 * клавиатурой это единственный способ понять, куда он попал, не слушая всё
 * подряд. Страница без h1 представляется безымянной.
 *
 * Так и было: /login, /register, /recover и /consent шли БЕЗ единого h1 —
 * четыре самых показываемых экрана продукта, — а первым заголовком оказывалось
 * название установки из декоративной панели слева. То есть страница входа
 * представлялась не «Вход», а названием установки.
 *
 * Проверка статическая: разбирает шаблоны, а не отрисованные страницы. Это
 * дешевле (не нужен поднятый сервер) и честнее в одном: она видит ВСЕ ветки
 * шаблона, включая те, куда живой запрос сегодня не заходит.
 *
 * Запуск: php tools/structure_audit.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$fails = 0;
$checked = 0;

/**
 * Шаблоны-страницы: те, что отрисовываются через View::render.
 *
 * Куски (`_role_grants`, `kit/*`) и письма сюда не входят: у куска своего
 * заголовка первого уровня быть и не должно, а письмо — не страница.
 */
function pageTemplates(): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(SSO_ROOT . '/templates', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', (string)$f);
        if (!str_ends_with($p, '.php')) continue;
        $rel = substr($p, strlen(str_replace('\\', '/', SSO_ROOT)) + 1);
        if (str_starts_with($rel, 'templates/emails/')) continue;
        if (str_starts_with($rel, 'templates/kit/')) continue;
        if (str_starts_with($rel, 'templates/layouts/')) continue;
        if (str_contains($rel, '/_')) continue;          // куски
        if (basename($rel)[0] === '_') continue;
        $out[$rel] = (string)file_get_contents($p);
    }
    ksort($out);
    return $out;
}

/**
 * Из каких кусков собирается страница.
 *
 * Заголовок живёт не всегда в самом шаблоне: экраны входа берут его из
 * `auth_tabs()`, карточки — из оболочки. Поэтому вместе с шаблоном смотрим на
 * то, что он подключает, иначе проверка объявит безголовым то, у чего голова
 * в помощнике.
 */
function emitsH1(string $src): bool
{
    if (preg_match('~<h1[\s>]~i', $src)) return true;
    /* Помощники, про которые известно, что они печатают h1. */
    foreach (['auth_head(', 'auth_tabs(', 'object_shell', 'settings_shell', 'page_head('] as $needle) {
        if (str_contains($src, $needle)) return true;
    }
    return false;
}

echo "═══ Устройство страницы ═══\n";
echo "Заголовки, ориентиры и фокус — то, чем человек понимает страницу, не видя её.\n\n";

/* 1. Помощники, объявленные как источники h1, обязаны его печатать. */
$helpers = (string)file_get_contents(SSO_ROOT . '/app/helpers.php');
foreach (['auth_head', 'auth_tabs'] as $fn) {
    $checked++;
    if (!preg_match('~function\s+' . $fn . '\b.*?\n}~s', $helpers, $m) || !str_contains($m[0], '<h1')) {
        echo "  ✗ $fn() числится источником заголовка, а h1 не печатает\n";
        $fails++;
    }
}

/* 2. Ни один шаблон-страница не печатает ДВА h1. */
foreach (pageTemplates() as $rel => $src) {
    $checked++;
    $n = preg_match_all('~<h1[\s>]~i', $src);
    if ($n > 1) {
        echo "  ✗ $rel — h1 несколько ($n)\n";
        $fails++;
        continue;
    }
    /* Шаблон, где h1 есть, но выше него стоит заголовок помельче, даёт
       перевёрнутое оглавление: раздел раньше названия страницы. */
    if ($n === 1 && preg_match('~<h([2-6])[\s>].*?<h1[\s>]~is', $src)) {
        echo "  ✗ $rel — h1 идёт после более мелкого заголовка\n";
        $fails++;
    }
}

/* 3. Декоративные блоки не занимают уровни оглавления.
      Панель оформления на экране входа была h2 и оказывалась первым
      заголовком страницы — раньше её собственного имени. */
$checked++;
if (preg_match('~function\s+auth_media_pane\b.*?\n}~s', $helpers, $m)
    && preg_match('~<h[1-6][\s>]~i', $m[0])) {
    echo "  ✗ auth_media_pane() печатает заголовок — панель оформления не раздел страницы\n";
    $fails++;
}

/* 4. Значки и инициалы не читаются вслух: рядом всегда стоит имя. */
foreach (['client_icon_html', 'avatar_html'] as $fn) {
    $checked++;
    if (!preg_match('~function\s+' . $fn . '\b.*?\n}~s', $helpers, $m)) continue;
    /* Ветка с картинкой закрыта alt=""; ветка с буквами — только aria-hidden. */
    if (str_contains($m[0], 'esc(user_initials') || str_contains($m[0], 'esc($letter)')) {
        if (!str_contains($m[0], 'aria-hidden')) {
            echo "  ✗ $fn() рисует инициалы без aria-hidden — их зачитают перед именем\n";
            $fails++;
        }
    }
}

/* 4.5. Два источника заголовка на одном экране.
        Вкладки печатают невидимый h1 с именем экрана, а плашка «Вход в
        такой-то» — видимый. Вместе они дают ДВА первых заголовка, и оглавление
        страницы перестаёт быть однозначным. На экране с вкладками плашка
        обязана идти третьим доводом — `false`. */
foreach (pageTemplates() as $rel => $src) {
    if (!str_contains($src, 'auth_tabs(') || !str_contains($src, 'auth_context(')) continue;
    $checked++;
    /* `[^)]*` не годится: внутри вызова стоит t('…'), и своя закрывающая
       скобка обрывает поиск раньше времени — проверка объявляла нарушением
       правильный код. */
    if (!preg_match('~auth_context\(.{0,160}?,\s*false\s*\)~s', $src)) {
        echo "  ✗ $rel — вкладки и плашка-заголовок вместе: получится два h1\n";
        $fails++;
    }
}

/* 5. Ориентиры страницы: куда прыгать тому, кто не смотрит на экран.
      <main> — единственная область, о которой программа чтения спрашивает
      первым делом. Он был только в базовом макете; экраны входа и гостевой
      главной landmark-ов не имели вовсе. */
$layouts = [
    'base'  => ['skip' => true,  'why' => 'островок сверху и док снизу'],
    'guest' => ['skip' => true,  'why' => 'шапка и витрина порталов'],
    /* У экранов входа перед формой нет ни одного элемента, на который встаёт
       фокус: панель слева — картинка. Пропускать нечего, и лишняя ссылка в
       начале была бы шумом. Landmark при этом нужен всё равно. */
    'auth'  => ['skip' => false, 'why' => 'перед формой нечего пропускать'],
];
foreach ($layouts as $name => $rule) {
    $file = SSO_ROOT . "/templates/layouts/$name.php";
    if (!is_file($file)) continue;
    $src = (string)file_get_contents($file);
    $checked++;
    $mains = preg_match_all('~<main\b[^>]*>~i', $src, $mm);
    if ($mains === 0) {
        echo "  ✗ layouts/$name.php — нет <main>: программе чтения не за что зацепиться\n";
        $fails++;
    } elseif (!str_contains(implode(' ', $mm[0]), 'id="main"')) {
        echo "  ✗ layouts/$name.php — у <main> нет id=\"main\", ссылка «к содержимому» ведёт в пустоту\n";
        $fails++;
    }
    $checked++;
    $hasSkip = str_contains($src, 'skip_link(');
    if ($rule['skip'] && !$hasSkip) {
        echo "  ✗ layouts/$name.php — нет ссылки «к содержимому», а пропускать есть что ({$rule['why']})\n";
        $fails++;
    }
}
$checked++;
if (preg_match('~function\s+skip_link\b.*?\n}~s', $helpers, $m) && !str_contains($m[0], 'href="#main"')) {
    echo "  ✗ skip_link() ведёт не на #main — ссылка обещает прыжок и не делает его\n";
    $fails++;
}

/* 6. Фокус не уезжает под закреплённые панели (WCAG 2.2 «Focus Not Obscured»).
      Островок сверху и док снизу — `position: fixed`: в потоке их нет, и
      браузер подводит цель к самому краю окна, то есть прямо под них. Замер:
      цель вставала на 0 при нижнем крае островка на 68 — с отступом на 80.
      Число не зашиваем: высота островка живёт токеном, и вторая правда о ней
      разошлась бы с первой при первой же правке. */
$css = (string)file_get_contents(SSO_ROOT . '/public/assets/css/app.css');
$checked++;
if (!preg_match('~scroll-padding-top:\s*([^;]+);~', $css, $m)) {
    echo "  ✗ нет scroll-padding-top — фокус и якоря уезжают под закреплённый островок\n";
    $fails++;
} elseif (!str_contains($m[1], '--isle-h')) {
    echo "  ✗ scroll-padding-top задан числом, а не высотой островка: «" . trim($m[1]) . "»\n";
    $fails++;
}
$checked++;
if (!str_contains($css, 'scroll-padding-bottom')) {
    echo "  ✗ нет scroll-padding-bottom — на телефоне фокус уезжает под нижний док\n";
    $fails++;
}

/* 7. После неудачной отправки курсор идёт в непринятое поле.
      Форма возвращается тем же POST-ом, ошибка покрашена и объяснена, а фокус
      лежит в начале страницы: человеку с клавиатурой надо пройти всю
      навигацию и половину формы до места, которое система уже знает. */
$js = (string)file_get_contents(SSO_ROOT . '/public/assets/js/app.js');
$checked++;
if (!preg_match('~querySelector\(\s*[\x27"]\[aria-invalid="true"\][\x27"]\s*\)~', $js)) {
    echo "  ✗ app.js не переводит фокус в первое непринятое поле после отправки\n";
    $fails++;
}

printf("Проверено: %d\n", $checked);
echo "\n══ Итог ══\n";
if ($fails === 0) echo "УСТРОЙСТВО СТРАНИЦЫ В ПОРЯДКЕ\n";
else printf("НАРУШЕНИЙ: %d\n", $fails);
exit($fails === 0 ? 0 : 1);
