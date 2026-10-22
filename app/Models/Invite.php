<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\DB;

/**
 * Инвайт-ссылки: закрывают политику access_policy = invite (§9.1),
 * которая до сих пор умела только вежливо отказывать.
 */
final class Invite
{
    /** Создать ссылку. Возвращает plaintext-токен (показывается один раз). */
    public static function create(int $clientDbId, array $opts, ?int $createdBy): string
    {
        $token = Crypto::randomToken(24);
        DB::insert('invites', [
            'token_hash' => Crypto::hashToken($token),
            'client_id' => $clientDbId,
            'role_id' => $opts['role_id'] ?? null,
            'email' => $opts['email'] ?? null,
            'max_uses' => max(1, min(1000, (int)($opts['max_uses'] ?? 1))),
            'note' => $opts['note'] ?? null,
            'created_by' => $createdBy,
            'expires_at' => now_plus(max(1, (int)($opts['days'] ?? 7)) * 86400),
            'created_at' => now(),
        ]);
        return $token;
    }

    /** Живой инвайт по токену (с данными портала) или null. */
    public static function findUsable(string $token): ?array
    {
        $row = DB::row(
            'SELECT i.*, c.name AS client_name, c.slug AS client_slug, c.base_url, c.icon_path, c.is_active
             FROM {invites} i JOIN {clients} c ON c.id = i.client_id
             WHERE i.token_hash = ?', [Crypto::hashToken($token)]);
        if (!$row) return null;
        if ($row['revoked_at'] !== null) return null;
        if (strtotime($row['expires_at'] . ' UTC') <= time()) return null;
        if ((int)$row['used'] >= (int)$row['max_uses']) return null;
        if (!(int)$row['is_active']) return null;
        return $row;
    }

    /** Принять: выдать доступ и роль, засчитать использование. */
    public static function accept(array $invite, int $userId): void
    {
        DB::tx(function () use ($invite, $userId) {
            Access::grant($userId, (int)$invite['client_id'], $invite['created_by'] !== null
                ? (int)$invite['created_by'] : null, 'active');
            if ($invite['role_id'] !== null) {
                Role::assign($userId, (int)$invite['role_id'], $invite['created_by'] !== null
                    ? (int)$invite['created_by'] : null);
            }
            DB::run('UPDATE {invites} SET used = used + 1 WHERE id = ?', [(int)$invite['id']]);
        });
    }

    public static function forClient(int $clientDbId): array
    {
        return DB::all(
            'SELECT i.*, r.slug AS role_slug FROM {invites} i
             LEFT JOIN {roles} r ON r.id = i.role_id
             WHERE i.client_id = ? ORDER BY i.id DESC LIMIT 50', [$clientDbId]);
    }

    public static function revoke(int $id, int $clientDbId): bool
    {
        return DB::update('invites', ['revoked_at' => now()],
            'id = ? AND client_id = ? AND revoked_at IS NULL', [$id, $clientDbId]) > 0;
    }

    /** Человеческий статус для админки. */
    public static function status(array $i): array
    {
        if ($i['revoked_at'] !== null) return ['badge-muted', 'отозван'];
        if (strtotime($i['expires_at'] . ' UTC') <= time()) return ['badge-muted', 'истёк'];
        if ((int)$i['used'] >= (int)$i['max_uses']) return ['badge-muted', 'исчерпан'];
        return ['badge-ok', 'активен'];
    }
}
