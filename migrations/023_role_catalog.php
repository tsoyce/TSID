<?php
/**
 * Роли управления, которых не хватало: аудитор, поддержка, аудитор организации.
 *
 * Матрица описана в ROLES.md, справочник — в RoleCatalog. Здесь только посев:
 * уровень и права роль получает из справочника, а не из этого файла, — иначе
 * появилось бы второе место, где написано, что такое «аудитор».
 *
 * Роли ПОРТАЛА (редактор, модератор, выпускающий…) здесь не заводятся и не
 * могут: они принадлежат конкретному сервису и появляются либо по сообщению
 * самого портала, либо руками в его карточке.
 *
 * Идемпотентен: существующие роли не трогает.
 */

declare(strict_types=1);

use App\Core\RoleCatalog;
use App\Models\Role;

return static function (): void {
    foreach (['auditor', 'support', 'org_auditor'] as $slug) {
        if (Role::findBySlug($slug, null)) continue;
        $row = RoleCatalog::ROLES[$slug];
        $id = Role::create(null, $slug, $row['title'], $row['desc'], true);
        Role::setLevel($id, $row['level']);
        Role::setPermissions($id, RoleCatalog::permsOf($slug));
    }
};
