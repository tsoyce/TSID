<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Роли: глобальные (client_id IS NULL) и скоуп-роли порталов (§9.2). */
final class Role
{
    public static function find(int $id): ?array
    {
        return DB::row('SELECT * FROM {roles} WHERE id = ?', [$id]);
    }

    public static function all(): array
    {
        return DB::all(
            'SELECT r.*, c.name AS client_name, c.slug AS client_slug
             FROM {roles} r LEFT JOIN {clients} c ON c.id = r.client_id
             ORDER BY r.client_id IS NULL DESC, c.name, r.slug');
    }

    public static function forClient(?int $clientDbId): array
    {
        if ($clientDbId === null) {
            return DB::all('SELECT * FROM {roles} WHERE client_id IS NULL ORDER BY slug');
        }
        return DB::all('SELECT * FROM {roles} WHERE client_id = ? ORDER BY slug', [$clientDbId]);
    }

    public static function findBySlug(string $slug, ?int $clientDbId = null): ?array
    {
        if ($clientDbId === null) {
            return DB::row('SELECT * FROM {roles} WHERE slug = ? AND client_id IS NULL', [$slug]);
        }
        return DB::row('SELECT * FROM {roles} WHERE slug = ? AND client_id = ?', [$slug, $clientDbId]);
    }

    public static function create(?int $clientDbId, string $slug, string $name, ?string $description = null, bool $system = false): int
    {
        $id = DB::insert('roles', [
            'client_id' => $clientDbId,
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'is_system' => $system ? 1 : 0,
            'created_at' => now(),
        ]);

        /* Знакомому слагу сразу даём его набор прав и уровень. Миграция 014
           разложила их только по ролям, существовавшим на момент обновления, —
           а `client_admin` заводится лениво, при первом назначении админа
           портала. Без этого делегированный админ, появившийся позже, получал
           роль с пустой таблицей прав и не мог ничего. */
        if (isset(\App\Core\Permission::DEFAULT_LEVEL[$slug])) {
            self::setLevel($id, \App\Core\Permission::DEFAULT_LEVEL[$slug]);
        }
        if (isset(\App\Core\Permission::DEFAULTS[$slug])) {
            self::setPermissions($id, \App\Core\Permission::DEFAULTS[$slug]);
        }
        return $id;
    }

    /**
     * Системная роль «Админ портала» — одна на всю установку.
     *
     * До v4.24 она заводилась строкой на КАЖДЫЙ портал, и справочник ролей рос
     * вместе с числом сервисов: три роли по смыслу и `3 + N` строк по факту, все
     * в одном плоском списке в карточке человека. Теперь роль определена один
     * раз, а «админ портала А» — это её выдача с областью `client:А`.
     */
    public static function clientAdmin(): array
    {
        $role = self::findBySlug('client_admin', null);
        if ($role) return $role;
        $id = self::create(null, 'client_admin', 'Админ портала',
            'Доступ в админку SSO: только пользователи и роли своего портала', true);
        return self::find($id);
    }

    /**
     * Выдать роль в области.
     *
     * Область — это «где роль действует»: `client:А` даёт админа одного
     * портала, `org:X` — всех проектов организации, `system` — всей установки.
     * Одна и та же роль выдаётся сколько угодно раз с разными областями:
     * ключ выдачи включает область (миграция 021).
     */
    public static function assignScoped(int $userId, int $roleId, \App\Core\Scope $scope, ?int $grantedBy = null): void
    {
        /* Отставшая схема областей не знает: кладём выдачу как раньше, без
           области. Смысл сохраняется — до миграции роль и так действовала там,
           где определена, — а после миграции 020 расстановка проставит область
           по тому же правилу. */
        if (!\App\Core\AccessResolver::ready()) {
            $exists = DB::row('SELECT 1 AS x FROM {user_roles} WHERE user_id = ? AND role_id = ?',
                [$userId, $roleId]);
            if ($exists) return;
            DB::insert('user_roles', [
                'user_id' => $userId, 'role_id' => $roleId,
                'granted_by' => $grantedBy, 'created_at' => now(),
            ]);
            return;
        }

        /* «Нет идентификатора» — это НОЛЬ, а не NULL, и на то две причины.
           Первая: колонка входит в первичный ключ, а MySQL не пускает туда
           NULL-able. Вторая важнее в бою — сравнение `scope_id = NULL` не
           истинно никогда, и эта самая проверка «уже выдано» ничего не
           находила: системные роли множились при каждом вызове, а зовётся
           Role::assign() в том числе на каждом входе через организацию. */
        $scopeId = $scope->id ?? 0;
        $exists = DB::row('SELECT 1 AS x FROM {user_roles}
             WHERE user_id = ? AND role_id = ? AND scope_type = ? AND scope_id = ?',
            [$userId, $roleId, $scope->type, $scopeId]);
        if ($exists) return;
        DB::insert('user_roles', [
            'user_id' => $userId, 'role_id' => $roleId,
            'granted_by' => $grantedBy, 'created_at' => now(),
            'scope_type' => $scope->type, 'scope_id' => $scopeId,
        ]);
    }

    /**
     * Кому что выдано В ЭТОЙ ОБЛАСТИ.
     *
     * Один запрос на организацию и на сервис: вопрос «кому здесь что выдано»
     * не меняется от того, чем именно «здесь» является, — меняется только
     * область. Раньше он жил в контроллере организации, и у сервиса такого
     * экрана не было вовсе: роль, приехавшая из портала, была видна списком и
     * ничего с ней сделать было нельзя.
     *
     * @return list<array>
     */
    public static function grantsInScope(\App\Core\Scope $scope): array
    {
        return DB::all(
            'SELECT ur.user_id, r.id AS role_id, r.slug, r.name, r.access_level,
                    u.email, u.login, u.first_name, u.last_name, u.display_name,
                    u.avatar_path, u.public_id
               FROM {user_roles} ur
               JOIN {roles} r ON r.id = ur.role_id
               JOIN {users} u ON u.id = ur.user_id
              WHERE ur.scope_type = ? AND ur.scope_id = ?
              ORDER BY r.access_level DESC, u.email',
            [$scope->type, (int)($scope->id ?? 0)]);
    }

    /** Отозвать роль в области. */
    public static function revokeScoped(int $userId, int $roleId, \App\Core\Scope $scope): void
    {
        if (!\App\Core\AccessResolver::ready()) {
            self::revoke($userId, $roleId);
            return;
        }
        DB::run('DELETE FROM {user_roles}
                  WHERE user_id = ? AND role_id = ? AND scope_type = ? AND scope_id = ?',
            [$userId, $roleId, $scope->type, $scope->id ?? 0]);
    }

    /**
     * Выдать роль в её собственной области — «где определена, там и действует».
     *
     * Так роль понималась до появления областей, и здесь это записано явно:
     * глобальная роль выдаётся на систему, роль портала — на этот портал.
     * Иначе клиентская роль легла бы в системную область и уехала бы в claim
     * без префикса — то есть портал получил бы чужую роль как свою.
     *
     * Когда область нужно назвать отдельно от определения (одна системная
     * роль «Админ портала» на разных сервисах) — {@see assignScoped()}.
     */
    public static function assign(int $userId, int $roleId, ?int $grantedBy = null): void
    {
        $role = self::find($roleId);
        if (!$role) return;
        $clientId = $role['client_id'] === null ? null : (int)$role['client_id'];
        $scope = $clientId === null ? \App\Core\Scope::system() : \App\Core\Scope::client($clientId);
        self::assignScoped($userId, $roleId, $scope, $grantedBy);
    }

    public static function revoke(int $userId, int $roleId): void
    {
        DB::run('DELETE FROM {user_roles} WHERE user_id = ? AND role_id = ?', [$userId, $roleId]);
    }

    public static function delete(int $id): bool
    {
        $role = self::find($id);
        if (!$role || (int)$role['is_system'] === 1) return false;
        DB::run('DELETE FROM {roles} WHERE id = ?', [$id]);
        return true;
    }

    /** Роли пользователя (полные записи, с именем клиента). */
    public static function ofUser(int $userId): array
    {
        return DB::all(
            'SELECT r.*, c.name AS client_name, c.slug AS client_slug
             FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id
             LEFT JOIN {clients} c ON c.id = r.client_id
             WHERE ur.user_id = ? ORDER BY r.client_id IS NULL DESC, r.slug', [$userId]);
    }

    /* ---------- Права и уровень (v4.3) ---------- */

    /** @return list<string> */
    public static function permissions(int $roleId): array
    {
        try {
            return array_column(
                DB::all('SELECT perm FROM {role_permissions} WHERE role_id = ?', [$roleId]), 'perm');
        } catch (\Throwable) {
            return [];   // миграция 014 ещё не применена
        }
    }

    /**
     * Переписать набор прав роли целиком.
     *
     * Целиком, а не по одному: форма присылает состояние всех галочек разом,
     * и «снятая галочка» — это отсутствие поля в запросе. Разница между
     * «снял» и «не прислал» на такой форме неразличима, поэтому источником
     * считается присланный набор.
     *
     * @param list<string> $perms
     */
    public static function setPermissions(int $roleId, array $perms): void
    {
        /* Таблицы прав может не быть: код обновили, миграцию 014 не применили.
           Молча пропускаем — роль заведётся без прав, и это честнее, чем
           уронить заведение роли целиком. */
        if (!\App\Core\Migrations::ready('role_permissions')) return;

        $perms = \App\Core\Permission::withImplied($perms);
        DB::run('DELETE FROM {role_permissions} WHERE role_id = ?', [$roleId]);
        foreach ($perms as $p) {
            DB::insert('role_permissions', ['role_id' => $roleId, 'perm' => $p]);
        }
    }

    public static function setLevel(int $roleId, int $level): void
    {
        /* Колонка появилась в миграции 014. Без неё роль заводится без уровня —
           заведение роли не должно падать из-за отставшей схемы. */
        if (!DB::columnExists('roles', 'access_level')) return;
        DB::update('roles', ['access_level' => max(0, min(99, $level))], 'id = ?', [$roleId]);
    }

    /**
     * Права пользователя: объединение по всем его ролям.
     *
     * Объединение, а не пересечение: роли добавляют возможности, а не
     * ограничивают друг друга. Человек с ролями «редактор» и «поддержка»
     * может и то, и другое.
     *
     * @return list<string>
     */
    public static function permissionsOfUser(int $userId): array
    {
        try {
            return array_column(DB::all(
                'SELECT DISTINCT rp.perm FROM {role_permissions} rp
                   JOIN {user_roles} ur ON ur.role_id = rp.role_id
                  WHERE ur.user_id = ?', [$userId]), 'perm');
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Уровень базовой роли `user` — пол, ниже которого живой человек не падает.
     *
     * `User::rolesClaim()` всегда дописывает в claim роль `user`, даже если её
     * не выдавали. Значит и число обязано это учитывать: иначе портал получает
     * два противоречащих утверждения — «роли: user» и «уровень: 0», а ноль по
     * нашей же шкале означает «заблокирован».
     */
    public static function baseLevel(): int
    {
        try {
            $v = DB::val('SELECT access_level FROM {roles} WHERE client_id IS NULL AND slug = ?', ['user']);
            return $v === null ? 20 : (int)$v;
        } catch (\Throwable) {
            return 20;
        }
    }

    /**
     * Наибольший уровень среди ролей пользователя.
     *
     * @param int|null $clientDbId портал, для которого считаем. Учитываются
     *        глобальные роли (действуют везде) и роли этого портала; роли
     *        чужих порталов — нет. null — по всей установке: так спрашивают
     *        админка и удостоверение, «кто этот человек». Роли порталов туда
     *        не входят: у нас они не значат ничего (v4.61).
     */
    public static function levelOfUser(int $userId, ?int $clientDbId = null): int
    {
        try {
            $sql = 'SELECT MAX(r.access_level) FROM {roles} r
                      JOIN {user_roles} ur ON ur.role_id = r.id
                     WHERE ur.user_id = ?';
            $params = [$userId];
            if ($clientDbId === null) {
                /* «Кто этот человек в системе» — без ролей порталов. Роль
                   портала — ступень внутри своего сервиса: «admin» Архива
                   — администратор Архива, а не администратор вообще.
                   Засчитай её здесь — и метка, придуманная на чужой стороне,
                   дала бы старшинство у нас. До v4.61 от этого берегла
                   двадцатка у всякого «admin» снаружи — и ломала сам портал. */
                $sql .= ' AND r.client_id IS NULL';
            } elseif (!\App\Core\AccessResolver::ready()) {
                /* Отставшая схема — прежний фильтр. Без этой ветки запрос падал бы,
                   а внешний catch вернул бы 0, то есть «заблокирован»: молчаливое
                   понижение всех до нуля вместо честного «уровень посчитать нечем». */
                $sql .= ' AND (r.client_id IS NULL OR r.client_id = ?)';
                $params[] = $clientDbId;
            } else {
                /* Считаем по ОБЛАСТИ ВЫДАЧИ, а не по тому, где роль определена.
                   Это не улучшение, а необходимость: с v4.24 «Админ портала» —
                   одна СИСТЕМНАЯ роль, у неё client_id пуст, и прежний фильтр
                   «глобальные плюс роли этого портала» засчитал бы её на всех
                   порталах сразу — то есть вернул бы утечку, закрытую в v4.22.1.
                   Отличает админа портала А от админа портала Б только область.

                   Область организации покрывает её проекты (SCOPES §2). Пока
                   clients.org_id не заполнен, эта ветка не находит ничего —
                   она здесь, чтобы фаза 3 не переписывала запрос заново. */
                $sql .= ' AND (ur.scope_type = ?
                            OR (ur.scope_type = ? AND ur.scope_id = ?)
                            OR (ur.scope_type = ? AND ur.scope_id =
                                  (SELECT c.org_id FROM {clients} c WHERE c.id = ?)))';
                $params[] = \App\Core\Scope::SYSTEM;
                $params[] = \App\Core\Scope::CLIENT;
                $params[] = $clientDbId;
                $params[] = \App\Core\Scope::ORG;
                $params[] = $clientDbId;
                /* И роль ЧУЖОГО портала не засчитывается никогда, в какой бы
                   области её ни выдали. Выдача сама этого не допускает
                   (RoleOffer), но с v4.61 уровень роли портала доходит до 70, и
                   держаться в своём портале он обязан расчётом, а не честным
                   словом каждого пути выдачи. */
                $sql .= ' AND (r.client_id = ? OR r.client_id IS NULL)';
                $params[] = $clientDbId;
            }
            $v = DB::val($sql, $params);
            return $v === null ? 0 : (int)$v;
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function usersCount(int $roleId): int
    {
        return (int)DB::val('SELECT COUNT(*) FROM {user_roles} WHERE role_id = ?', [$roleId]);
    }
}
