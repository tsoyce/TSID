<?php
/**
 * Расстановка областей по тому, что уже есть — см. 020_scopes.sql.
 *
 * Правило перевода одно и оно без потерь:
 *   роль без портала  → дом `system`
 *   роль портала C    → дом `client:C`
 * а выдача получает область дома своей роли: сегодня роль портала иначе как
 * «в этом портале» и не действовала, просто область была не записана, а
 * подразумевалась.
 *
 * Шаг обязан быть идемпотентным: он выполняется до записи версии, и если
 * следом что-то упадёт, миграцию запустят заново. Здесь это так по построению
 * — расстановка вычисляется из client_id, а не из текущего значения области.
 */

declare(strict_types=1);

use App\Core\DB;

return static function (): void {
    if (!DB::columnExists('roles', 'scope_type')) return;

    /* Ролей в системе десятки, не миллионы: обходим по одной вместо
       UPDATE ... JOIN, который на MySQL и SQLite пишется по-разному. */
    foreach (DB::all('SELECT id, client_id FROM {roles}') as $r) {
        $clientId = $r['client_id'] === null ? null : (int)$r['client_id'];
        $type = $clientId === null ? 'system' : 'client';
        DB::run('UPDATE {roles} SET scope_type = ?, scope_id = ? WHERE id = ?',
            [$type, $clientId, (int)$r['id']]);
        /* У выдачи «нет идентификатора» — это ноль: колонка войдёт в первичный
           ключ (021), а MySQL не пускает туда NULL-able. Ноль ещё и
           сравнивается, в отличие от NULL. */
        DB::run('UPDATE {user_roles} SET scope_type = ?, scope_id = ? WHERE role_id = ?',
            [$type, $clientId ?? 0, (int)$r['id']]);
    }
};
