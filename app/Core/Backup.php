<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Бэкап инсталляции: ключи подписи + конфиг + дамп БД одним ZIP.
 * Потеря storage/keys означает, что все выданные токены перестают проверяться,
 * а порталы — верить SSO. Поэтому кнопка есть прямо в админке.
 */
final class Backup
{
    /** Собирает архив во временный файл, возвращает путь. Вызывающий отдаёт и удаляет. */
    public static function build(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ssobak');
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Не удалось создать архив');
        }

        // Ключи подписи — самое ценное
        foreach ((array)glob(Keys::dir() . '/*.pem') as $f) {
            $zip->addFile($f, 'keys/' . basename($f));
        }
        foreach ((array)glob(Keys::dir() . '/archive/*.pem') as $f) {
            $zip->addFile($f, 'keys/archive/' . basename($f));
        }

        // Конфиг (в нём APP_KEY — без него не расшифровать TOTP-секреты)
        $config = SSO_ROOT . '/config/config.php';
        if (is_file($config)) $zip->addFile($config, 'config/config.php');

        // Дамп БД
        $zip->addFromString('database.sql', self::dump());

        // Аватары и брендинг — не критично, но приятно не потерять
        foreach ((array)glob(SSO_ROOT . '/storage/avatars/*/*.webp') as $f) {
            $zip->addFile($f, 'avatars/' . basename(dirname($f)) . '/' . basename($f));
        }
        foreach ((array)glob(SSO_ROOT . '/storage/avatars/branding/*') as $f) {
            $zip->addFile($f, 'branding/' . basename($f));
        }

        $zip->addFromString('README.txt', self::readme());
        $zip->close();
        return $path;
    }

    /** SQL-дамп: структура + данные, портируемый между MySQL и восстановлением вручную. */
    private static function dump(): string
    {
        $prefix = (string)config('db.prefix', 'sso_');
        $driver = DB::driver();
        $out = "-- SSO · дамп базы от " . gmdate('c') . " UTC\n"
            . "-- Восстановление: mysql -u USER -p DBNAME < database.sql\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

        /* SHOW TABLES LIKE не принимает плейсхолдер: MySQL и MariaDB отвечают
           «syntax error near '?'». Обычный SELECT принимает, а SHOW — нет, и
           на sqlite это не проявлялось вовсе, поэтому бэкап падал только на
           боевой базе. Подставляем через quote(): шаблон приходит из конфига,
           но экранировать его — дешевле, чем однажды обнаружить, что оттуда
           приехала кавычка. */
        $tables = $driver === 'sqlite'
            ? array_column(DB::all("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE ?", [$prefix . '%']), 'name')
            : array_map(static fn($r) => reset($r),
                DB::all('SHOW TABLES LIKE ' . DB::pdo()->quote($prefix . '%')));

        foreach ($tables as $table) {
            $out .= "\n-- Таблица $table\n";
            /* Схему кладём при ЛЮБОМ движке. На sqlite её не клали вовсе, и
               «бэкап» получался из одних INSERT-ов: восстановить его на чистую
               базу было нельзя — 493 отказа «no such table» из 493 попыток.
               Файл при этом выглядел настоящим: архив, ключи, конфиг, дамп на
               158 КБ. Бэкап, который не разворачивается, хуже отсутствующего:
               на него рассчитывают. */
            if ($driver === 'sqlite') {
                $ddl = (string)DB::val(
                    "SELECT sql FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
                if ($ddl !== '') $out .= "DROP TABLE IF EXISTS `$table`;\n" . $ddl . ";\n";
                /* Индексы живут отдельными строками того же каталога. Без них
                   база восстановится, но станет медленной и потеряет UNIQUE —
                   то есть перестанет защищать от дублей. */
                foreach (DB::all("SELECT sql FROM sqlite_master
                                   WHERE type='index' AND tbl_name = ? AND sql IS NOT NULL", [$table]) as $idx) {
                    $out .= (string)$idx['sql'] . ";\n";
                }
            } else {
                $create = DB::row("SHOW CREATE TABLE `$table`");
                $out .= "DROP TABLE IF EXISTS `$table`;\n" . ($create['Create Table'] ?? '') . ";\n";
            }
            $rows = DB::all("SELECT * FROM `$table`");
            foreach ($rows as $row) {
                $cols = array_map(fn($c) => "`$c`", array_keys($row));
                $vals = array_map(fn($v) => $v === null ? 'NULL' : DB::pdo()->quote((string)$v), array_values($row));
                $out .= "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n";
            }
        }
        return $out . "\nSET FOREIGN_KEY_CHECKS = 1;\n";
    }

    private static function readme(): string
    {
        return "Бэкап " . Setting::appName() . " от " . gmdate('Y-m-d H:i') . " UTC\n"
            . str_repeat('=', 50) . "\n\n"
            . "ВНИМАНИЕ: архив содержит приватные ключи подписи и APP_KEY.\n"
            . "Кто получит этот файл — сможет выпускать токены от имени вашего SSO\n"
            . "и расшифровать TOTP-секреты. Храните как пароль от всего.\n\n"
            . "Содержимое:\n"
            . "  keys/          приватные и публичные ключи RS256 (kid = имя файла)\n"
            . "  config/        config.php с APP_KEY, доступами к БД и cron-токеном\n"
            . "  database.sql   полный дамп базы\n"
            . "  avatars/       аватары пользователей\n"
            . "  branding/      логотип и favicon инсталляции\n\n"
            . "Восстановление:\n"
            . "  1. Разверните код той же версии (" . SSO_VERSION . ").\n"
            . "  2. Восстановите базу: mysql -u USER -p DBNAME < database.sql\n"
            . "  3. Верните keys/ в storage/keys/ (права 0600) и config/config.php.\n"
            . "  4. Создайте config/installed.lock (пустой файл).\n"
            . "  5. Проверьте /admin/health — все пункты должны быть зелёными.\n";
    }
}
