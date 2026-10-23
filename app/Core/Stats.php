<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Цифры для дашборда, /metrics и профиля — из одного места (v4.98).
 *
 * До v4.98 «вход» был определён трижды и по-разному: дашборд считал пароль и
 * ссылку из письма, /metrics — пароль, passkey и соцсети, профиль — как
 * дашборд. Человек, вошедший по passkey, на дашборде не появлялся вовсе.
 * Второй промах — сутки: их резали по UTC, а показывали по поясу установки,
 * и для UTC+3 «сегодня» начиналось в 03:00.
 */
final class Stats
{
    /** Все успешные входы, каким бы способом они ни шли. */
    public const LOGIN_EVENTS = ['login.success', 'login.magic_link', 'login.passkey', 'login.social'];

    /** Пояс, в котором человек читает даты, — тот же, что у fmt_dt(). */
    public static function tz(): DateTimeZone
    {
        try {
            return new DateTimeZone((string)(Auth::user()['timezone'] ?? Setting::timezone()));
        } catch (\Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /** UTC-время начала местных суток: 0 — сегодня, 1 — вчера и т. д. */
    public static function dayStart(int $daysAgo = 0, ?int $now = null): string
    {
        $local = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(self::tz())
            ->setTime(0, 0)->modify('-' . $daysAgo . ' days');
        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** `IN (?, ?, …)` под список событий входа. */
    public static function loginIn(): string
    {
        return 'event IN (' . implode(', ', array_fill(0, count(self::LOGIN_EVENTS), '?')) . ')';
    }

    /** Успешных входов с момента $since (UTC). */
    public static function logins(string $since): int
    {
        return (int)DB::val('SELECT COUNT(*) FROM {audit_log} WHERE ' . self::loginIn() . ' AND created_at >= ?',
            array_merge(self::LOGIN_EVENTS, [$since]));
    }

    /**
     * Ряд по местным суткам: [Y-m-d => n], старые слева, пустой день — ноль.
     *
     * База группирует по часам UTC, а сутки собираются здесь: группировать
     * сразу по местной дате SQL не умеет одинаково на MySQL и SQLite. Пояса в
     * настройках — целые часы (tz_options), так что час в сутки не раскалывается;
     * только пояс из config.php может быть «с половиной», и тогда граница дня в
     * графике сдвинется на полчаса — для ряда по дням это терпимо.
     */
    public static function perDay(string $table, string $where, array $params, int $days, ?int $now = null): array
    {
        $now ??= time();
        $rows = DB::all('SELECT SUBSTR(created_at, 1, 13) AS h, COUNT(*) AS n FROM {' . $table . '} WHERE ' . $where
            . ' AND created_at >= ? GROUP BY SUBSTR(created_at, 1, 13)',
            array_merge($params, [self::dayStart($days - 1, $now)]));
        $tz = self::tz();
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $out[(new DateTimeImmutable('@' . $now))->setTimezone($tz)->modify('-' . $i . ' days')->format('Y-m-d')] = 0;
        }
        foreach ($rows as $r) {
            $d = (new DateTimeImmutable($r['h'] . ':00:00', new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
            if (isset($out[$d])) $out[$d] += (int)$r['n'];
        }
        return $out;
    }
}
