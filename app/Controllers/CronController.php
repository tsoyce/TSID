<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CronTasks;

/** GET /cron?token=… — альтернатива CLI для планировщика (§5.3). */
final class CronController
{
    public function run(): never
    {
        $token = (string)($_GET['token'] ?? '');
        $expected = (string)config('cron.token', '');
        if ($expected === '' || !hash_equals($expected, $token)) {
            json_response(['error' => 'forbidden'], 403);
        }
        $log = CronTasks::run();
        json_response(['ok' => true, 'ran' => $log, 'time' => gmdate('c')]);
    }
}
