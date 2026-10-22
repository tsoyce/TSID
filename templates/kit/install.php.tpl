<?php
/**
 * Установщик моста с {{APP_NAME}}.
 *
 * Открывается по одноразовой ссылке из карточки портала. Дальше сам:
 *  1. предъявляет билет {{APP_NAME}} и забирает реквизиты — client_secret,
 *     ключ переноса, адреса, имя владельца;
 *  2. кладёт секреты в файл ВНЕ веб-корня и проверяет, что он читается;
 *  3. прогоняет проверку связи — ту же, что на экране настроек (admin.php);
 *  4. отдаёт кнопку «Войти как владелец» — портал узнает владельца по роли,
 *     выданной в {{APP_NAME}}, и свяжет с ним существующую учётную запись.
 *
 * Без билета файл не делает ничего опасного: реквизиты выдаёт {{APP_NAME}}, и
 * только предъявителю билета. Но после установки его лучше удалить: дальше
 * секрет и ключ переноса меняются на экране настроек, sso/admin.php.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/checks.php';

$cfg = require __DIR__ . '/config.php';
$issuer = rtrim((string)($cfg['issuer'] ?? ''), '/');
$ticket = trim((string)($_GET['ticket'] ?? $_POST['ticket'] ?? ''));
/* Вставили ссылку целиком — достаём билет из неё. */
if (preg_match('~[?&]ticket=([^&\s]+)~', $ticket, $tm)) $ticket = rawurldecode($tm[1]);
$step = [];
$done = false;
$owner = null;

/* ---------- Шаг 1: обмен билета ---------- */
if ($ticket !== '') {
    if ($issuer === '' || str_contains($issuer, '{{')) {
        $step[] = ['fail', 'В config.php не задан адрес ' . '{{APP_NAME}}' . ' — набор скачан не из карточки портала?'];
    } else {
        $res = sso_http('POST', $issuer . '/portal/bootstrap', ['ticket' => $ticket]);
        $data = json_decode($res['body'], true);
        if ($res['code'] !== 200 || !is_array($data) || empty($data['ok'])) {
            $why = is_array($data) && isset($data['error']) ? (string)$data['error']
                : ('сервер ответил ' . $res['code'] . ($res['error'] !== '' ? ' (' . $res['error'] . ')' : ''));
            $step[] = ['fail', 'Реквизиты забрать не удалось: ' . $why];
        } else {
            $owner = $data['owner'] ?? null;
            $write = sso_save_secrets(array_filter([
                'client_secret' => (string)($data['client_secret'] ?? ''),
                'users_secret' => (string)($data['users_secret'] ?? ''),
                /* Владельца записываем рядом с секретами: по нему callback.php
                   узнаёт установщика и связывает его с уже существующей у вас
                   учётной записью, вместо того чтобы завести вторую. */
                'owner_sub' => (string)($owner['sub'] ?? ''),
                'owner_login' => (string)($owner['login'] ?? ''),
                'owner_email' => (string)($owner['email'] ?? ''),
            ], static fn($v) => $v !== ''));

            if (!$write['ok']) {
                $step[] = ['fail', 'Реквизиты получены, но сохранить не вышло: ' . $write['error']];
            } else {
                $step[] = ['ok', 'Реквизиты получены и записаны: ' . $write['path']];
                $done = true;
            }
        }
    }
}

/* ---------- Шаг 2: проверка связи — та же, что на экране настроек ---------- */
$checks = $issuer !== '' && !str_contains($issuer, '{{') ? sso_checks() : [];
$marks = ['ok' => '✓', 'warn' => '!', 'fail' => '✗'];
?>
<!DOCTYPE html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Установка моста с {{APP_NAME}}</title>
<link rel="stylesheet" href="sso-button.css">
<style>
  body { font: 15px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
         max-width: 760px; margin: 40px auto; padding: 0 20px; color: #111; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  .sub { color: #666; margin: 0 0 24px; }
  .row { display: flex; gap: 10px; align-items: baseline; padding: 7px 0; border-bottom: 1px solid #eee; }
  .row b { flex: none; width: 22px; }
  .ok { color: #15803d; } .fail { color: #b91c1c; } .warn { color: #92400e; }
  .d { margin-left: auto; color: #777; font-size: 13px; text-align: right; word-break: break-word; }
  .note { background: #f6f7f9; border-radius: 10px; padding: 14px 16px; margin: 20px 0; }
  code { background: #f2f3f5; padding: 1px 5px; border-radius: 4px; }
  form { margin: 18px 0 0; }
  input[type=text] { width: 100%; padding: 9px 11px; border: 1px solid #ccd; border-radius: 8px; font: inherit; }
</style>
</head><body>

<h1>Установка моста с {{APP_NAME}}</h1>
<p class="sub">{{CLIENT_NAME}} · {{CLIENT_URL}}</p>

<?php foreach ($step as [$kind, $text]): ?>
  <p class="<?= $kind === 'ok' ? 'ok' : 'fail' ?>"><b><?= $kind === 'ok' ? '✓' : '✗' ?></b> <?= htmlspecialchars($text) ?></p>
<?php endforeach; ?>

<?php if ($ticket === '' && !$done): ?>
  <div class="note">
    <p><b>Как это работает.</b> В карточке портала на {{APP_NAME}} нажмите
    «Ссылка установки» и откройте её здесь. Реквизиты приедут сами — вписывать
    в <code>config.php</code> ничего не нужно.</p>
    <form method="post">
      <p>Или вставьте ссылку целиком:</p>
      <input type="text" name="ticket" placeholder="https://…/sso/install.php?ticket=…" autofocus>
      <p><button class="sso-btn sso-btn-s" type="submit">Установить</button></p>
    </form>
  </div>
<?php endif; ?>

<?php if ($checks !== []): ?>
  <h2 style="font-size:17px;margin:26px 0 6px">Проверка связи</h2>
  <?php foreach ($checks as [$label, $state, $detail]): ?>
    <div class="row"><b class="<?= $state ?>"><?= $marks[$state] ?? '·' ?></b>
      <span><?= htmlspecialchars($label) ?></span>
      <span class="d"><?= htmlspecialchars((string)$detail) ?></span></div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($done && $owner): ?>
  <div class="note">
    <p><b>Владелец установки — <?= htmlspecialchars((string)($owner['name'] ?? $owner['login'] ?? '')) ?></b>
    <?php if (!empty($owner['login'])): ?>(логин <code><?= htmlspecialchars((string)$owner['login']) ?></code>)<?php endif; ?>.</p>
    <p>Войдите — портал узнает вас по роли в {{APP_NAME}} и, если такая учётная
    запись у него уже есть, свяжет её с вашей, а не заведёт вторую.</p>
    <p><a class="sso-btn" href="login.php?back=<?= rawurlencode('/sso/admin.php') ?>"><span class="sso-btn-mark">{{MARK}}</span> Войти как владелец</a></p>
  </div>
<?php endif; ?>

<?php if ($done): ?>
  <div class="note">
    <p><b>Осталось одно.</b> Удалите <code>sso/install.php</code> — установка
    закончена, а лишняя точка входа ни к чему. Дальше секрет и ключ переноса
    меняются на экране настроек: <code>/sso/admin.php</code>.</p>
  </div>
<?php endif; ?>

</body></html>
