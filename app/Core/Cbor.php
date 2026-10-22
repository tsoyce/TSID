<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Минимальный CBOR-декодер (RFC 8949) — ровно то подмножество, что встречается
 * в WebAuthn attestationObject и COSE-ключах: числа, байтовые/текстовые строки,
 * массивы, map'ы. Без float и indefinite-length — их в WebAuthn не бывает.
 */
final class Cbor
{
    /** Декодирует первый элемент; $offset сдвигается за него. */
    public static function decode(string $bin, int &$offset = 0): mixed
    {
        if ($offset >= strlen($bin)) {
            throw new \RuntimeException('CBOR: неожиданный конец данных');
        }
        $initial = ord($bin[$offset++]);
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        switch ($major) {
            case 0: // unsigned int
                return self::readLength($bin, $offset, $info);
            case 1: // negative int
                return -1 - self::readLength($bin, $offset, $info);
            case 2: // byte string
            case 3: // text string
                $len = self::readLength($bin, $offset, $info);
                if ($offset + $len > strlen($bin)) throw new \RuntimeException('CBOR: строка выходит за границы');
                $v = substr($bin, $offset, $len);
                $offset += $len;
                return $v;
            case 4: // array
                $len = self::readLength($bin, $offset, $info);
                $arr = [];
                for ($i = 0; $i < $len; $i++) {
                    $arr[] = self::decode($bin, $offset);
                }
                return $arr;
            case 5: // map
                $len = self::readLength($bin, $offset, $info);
                $map = [];
                for ($i = 0; $i < $len; $i++) {
                    $key = self::decode($bin, $offset);
                    $val = self::decode($bin, $offset);
                    if (!is_int($key) && !is_string($key)) throw new \RuntimeException('CBOR: неподдерживаемый ключ map');
                    $map[$key] = $val;
                }
                return $map;
            case 6: // tag — пропускаем, отдаём содержимое
                self::readLength($bin, $offset, $info);
                return self::decode($bin, $offset);
            case 7: // simple values
                if ($info === 20) return false;
                if ($info === 21) return true;
                if ($info === 22) return null;
                throw new \RuntimeException('CBOR: float/simple не поддерживаются (major 7, info ' . $info . ')');
        }
        throw new \RuntimeException('CBOR: неизвестный major type');
    }

    private static function readLength(string $bin, int &$offset, int $info): int
    {
        if ($info < 24) return $info;
        $bytes = match ($info) {
            24 => 1, 25 => 2, 26 => 4, 27 => 8,
            default => throw new \RuntimeException('CBOR: indefinite length не поддерживается'),
        };
        if ($offset + $bytes > strlen($bin)) throw new \RuntimeException('CBOR: длина выходит за границы');
        $val = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $val = ($val << 8) | ord($bin[$offset++]);
        }
        if ($val < 0) throw new \RuntimeException('CBOR: переполнение длины');
        return $val;
    }
}
