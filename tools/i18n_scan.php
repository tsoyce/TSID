<?php
/**
 * Сколько русского осталось на отрисованных страницах при выбранном языке.
 *
 * Непереведённая строка не ломает интерфейс — Lang::t тихо падает на русский,
 * поэтому увидеть пропуск можно только замером. Скрипт логинится, переключает
 * язык и считает кириллические слова в готовом HTML, вычитая содержимое
 * данных (имена пользователей, названия порталов) не умеет — поэтому смотрит
 * только на страницы, где данные заведомо латинские, и печатает сами слова.
 *
 * Запуск: php tools/i18n_scan.php [базовый-URL] [email] [пароль]
 */

declare(strict_types=1);

$base = $argv[1] ?? 'http://localhost:8710';
$email = $argv[2] ?? 'admin@test.local';
$pass = $argv[3] ?? 'correct-horse-battery';
$jar = sys_get_temp_dir() . '/sso-i18n-' . getmypid() . '.txt';

function req(string $url, string $jar, array $post = []): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15,
    ]);
    if ($post !== []) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $body = (string)curl_exec($ch);
    curl_close($ch);
    return $body;
}

$login = req("$base/login", $jar);
preg_match('~name="_csrf" value="([^"]+)"~', $login, $m);
req("$base/login", $jar, ['_csrf' => $m[1] ?? '', 'identity' => $email, 'password' => $pass]);
req("$base/lang/en", $jar);

$pages = ['/admin', '/admin/users', '/admin/clients', '/admin/orgs', '/admin/roles',
          '/admin/webhooks', '/admin/logs', '/admin/logs/errors', '/admin/settings',
          '/admin/health', '/admin/tools'];

$total = 0;
foreach ($pages as $p) {
    $html = req($base . $p, $jar);
    /* Скрипты и стили — не интерфейс; данные тоже мешают, но на тестовой
       установке они латинские, а реальные названия видно глазами. */
    $html = (string)preg_replace('~<script[^>]*>.*?</script>~s', '', $html);
    $html = (string)preg_replace('~<style[^>]*>.*?</style>~s', '', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    preg_match_all('~[А-Яа-яЁё][А-Яа-яЁё-]+~u', $text, $m);
    $words = array_values(array_unique($m[0]));
    $total += count($words);
    printf("%-22s %s\n", $p, $words === [] ? 'чисто' : count($words) . ': ' . implode(', ', array_slice($words, 0, 10)));
}
@unlink($jar);
printf("\nвсего непереведённых слов: %d\n", $total);
exit($total === 0 ? 0 : 1);
