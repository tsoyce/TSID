<?php
/**
 * Настройки SSO — обязательный экран портала на {{APP_NAME}} (v4.63).
 *
 * Три блока, как у каждого портала: мост (секрет клиента), перенос людей (ключ
 * переноса) и проверка связи. До 4.63 ввести ключ после установки было негде:
 * секрет меняли в {{APP_NAME}} — и шли на сервер править файл руками, ключ
 * переноса выдавали — и вписывали его туда же. А проверка связи лежала в
 * selftest.php, открытом всем, с просьбой удалить его после отладки.
 *
 * Кого пускает — решает sso_is_admin() из hooks.php: по умолчанию
 * администратор этого портала в {{APP_NAME}} (уровень 70+) или владелец
 * установки.
 *
 * Вход сломан — экрана не видно, а нужен он как раз тогда. То же с сервера:
 *   php sso/admin.php check        проверка связи
 *   php sso/admin.php secret       ввести секрет клиента (с клавиатуры, не аргументом)
 *   php sso/admin.php users-key    ввести ключ переноса
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/checks.php';
if (is_file(__DIR__ . '/hooks.php')) require_once __DIR__ . '/hooks.php';

const SSO_STATE_LABEL = ['ok' => 'есть', 'warn' => 'не всё', 'fail' => 'нет'];

/**
 * Сохранить секрет клиента — только если его принял издатель.
 *
 * @return array{ok: bool, message: string}
 */
function sso_admin_save_secret(string $value): array
{
    $value = trim($value);
    if ($value === '') return ['ok' => false, 'message' => 'Пусто — ничего не сохранено.'];
    if (str_starts_with($value, 'pusec_')) {
        return ['ok' => false, 'message' => 'Это ключ переноса людей, а не секрет клиента — ему своё поле.'];
    }
    if (preg_match('~\s~', $value) || strlen($value) < 32) {
        return ['ok' => false, 'message' => 'Не похоже на секрет клиента: он длинный и без пробелов. Скопируйте его из карточки портала целиком.'];
    }
    [$envValue, $envVar] = sso_env('CLIENT_SECRET');
    if ($envValue !== '') {
        return ['ok' => false, 'message' => 'Секрет задан переменной окружения ' . $envVar . ' — меняйте его там: файл её не перекроет.'];
    }
    [$accepted, $why] = sso_probe_secret($value);
    if ($accepted === false) return ['ok' => false, 'message' => 'Не сохранено: ' . $why . '. Вход работает по-прежнему.'];
    $saved = sso_save_secrets(['client_secret' => $value]);
    if (!$saved['ok']) return ['ok' => false, 'message' => 'Не сохранено: ' . $saved['error'] . '.'];
    return ['ok' => true, 'message' => 'Секрет сохранён в ' . $saved['path'] . ' — ' . $why . '.'];
}

/**
 * Сохранить ключ переноса людей.
 *
 * @return array{ok: bool, message: string}
 */
function sso_admin_save_users_key(string $value): array
{
    $value = trim($value);
    if ($value === '') return ['ok' => false, 'message' => 'Пусто — ничего не сохранено.'];
    if (!str_starts_with($value, 'pusec_') || preg_match('~\s~', $value)) {
        return ['ok' => false, 'message' => 'Не похоже на ключ переноса: он начинается с pusec_. Скопируйте его из карточки портала целиком.'];
    }
    [$envValue, $envVar] = sso_env('USERS_SECRET');
    if ($envValue !== '') {
        return ['ok' => false, 'message' => 'Ключ задан переменной окружения ' . $envVar . ' — меняйте его там.'];
    }
    $saved = sso_save_secrets(['users_secret' => $value]);
    if (!$saved['ok']) return ['ok' => false, 'message' => 'Не сохранено: ' . $saved['error'] . '.'];
    return ['ok' => true, 'message' => 'Ключ переноса сохранён в ' . $saved['path'] . '.'];
}

/** Кого пускать: sso_is_admin() из hooks.php, а без него — то же правило по умолчанию. */
function sso_admin_allowed(?array $user): bool
{
    if (function_exists('sso_is_admin')) return (bool)sso_is_admin($user);
    if ($user === null) return false;
    $owner = (string)(sso_secrets()['owner_sub'] ?? '');
    return (int)($user['access_level'] ?? 0) >= 70
        || ($owner !== '' && hash_equals($owner, (string)($user['sub'] ?? '')));
}

