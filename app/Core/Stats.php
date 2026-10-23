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

    /**
     * Воронка регистрации (v5.1): ввели почту → подтвердили → завершили.
     * События шагов пишутся с 5.1 — раньше их не было, и до первых данных
     * воронка пустая, а не выдуманная.
     * @return list<array{key:string, n:int}>
     */
    public static function funnel(string $since): array
    {
        $out = [];
        foreach (['signup.started', 'signup.verified', 'register'] as $e) {
            $out[] = ['key' => $e, 'n' => self::count('audit_log', 'event = ?', [$e], $since)];
        }
        return $out;
    }

    /**
     * Входы по дням недели и часам, по местному времени: [1..7][0..23] => n
     * (1 — понедельник). Группирует база по часам UTC, раскладываем здесь.
     */
    public static function heatmap(string $since): array
    {
        $grid = array_fill(1, 7, array_fill(0, 24, 0));
        $rows = DB::all('SELECT SUBSTR(created_at, 1, 13) AS h, COUNT(*) AS n FROM {audit_log} WHERE ' . self::loginIn()
            . ' AND created_at >= ? GROUP BY SUBSTR(created_at, 1, 13)', array_merge(self::LOGIN_EVENTS, [$since]));
        $tz = self::tz();
        foreach ($rows as $r) {
            $t = (new DateTimeImmutable($r['h'] . ':00:00', new DateTimeZone('UTC')))->setTimezone($tz);
            $grid[(int)$t->format('N')][(int)$t->format('G')] += (int)$r['n'];
        }
        return $grid;
    }

    /**
     * Почему вход не удался: причина из meta login.fail.
     * Группирует база по самой строке meta — одинаковые причины пишутся
     * одинаковым JSON, — разбираем уже горстку строк.
     * @return array<string,int> причина => n, по убыванию
     */
    public static function failReasons(string $since): array
    {
        $out = [];
        foreach (DB::all('SELECT meta, COUNT(*) AS n FROM {audit_log} WHERE event = ? AND created_at >= ? GROUP BY meta ORDER BY n DESC LIMIT 50',
            ['login.fail', $since]) as $r) {
            $why = (string)(json_decode((string)$r['meta'], true)['reason'] ?? '');
            $why = $why === '' ? 'other' : $why;
            $out[$why] = ($out[$why] ?? 0) + (int)$r['n'];
        }
        arsort($out);
        return $out;
    }

    /**
     * Чем входят: браузер и система по user-agent успешных входов. Подпись —
     * device_label(), та же, что в сессиях и письмах о новом устройстве.
     * @return array{browsers: array<string,int>, systems: array<string,int>}
     */
    public static function devices(string $since): array
    {
        $b = $o = [];
        foreach (DB::all('SELECT user_agent, COUNT(*) AS n FROM {audit_log} WHERE ' . self::loginIn()
            . ' AND created_at >= ? GROUP BY user_agent ORDER BY n DESC LIMIT 500', array_merge(self::LOGIN_EVENTS, [$since])) as $r) {
            $parts = explode(' · ', device_label((string)$r['user_agent']));
            $b[$parts[0]] = ($b[$parts[0]] ?? 0) + (int)$r['n'];
            $os = $parts[1] ?? t('Другая');
            $o[$os] = ($o[$os] ?? 0) + (int)$r['n'];
        }
        arsort($b);
        arsort($o);
        return ['browsers' => $b, 'systems' => $o];
    }

    /** Сколько в каком состоянии: [status => n] за [с, …). */
    public static function byStatus(string $table, string $where, array $params, string $since): array
    {
        $out = [];
        foreach (DB::all('SELECT status, COUNT(*) AS n FROM {' . $table . '} WHERE ' . $where . ' AND created_at >= ? GROUP BY status',
            array_merge($params, [$since])) as $r) $out[(string)$r['status']] = (int)$r['n'];
        return $out;
    }

    /**
     * Портал за период (v5.2): входы и прошлый такой же период, ряд, активные
     * за день, неделю и месяц, токены, доставка событий и отказы.
     *
     * «Активные» — по last_login_at доступа: это последний вход человека в
     * портал, и за месяц он один на человека, — без отдельной таблицы визитов.
     */
    public static function portal(int $cid, int $days, ?int $now = null): array
    {
        [$since, $prev] = self::bounds($days, $now);
        $access = static fn(int $d): int => (int)DB::val('SELECT COUNT(*) FROM {user_client_access} WHERE client_id = ? AND last_login_at >= ?',
            [$cid, self::dayStart($d, $now)]);
        $audit = static fn(string $e): int => self::count('audit_log', 'event = ? AND client_id = ?', [$e, $cid], $since);
        return [
            'logins' => [self::count('session_clients', 'client_id = ?', [$cid], $since),
                         self::count('session_clients', 'client_id = ?', [$cid], $prev, $since)],
            'series' => self::series('session_clients', 'client_id = ?', [$cid], $days, $now),
            'active' => ['day' => $access(0), 'week' => $access(6), 'month' => $access(29)],
            'refresh' => $audit('token.refresh'),
            'reuse' => $audit('token.refresh_reuse'),
            'delivery' => self::byStatus('client_events', 'client_id = ?', [$cid], $since),
            'errors' => array_map(static fn($r) => ['error' => (string)$r['last_error'], 'n' => (int)$r['n']],
                DB::all('SELECT last_error, COUNT(*) AS n FROM {client_events} WHERE client_id = ? AND created_at >= ? AND last_error IS NOT NULL
                         GROUP BY last_error ORDER BY n DESC LIMIT 3', [$cid, $since])),
            'refusals' => ['access.requested' => $audit('access.requested'), 'access.denied' => $audit('access.denied'),
                           'consent.denied' => $audit('consent.denied')],
        ];
    }

    /** Границы корзин скорости ответа, мс. Последняя — «дольше». */
    public const SPEED = [50, 100, 200, 500, 1000, 2000];

    /**
     * Скорость ответа (v5.2): сколько запросов в какой корзине и в какую
     * корзину попадают медиана и 95-й процентиль.
     *
     * Корзины, а не точный процентиль: точный требует сортировки всех строк
     * периода (за 90 дней — сотни тысяч), корзины база считает одним проходом.
     * Ответ «95 % быстрее 200 мс» точен ровно настолько, насколько нужен.
     * Стук сканеров (гость, 404/405) не считается: это не работа приложения.
     * @return array{buckets: list<int>, total:int, p50:?int, p95:?int} p — индекс корзины
     */
    public static function speed(string $since): array
    {
        $case = 'CASE';
        foreach (self::SPEED as $i => $b) $case .= ' WHEN ms < ' . $b . ' THEN ' . $i;
        $case .= ' ELSE ' . count(self::SPEED) . ' END';
        $buckets = array_fill(0, count(self::SPEED) + 1, 0);
        foreach (DB::all('SELECT ' . $case . ' AS b, COUNT(*) AS n FROM {activity_log}
                          WHERE created_at >= ? AND NOT (user_id IS NULL AND status IN (404, 405)) GROUP BY ' . $case, [$since]) as $r) {
            $buckets[(int)$r['b']] += (int)$r['n'];
        }
        return ['buckets' => $buckets, 'total' => array_sum($buckets),
                'p50' => self::quantileBucket($buckets, 0.5), 'p95' => self::quantileBucket($buckets, 0.95)];
    }

    /** В какую корзину попадает доля $q запросов; null — запросов не было. */
    public static function quantileBucket(array $buckets, float $q): ?int
    {
        $total = array_sum($buckets);
        if ($total === 0) return null;
        $acc = 0;
        foreach ($buckets as $i => $n) {
            $acc += $n;
            if ($acc >= $q * $total) return $i;
        }
        return count($buckets) - 1;
    }

    /**
     * Самые медленные адреса: среднее время, от пяти запросов — единичный
     * долгий запрос говорит о случае, а не об адресе.
     * @return list<array{path:string, n:int, ms:int}>
     */
    public static function slowPaths(string $since, int $limit = 5): array
    {
        return array_map(static fn($r) => ['path' => (string)$r['path'], 'n' => (int)$r['n'], 'ms' => (int)round((float)$r['a'])],
            DB::all('SELECT path, COUNT(*) AS n, AVG(ms) AS a FROM {activity_log} WHERE created_at >= ?
                     GROUP BY path HAVING COUNT(*) >= 5 ORDER BY a DESC LIMIT ' . $limit, [$since]));
    }
}
