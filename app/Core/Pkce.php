<?php

declare(strict_types=1);

namespace App\Core;

/** PKCE (RFC 7636), только S256 — plain не существует. */
final class Pkce
{
    /** Валидность code_challenge из /authorize. */
    public static function validChallenge(?string $challenge, ?string $method): bool
    {
        return $method === 'S256'
            && is_string($challenge)
            && preg_match('~^[A-Za-z0-9_-]{43}$~', $challenge) === 1;
    }

    /** Сверка verifier'а из /token с сохранённым challenge. */
    public static function verify(string $verifier, string $challenge): bool
    {
        if (!preg_match('#^[A-Za-z0-9._~\-]{43,128}$#', $verifier)) return false;
        $computed = Crypto::b64url(hash('sha256', $verifier, true));
        return hash_equals($challenge, $computed);
    }
}
