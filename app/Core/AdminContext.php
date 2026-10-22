<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Org;

/**
 * Где администратор сейчас работает: вся система или одна организация (v4.26).
 *
 * Контекст — это ФИЛЬТР, а не выдача прав. Он сужает то, что человек видит, и
 * никогда не расширяет: выбрав организацию, глобальный админ перестаёт видеть
 * чужие списки, но выбрав «Все организации», админ одной организации всё равно
 * не увидит ничего сверх своей. Права остаются за областями выдач (SCOPES §2),
 * контекст лишь наводит на них объектив.
 *
 * Зачем: искать имя в списке на тысячу человек и в списке на сорок — разные
 * задачи. И это не только про глаза: сужение превращается в `WHERE org_id = ?`
 * вместо полного перебора, то есть в нагрузку на базу, которая перестаёт расти
 * вместе с экосистемой.
 *
 * Живёт в сессии, а не в адресе: контекст сопровождает человека по всем
 * разделам, и таскать его параметром в каждой ссылке значило бы однажды его
 * потерять — на кнопке «назад», в закладке, в письме поддержки. Адрес при этом
 * остаётся тем же самым, и ссылкой на конкретный объект по-прежнему можно
 * поделиться.
 */
final class AdminContext
{
    private const KEY = 'admin_scope';

    /** Текущий контекст. По умолчанию — корень: «все организации». */
    public static function current(): Scope
    {
        $raw = (string)($_SESSION[self::KEY] ?? '');
        $scope = $raw === '' ? Scope::system() : Scope::parse($raw);

        /* Организация могла исчезнуть, пока человек в ней «стоял», — тогда
           контекст молча возвращается в корень. Иначе он показывал бы пустоту
           и не давал переключиться: списки-то пустые. */
        if ($scope->is(Scope::ORG) && ($scope->id === null || Org::find($scope->id) === null)) {
            unset($_SESSION[self::KEY]);
            return Scope::system();
        }
        return $scope;
    }

    /**
     * Переключиться. Возвращает false, если такой области человеку не видно —
     * тогда контекст не меняется вовсе.
     */
    public static function set(Scope $scope): bool
    {
        foreach (self::available() as $option) {
            if (!$option->equals($scope)) continue;
            if ($scope->is(Scope::SYSTEM)) unset($_SESSION[self::KEY]);
            else $_SESSION[self::KEY] = $scope->key();
            return true;
        }
        return false;
    }

    /**
     * Куда этот человек может переключиться.
     *
     * Корень — только тем, у кого полномочия на всю установку: для остальных
     * «все организации» означало бы ровно их собственные, и пункт лгал бы.
     *
     * @return list<Scope>
     */
    public static function available(): array
    {
        if (!Auth::check()) return [];

        $out = [];
        if (Auth::isAdmin()) $out[] = Scope::system();
        foreach (self::orgIds() as $orgId) $out[] = Scope::org($orgId);

        /* Ни одной организации и не глобальный админ — переключать нечего:
           человек и так видит ровно своё. */
        return $out;
    }

    /** Организации, доступные человеку: свои по членству или все — глобальному. */
    private static function orgIds(): array
    {
        if (!Org::ready()) return [];
        if (Auth::isAdmin()) {
            return array_map(static fn(array $o): int => (int)$o['id'], Org::all());
        }
        return array_map(static fn(array $o): int => (int)$o['id'], Org::forUser((int)Auth::id()));
    }

    /**
     * Порталы, попадающие в текущий контекст, или null — «без сужения».
     *
     * Складывается с полномочиями, а не заменяет их: {@see AdminBase} сначала
     * спрашивает, что человеку вообще можно, и лишь потом сужает контекстом.
     *
     * @return list<int>|null
     */
    public static function clientIds(): ?array
    {
        $scope = self::current();
        if ($scope->is(Scope::SYSTEM)) return null;
        if (!DB::columnExists('clients', 'org_id')) return null;
        return array_map(static fn(array $c): int => (int)$c['id'],
            DB::all('SELECT id FROM {clients} WHERE org_id = ?', [$scope->id]));
    }

    /** Подпись для шапки: имя организации или «Все организации». */
    public static function label(?Scope $scope = null): string
    {
        $scope = $scope ?? self::current();
        if ($scope->is(Scope::SYSTEM)) return t('Все организации');
        $org = $scope->id === null ? null : Org::find($scope->id);
        return $org === null ? t('Организация') : (string)$org['name'];
    }
}
