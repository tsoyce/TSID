<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/**
 * Дополнительные email-адреса (v3.6).
 *
 * Зачем: каждый соцпровайдер приносит свой адрес — Яндекс яндексовый,
 * Google гуглопочту. Если их выбрасывать, следующий вход через другого
 * провайдера не найдёт аккаунт по почте и заведёт дубль. Здесь эти адреса
 * копятся, и поиск аккаунта смотрит и сюда тоже.
 *
 * Основной адрес живёт в users.email и отсюда не видён.
 */
final class UserEmail
{
    public static function ready(): bool
    {
        return \App\Core\Migrations::ready('user_emails');
    }

    /** @return list<array<string, mixed>> */
    public static function forUser(int $userId): array
    {
        if (!self::ready()) return [];
        return DB::all('SELECT * FROM {user_emails} WHERE user_id = ? ORDER BY id', [$userId]);
    }

    /**
     * Чей это адрес — основной или дополнительный.
     * Главный инструмент против дублей при соцвходе.
     *
     * Дополнительные адреса учитываются только ПОДТВЕРЖДЁННЫЕ: иначе, добавь
     * мы когда-нибудь ручной ввод, злоумышленник вписал бы чужой gmail себе
     * и перехватывал бы чужие соцвходы. Провайдерские адреса подтверждены
     * самим провайдером, поэтому на практике фильтр ничего не отсекает —
     * он страхует будущее.
     */
    public static function findOwner(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') return null;
        $user = User::findByEmail($email);
        if ($user) return $user;
        if (!self::ready()) return null;
        $row = DB::row('SELECT user_id FROM {user_emails} WHERE email = ? AND verified_at IS NOT NULL', [$email]);
        return $row ? User::find((int)$row['user_id']) : null;
    }

    /**
     * Добавить адрес, если он ничей. Возвращает false, когда адрес занят
     * другим аккаунтом — решать, что с этим делать, должен вызывающий.
     */
    public static function add(int $userId, string $email, string $source, bool $verified): bool
    {
        if (!self::ready()) return true;
        $email = mb_strtolower(trim($email));
        if ($email === '' || str_ends_with($email, '.invalid')) return true;

        $owner = self::findOwner($email);
        if ($owner !== null) return (int)$owner['id'] === $userId;

        DB::insert('user_emails', [
            'user_id' => $userId,
            'email' => $email,
            'source' => mb_substr($source, 0, 20),
            'verified_at' => $verified ? now() : null,
            'created_at' => now(),
        ]);
        return true;
    }

    public static function remove(int $userId, int $id): void
    {
        DB::run('DELETE FROM {user_emails} WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    /** При слиянии адреса дубля переезжают к основному аккаунту. */
    public static function moveAll(int $fromUserId, int $toUserId): void
    {
        if (!self::ready()) return;
        DB::run('UPDATE {user_emails} SET user_id = ? WHERE user_id = ?', [$toUserId, $fromUserId]);
    }
}
