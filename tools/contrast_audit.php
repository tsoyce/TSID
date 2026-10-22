<?php
/**
 * Контраст текста и подложек — счётом, а не глазами.
 *
 * «Читается» — не мнение, а число: WCAG 2.1 требует 4.5 для обычного текста и
 * 3.0 для крупного. Глаз этого не измеряет и вдобавок подводит: на хорошем
 * мониторе в светлой комнате слабый серый выглядит нормально, а на дешёвой
 * матрице под лампой исчезает совсем.
 *
 * Проверять пары «цвет текста × цвет фона» приходится не по факту вёрстки, а
 * по НАБОРУ: подложек в проекте несколько (страница, карточка, вторая
 * поверхность, подкраска акцентом), и текст может оказаться на любой из них.
 * Пара, посчитанная только для белого фона, — это гарантия, действующая ровно
 * до первой подкраски. Так и вышло: --text-3 брал 5.04 на белом и 3.50 на
 * подложке гостевых экранов, где стоят подсказки и ссылки на документы.
 *
 * Отдельная строка — заливка со ГРАДИЕНТОМ. Палитра подбирает цвет текста под
 * --ink, а знак портала красится в --ink-grad, светлый конец которого светлее
 * --ink. Гарантия дана для одного цвета, краска положена другим.
 *
 * Запуск: php tools/contrast_audit.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Palette;

$fails = 0;
$checks = 0;

/** Значения токенов из tokens.css: [светлая, тёмная]. */
function tokens(): array
{
    $css = (string)file_get_contents(SSO_ROOT . '/public/assets/css/tokens.css');
    /* Тёмная тема живёт в своём блоке; всё до него — светлая. */
    $cut = strpos($css, '[data-theme="dark"]');
    if ($cut === false) throw new \RuntimeException('в tokens.css нет блока тёмной темы');
    $out = ['light' => [], 'dark' => []];
    foreach (['light' => substr($css, 0, $cut), 'dark' => substr($css, $cut)] as $theme => $part) {
        if (preg_match_all('~(--[a-z0-9-]+)\s*:\s*(#[0-9A-Fa-f]{3,8})\s*;~', $part, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) $out[$theme][$hit[1]] = strtoupper($hit[2]);
        }
    }
    /* Светлая — основа: тёмная переопределяет не всё. */
    $out['dark'] += $out['light'];
    return $out;
}

/** Наложение цвета с прозрачностью на подложку. */
function blend(string $fg, string $bg, float $alpha): string
{
    $a = sscanf($fg, '#%2x%2x%2x');
    $b = sscanf($bg, '#%2x%2x%2x');
    return sprintf('#%02X%02X%02X',
        (int)round($a[0] * $alpha + $b[0] * (1 - $alpha)),
        (int)round($a[1] * $alpha + $b[1] * (1 - $alpha)),
        (int)round($a[2] * $alpha + $b[2] * (1 - $alpha)));
}

/**
 * Подложки, на которых может оказаться текст.
 *
 * Список объявлен, а не выведен из вёрстки: вывести его нельзя — подложка
 * складывается из фона элемента, подкраски и всего, что просвечивает сверху.
 * Зато объявленный список читается и обсуждается, а «случайно не проверили»
 * тут не бывает: не найдя пары, проверка молчит, а не додумывает.
 *
 * Силу подкраски читаем ИЗ CSS, а не держим числом здесь: --wash кладёт три
 * радиальных пятна, и стоит кому-то поправить проценты, как проверка обязана
 * узнать об этом сама.
 */
function surfaces(array $t, string $theme): array
{
    $ink = $t['--ink'];
    $w = washStrength($theme);
    return [
        'страница (--bg)'                 => $t['--bg'],
        'карточка (--surface)'            => $t['--surface'],
        'вторая поверхность (--surface-2)' => $t['--surface-2'],
        'подкраска на карточке (--wash)'   => blend($ink, $t['--surface'], $w),
        'подкраска на странице (--wash)'   => blend($ink, $t['--bg'], $w),
        'мягкая заливка (--ink-soft)'      => blend($ink, $t['--surface'], 0.10),
    ];
}

/**
 * Худшая точка подкраски.
 *
 * Пятна лежат по разным углам и целиком не совпадают, поэтому считать их
 * простым наложением — значит требовать невозможного и чинить несуществующее.
 * Но и брать самое сильное пятно как есть нельзя: края соседних пятен до него
 * дотягиваются. Замер живой страницы в браузере дал 22% при объявленных 18% —
 * отсюда поправка. Число проверяемое: цвет фона под подсказками на /register
 * измерялся getComputedStyle, а не оценивался на глаз.
 */
function washStrength(string $theme): float
{
    /* Тёмная тема переопределяет --wash у себя (одинаковый процент двигает
       яркость по-разному, смотря светлее акцент фона или темнее). Ищем сперва
       её объявление, и только если его нет — общее. */
    $sources = [SSO_ROOT . '/public/assets/css/tokens.css',
                SSO_ROOT . '/public/assets/css/identity.css'];
    $peak = null;
    foreach ($sources as $file) {
        $css = (string)file_get_contents($file);
        $cut = strpos($css, '[data-theme="dark"]');
        $part = $theme === 'dark'
            ? ($cut === false ? '' : substr($css, $cut))
            : ($cut === false ? $css : substr($css, 0, $cut));
        if (preg_match('~--wash:(.*?);~s', $part, $m)) {
            preg_match_all('~var\(--ink\)\s*(\d+)%~', $m[1], $mm);
            if ($mm[1] !== []) { $peak = max(array_map('intval', $mm[1])); break; }
        }
    }
    if ($peak === null) throw new \RuntimeException("не нашёл --wash для темы «{$theme}»");
    return min(0.6, $peak / 100 * 1.25);
}

