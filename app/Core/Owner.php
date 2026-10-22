<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * Владелец инсталляции (v4.2).
 *
 * Зачем отдельно от `superadmin`: эта роль выдаётся из админки в два клика,
 * чтобы человек завёл портал. Смена домена, смена базы и сброс проекта —
 * операции, которые нельзя откатить из интерфейса, и раздавать их вместе с
 * ролью неправильно. Владелец один на инсталляцию.
 *
 * Владелец всегда есть: если запись потерялась или указывает на удалённый
 * аккаунт, владельцем становится старейший суперадмин, и это пишется в аудит.
 * Пустой владелец означал бы, что системный раздел недоступен вообще никому,
 * а конфиг всё равно правится по SSH — то есть защита превратилась бы в
 * неудобство без выигрыша.
 */
final class Owner
{
    private const KEY = 'system.owner_user_id';

    /** id владельца или null, если суперадминов нет вообще (момент установки). */
    public static function id(): ?int
    {
        $stored = Setting::get(self::KEY);
        if ($stored !== null && $stored !== '') {
            $user = User::find((int)$stored);
            if ($user && $user['status'] !== 'deleted' && self::isSuperadmin((int)$user['id'])) {
                return (int)$user['id'];
            }
            /* Запись есть, но аккаунта нет или он больше не суперадмин.
               Молча вернуть null нельзя: раздел исчезнет без объяснений. */
            Log::warning('owner.stale', ['stored' => (int)$stored]);
        }
        return self::inherit();
    }

    public static function user(): ?array
    {
        $id = self::id();
        return $id !== null ? User::find($id) : null;
    }

    /** Текущий вошедший — владелец? Имперсонизация не считается. */
    public static function isCurrent(): bool
    {
        $real = Auth::realUser();
        if (!$real || Auth::impersonating()) return false;
        $id = self::id();
        return $id !== null && (int)$real['id'] === $id;
    }

    /**
     * Назначить владельца. Вызывается при передаче владения, из CLI и при
     * наследовании. Аккаунт обязан быть живым суперадмином — иначе владельцем
     * стал бы тот, кто не может открыть админку.
     */
    public static function set(int $userId, ?int $actor, string $why): bool
    {
        $user = User::find($userId);
        if (!$user || $user['status'] === 'deleted' || !self::isSuperadmin($userId)) return false;
        $was = Setting::get(self::KEY);
        Setting::set(self::KEY, (string)$userId);
        Audit::log('system.owner.set', [
            'actor' => $actor, 'on' => $userId,
            'meta' => ['was' => $was !== null ? (int)$was : null, 'why' => $why],
        ]);
        return true;
    }

    /** Старейший живой суперадмин — владелец по умолчанию. */
    private static function inherit(): ?int
    {
        $row = DB::row(
            'SELECT u.id FROM {users} u
               JOIN {user_roles} ur ON ur.user_id = u.id
               JOIN {roles} r ON r.id = ur.role_id
              WHERE r.slug = ? AND r.client_id IS NULL AND u.status <> ?
              ORDER BY u.id LIMIT 1',
            ['superadmin', 'deleted']
        );
        if (!$row) return null;
        self::set((int)$row['id'], null, 'inherited');
        return (int)$row['id'];
    }

    private static function isSuperadmin(int $userId): bool
    {
        return in_array('superadmin', User::globalRoleSlugs($userId), true);
    }
}