/* ---------- Консоль ---------- */
if (PHP_SAPI === 'cli') {
    $cmd = (string)($argv[1] ?? 'check');
    if ($cmd === 'secret' || $cmd === 'users-key') {
        fwrite(STDOUT, $cmd === 'secret' ? 'Секрет клиента из карточки портала: ' : 'Ключ переноса из карточки портала: ');
        /* Ввод не эхом: секрет не должен остаться ни в истории команд, ни на экране. */
        $tty = DIRECTORY_SEPARATOR === '/' && function_exists('stream_isatty') && stream_isatty(STDIN);
        if ($tty) @shell_exec('stty -echo');
        $value = (string)fgets(STDIN);
        if ($tty) {
            @shell_exec('stty echo');
            fwrite(STDOUT, "\n");
        }
        $res = $cmd === 'secret' ? sso_admin_save_secret($value) : sso_admin_save_users_key($value);
        fwrite(STDOUT, ($res['ok'] ? '✓ ' : '✗ ') . $res['message'] . "\n\n");
        if (!$res['ok']) exit(1);
    } elseif ($cmd !== 'check') {
        fwrite(STDERR, "Команды: check | secret | users-key\n");
        exit(2);
    }
    $fails = 0;
    foreach (sso_checks() as [$what, $state, $detail]) {
        if ($state === 'fail') $fails++;
        fwrite(STDOUT, str_pad(SSO_STATE_LABEL[$state] ?? $state, 8) . $what . ($detail !== '' ? ' — ' . $detail : '') . "\n");
    }
    exit($fails > 0 ? 1 : 0);
}

/* ---------- Экран ---------- */
sso_session_start();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$me = is_array($_SESSION['sso_user'] ?? null) ? $_SESSION['sso_user'] : null;
$e = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$page = static function (string $body) use ($e): never {
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Настройки SSO · {{CLIENT_NAME}}</title><link rel="stylesheet" href="sso-button.css"><style>'
        . 'body{font:14px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;max-width:980px;margin:32px auto;padding:0 20px;color:#1c1917}'
        . 'h1{font-size:22px;margin:0 0 4px}.sub{color:#78716c;margin:0 0 20px}'
        . '.card{border:1px solid #e7e5e4;border-radius:12px;padding:18px 20px;margin:0 0 16px}'
        . '.card h2{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#57534e;margin:0 0 4px}'
        . '.hint{color:#78716c;font-size:13px;margin:4px 0 12px}'
        . 'dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 18px;margin:0 0 14px}dt{color:#78716c}dd{margin:0;font-family:ui-monospace,Consolas,monospace;font-size:13px;word-break:break-all}'
        . 'form.inline{display:flex;gap:8px;flex-wrap:wrap}form.inline input{flex:1;min-width:240px;padding:8px 10px;border:1px solid #d6d3d1;border-radius:8px;font:inherit}'
        . '.flash{border-radius:10px;padding:10px 14px;margin:0 0 16px}.flash.ok{background:#f0fdf4;color:#166534}.flash.fail{background:#fef2f2;color:#991b1b}'
        . '.tw{overflow-x:auto}table{width:100%;border-collapse:collapse}th{text-align:left;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#78716c;padding:8px 10px;border-bottom:1px solid #e7e5e4}'
        . 'td{padding:9px 10px;border-bottom:1px solid #f0efee;vertical-align:top}td.d{font-family:ui-monospace,Consolas,monospace;font-size:12.5px;color:#57534e;word-break:break-word}'
        . '.st{display:inline-block;border-radius:999px;padding:1px 9px;font-size:12px;white-space:nowrap}'
        . '.st.ok{background:#dcfce7;color:#166534}.st.warn{background:#fef3c7;color:#92400e}.st.fail{background:#fee2e2;color:#991b1b}'
        . '.head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}'
        . '</style></head><body>' . $body . '</body></html>';
    exit;
};

if (!sso_admin_allowed($me)) {
    http_response_code($me === null ? 401 : 403);
    $page('<h1>Настройки SSO</h1><p class="sub">{{CLIENT_NAME}}</p><div class="card">'
        . ($me === null
            ? '<p>Экран только для администратора портала.</p>'
              . '<p><a class="sso-btn" href="login.php?back=' . $e(rawurlencode('/sso/admin.php')) . '" data-turbo="false" hx-boost="false">'
              . '<span class="sso-btn-mark">{{MARK}}</span> Войти через {{APP_NAME}}</a></p>'
            : '<p>Вы вошли как ' . $e($me['name'] ?? $me['email'] ?? '') . ', но администратором этого портала не являетесь:'
              . ' нужен уровень 70 в {{APP_NAME}} или своя проверка в <code>sso_is_admin()</code> из hooks.php.</p>')
        . '<p class="hint">Вход не работает? То же самое делается с сервера: <code>php sso/admin.php check</code>.</p></div>');
}

if (empty($_SESSION['sso_admin_csrf'])) $_SESSION['sso_admin_csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['sso_admin_csrf'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['_csrf'] ?? ''))) {
        $res = ['ok' => false, 'message' => 'Форма устарела — откройте экран заново.'];
    } else {
        $res = match ((string)($_POST['action'] ?? '')) {
            'secret' => sso_admin_save_secret((string)($_POST['value'] ?? '')),
            'users_key' => sso_admin_save_users_key((string)($_POST['value'] ?? '')),
            default => ['ok' => false, 'message' => 'Непонятное действие.'],
        };
    }
    $_SESSION['sso_admin_flash'] = [$res['ok'] ? 'ok' : 'fail', $res['message']];
    header('Location: admin.php?check=1', true, 303);
    exit;
}

