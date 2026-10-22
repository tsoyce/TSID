<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Поиск события по номеру инцидента (v4.31).
 *
 * Страница 500 показывает номер и говорит: «назовите его администратору,
 * событие найдётся в логах». Найти его при этом было нечем. Экран ошибок
 * читал последние восемьсот строк файла ЗА ОДИН день и фильтровал только по
 * уровню — то есть обещание держалось лишь в том случае, если инцидент
 * свежий и на сервере тихо. В остальных админ шёл в консоль, а владелец без
 * доступа к консоли не шёл никуда.
 *
 * Номер — точный запрос, а не просмотр: по нему ищем по ВСЕМ дням и без
 * ограничения на последние строки. Дорого это ровно один раз — когда спросили.
 */
final class Incident
{
    /** Формат номера: восемь шестнадцатеричных знаков (bin2hex(random_bytes(4))). */
    public const RE = '~^[0-9a-f]{8}$~i';

    public static function valid(string $id): bool
    {
        return (bool)preg_match(self::RE, trim($id));
    }

    /**
     * Все записи журнала с этим номером, старые сверху.
     *
     * Записей обычно несколько: один запрос успевает записать и предупреждение,
     * и падение. Показывать надо все — по одной причина часто не видна.
     *
     * @return list<array{file:string,line:int,entry:array}>
     */
    public static function find(string $id): array
    {
        $id = mb_strtolower(trim($id));
        if (!self::valid($id)) return [];

        $out = [];
        $files = (array)glob(SSO_ROOT . '/storage/logs/app-*.log');
        rsort($files);   // свежие дни первыми: инцидент чаще вчерашний, чем годичный
        foreach ($files as $file) {
            $fh = @fopen((string)$file, 'rb');
            if ($fh === false) continue;
            $n = 0;
            while (($line = fgets($fh)) !== false) {
                $n++;
                /* Сначала дешёвая проверка вхождения, и только потом разбор
                   JSON: файл журнала за день бывает на сотни тысяч строк, а
                   номер в нём встречается единицы раз. */
                if (!str_contains($line, $id)) continue;
                $j = json_decode($line, true);
                if (!is_array($j)) continue;
                if (mb_strtolower((string)($j['request_id'] ?? '')) !== $id) continue;
                $out[] = ['file' => basename((string)$file), 'line' => $n, 'entry' => $j];
            }
            fclose($fh);
            /* Нашли — дальше в глубь истории не идём: номер уникален в пределах
               запроса, и совпадение в более старом дне было бы совпадением
               случайным. */
            if ($out !== []) break;
        }
        usort($out, static fn(array $a, array $b): int => $a['line'] <=> $b['line']);
        return $out;
    }

    /** Короткое человеческое изложение — для консоли. */
    public static function describe(array $hit): string
    {
        $e = $hit['entry'];
        $ctx = $e['ctx'] ?? [];
        $lines = [
            'когда:   ' . ($e['ts'] ?? '—'),
            'уровень: ' . ($e['level'] ?? '—'),
            'что:     ' . ($e['msg'] ?? '—'),
        ];
        if (isset($ctx['file'])) $lines[] = 'где:     ' . $ctx['file'];
        foreach ((array)($ctx['trace'] ?? []) as $i => $frame) {
            if ($i > 4) { $lines[] = '         …'; break; }
            $lines[] = '         ' . $frame;
        }
        return implode("\n", $lines);
    }
}
