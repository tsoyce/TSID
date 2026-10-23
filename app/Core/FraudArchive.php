<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Архив образцов ботов (v5.5).
 *
 * Живой случай 23.10: шестьдесят четыре бота удалили пачкой — и вместе с
 * аккаунтами ушло всё, на чём можно учиться. Аудит пережил их, но в нём не
 * записано, что это были боты; журнал активности уходит вместе с аккаунтом.
 * Отличить удалённого бота от удалённого человека стало нельзя.
 *
 * Теперь удаление «как бота» сначала откладывает обезличенный образец:
 * подсеть (не адрес), строку браузера, домен почты, сработавшие признаки,
 * оценку и поведение — сколько запросов, куда, за какое время, как быстро
 * заполнена форма. Ни ящика, ни имени, ни адреса, ни номера аккаунта: бот
 * подставляет чужую настоящую почту, и образец не должен вести к её хозяину.
 *
 * Архив и есть «обучение»: BotSignal берёт из него подсети и браузеры, и
 * следующий бот с той же подсети отмечен сразу. Плюс — сводка для раздела
 * «Антифрод» и выгрузка образцов в JSON.
 */
final class FraudArchive
{
    /** Срок хранения по умолчанию, дней; 0 — вечно. */
    public const KEEP_DAYS = 365;

    /** Сколько адресов из журнала активности держать в образце: поведение, а не протокол. */
    private const PATHS = 8;

    /** @var array{nets: array<string,int>, agents: array<string,int>}|null */
    private static ?array $known = null;

    public static function ready(): bool
    {
        return DB::tableExists('fraud_samples');
    }

    /**
     * Отложить образцы перед удалением. Принимает строки BotSignal::suspects():
     * там уже есть адрес и браузер регистрации, признаки и оценка.
     *
     * Вызывать ДО удаления: журнал активности аккаунта уходит вместе с ним.
     *
     * @param list<array> $suspects
     */
    public static function keep(array $suspects, string $label = 'bot'): int
    {
        if (!self::ready()) return 0;
        $n = 0;
        foreach ($suspects as $s) {
            DB::insert('fraud_samples', self::sample($s, $label));
            $n++;
        }
        self::$known = null;
        return $n;
    }

