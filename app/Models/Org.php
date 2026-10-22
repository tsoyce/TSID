<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/**
 * Организации: группа людей, которой можно выдать доступ к порталу целиком.
 * Роль внутри организации (owner/admin/member) — не то же, что роль в портале:
 * первая про управление составом, вторая про права внутри сервиса.
 */
final class Org
{
    /**
     * Роли внутри организации: они про управление составом, а не про права в
     * порталах. Подписи намеренно с уточнением «организации»: слово «Владелец»
     * в этой системе уже занято владельцем удостоверения в профиле, а словарь
     * переводит по самой русской строке — одинаковый ключ дал бы в английском
     * «Holder» вместо «Owner».
     */
    public const ROLES = [
        'owner' => 'Владелец организации',
        'admin' => 'Администратор организации',
        'member' => 'Участник',
    ];

    /** Схема готова? Организации появились в миграции 005. */
    public static function ready(): bool
    {
        return \App\Core\Migrations::ready('orgs', 'org_members', 'org_client_access');
    }

    public static function all(): array
    {
        if (!self::ready()) return [];
        /* Сервисы — для плитки в списке (v4.90): «сколько проектов у
           организации» — второй вопрос после «сколько людей». */
        $projects = DB::columnExists('clients', 'org_id')
            ? ', (SELECT COUNT(*) FROM {clients} c WHERE c.org_id = o.id) AS projects' : ', 0 AS projects';
        return DB::all('SELECT o.*, (SELECT COUNT(*) FROM {org_members} m WHERE m.org_id = o.id) AS members' . $projects . '
             FROM {orgs} o ORDER BY o.name');
    }

    public static function find(int $id): ?array
    {
        return DB::row('SELECT * FROM {orgs} WHERE id = ?', [$id]);
    }

    public static function create(string $name, ?string $description, ?int $createdBy): int
    {
        /* Через Translit, а не через выброс кириллицы: раньше «Департамент
           образования» и «Отдел кадров» давали один и тот же слаг «-». */
        $slug = \App\Core\Translit::slug($name, 'org');
        $base = $slug;
        for ($i = 2; DB::row('SELECT id FROM {orgs} WHERE slug = ?', [$slug]); $i++) $slug = $base . '-' . $i;

        return DB::insert('orgs', [
            'name' => mb_substr($name, 0, 100),
            'slug' => $slug,
            'description' => $description ? mb_substr($description, 0, 255) : null,
            'created_by' => $createdBy,
            'created_at' => now(),
        ]);
    }

    public static function delete(int $id): void
    {
        DB::run('DELETE FROM {orgs} WHERE id = ?', [$id]);
    }

    public static function rename(int $id, string $name, ?string $description): void
    {
        DB::update('orgs', ['name' => $name, 'description' => $description], 'id = ?', [$id]);
    }

    /** Роль участника на месте: дата добавления при этом сохраняется. */
    public static function setRole(int $orgId, int $userId, string $role): bool
    {
        if (!isset(self::ROLES[$role])) return false;
        /* У таблицы состава нет собственного id — ключ составной, поэтому
           проверяем наличие строки, а не её идентификатор. */
        $has = DB::row('SELECT 1 AS x FROM {org_members} WHERE org_id = ? AND user_id = ?', [$orgId, $userId]);
        if (!$has) return false;
        /* Колонку обновляем ради истории, полномочия — выдачами (v4.25). */
        DB::update('org_members', ['role' => $role], 'org_id = ? AND user_id = ?', [$orgId, $userId]);
        self::applyRole($orgId, $userId, $role, null);
        return true;
    }

    /**
     * Удалить организацию со всем, что к ней привязано.
     *
     * Каскады объявлены, но у SQLite работают только при включённом PRAGMA, а
     * оставшийся состав или выданный доступ — это люди, которые продолжают
     * входить в порталы через организацию, которой уже нет.
     *
     * @return array<string,int>
     */
    public static function purge(int $id): array
    {
        /* Одним куском (v4.45): иначе обрыв между составом и доступами
           оставляет организацию, у которой людей уже нет, а выданные ею
           доступы к порталам ещё действуют. */
        return DB::tx(static function () use ($id): array {
            $counts = DB::sweep(['org_members', 'org_client_access'], 'org_id', $id);
            /* Роли, выданные на область организации, и её порталы (v4.70).
               Раньше оставались: в карточке человека висела «организация #7» —
               роль в том, чего нет, — а портал числился за удалённой
               организацией. Портал сам по себе живёт дальше, ничьим. */
            if (DB::columnExists('user_roles', 'scope_type')) {
                $n = DB::run('DELETE FROM {user_roles} WHERE scope_type = ? AND scope_id = ?',
                    [\App\Core\Scope::ORG, $id])->rowCount();
                if ($n > 0) $counts['user_roles'] = $n;
            }
            if (DB::columnExists('clients', 'org_id')) {
                $n = DB::run('UPDATE {clients} SET org_id = NULL WHERE org_id = ?', [$id])->rowCount();
                if ($n > 0) $counts['clients_detached'] = $n;
            }
            DB::run('DELETE FROM {orgs} WHERE id = ?', [$id]);
            return $counts;
        });
    }

    /* ---------- Состав ---------- */

    /**
     * Слаг роли для полномочия в организации. `member` полномочий не даёт и
     * роли не имеет: членство — самостоятельный факт, а не право.
     */
    private const ROLE_SLUG = ['owner' => 'org_owner', 'admin' => 'org_admin'];

    /**
     * Полномочие человека в организации: owner | admin | member.
     *
     * Читается из ВЫДАЧ с областью этой организации, а не из `org_members.role`:
     * с v4.25 полномочия организации — обычные роли, и держать их вторым,
     * несвязанным перечислением значило бы иметь две системы прав (SCOPES §1.3).
     */
    public static function roleOf(int $orgId, int $userId): string
    {
        /* Отставшая схема — прежний источник: колонка состава. Она осталась на
           месте и до миграции 022 была единственной правдой. */
        if (!\App\Core\AccessResolver::ready()) {
            $v = DB::val('SELECT role FROM {org_members} WHERE org_id = ? AND user_id = ?',
                [$orgId, $userId]);
            return is_string($v) && isset(self::ROLES[$v]) ? $v : 'member';
        }

        $slugs = array_column(DB::all(
            'SELECT r.slug FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id
              WHERE ur.user_id = ? AND ur.scope_type = ? AND ur.scope_id = ?',
            [$userId, \App\Core\Scope::ORG, $orgId]), 'slug');
        if (in_array('org_owner', $slugs, true)) return 'owner';
        if (in_array('org_admin', $slugs, true)) return 'admin';
        return 'member';
    }

    public static function members(int $orgId): array
    {
        if (!self::ready()) return [];
        $rows = DB::all('SELECT m.*, u.email, u.public_id, u.first_name, u.last_name, u.display_name,
                    u.login, u.avatar_path, u.status
             FROM {org_members} m JOIN {users} u ON u.id = m.user_id
             WHERE m.org_id = ? ORDER BY u.email', [$orgId]);
        foreach ($rows as &$row) {
            $row['role'] = self::roleOf($orgId, (int)$row['user_id']);
        }
        unset($row);
        /* Порядок прежний: сначала полномочные, потом участники. */
        $rank = ['owner' => 0, 'admin' => 1, 'member' => 2];
        usort($rows, static fn(array $a, array $b): int
            => [$rank[$a['role']], $a['email'] ?? ''] <=> [$rank[$b['role']], $b['email'] ?? '']);
        return $rows;
    }

    public static function addMember(int $orgId, int $userId, string $role, ?int $addedBy): void
    {
        if (!isset(self::ROLES[$role])) $role = 'member';
        $exists = DB::row('SELECT 1 AS x FROM {org_members} WHERE org_id = ? AND user_id = ?', [$orgId, $userId]);
        if (!$exists) {
            DB::insert('org_members', [
                'org_id' => $orgId, 'user_id' => $userId, 'role' => $role,
                'added_by' => $addedBy, 'created_at' => now(),
            ]);
        }
        self::applyRole($orgId, $userId, $role, $addedBy);
    }

    /**
     * Привести выдачи в соответствие с полномочием: одно из двух или ни одного.
     *
     * Снимаем обе и выдаём нужную — так понижение владельца до участника
     * действительно снимает права, а не добавляет вторую роль поверх первой.
     */
    private static function applyRole(int $orgId, int $userId, string $role, ?int $by): void
    {
        $scope = \App\Core\Scope::org($orgId);
        foreach (self::ROLE_SLUG as $slug) {
            $r = \App\Models\Role::findBySlug($slug, null);
            if ($r) \App\Models\Role::revokeScoped($userId, (int)$r['id'], $scope);
        }
        $want = self::ROLE_SLUG[$role] ?? null;
        if ($want === null) return;
        $r = \App\Models\Role::findBySlug($want, null);
        if (!$r) {
            $id = \App\Models\Role::create(null, $want, self::ROLES[$role],
                'Полномочия в своей организации', true);
            $r = \App\Models\Role::find($id);
        }
        \App\Models\Role::assignScoped($userId, (int)$r['id'], $scope, $by);
    }

    public static function removeMember(int $orgId, int $userId): void
    {
        DB::run('DELETE FROM {org_members} WHERE org_id = ? AND user_id = ?', [$orgId, $userId]);
    }

    /** Организации пользователя — для claim `orgs` и профиля. */
    public static function forUser(int $userId): array
    {
        if (!self::ready()) return [];
        $rows = DB::all('SELECT o.* FROM {org_members} m JOIN {orgs} o ON o.id = m.org_id
             WHERE m.user_id = ? AND o.is_active = 1 ORDER BY o.name', [$userId]);
        foreach ($rows as &$row) {
            $row['role'] = self::roleOf((int)$row['id'], $userId);
        }
        unset($row);
        return $rows;
    }

    /**
     * Проекты организации — те, что ей ПРИНАДЛЕЖАТ.
     *
     * Не путать с {@see clients()}: там доступ, выданный организации к чужому
     * порталу, здесь владение. Первое отвечает «кого туда пускают», второе —
     * «чей проект»; роль, выданная на организацию, действует именно во втором.
     */
    public static function projects(int $orgId): array
    {
        if (!DB::columnExists('clients', 'org_id')) return [];
        return DB::all('SELECT * FROM {clients} WHERE org_id = ? ORDER BY name', [$orgId]);
    }

    /** Claim для id_token: ["acme:owner", "clients-dept:member"]. */
    public static function claimFor(int $userId): array
    {
        return array_map(fn($o) => $o['slug'] . ':' . $o['role'], self::forUser($userId));
    }

    /* ---------- Доступ организации к порталам ---------- */

    public static function clients(int $orgId): array
    {
        if (!self::ready()) return [];
        return DB::all('SELECT a.*, c.name, c.base_url, r.slug AS role_slug
             FROM {org_client_access} a JOIN {clients} c ON c.id = a.client_id
             LEFT JOIN {roles} r ON r.id = a.role_id
             WHERE a.org_id = ? ORDER BY c.name', [$orgId]);
    }

    /**
     * Обратный вопрос: какие организации получили доступ к этому порталу.
     *
     * Нужен карточке портала. Без него из неё не видно, что доступ роздан не
     * поштучно, а целой организацией, — а именно это и ломается при её
     * выключении.
     *
     * @return list<array{id:int,name:string,slug:string,role_slug:?string}>
     */
    public static function forClient(int $clientDbId): array
    {
        if (!self::ready()) return [];
        return DB::all('SELECT o.id, o.name, o.slug, r.slug AS role_slug
             FROM {org_client_access} a JOIN {orgs} o ON o.id = a.org_id
             LEFT JOIN {roles} r ON r.id = a.role_id
             WHERE a.client_id = ? ORDER BY o.name', [$clientDbId]);
    }

    public static function grantClient(int $orgId, int $clientDbId, ?int $roleId, ?int $grantedBy): void
    {
        $exists = DB::row('SELECT 1 AS x FROM {org_client_access} WHERE org_id = ? AND client_id = ?',
            [$orgId, $clientDbId]);
        if ($exists) {
            DB::update('org_client_access', ['role_id' => $roleId], 'org_id = ? AND client_id = ?',
                [$orgId, $clientDbId]);
            return;
        }
        DB::insert('org_client_access', [
            'org_id' => $orgId, 'client_id' => $clientDbId, 'role_id' => $roleId,
            'granted_by' => $grantedBy, 'created_at' => now(),
        ]);
    }

    public static function revokeClient(int $orgId, int $clientDbId): void
    {
        DB::run('DELETE FROM {org_client_access} WHERE org_id = ? AND client_id = ?', [$orgId, $clientDbId]);
    }

    /**
     * Есть ли у пользователя доступ к порталу через какую-нибудь организацию.
     * Возвращает role_id для выдачи или false.
     */
    public static function accessVia(int $userId, int $clientDbId): array|false
    {
        /* Вход не должен зависеть от того, применена ли миграция 005 */
        if (!self::ready()) return false;
        $row = DB::row('SELECT a.role_id, o.name, o.slug FROM {org_members} m
             JOIN {orgs} o ON o.id = m.org_id AND o.is_active = 1
             JOIN {org_client_access} a ON a.org_id = m.org_id
             WHERE m.user_id = ? AND a.client_id = ? LIMIT 1', [$userId, $clientDbId]);
        return $row ?: false;
    }
}
