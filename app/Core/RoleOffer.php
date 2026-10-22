<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Role;

/**
 * Какие роли имеет смысл выдать ЗДЕСЬ (v4.50).
 *
 * Выпадающий список выдачи собирался запросом «свои роли плюс системные» и
 * вырастал до десятка с лишним пунктов, среди которых были и прямые дубли, и
 * роли, которым в этой области делать нечего. На живом портале это выглядело
 * так: `moderator, admin, user, superadmin, auditor, org_owner, org_admin,
 * admin, client_admin, org_auditor, support, user` — двенадцать пунктов, из
 * них `admin` и `user` по два раза, а семь штук вообще из чужих областей.
 *
 * Систематизация опирается на то, что в проекте уже объявлено, и ничего не
 * выдумывает поверх:
 *
 *   1. У КАЖДОЙ роли справочника перечислены области, где она осмысленна
 *      (RoleCatalog::ROLES[…]['scopes']). `superadmin` живёт только в системе,
 *      `org_owner` — только в организации. Предлагать их в области сервиса —
 *      это и есть «миллион ролей»: выдать их там нельзя, а читать приходится.
 *
 *   2. У сервиса есть СВОЙ словарь ролей — строки `roles` с его client_id.
 *      Это то, чем он на самом деле пользуется.
 *
 *   3. Портал рассказывает о себе сам: `client_roles_seen` (что он назвал
 *      манифестом) и `client_role_map` (что ему сопоставили руками). Роль,
 *      о которой портал не слышал, — метка, которую никто не прочтёт.
 *
 * Отсюда правило для области сервиса: свои роли, плюс `client_admin` (она
 * значит что-то в САМОМ SSO и потому уместна всегда), плюс те роли
 * справочника, которые портал признаёт. Совпали слаги — побеждает своя: если
 * у сервиса есть собственный `admin`, системный `admin` рядом с ним не нужен,
 * это один и тот же «админ» глазами человека.
 */
final class RoleOffer
{
    /**
     * Роли к выдаче в этой области — уже без дублей и без чужого.
     *
     * @return list<array<string,mixed>> строки `roles` плюс `own` и `why`
     */
    public static function inScope(Scope $scope): array
    {
        $rows = match ($scope->type) {
            Scope::CLIENT => self::forClient((int)$scope->id),
            Scope::ORG    => self::forOrg((int)$scope->id),
            default       => self::forSystem(),
        };

        /* Порядок — по старшинству, как и везде в продукте: сверху тот, кто
           главнее. При равном уровне — по слагу, чтобы список не прыгал. */
        usort($rows, static function (array $a, array $b): int {
            $byLevel = (int)$b['access_level'] <=> (int)$a['access_level'];
            return $byLevel !== 0 ? $byLevel : strcmp((string)$a['slug'], (string)$b['slug']);
        });
        return $rows;
    }

    /**
     * Можно ли выдать здесь именно эту роль.
     *
     * Проверка серверная и обязательная: список рисуется в разметке, а
     * приходит POST — спрятать пункт и считать вопрос закрытым значит
     * охранять дверь, повесив занавеску.
     */
    public static function allows(Scope $scope, int $roleId): bool
    {
        foreach (self::inScope($scope) as $r) {
            if ((int)$r['id'] === $roleId) return true;
        }
        return false;
    }

    /**
     * Кому что выдано в этой области, сгруппированное по человеку.
     *
     * Один запрос на страницу: людей в списке бывает до двухсот, и запрос на
     * строку здесь — это двести запросов (§8).
     *
     * @return array<int, list<array<string,mixed>>>
     */
    public static function grantsByUser(Scope $scope): array
    {
        $out = [];
        foreach (Role::grantsInScope($scope) as $g) {
            $out[(int)$g['user_id']][] = $g;
        }
        return $out;
    }

    /**
     * Почему список именно такой — словами, для подписи под органом.
     *
     * Короткий список без объяснения читается как поломка: «а где остальные
     * роли?». Объяснение стоит там же, где список, и говорит, что делать.
     */
    public static function why(Scope $scope): string
    {
        if ($scope->type !== Scope::CLIENT) {
            return t('Показаны роли, которые действуют в этой области.');
        }
        $cid = (int)$scope->id;
        $own = count(Role::forClient($cid));

        /* Портал назвал роль, а строки для неё ещё нет — выдать нечего, и
           молчать об этом нельзя: человек видит короткий список и решает, что
           система потеряла роль, о которой портал точно рассказывал. */
        $pending = self::declaredButMissing($cid);
        if ($pending !== []) {
            return t('Портал называл роли, которых здесь ещё нет: :list. Завести — в разделе «Роли».',
                ['list' => implode(', ', array_slice($pending, 0, 5))]);
        }
        if ($own === 0) {
            return t('У сервиса пока нет своих ролей. Заведите их в разделе «Роли» или спросите портал о его ролях — тогда они появятся здесь.');
        }
        return t('Только роли этого сервиса: чужие метки портал всё равно не прочтёт.');
    }

