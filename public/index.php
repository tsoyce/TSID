<?php
/**
 * SSO — front controller. Все маршруты приходят сюда (rewrite в .htaccess/nginx).
 */

declare(strict_types=1);

// Встроенный сервер PHP (dev): статику отдаём как есть
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

/* Журнал активности (v4.72): запрос пишется в самом конце, когда известны
   ответ и вошедший. Статику и машинные точки он пропускает сам. */
\App\Core\ActivityLog::start();

use App\Core\Config;
use App\Core\ErrorPage;
use App\Core\Log;
use App\Core\Router;
use App\Core\Setting;

$uri = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* Подпись «ответило приложение».
   Без неё 404 неразличим: то ли маршрута нет, то ли запрос вообще не дошёл до
   PHP. Разница решающая — чинить надо в разных местах. Так и вышло с discovery:
   `.well-known/openid-configuration` отдавал 404, и по одному коду нельзя было
   сказать, наш это отказ или типовое правило хостинга «резать всё, что
   начинается с точки». Версию не пишем: посторонним знать её незачем. */
header('X-SSO-App: 1');
header('X-TSOY-ID: 1');   // прежнее имя метки (до 4.89), уйдёт в 5.0

/* --- Заголовки безопасности (§8.3) --- */
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Frame-Options: DENY');
$scriptSrc = "'self' 'nonce-" . csp_nonce() . "'";
/* Заголовки ставятся до try — а этот единственный из них ходит в базу.
   Недоступная база не должна ронять запрос здесь: ниже для неё есть честный
   ответ 503, и добраться до него важнее, чем разрешить виджет Telegram. */
$frameSrc = 'https://oauth.telegram.org';
$connectSrc = "'self'";
try {
    if (Config::installed() && Setting::get('social.telegram.enabled') === '1') {
        $scriptSrc .= ' https://telegram.org'; // виджет Telegram Login
    }
    /* Сторонний виджет капчи грузит свой скрипт и рисуется во фрейме. Домены
       открываются, только когда виджет настроен: пока владелец им не
       пользуется, политика остаётся закрытой. Без этой строки виджет не
       загрузился бы вовсе — рамка осталась бы пустой, а вход заперся. */
    if (Config::installed() && \App\Core\Captcha::widgetReady()) {
        $host = \App\Core\Captcha::provider() === 'turnstile'
            ? 'https://challenges.cloudflare.com'
            : 'https://*.hcaptcha.com https://hcaptcha.com';
        $scriptSrc .= ' ' . $host;
        $frameSrc .= ' ' . $host;
        /* Виджет ещё и сам ходит на свой домен за задачей — без connect-src
           он загрузился бы и молча не заработал. */
        $connectSrc .= ' ' . $host;
    }
} catch (\App\Core\DbUnavailable) {
    // молчим: разбор ниже
}
/* form-action собирает сам Csp: 'self' плюс портал, куда человек сейчас
   возвращается. Постоянное 'self' обрывало возврат после «Продолжить». */
\App\Core\Csp::start([
    'default-src' => ["'self'"],
    'script-src' => [$scriptSrc],
    'style-src' => ["'self'", "'unsafe-inline'"],
    'img-src' => ["'self'", 'data:'],
    'font-src' => ["'self'"],
    'connect-src' => [$connectSrc],
    'frame-src' => [$frameSrc],
    'frame-ancestors' => ["'none'"],
    'base-uri' => ["'self'"],
]);
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000');
}
/* Версию PHP наружу не отдаём (v4.67): замер 12.09 — каждый ответ боевой нёс
   «X-Powered-By: PHP/8.4.17», готовую подсказку, под какие уязвимости целиться.
   Выключается это в php.ini, а он принадлежит установке, — снимаем здесь. */
header_remove('X-Powered-By');

try {
    /* --- Не установлено → инсталлятор --- */
    if (!Config::installed()) {
        // До установки секретов ещё нет — ошибки показываем, а не прячем:
        // «белый экран» на этапе установки хуже любого стектрейса
        ini_set('display_errors', '1');
        if (is_file(SSO_ROOT . '/install/index.php')) {
            require SSO_ROOT . '/install/index.php';
            exit;
        }
        http_response_code(503);
        exit('SSO не установлен, а каталог /install отсутствует. Восстановите его из дистрибутива.');
    }

    /* --- Инсталлятор после установки заблокирован намертво (§7.0).
           Исключение: финальный экран сразу после установки (флаг в сессии). --- */
    if (($uri === '/install' || str_starts_with($uri, '/install/')) && !empty($_SESSION['install_done_info'])) {
        require SSO_ROOT . '/install/index.php';
        exit;
    }
    if ($uri === '/install' || str_starts_with($uri, '/install/')) {
        http_response_code(403);
        ErrorPage::render(403, [
            'title' => 'Установка завершена',
            'message' => 'Инсталлятор заблокирован. Вход — на странице /login. Каталог /install стоит удалить с сервера.',
        ]);
    }

    /* --- Заблокированные адреса (v4.72): 403 на любой странице, до всего
           остального — и до режима обслуживания, и до маршрутов. --- */
    \App\Core\IpBlock::enforce();

    /* --- Режим обслуживания (allowlist IP из настроек) --- */
    if (Setting::get('maintenance.enabled') === '1' && $uri !== '/status') {
        $allow = array_filter(array_map('trim', explode(',', (string)Setting::get('maintenance.allowlist', ''))));
        if (!in_array(request_ip(), $allow, true)) {
            http_response_code(503);
            header('Retry-After: 600');
            ErrorPage::render(503, [
                'title' => 'Идут работы',
                'message' => 'Сервис ненадолго закрыт на обслуживание. Загляните через несколько минут.',
            ]);
        }
    }

    $router = new Router();
    require SSO_ROOT . '/app/routes.php';
    $router->dispatch($method, $uri);
} catch (\App\Core\DbUnavailable $e) {
    /* Отдельно от прочих ошибок: причина почти всегда в одной строке конфига
       или в лежащем сервере БД, и говорить об этом надо прямо. Раньше такая
       установка выглядела работающей — страницы рисовались на файловых
       дефолтах, а падали только POST-ы. */
    Log::exception($e, 'db_unavailable');
    http_response_code(503);
    header('Retry-After: 60');
    ErrorPage::render(503, [
        'title' => 'База данных недоступна',
        'message' => 'Сервис не может подключиться к своей базе. Проверьте, отвечает ли сервер БД и верны ли реквизиты в config/config.php.',
    ]);
} catch (\Throwable $e) {
    Log::exception($e, 'unhandled');
    /* Недоехавший файл — самая частая причина падения на боевой и при этом
       самая незаметная: сообщение «Class ... not found» ничего не говорит
       владельцу, а страница отсылает его в журнал, который может быть той же
       страницей, что упала. Поэтому называем файл сразу — и в журнале, и на
       экране (администратору). */
    $blame = null;
    try {
        $blame = \App\Core\Delivery::blame($e);
        if ($blame !== null) Log::error('delivery.missing_file', $blame);
    } catch (\Throwable) {
        // Разбор причины не вправе стать второй причиной.
    }
    if (Config::get('app.env') === 'debug') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo get_class($e) . ': ' . $e->getMessage() . "\n\n" . $e->getTraceAsString();
        exit;
    }
    ErrorPage::render(500, $blame !== null ? ['delivery' => $blame] : []);
}
