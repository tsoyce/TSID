<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\DB;

/**
 * Refresh-токены (§3.3): opaque 64 байта, в БД sha256, ротация на каждом
 * использовании, family_id + reuse-detection → отзыв всей семьи.
 */
final class RefreshToken
{
    private const TTL = 30 * 86400; // 30 дней скользящие

    /** Новая семья (при обмене кода). Возвращает plaintext-токен. */
    public static function issueFamily(int $userId, int $clientDbId, ?int $sessionId, string $scope): string
    {
        return self::issue($userId, $clientDbId, $sessionId, $scope, Crypto::uuid4());
    }

    private static function issue(int $userId, int $clientDbId, ?int $sessionId, string $scope, string $familyId): string
    {
        $token = Crypto::randomToken(64);
        DB::insert('refresh_tokens', [
            'token_hash' => Crypto::hashToken($token),
            'family_id' => $familyId,
            'user_id' => $userId,
            'client_id' => $clientDbId,
            'session_id' => $sessionId,
            'scope' => $scope,
            'expires_at' => now_plus(self::TTL),
            'created_at' => now(),
        ]);
        return $token;
    }

    /**
     * Ротация: старый помечается, выдаётся новый той же семьи.
     * Повторное использование ротированного → отзыв семьи + аудит (⚠).
     * Возвращает [row, newToken] или [null, null].
     */
    public static function rotate(string $token): array
    {
        $row = DB::row('SELECT * FROM {refresh_tokens} WHERE token_hash = ?', [Crypto::hashToken($token)]);
        if (!$row) return [null, null];
        if ($row['revoked_at'] !== null || strtotime($row['expires_at'] . ' UTC') <= time()) return [null, null];
        /* Помечаем условным UPDATE и продолжаем, только если строка досталась
           нам (v4.70). Раньше два одновременных обновления одним токеном оба
           проходили проверку «ещё не ротирован» и оба получали новый токен —
           детектор повтора, ради которого семьи и заводились, не срабатывал. */
        $won = $row['rotated_at'] === null
            && DB::update('refresh_tokens', ['rotated_at' => now()], 'id = ? AND rotated_at IS NULL', [$row['id']]) === 1;
        if (!$won) {
            self::revokeFamily($row['family_id']);
            Audit::log('token.refresh_reuse', [
                'actor' => null, 'on' => (int)$row['user_id'], 'client' => (int)$row['client_id'],
                'meta' => ['family' => $row['family_id'], 'token_prefix' => substr($row['token_hash'], 0, 8)],
            ]);
            return [null, null];
        }
        $new = self::issue((int)$row['user_id'], (int)$row['client_id'],
            $row['session_id'] !== null ? (int)$row['session_id'] : null, $row['scope'], $row['family_id']);
        return [$row, $new];
    }

    public static function revokeFamily(string $familyId): void
    {
        DB::run('UPDATE {refresh_tokens} SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL',
            [now(), $familyId]);
    }

    public static function findValid(string $token): ?array
    {
        $row = DB::row('SELECT * FROM {refresh_tokens} WHERE token_hash = ?', [Crypto::hashToken($token)]);
        if (!$row || $row['revoked_at'] !== null || $row['rotated_at'] !== null) return null;
        if (strtotime($row['expires_at'] . ' UTC') <= time()) return null;
        return $row;
    }

    public static function revokeByToken(string $token): bool
    {
        $row = DB::row('SELECT * FROM {refresh_tokens} WHERE token_hash = ?', [Crypto::hashToken($token)]);
        if (!$row) return false;
        self::revokeFamily($row['family_id']);
        return true;
    }

    public static function revokeAllForUser(int $userId): void
    {
        DB::run('UPDATE {refresh_tokens} SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [now(), $userId]);
    }

    public static function purgeExpired(): int
    {
        return DB::run('DELETE FROM {refresh_tokens} WHERE expires_at < ?', [now_plus(-86400)])->rowCount();
    }
}