$flash = $_SESSION['sso_admin_flash'] ?? null;
unset($_SESSION['sso_admin_flash']);
$cfg = sso_config(true);
$has = static fn($v): bool => is_string($v) && $v !== '' && !str_contains($v, '{{');
$secret = (string)($cfg['client_secret'] ?? '');
$usersKey = (string)($cfg['users_secret'] ?? '');
$rows = isset($_GET['check']) ? sso_checks() : null;

$form = static function (string $action, string $label, string $placeholder) use ($e, $csrf): string {
    return '<form class="inline" method="post" action="admin.php" autocomplete="off">'
        . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
        . '<input type="hidden" name="action" value="' . $e($action) . '">'
        . '<input type="password" name="value" placeholder="' . $e($placeholder) . '" aria-label="' . $e($label) . '" autocomplete="new-password">'
        . '<button class="sso-btn sso-btn-s" type="submit">' . $e($label) . '</button></form>';
};

$html = '<h1>Настройки SSO</h1><p class="sub">{{CLIENT_NAME}} · вы: ' . $e($me['name'] ?? $me['email'] ?? '')
    . ' · уровень ' . (int)($me['access_level'] ?? 0) . '</p>';
if (is_array($flash)) $html .= '<div class="flash ' . $e($flash[0]) . '" role="status">' . $e($flash[1]) . '</div>';

$html .= '<div class="card"><h2>Мост с {{APP_NAME}}</h2>'
    . '<p class="hint">Реквизиты приходят из карточки портала на {{APP_NAME}}. Секрет перед сохранением проверяет сам {{APP_NAME}}: не тот ключ не сохранится, и вход не сломается.</p><dl>'
    . '<dt>Издатель</dt><dd>' . $e($cfg['issuer'] ?? '') . '</dd>'
    . '<dt>client_id</dt><dd>' . $e($cfg['client_id'] ?? '') . '</dd>'
    . '<dt>Адрес возврата</dt><dd>' . $e($cfg['redirect_uri'] ?? '') . '</dd>'
    . '<dt>После выхода</dt><dd>' . $e($cfg['post_logout'] ?? '') . '</dd>'
    . '<dt>Области</dt><dd>' . $e($cfg['scopes'] ?? '') . '</dd>'
    . '<dt>Секрет клиента</dt><dd>' . ($has($secret) ? 'есть, длина ' . strlen($secret) . ' · ' . $e(sso_secret_source('client_secret')) : 'нет') . '</dd>'
    . '</dl>' . $form('secret', 'Проверить и сохранить секрет', 'новый секрет клиента') . '</div>';

$html .= '<div class="card"><h2>Перенос людей</h2>'
    . '<p class="hint">Ключ выдаётся в карточке портала: «Забрать людей» → «Выдать ключ переноса». Он отдельный от секрета клиента: смена одного не ломает другое.</p><dl>'
    . '<dt>Ключ переноса</dt><dd>' . ($has($usersKey) ? 'есть, длина ' . strlen($usersKey) . ' · ' . $e(sso_secret_source('users_secret')) : 'не выдан') . '</dd>'
    . '<dt>Адрес выгрузки</dt><dd>/sso/users.php</dd>'
    . '</dl>' . $form('users_key', 'Сохранить ключ переноса', 'pusec_…') . '</div>';

$html .= '<div class="card"><div class="head"><div><h2>Проверка связи</h2>'
    . '<p class="hint">Ходит к издателю и к самому себе. Ничего не сохраняет и никого не впускает.</p></div>'
    . '<a class="sso-btn sso-btn-s sso-btn-outline" href="admin.php?check=1">Проверить</a></div>';
if ($rows !== null) {
    $html .= '<div class="tw"><table><thead><tr><th>Состояние</th><th>Что проверяли</th><th>Подробности</th></tr></thead><tbody>';
    foreach ($rows as [$what, $state, $detail]) {
        $html .= '<tr data-check="' . $e($what) . '" data-state="' . $e($state) . '">'
            . '<td><span class="st ' . $e($state) . '">' . $e(SSO_STATE_LABEL[$state] ?? $state) . '</span></td>'
            . '<td>' . $e($what) . '</td><td class="d">' . $e($detail) . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
}
$html .= '</div><p class="hint">Если экран недоступен, то же самое делается с сервера: '
    . '<code>php sso/admin.php check</code>, <code>php sso/admin.php secret</code>, <code>php sso/admin.php users-key</code>.</p>';

$page($html);
