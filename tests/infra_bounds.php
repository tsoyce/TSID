<?php
/**
 * Инфраструктура (v4.98) — находки ревизии кода, каждая со своей проверкой.
 *
 * Здесь проверяется, что:
 *   · журнал «Почта» не показывает действующих ссылок и кодов из писем. До 4.98
 *     роль «только чтение» открывала там письмо сброса пароля владельца;
 *   · второй запуск cron, пока идёт первый, ничего не делает;
 *   · сроки хранения чистят и неотправленные письма, и доставки событий, и
 *     счётчики лимитов, а ждущее доставки не трогают;
 *   · инсталлятор не открывается, если конфиг есть, а замка нет;
 *   · картинка с огромными размерами в заголовке не разбирается вовсе;
 *   · лимит частоты держит предел и открывает новое окно;
 *   · ссылка «//host» в документах — внешняя, с rel.
 *
 * Запуск: php tests/infra_bounds.php  (нужен dev-сервер на :8710)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\RateLimiter;
use App\Models\Client;

const SSO = 'http://localhost:8710';
$passed = 0;
$failed = 0;
$JAR = sys_get_temp_dir() . '/infra-bounds.txt';
@unlink($JAR);
$stamp = bin2hex(random_bytes(3));
$mails = [];
$clients = [];
$lockFile = SSO_ROOT . '/config/installed.lock';
$lockAside = $lockFile . '.aside-' . $stamp;
$mailDaysWas = \App\Core\Setting::get('retention.mail_days');

try {
    echo "── Журнал «Почта» без действующих ссылок ──\n";
    $secret = \App\Core\Crypto::randomToken(32);
    $mails[] = $mid = DB::insert('mail_queue', ['to_email' => "infra-$stamp@test.local", 'subject' => 'Сброс пароля',
        'body_html' => '<p>x</p>', 'body_text' => "Сбросить → http://localhost:8710/recover/$secret\nКод: 482913",
        'status' => 'sent', 'attempts' => 1, 'created_at' => now()]);
    DB::run('UPDATE {users} SET totp_secret = NULL, totp_enabled_at = NULL WHERE email = ?', ['admin@test.local']);
    DB::run('DELETE FROM {rate_limits}');
    [, $lp] = http('GET', SSO . '/login');
    http('POST', SSO . '/login', ['_csrf' => csrfOf($lp), 'identity' => 'admin@test.local', 'password' => 'correct-horse-battery']);
    [$st, $page] = http('GET', SSO . '/admin/logs/mail?view=' . $mid);
    ok($st === 200 && str_contains($page, '/recover/•••'), 'письмо открывается, ссылка в нём — без токена', "код $st");
    ok(!str_contains($page, $secret) && !str_contains($page, '482913'), 'ни токена, ни кода на странице нет');

    echo "\n── Cron: один запуск за раз ──\n";
    $fh = fopen(SSO_ROOT . '/storage/cron.lock', 'c');
    flock($fh, LOCK_EX);
    $log = \App\Core\CronTasks::run();
    ok(count($log) === 1 && str_contains($log[0], 'прошлый запуск'), 'пока идёт прошлый запуск, новый ничего не делает', implode('; ', $log));
    flock($fh, LOCK_UN);
    fclose($fh);

    echo "\n── Сроки хранения ──\n";
    $old = now_plus(-400 * 86400);
    $mails[] = $failedOld = DB::insert('mail_queue', ['to_email' => "infra-$stamp@test.local", 'subject' => 's', 'body_html' => '',
        'body_text' => '', 'status' => 'failed', 'attempts' => 5, 'created_at' => $old]);
    $mails[] = $pendingOld = DB::insert('mail_queue', ['to_email' => "infra-$stamp@test.local", 'subject' => 's', 'body_html' => '',
        'body_text' => '', 'status' => 'pending', 'attempts' => 0, 'created_at' => $old]);
    [$cid] = Client::create(['name' => 'Проба хранения', 'slug' => 'infra-' . $stamp, 'base_url' => "https://infra-$stamp.example",
        'redirect_uris' => ["https://infra-$stamp.example/cb"], 'post_logout_uris' => [], 'backchannel_uri' => null]);
    $clients[] = (int)$cid;
    $evOld = DB::insert('client_events', ['client_id' => (int)$cid, 'event' => 'test', 'payload' => '{}', 'status' => 'failed',
        'attempts' => 9, 'created_at' => $old]);
    $evPending = DB::insert('client_events', ['client_id' => (int)$cid, 'event' => 'test', 'payload' => '{}', 'status' => 'pending',
        'attempts' => 0, 'created_at' => $old, 'next_attempt_at' => now_plus(86400)]);
    \App\Core\Setting::set('retention.mail_days', '30');
    \App\Core\Setting::flush();
    DB::insert('rate_limits', ['bucket' => "infra:$stamp", 'points' => 1, 'window_start' => $old]);
    \App\Core\CronTasks::runNow(['logs_rotate']);
    $gone = static fn(string $t, int $id): bool => !DB::val("SELECT 1 FROM {{$t}} WHERE id = ?", [$id]);
    ok($gone('mail_queue', $failedOld), 'старое неотправленное письмо удалено');
    ok($gone('client_events', $evOld), 'старая доставка события удалена');
    ok(!DB::val('SELECT 1 FROM {rate_limits} WHERE bucket = ?', ["infra:$stamp"]), 'старый счётчик лимита удалён');
    ok(!$gone('mail_queue', $pendingOld) && !$gone('client_events', $evPending), 'а ждущее доставки — на месте');

    echo "\n── Инсталлятор без замка ──\n";
    rename($lockFile, $lockAside);
    [$st, $body] = http('GET', SSO . '/install/', [], [], sys_get_temp_dir() . '/infra-install.txt');
    rename($lockAside, $lockFile);
    ok($st === 403 && str_contains($body, 'installed.lock'), 'конфиг есть, замка нет — установщик закрыт', "код $st");

    echo "\n── Картинка: потолок по пикселям ──\n";
    // Настоящий PNG 5100×5100 в оттенках серого: десятки килобайт, а GD
    // развернул бы его в 26 мегапикселей.
    $chunk = static fn(string $t, string $d): string => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    $side = 5100;
    $bomb = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $side, $side, 8, 0, 0, 0, 0))
        . $chunk('IDAT', (string)gzcompress(str_repeat("\0", ($side + 1) * $side), 9)) . $chunk('IEND', '');
    ok(strlen($bomb) < 100_000 && \App\Core\Image::fromBytes($bomb) === false,
        'PNG 5100×5100 весом ' . intdiv(strlen($bomb), 1024) . ' КБ — не разбирается');
    $g = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($g);
    $small = (string)ob_get_clean();
    ok(\App\Core\Image::fromBytes($small) instanceof \GdImage, 'обычная картинка — разбирается');

    echo "\n── Лимит частоты ──\n";
    $b = "infra-rl:$stamp";
    $hits = [];
    for ($i = 0; $i < 4; $i++) $hits[] = RateLimiter::hit($b, 3, 60);
    ok($hits === [true, true, true, false], 'три попытки из трёх, четвёртая — нет', json_encode($hits));
    DB::run('UPDATE {rate_limits} SET window_start = ? WHERE bucket = ?', [now_plus(-120), $b]);
    ok(RateLimiter::hit($b, 3, 60) && (int)DB::val('SELECT points FROM {rate_limits} WHERE bucket = ?', [$b]) === 1,
        'окно кончилось — новое начинается с единицы');
    RateLimiter::clear($b);

    echo "\n── Markdown ──\n";
    $md = \App\Core\Markdown::toHtml('[тут](//evil.example/x) и [там](HTTPS://evil.example)');
    ok(substr_count($md, 'rel="noopener noreferrer"') === 2, 'ссылки «//host» и HTTPS:// — внешние, с rel', $md);
} finally {
    if ($mailDaysWas === null) DB::run('DELETE FROM {settings} WHERE `key` = ?', ['retention.mail_days']);
    else \App\Core\Setting::set('retention.mail_days', (string)$mailDaysWas);
    \App\Core\Setting::flush();
    if (is_file($lockAside)) rename($lockAside, $lockFile);
    foreach ($mails as $m) DB::run('DELETE FROM {mail_queue} WHERE id = ?', [$m]);
    foreach ($clients as $c) Client::purge($c);
    @unlink($JAR);
}

echo "\n══ Итог ══\nПройдено: $passed, провалено: $failed\n";
exit($failed > 0 ? 1 : 0);
