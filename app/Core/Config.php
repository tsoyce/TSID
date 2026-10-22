<?php

declare(strict_types=1);

namespace App\Core;

/** Файловый конфиг (config/config.php). Дот-нотация, только чтение. */
final class Config
{
    private static array $data = [];
    private static bool $installed = false;

    public static function init(?array $data): void
    {
        self::$data = $data ?? [];
        self::$installed = $data !== null && is_file(SSO_ROOT . '/config/installed.lock');
    }

    public static function installed(): bool
    {
        return self::$installed;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $node = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) return $default;
            $node = $node[$part];
        }
        return $node;
    }
}
