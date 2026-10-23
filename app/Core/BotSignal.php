<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * Признаки бота на регистрации (v4.95).
 *
 * Замер на боевой 23.10: с 17.10 — 64 регистрации ботов, по одной в час, с
 * девяти хостинговых подсетей /24, каждый раз с нового адреса. Блокировка по
 * одному адресу не помогала: адрес встречался раз-два. Почта — настоящие чужие
 * ящики, и письмо с кодом уходило незнакомым людям. Ни один бот не подтвердил
 * почту и не вошёл.
 *
 * Признак, который разделил всех: браузер. У всех 64 — Internet Explorer,
 * Chrome 31–45, Android 4, iPad на iOS 3–8, Windows XP. У всех 19 живых
 * людей за то же время — свежий Chrome или iOS. Такой бот перебирает строки
 * браузера из старого списка; человека на Chrome 39 в живой природе нет —
 * современные сайты у него просто не открываются.
 *
 * Здесь собраны три вещи, и больше нигде: что считать устаревшим браузером,
 * кого из зарегистрированных показать как «похоже на бота» и откуда они шли.
 */
final class BotSignal
{
    /**
     * Ниже этих версий браузера у людей нет: Chrome и Firefox 100 — весна 2022,
     * Safari 13 и iOS 13 — 2019, Android 5 — 2014. Порог ниже реальной
     * границы с запасом: лучше пропустить бота, чем отказать человеку.
     */
    private const FLOOR = ['chrome' => 100, 'firefox' => 100, 'safari' => 13, 'ios' => 13, 'android' => 5];

    /** Сколько регистраций с одной подсети делают её подозрительной. */
    public const CROWD = 3;

    /**
     * Вес признака в оценке риска (v4.98). Галочка «удалить» ставится от
     * SURE: каждый сильный признак весит не меньше порога и один решает дело,
     * слабые (отказы и подбор с той же подсети, браузер из архива) — только
     * вместе. Одна шкала вместо «сильный/слабый» — чтобы признаки из архива
     * встали в тот же ряд, а не рядом вторым правилом.
     */
    public const WEIGHT = [
        'outdated' => 60, 'blocked' => 60, 'known_net' => 50, 'crowd' => 40,
        'refused_net' => 30, 'fails_net' => 20, 'known_ua' => 20,
    ];

    /** С какой оценки аккаунт отмечен к удалению сразу. */
    public const SURE = 40;

    /** Сколько неудачных входов с подсети за месяц — признак подбора. */
    public const FAILS = 5;

    /** Признак словами — для сводки архива, где кода мало. */
    public static function signalLabel(string $code): string
    {
        return match ($code) {
            'outdated' => t('устаревший браузер'),
            'blocked' => t('адрес закрыт'),
            'known_net' => t('подсеть уже была у ботов'),
            'crowd' => t('толпа с одной подсети'),
            'refused_net' => t('с подсети отказано ботам'),
            'fails_net' => t('подбор паролей с подсети'),
            'known_ua' => t('браузер как у бота из архива'),
            default => $code,
        };
    }

    /** Оценка риска 0–100 по кодам признаков. */
    public static function score(array $codes): int
    {
        $sum = 0;
        foreach (array_unique($codes) as $c) $sum += self::WEIGHT[$c] ?? 0;
        return min(100, $sum);
    }

    /**
     * Устаревший браузер — словами («Chrome 39», «Internet Explorer»), или null.
     *
     * Пустая строка признаком не считается: в замере её не было ни у одного
     * бота, а без строки браузера ходят свои — проверки, мониторинг, curl.
     */
    public static function outdated(string $ua): ?string
    {
        $ua = trim($ua);
        if ($ua === '') return null;
        if (preg_match('~MSIE |Trident/~', $ua)) return 'Internet Explorer';
        if (preg_match('~(?:Chrome|CriOS)/(\d+)~', $ua, $m) && (int)$m[1] < self::FLOOR['chrome']) return 'Chrome ' . $m[1];
        if (preg_match('~(?:Firefox|FxiOS)/(\d+)~', $ua, $m) && (int)$m[1] < self::FLOOR['firefox']) return 'Firefox ' . $m[1];
        if (preg_match('~Android (\d+)~', $ua, $m) && (int)$m[1] < self::FLOOR['android']) return 'Android ' . $m[1];
        if (preg_match('~(?:iPhone|iPad|iPod).*? OS (\d+)_~', $ua, $m) && (int)$m[1] < self::FLOOR['ios']) return 'iOS ' . $m[1];
        if (!preg_match('~Chrome|CriOS|Firefox|FxiOS|Android~', $ua)
            && preg_match('~Version/(\d+)[\d.]* (?:Mobile/\S+ )?Safari/~', $ua, $m) && (int)$m[1] < self::FLOOR['safari']) {
            return 'Safari ' . $m[1];
        }
        if (preg_match('~Windows NT (\d+)\.(\d+)~', $ua, $m) && ((int)$m[1] < 6 || ((int)$m[1] === 6 && (int)$m[2] === 0))) {
            return (int)$m[1] < 6 ? 'Windows XP' : 'Windows Vista';
        }
        return null;
    }

