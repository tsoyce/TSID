<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** WebAuthn-ключи пользователя ({passkeys}). */
final class Passkey
{
    public static function forUser(int $userId): array
    {
        return DB::all('SELECT * FROM {passkeys} WHERE user_id = ? ORDER BY id', [$userId]);
    }

    public static function credentialIdsForUser(int $userId): array
    {
        return array_column(self::forUser($userId), 'credential_id');
    }

    public static function findByCredentialId(string $credentialId): ?array
    {
        return DB::row('SELECT * FROM {passkeys} WHERE credential_id = ?', [$credentialId]);
    }

    public static function create(int $userId, array $cred, ?string $label, ?string $transports): int
    {
        return DB::insert('passkeys', [
            'user_id' => $userId,
            'credential_id' => $cred['credential_id'],
            'public_key' => $cred['public_key'],
            'alg' => $cred['alg'],
            'sign_count' => $cred['sign_count'],
            'transports' => $transports ? mb_substr($transports, 0, 255) : null,
            'label' => $label ? mb_substr($label, 0, 100) : null,
            'created_at' => now(),
        ]);
    }

    public static function recordUse(int $id, int $signCount): void
    {
        DB::update('passkeys', ['sign_count' => $signCount, 'last_used_at' => now()], 'id = ?', [$id]);
    }

    public static function delete(int $id, int $userId): bool
    {
        return DB::run('DELETE FROM {passkeys} WHERE id = ? AND user_id = ?', [$id, $userId])->rowCount() > 0;
    }

    public static function countForUser(int $userId): int
    {
        return (int)DB::val('SELECT COUNT(*) FROM {passkeys} WHERE user_id = ?', [$userId]);
    }
}
