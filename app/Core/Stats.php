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

    /** Периоды пульта, в днях (v4.99). 1 — «сегодня», по часам. */
    public const PERIODS = [1, 7, 14, 30, 90];

    /** Способы входа — для графика «по способам»: событие => подпись. */
    public static function methods(): array
    {
        return [
            'login.success' => t('Пароль'),
            'login.magic_link' => t('Ссылка из письма'),
            'login.passkey' => t('Passkey'),
            'login.social' => t('Соцсети'),
        ];
    }

    /** Период из запроса: незнакомое число — 14 дней. */
    public static function period(mixed $raw): int
    {
        $p = (int)$raw;
        return in_array($p, self::PERIODS, true) ? $p : 14;
    }

    /**
     * Границы периода и предыдущего такого же (UTC): [с, прошлый с].
     * «Сегодня» сравнивается со вчера целиком: часть вчерашнего дня против
     * части сегодняшнего — честнее, но «до этого часа» никто не помнит.
     */
    public static function bounds(int $days, ?int $now = null): array
    {
        return [self::dayStart($days - 1, $now), self::dayStart(2 * $days - 1, $now)];
    }

    /** Сколько строк в [с, до). */
    public static function count(string $table, string $where, array $params, string $since, ?string $until = null): int
    {
        $sql = 'SELECT COUNT(*) FROM {' . $table . '} WHERE ' . $where . ' AND created_at >= ?';
        $params[] = $since;
        if ($until !== null) { $sql .= ' AND created_at < ?'; $params[] = $until; }
        return (int)DB::val($sql, $params);
    }

    /** Ряд по часам местных суток сегодня: [«H» => n], 0…23. */
    public static function perHour(string $table, string $where, array $params, ?int $now = null): array
    {
        $now ??= time();
        $rows = DB::all('SELECT SUBSTR(created_at, 1, 13) AS h, COUNT(*) AS n FROM {' . $table . '} WHERE ' . $where
            . ' AND created_at >= ? GROUP BY SUBSTR(created_at, 1, 13)', array_merge($params, [self::dayStart(0, $now)]));
        $out = array_fill_keys(array_map(static fn($h) => sprintf('%02d', $h), range(0, 23)), 0);
        $tz = self::tz();
        foreach ($rows as $r) {
            $h = (new DateTimeImmutable($r['h'] . ':00:00', new DateTimeZone('UTC')))->setTimezone($tz)->format('H');
            $out[$h] += (int)$r['n'];
        }
        return $out;
    }

    /** Ряд за период: по часам для «сегодня», по дням для остального. */
    public static function series(string $table, string $where, array $params, int $days, ?int $now = null): array
    {
        return $days === 1 ? self::perHour($table, $where, $params, $now) : self::perDay($table, $where, $params, $days, $now);
    }

    /** Входы по способам: [событие => ряд]. */
    public static function loginsByMethod(int $days, ?int $now = null): array
    {
        $out = [];
        foreach (array_keys(self::methods()) as $e) $out[$e] = self::series('audit_log', 'event = ?', [$e], $days, $now);
        return $out;
    }

    /**
     * Порталы по входам за период: первый вход в портал из сессии
     * (session_clients) — то самое «зашёл в портал через SSO».
     * @return list<array{id:int, name:string, n:int}>
     */
    public static function portals(string $since, int $limit = 5): array
    {
        return array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'n' => (int)$r['n']],
            DB::all('SELECT c.id, c.name, COUNT(*) AS n FROM {session_clients} sc JOIN {clients} c ON c.id = sc.client_id
                     WHERE sc.created_at >= ? GROUP BY c.id, c.name ORDER BY n DESC, c.name LIMIT ' . $limit, [$since]));
    }

    /**
     * Защита аккаунтов: у скольких живых людей есть passkey или второй фактор.
     * Только число — ничего не требует (необязательность — принцип проекта).
     * @return array{total:int, protected:int}
     */
    public static function protection(): array
    {
        return [
            'total' => (int)DB::val('SELECT COUNT(*) FROM {users} WHERE status != ?', ['deleted']),
            'protected' => (int)DB::val('SELECT COUNT(*) FROM {users} u WHERE u.status != ? AND (u.totp_enabled_at IS NOT NULL
                OR EXISTS (SELECT 1 FROM {passkeys} p WHERE p.user_id = u.id))', ['deleted']),
        ];
    }

    /** Разница к прошлому периоду в процентах; null — сравнивать не с чем. */
    public static function delta(int $now, int $prev): ?int
    {
        if ($prev === 0) return null;
        return (int)round(($now - $prev) / $prev * 100);
    }
}
