<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Сводка ошибок: что ломается и как часто (v4.32).
 *
 * Журнал отвечает на вопрос «что случилось в этой записи». Другой вопрос —
 * «что у нас вообще ломается» — требовал листать экраны: 460 записей за день
 * это обычно шесть разных сообщений, повторённых сотни раз.
 *
 * Замер живого журнала боевой установки: 26 записей за сутки и ОДНО сообщение.
 * Одно — но оно роняло экран входа. Сводка показывает такое сразу и числом.
 */
final class ErrorDigest
{
    /**
     * Топ сообщений за последние сутки и неделю.
     *
     * @return array{day:list<array>, week:list<array>, days:int, scanned:int}
     */
    public static function top(int $limit = 8): array
    {
        $files = (array)glob(SSO_ROOT . '/storage/logs/app-*.log');
        rsort($files);
        $files = array_slice($files, 0, 8);   // неделя с запасом

        $now = time();
        $dayAgo = $now - 86400;
        $weekAgo = $now - 7 * 86400;
        $day = [];
        $week = [];
        $scanned = 0;

        foreach ($files as $file) {
            $fh = @fopen((string)$file, 'rb');
            if ($fh === false) continue;
            while (($line = fgets($fh)) !== false) {
                $scanned++;
                if (!str_contains($line, '"level":"error"') && !str_contains($line, '"level":"warning"')) continue;
                $j = json_decode($line, true);
                if (!is_array($j)) continue;
                $lvl = (string)($j['level'] ?? '');
                if ($lvl !== 'error' && $lvl !== 'warning') continue;

                $ts = strtotime((string)($j['ts'] ?? '')) ?: 0;
                if ($ts < $weekAgo) continue;

                /* Ключ — уровень плюс сообщение без переменной части: номера
                   строк и идентификаторы у одной и той же поломки разные, а
                   поломка одна. */
                $msg = (string)($j['msg'] ?? '');
                $key = $lvl . '|' . preg_replace('~\b[0-9a-f]{8,}\b|\b\d+\b~', '#', $msg);

                foreach ([[&$week, $weekAgo], [&$day, $dayAgo]] as [&$bucket, $since]) {
                    if ($ts < $since) continue;
                    if (!isset($bucket[$key])) {
                        $bucket[$key] = ['level' => $lvl, 'msg' => $msg, 'count' => 0,
                                         'last' => $ts, 'request_id' => (string)($j['request_id'] ?? '')];
                    }
                    $bucket[$key]['count']++;
                    if ($ts >= $bucket[$key]['last']) {
                        $bucket[$key]['last'] = $ts;
                        $bucket[$key]['request_id'] = (string)($j['request_id'] ?? '');
                    }
                }
                unset($bucket);
            }
            fclose($fh);
        }

        $sort = static function (array $rows) use ($limit): array {
            uasort($rows, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
            return array_slice(array_values($rows), 0, $limit);
        };

        return ['day' => $sort($day), 'week' => $sort($week),
                'days' => count($files), 'scanned' => $scanned];
    }

    /**
     * Запись журнала, пригодная к выдаче наружу.
     *
     * Событие отдаётся по API — по токену, но всё же по сети. В контексте
     * записи может оказаться что угодно: журнал пишут десятки мест, и однажды
     * туда попадёт то, чему в ответе не место. Поэтому список запретных ключей,
     * а не надежда на аккуратность каждого вызова.
     *
     * Режем по имени ключа, а не по содержимому: содержимое секрета выглядит
     * как обычная строка, а имя — нет.
     */
    public static function sanitize(array $entry): array
    {
        $secret = '~(pass|secret|token|_key|apikey|hash|cookie|authorization|otp|code_verifier)~i';
        $walk = static function (array $a) use (&$walk, $secret): array {
            $out = [];
            foreach ($a as $k => $v) {
                if (is_string($k) && preg_match($secret, $k)) { $out[$k] = '···'; continue; }
                $out[$k] = is_array($v) ? $walk($v) : $v;
            }
            return $out;
        };
        return $walk($entry);
    }

}
