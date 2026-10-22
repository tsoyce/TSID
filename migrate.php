<?php
/**
 * Применение миграций из консоли.
 *
 *   php migrate.php          применить всё недостающее
 *   php migrate.php --status только показать, что не применено
 *
 * Нужен затем, что админка может быть недоступна целиком: если код обновили,
 * а миграции нет, часть страниц отдаёт 500 — включая ту, где эти миграции
 * применяются. По SSH выбраться должно быть можно всегда.
 *
 * Запускать из корня проекта, от пользователя веб-сервера (иначе новые файлы
 * в storage/ окажутся с чужим владельцем).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Только из консоли.\n");
}

require __DIR__ . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Migrations;

if (!Config::installed()) {
    fwrite(STDERR, "Проект ещё не установлен — пройдите /install.\n");
    exit(1);
}

$pending = Migrations::pending();
$applied = Migrations::applied();

echo 'Применено ранее: ' . ($applied === [] ? '—' : implode(', ', $applied)) . "\n";

if ($pending === []) {
    echo "Нечего применять: схема совпадает с кодом.\n";
    exit(0);
}
echo 'Не применено: ' . implode(', ', $pending) . "\n";

if (in_array('--status', $argv, true)) exit(1);

echo "Применяю…\n";
$result = Migrations::apply();
foreach ($result['applied'] as $v) echo "  ✓ $v\n";

if ($result['failed'] !== null) {
    fwrite(STDERR, "  ✗ {$result['failed']}: {$result['error']}\n");
    fwrite(STDERR, "Остановился на первой ошибке — следующие миграции могут зависеть от неё.\n");
    exit(1);
}
echo "Готово.\n";
