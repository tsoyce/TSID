<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Rate limiting на MySQL (§8.1): bucket в PK, условные UPDATE (v5.4).
 * Окна фиксированные: window_start + длительность; новый период сбрасывает счётчик.
 */
final class RateLimiter
{
    /**
     * true — можно; false — лимит исчерпан.
     *
     * v5.4: каждое решение — одним условным запросом, и засчитывается только
     * та попытка, чей запрос изменил строку. Было «прочитать, сравнить,
     * записать»: двести одновременных запросов читали «ещё есть» и проходили
     * все — так перебор кода 2FA обходил лимит 10 за 15 минут.
     */
    public static function hit(string $bucket, int $max, int $windowSec): bool
    {
        $bucket = mb_substr($bucket, 0, 96);
        $since = now_plus(-$windowSec);
        for ($try = 0; $try < 2; $try++) {
            // Окно идёт и место есть — занимаем его
            if (DB::run('UPDATE {rate_limits} SET points = points + 1 WHERE bucket = ? AND window_start > ? AND points < ?',
                    [$bucket, $since, $max])->rowCount() === 1) return true;
            // Окно кончилось — открываем новое
            if (DB::run('UPDATE {rate_limits} SET points = 1, window_start = ? WHERE bucket = ? AND window_start <= ?',
                    [now(), $bucket, $since])->rowCount() === 1) return true;
            if (DB::val('SELECT 1 FROM {rate_limits} WHERE bucket = ?', [$bucket])) return false;
            try {
                DB::insert('rate_limits', ['bucket' => $bucket, 'points' => 1, 'window_start' => now()]);
                return true;
            } catch (\PDOException) {
                // Строку только что вставил соседний запрос — ещё круг по ней
            }
        }
        return false;
    }

    /** Секунд до конца окна (для Retry-After и «подождите N сек»). */
    public static function retryAfter(string $bucket, int $windowSec): int
    {
        $row = DB::row('SELECT window_start FROM {rate_limits} WHERE bucket = ?', [mb_substr($bucket, 0, 96)]);
        if (!$row) return $windowSec;
        $left = strtotime($row['window_start'] . ' UTC') + $windowSec - time();
        return max(1, $left);
    }

    /** Хэш IP для bucket-имени (не хранить IP открыто в PK). */
    public static function ipKey(): string
    {
        return substr(hash('sha256', request_ip()), 0, 16);
    }

    /** Проверка + фирменная страница 429 при превышении. */
    /**
     * Забыть счётчик.
     *
     * Нужно там, где удачная попытка обнуляет неудачные: пять неверных PIN
     * подряд гасят сессию, но верный PIN между ними означает, что это тот же
     * человек, а не подбор.
     */
    public static function clear(string $bucket): void
    {
        DB::run('DELETE FROM {rate_limits} WHERE bucket = ?', [mb_substr($bucket, 0, 96)]);
    }

    public static function guard(string $bucket, int $max, int $windowSec): void
    {
        if (!self::hit($bucket, $max, $windowSec)) {
            $after = self::retryAfter($bucket, $windowSec);
            header('Retry-After: ' . $after);
            ErrorPage::render(429, ['retry_after' => $after]);
        }
    }
}