    /** Отказывать ли устаревшим браузерам на регистрации — по умолчанию да. */
    public static function refuseOutdated(): bool
    {
        return Setting::get('policy.refuse_outdated', '1') === '1';
    }

    /**
     * Подсеть адреса для группировки: /24 у IPv4, /48 у IPv6 — столько
     * обычно выдаёт хостинг одному клиенту. Строкой правила блокировки.
     */
    public static function subnet(?string $ipBin): ?string
    {
        if ($ipBin === null || $ipBin === '') return null;
        $len = strlen($ipBin);
        if ($len !== 4 && $len !== 16) return null;
        $prefix = $len === 4 ? 24 : 48;
        $r = IpBlock::parse((string)inet_ntop($ipBin) . '/' . $prefix);
        return is_array($r) ? $r['rule'] : null;
    }

    /**
     * Похожие на ботов: завели аккаунт сами, почту не подтвердили и ни разу не
     * пришли ни в один портал.
     *
     * Только регистрация формой — по событию `register` в аудите: людей,
     * перенесённых с портала или заведённых администратором, тоже никто не
     * подтверждал, но ботами они от этого не становятся. Владелец и
     * суперадмины не попадают сюда никогда.
     *
     * У каждого — причины словами, коды признаков `signals`, оценка `score`
     * (0–100, см. WEIGHT) и `sure`: от SURE — отмечены галочкой сразу, ниже —
     * только показаны.
     *
     * @return list<array>
     */
    public static function suspects(int $limit = 500): array
    {
        $rows = DB::all(
            "SELECT u.id, u.public_id, u.email, u.first_name, u.last_name, u.created_at,
                    a.ip AS reg_ip, a.user_agent AS reg_ua
             FROM {users} u JOIN {audit_log} a ON a.on_user_id = u.id AND a.event = 'register'
             WHERE u.status IN ('pending', 'active') AND u.email_verified_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM {user_client_access} x WHERE x.user_id = u.id)
             ORDER BY u.id DESC LIMIT " . max(1, $limit)
        );
        $ownerId = Owner::id();
        $crowd = [];
        foreach ($rows as $i => $r) {
            if ((int)$r['id'] === $ownerId || in_array('superadmin', User::globalRoleSlugs((int)$r['id']), true)) {
                unset($rows[$i]);
                continue;
            }
            $net = self::subnet($r['reg_ip'] !== null ? (string)$r['reg_ip'] : null);
            $rows[$i]['net'] = $net;
            if ($net !== null) $crowd[$net] = ($crowd[$net] ?? 0) + 1;
        }
        $noise = self::noiseByNet();
        $known = FraudArchive::known();
        $out = [];
        foreach ($rows as $r) {
            $why = [];
            $codes = [];
            $net = $r['net'];
            $old = self::outdated((string)($r['reg_ua'] ?? ''));
            if ($old !== null) { $why[] = t('браузер: :b', ['b' => $old]); $codes[] = 'outdated'; }
            if ($r['reg_ip'] !== null && IpBlock::match((string)$r['reg_ip']) !== null) { $why[] = t('адрес закрыт'); $codes[] = 'blocked'; }
            if ($net !== null && ($crowd[$net] ?? 0) >= self::CROWD) {
                $why[] = t('с подсети ещё :n', ['n' => $crowd[$net] - 1]);
                $codes[] = 'crowd';
            }
            /* Из архива (v4.98): подсеть и браузер удалённых раньше ботов. Так
               удаление пачкой учит следующий разбор, а не стирает его. */
            if ($net !== null && isset($known['nets'][$net])) { $why[] = t('подсеть уже была у ботов'); $codes[] = 'known_net'; }
            if ((string)($r['reg_ua'] ?? '') !== '' && isset($known['agents'][(string)$r['reg_ua']])) {
                $why[] = t('браузер как у бота из архива');
                $codes[] = 'known_ua';
            }
            if ($net !== null && ($noise[$net]['refused'] ?? 0) > 0) {
                $why[] = t('с подсети отказано ботам: :n', ['n' => $noise[$net]['refused']]);
                $codes[] = 'refused_net';
            }
            if ($net !== null && ($noise[$net]['fails'] ?? 0) >= self::FAILS) {
                $why[] = t('с подсети неудачных входов: :n', ['n' => $noise[$net]['fails']]);
                $codes[] = 'fails_net';
            }
            $score = self::score($codes);
            /* «Почта не подтверждена» верно для всех в списке — плашкой на каждой
               строке это ничего не различает. Без сильных признаков так и говорим. */
            $out[] = $r + ['why' => $why, 'signals' => $codes, 'score' => $score, 'sure' => $score >= self::SURE];
        }
        /* Уверенные — сверху: их удаляют, остальных разглядывают. */
        usort($out, static fn(array $a, array $b): int => [$b['score'], (int)$b['id']] <=> [$a['score'], (int)$a['id']]);
        return $out;
    }

    /**
     * Отказы ботам и неудачные входы по подсетям за месяц — для признаков
     * refused_net и fails_net. Свою подсеть администратора не считаем: его
     * опечатки в пароле — не подбор.
     *
     * @return array<string, array{refused:int, fails:int}>
     */
    private static function noiseByNet(int $days = 30): array
    {
        $mine = self::subnet(ip_bin());
        $by = [];
        foreach (DB::all("SELECT event, ip FROM {audit_log} WHERE event IN ('register.refused', 'login.fail')
                          AND created_at >= ? AND ip IS NOT NULL", [now_plus(-$days * 86400)]) as $r) {
            $net = self::subnet((string)$r['ip']);
            if ($net === null || $net === $mine) continue;
            $by[$net] ??= ['refused' => 0, 'fails' => 0];
            $by[$net][$r['event'] === 'login.fail' ? 'fails' : 'refused']++;
        }
        return $by;
    }

    /**
     * Сколько похожих на ботов — для строки над списком людей. Один запрос без
     * разбора причин: владелец и суперадмины почту подтвердили давно.
     */
    public static function suspectCount(): int
    {
        return (int)DB::val(
            "SELECT COUNT(*) FROM {users} u JOIN {audit_log} a ON a.on_user_id = u.id AND a.event = 'register'
             WHERE u.status IN ('pending', 'active') AND u.email_verified_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM {user_client_access} x WHERE x.user_id = u.id)"
        );
    }

    /** Сколько регистраций отклонено за сутки — видно, что фильтр работает. */
    public static function refusedToday(): int
    {
        return (int)DB::val("SELECT COUNT(*) FROM {audit_log} WHERE event = 'register.refused' AND created_at >= ?",
            [now_plus(-86400)]);
    }

    /**
     * Откуда шли регистрации без подтверждения, отказы ботам и неудачные
     * входы — по подсетям, за $days дней. Самые шумные сверху.
     *
     * @return list<array{net:string, regs:int, unconfirmed:int, refused:int, fails:int, last:string, blocked:bool, mine:bool}>
     */
    public static function subnets(int $days = 30): array
    {
        $rows = DB::all(
            "SELECT a.event, a.ip, a.created_at, u.email_verified_at
             FROM {audit_log} a LEFT JOIN {users} u ON u.id = a.on_user_id AND a.event = 'register'
             WHERE a.event IN ('register', 'register.refused', 'login.fail') AND a.created_at >= ? AND a.ip IS NOT NULL",
            [now_plus(-$days * 86400)]
        );
        $mine = self::subnet(ip_bin());
        $by = [];
        foreach ($rows as $r) {
            $net = self::subnet((string)$r['ip']);
            if ($net === null) continue;
            $s = $by[$net] ??= ['net' => $net, 'regs' => 0, 'unconfirmed' => 0, 'refused' => 0, 'fails' => 0, 'last' => '',
                'blocked' => false, 'mine' => $net === $mine];
            if ($r['event'] === 'register') {
                $s['regs']++;
                if ($r['email_verified_at'] === null) $s['unconfirmed']++;
            } elseif ($r['event'] === 'register.refused') {
                $s['refused']++;
            } else {
                $s['fails']++;
            }
            if ((string)$r['created_at'] > $s['last']) $s['last'] = (string)$r['created_at'];
            $by[$net] = $s;
        }
        $out = [];
        foreach ($by as $s) {
            /* Подсеть, откуда только регистрировались и подтверждали, — люди. */
            if ($s['unconfirmed'] + $s['refused'] + $s['fails'] === 0) continue;
            /* Закрыта целиком — закрыты оба её края; один закрытый адрес внутри
               подсеть закрытой не делает. */
            $r = IpBlock::parse($s['net']);
            $s['blocked'] = is_array($r) && IpBlock::match($r['from']) !== null && IpBlock::match($r['to']) !== null;
            $out[] = $s;
        }
        usort($out, static fn(array $a, array $b): int =>
            [$b['unconfirmed'] + $b['refused'], $b['fails'], $b['last']] <=> [$a['unconfirmed'] + $a['refused'], $a['fails'], $a['last']]);
        return $out;
    }
}
