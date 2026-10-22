<?php
/**
 * ENUM убран из схемы: набор значений живёт в коде, а не в двух местах сразу.
 *
 * Боевой случай, с которого всё началось: при подключении портала админка
 * упала с «SQLSTATE[01000]: 1265 Data truncated for column 'type'». Тип
 * одноразового токена `portal_install` завели в коде и не дописали в ENUM,
 * объявленный ещё в 001_init. Ссылка установки портала не выдавалась вовсе.
 * Тем же способом был мёртв `owner_transfer` — передача владения.
 *
 * Почему не «дописать два значения»: потому что дописывать пришлось бы каждый
 * раз, а забыть — ровно один. На SQLite, где идёт вся разработка и вся батарея,
 * ENUM игнорируется — колонка становится TEXT и принимает что угодно. То есть
 * ограничение существует ровно там, где его некому поймать: на боевой. Оно не
 * превращает опечатку в красную проверку, оно превращает её в отказ у
 * пользователя, а при нестрогом режиме MySQL — в молча обрезанное значение.
 *
 * Набор значений сторожит код — `OneTimeToken::TTL`, `User::STATUSES`,
 * `Org::ROLES`, — и он один на оба движка. Ревизия DDL (tools/mysql_lint.php)
 * с этого выпуска не пропускает ENUM обратно.
 *
 * Идемпотентен: повторный MODIFY уже переведённой колонки ничего не меняет.
 * Значения при переводе ENUM → VARCHAR сохраняются как есть.
 */

declare(strict_types=1);

use App\Core\DB;

return static function (): void {
    /* На SQLite делать нечего: ENUM он и не создавал. */
    if (DB::driver() !== 'mysql') return;

    $columns = [
        ['users', 'status', "VARCHAR(16) NOT NULL DEFAULT 'pending'"],
        ['clients', 'access_policy', "VARCHAR(16) NOT NULL DEFAULT 'open'"],
        ['user_client_access', 'status', "VARCHAR(16) NOT NULL DEFAULT 'active'"],
        ['one_time_tokens', 'type', 'VARCHAR(32) NOT NULL'],
        ['linked_identities', 'provider', 'VARCHAR(20) NOT NULL'],
        ['client_events', 'status', "VARCHAR(16) NOT NULL DEFAULT 'pending'"],
        ['mail_queue', 'status', "VARCHAR(16) NOT NULL DEFAULT 'pending'"],
        ['export_jobs', 'status', "VARCHAR(16) NOT NULL DEFAULT 'pending'"],
        ['webhook_deliveries', 'status', "VARCHAR(16) NOT NULL DEFAULT 'pending'"],
        ['org_members', 'role', "VARCHAR(16) NOT NULL DEFAULT 'member'"],
        ['roles', 'scope_type', "VARCHAR(16) NOT NULL DEFAULT 'system'"],
        ['user_roles', 'scope_type', "VARCHAR(16) NOT NULL DEFAULT 'system'"],
    ];

    foreach ($columns as [$table, $column, $definition]) {
        /* Таблицы может не быть: часть из них появилась поздними миграциями, а
           установка могла остановиться на любой из них. */
        if (!DB::columnExists($table, $column)) continue;
        DB::run('ALTER TABLE ' . DB::sql('{' . $table . '}')
            . ' MODIFY COLUMN `' . $column . '` ' . $definition);
    }
};
