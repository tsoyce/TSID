<?php
/**
 * Раскладка прав и уровней для уже существующих ролей (v4.3).
 *
 * Схема даёт пустую таблицу прав — а пустая таблица означает «роль не может
 * ничего». Для встроенных ролей это молчаливое лишение доступа посреди
 * обновления, поэтому раскладываем умолчания сразу.
 *
 * Шаг идемпотентен: он смотрит, есть ли у роли хоть одно право, и не трогает
 * тех, у кого уже что-то проставлено, — повторный запуск не затрёт ручную
 * настройку.
 */

declare(strict_types=1);

return static function (): void {
    $roles = \App\Core\DB::all('SELECT id, slug, client_id FROM {roles}');
    foreach ($roles as $r) {
        $id = (int)$r['id'];
        $slug = (string)$r['slug'];

        $level = \App\Core\Permission::DEFAULT_LEVEL[$slug] ?? 20;
        \App\Core\DB::update('roles', ['access_level' => $level], 'id = ?', [$id]);

        /* superadmin'у права не выдаём: ему разрешено всё по определению, и
           галочки позволили бы снять их с себя и запереть систему. */
        if ($slug === 'superadmin') continue;

        $has = \App\Core\DB::val('SELECT COUNT(*) FROM {role_permissions} WHERE role_id = ?', [$id]);
        if ((int)$has > 0) continue;

        foreach (\App\Core\Permission::DEFAULTS[$slug] ?? [] as $perm) {
            \App\Core\DB::insert('role_permissions', ['role_id' => $id, 'perm' => $perm]);
        }
    }
};
