<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Разбор CHANGELOG.md в объекты релизов (v4.3).
 *
 * Раньше разбор жил приватным методом в контроллере «О сервисе» и умел
 * доставать только первую строку описания. Как только понадобился сам текст
 * релиза, стало ясно, что это не деталь одной страницы, а отдельная вещь:
 * файл читают и лента, и карточка релиза, а завтра — уведомление об
 * обновлении.
 *
 * Читаем файл, а не дублируем список в коде: одна версия правды, и забыть
 * обновить её невозможно.
 */
final class Changelog
{
    /**
     * Заголовок релиза: версия, а дальше в любом порядке дата и имя.
     *
     * Порядок был жёстким — `## [4.2.0] — 2026-08-01 · «Хозяин»`, — и стоило
     * написать имя перед датой, как разбор молча терял и дату, и имя: строка
     * всё равно совпадала по версии, а группы оставались пустыми. В ленте это
     * выглядело не поломкой, а «у этих релизов почему-то нет даты» — шесть
     * релизов подряд стояли без даты и без названия, и никто не спотыкался.
     *
     * Поэтому версию ловим строго, а остальное вынимаем из хвоста поштучно.
     */
    private const HEAD = '~^##\s+\[?([0-9]+\.[0-9]+\.[0-9]+)\]?(.*)$~u';

    /**
     * Разобранные релизы, новые сверху.
     *
     * @return list<array{version:string,date:string,title:string,summary:string,body:string}>
     */
    public static function releases(int $limit = 12): array
    {
        $file = SSO_ROOT . '/CHANGELOG.md';
        if (!is_file($file)) return [];

        $out = [];
        $current = null;
        $prevBlank = true;
        $body = [];
        $paragraph = [];
        foreach ((array)file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = (string)$line;
            if (preg_match(self::HEAD, $line, $m)) {
                if ($current !== null) {
                    /* Абзац мог упереться прямо в следующий заголовок — тогда
                       пустой строки, которая его закрывает, не было. */
                    if ($current['summary'] === "\x00") {
                        $current['summary'] = self::cut(implode(' ', $paragraph));
                    }
                    $current['body'] = trim(implode("\n", $body));
                    $out[] = $current;
                    if (count($out) >= $limit) return $out;
                }
                $paragraph = [];
                $tail = (string)($m[2] ?? '');
                preg_match('~\d{4}-\d{2}-\d{2}~', $tail, $dm);
                preg_match('~«([^»]+)»~u', $tail, $tm);
                $current = [
                    'version' => $m[1],
                    'date' => $dm[0] ?? '',
                    'title' => trim($tm[1] ?? ''),
                    'summary' => '',
                    'body' => '',
                ];
                $body = [];
                continue;
            }
            if ($current === null) continue;
            $body[] = $line;

            /* Сводка — начало первого настоящего абзаца.
               Мало проверить, что строка не начинается с «-»: пункты списка
               переносятся с отступом, и их продолжение выглядит обычным
               текстом. Так в ленту попадала середина фразы: «стороне — по-
               своему, и до сих пор портал получал…». Начало абзаца узнаётся
               по двум признакам разом: перед ним пустая строка и сам он
               написан от левого края. */
            if ($current['summary'] === '' && $prevBlank
                && $line !== '' && !str_starts_with($line, ' ') && !str_starts_with($line, "\t")) {
                $t = trim($line);
                if ($t !== '' && !str_starts_with($t, '#') && !str_starts_with($t, '-')
                    && !str_starts_with($t, '*') && !str_starts_with($t, '|') && !str_starts_with($t, '>')) {
                    /* Помечаем начало абзаца, а не берём строку целиком: в
                       файле абзац разложен по строкам в восемьдесят знаков, и
                       «сводкой» становился первый перенос — фраза, оборванная
                       там, где кончилась строка в редакторе. Собираем абзац
                       ниже, когда встретим пустую строку. */
                    $current['summary'] = "\x00";
                    $paragraph = [$t];
                }
            } elseif (($current['summary'] ?? '') === "\x00") {
                $t = trim($line);
                if ($t === '') {
                    $current['summary'] = self::cut(implode(' ', $paragraph));
                    $paragraph = [];
                } else {
                    $paragraph[] = $t;
                }
            }
            $prevBlank = trim($line) === '';
        }
        if ($current !== null) {
            if ($current['summary'] === "\x00") {
                $current['summary'] = self::cut(implode(' ', $paragraph));
            }
            $current['body'] = trim(implode("\n", $body));
            $out[] = $current;
        }
        return $out;
    }

