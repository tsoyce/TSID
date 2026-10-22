<?php
/** Экраны инсталлятора. Один рендерер + секции шагов. Стили — общие ассеты. */

declare(strict_types=1);

function install_render(int $step, array $st, array $errors = []): void
{
    $e = 'install_esc';
    $csrf = $_SESSION['install_csrf'] ?? '';
    $d = $st['data'] ?? [];
    $titles = ['Добро пожаловать', 'Проверка окружения', 'База данных', 'Миграции', 'Криптография', 'Суперадмин', 'Базовые настройки', 'Готово'];
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#1D4ED8">
<title>Установка SSO · <?= $e($titles[$step]) ?></title>
<link rel="stylesheet" href="<?= esc(asset('assets/css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= esc(asset('assets/fonts/fonts.css')) ?>">
<link rel="stylesheet" href="<?= esc(asset('assets/css/app.css')) ?>">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card" style="max-width:560px">
    <div class="auth-logo"><span class="logo-mark">●</span> Установка SSO <span class="hint">v<?= defined('SSO_VERSION') ? SSO_VERSION : '2.0' ?></span></div>
    <?php if ($step > 0 && $step < 7): ?>
    <?= stepper(['Окружение', 'База', 'Миграции', 'Ключи', 'Админ', 'Настройки'], $step) ?>
    <?php endif; ?>
    <?php foreach ($errors as $err): if ($err === 'exists') continue; ?>
      <div class="secret-plate mb-16"><?= $e($err) ?></div>
    <?php endforeach; ?>
    <?php install_render_step($step, $d, $csrf, $errors); ?>
  </div>
</div>
<script src="<?= esc(asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
<?php
}

