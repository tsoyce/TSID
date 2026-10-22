<?php
/**
 * Роутер для встроенного PHP-сервера в тестах.
 *
 * `php -S ... -t public` делает рабочим каталогом docroot, а путь к базе в
 * config.php относительный (`storage/dev-test.sqlite`). Без этого сервер видит
 * `public/storage/...`, PDO падает с «unable to open database file», и это
 * незаметно: Setting::load() глушит исключение, страницы отдают 200 на
 * дефолтах, а валятся только POST-ы. Возвращаем каталог проекта перед каждым
 * запросом.
 */
$root = dirname(__DIR__);
chdir($root);
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/' && is_file($root . '/public' . $path)) return false;
require $root . '/public/index.php';
