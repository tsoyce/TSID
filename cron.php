<?php
/**
 * SSO · cron-точка (CLI): * * * * * php /path/to/cron.php
 * Задачи и их частоты — App\Core\CronTasks (§11.4).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only; для HTTP используйте /cron?token=…');
}

require __DIR__ . '/app/bootstrap.php';

if (!\App\Core\Config::installed()) {
    fwrite(STDERR, "SSO не установлен — cron пропущен\n");
    exit(1);
}

$log = \App\Core\CronTasks::run();
if ($log !== []) {
    echo '[' . gmdate('c') . "]\n";
    foreach ($log as $line) echo "  $line\n";
}