    /**
     * Сводка для ленты: вводный абзац, а если его нет — первый пункт списка.
     *
     * Не у всякого релиза есть вступление: часть начинается прямо с «###
     * Добавлено» и перечня. Пустая строка в ленте честна, но бесполезна, а
     * первый пункт как раз говорит, о чём релиз.
     */
    /**
     * Обрезка по смыслу, а не по счётчику символов.
     *
     * Резали ровно на двухсотом знаке, и в ленте получалось «…не ловила ни одна
     * из прежних проверок: каталог» — фраза, оборванная посреди слова, без
     * многоточия. Читается не как «текст длинный», а как «текст побился».
     *
     * Сначала пробуем закончить на точке: одно законченное предложение говорит
     * о релизе больше, чем два обрубка. Не вышло — режем по последнему пробелу
     * и ставим многоточие, чтобы обрыв был назван.
     */
    private static function cut(string $text, int $limit = 200): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $limit) return $text;
        $head = mb_substr($text, 0, $limit);
        $dot = max(mb_strrpos($head, '. ') ?: 0, mb_strrpos($head, '! ') ?: 0);
        /* Половина предела — граница разумного: предложение короче ста знаков
           годится как сводка, а «Да.» из первых трёх — нет. */
        if ($dot > $limit / 2) return mb_substr($head, 0, $dot + 1);
        $space = mb_strrpos($head, ' ');
        return rtrim(mb_substr($head, 0, $space ?: $limit), " ,;:—-") . '…';
    }

    public static function summary(array $release): string
    {
        $s = trim((string)($release['summary'] ?? ''));
        /* Выделение снимаем и здесь. Раньше — только в запасной ветке ниже, и
           сводка выпуска, у которого есть свой абзац, показывалась с сырыми
           звёздочками и обратными кавычками: в ленте это читается как мусор.
           Одно правило на обе ветки — иначе половина списка выглядит иначе. */
        if ($s !== '') return self::cut(trim(preg_replace('~[*_`]+~u', '', $s) ?? ''));

        /* Пункт списка тоже разложен по строкам: продолжение идёт с отступом.
           Брали только первую строку — и сводка обрывалась там, где в файле
           кончилась строка, а не там, где кончилась мысль. */
        $lines = explode("\n", (string)($release['body'] ?? ''));
        foreach ($lines as $i => $line) {
            if (!preg_match('~^[-*]\s+(.+)$~u', trim($line), $m)) continue;
            $parts = [$m[1]];
            for ($j = $i + 1; $j < count($lines); $j++) {
                $next = $lines[$j];
                /* Продолжение пункта — строка с отступом. Пустая строка, новый
                   пункт или заголовок его закрывают. */
                if (trim($next) === '' || !preg_match('~^\s+\S~', $next)) break;
                if (preg_match('~^\s*[-*]\s~', $next)) break;
                $parts[] = trim($next);
            }
            /* Снимаем выделение: `**Что-то.**` в ленте читается как мусор. */
            $t = trim(preg_replace('~[*_`]+~u', '', implode(' ', $parts)) ?? '');
            if ($t !== '') return self::cut($t);
        }
        return '';
    }

    /** Один релиз по версии или null. */
    public static function release(string $version): ?array
    {
        foreach (self::releases(100) as $r) {
            if ($r['version'] === $version) return $r;
        }
        return null;
    }

    /**
     * Тело релиза в HTML.
     *
     * Через тот же Markdown, что и правовые документы: он экранирует сначала и
     * размечает потом, поэтому чужая разметка из файла не превратится в теги.
     * CHANGELOG правит тот же человек, что и код, но правило «не доверяй
     * содержимому файла» дешевле соблюдать всегда, чем вспоминать про
     * исключения.
     */
    public static function html(array $release): string
    {
        return Markdown::toHtml((string)($release['body'] ?? ''));
    }

}
