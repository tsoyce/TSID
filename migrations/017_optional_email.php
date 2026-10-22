<?php
/**
 * Снятие NOT NULL с {users}.email — см. разбор в 017_optional_email.sql.
 *
 * MySQL меняет колонку на месте. SQLite ALTER COLUMN не поддерживает совсем,
 * там таблицу пересобирают: новая рядом, перелив, снос старой, переименование.
 * DDL для новой берём не из файла, а из самой базы: к этому шагу таблица уже
 * прошла через шестнадцать миграций, и повторять её описание руками значит
 * однажды разойтись с действительностью.
 *
 * Уникальный ключ по почте остаётся: и MySQL, и SQLite считают NULL
 * несравнимым, поэтому пустых адресов может быть сколько угодно, а
 * непустой — по-прежнему один.
 */

declare(strict_types=1);

use App\Core\DB;

return static function (): void {
    if (DB::driver() !== 'sqlite') {
        DB::run('ALTER TABLE {users} MODIFY email VARCHAR(255) NULL');
        return;
    }

    $table = DB::sql('{users}');
    $ddl = (string)(DB::row('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table])['sql'] ?? '');
    if ($ddl === '') return;
    /* Уже без NOT NULL — повтор шага ничего не портит и ничего не делает. */
    if (!preg_match('~^(\s*)email(\s+)(\w+)\s+NOT\s+NULL~mi', $ddl)) return;

    $indexes = array_column(DB::all(
        'SELECT sql FROM sqlite_master WHERE type = ? AND tbl_name = ? AND sql IS NOT NULL',
        ['index', $table]
    ), 'sql');

    $tmp = $table . '_mig017';
    $newDdl = (string)preg_replace('~^(\s*)email(\s+)(\w+)\s+NOT\s+NULL~mi', '$1email$2$3 NULL', $ddl, 1);
    $newDdl = (string)preg_replace('~CREATE\s+TABLE\s+["`\[]?' . preg_quote($table, '~') . '["`\]]?~i',
        'CREATE TABLE "' . $tmp . '"', $newDdl, 1);

    /* Внешние ключи гасим на время пересборки: DROP TABLE увидел бы ссылки на
       себя из полутора десятков таблиц. Переключатель обязан стоять снаружи
       транзакции — внутри SQLite его молча игнорирует.

       Возвращаем ровно то значение, что было, а не «включено». Соединение здесь
       не всегда наше: перенос базы зовёт миграции для приёмника, копирует туда
       строки пачками и рассчитывает на выключенные ключи. Миграция, включившая
       их за собой, ломала перенос на первой же таблице. */
    $fkWas = (int)DB::pdo()->query('PRAGMA foreign_keys')->fetchColumn();
    DB::pdo()->exec('PRAGMA foreign_keys = OFF');
    try {
        DB::pdo()->exec('BEGIN');
        DB::pdo()->exec($newDdl);
        DB::pdo()->exec('INSERT INTO "' . $tmp . '" SELECT * FROM "' . $table . '"');
        DB::pdo()->exec('DROP TABLE "' . $table . '"');
        DB::pdo()->exec('ALTER TABLE "' . $tmp . '" RENAME TO "' . $table . '"');
        foreach ($indexes as $sql) DB::pdo()->exec((string)$sql);
        DB::pdo()->exec('COMMIT');
    } catch (\Throwable $e) {
        DB::pdo()->exec('ROLLBACK');
        DB::pdo()->exec('PRAGMA foreign_keys = ' . ($fkWas ? 'ON' : 'OFF'));
        throw $e;
    }
    DB::pdo()->exec('PRAGMA foreign_keys = ' . ($fkWas ? 'ON' : 'OFF'));
};
