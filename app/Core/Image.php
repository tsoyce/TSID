<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Разбор картинки из байтов — с потолком по пикселям (v4.97.2).
 *
 * Потолок по байтам (5 МБ) не защищает: PNG в несколько килобайт
 * разворачивается в 30000×30000, и GD просит гигабайты. Запрос падал по
 * памяти с 500, а логотип портала так ронял весь запуск cron. Размеры
 * читаются из заголовка до разбора — это дёшево.
 */
final class Image
{
    /** 25 мегапикселей: 5000×5000, больше любого разумного аватара и логотипа. */
    public const MAX_PIXELS = 25_000_000;

    public static function fromBytes(string $bytes): \GdImage|false
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) return false;
        return @imagecreatefromstring($bytes);
    }
}
