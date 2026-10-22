<?php

declare(strict_types=1);

namespace App\Core;

/** Одноразовые сообщения (тосты): success / error / info. */
final class Flash
{
    public static function add(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $m): void { self::add('success', $m); }
    public static function error(string $m): void { self::add('error', $m); }
    public static function info(string $m): void { self::add('info', $m); }

    /** Забрать и очистить. */
    public static function pull(): array
    {
        $all = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $all;
    }
}
