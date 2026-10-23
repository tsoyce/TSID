<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Справочник ролей: какие бывают и что каждая значит (v4.39).
 *
 * Роли делятся на ДВЕ СЕМЬИ, и путать их нельзя — именно на этом ломаются
 * типовые «списки ролей для SSO», где `SUPER_ADMIN` стоит рядом с `EDITOR`:
 *
 *   · роли УПРАВЛЕНИЯ — отвечают на вопрос «что человеку можно в самом SSO».
 *     Несут права (`users.write`, `logs.read`, …), и каждое из них что-то
 *     разрешает здесь, у нас;
 *
 *   · роли ПОРТАЛА — не значат внутри SSO ничего. Это метка, которую мы
 *     доносим до сервиса в claim `roles`, а решает по ней он сам. Прав SSO у
 *     них нет и быть не должно: «редактор» на чужом сайте не даёт права
 *     блокировать людей у нас.
 *
 * Общего у семей одно — ЛЕСТНИЦА УРОВНЕЙ (AccessLevel::TIERS). Она уже
 * содержала «редактора», «модератора», «выпускающего» и «наблюдателя»: ступени
 * были, ролей на них не было. Уровень нужен, чтобы роли можно было сравнивать
 * между собой, не зная, что они значат: кто выше, тот и старше.
 *
 * Область выдачи (система / организация / сервис) — третье измерение и живёт
 * отдельно, в Scope. Одна и та же роль выдаётся в разных областях и от этого
 * меняет охват, а не смысл (SCOPES §2).
 */
final class RoleCatalog
{
    /** Роль управления: несёт права в самом SSO. */
    public const FAMILY_CONTROL = 'control';
    /** Роль портала: метка для сервиса, прав в SSO не несёт. */
    public const FAMILY_PORTAL = 'portal';

    /**
     * Что можно заводить и с каким уровнем.
     *
     * Список намеренно короткий. Роль в справочнике оправдана только тогда,
     * когда за ней стоит либо набор прав SSO, либо ступень, по которой портал
     * реально ветвится. `BILLING_ADMIN` и `ANALYST` из типовых списков сюда не
     * попали: биллинга и аналитики в системе нет, и роль без содержания — это
     * обещание, которого никто не выполнит.
     *
     * @var array<string, array{level:int, family:string, scopes:list<string>, title:string, desc:string, perms:list<string>}>
     */
    public const ROLES = [
        /* ── Система: кто управляет самим SSO ─────────────────────────────── */
        'superadmin' => [
            'level' => 91, 'family' => self::FAMILY_CONTROL, 'scopes' => [Scope::SYSTEM],
            'title' => 'Суперадмин',
            'desc' => 'Полный доступ: ключи подписи, системный раздел, вход от имени.',
            'perms' => ['*'],
        ],
        'auditor' => [
            'level' => 90, 'family' => self::FAMILY_CONTROL, 'scopes' => [Scope::SYSTEM],
            'title' => 'Аудитор',
            'desc' => 'Видит всё, не меняет ничего: журнал, сессии, настройки — только чтение.',
            'perms' => ['logs.read', 'settings.read', 'users.read', 'clients.read', 'orgs.read', 'roles.read'],
        ],
        'admin' => [
            'level' => 70, 'family' => self::FAMILY_CONTROL, 'scopes' => [Scope::SYSTEM],
            'title' => 'Администратор',
            'desc' => 'Люди, сервисы, организации, настройки. Без ключей и без системного раздела.',
            'perms' => ['users.read', 'users.write', 'users.delete', 'clients.read', 'clients.write',
                        'orgs.read', 'orgs.write', 'roles.read', 'logs.read', 'settings.read',
                        'impersonate', 'housekeeping'],
        ],
        'support' => [
            'level' => 50, 'family' => self::FAMILY_CONTROL, 'scopes' => [Scope::SYSTEM],
            'title' => 'Поддержка',
            'desc' => 'Смотрит карточки людей и заходит от их имени, чтобы воспроизвести жалобу. Ничего не правит.',
            /* Уровень 50 не украшение: войти от имени можно только к тому, кто
               НЕ выше тебя. Поддержка с уровнем администратора смогла бы войти
               от имени администратора — это уже не поддержка. */
            'perms' => ['users.read', 'impersonate'],
        ],

        /* ── Организация: кто распоряжается своей ──────────────────────────── */
        'org_owner' => [
            'level' => 80, 'family' => self::FAMILY_CONTROL, 'scopes' => [Scope::ORG],
            'title' => 'Владелец организации',
            'desc' => 'Полный распорядитель своей организации: состав, сервисы, роли внутри неё.',
            'perms' => ['users.read', 'users.write', 'clients.read', 'clients.write', 'orgs.read', 'orgs.write'],
        ],
        'org_admin' => [
            'level' => 75, 'family' => self::FAMILY_CONTROL, 'scopes' => [Scope::ORG],
            'title' => 'Администратор организации',
            'desc' => 'Ведёт сервисы организации. Состав людей не меняет.',
            'perms' => ['users.read', 'clients.read', 'clients.write', 'orgs.read'],
        ],
        'org_auditor' => [
            'level' => 50, 'family' => self::FAMILY_CONTROL, 'scopes' => [Scope::ORG],
            'title' => 'Аудитор организации',
            'desc' => 'Журнал организации и её состав — только чтение.',
            'perms' => ['orgs.read', 'logs.read', 'users.read'],
        ],

        /* ── Сервис: делегированное администрирование ──────────────────────── */
        'client_admin' => [
            'level' => 70, 'family' => self::FAMILY_CONTROL, 'scopes' => [Scope::CLIENT],
            'title' => 'Админ портала',
            'desc' => 'Правит СВОЙ сервис: реквизиты, карту ролей, людей. Границу держит область выдачи.',
            'perms' => ['users.read', 'clients.read', 'clients.write'],
        ],

        /* ── Роли портала: метки, которые едут в claim `roles` ─────────────── */
        'publisher' => [
            'level' => 65, 'family' => self::FAMILY_PORTAL, 'scopes' => [Scope::CLIENT, Scope::ORG],
            'title' => 'Выпускающий',
            'desc' => 'Редактор плюс право выпустить: публикация, отправка в эфир, релиз.',
            'perms' => [],
        ],
        'editor' => [
            'level' => 60, 'family' => self::FAMILY_PORTAL, 'scopes' => [Scope::CLIENT, Scope::ORG],
            'title' => 'Редактор',
            'desc' => 'Создаёт и правит содержимое сервиса — своё и чужое.',
            'perms' => [],
        ],
        'moderator' => [
            'level' => 55, 'family' => self::FAMILY_PORTAL, 'scopes' => [Scope::CLIENT, Scope::ORG],
            'title' => 'Модератор',
            'desc' => 'Разбирает чужое: одобряет, скрывает, обрабатывает жалобы. Сам обычно не пишет.',
            'perms' => [],
        ],
        'viewer' => [
            'level' => 50, 'family' => self::FAMILY_PORTAL, 'scopes' => [Scope::CLIENT, Scope::ORG],
            'title' => 'Наблюдатель',
            'desc' => 'Видит всё в сервисе, не нажимает ничего. Для аудита, стажёра, демонстрации.',
            'perms' => [],
        ],
        'user' => [
            'level' => 20, 'family' => self::FAMILY_PORTAL, 'scopes' => [Scope::SYSTEM, Scope::ORG, Scope::CLIENT],
            'title' => 'Пользователь',
            'desc' => 'Обычный человек: свой профиль и своё содержимое. Роль по умолчанию.',
            'perms' => [],
        ],
        'guest' => [
            'level' => 10, 'family' => self::FAMILY_PORTAL, 'scopes' => [Scope::CLIENT, Scope::ORG],
            'title' => 'Гость',
            'desc' => 'Ограниченный доступ: посмотреть то, ради чего позвали. Обычно на срок.',
            'perms' => [],
        ],
    ];

