<?php

declare(strict_types=1);

/**
 * PHP-шаг миграции 027: снять выдачи на области, которых больше нет.
 *
 * Идемпотентен: трогает только строки, чья область не находит своего объекта.
 * Отставшая схема без колонок областей (до 020) пропускается целиком.
 */

return static function (): void {
    if (!\App\Core\DB::columnExists('user_roles', 'scope_type')) return;
    \App\Core\DB::run('DELETE FROM {user_roles} WHERE scope_type = ? AND scope_id NOT IN (SELECT id FROM {orgs})',
        [\App\Core\Scope::ORG]);
    \App\Core\DB::run('DELETE FROM {user_roles} WHERE scope_type = ? AND scope_id NOT IN (SELECT id FROM {clients})',
        [\App\Core\Scope::CLIENT]);
    if (\App\Core\DB::columnExists('clients', 'org_id')) {
        \App\Core\DB::run('UPDATE {clients} SET org_id = NULL WHERE org_id IS NOT NULL AND org_id NOT IN (SELECT id FROM {orgs})');
    }
};
