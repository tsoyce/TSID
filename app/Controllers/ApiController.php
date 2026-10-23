<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\RateLimiter;
use App\Core\SettingsTransfer;
use App\Models\ApiToken;
use App\Models\Client;
use App\Models\Role;
use App\Models\SessionModel;
use App\Models\User;
use App\Oidc\EventDispatcher;

/**
 * Admin API v1 — программное управление для скриптов и CI.
 * Аутентификация: `Authorization: Bearer sso_…`, права — по scope токена.
 * Все изменяющие действия попадают в аудит с actor = api:<имя токена>.
 */
final class ApiController
{
    /** Чем подменяется секрет в ответе. Записать это обратно нельзя. */
    private const MASK = '***';

    private array $token = [];

    /* ---------- Пользователи ---------- */

    public function usersList(): never
    {
        $this->auth('users:read');
        $q = trim((string)($_GET['q'] ?? ''));
        $status = (string)($_GET['status'] ?? '');
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        /* Тот же фильтр, что у списка людей в админке: удалённые по умолчанию
           не показываются ни там, ни здесь. `status=all` — если нужны все. */
        [$stWhere, $stParams] = User::statusWhere($status);
        $where = [$stWhere];
        $params = $stParams;
        if ($q !== '') {
            $where[] = '(email LIKE ? OR login LIKE ? OR public_id = ?)';
            array_push($params, '%' . $q . '%', '%' . $q . '%', $q);
        }
        $sql = $where === [] ? '1=1' : implode(' AND ', $where);
        $total = (int)DB::val("SELECT COUNT(*) FROM {users} WHERE $sql", $params);
        $rows = DB::all("SELECT * FROM {users} WHERE $sql ORDER BY id DESC LIMIT $limit OFFSET $offset", $params);

        $this->json([
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'items' => array_map([$this, 'userDto'], $rows),
        ]);
    }

    public function userGet(string $sub): never
    {
        $this->auth('users:read');
        $user = User::findByPublicId($sub);
        if (!$user) $this->error(404, 'not_found', 'Пользователь не найден');
        $dto = $this->userDto($user);
        $dto['roles'] = User::rolesClaim((int)$user['id'], null);
        $dto['sessions'] = count(SessionModel::activeForUser((int)$user['id']));
        $this->json($dto);
    }

    /**
     * GET /api/v1/users/by-number/{номер} — «ху из зу» со стороны портала.
     *
     * Человек написал в поддержку портала и назвал номер аккаунта — sub он
     * не знает и знать не может. Этот запрос связывает названное им с учётной
     * записью SSO. Номер принимается в любом виде записи; неверная
     * контрольная цифра отсекается до обращения к базе.
     */
    public function userByNumber(string $number): never
    {
        $this->auth('users:read');
        $user = User::findByNumber($number);
        if (!$user) $this->error(404, 'not_found', 'Пользователь не найден');
        $this->json($this->userDto($user));
    }

    /** GET /api/v1/users/by-email/{email} — то же по адресу, включая дополнительные. */
    public function userByEmail(string $email): never
    {
        $this->auth('users:read');
        /* Через UserEmail, а не по users.email: адрес, оставленный соцвходом,
           тоже принадлежит человеку, и портал вправе найти его по нему. */
        $user = \App\Models\UserEmail::findOwner(mb_strtolower(trim($email)));
        if (!$user) $this->error(404, 'not_found', 'Пользователь не найден');
        $this->json($this->userDto($user));
    }

    public function userCreate(): never
    {
        $this->auth('users:write');
        $body = $this->body();
        $email = mb_strtolower(trim((string)($body['email'] ?? '')));
        $login = trim((string)($body['login'] ?? ''));
        /* Хватает либо адреса, либо логина: людей переносят и из сервисов, где
           почты не спрашивали вовсе (см. §13.2). Кривой адрес по-прежнему
           отвергается — «нет» и «мусор» это разные ответы. */
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error(422, 'invalid_email', 'Поле email указано, но некорректно');
        }
        if ($email === '' && $login === '') {
            $this->error(422, 'invalid_identity', 'Нужен email или login — по чему-то человека надо узнавать');
        }
        if ($email !== '' && User::emailTaken($email)) $this->error(409, 'email_taken', 'Пользователь с таким email уже есть');
        if ($login !== '' && ($le = \App\Core\LoginName::check($login)) !== null) {
            $this->error(422, 'invalid_login', $le);
        }
        if ($login !== '' && User::loginTaken($login)) $this->error(409, 'login_taken', 'Пользователь с таким login уже есть');

