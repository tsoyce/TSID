<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\ErrorPage;

/**
 * Делегированное администрирование (§0, §9.2): глобальные admin/superadmin видят всё,
 * client_admin — только свой портал. Роут-флаг 'admin' пускает в зону, скоуп — здесь.
 */
abstract class AdminBase
{
    /**
     * Запомнить фильтры списка, чтобы «← к списку» из карточки вернул ровно туда,
     * откуда ушли (§5.2 роадмапа: фильтры не должны теряться).
     */
    protected function rememberListQuery(string $key): void
    {
        $q = array_filter($_GET, fn($v) => $v !== '' && $v !== null);
        $_SESSION['list_query'][$key] = $q === [] ? '' : http_build_query($q);
    }

    /** URL возврата к списку с восстановленными фильтрами. */
    protected function listBackUrl(string $key, string $base): string
    {
        $q = $_SESSION['list_query'][$key] ?? '';
        return $q !== '' ? $base . '?' . $q : $base;
    }

    /** null — глобальный админ (всё); массив id клиентов — скоуп client_admin'а. */
    /**
     * Порталы для СПИСКОВ: что человеку можно и на что он сейчас смотрит.
     *
     * Два разных сужения, и порядок между ними важен. Сначала полномочия: что
     * можно вообще. Потом контекст: на что смотрит. Контекст только сужает —
     * выбрав «все организации», админ одной организации не увидит ничего сверх
     * своей (SCOPES §4.1).
     */
    protected function scopeClientIds(): ?array
    {
        $allowed = $this->permittedClientIds();
        $context = \App\Core\AdminContext::clientIds();
        if ($context === null) return $allowed;
        if ($allowed === null) return $context;
        return array_values(array_intersect($allowed, $context));
    }

    /**
     * Условие «идентификатор в списке» — с честным ответом на пустой список.
     *
     * `IN ()` — синтаксическая ошибка и в MySQL, и в SQLite, а пустой список
     * получается легко: администратор выбрал организацию, у которой ещё нет
     * проектов. Собранное руками условие роняло страницу с «error in your SQL
     * syntax near '))'» — так и легла страница людей на боевой.
     *
     * Пустой список означает «не подходит ничего», а не «подходит всё»: второе
     * показало бы чужие данные. Отсюда `1 = 0`.
     */
    protected function inIds(string $column, array $ids): string
    {
        if ($ids === []) return '1 = 0';
        return $column . ' IN (' . implode(',', array_map('intval', $ids)) . ')';
    }

    /**
     * Порталы по ПОЛНОМОЧИЯМ, без оглядки на контекст.
     *
     * Спрашивается там, где объект уже назван: открыть карточку, сохранить,
     * удалить. Контекст туда не примешивается намеренно — он рамка обзора, а
     * не забор: иначе глобальный админ, выбравший организацию, получал бы 403
     * на ссылку в чужой проект, присланную ему в переписке. Ровно так и вышло
     * в v4.26, пока эти два вопроса задавались одним методом.
     */
    protected function permittedClientIds(): ?array
    {
        if (Auth::isAdmin()) return null;
        $ids = Auth::clientAdminIds();
        if ($ids === []) ErrorPage::render(403);
        return $ids;
    }

    protected function requireGlobalAdmin(): void
    {
        if (!Auth::isAdmin()) ErrorPage::render(403);
    }

    /**
     * Проверка права из таблицы ролей (v4.3).
     *
     * Флаг маршрута `admin` пускает в зону, а что именно человеку можно внутри
     * неё — решает эта проверка. Без неё таблица прав была бы декоративной:
     * галочки снимаются, а доступ остаётся. Суперадмину разрешено всё, поэтому
     * запереть себя, сняв галочки, нельзя.
     */
    protected function requirePerm(string $perm): void
    {
        if (!Auth::can($perm)) ErrorPage::render(403);
    }

    protected function requireSuperadmin(): void
    {
        Auth::requireSuperadmin();
    }

    /** Доступ к конкретному клиенту в рамках скоупа. */
    protected function assertClientScope(int $clientDbId): void
    {
        $scope = $this->permittedClientIds();
        if ($scope !== null && !in_array($clientDbId, $scope, true)) {
            ErrorPage::render(403);
        }
    }

    /** Пользователь виден админу? (client_admin — только пользователи его портала) */
    protected function assertUserScope(int $userId): void
    {
        $scope = $this->permittedClientIds();
        if ($scope === null) return;
        $in = \App\Core\DB::row(
            'SELECT 1 AS x FROM {user_client_access} WHERE user_id = ? AND '
            . $this->inIds('client_id', $scope), [$userId]);
        if (!$in) ErrorPage::render(403);
    }
}
