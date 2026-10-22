<?php
/**
 * Старт входа: генерирует state+verifier в сессию и отправляет на SSO.
 * Для sso.js (popup) отвечает JSON {"authorize_url": …}; иначе — редирект.
 *
 * ?back=/путь — куда вернуть после входа (экран настроек просит вернуть на
 * себя). Только путь этого же сайта: иначе это открытый редирект.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/SsoClient.php';
sso_session_start();
$sso = new SsoClient(sso_config());

$back = (string)($_GET['back'] ?? '');
if ($back !== '' && preg_match('~^/(?!/)[^\s\\\\]*$~', $back) === 1) $_SESSION['sso_back'] = $back;

$url = $sso->buildAuthorizeUrl();

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'sso.js') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['authorize_url' => $url]);
    exit;
}
header('Location: ' . $url);