        $id = User::create([
            'email' => $email ?: null,
            'first_name' => trim((string)($body['first_name'] ?? '')) ?: null,
            'last_name' => trim((string)($body['last_name'] ?? '')) ?: null,
            'login' => $login ?: null,
            'password_hash' => !empty($body['password'])
                ? password_hash((string)$body['password'], PASSWORD_ARGON2ID) : null,
            'email_verified_at' => !empty($body['email_verified']) ? now() : null,
            'status' => !empty($body['email_verified']) ? 'active' : 'pending',
        ]);
        \App\Core\Signup::grantDefaultRole($id);
        $user = User::find($id);
        $this->audit('api.user.create', ['on' => $id]);
        \App\Core\WebhookDispatcher::emit('user.created',
            ['sub' => $user['public_id'], 'email' => $email ?: null, 'via' => 'api']);
        $this->json($this->userDto($user), 201);
    }

    public function userUpdate(string $sub): never
    {
        $this->auth('users:write');
        $user = User::findByPublicId($sub);
        if (!$user) $this->error(404, 'not_found', 'Пользователь не найден');
        $body = $this->body();

        $data = [];
        foreach (['first_name', 'last_name', 'display_name', 'login'] as $field) {
            if (array_key_exists($field, $body)) $data[$field] = trim((string)$body[$field]) ?: null;
        }
        /* v4.97.2: логин при правке — по тем же правилам, что при создании:
           без них писался недопустимый, а занятый ронял запрос в 500. */
        if (($data['login'] ?? null) !== null) {
            $data['login'] = \App\Core\LoginName::normalize($data['login']);
            if (($le = \App\Core\LoginName::check($data['login'])) !== null) $this->error(422, 'invalid_login', $le);
            if (User::loginTaken($data['login'], (int)$user['id'])) $this->error(409, 'login_taken', 'Пользователь с таким login уже есть');
        }
        if (isset($body['status']) && in_array($body['status'], ['active', 'blocked'], true)) {
            if ($body['status'] === 'blocked' && \App\Core\UserBlock::forbidden((int)$user['id'])) {
                $this->error(403, 'forbidden', 'Суперадмина нельзя заблокировать');
            }
            $data['status'] = $body['status'];
        }
        $block = ($data['status'] ?? '') === 'blocked' && $user['status'] !== 'blocked';
        if ($block) unset($data['status']);
        if ($data !== []) User::update((int)$user['id'], $data);
        // Блокировка через API — та же, что в админке (UserBlock)
        if ($block) {
            \App\Core\UserBlock::apply($user, 'api');
            $data['status'] = 'blocked';
        }
        $this->audit('api.user.update', ['on' => (int)$user['id'], 'meta' => ['fields' => array_keys($data)]]);
        $this->json($this->userDto(User::find((int)$user['id'])));
    }

    public function userDelete(string $sub): never
    {
        $this->auth('users:write');
        $user = User::findByPublicId($sub);
        if (!$user) $this->error(404, 'not_found', 'Пользователь не найден');
        if (in_array('superadmin', User::globalRoleSlugs((int)$user['id']), true)) {
            $this->error(403, 'forbidden', 'Суперадмина нельзя удалить');
        }
        /* Окончательное удаление — вторым шагом и только для уже помеченных.
           В интерфейсе от промаха защищает набранное руками имя аккаунта; у
           bearer-токена такой защиты нет и быть не может, поэтому защитой
           работает сам порядок: один вызов пометит, второй сотрёт. Украденный
           токен не сносит живого человека одним запросом.

           Оно нужно не ради красоты: программа, заведшая пробные записи, обязана
           уметь убрать их насовсем, а не оставлять мусор до анонимизации. */
        if (!empty($_GET['purge'])) {
            if ((string)$user['status'] !== 'deleted') {
                $this->error(409, 'not_deleted',
                    'Сначала пометьте удалённым: DELETE без purge. Живого человека этот вызов не трогает.');
            }
            /* Порталы оповещаем ДО удаления: событие рассылается по строкам
               доступа, а purge() их сносит — после него рассылать некому. */
            EventDispatcher::userDeleted($user, 'purged');
            $removed = User::purge((int)$user['id']);
            \App\Core\Log::warning('api.user.purge', [
                'email' => $user['email'], 'sub' => $user['public_id'], 'removed' => $removed]);
            $this->audit('api.user.purge', ['meta' => ['sub' => $sub, 'removed' => $removed]]);
            $this->json(['purged' => true, 'removed' => $removed]);
        }
        User::update((int)$user['id'], ['status' => 'deleted', 'deleted_at' => now()]);
        SessionModel::revokeAllForUser((int)$user['id']);
        EventDispatcher::userDeleted($user, 'scheduled');
        $this->audit('api.user.delete', ['on' => (int)$user['id']]);
        $this->json(['deleted' => true, 'anonymize_after_days' => 14,
                     'purge_hint' => 'DELETE /users/' . $sub . '?purge=1 — стереть насовсем']);
    }

    /* ---------- Порталы ---------- */

    public function clientsList(): never
    {
        $this->auth('clients:read');
        $this->json(['items' => array_map(fn($c) => [
            'client_id' => $c['client_id'],
            'name' => $c['name'],
            'slug' => $c['slug'],
            'base_url' => $c['base_url'],
            'access_policy' => $c['access_policy'],
            'is_active' => (bool)$c['is_active'],
            'users' => Client::usersCount((int)$c['id']),
        ], Client::all())]);
    }

    public function clientCreate(): never
    {
        $this->auth('clients:write');
        $body = $this->body();
        $name = trim((string)($body['name'] ?? ''));
        $baseUrl = rtrim(trim((string)($body['base_url'] ?? '')), '/');
        if ($name === '' || !preg_match('~^https?://[^\s]+$~', $baseUrl)) {
            $this->error(422, 'invalid_request', 'Нужны name и корректный base_url');
        }
        $slug = \App\Core\Translit::domainSlug($baseUrl, \App\Core\Translit::slug($name, 'portal'));
        $base = $slug;
        for ($i = 2; Client::slugTaken($slug); $i++) $slug = $base . '-' . $i;

        /* Те же поля, что и при изменении: портал, заведённый программой, не
           должен отличаться от заведённого руками ничем, кроме способа. */
        $fields = Client::patchFrom($body);
        unset($fields['slug']);   // машинное имя выводим из адреса сами
        $this->checkClientFields($fields);
        [$id, $clientId, $secret] = Client::create($fields + [
            'name' => $name,
            'slug' => $slug,
            'base_url' => $baseUrl,
            /* Адрес возврата не угадываем: см. RedirectMiss. */
            'redirect_uris' => [],
            'post_logout_uris' => [$baseUrl . '/'],
            'backchannel_uri' => null,
            'access_policy' => 'open',
        ]);
        /* Client::create кладёт колонки списком и лишние ключи не видит: то,
           что он не знает, дописываем следом. */
        $after = array_intersect_key($fields, array_flip(['is_active', 'show_on_home']));
        if ($after !== []) Client::update($id, $after);
        $this->audit('api.client.create', ['client' => $id, 'meta' => ['name' => $name]]);
        // client_secret отдаётся ОДИН раз — как и в мастере §6.1
        $this->json(['client_id' => $clientId, 'client_secret' => $secret, 'slug' => $slug,
            'integration_url' => base_url('/admin/clients/' . $id)], 201);
    }

    public function clientGet(string $clientId): never
    {
        $this->auth('clients:read');
        $c = Client::findByClientId($clientId) ?? $this->error(404, 'not_found', 'Портал не найден');
        $this->json($this->clientDto($c) + [
            'redirect_uris' => (array)$c['redirect_uris'],
            'post_logout_uris' => (array)$c['post_logout_uris'],
            'allowed_scopes' => (array)$c['allowed_scopes'],
            'org_id' => $c['org_id'] !== null ? (int)$c['org_id'] : null,
            'require_2fa' => (bool)$c['require_2fa'],
        ]);
    }

    public function clientUpdate(string $clientId): never
    {
        $this->auth('clients:write');
        $c = Client::findByClientId($clientId) ?? $this->error(404, 'not_found', 'Портал не найден');
        $body = $this->body();
        /* Разбор — по карте полей портала, одной на карточку и на API. Свой
           набор ключей здесь уже приводил к тому, что `same_site_family` нельзя
           было включить снаружи вовсе. */
        $patch = Client::patchFrom($body);
        $this->checkClientFields($patch, (int)$c['id']);
        if ($patch === []) $this->error(422, 'invalid_request', 'Нечего менять');
        Client::update((int)$c['id'], $patch);
        $this->audit('api.client.update', ['client' => (int)$c['id'],
            'meta' => ['fields' => array_keys($patch)]]);
        $this->json($this->clientDto(Client::find((int)$c['id'])));
    }

    public function clientDelete(string $clientId): never
    {
        $this->auth('clients:write');
        $c = Client::findByClientId($clientId) ?? $this->error(404, 'not_found', 'Портал не найден');
        $removed = Client::purge((int)$c['id']);
        $this->audit('api.client.delete', ['meta' => ['slug' => $c['slug'], 'removed' => $removed]]);
        $this->json(['deleted' => true, 'removed' => $removed]);
    }

    /**
     * Перевыпуск секрета.
     *
     * Старый живёт ещё сутки — как и в админке: портал успевает подхватить
     * новый, а обмен токенами не рвётся на середине выкладки.
     */
    public function clientRotateSecret(string $clientId): never
    {
        $this->auth('clients:write');
        $c = Client::findByClientId($clientId) ?? $this->error(404, 'not_found', 'Портал не найден');
        $secret = Client::rotateSecret((int)$c['id']);
        $this->audit('api.client.secret_rotate', ['client' => (int)$c['id']]);
        $this->json(['client_id' => $c['client_id'], 'client_secret' => $secret,
            'old_valid_until' => gmdate('c', time() + 86400)]);
    }

    /* ---------- Роли ---------- */

    public function rolesList(): never
    {
        $this->auth('roles:read');
        $scope = $this->scopeFromQuery();
        /* Без области — весь справочник; с областью — то, что осмысленно выдать
           именно здесь. Тот же ответ, что видит человек в выпадающем списке:
           двух правд о том, «какие роли уместны», в системе быть не должно. */
        $rows = $scope === null
            ? Role::all()
            : \App\Core\RoleOffer::inScope($scope);
        $this->json(['items' => array_map(static fn(array $r): array => [
            'id' => (int)$r['id'],
            'slug' => $r['slug'],
            'name' => $r['name'],
            'access_level' => (int)($r['access_level'] ?? 0),
            'client_id' => $r['client_id'] !== null ? (int)$r['client_id'] : null,
            'is_system' => (bool)($r['is_system'] ?? false),
        ], $rows)]);
    }

    public function roleCreate(): never
    {
        $this->auth('roles:write');
        $body = $this->body();
        $slug = mb_strtolower(trim((string)($body['slug'] ?? '')));
        $name = trim((string)($body['name'] ?? ''));
        if (!preg_match('~^[a-z0-9._-]{2,64}$~', $slug) || $name === '') {
            $this->error(422, 'invalid_request', 'Нужны slug (a-z0-9._-) и name');
        }
        $clientDbId = null;
        if (!empty($body['client_id'])) {
            $c = Client::findByClientId((string)$body['client_id'])
                ?? $this->error(404, 'not_found', 'Портал не найден');
            $clientDbId = (int)$c['id'];
        }
        if (Role::findBySlug($slug, $clientDbId)) {
            $this->error(409, 'conflict', 'Роль с таким slug здесь уже есть');
        }
        $id = Role::create($clientDbId, $slug, $name, (string)($body['description'] ?? '') ?: null);
        /* Уровень — из справочника по имени, если он там есть: «blog_editor»
           опознаётся как редактор. Прав SSO роль портала не несёт никогда,
           как бы она ни называлась (ROLES.md §4). */
        $level = isset($body['access_level'])
            ? max(0, min(100, (int)$body['access_level']))
            : \App\Core\RoleCatalog::levelForIncoming($slug);
        Role::setLevel($id, $level);
        if ($clientDbId !== null) Role::setPermissions($id, []);
        $this->audit('api.role.create', ['meta' => ['slug' => $slug, 'level' => $level]]);
        $this->json(['id' => $id, 'slug' => $slug, 'access_level' => $level], 201);
    }

    public function roleDelete(string $id): never
    {
        $this->auth('roles:write');
        $role = Role::find((int)$id) ?? $this->error(404, 'not_found', 'Роль не найдена');
        if (!empty($role['is_system'])) {
            $this->error(409, 'conflict', 'Системную роль удалить нельзя');
        }
        Role::delete((int)$role['id']);
        $this->audit('api.role.delete', ['meta' => ['slug' => $role['slug']]]);
        $this->json(['deleted' => true]);
    }

    /** Что человеку выдано и где. */
    public function userRoles(string $sub): never
    {
        $this->auth('roles:read');
        $u = User::findByPublicId($sub) ?? $this->error(404, 'not_found', 'Человек не найден');
        $rows = DB::all(
            'SELECT r.id, r.slug, r.name, r.access_level, ur.scope_type, ur.scope_id
               FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id
              WHERE ur.user_id = ? ORDER BY r.access_level DESC', [(int)$u['id']]);
        $this->json(['items' => array_map(static fn(array $r): array => [
            'role_id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'],
            'access_level' => (int)$r['access_level'],
            'scope' => ['type' => $r['scope_type'] ?? 'system',
                        'id' => $r['scope_id'] !== null ? (int)$r['scope_id'] : null],
        ], $rows)]);
    }

    /**
     * Выдать роль.
     *
     * Проверка та же, что в интерфейсе: RoleOffer решает, уместна ли роль в
     * этой области. Иначе API стал бы обходным путём вокруг систематизации —
     * список сузили бы глазами, а машиной по-прежнему выдавалось бы что
     * угодно (v4.51).
     */
    public function userRoleGrant(string $sub): never
    {
        $this->auth('roles:write');
        $u = User::findByPublicId($sub) ?? $this->error(404, 'not_found', 'Человек не найден');
        $body = $this->body();
        $role = $this->roleFromBody($body);
        $scope = $this->scopeFromBody($body);
        if (!\App\Core\RoleOffer::allows($scope, (int)$role['id'])) {
            $this->error(422, 'invalid_request',
                'Эту роль нельзя выдать в этой области: она к ней не относится');
        }
        /* v4.97.2: суперадмина выдаёт только токен `*` — с одним roles:write
           программа поднимала себя до уровня, которого ей не давали. */
        if ($role['slug'] === 'superadmin' && !in_array(ApiToken::SCOPE_ALL, $this->token['scopes'], true)) {
            $this->error(403, 'insufficient_scope', 'Роль superadmin выдаёт только токен «*»');
        }
        Role::assignScoped((int)$u['id'], (int)$role['id'], $scope, null);
        \App\Core\AccessResolver::forget();
        $this->audit('api.role.grant', ['on' => (int)$u['id'],
            'meta' => ['role' => $role['slug'], 'scope' => $scope->type . ':' . ($scope->id ?? 0)]]);
        $this->json(['granted' => true, 'role' => $role['slug'],
            'scope' => ['type' => $scope->type, 'id' => $scope->id]], 201);
    }

    /** Отозвать. Без проверки уместности: снять выданное можно всегда. */
    public function userRoleRevoke(string $sub): never
    {
        $this->auth('roles:write');
        $u = User::findByPublicId($sub) ?? $this->error(404, 'not_found', 'Человек не найден');
        $body = $this->body() + $_GET;
        $role = $this->roleFromBody($body);
        Role::revokeScoped((int)$u['id'], (int)$role['id'], $this->scopeFromBody($body));
        \App\Core\AccessResolver::forget();
        $this->audit('api.role.revoke', ['on' => (int)$u['id'], 'meta' => ['role' => $role['slug']]]);
        $this->json(['revoked' => true]);
    }

    /* ---------- Организации ---------- */

    public function orgsList(): never
    {
        $this->auth('orgs:read');
        $this->json(['items' => array_map(static fn(array $o): array => [
            'id' => (int)$o['id'], 'name' => $o['name'], 'slug' => $o['slug'],
            'is_active' => (bool)($o['is_active'] ?? 1),
            'members' => (int)DB::val('SELECT COUNT(*) FROM {org_members} WHERE org_id = ?', [(int)$o['id']]),
        ], \App\Models\Org::all())]);
    }

    public function orgCreate(): never
    {
        $this->auth('orgs:write');
        $body = $this->body();
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') $this->error(422, 'invalid_request', 'Нужно name');
        $slug = trim((string)($body['slug'] ?? '')) ?: \App\Core\Translit::slug($name, 'org');
        $base = $slug;
        for ($i = 2; DB::row('SELECT id FROM {orgs} WHERE slug = ?', [$slug]); $i++) $slug = $base . '-' . $i;
        $id = DB::insert('orgs', ['name' => $name, 'slug' => $slug,
            'is_active' => 1, 'created_at' => now()]);
        $this->audit('api.org.create', ['meta' => ['slug' => $slug]]);
        $this->json(['id' => $id, 'slug' => $slug], 201);
    }

    public function orgDelete(string $id): never
    {
        $this->auth('orgs:write');
        $org = \App\Models\Org::find((int)$id) ?? $this->error(404, 'not_found', 'Организация не найдена');
        $removed = \App\Models\Org::purge((int)$org['id']);
        $this->audit('api.org.delete', ['meta' => ['slug' => $org['slug'], 'removed' => $removed]]);
        $this->json(['deleted' => true, 'removed' => $removed]);
    }

    public function orgMembers(string $id): never
    {
        $this->auth('orgs:read');
        $org = \App\Models\Org::find((int)$id) ?? $this->error(404, 'not_found', 'Организация не найдена');
        $this->json(['items' => array_map(fn(array $m): array => [
            'sub' => $m['public_id'] ?? null,
            'email' => $m['email'] ?? null,
            'role' => $m['role'] ?? 'member',
        ], \App\Models\Org::members((int)$org['id']))]);
    }

    public function orgMemberAdd(string $id): never
    {
        $this->auth('orgs:write');
        $org = \App\Models\Org::find((int)$id) ?? $this->error(404, 'not_found', 'Организация не найдена');
        $body = $this->body();
        $u = $this->userFromBody($body);
        $role = in_array($body['role'] ?? '', ['owner', 'admin', 'member'], true)
            ? (string)$body['role'] : 'member';
        \App\Models\Org::addMember((int)$org['id'], (int)$u['id'], $role, null);
        $this->audit('api.org.member_add', ['on' => (int)$u['id'],
            'meta' => ['org' => $org['slug'], 'role' => $role]]);
        $this->json(['added' => true, 'role' => $role], 201);
    }

    public function orgMemberRemove(string $id, string $sub): never
    {
        $this->auth('orgs:write');
        $org = \App\Models\Org::find((int)$id) ?? $this->error(404, 'not_found', 'Организация не найдена');
        $u = User::findByPublicId($sub) ?? $this->error(404, 'not_found', 'Человек не найден');
        \App\Models\Org::removeMember((int)$org['id'], (int)$u['id']);
        $this->audit('api.org.member_remove', ['on' => (int)$u['id'],
            'meta' => ['org' => $org['slug']]]);
        $this->json(['removed' => true]);
    }

    /* ---------- Доступ к порталам ---------- */

    public function accessGrant(string $sub): never
    {
        $this->auth('access:write');
        $u = User::findByPublicId($sub) ?? $this->error(404, 'not_found', 'Человек не найден');
        $body = $this->body();
        $c = Client::findByClientId((string)($body['client_id'] ?? ''))
            ?? $this->error(404, 'not_found', 'Портал не найден');
        \App\Models\Access::grant((int)$u['id'], (int)$c['id'], null, 'active');
        $this->audit('api.access.grant', ['on' => (int)$u['id'], 'client' => (int)$c['id']]);
        $this->json(['granted' => true, 'client_id' => $c['client_id']], 201);
    }

    public function accessRevoke(string $sub): never
    {
        $this->auth('access:write');
        $u = User::findByPublicId($sub) ?? $this->error(404, 'not_found', 'Человек не найден');
        $body = $this->body() + $_GET;
        $c = Client::findByClientId((string)($body['client_id'] ?? ''))
            ?? $this->error(404, 'not_found', 'Портал не найден');
        /* Порталу сообщает сам setStatus — и только ЭТОМУ порталу. Здесь стоял
           userEvent(), который рассылает по ВСЕМ порталам человека: отзыв
           доступа к одному выкидывал его отовсюду (v4.62). */
        \App\Models\Access::setStatus((int)$u['id'], (int)$c['id'], 'blocked');
        $this->audit('api.access.revoke', ['on' => (int)$u['id'], 'client' => (int)$c['id']]);
        $this->json(['revoked' => true]);
    }

    /* ---------- Настройки ---------- */

    public function settingsList(): never
    {
        $this->auth('settings:read');
        $out = [];
        foreach (DB::all('SELECT `key`, `value` FROM {settings} ORDER BY `key`') as $r) {
            /* Секреты наружу не отдаём даже суперадминскому токену: пароль
               SMTP и ключи в ответе API оседают в логах того, кто спрашивал.

               Кто именно секрет — знает один список на весь проект. Своё
               правило здесь стояло ровно один выпуск и успело соврать на живой
               установке: см. SettingsTransfer::secret(). */
            if (SettingsTransfer::secret((string)$r['key'])) {
                $out[(string)$r['key']] = self::MASK;
                continue;
            }
            $out[(string)$r['key']] = $r['value'];
        }
        $this->json(['items' => $out]);
    }

    public function settingsUpdate(): never
    {
        $this->auth('settings:write');
        $body = $this->body();
        $changed = [];
        foreach ($body as $k => $v) {
            if (!is_string($k) || !preg_match('~^[a-z0-9_.]{2,64}$~i', $k)) continue;
            /* v4.97.2: право settings:write — это настройки, которые переезжают
               между установками (SettingsTransfer::portable). Прочее (служебные
               отметки, ключи подписи, владелец) — только токену `*`, а
               владелец и ключи не пишутся вовсе: у них свои пути с
               подтверждением (Owner::set, Keys). */
            if ($k === 'system.owner_user_id' || str_starts_with($k, 'keys.')) {
                $this->error(403, 'forbidden', 'Этот ключ через API не меняется: ' . $k);
            }
            if (!SettingsTransfer::portable($k) && !in_array(ApiToken::SCOPE_ALL, $this->token['scopes'], true)) {
                $this->error(403, 'insufficient_scope', 'Служебная настройка — только токену «*»: ' . $k);
            }
            /* Прочитал, поправил соседнее, записал всё обратно — обычный приём
               для машины. Секрет при чтении был `***`, и записать его обратно
               значит стереть настоящий. Отказываем: это маска, а не значение. */
            if ((string)$v === self::MASK && SettingsTransfer::secret($k)) {
                $this->error(422, 'invalid_request',
                    'Значение «' . self::MASK . '» — это маска секрета, а не сам секрет: ' . $k);
            }
            \App\Core\Setting::set($k, (string)$v);
            $changed[] = $k;
        }
        if ($changed === []) $this->error(422, 'invalid_request', 'Нечего менять');
        \App\Core\Setting::flush();
        $this->audit('api.settings.update', ['meta' => ['keys' => $changed]]);
        $this->json(['updated' => $changed]);
    }

    /* ---------- Аудит ---------- */

    public function auditList(): never
    {
        $this->auth('audit:read');
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
        $where = [];
        $params = [];
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            $v = trim((string)($_GET[$key] ?? ''));
            if ($v !== '' && preg_match('~^\d{4}-\d{2}-\d{2}~', $v)) {
                $where[] = "a.created_at $op ?";
                $params[] = strlen($v) === 10 ? $v . ($key === 'to' ? ' 23:59:59' : ' 00:00:00') : $v;
            }
        }
        if (($event = trim((string)($_GET['event'] ?? ''))) !== '') {
            $where[] = 'a.event LIKE ?';
            $params[] = $event . '%';
        }
        $sql = $where === [] ? '1=1' : implode(' AND ', $where);
        $rows = DB::all("SELECT a.*, u.public_id AS on_sub FROM {audit_log} a
             LEFT JOIN {users} u ON u.id = a.on_user_id
             WHERE $sql ORDER BY a.id DESC LIMIT $limit", $params);

        $this->json(['items' => array_map(fn($r) => [
            'id' => (int)$r['id'],
            'at' => gmdate('c', strtotime($r['created_at'] . ' UTC')),
            'event' => $r['event'],
            'sub' => $r['on_sub'],
            'ip' => ip_str($r['ip']),
            'meta' => $r['meta'] ? json_decode((string)$r['meta'], true) : null,
        ], $rows)]);
    }

    /* ---------- Схема ---------- */

    public function openapi(): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo json_encode(\App\Core\OpenApi::document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---------- Внутренности ---------- */

    private function auth(string $scope): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            $this->error(401, 'unauthorized', 'Нужен заголовок Authorization: Bearer <токен>');
        }
        $token = ApiToken::findUsable(substr($header, 7));
        if (!$token) $this->error(401, 'invalid_token', 'Токен неизвестен, отозван или истёк');

        RateLimiter::guard('api:' . $token['id'], 120, 60);
        /* `*` покрывает любое право — это уровень суперадмина для программы.
           Проверка живёт в модели, а не здесь: право спрашивают и другие точки
           (SCIM, здоровье), и два места решали бы один вопрос по-разному. */
        if (!ApiToken::allows($token['scopes'], $scope)) {
            $this->error(403, 'insufficient_scope', 'Токену не хватает права ' . $scope);
        }
        ApiToken::touch((int)$token['id']);
        $this->token = $token;
    }

    /**
     * Область выдачи из тела запроса.
     *
     * `{"scope": "system"}` или `{"scope": "client", "client_id": "cli_…"}`
     * или `{"scope": "org", "org_id": 3}`. Портал называется своим внешним
     * `client_id`, а не внутренним номером: наружу мы обещали именно его, и
     * пускать в API внутренние идентификаторы значит обещать их навсегда.
     */
    private function scopeFromBody(array $body): \App\Core\Scope
    {
        $type = (string)($body['scope'] ?? \App\Core\Scope::SYSTEM);
        if ($type === \App\Core\Scope::CLIENT) {
            $c = Client::findByClientId((string)($body['client_id'] ?? ''))
                ?? $this->error(404, 'not_found', 'Портал не найден');
            return \App\Core\Scope::client((int)$c['id']);
        }
        if ($type === \App\Core\Scope::ORG) {
            $org = \App\Models\Org::find((int)($body['org_id'] ?? 0))
                ?? $this->error(404, 'not_found', 'Организация не найдена');
            return \App\Core\Scope::org((int)$org['id']);
        }
        return \App\Core\Scope::system();
    }

    /** То же из строки запроса — для GET-ов. Без параметра области — null. */
    private function scopeFromQuery(): ?\App\Core\Scope
    {
        if (!isset($_GET['scope'])) return null;
        return $this->scopeFromBody($_GET);
    }

    /** Роль по номеру или по слагу (со своим порталом, если он назван). */
    private function roleFromBody(array $body): array
    {
        if (!empty($body['role_id'])) {
            return Role::find((int)$body['role_id'])
                ?? $this->error(404, 'not_found', 'Роль не найдена');
        }
        $slug = mb_strtolower(trim((string)($body['role'] ?? '')));
        if ($slug === '') $this->error(422, 'invalid_request', 'Нужен role или role_id');
        $clientDbId = null;
        if (!empty($body['client_id'])) {
            $c = Client::findByClientId((string)$body['client_id']);
            if ($c) $clientDbId = (int)$c['id'];
        }
        /* Сначала ищем роль САМОГО сервиса, потом системную с тем же слагом:
           тот же порядок старшинства, что в списке выдачи (RoleOffer). */
        return Role::findBySlug($slug, $clientDbId)
            ?? Role::findBySlug($slug)
            ?? $this->error(404, 'not_found', 'Роль «' . $slug . '» не найдена');
    }

    /** Человек по sub, почте или логину — тем, что назвали. */
    private function userFromBody(array $body): array
    {
        if (!empty($body['sub'])) {
            return User::findByPublicId((string)$body['sub'])
                ?? $this->error(404, 'not_found', 'Человек не найден');
        }
        $id = trim((string)($body['identity'] ?? $body['email'] ?? ''));
        if ($id === '') $this->error(422, 'invalid_request', 'Нужен sub, email или identity');
        return User::findByLoginOrEmail($id)
            ?? $this->error(404, 'not_found', 'Человек не найден');
    }

    /** Короткая карточка портала — одна на все ответы про порталы. */
    /**
     * Портал наружу — по той же карте, по которой принимается.
     *
     * Раньше отдавалось шесть полей из пятнадцати: снаружи не было видно ни
     * доверия, ни обязательной 2FA, ни адресов возврата, — то есть проверить,
     * что записалось, было нечем.
     */
    private function clientDto(array $c): array
    {
        $out = ['client_id' => $c['client_id']];
        foreach (Client::EDITABLE as $field => $type) {
            if (!array_key_exists($field, $c)) continue;   // колонки может не быть
            $v = $c[$field];
            $out[$field] = match ($type) {
                'bool' => (bool)$v,
                'list' => is_array($v) ? $v : (json_decode((string)$v, true) ?: []),
                'int?' => $v === null ? null : (int)$v,
                default => $v,
            };
        }
        return $out;
    }

    /**
     * Проверки, которые картой полей не выражаются: имя занято, организации нет.
     *
     * Меняет $patch по ссылке — негодное значение выбрасывается, а не молча
     * записывается: «уехал в никуда» хуже, чем «не изменилось».
     */
    private function checkClientFields(array &$patch, int $selfId = 0): void
    {
        if (array_key_exists('slug', $patch)) {
            $slug = \App\Core\Translit::slug((string)$patch['slug'], '');
            if ($slug === '') $this->error(422, 'invalid_request', 'Машинное имя пустое');
            if (Client::slugTaken($slug, $selfId ?: null)) {
                $this->error(409, 'slug_taken', 'Машинное имя уже занято: ' . $slug);
            }
            $patch['slug'] = $slug;
        }
        if (array_key_exists('org_id', $patch) && $patch['org_id'] !== null
            && \App\Models\Org::find((int)$patch['org_id']) === null) {
            $this->error(404, 'not_found', 'Организация не найдена: ' . $patch['org_id']);
        }
    }

    private function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        if (is_array($json)) return $json;
        return $_POST; // допускаем и обычную форму — так проще из curl
    }

    private function userDto(array $u): array
    {
        return [
            'sub' => $u['public_id'],
            'email' => $u['email'],
            'email_verified' => $u['email_verified_at'] !== null,
            'login' => $u['login'],
            'first_name' => $u['first_name'],
            'last_name' => $u['last_name'],
            'display_name' => $u['display_name'],
            'status' => $u['status'],
            'two_factor' => $u['totp_enabled_at'] !== null,
            'created_at' => gmdate('c', strtotime($u['created_at'] . ' UTC')),
            'last_login_at' => $u['last_login_at'] ? gmdate('c', strtotime($u['last_login_at'] . ' UTC')) : null,
        ];
    }

    private function audit(string $event, array $opts): void
    {
        $meta = ($opts['meta'] ?? []) + ['token' => $this->token['name'] ?? '?'];
        Audit::log($event, ['actor' => null] + $opts + ['meta' => $meta]);
    }

    private function json(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function error(int $code, string $error, string $message): never
    {
        $this->json(['error' => $error, 'message' => $message], $code);
    }
}
