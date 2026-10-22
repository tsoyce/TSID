<?php

declare(strict_types=1);

namespace App\Core;

/**
 * JWT строго RS256 (openssl). Никакой поддержки других алгоритмов —
 * защита от alg-confusion по построению. kid обязателен в заголовке.
 */
final class Jwt
{
    /** Подписать claims активным ключом. */
    public static function sign(array $claims, ?string $kid = null): string
    {
        $kid = $kid ?? Keys::activeKid();
        $privatePem = Keys::privatePem($kid);
        $header = Crypto::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid], JSON_UNESCAPED_SLASHES));
        $payload = Crypto::b64url(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $input = $header . '.' . $payload;
        $key = openssl_pkey_get_private($privatePem);
        if ($key === false) throw new \RuntimeException('Приватный ключ не читается');
        if (!openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('openssl_sign failed');
        }
        return $input . '.' . Crypto::b64url($sig);
    }

    /**
     * Проверка подписи и базовых клеймов. Возвращает payload или null.
     * exp/nbf проверяются здесь; iss/aud/nonce — на вызывающей стороне.
     */
    public static function verify(string $jwt, int $leeway = 60): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;
        [$h64, $p64, $s64] = $parts;
        $header = json_decode((string)Crypto::b64urlDecode($h64), true);
        $payload = json_decode((string)Crypto::b64urlDecode($p64), true);
        $sig = Crypto::b64urlDecode($s64);
        if (!is_array($header) || !is_array($payload) || $sig === false) return null;
        if (($header['alg'] ?? '') !== 'RS256') return null;
        $kid = (string)($header['kid'] ?? '');
        if ($kid === '') return null;
        $publicPem = Keys::publicPem($kid);
        if ($publicPem === null) return null;
        $key = openssl_pkey_get_public($publicPem);
        if ($key === false) return null;
        if (openssl_verify($h64 . '.' . $p64, $sig, $key, OPENSSL_ALGO_SHA256) !== 1) return null;
        $now = time();
        if (isset($payload['exp']) && $now > (int)$payload['exp'] + $leeway) return null;
        if (isset($payload['nbf']) && $now < (int)$payload['nbf'] - $leeway) return null;
        return $payload;
    }
}
