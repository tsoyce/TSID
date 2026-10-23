<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Миграции схемы: единый источник правды.
 *
 * До v3.4 та же логика жила в трёх местах — install/helpers.php,
 * ToolsController и HealthController, — и расходилась. Хуже того, применить
 * миграцию можно было только со страницы /admin/tools, а она сама падала с 500,
 * если не хватало таблицы из этой же миграции. Обновивший код оказывался в
 * ловушке: чтобы починить, нужна страница, которая сломана именно потому, что
 * не починено.
 *
 * Поэтому здесь:
 *   · pending() — что осталось применить (безопасно даже без таблицы migrations);
 *   · apply()   — применить всё недостающее, вернуть журнал;
 *   · CLI-обёртка в migrate.php — чтобы выбраться по SSH вообще без браузера.
 */
final class Migrations
{
    /** @return list<string> версии всех файлов миграций по порядку */
    public static function available(): array
    {
        $files = (array)glob(SSO_ROOT . '/migrations/*.sql');
        $versions = array_map(static fn($f) => basename((string)$f, '.sql'), $files);
        sort($versions);
        return array_values($versions);
    }

    /** @return list<string> уже применённые версии */
    public static function applied(): array
    {
        if (!DB::tableExists('migrations')) return [];
        try {
            return array_column(DB::all('SELECT version FROM {migrations} ORDER BY version'), 'version');
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<string> что осталось применить */
    public static function pending(): array
    {
        return array_values(array_diff(self::available(), self::applied()));
    }

    /**
     * Применить все недостающие миграции.
     *
     * @return array{applied: list<string>, skipped: array<string, list<string>>,
     *               failed: ?string, error: ?string}
     *         failed — версия, на которой остановились; предыдущие уже записаны
     *         skipped — фрагменты, которые были применены раньше
     */
    public static function apply(): array
    {
        /* Один прогон за раз (v5.6): выкладка и кнопка в админке могли
           запустить миграции одновременно, и вторая применяла ту же версию
           поверх первой. Второй ждёт замка и видит, что делать уже нечего. */
        $lock = @fopen(SSO_ROOT . '/storage/migrate.lock', 'c');
        if ($lock !== false) flock($lock, LOCK_EX);
        try {
            return self::applyUnlocked();
        } finally {
            if ($lock !== false) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private static function applyUnlocked(): array
    {
        $done = [];
        $skipped = [];
        foreach (self::pending() as $version) {
            $file = SSO_ROOT . '/migrations/' . $version . '.sql';
            if (!is_file($file)) continue;
            try {
                $s = DB::runMigrationSql((string)file_get_contents($file));
                if ($s !== []) $skipped[$version] = $s;
                self::runPhpStep($version);
                DB::insert('migrations', ['version' => $version, 'applied_at' => now()]);
                $done[] = $version;
            } catch (\Throwable $e) {
                /* Останавливаемся на первой ошибке: следующие миграции могут
                   опираться на эту, и применять их вслепую — портить схему. */
                return ['applied' => $done, 'skipped' => $skipped,
                        'failed' => $version, 'error' => $e->getMessage()];
            }
        }
        return ['applied' => $done, 'skipped' => $skipped, 'failed' => null, 'error' => null];
    }

    /**
     * Необязательный PHP-шаг миграции: файл `NNN_имя.php` рядом с `NNN_имя.sql`.
     *
     * Нужен там, где SQL бессилен: заполнить новую колонку значениями, которые
     * умеет считать только приложение. Номер аккаунта, например, — случайный и с
     * контрольной цифрой, средствами SQL такое не выдать, а переносимо между
     * MySQL и SQLite — тем более.
     *
     * Файл возвращает callable. Шаг обязан быть идемпотентным: он выполняется
     * до записи версии, и если следом что-то упадёт, миграцию запустят заново.
     */
    public static function runPhpStep(string $version): void
    {
        $file = SSO_ROOT . '/migrations/' . $version . '.php';
        if (!is_file($file)) return;
        $step = require $file;
        if (is_callable($step)) $step();
    }

    /**
     * Готова ли схема под конкретную возможность.
     * Дешевле, чем ловить исключение на каждом запросе, и читается яснее.
     */
    public static function ready(string ...$tables): bool
    {
        foreach ($tables as $t) if (!DB::tableExists($t)) return false;
        return true;
    }
}
