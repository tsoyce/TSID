<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Разрешение доступа по областям (v4.23, фаза 1).
 *
 * Три вопроса, все — над таблицей покрытия из {@see Scope}:
 *
 *   roles(человек, область)   — какие роли действуют
 *   perms(человек, область)   — какие права складываются
 *   level(человек, портал)    — какое число уезжает в токен
 *
 * **Сложение, а не замещение.** Права объединяются, уровень берётся
 * наибольший. Замещение («узкая область отменяет широкую») выразительнее, но
 * даёт молчаливую ловушку: человек с ролью администратора организации и любой
 * мелкой ролью на одном портале потерял бы там администратора, ничего об этом
 * не узнав. Цена выбора честная и записана в SCOPES §2.2: понижение в
 * отдельном проекте не выражается. Появится случай — добавится запрещающая
 * выдача, одно поле и одна строка здесь.
 *
 * **Пока ничего не переключено.** Фаза 1 добавляет механизм и доказывает
 * проверками, что он считает то же, что старый код. Перевод — в фазах 2–4.
 */
final class AccessResolver
{
    /** @var array<int, int|null> портал → его организация */
    private static array $orgOfClient = [];

    /**
     * Готова ли схема. Колонки появляются миграцией 020, и до неё резолвер
     * обязан молчать, а не падать: приложение переживает неприменённую
     * миграцию — это отдельно оговорённое свойство (v3.4).
     */
    public static function ready(): bool
    {
        return DB::columnExists('roles', 'scope_type')
            && DB::columnExists('user_roles', 'scope_type')
            && DB::columnExists('clients', 'org_id');
    }

    /** Организация портала. Кэш на запрос: спрашивают на каждый токен. */
    public static function orgOfClient(int $clientDbId): ?int
    {
        if (array_key_exists($clientDbId, self::$orgOfClient)) {
            return self::$orgOfClient[$clientDbId];
        }
        $v = null;
        if (self::ready()) {
            $raw = DB::val('SELECT org_id FROM {clients} WHERE id = ?', [$clientDbId]);
            $v = $raw === null ? null : (int)$raw;
        }
        return self::$orgOfClient[$clientDbId] = $v;
    }

    /** Сбросить кэш — нужен проверкам и переносу базы. */
    public static function forget(): void
    {
        self::$orgOfClient = [];
    }

    /**
     * Роли человека, действующие в области.
     *
     * @return list<array> строки {roles} с полями выдачи `granted_scope_*`
     */
    public static function rolesFor(int $userId, Scope $scope): array
    {
        if (!self::ready()) return [];

        $rows = DB::all(
            'SELECT r.*, ur.scope_type AS granted_scope_type, ur.scope_id AS granted_scope_id
               FROM {user_roles} ur
               JOIN {roles} r ON r.id = ur.role_id
              WHERE ur.user_id = ?', [$userId]);

        /* Организацию портала спрашиваем один раз на вызов, а не на строку. */
        $targetOrgId = $scope->is(Scope::CLIENT) && $scope->id !== null
            ? self::orgOfClient($scope->id)
            : null;

        $out = [];
        foreach ($rows as $row) {
            /* Ноль и NULL означают одно — «идентификатора нет»: в базе лежит
               ноль (он входит в первичный ключ), а Scope знает про null.
               Читаем оба, чтобы установка, застрявшая между миграциями, не
               получала область `client:0`, не покрывающую ничего. */
            $rawId = $row['granted_scope_id'];
            $granted = Scope::of((string)$row['granted_scope_type'],
                ($rawId === null || (int)$rawId === 0) ? null : (int)$rawId);
            if ($granted->covers($scope, $targetOrgId)) $out[] = $row;
        }
        return $out;
    }

    /**
     * Уровень доступа в портале.
     *
     * Пол — уровень базовой роли `user`: живой человек не бывает нулём, а ноль
     * по нашей шкале означает «заблокирован». Статус старше ролей и разбирается
     * выше, в {@see AccessLevel}; сюда приходят уже живые.
     */
    public static function levelFor(int $userId, int $clientDbId): int
    {
        if (!self::ready()) return 0;
        $level = 0;
        foreach (self::rolesFor($userId, Scope::client($clientDbId)) as $r) {
            /* Роль чужого портала здесь не значит ничего, в какой бы области её
               ни выдали, — как и в Role::levelOfUser: две сверки уровня обязаны
               сходиться (v4.61). */
            if ($r['client_id'] !== null && (int)$r['client_id'] !== $clientDbId) continue;
            $level = max($level, (int)$r['access_level']);
        }
        return $level;
    }

    /**
     * Права человека в области — объединение прав действующих ролей.
     *
     * @return list<string>
     */
    public static function permsFor(int $userId, Scope $scope): array
    {
        $roles = self::rolesFor($userId, $scope);
        if ($roles === []) return [];

        $ids = array_map(static fn(array $r): int => (int)$r['id'], $roles);
        $rows = DB::all('SELECT DISTINCT perm FROM {role_permissions} WHERE role_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        return array_values(array_column($rows, 'perm'));
    }
}