/** Цвета текста и то, чем они бывают. */
function texts(array $t): array
{
    return [
        '--text'     => $t['--text'],
        '--text-2'   => $t['--text-2'],
        '--text-3'   => $t['--text-3'],
        '--ink-text' => $t['--ink-text'],
    ];
}

const NEED = 4.5;

echo "═══ Контраст текста и подложек ═══\n";
echo "Порог WCAG 2.1 для обычного текста: " . NEED . "\n";

$all = tokens();
foreach (['light' => 'Светлая тема', 'dark' => 'Тёмная тема'] as $theme => $title) {
    $t = $all[$theme];
    echo "\n── $title ──\n";
    $surf = surfaces($t, $theme);
    $txt = texts($t);

    printf("  %-34s", '');
    foreach ($txt as $name => $_) printf('%12s', $name);
    echo "\n";

    foreach ($surf as $sname => $sval) {
        printf('  %-26s %-7s', $sname, $sval);
        foreach ($txt as $tname => $tval) {
            $r = Palette::contrast($tval, $sval);
            $checks++;
            if ($r < NEED) { $fails++; printf('%11.2f✗', $r); }
            else printf('%12.2f', $r);
        }
        echo "\n";
    }

    /* Заливка с градиентом: текст на ней обязан читаться на ОБОИХ концах.
       Формула --ink-grad из identity.css. */
    echo "\n  Знак портала (--ink-grad, текст --on-ink):\n";
    $onInk = Palette::contrast($t['--ink'], '#FFFFFF') >= Palette::contrast($t['--ink'], '#12100E')
        ? '#FFFFFF' : '#12100E';
    /* Формулу берём ИЗ CSS, а не из головы: проверка обязана мерить то, что
       написано, иначе она проверяет намерение автора, а не его код. */
    $idc = (string)file_get_contents(SSO_ROOT . '/public/assets/css/identity.css');
    if (!preg_match('~--ink-grad:\s*linear-gradient\([^;]*?var\(--ink\)\s*(\d+)%\s*,\s*var\((--[a-z0-9-]+)\)~s', $idc, $gm)) {
        throw new \RuntimeException('не разобрал --ink-grad в identity.css');
    }
    $share = (int)$gm[1] / 100;
    $toward = $t[$gm[2]] ?? $onInk;
    $ends = [
        'тёмный конец (сам --ink)' => $t['--ink'],
        sprintf('второй конец (%d%% --ink + %s)', (int)$gm[1], $gm[2])
            => blend($t['--ink'], $toward, $share),
    ];
    foreach ($ends as $ename => $eval) {
        $r = Palette::contrast($onInk, $eval);
        $checks++;
        printf("    %-38s %-8s %.2f %s\n", $ename, $eval, $r, $r < NEED ? '✗' : '');
        if ($r < NEED) $fails++;
    }
}

/* ---------- Границы органов управления ----------
   У нетекстовых элементов своя норма — 3:1 (WCAG 1.4.11). По границе человек
   опознаёт, что перед ним поле, а не плашка с текстом; по цвету дорожки —
   включён тумблер или нет.

   Замер до v4.49: рамка поля давала 1.49 при норме 3.0, и это при том, что
   собственная заливка поля отличалась от подложки на 1.05 — то есть глазом не
   отличалась вовсе, а внутри карточек совпадала полностью. Поле опознавалось
   не по виду, а по тому, что в него удалось напечатать. */
echo "\n── Границы органов управления (норма 3.0) ──\n";
const NEED_UI = 3.0;
foreach (['light' => 'Светлая', 'dark' => 'Тёмная'] as $theme => $title) {
    $t = $all[$theme];
    $onInk = Palette::contrast($t['--ink'], '#FFFFFF') >= Palette::contrast($t['--ink'], '#12100E')
        ? '#FFFFFF' : '#12100E';
    $pairs = [
        'рамка поля на карточке'      => [$t['--control-line'], $t['--surface']],
        'дорожка тумблера (выключен)' => [$t['--control-line'], $t['--surface']],
        'дорожка тумблера (включён)'  => [$t['--ink'], $t['--surface']],
        'ручка на выключенной дорожке' => ['#FFFFFF', $t['--control-line']],
        'ручка на включённой дорожке'  => [$onInk, $t['--ink']],
    ];
    printf("  %s:\n", $title);
    foreach ($pairs as $name => [$a, $b]) {
        $r = Palette::contrast($a, $b);
        $checks++;
        printf("    %-32s %-8s на %-8s %.2f %s\n", $name, $a, $b, $r, $r < NEED_UI ? '✗' : '');
        if ($r < NEED_UI) $fails++;
    }
}

echo "\n══ Итог ══\n";
printf("Пар проверено: %d, ниже порога: %d\n", $checks, $fails);
if ($fails === 0) echo "КОНТРАСТ ДЕРЖИТСЯ НА ВСЕХ ПОДЛОЖКАХ\n";
exit($fails === 0 ? 0 : 1);
