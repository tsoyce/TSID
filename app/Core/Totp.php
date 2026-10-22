<?php

declare(strict_types=1);

namespace App\Core;

/** TOTP (RFC 6238): 6 цифр, шаг 30 с, SHA-1 — совместимо со всеми аутентификаторами. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Новый секрет: 20 случайных байт → base32. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** otpauth:// URI для QR-кода. */
    public static function uri(string $secret, string $accountLabel, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $accountLabel)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    /** Проверка кода с окном ±1 шаг (§: часы гуляют). */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        return self::matchStep($secret, $code, $window) !== null;
    }

    /**
     * Какому 30-секундному шагу соответствует код — или null.
     *
     * Шаг нужен, чтобы код нельзя было предъявить второй раз (v4.70): окно ±1
     * держит его живым полторы минуты, и подсмотренный код входил повторно.
     * Вызывающий запоминает принятый шаг и отказывает всему, что не новее.
     */
    public static function matchStep(string $secret, string $code, int $window = 1): ?int
    {
        $code = (string)preg_replace('~\D~', '', $code);
        if (strlen($code) !== 6) return null;
        $counter = (int)floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $counter + $i), $code)) return $counter + $i;
        }
        return null;
    }

    public static function code(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        $bin = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % 1000000;
        return str_pad((string)$value, 6, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bin): string
    {
        $out = '';
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('~[^A-Za-z2-7]~', '', $b32))) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) continue;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
        }
        return $out;
    }
}