    /**
     * Верх лестницы сервиса — «Администратор сервиса» (AccessLevel::TIERS).
     * Выше живут ступени управления самим SSO, и роль портала их не получает
     * никогда, как бы она ни называлась.
     */
    public const SERVICE_CEILING = 70;

    /**
     * Как порталы называют своего администратора. Это верх ЕГО лестницы, а не
     * наша роль `admin`: у нас она управляет SSO, у него — только его сервисом.
     */
    private const SERVICE_ADMIN_NAMES = ['admin', 'administrator', 'superadmin', 'owner'];

    /**
     * Уровень для роли, ПРИЕХАВШЕЙ снаружи.
     *
     * Портал сообщает свои роли сам, и слаги у него какие угодно. Известное имя
     * из справочника получает свою ступень — «editor» и есть редактор, что бы
     * его ни окружало. Имя администратора получает верх лестницы сервиса, 70.
     * Незнакомое — уровень обычного пользователя: придумать себе ступень чужая
     * метка не может. Поднять её может только человек, руками.
     *
     * До v4.61 «admin» портала тоже получал 20. Правило держалось на том, что
     * уровень роли портала входил в общий уровень человека, и чужая метка
     * «admin» дала бы старшинство у нас. Теперь не входит
     * ({@see \App\Models\Role::levelOfUser}), а двадцатка у администратора
     * ломала сам портал: выданный в SSO «admin» Архива приезжал к нему
     * обычным пользователем.
     */
    public static function levelForIncoming(string $slug): int
    {
        $slug = mb_strtolower(trim($slug));
        /* Приставка сервиса в слаге — обычное дело («blog_editor», «cv-admin»).
           Смотрим и на хвост, но только на точное совпадение со ступенью. */
        $tail = (string)preg_replace('~^.*[_.:-]~', '', $slug);
        foreach ([$slug, $tail] as $candidate) {
            if (isset(self::ROLES[$candidate])
                && self::ROLES[$candidate]['family'] === self::FAMILY_PORTAL) {
                return self::ROLES[$candidate]['level'];
            }
            if (in_array($candidate, self::SERVICE_ADMIN_NAMES, true)) return self::SERVICE_CEILING;
        }
        return self::ROLES['user']['level'];
    }

    /** Права роли из справочника; `*` разворачивается в полный список. */
    public static function permsOf(string $slug): array
    {
        $row = self::ROLES[$slug] ?? null;
        if ($row === null) return [];
        return $row['perms'] === ['*'] ? Permission::all() : $row['perms'];
    }
}
