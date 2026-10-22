<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Случайные значения, UUID, шифрование секретов at rest (APP_KEY).
 * sodium secretbox при наличии расширения, иначе openssl aes-256-gcm.
 */
final class Crypto
{
    /** URL-safe случайный токен. 32 байта → 43 символа. */
    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** SHA-256 hex — единственный формат хранения токенов в БД. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Хэш-префикс (8 hex) для аудита — сам токен в логи не попадает никогда. */
    public static function tokenPrefix(string $token): string
    {
        return substr(self::hashToken($token), 0, 8);
    }

    private static function key(): string
    {
        $key = base64_decode((string)Config::get('app.key'), true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('APP_KEY отсутствует или повреждён');
        }
        return $key;
    }

    /** Шифрование секретов at rest (TOTP и т.п.). Формат: v1|base64(nonce.cipher). */
    public static function encrypt(string $plain): string
    {
        $key = self::key();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
            return 's1|' . base64_encode($nonce . $cipher);
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new \RuntimeException('Ошибка шифрования');
        return 'o1|' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        $key = self::key();
        [$ver, $b64] = explode('|', $encoded, 2) + [null, null];
        $raw = base64_decode((string)$b64, true);
        if ($raw === false) throw new \RuntimeException('Повреждённый шифротекст');
        if ($ver === 's1') {
            if (!function_exists('sodium_crypto_secretbox_open')) {
                throw new \RuntimeException('Нужно расширение sodium для расшифровки');
            }
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            if ($plain === false) throw new \RuntimeException('Ошибка расшифровки');
            return $plain;
        }
        if ($ver === 'o1') {
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain === false) throw new \RuntimeException('Ошибка расшифровки');
            return $plain;
        }
        throw new \RuntimeException('Неизвестный формат шифротекста');
    }

    /** base64url для JWT. */
    public static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
