<?php
/**
 * Замер скорости экранов: где интерфейс думает дольше, чем человек ждёт.
 *
 * «Лагает» — это не ощущение, а число. Меряем время ответа каждого экрана
 * админки и кабинета живым запросом, по три раза, и берём медиану: первый
 * запрос всегда дороже (кэш опкода, соединение с базой), и судить по нему
 * значит чинить не то.
 *
 * Порог назван вслух: 300 мс — предел, за которым нажатие перестаёт
 * чувствоваться мгновенным. Всё, что дольше, попадает в отчёт вместе с
 * размером ответа: тяжёлый HTML и медленный запрос лечатся по-разному.
 *
 * Запуск (сервер должен быть поднят):
 *   php tools/speed_probe.php http://localhost:8710 <cookie>
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$base = rtrim($argv[1] ?? 'http://localhost:8710', '/');
$cookie = $argv[2] ?? '';
const SLOW_MS = 300;

/** Один запрос: время в миллисекундах, код, размер. */
function hit(string $url, string $cookie): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIE => (string)App\Core\Config::get('session.name', 'sso') . '=' . $cookie,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $t = microtime(true);
    $body = (string)curl_exec($ch);
    $ms = (microtime(true) - $t) * 1000;
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$ms, $code, strlen($body)];
}

/* Экраны берём из тех же перечней, что рисуют навигацию и настройки, — новый
   раздел попадёт под замер сам. */
$routes = ['/admin', '/profile'];
foreach (\App\Core\SettingsMap::sections() as $s) $routes[] = $s['route'];
foreach (['/admin/users', '/admin/clients', '/admin/orgs', '/admin/roles',
          '/admin/logs', '/admin/logs/errors', '/admin/logs/mail',
          '/profile/security', '/profile/sessions', '/profile/services',
          '/profile/settings', '/profile/edit', '/about'] as $r) {
    $routes[] = $r;
}
/* Карточки: самые тяжёлые экраны — там вкладки, история и связи. */
foreach ((array)\App\Core\DB::all('SELECT id FROM {clients} ORDER BY id LIMIT 2') as $r) {
    $routes[] = '/admin/clients/' . (int)$r['id'];
}
foreach ((array)\App\Core\DB::all('SELECT id FROM {users} ORDER BY id LIMIT 2') as $r) {
    $routes[] = '/admin/users/' . (int)$r['id'];
}
$routes = array_values(array_unique($routes));

echo "══ Скорость экранов ══\n";
printf("порог %d мс, по три замера, берём медиану\n\n", SLOW_MS);

$slow = [];
$broken = [];
foreach ($routes as $route) {
    $runs = [];
    $code = 0;
    $size = 0;
    for ($i = 0; $i < 3; $i++) {
        [$ms, $code, $size] = hit($base . $route, $cookie);
        $runs[] = $ms;
    }
    sort($runs);
    $median = $runs[1];
    if (!in_array($code, [200, 302], true)) {
        $broken[] = sprintf('%s → HTTP %d', $route, $code);
        continue;
    }
    $mark = $median > SLOW_MS ? '✗' : '·';
    printf("  %s %-44s %6.0f мс  %5.0f КБ\n", $mark, $route, $median, $size / 1024);
    if ($median > SLOW_MS) $slow[] = sprintf('%s — %.0f мс', $route, $median);
}

echo "\n══ Итог ══\n";
foreach ($broken as $b) echo "  ✗ $b\n";
foreach ($slow as $s) echo "  ✗ медленно: $s\n";
if ($slow === [] && $broken === []) echo "  ✓ все экраны отвечают быстрее " . SLOW_MS . " мс\n";
exit($slow === [] && $broken === [] ? 0 : 1);