    /**
     * Образец из строки кандидата. Отдельно от записи — чтобы проверка могла
     * убедиться, что ни почта, ни имя, ни адрес в образец не попадают.
     */
    public static function sample(array $s, string $label = 'bot'): array
    {
        $email = (string)($s['email'] ?? '');
        $at = strrpos($email, '@');
        $ua = (string)($s['reg_ua'] ?? '');
        $ip = $s['reg_ip'] !== null && ($s['reg_ip'] ?? '') !== '' ? (string)$s['reg_ip'] : null;
        return [
            'created_at' => now(),
            'registered_at' => $s['created_at'] ?? null,
            'label' => $label,
            'net' => $s['net'] ?? BotSignal::subnet($ip),
            'user_agent' => $ua !== '' ? mb_substr($ua, 0, 255) : null,
            'outdated' => BotSignal::outdated($ua),
            'email_domain' => $at !== false ? mb_strtolower(mb_substr($email, $at + 1, 128)) : null,
            'score' => (int)($s['score'] ?? 0),
            'signals' => json_encode(array_values($s['signals'] ?? []), JSON_UNESCAPED_UNICODE),
            'activity' => json_encode(self::behaviour((int)($s['id'] ?? 0), $ip, $s['created_at'] ?? null),
                JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Поведение аккаунта по журналу активности: что делал после регистрации
     * и как шёл к ней с того же адреса. Числа в адресах заменены на {n} —
     * номера чужих объектов в образце ни к чему.
     *
     * @return array{requests:int, span_s:int, paths:array<string,int>, statuses:array<string,int>, before:int, form_s:?int}
     */
    public static function behaviour(int $userId, ?string $ip, ?string $registeredAt): array
    {
        $out = ['requests' => 0, 'span_s' => 0, 'paths' => [], 'statuses' => [], 'before' => 0, 'form_s' => null];
        if (!DB::columnExists('activity_log', 'path')) return $out;
        $rows = $userId > 0
            ? DB::all('SELECT created_at, method, path, status FROM {activity_log} WHERE user_id = ? ORDER BY id', [$userId])
            : [];
        $first = $last = null;
        foreach ($rows as $r) {
            $out['requests']++;
            $key = $r['method'] . ' ' . preg_replace('~/\d+~', '/{n}', (string)$r['path']);
            $out['paths'][$key] = ($out['paths'][$key] ?? 0) + 1;
            $st = (string)$r['status'];
            $out['statuses'][$st] = ($out['statuses'][$st] ?? 0) + 1;
            $t = strtotime((string)$r['created_at'] . ' UTC');
            $first ??= $t;
            $last = $t;
        }
        if ($first !== null) $out['span_s'] = (int)($last - $first);
        arsort($out['paths']);
        $out['paths'] = array_slice($out['paths'], 0, self::PATHS, true);

        /* До регистрации аккаунта ещё нет — запросы видны только по адресу.
           Час до неё: сколько страниц открыл и за сколько секунд от открытия
           формы до отправки. Человек тратит на форму десятки секунд. */
        if ($ip !== null && $registeredAt !== null) {
            $reg = strtotime($registeredAt . ' UTC');
            $pre = DB::all('SELECT created_at, method, path FROM {activity_log}
                            WHERE ip = ? AND user_id IS NULL AND created_at >= ? AND created_at <= ? ORDER BY id',
                [$ip, gmdate('Y-m-d H:i:s', $reg - 3600), $registeredAt]);
            $out['before'] = count($pre);
            $opened = null;
            foreach ($pre as $r) {
                if ($r['method'] === 'GET' && str_starts_with((string)$r['path'], '/register')) {
                    $opened = strtotime((string)$r['created_at'] . ' UTC');
                }
            }
            if ($opened !== null) $out['form_s'] = max(0, $reg - $opened);
        }
        return $out;
    }

    /**
     * Подсети и строки браузера из архива — для признаков known_net и known_ua.
     *
     * @return array{nets: array<string,int>, agents: array<string,int>}
     */
    public static function known(): array
    {
        if (self::$known !== null) return self::$known;
        $known = ['nets' => [], 'agents' => []];
        if (self::ready()) {
            foreach (DB::all('SELECT net, user_agent FROM {fraud_samples} WHERE label = ?', ['bot']) as $r) {
                if ($r['net'] !== null) $known['nets'][(string)$r['net']] = ($known['nets'][(string)$r['net']] ?? 0) + 1;
                if ($r['user_agent'] !== null) {
                    $known['agents'][(string)$r['user_agent']] = ($known['agents'][(string)$r['user_agent']] ?? 0) + 1;
                }
            }
        }
        return self::$known = $known;
    }

    public static function count(): int
    {
        return self::ready() ? (int)DB::val('SELECT COUNT(*) FROM {fraud_samples}') : 0;
    }

    /** @return list<array> последние образцы, signals и activity — уже массивами. */
    public static function recent(int $limit = 50): array
    {
        if (!self::ready()) return [];
        $rows = DB::all('SELECT * FROM {fraud_samples} ORDER BY id DESC LIMIT ' . max(1, $limit));
        foreach ($rows as $i => $r) {
            $rows[$i]['signals'] = json_decode((string)($r['signals'] ?? '[]'), true) ?: [];
            $rows[$i]['activity'] = json_decode((string)($r['activity'] ?? '{}'), true) ?: [];
        }
        return $rows;
    }

    /**
     * Сводка архива для раздела: чаще всего встречающиеся подсети, браузеры
     * (словами — «Chrome 39», а не вся строка), домены почты и признаки.
     *
     * @return array{nets:array<string,int>, browsers:array<string,int>, domains:array<string,int>, signals:array<string,int>, form_s:?int}
     */
    public static function summary(int $top = 8): array
    {
        $out = ['nets' => [], 'browsers' => [], 'domains' => [], 'signals' => [], 'form_s' => null];
        if (!self::ready()) return $out;
        $forms = [];
        foreach (DB::all('SELECT net, user_agent, outdated, email_domain, signals, activity FROM {fraud_samples}') as $r) {
            if ($r['net'] !== null) $out['nets'][(string)$r['net']] = ($out['nets'][(string)$r['net']] ?? 0) + 1;
            $b = $r['outdated'] ?? null;
            $b = $b !== null ? (string)$b : device_text(device_label((string)($r['user_agent'] ?? '')));
            $out['browsers'][$b] = ($out['browsers'][$b] ?? 0) + 1;
            if ($r['email_domain'] !== null) {
                $out['domains'][(string)$r['email_domain']] = ($out['domains'][(string)$r['email_domain']] ?? 0) + 1;
            }
            foreach ((array)(json_decode((string)($r['signals'] ?? '[]'), true) ?: []) as $c) {
                $out['signals'][(string)$c] = ($out['signals'][(string)$c] ?? 0) + 1;
            }
            $f = (json_decode((string)($r['activity'] ?? '{}'), true) ?: [])['form_s'] ?? null;
            if ($f !== null) $forms[] = (int)$f;
        }
        foreach (['nets', 'browsers', 'domains', 'signals'] as $k) {
            arsort($out[$k]);
            $out[$k] = array_slice($out[$k], 0, $top, true);
        }
        if ($forms !== []) {
            sort($forms);
            $out['form_s'] = $forms[intdiv(count($forms), 2)];
        }
        return $out;
    }

    /** Образцы по местным суткам за $days дней — ряд для графика, старые слева. */
    public static function perDay(int $days = 30): array
    {
        return self::ready() ? array_values(Stats::perDay('fraud_samples', '1 = 1', [], $days)) : array_fill(0, $days, 0);
    }

    /** Все образцы — для выгрузки: обезличены уже при записи. */
    public static function export(): array
    {
        if (!self::ready()) return [];
        $rows = DB::all('SELECT created_at, registered_at, label, net, user_agent, outdated, email_domain, score, signals, activity
                         FROM {fraud_samples} ORDER BY id');
        foreach ($rows as $i => $r) {
            $rows[$i]['score'] = (int)$r['score'];
            $rows[$i]['signals'] = json_decode((string)($r['signals'] ?? '[]'), true) ?: [];
            $rows[$i]['activity'] = json_decode((string)($r['activity'] ?? '{}'), true) ?: [];
        }
        return $rows;
    }

    /** Убрать образцы старше срока; 0 — хранить вечно. */
    public static function purge(int $days): int
    {
        if ($days <= 0 || !self::ready()) return 0;
        return DB::run('DELETE FROM {fraud_samples} WHERE created_at < ?', [now_plus(-$days * 86400)])->rowCount();
    }
}