    /**
     * О чём портал рассказал, а роли под это в системе нет.
     *
     * @return list<string> слаги
     */
    public static function declaredButMissing(int $clientDbId): array
    {
        if (!PortalManifest::ready()) return [];
        $have = [];
        foreach (self::inScope(Scope::client($clientDbId)) as $r) {
            $have[mb_strtolower((string)$r['slug'])] = true;
        }
        $out = [];
        foreach (DB::all('SELECT slug FROM {client_roles_seen} WHERE client_id = ? ORDER BY slug',
                 [$clientDbId]) as $r) {
            $slug = mb_strtolower((string)$r['slug']);
            if (!isset($have[$slug])) $out[] = $slug;
        }
        return $out;
    }

    /* ---------- Области ---------- */

    /** @return list<array<string,mixed>> */
    private static function forSystem(): array
    {
        $rows = DB::all('SELECT * FROM {roles} WHERE client_id IS NULL');
        return array_values(array_filter($rows, static fn(array $r): bool
            => self::catalogAllows((string)$r['slug'], Scope::SYSTEM)));
    }

    /** @return list<array<string,mixed>> */
    private static function forOrg(int $orgId): array
    {
        $rows = DB::all('SELECT * FROM {roles} WHERE client_id IS NULL');
        return array_values(array_filter($rows, static fn(array $r): bool
            => self::catalogAllows((string)$r['slug'], Scope::ORG)));
    }

    /** @return list<array<string,mixed>> */
    private static function forClient(int $clientDbId): array
    {
        /* 1. Свой словарь сервиса — он главнее всего. */
        $out = [];
        foreach (DB::all('SELECT * FROM {roles} WHERE client_id = ?', [$clientDbId]) as $r) {
            $r['own'] = true;
            $out[mb_strtolower((string)$r['slug'])] = $r;
        }

        $known = self::knownToPortal($clientDbId);

        /* 2. Системные роли — только те, что уместны в области сервиса И либо
              что-то значат в самом SSO, либо признаны порталом. */
        foreach (DB::all('SELECT * FROM {roles} WHERE client_id IS NULL') as $r) {
            $slug = mb_strtolower((string)$r['slug']);
            /* Совпал слаг со своей ролью — своя побеждает: для человека это
               один и тот же «админ», и два одинаковых пункта в списке он
               прочтёт как ошибку. */
            if (isset($out[$slug])) continue;
            if (!self::catalogAllows($slug, Scope::CLIENT)) continue;

            $control = (RoleCatalog::ROLES[$slug]['family'] ?? null) === RoleCatalog::FAMILY_CONTROL;
            if (!$control && !isset($known[$slug])) continue;

            $r['own'] = false;
            $out[$slug] = $r;
        }
        return array_values($out);
    }

    /**
     * Слаги, о которых портал знает: сам рассказал манифестом или ему
     * сопоставили руками в карте ролей.
     *
     * @return array<string,true>
     */
    private static function knownToPortal(int $clientDbId): array
    {
        $known = [];
        if (PortalManifest::ready()) {
            foreach (DB::all('SELECT slug FROM {client_roles_seen} WHERE client_id = ?',
                     [$clientDbId]) as $r) {
                $known[mb_strtolower((string)$r['slug'])] = true;
            }
        }
        if (DB::tableExists('client_role_map')) {
            foreach (DB::all('SELECT r.slug FROM {client_role_map} m
                               JOIN {roles} r ON r.id = m.role_id
                              WHERE m.client_id = ?', [$clientDbId]) as $r) {
                $known[mb_strtolower((string)$r['slug'])] = true;
            }
        }
        return $known;
    }

    /**
     * Разрешает ли справочник эту роль в этой области.
     *
     * Роли, которой в справочнике нет, не отказываем: её завели руками, и
     * решать за автора, где она уместна, здесь не из чего. Отсекаем только то,
     * про что справочник высказался прямо.
     */
    private static function catalogAllows(string $slug, string $scopeType): bool
    {
        $entry = RoleCatalog::ROLES[mb_strtolower($slug)] ?? null;
        if ($entry === null) return true;
        return in_array($scopeType, $entry['scopes'], true);
    }
}
