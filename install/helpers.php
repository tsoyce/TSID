<?php
/** Хелперы инсталлятора: окружение, БД, конфиг. Без зависимостей от App-конфига. */

declare(strict_types=1);

/** Проверка окружения (§7.1 шаг 1): [label, status(ok|warn|fail), hint]. */
function install_env_checks(): array
{
    $checks = [];
    $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
    $checks[] = ['PHP ≥ 8.2 (у вас ' . PHP_VERSION . ')', $phpOk ? 'ok' : 'fail',
        $phpOk ? '' : 'Обновите PHP до 8.2+ (цель — 8.3).'];

    foreach (['openssl', 'mbstring', 'curl', 'json', 'gd'] as $ext) {
        $has = extension_loaded($ext);
        $checks[] = ["Расширение $ext", $has ? 'ok' : 'fail',
            $has ? '' : "Включите extension=$ext в php.ini и перезапустите PHP-FPM."];
    }
    $mysql = extension_loaded('pdo_mysql');
    $sqlite = extension_loaded('pdo_sqlite');
    $checks[] = ['Драйвер БД: pdo_mysql' . ($sqlite && !$mysql ? ' (найден только pdo_sqlite — режим разработки)' : ''),
        $mysql ? 'ok' : ($sqlite ? 'warn' : 'fail'),
        $mysql ? '' : ($sqlite ? 'Для прода нужен pdo_mysql; sqlite годится только для локальной разработки.'
            : 'Включите extension=pdo_mysql в php.ini.')];
    $checks[] = ['Расширение sodium (желательно)', extension_loaded('sodium') ? 'ok' : 'warn',
        extension_loaded('sodium') ? '' : 'Без sodium шифрование секретов пойдёт через openssl — это допустимо.'];
    $webp = function_exists('imagewebp');
    $checks[] = ['GD с поддержкой webp (аватары)', $webp ? 'ok' : 'warn',
        $webp ? '' : 'GD собран без webp — загрузка аватаров и иконок работать не будет, остальное не пострадает.'];

    foreach (['storage', 'config'] as $dir) {
        $path = SSO_ROOT . '/' . $dir;
        $ok = is_dir($path) && is_writable($path);
        $checks[] = ["Каталог $dir/ доступен на запись", $ok ? 'ok' : 'fail',
            $ok ? '' : "chmod/chown каталога $path под пользователя PHP."];
    }
    $https = is_https();
    $checks[] = ['HTTPS', $https ? 'ok' : 'warn',
        $https ? '' : 'OAuth без TLS — не OAuth. Если вы за обратным прокси с TLS — это предупреждение можно игнорировать.'];
    return $checks;
}

function install_env_blocked(): bool
{
    foreach (install_env_checks() as $c) {
        if ($c[1] === 'fail') return true;
    }
    return false;
}

/** PDO по данным из массива (сессия или POST). */
function install_pdo(array $db): PDO
{
    if (($db['driver'] ?? 'mysql') === 'sqlite') {
        $file = $db['name'] ?: (SSO_ROOT . '/storage/dev.sqlite');
        $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }
    /* Строка одна на весь проект: разойдись она между инсталлятором и
       рабочим подключением — «база доступна» на установке и отказ в работе. */
    $dsn = \App\Core\Dsn::build(['driver' => 'mysql'] + $db);
    return new PDO($dsn, (string)$db['user'], (string)$db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/** Человеческая подсказка по ошибке подключения (§7.1 шаг 2). */
function install_db_hint(\Throwable $e): string
{
    $m = $e->getMessage();
    if (str_contains($m, 'Access denied')) return 'Сервер БД отвечает, но логин/пароль не подходят. Проверьте пользователя и его права на эту базу.';
    if (str_contains($m, 'Unknown database')) return 'Подключение есть, но такой базы нет. Создайте её: CREATE DATABASE … CHARACTER SET utf8mb4.';
    if (str_contains($m, 'getaddrinfo') || str_contains($m, 'Connection refused')) return 'Хост не отвечает. Проверьте адрес и порт; для локальной БД обычно localhost:3306.';
    return $m;
}

/** Уже есть таблицы с этим префиксом? (§7.1 шаг 3) */
function install_tables_exist(PDO $pdo, string $prefix, string $driver): bool
{
    try {
        if ($driver === 'sqlite') {
            $st = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE " . $pdo->quote($prefix . '%'));
        } else {
            $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($prefix . '%'));
        }
        return $st->fetchColumn() !== false;
    } catch (\Throwable) {
        return false;
    }
}

/** Применить миграции. Возвращает лог строк. */
function install_run_migrations(PDO $pdo, string $prefix, string $driver): array
{
    $log = [];
    $files = glob(SSO_ROOT . '/migrations/*.sql');
    sort($files);
    $applied = [];
    try {
        $st = $pdo->query("SELECT version FROM {$prefix}migrations");
        $applied = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable) { /* таблицы ещё нет */ }
    /* PHP-шаги обязаны выполниться и здесь, а не только при доустановке из
       админки. Пока такие шаги лишь досыпали данные, разница не читалась: на
       пустой базе досыпать нечего. Шаг, меняющий схему, вставал молча — версия
       записана, колонка прежняя, а узнавалось об этом на первой живой вставке.

       Соединение отдаём то самое, что открыл вызывающий: при установке своего
       у приложения ещё нет, конфиг не записан. Возврат в finally обязателен —
       перенос базы зовёт эту же функцию для ПРИЁМНИКА из живого приложения, и
       без возврата всё дальнейшее ушло бы в чужую базу. */
    \App\Core\DB::adopt($pdo, $driver, $prefix);
    try {
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                $log[] = "· $version — уже применена, пропуск";
                continue;
            }
            $raw = (string)file_get_contents($file);
            $sql = preg_replace('~\{([a-z_]+)\}~', $prefix . '$1', $raw);
            if ($driver === 'sqlite') {
                $sql = \App\Core\SqliteCompat::translate($sql);
            }
            foreach (\App\Core\SqliteCompat::splitStatements($sql) as $stmt) {
                if (trim($stmt) !== '') $pdo->exec($stmt);
            }
            \App\Core\Migrations::runPhpStep($version);
            $pdo->prepare("INSERT INTO {$prefix}migrations (version, applied_at) VALUES (?, ?)")
                ->execute([$version, gmdate('Y-m-d H:i:s')]);
            $log[] = "✓ $version применена";
        }
    } finally {
        \App\Core\DB::release();
    }
    return $log;
}

/** Снести таблицы с префиксом (вариант «стереть и заново»). */
function install_drop_tables(PDO $pdo, string $prefix, string $driver): void
{
    if ($driver === 'sqlite') {
        $names = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE " . $pdo->quote($prefix . '%'))->fetchAll(PDO::FETCH_COLUMN);
        foreach ($names as $n) $pdo->exec("DROP TABLE IF EXISTS \"$n\"");
        return;
    }
    $names = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($prefix . '%'))->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($names as $n) $pdo->exec("DROP TABLE IF EXISTS `$n`");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * Генерация config/config.php из данных установки (Приложение B).
 *
 * С v4.2 сам генератор живёт в `App\Core\ConfigFile`: конфиг переписывается не
 * только при установке (смена домена, смена базы), а каталог `/install` после
 * установки удаляют. Двух форматов конфига в проекте быть не должно.
 */
function install_write_config(array $d): void
{
    \App\Core\ConfigFile::write($d);
}


function install_esc(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
