<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Дата рождения из ответов соцпровайдеров (v3.8).
 *
 * Каждый отдаёт её по-своему, и у всех есть вариант «год скрыт»:
 *   · Яндекс — «1990-06-15», а со скрытым годом «0000-06-15»;
 *   · VK     — «15.6.1990», а без года просто «15.6».
 *
 * Без года дата в профиль не годится: колонка DATE, и «0000-06-15» либо ляжет
 * мусором, либо не пройдёт вовсе. Поэтому такие значения отбрасываются целиком —
 * лучше пустое поле, чем выдуманный год.
 */
final class Birthdate
{
    /** Разумные границы: раньше — опечатка, позже — шутка. */
    private const MIN_YEAR = 1900;

    /** @return string|null «ГГГГ-ММ-ДД» или null, если года нет либо дата бессмысленна */
    public static function parse(mixed $raw): ?string
    {
        if (!is_string($raw)) return null;
        $raw = trim($raw);
        if ($raw === '') return null;

        if (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $raw, $m)) {
            [, $y, $mo, $d] = $m;
        } elseif (preg_match('~^(\d{1,2})\.(\d{1,2})\.(\d{4})$~', $raw, $m)) {
            [, $d, $mo, $y] = $m;
        } else {
            /* «15.6» без года и всё остальное — не дата, которую можно хранить */
            return null;
        }

        $y = (int)$y; $mo = (int)$mo; $d = (int)$d;
        if ($y < self::MIN_YEAR || $y > (int)gmdate('Y')) return null;
        if (!checkdate($mo, $d, $y)) return null;

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
}
