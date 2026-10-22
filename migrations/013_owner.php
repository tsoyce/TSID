<?php
/**
 * Владелец инсталляции (v4.2).
 *
 * `superadmin` — роль, которую выдают в два клика, чтобы человек завёл портал.
 * Системные операции (смена домена, смена базы, сброс) равнять с ней нельзя.
 * Владелец один, и на существующих установках это тот, кто ставил систему, —
 * старейший суперадмин.
 *
 * Шаг только PHP: новых таблиц нет, есть запись в settings, которую надо
 * вычислить по данным. Пустым владелец не остаётся: если суперадминов нет
 * вообще (свежая база в момент установки), настройку просто не пишем — её
 * проставит первый вход.
 */

declare(strict_types=1);

return static function (): void {
    if (\App\Core\Setting::get('system.owner_user_id') !== null) return;

    $row = \App\Core\DB::row(
        'SELECT u.id FROM {users} u
           JOIN {user_roles} ur ON ur.user_id = u.id
           JOIN {roles} r ON r.id = ur.role_id
          WHERE r.slug = ? AND r.client_id IS NULL AND u.status <> ?
          ORDER BY u.id LIMIT 1',
        ['superadmin', 'deleted']
    );
    if (!$row) return;

    \App\Core\Setting::set('system.owner_user_id', (string)(int)$row['id']);
    \App\Core\Audit::log('system.owner.set', [
        'actor' => null, 'on' => (int)$row['id'], 'meta' => ['why' => 'migration'],
    ]);
};