function install_render_step(int $step, array $d, string $csrf, array $errors): void
{
    $e = 'install_esc';
    switch ($step) {
        case 0: ?>
<h2 style="text-align:center">Единая учётная запись экосистемы</h2>
<p class="muted" style="text-align:center">Мастер поставит центральный SSO-сервер: проверит окружение,
создаст базу, ключи подписи и аккаунт суперадмина. Обычно это занимает три минуты.</p>
<form method="post" action="/install/?step=0">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <button class="btn btn-primary btn-block" type="submit">Начать установку</button>
</form>
<p class="auth-footer">Архитектура и контракты — в файле ARCHITECTURE.md дистрибутива.</p>
<?php break;

        case 1: $blocked = install_env_blocked(); ?>
<h3>Шаг 1 — проверка окружения</h3>
<div class="stack" style="margin:16px 0">
<?php foreach (install_env_checks() as [$label, $status, $hint]): ?>
  <div class="health-row" style="padding:6px 0">
    <span class="health-icon"><?= $status === 'ok' ? '✅' : ($status === 'warn' ? '⚠️' : '❌') ?></span>
    <div><div><?= $e($label) ?></div>
    <?php if ($hint): ?><div class="hint"><?= $e($hint) ?></div><?php endif; ?></div>
  </div>
<?php endforeach; ?>
</div>
<form method="post" action="/install/?step=1" class="row">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <a class="btn btn-secondary" href="/install/?step=1">Проверить ещё раз</a>
  <button class="btn btn-primary" type="submit" <?= $blocked ? 'disabled' : '' ?> style="flex:1">Далее</button>
</form>
<?php break;

        case 2: $db = $d['db'] ?? []; ?>
<h3>Шаг 2 — база данных</h3>
<form method="post" action="/install/?step=2">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <div class="field"><label>Драйвер</label>
    <select class="select" name="db_driver" id="db_driver">
      <option value="mysql" <?= ($db['driver'] ?? 'mysql') === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB (прод)</option>
      <option value="sqlite" <?= ($db['driver'] ?? '') === 'sqlite' ? 'selected' : '' ?>>SQLite (только разработка)</option>
    </select>
  </div>
  <div class="grid-2">
    <div class="field"><label>Хост</label><input class="input" name="db_host" value="<?= $e($db['host'] ?? 'localhost') ?>"></div>
    <div class="field"><label>Порт</label><input class="input" name="db_port" value="<?= $e($db['port'] ?? 3306) ?>"></div>
  </div>
  <div class="field"><label>Имя базы <span class="hint">(для SQLite — путь к файлу, можно пустым)</span></label>
    <input class="input" name="db_name" value="<?= $e($db['name'] ?? '') ?>"></div>
  <div class="grid-2">
    <div class="field"><label>Пользователь</label><input class="input" name="db_user" value="<?= $e($db['user'] ?? '') ?>" autocomplete="off"></div>
    <div class="field"><label>Пароль</label><input class="input" type="password" name="db_pass" value="<?= $e($db['pass'] ?? '') ?>" autocomplete="new-password"></div>
  </div>
  <div class="field"><label>Префикс таблиц</label><input class="input mono" name="db_prefix" value="<?= $e($db['prefix'] ?? 'sso_') ?>"></div>
  <div class="row mb-16">
    <button class="btn btn-secondary" type="button" data-ajax="db-test-out" data-ajax-action="db_test" data-ajax-enable="db-next">Проверить подключение</button>
    <span id="db-test-out" class="hint">Сначала проверьте подключение.</span>
  </div>
  <button class="btn btn-primary btn-block" id="db-next" type="submit" <?= isset($db['host']) ? '' : 'disabled' ?>>Далее</button>
</form>
<?php break;

        case 3:
            $db = $d['db'];
            $pdo = install_pdo($db);
            $exists = install_tables_exist($pdo, $db['prefix'], $db['driver']);
            $log = $d['migration_log'] ?? null;
            if ($log !== null): ?>
<h3>Шаг 3 — миграции применены</h3>
<div class="code-block" style="max-height:220px;overflow:auto"><?= $e(implode("\n", $log)) ?></div>
<form method="post" action="/install/?step=3" class="mt-16">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="mode" value="skip">
  <a class="btn btn-primary btn-block" href="/install/?step=4">Далее</a>
</form>
<?php elseif ($exists): ?>
<h3>Шаг 3 — обнаружена установка</h3>
<p class="muted">В базе уже есть таблицы с префиксом <code><?= $e($db['prefix']) ?></code>. Что делаем?</p>
<div class="stack">
  <form method="post" action="/install/?step=3">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="mode" value="install">
    <button class="btn btn-primary btn-block" type="submit">Обновить схему (применить недостающие миграции)</button>
  </form>
  <form method="post" action="/install/?step=3" data-confirm="Все данные в таблицах с этим префиксом будут удалены. Точно?">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="mode" value="wipe">
    <button class="btn btn-danger-ghost btn-block" type="submit">Стереть и поставить заново</button>
  </form>
  <a class="btn btn-secondary btn-block" href="/install/?step=2">Отмена — назад к базе</a>
</div>
<?php else: ?>
<h3>Шаг 3 — миграции</h3>
<p class="muted">Схема §4: пользователи, клиенты, сессии, токены, аудит — <?= count(glob(SSO_ROOT . '/migrations/*.sql')) ?> файл(ов) SQL по порядку.</p>
<form method="post" action="/install/?step=3">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="mode" value="install">
  <button class="btn btn-primary btn-block" type="submit">Применить миграции</button>
</form>
<?php endif; break;

        case 4: ?>
<h3>Шаг 4 — криптография</h3>
<?php if (empty($d['kid'])): ?>
<p class="muted">Автоматически: пара RSA-2048 в <code>storage/keys/</code> (права 0600),
APP_KEY для шифрования секретов at rest, токен для cron. Сами ключи на экран не выводятся.</p>
<form method="post" action="/install/?step=4">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <button class="btn btn-primary btn-block" type="submit">Сгенерировать ключи</button>
</form>
<?php else: ?>
<div class="health-row"><span class="health-icon">✅</span><div>Ключи созданы, kid=<code><?= $e($d['kid']) ?></code></div></div>
<div class="health-row"><span class="health-icon">✅</span><div>APP_KEY сгенерирован (32 байта)</div></div>
<a class="btn btn-primary btn-block mt-16" href="/install/?step=5">Далее</a>
<?php endif; break;

        case 5: $a = $d['admin'] ?? []; ?>
<h3>Шаг 5 — суперадмин</h3>
<form method="post" action="/install/?step=5">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <div class="grid-2">
    <div class="field"><label>Фамилия</label><input class="input" name="last_name" value="<?= $e($a['last_name'] ?? '') ?>"></div>
    <div class="field"><label>Имя</label><input class="input" name="first_name" value="<?= $e($a['first_name'] ?? '') ?>"></div>
  </div>
  <div class="field"><label>Email</label><input class="input" type="email" name="email" required value="<?= $e($a['email'] ?? '') ?>"></div>
  <div class="field"><label>Логин <span class="hint">(необязательно)</span></label>
    <input class="input" name="login" value="<?= $e($a['login'] ?? '') ?>" autocomplete="off"></div>
  <div class="field"><label>Пароль <span class="hint">(любой на ваше усмотрение; политику для пользователей настроите в админке)</span></label>
    <div class="input-wrap">
      <input class="input" type="password" name="password" id="sa-pass" required autocomplete="new-password">
      <button class="input-eye" type="button" data-pw-toggle="sa-pass">показать</button>
    </div>
  </div>
  <button class="btn btn-primary btn-block" type="submit">Создать суперадмина</button>
</form>
<?php break;

        case 6:
            $scheme = is_https() ? 'https' : 'http';
            $auto = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'); ?>
<h3>Шаг 6 — базовые настройки</h3>
<form method="post" action="/install/?step=6">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <div class="field"><label>Issuer URL <span class="hint">(канонический адрес SSO)</span></label>
    <input class="input mono" name="issuer" value="<?= $e($d['issuer'] ?? $auto) ?>"></div>
  <div class="field"><label>Название инсталляции</label>
    <input class="input" name="app_name" value="<?= $e($d['app_name'] ?? 'SSO') ?>"></div>
  <hr>
  <h3>Почта</h3>
  <div class="field"><label>Транспорт</label>
    <select class="select" name="mail_driver">
      <option value="smtp">SMTP</option>
      <option value="log">Файл-лог (разработка: письма в storage/logs/mail)</option>
    </select></div>
  <div class="grid-2">
    <div class="field"><label>SMTP-хост</label><input class="input" name="smtp_host"></div>
    <div class="field"><label>Порт</label><input class="input" name="smtp_port" value="587"></div>
  </div>
  <div class="grid-2">
    <div class="field"><label>Шифрование</label>
      <select class="select" name="smtp_enc"><option value="tls">STARTTLS</option><option value="ssl">SSL</option><option value="none">Нет</option></select></div>
    <div class="field"><label>From-адрес</label><input class="input" name="smtp_from" placeholder="no-reply@…"></div>
  </div>
  <div class="grid-2">
    <div class="field"><label>Логин</label><input class="input" name="smtp_user" autocomplete="off"></div>
    <div class="field"><label>Пароль</label><input class="input" type="password" name="smtp_pass" autocomplete="new-password"></div>
  </div>
  <div class="row mb-16">
    <input type="hidden" name="test_email" value="<?= $e($d['admin']['email'] ?? '') ?>">
    <button class="btn btn-secondary" type="button" data-ajax="smtp-out" data-ajax-action="smtp_test">Отправить тестовое письмо</button>
    <span id="smtp-out" class="hint" style="white-space:pre-line">Письмо уйдёт на <?= $e($d['admin']['email'] ?? 'email суперадмина') ?></span>
  </div>
  <label class="checkbox mb-16"><input type="checkbox" name="skip_mail" value="1"> Пропустить, настрою потом (письма лягут в очередь)</label>
  <button class="btn btn-primary btn-block" type="submit">Завершить установку</button>
</form>
<?php break;

        case 7:
            $info = $_SESSION['install_done_info'] ?? [];
            $issuer = $info['issuer'] ?? '';
            $cronToken = $info['cron_token'] ?? '{{CRON_TOKEN}}';
            $root = str_replace('\\', '/', SSO_ROOT); ?>
<h3>Готово 🎉</h3>
<p class="muted">SSO установлен. Осталось три вещи по хозяйству:</p>
<h3 class="mt-16">1. Cron (каждую минуту)</h3>
<div class="code-block">* * * * * /usr/bin/php <?= $e($root) ?>/cron.php >> <?= $e($root) ?>/storage/logs/cron.log 2>&1</div>
<p class="hint">Альтернатива без CLI: <code>* * * * * curl -s "<?= $e($issuer) ?>/cron?token=<?= $e($cronToken) ?>"</code></p>
<h3 class="mt-16">2. Webroot → public/</h3>
<div class="code-block"># Apache: DocumentRoot на public/; в public/.htaccess уже всё есть.
# nginx:
root <?= $e($root) ?>/public;
location / { try_files $uri /index.php$is_args$args; }
location ~ ^/(storage|config)/ { deny all; }</div>
<h3 class="mt-16">3. Безопасность</h3>
<div class="secret-plate">Удалите каталог <code>/install</code> с сервера — «Здоровье» в админке будет напоминать, пока он на месте.
2FA — по желанию: включается в профиле, а обязательность для админов — тумблером в настройках.</div>
<a class="btn btn-primary btn-block mt-16" href="/login">Перейти ко входу</a>
<?php break;
    }
}
