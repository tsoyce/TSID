<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\SessionModel;
use App\Models\User;

/**
 * Замок: сессия жива, но перед работой переспрашивает — коротко.
 *
 * Задача не та, что у входа. Вход отвечает «кто ты» незнакомцу; замок отвечает
 * «ты ли это» тому, кого мы уже знаем и чья сессия открыта. Между ними разница
 * в цене ошибки: телефон, оставленный на столе, попадает в руки жены, ребёнка
 * или кого угодно ещё — и там нужен не пароль, а полсекунды.
 *
 * Поэтому:
 *  · сначала ключ устройства (passkey) — это отпечаток или лицо средствами
 *    системы, и ничего нового запоминать не надо. Он у проекта уже есть;
 *  · PIN — запасной путь для устройств без биометрии. Именно запасной: PIN это
 *    второй секрет, который можно забыть, и делать его главным значит плодить
 *    пароли;
 *  · пароль — последний рубеж, если нет ни того ни другого.
 *
 * Выключено по умолчанию (§1.1). Включается сроком простоя: 0 — замка нет.
 *
 * Чего замок НЕ делает: он не выходит из аккаунта и не рвёт сессию. Заперто —
 * значит, показываем один экран вместо всех остальных; токены порталов при
 * этом продолжают жить, потому что человек никуда не выходил.
 */
final class QuickUnlock
{
    /** Сколько неверных PIN подряд до полного выхода. */
    public const PIN_ATTEMPTS = 5;

    /** Границы длины PIN. Четыре — привычка от телефонов, восемь — предел терпения. */
    public const PIN_MIN = 4;
    public const PIN_MAX = 8;

    /** Через сколько минут простоя запирать. 0 — не запирать. */
    public static function idleMinutes(): int
    {
        return max(0, min(1440, Setting::getInt('policy.lock_minutes', 0)));
    }

    public static function enabled(): bool
    {
        return self::idleMinutes() > 0;
    }

    /** Есть ли чем отпереть быстро: ключ устройства или PIN. */
    public static function ready(array $user): bool
    {
        return self::hasPin($user) || self::hasPasskey((int)$user['id']);
    }

    public static function hasPin(array $user): bool
    {
        return !empty($user['pin_hash']);
    }

    public static function hasPasskey(int $userId): bool
    {
        if (!DB::tableExists('passkeys')) return false;
        return (int)DB::val('SELECT COUNT(*) FROM {passkeys} WHERE user_id = ?', [$userId]) > 0;
    }

    /**
     * Пора ли запирать: сессия простояла дольше срока.
     *
     * Считаем ДО того, как отметка обновится текущим запросом, — иначе простой
     * всегда нулевой и замок не срабатывает никогда.
     */
    public static function shouldLock(array $session, array $user): bool
    {
        if (!self::enabled() || !self::ready($user)) return false;
        if (!empty($session['locked_at'])) return true;
        $seen = (string)($session['last_seen_at'] ?? '');
        if ($seen === '') return false;
        return (time() - strtotime($seen . ' UTC')) >= self::idleMinutes() * 60;
    }

    public static function locked(array $session): bool
    {
        return !empty($session['locked_at']);
    }

    public static function lock(int $sessionId): void
    {
        DB::update('sessions', ['locked_at' => now()], 'id = ?', [$sessionId]);
    }

    public static function unlock(int $sessionId): void
    {
        DB::update('sessions', ['locked_at' => null], 'id = ?', [$sessionId]);
    }

    /* ---- PIN ---- */

    /** Годится ли код. Возвращает текст ошибки или null. */
    public static function checkPinFormat(#[\SensitiveParameter] string $pin): ?string
    {
        $len = strlen($pin);
        if ($len < self::PIN_MIN || $len > self::PIN_MAX || !ctype_digit($pin)) {
            return t('PIN — от :min до :max цифр.', ['min' => self::PIN_MIN, 'max' => self::PIN_MAX]);
        }
        /* Четыре одинаковые цифры и «1234» — не защита, а её видимость.
           Запрет здесь, а не в подсказке: подсказку читают после. */
        if (preg_match('~^(\d)\1+$~', $pin)) return t('Все цифры одинаковые — так не годится.');
        $seq = '0123456789';
        if (str_contains($seq, $pin) || str_contains(strrev($seq), $pin)) {
            return t('Цифры подряд — так не годится.');
        }
        return null;
    }

    public static function setPin(int $userId, #[\SensitiveParameter] string $pin): void
    {
        User::update($userId, ['pin_hash' => password_hash($pin, PASSWORD_ARGON2ID)]);
        Audit::log('pin.set', ['actor' => $userId, 'on' => $userId]);
    }

    public static function clearPin(int $userId): void
    {
        User::update($userId, ['pin_hash' => null]);
        Audit::log('pin.cleared', ['actor' => $userId, 'on' => $userId]);
    }

    /**
     * Проверить PIN. Верный — отпираем; неверный — считаем попытки и на пятой
     * гасим сессию: дальше только полный вход.
     */
    public static function tryPin(array $user, array $session, #[\SensitiveParameter] string $pin): bool
    {
        $hash = (string)($user['pin_hash'] ?? '');
        if ($hash !== '' && password_verify($pin, $hash)) {
            self::unlock((int)$session['id']);
            RateLimiter::clear(self::bucket((int)$session['id']));
            Audit::log('pin.unlock', ['actor' => (int)$user['id'], 'on' => (int)$user['id']]);
            return true;
        }
        $left = RateLimiter::hit(self::bucket((int)$session['id']), self::PIN_ATTEMPTS, 900);
        if (!$left) {
            SessionModel::revoke((int)$session['id']);
            Audit::log('pin.failed_out', ['actor' => (int)$user['id'], 'on' => (int)$user['id']]);
        }
        return false;
    }

    private static function bucket(int $sessionId): string
    {
        return 'pin:' . $sessionId;
    }
}
