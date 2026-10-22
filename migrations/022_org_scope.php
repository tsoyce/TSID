<?php
/**
 * Полномочия организации из ENUM в выдачи ролей — см. 022_org_scope.sql.
 *
 * Идемпотентен: повторный запуск видит, что выдачи уже есть, и ничего не
 * добавляет (проверка идёт по паре «человек + область», а не по факту
 * перебора).
 */

declare(strict_types=1);

use App\Core\DB;
use App\Core\Permission;
use App\Core\Scope;
use App\Models\Role;

return static function (): void {
    if (!DB::columnExists('user_roles', 'scope_type')) return;
    if (!DB::tableExists('org_members')) return;

    /* Роли-приёмники. Заводим через Role::create — он сам проставит уровень и
       набор прав по каталогу (Permission::DEFAULT_LEVEL и DEFAULTS), как это
       делается для всех встроенных ролей. */
    $ensure = static function (string $slug, string $name, string $desc): int {
        $role = Role::findBySlug($slug, null);
        if ($role) return (int)$role['id'];
        return Role::create(null, $slug, $name, $desc, true);
    };
    $roleIds = [
        'owner' => $ensure('org_owner', 'Владелец организации',
            'Распоряжается проектами и составом своей организации'),
        'admin' => $ensure('org_admin', 'Администратор организации',
            'Управляет проектами своей организации'),
    ];

    /* Уровень и права могли не проставиться, если слаг попал в каталог позже
       самой роли (так уже было с client_admin в 014). Досыпаем явно. */
    foreach (['org_owner', 'org_admin'] as $slug) {
        $role = Role::findBySlug($slug, null);
        if (!$role) continue;
        if ((int)$role['access_level'] !== (Permission::DEFAULT_LEVEL[$slug] ?? 20)) {
            Role::setLevel((int)$role['id'], Permission::DEFAULT_LEVEL[$slug] ?? 20);
        }
        if (Role::permissions((int)$role['id']) === []) {
            Role::setPermissions((int)$role['id'], Permission::DEFAULTS[$slug] ?? []);
        }
    }

    foreach (DB::all('SELECT org_id, user_id, role, added_by FROM {org_members}') as $m) {
        $roleId = $roleIds[(string)$m['role']] ?? null;
        if ($roleId === null) continue;   // обычный участник — полномочий нет
        Role::assignScoped((int)$m['user_id'], $roleId, Scope::org((int)$m['org_id']),
            $m['added_by'] === null ? null : (int)$m['added_by']);
    }
};
