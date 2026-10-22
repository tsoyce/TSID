<?php
/**
 * /install/?diag=1 — самодиагностика окружения ДО установки.
 * Проверяет три механики, без которых мастер не пройдёт:
 * сессии (держатся ли между запросами), POST (доходит ли), редиректы.
 */

declare(strict_types=1);

if (!defined('SSO_ROOT')) exit('Прямой запуск запрещён');

$e = 'install_esc';

/* Тест редиректа: ?diag=go → Location на ?diag=1&back=1 */
if (($_GET['diag'] ?? '') === 'go') {
    header('Location: /install/?diag=1&back=1');
    exit;
}

if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(24));
}

/* Счётчик визитов в сессии: если при обновлении страницы не растёт — сессии не держатся */
$_SESSION['diag_hits'] = (int)($_SESSION['diag_hits'] ?? 0) + 1;
$hits = $_SESSION['diag_hits'];

/* POST-эхо */
$postResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenOk = hash_equals($_SESSION['install_csrf'] ?? '', (string)($_POST['_csrf'] ?? ''));
    $postResult = [
        'received' => true,
        'csrf' => $tokenOk,
        'size' => (int)($_SERVER['CONTENT_LENGTH'] ?? 0),
    ];
}

$sessionPath = session_save_path() ?: sys_get_temp_dir();
$rows = [
    ['PHP', PHP_VERSION . ' · ' . PHP_SAPI, version_compare(PHP_VERSION, '8.2', '>=') ? 'ok' : 'warn'],
    ['Веб-сервер', (string)($_SERVER['SERVER_SOFTWARE'] ?? '?'), 'ok'],
    ['REQUEST_METHOD этого запроса', (string)$_SERVER['REQUEST_METHOD'], 'ok'],
    ['Сессия: id', substr(session_id() ?: '—', 0, 12) . '…', session_id() ? 'ok' : 'fail'],
    ['Сессия: save_path', $sessionPath . (is_writable($sessionPath) ? ' (писабелен)' : ' (НЕ писабелен!)'),
        is_writable($sessionPath) ? 'ok' : 'fail'],
    ['Сессия: счётчик визитов', (string)$hits . ' — обновите страницу: число должно расти',
        $hits > 1 ? 'ok' : 'warn'],
    ['storage/ писабелен', is_writable(SSO_ROOT . '/storage') ? 'да' : 'НЕТ',
        is_writable(SSO_ROOT . '/storage') ? 'ok' : 'fail'],
    ['config/ писабелен', is_writable(SSO_ROOT . '/config') ? 'да' : 'НЕТ',
        is_writable(SSO_ROOT . '/config') ? 'ok' : 'fail'],
    ['Редирект-тест', isset($_GET['back']) ? 'работает ✓' : 'нажмите кнопку ниже',
        isset($_GET['back']) ? 'ok' : 'warn'],
];
if ($postResult !== null) {
    $rows[] = ['POST-тест', 'POST дошёл (' . $postResult['size'] . ' байт), CSRF '
        . ($postResult['csrf'] ? 'совпал ✓' : 'НЕ совпал — сессия не удержалась между запросами'),
        $postResult['csrf'] ? 'ok' : 'fail'];
}

$logFile = SSO_ROOT . '/storage/logs/install.log';
$logTail = is_file($logFile)
    ? implode("\n", array_slice(file($logFile, FILE_IGNORE_NEW_LINES) ?: [], -15))
    : '(лога ещё нет)';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Диагностика установки — SSO</title>
<link rel="stylesheet" href="<?= esc(asset('assets/css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= esc(asset('assets/css/app.css')) ?>">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card" style="max-width:640px">
    <div class="auth-logo"><span class="logo-mark">●</span> Диагностика установки</div>

    <?php foreach ($rows as [$label, $value, $status]): ?>
    <div class="health-row" style="padding:7px 0">
      <span class="health-icon"><?= $status === 'ok' ? '🟢' : ($status === 'warn' ? '🟡' : '🔴') ?></span>
      <div><div class="cell-main"><?= $e($label) ?></div>
      <div class="hint" style="overflow-wrap:anywhere"><?= $e($value) ?></div></div>
    </div>
    <?php endforeach; ?>

    <div class="row mt-16">
      <form method="post" action="/install/?diag=1">
        <input type="hidden" name="_csrf" value="<?= $e($_SESSION['install_csrf'] ?? '') ?>">
        <button class="btn btn-primary" type="submit">Проверить POST</button>
      </form>
      <a class="btn btn-secondary" href="/install/?diag=go">Проверить редирект</a>
      <a class="btn btn-ghost" href="/install/">← К установке</a>
    </div>

    <h3 class="mt-24">Хвост install.log</h3>
    <div class="code-block" style="white-space:pre-wrap;max-height:220px;overflow:auto"><?= $e($logTail) ?></div>
    <p class="hint mt-8">Полный лог: storage/logs/install.log. Если кнопка «Начать установку» молчит,
    а здесь всё зелёное — пришлите содержимое лога и последние строки error_log веб-сервера.</p>
  </div>
</div>
</body>
</html>
