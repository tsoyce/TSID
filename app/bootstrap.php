<?php
/**
 * SSO — bootstrap: автозагрузка, конфиг, обработка ошибок, сессия.
 * Подключается из public/index.php и cron.php.
 */

declare(strict_types=1);

define('SSO_ROOT', dirname(__DIR__));
define('SSO_START', microtime(true));
define('SSO_VERSION', '4.94.1');
/* Прежние имена (до 4.89) — для сторонних скриптов выкладки и проверок, что
   могли на них опираться. Переходный период: уберутся в 5.0. */
define('TSOY_ROOT', SSO_ROOT);
define('TSOY_VERSION', SSO_VERSION);

mb_internal_encoding('UTF-8');

/* --- Автозагрузка: App\ → app/, PHPMailer → lib/PHPMailer --- */
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $rel = str_replace('\\', '/', substr($class, 4));
        $file = SSO_ROOT . '/app/' . $rel . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
        /* Файла нет — PHP скажет только «Class not found», и по этому сообщению
           не понять, что именно искали. Чаще всего причина простая: файл не
           доехал при заливке. Путь пишем, но только в отладке: class_exists()
           — обычный способ спросить «а есть ли такой класс», и в проде запись
           на каждый такой вопрос забила бы лог. */
        /* Проверку окружения спрашиваем у Config — но не тогда, когда не нашли
           сам Config: иначе автозагрузчик позовёт себя же и уйдёт в рекурсию. */
        if ($class !== 'App\Core\Config' && \App\Core\Config::get('app.env') === 'debug') {
            error_log('SSO: класс ' . $class . ' — ожидался файл ' . $file);
        }
        return;
    }
    if (str_starts_with($class, 'PHPMailer\\PHPMailer\\')) {
        $file = SSO_ROOT . '/lib/PHPMailer/' . substr($class, strlen('PHPMailer\\PHPMailer\\')) . '.php';
        if (is_file($file)) require $file;
    }
});

require SSO_ROOT . '/app/helpers.php';

/* --- Request ID: сквозной идентификатор для логов и страницы 500 --- */
define('SSO_REQUEST_ID', bin2hex(random_bytes(4)));
define('TSOY_REQUEST_ID', SSO_REQUEST_ID); // прежнее имя, до 5.0

/* --- Конфиг --- */
use App\Core\Config;

$configFile = SSO_ROOT . '/config/config.php';
Config::init(is_file($configFile) ? require $configFile : null);

date_default_timezone_set(Config::get('app.timezone', 'Europe/Moscow'));

/* --- Ошибки: всё в лог, наружу — ничего лишнего --- */
error_reporting(E_ALL);
ini_set('display_errors', Config::get('app.env') === 'debug' ? '1' : '0');
ini_set('log_errors', '1');

set_error_handler(function (int $no, string $msg, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $no)) return false;
    // Депрекейты (PHP 8.4 их щедро раздаёт, в т.ч. вендорным библиотекам) — в лог, не в фатал
    if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED) {
        @error_log("deprecated: $msg @ $file:$line");
        return true;
    }
    throw new \ErrorException($msg, 0, $no, $file, $line);
});

/**
 * Маршруты без состояния: манифест, иконки и service worker.
 *
 * Сессия им не нужна, а вреда от неё два. Первый: браузер тянет их вне
 * пользовательского контекста, и каждый такой запрос заводил бы пустую сессию,
 * которую никто никогда не откроет. Второй важнее — session_start() ставит
 * «Expires: 1981» и «Pragma: no-cache», и иконка, которой положено лежать в
 * кэше неделю, перезапрашивалась бы каждый раз.
 */
$statelessPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$stateless = $statelessPath === '/sw.js'
    || $statelessPath === '/manifest.webmanifest'
    || str_starts_with($statelessPath, '/pwa/');

/* Пустой массив вместо незапущенной сессии: код читает $_SESSION через
   empty()/isset() и не должен знать, стартовала она или нет. */
if ($stateless) $_SESSION = [];

/* --- Сессия PHP (служебная: CSRF, flash, шаги флоу). Центральная SSO-сессия — своя, в БД. --- */
if (PHP_SAPI !== 'cli' && !$stateless && session_status() === PHP_SESSION_NONE) {
    /* Вход во фрейме на странице портала с другого домена — своя пара cookie
       (SameSite=None; Partitioned), см. App\Core\Framed (v4.73). */
    \App\Core\Framed::detect();
    session_name(\App\Core\Framed::cookieName('_s'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => \App\Core\Framed::secure(),
        'httponly' => true,
        'samesite' => \App\Core\Framed::sameSite(),
    ]);
    session_start();
}
