<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Блокировка адресов (v4.72).
 *
 * Живой случай 21.09: один и тот же человек настойчиво входил с одного адреса.
 * Заблокировать аккаунт можно было и раньше — он заводит новый; закрыть дверь
 * самому адресу было нечем.
 *
 * Правило — адрес или подсеть: 198.51.100.66, 198.51.100.0/24, 2a01:db8::/48.
 * Хранится диапазоном упакованных адресов (inet_pton), поэтому проверка — два
 * сравнения строк одной длины. Заблокированный адрес получает 403 на любой
 * странице: и вход, и регистрацию, и протокольные точки.
 *
 * Защиты от себя две. Слишком широкие подсети не принимаются: /7 закрыла бы
 * пол-интернета одной опечаткой. И адрес, с которого сейчас работает сам
 * администратор, заблокировать нельзя — иначе выйти из этого положения можно
 * только через базу.
 */
final class IpBlock
{
    /** Шире не блокируем: IPv4 — от /8, IPv6 — от /32. */
    private const MIN_PREFIX = [4 => 8, 16 => 32];

    /** @var list<array{id:int, from:string, to:string}>|null активные правила на этот запрос */
    private static ?array $active = null;

    /**
     * Разобрать правило: адрес или подсеть в диапазон.
     *
     * @return array{rule:string, from:string, to:string}|string диапазон или текст ошибки
     */
    public static function parse(string $rule): array|string
    {
        $rule = strtolower(trim($rule));
        $prefix = null;
        if (str_contains($rule, '/')) {
            [$rule, $p] = explode('/', $rule, 2);
            if (!ctype_digit($p)) return t('После «/» — число: длина префикса подсети.');
            $prefix = (int)$p;
        }
        $bin = @inet_pton($rule);
        if ($bin === false) return t('Это не адрес: нужен IPv4 (1.2.3.4) или IPv6, можно с подсетью (/24).');
        $len = strlen($bin);
        $bits = $len * 8;
        $prefix ??= $bits;
        if ($prefix > $bits) return t('Префикс длиннее адреса.');
        if ($prefix < self::MIN_PREFIX[$len]) {
            return t('Слишком широкая подсеть: не шире /:n — иначе одна опечатка закроет вход целым странам.',
                ['n' => self::MIN_PREFIX[$len]]);
        }
        $from = $to = '';
        for ($i = 0; $i < $len; $i++) {
            $keep = max(0, min(8, $prefix - $i * 8));
            $mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
            $byte = ord($bin[$i]);
            $from .= chr($byte & $mask);
            $to .= chr(($byte & $mask) | (~$mask & 0xFF));
        }
        $canon = (string)inet_ntop($from) . ($prefix < $bits ? '/' . $prefix : '');
        return ['rule' => $canon, 'from' => $from, 'to' => $to];
    }

    /** Попадает ли упакованный адрес в диапазон. */
    public static function inRange(string $ip, string $from, string $to): bool
    {
        return strlen($ip) === strlen($from) && strcmp($ip, $from) >= 0 && strcmp($ip, $to) <= 0;
    }

    private static function ready(): bool
    {
        try {
            return DB::columnExists('ip_blocks', 'ip_from');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<array{id:int, from:string, to:string}> */
    private static function active(): array
    {
        if (self::$active !== null) return self::$active;
        self::$active = [];
        if (!self::ready()) return self::$active;
        foreach (DB::all('SELECT id, ip_from, ip_to FROM {ip_blocks} WHERE expires_at IS NULL OR expires_at > ?', [now()]) as $r) {
            self::$active[] = ['id' => (int)$r['id'], 'from' => (string)$r['ip_from'], 'to' => (string)$r['ip_to']];
        }
        return self::$active;
    }

    /** Правило, под которое попадает адрес, или null. */
    public static function match(?string $ipBin): ?int
    {
        if ($ipBin === null || $ipBin === '') return null;
        foreach (self::active() as $b) {
            if (self::inRange($ipBin, $b['from'], $b['to'])) return $b['id'];
        }
        return null;
    }

    /**
     * Закрыть запрос с заблокированного адреса — 403 без подробностей.
     *
     * Причину и правило не называем: человеку за этим адресом незачем знать,
     * по какой подсети его отрезали и что именно о нём записано.
     */
    public static function enforce(): void
    {
        if (PHP_SAPI === 'cli') return;
        $id = self::match(ip_bin());
        if ($id === null) return;
        DB::run('UPDATE {ip_blocks} SET hits = hits + 1, last_hit_at = ? WHERE id = ?', [now(), $id]);
        http_response_code(403);
        ErrorPage::render(403, [
            'title' => t('Доступ закрыт'),
            'message' => t('С этого адреса доступ к сервису закрыт.'),
        ]);
    }

    /**
     * Добавить правило. Возвращает текст ошибки или null.
     *
     * @param int $days 0 — бессрочно
     */
    public static function add(string $rule, string $reason, int $days, ?int $byUserId): ?string
    {
        if (!self::ready()) return t('Схема базы отстаёт от кода.');
        $r = self::parse($rule);
        if (is_string($r)) return $r;
        $mine = ip_bin();
        if ($mine !== null && self::inRange($mine, $r['from'], $r['to'])) {
            return t('Под это правило попадает ваш собственный адрес — вы заперли бы себя.');
        }
        if (DB::row('SELECT id FROM {ip_blocks} WHERE rule = ?', [$r['rule']]) !== null) {
            return t('Такое правило уже есть.');
        }
        DB::insert('ip_blocks', [
            'rule' => $r['rule'], 'ip_from' => $r['from'], 'ip_to' => $r['to'],
            'reason' => mb_substr(trim($reason), 0, 255) ?: null,
            'created_by' => $byUserId, 'created_at' => now(),
            'expires_at' => $days > 0 ? now_plus($days * 86400) : null,
        ]);
        self::$active = null;
        Audit::log('admin.ip.block', ['meta' => ['rule' => $r['rule'], 'days' => $days ?: 'навсегда']]);
        return null;
    }

    public static function remove(int $id): void
    {
        $row = DB::row('SELECT rule FROM {ip_blocks} WHERE id = ?', [$id]);
        if ($row === null) return;
        DB::run('DELETE FROM {ip_blocks} WHERE id = ?', [$id]);
        self::$active = null;
        Audit::log('admin.ip.unblock', ['meta' => ['rule' => (string)$row['rule']]]);
    }

    /** Все правила для экрана — действующие сверху. */
    public static function all(): array
    {
        if (!self::ready()) return [];
        return DB::all('SELECT b.*, u.email AS by_email FROM {ip_blocks} b LEFT JOIN {users} u ON u.id = b.created_by
            ORDER BY (b.expires_at IS NOT NULL AND b.expires_at <= ?) ASC, b.id DESC', [now()]);
    }

    /** Действующее правило, закрывающее этот адрес, — для кнопок «заблокировать/снять». */
    public static function ruleFor(string $ipBin): ?array
    {
        $id = self::match($ipBin);
        return $id === null ? null : DB::row('SELECT * FROM {ip_blocks} WHERE id = ?', [$id]);
    }
}
