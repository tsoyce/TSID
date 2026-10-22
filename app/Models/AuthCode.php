<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\DB;

/** Одноразовые authorization codes: TTL 60 с, в БД только sha256 (§2.2). */
final class AuthCode
{
    public static function issue(int $clientDbId, int $userId, int $sessionId, string $redirectUri,
                                 string $scope, ?string $nonce, string $codeChallenge, string $authTime): string
    {
        $code = Crypto::randomToken(32);
        DB::insert('auth_codes', [
            'code_hash' => Crypto::hashToken($code),
            'client_id' => $clientDbId,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'auth_time' => $authTime,
            'expires_at' => now_plus(60),
        ]);
        return $code;
    }

    public static function findByCode(string $code): ?array
    {
        return DB::row('SELECT * FROM {auth_codes} WHERE code_hash = ?', [Crypto::hashToken($code)]);
    }

    /**
     * Погасить код. true — погасили мы, false — его уже погасил другой запрос.
     *
     * Условие стоит в самом UPDATE, а не в проверке перед ним (v4.70). Иначе
     * два одновременных обмена одним кодом оба видели «ещё не погашен» и оба
     * получали токены: перехваченный код обменивался наперегонки с законным.
     */
    public static function consume(int $id): bool
    {
        return DB::update('auth_codes', ['consumed_at' => now()], 'id = ? AND consumed_at IS NULL', [$id]) === 1;
    }

    public static function purgeExpired(): int
    {
        return DB::run('DELETE FROM {auth_codes} WHERE expires_at < ?', [now_plus(-300)])->rowCount();
    }
}
