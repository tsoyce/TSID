<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Setting;
use App\Core\View;

/** Публичный /status: ok/degraded без деталей (§11.3). */
final class StatusController
{
    public function status(): never
    {
        $degraded = [];
        try {
            $t = microtime(true);
            DB::val('SELECT 1');
            if ((microtime(true) - $t) * 1000 > 500) $degraded[] = 'db';
        } catch (\Throwable) {
            $degraded[] = 'db';
        }
        try {
            $hb = (string)Setting::get('cron.heartbeat', '');
            if ($hb === '' || strtotime($hb . ' UTC') < time() - 600) $degraded[] = 'cron';
        } catch (\Throwable) {
            // без БД и настроек не дотянуться — уже учтено выше
        }
        if (!is_writable(SSO_ROOT . '/storage')) $degraded[] = 'storage';

        $payload = [
            'status' => $degraded === [] ? 'ok' : 'degraded',
            'time' => gmdate('c'),
        ];
        // Человекочитаемая страница для браузера, JSON — для мониторинга
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'text/html')) {
            View::render('status', [
                'pageTitle' => 'Статус',
                'ok' => $degraded === [],
            ], 'layouts/minimal');
        }
        json_response($payload);
    }
}
