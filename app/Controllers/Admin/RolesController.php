<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\ErrorPage;
use App\Core\Flash;
use App\Core\View;
use App\Models\Client;
use App\Models\Role;

/** /admin/roles: глобальные и скоуп-роли порталов (§9.2). */
final class RolesController extends AdminBase
{
    public function index(): never
    {
        $this->requirePerm('roles.read');
        $scope = $this->scopeClientIds();
        $roles = Role::all();
        if ($scope !== null) {
            $roles = array_values(array_filter($roles,
                fn($r) => $r['client_id'] !== null && in_array((int)$r['client_id'], $scope, true)));
        }
        /* Сколько людей у каждой роли — одним запросом на весь список.
           Было по запросу на строку: сорок ролей — сорок один запрос. Ролей
           становится больше с каждым подключённым сервисом, то есть эта цена
           растёт ровно там, где растёт установка. */
        $counts = array_column(\App\Core\DB::all(
            'SELECT role_id, COUNT(*) AS n FROM {user_roles} GROUP BY role_id'), 'n', 'role_id');
        foreach ($roles as &$r) {
            $r['users_count'] = (int)($counts[(int)$r['id']] ?? 0);
        }
        unset($r);

        /* Раскладываем по владельцам ЗДЕСЬ, а не в шаблоне: десять сервисов со
           своими ролями превращали страницу в ленту на несколько экранов, где
           системные роли тонули между чужими. Системные — отдельно и всегда на
           виду; роли сервиса — своей группой, свёрнутой по умолчанию. */
        $system = [];
        $byClient = [];
        foreach ($roles as $r) {
            if ($r['client_id'] === null) { $system[] = $r; continue; }
            $byClient[(int)$r['client_id']]['name'] = (string)$r['client_name'];
            $byClient[(int)$r['client_id']]['roles'][] = $r;
        }
        /* Сервисы по алфавиту: порядок «как в базе» меняется от установки к
           установке, и найти нужный в нём нельзя. */
        uasort($byClient, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        View::render('admin/roles', [
            'pageTitle' => t('Роли'),
            'roles' => $roles,
            'system' => $system,
            'byClient' => $byClient,
            'clients' => $scope === null ? Client::all()
                : array_values(array_filter(Client::all(), fn($c) => in_array((int)$c['id'], $scope, true))),
            'isGlobalAdmin' => $scope === null,
        ]);
    }

    /**
     * Карточка роли: таблица прав и уровень доступа (v4.3).
     *
     * Права показываются сеткой «область × действие» — это ровно rwx, и
     * человек видит всю раскладку разом, а не догадывается по названию роли.
     */
    public function card(string $id): never
    {
        $this->requirePerm('roles.read');
        $this->requireGlobalAdmin();
        $role = Role::find((int)$id);
        if (!$role) ErrorPage::render(404);
        View::render('admin/role_card', [
            'pageTitle' => $role['name'],
            'role' => $role,
            'perms' => Role::permissions((int)$role['id']),
            'usersCount' => Role::usersCount((int)$role['id']),
            /* superadmin — единственная роль без галочек: ему разрешено всё,
               и возможность снять права с себя означала бы запертую систему. */
            'locked' => $role['slug'] === 'superadmin' && $role['client_id'] === null,
        ]);
    }

    public function savePermissions(string $id): never
    {
        $this->requirePerm('roles.write');
        $this->requireGlobalAdmin();
        $role = Role::find((int)$id);
        if (!$role) ErrorPage::render(404);
        if ($role['slug'] === 'superadmin' && $role['client_id'] === null) {
            Flash::error('Права суперадмина не редактируются: снять их с себя значило бы запереть систему.');
            redirect('/admin/roles/' . $role['id']);
        }

        $sent = array_values(array_filter((array)($_POST['perms'] ?? []), 'is_string'));
        $perms = \App\Core\Permission::withImplied($sent);
        Role::setPermissions((int)$role['id'], $perms);
        Role::setLevel((int)$role['id'], (int)($_POST['access_level'] ?? 20));

        Audit::log('admin.role.permissions', ['meta' => [
            'role' => $role['slug'], 'perms' => $perms,
            'level' => (int)($_POST['access_level'] ?? 20),
        ]]);
        /* Дописанное чтение показываем, а не скрываем: человек должен понимать,
           почему галочек стало больше, чем он поставил. */
        $added = array_values(array_diff($perms, $sent));
        Flash::success($added === []
            ? t('Права роли сохранены.')
            : t('Права сохранены. Добавлено чтение области, без которого изменение не имеет смысла: :list.', ['list' => implode(', ', $added)]));
        redirect('/admin/roles/' . $role['id']);
    }

    public function create(): never
    {
        $this->requirePerm('roles.write');
        $clientDbId = (int)($_POST['client_id'] ?? 0) ?: null;
        if ($clientDbId === null) {
            $this->requireGlobalAdmin();
        } else {
            $this->assertClientScope($clientDbId);
            if (!Client::find($clientDbId)) ErrorPage::render(404);
        }
        $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        if (!preg_match('~^[a-z0-9_-]{2,50}$~', $slug) || $name === '') {
            Flash::error(t('Слаг: 2–50 символов (a-z, 0-9, -, _); имя обязательно.'));
            redirect(back_to('/admin/roles'));
        }
        if (Role::findBySlug($slug, $clientDbId)) {
            Flash::error(t('Такая роль уже есть в этом скоупе.'));
            redirect(back_to('/admin/roles'));
        }
        $rid = Role::create($clientDbId, $slug, $name, trim((string)($_POST['description'] ?? '')) ?: null);
        /* Уровень по тому же правилу, что и у роли, приехавшей из портала:
           «editor» — это редактор, кто бы его ни завёл. Незнакомое имя
           получает двадцатку, и поднять её может только человек (ROLES.md §4). */
        if ($clientDbId !== null) {
            Role::setLevel($rid, \App\Core\RoleCatalog::levelForIncoming($slug));
            Role::setPermissions($rid, []);   // роль портала прав SSO не несёт
        }
        Audit::log('admin.role.create', ['client' => $clientDbId, 'meta' => ['slug' => $slug]]);
        Flash::success(t('Роль создана.'));
        redirect(back_to('/admin/roles'));
    }

    public function delete(string $id): never
    {
        $this->requirePerm('roles.delete');
        $role = Role::find((int)$id);
        if (!$role) ErrorPage::render(404);
        if ($role['client_id'] === null) {
            $this->requireGlobalAdmin();
        } else {
            $this->assertClientScope((int)$role['client_id']);
        }
        if (!Role::delete((int)$role['id'])) {
            Flash::error(t('Системные роли (:slug) не удаляются.', ['slug' => $role['slug']]));
        } else {
            Audit::log('admin.role.delete', ['client' => $role['client_id'] !== null ? (int)$role['client_id'] : null,
                'meta' => ['slug' => $role['slug']]]);
            Flash::success(t('Роль удалена.'));
        }
        redirect(back_to('/admin/roles'));
    }
}
