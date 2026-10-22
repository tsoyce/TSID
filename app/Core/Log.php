<?php

declare(strict_types=1);

namespace App\Core;

/**
 * JSON-lines лог: storage/logs/app-YYYY-MM-DD.log
 * {ts, level, msg, ctx, request_id}. Секреты сюда не попадают — никогда.
 */
final class Log
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public static function debug(string $msg, array $ctx = []): void { self::write('debug', $msg, $ctx); }
    public static function info(string $msg, array $ctx = []): void { self::write('info', $msg, $ctx); }
    public static function warning(string $msg, array $ctx = []): void { self::write('warning', $msg, $ctx); }
    public static function error(string $msg, array $ctx = []): void { self::write('error', $msg, $ctx); }

    public static function exception(\Throwable $e, string $note = ''): void
    {
        self::write('error', ($note !== '' ? "$note: " : '') . $e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
    }

    private static function write(string $level, string $msg, array $ctx): void
    {
        $min = Config::get('app.env') === 'debug' ? 'debug' : 'info';
        if (self::LEVELS[$level] < self::LEVELS[$min]) return;
        $line = json_encode([
            'ts' => gmdate('c'),
            'level' => $level,
            'msg' => $msg,
            'ctx' => $ctx ?: new \stdClass(),
            'request_id' => defined('SSO_REQUEST_ID') ? SSO_REQUEST_ID : '-',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $dir = SSO_ROOT . '/storage/logs';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
