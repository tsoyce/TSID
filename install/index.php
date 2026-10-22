<?php
/**
 * SSO · Инсталлятор (§7). Самодостаточен: до финала конфиг не нужен,
 * работает на чистом PHP + PDO. Подключается из public/index.php,
 * когда config/config.php ещё не существует.
 */

declare(strict_types=1);

if (!defined('SSO_ROOT')) exit('Прямой запуск запрещён');

require __DIR__ . '/helpers.php';
require __DIR__ . '/steps.php';
require __DIR__ . '/views.php';

/* Лог хода установки: storage/logs/install.log — читается глазами при проблемах */
function ilog(string $msg): void
{
    $dir = SSO_ROOT . '/storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents($dir . '/install.log',
        gmdate('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}

/* Самодиагностика окружения: /install/?diag=1 */
if (isset($_GET['diag'])) {
    require __DIR__ . '/diag.php';
    exit;
}

/* Заблокирован после установки (§7.0); финальный экран — один раз по флагу сессии */
if (is_file(SSO_ROOT . '/config/installed.lock')) {
    if (!empty($_SESSION['install_done_info'])) {
        install_render(7, ['step_done' => 7, 'data' => []]);
        exit;
    }
    http_response_code(403);
    exit('<meta charset="utf-8">Установка уже завершена. <a href="/login">Войти</a>');
}

$_SESSION['install'] = $_SESSION['install'] ?? ['step_done' => -1, 'data' => []];
$st =& $_SESSION['install'];

/* CSRF инсталлятора */
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(24));
}

$step = isset($_GET['step']) ? max(0, min(7, (int)$_GET['step'])) : min($st['step_done'] + 1, 7);
// Нельзя прыгнуть дальше, чем сделано; назад — можно
if ($step > $st['step_done'] + 1) $step = $st['step_done'] + 1;

ilog(($_SERVER['REQUEST_METHOD'] ?? '?') . ' step=' . $step . ' done=' . $st['step_done']
    . ' sess=' . substr(session_id() ?: '-', 0, 8));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['install_csrf'], (string)($_POST['_csrf'] ?? ''))) {
        ilog('POST отклонён: CSRF не совпал (сессия не держится между запросами?)');
        http_response_code(419);
        exit('<meta charset="utf-8">Форма устарела: сервер не удержал сессию между запросами.'
            . ' Обновите страницу и попробуйте снова; если повторится — откройте <a href="/install/?diag=1">/install/?diag=1</a>.');
    }
    /* AJAX-проверки (живой ответ, §7.1 шаг 2/6) */
    $ajax = (string)($_POST['_ajax_action'] ?? '');
    if ($ajax !== '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(install_ajax($ajax, $_POST), JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = install_handle_post($step, $_POST, $st);
    ilog('POST step=' . $step . ' → ' . ($result['ok'] ? 'ok, redirect step=' . ($step + 1)
        : 'ошибки: ' . implode('; ', $result['errors'] ?? [])));
    if ($result['ok']) {
        $st['step_done'] = max($st['step_done'], $step);
        session_write_close(); // сессия на диск ДО редиректа — гонки на медленных FPM
        header('Location: /install/?step=' . ($step + 1));
        exit;
    }
    install_render($step, $st, $result['errors'] ?? []);
    exit;
}

install_render($step, $st);
