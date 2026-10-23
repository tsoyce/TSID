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
     * У каждого — причины словами и `sure`: сильный признак (устаревший
     * браузер, закрытый адрес, толпа с одной подсети) — такие отмечены
     * галочкой сразу; без сильного признака — только показаны.
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
        $out = [];
        foreach ($rows as $r) {
            $why = [];
            $sure = false;
            $old = self::outdated((string)($r['reg_ua'] ?? ''));
            if ($old !== null) { $why[] = t('браузер: :b', ['b' => $old]); $sure = true; }
            if ($r['reg_ip'] !== null && IpBlock::match((string)$r['reg_ip']) !== null) { $why[] = t('адрес закрыт'); $sure = true; }
            if ($r['net'] !== null && ($crowd[$r['net']] ?? 0) >= self::CROWD) {
                $why[] = t('с подсети ещё :n', ['n' => $crowd[$r['net']] - 1]);
                $sure = true;
            }
            /* «Почта не подтверждена» верно для всех в списке — плашкой на каждой
               строке это ничего не различает. Без сильных признаков так и говорим. */
            $out[] = $r + ['why' => $why, 'sure' => $sure];
        }
        /* Уверенные — сверху: их удаляют, остальных разглядывают. */
        usort($out, static fn(array $a, array $b): int => [$b['sure'], (int)$b['id']] <=> [$a['sure'], (int)$a['id']]);
        return $out;
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
