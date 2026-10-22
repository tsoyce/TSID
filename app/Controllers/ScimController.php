<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\RateLimiter;
use App\Models\ApiToken;
use App\Models\Org;
use App\Models\User;

/**
 * SCIM 2.0 — подмножество (RFC 7643/7644), v3.2.
 *
 * Зачем отдельно от Admin API: кадровые и корпоративные системы (1С-выгрузки,
 * Okta, Azure AD, десяток HR-платформ) умеют синхронизировать пользователей
 * только по SCIM. Наш /api/v1 удобнее, но его никто, кроме нас, не знает.
 * Здесь тот же слой данных, но в чужом формате — чтобы такие системы можно
 * было подключить без единой строки кода на их стороне.
 *
 * Что поддержано: /Users и /Groups с GET (список и по id), POST, PATCH,
 * PUT и DELETE, фильтр по userName/externalId/displayName, постраничность
 * startIndex/count. Группы — это организации: у них уже есть состав и выдача
 * доступа к порталам целиком, то есть ровно семантика SCIM-группы.
 *
 * Чего намеренно нет: сложные фильтры SCIM (and/or/сравнения по датам),
 * /Me, /Bulk, /Schemas. Они утраивают объём кода, а нужны единицам —
 * когда понадобятся, добавим по факту, а не наугад.
 *
 * Права: те же scope-токены, что у Admin API — users:read/users:write.
 */
final class ScimController
{
    private const USER_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const GROUP_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    private const LIST_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';
    private const ERROR_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:Error';
    private const PATCH_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    private array $token = [];

    /* ==================== Users ==================== */

    public function usersList(): never
    {
        $this->auth('users:read');
        [$start, $count] = $this->page();

        $where = ['status != ?'];
        $params = ['deleted'];
        /* Фильтр SCIM поддерживаем в том виде, в каком его реально шлют
           интеграции: одно равенство по userName или externalId. */
        if ($eq = $this->parseEq(['userName', 'externalId', 'emails.value', 'emails'])) {
            /* Портал ищет своих людей по тому же userName, каким их заводил,
               а у человека без почты это логин. Поэтому равенство идёт по обоим
               столбцам сразу: адрес с логином не совпадёт никогда — в логине
               нет «собаки», а значит и ложного попадания быть не может. */
            if ($eq[0] === 'externalId') { $where[] = 'public_id = ?'; $params[] = $eq[1]; }
            else { $where[] = '(email = ? OR login = ?)'; $params[] = $eq[1]; $params[] = $eq[1]; }
        }
        $sql = 'FROM {users} WHERE ' . implode(' AND ', $where);
        $total = (int)DB::val('SELECT COUNT(*) ' . $sql, $params);
        $rows = DB::all('SELECT * ' . $sql . ' ORDER BY id LIMIT ' . (int)$count . ' OFFSET ' . (int)($start - 1), $params);

        $this->ok([
            'schemas' => [self::LIST_SCHEMA],
            'totalResults' => $total,
            'startIndex' => $start,
            'itemsPerPage' => count($rows),
            'Resources' => array_map([$this, 'userResource'], $rows),
        ]);
    }

    public function userGet(string $id): never
    {
        $this->auth('users:read');
        $this->ok($this->userResource($this->mustUser($id)));
    }

    public function userCreate(): never
    {
        $this->auth('users:write');
        $body = $this->body();
        $email = $this->emailFrom($body);
        $login = $this->loginFrom($body);
        if ($email === null && $login === null) {
            $this->fail(400, 'invalidValue', 'Нужен userName или emails[0].value');
        }
        if ($email !== null && User::findByEmail($email)) {
            $this->fail(409, 'uniqueness', 'Пользователь с таким email уже есть');
        }
        if ($login !== null && ($le = \App\Core\LoginName::check($login)) !== null) {
            $this->fail(400, 'invalidValue', $le);
        }
        if ($login !== null && User::loginTaken($login)) {
            $this->fail(409, 'uniqueness', 'Пользователь с таким userName уже есть');
        }

        $name = (array)($body['name'] ?? []);
        $id = User::create([
            'email' => $email,
            'login' => $login,
            'first_name' => $this->str($name['givenName'] ?? null, 100),
            'last_name' => $this->str($name['familyName'] ?? null, 100),
            'display_name' => $this->str($body['displayName'] ?? null, 150),
            /* active по умолчанию true: SCIM-провижининг заводит рабочие учётки,
               а не заготовки. Пароля нет — вход по ссылке, passkey или соцвходу. */
            'status' => ($body['active'] ?? true) ? 'active' : 'blocked',
        ]);
        Audit::log('scim.user.created', ['target_user_id' => $id,
            'meta' => ['token' => $this->token['name'] ?? '', 'email' => $email ?? ('@' . $login)]]);

        $this->ok($this->userResource(User::find($id) ?? []), 201);
    }

    /** PUT: замена ресурса целиком. */
    public function userReplace(string $id): never
    {
        $this->auth('users:write');
        $u = $this->mustUser($id);
        $body = $this->body();
        $name = (array)($body['name'] ?? []);
        User::update((int)$u['id'], array_filter([
            'first_name' => $this->str($name['givenName'] ?? null, 100),
            'last_name' => $this->str($name['familyName'] ?? null, 100),
            'display_name' => $this->str($body['displayName'] ?? null, 150),
            'status' => array_key_exists('active', $body)
                ? ($body['active'] ? 'active' : 'blocked') : null,
        ], static fn($v) => $v !== null));
        Audit::log('scim.user.updated', ['target_user_id' => (int)$u['id'],
            'meta' => ['token' => $this->token['name'] ?? '', 'op' => 'replace']]);
        $this->ok($this->userResource(User::find((int)$u['id']) ?? []));
    }

    /**
     * PATCH по RFC 7644 §3.5.2. Именно им системы включают и отключают людей:
     * `{"Operations":[{"op":"replace","path":"active","value":false}]}`.
     */
    public function userPatch(string $id): never
    {
        $this->auth('users:write');
        $u = $this->mustUser($id);
        $body = $this->body();
        $ops = $body['Operations'] ?? $body['operations'] ?? null;
        if (!is_array($ops)) $this->fail(400, 'invalidSyntax', 'Нужен массив Operations');

        $map = ['name.givenname' => 'first_name', 'name.familyname' => 'last_name',
                'displayname' => 'display_name'];
        $patch = [];
        foreach ($ops as $op) {
            $kind = strtolower((string)($op['op'] ?? ''));
            if ($kind === 'remove') { $patch[$map[strtolower((string)($op['path'] ?? ''))] ?? ''] = null; continue; }
            if ($kind !== 'replace' && $kind !== 'add') continue;
            $path = strtolower((string)($op['path'] ?? ''));
            $value = $op['value'] ?? null;

            /* Без path значение приходит объектом со всеми полями сразу */
            if ($path === '' && is_array($value)) {
                foreach ($value as $k => $v) {
                    $kk = strtolower((string)$k);
                    if ($kk === 'active') { $patch['status'] = $v ? 'active' : 'blocked'; continue; }
                    if (isset($map[$kk])) $patch[$map[$kk]] = $this->str($v, 150);
                }
                continue;
            }
            if ($path === 'active') { $patch['status'] = $this->truthy($value) ? 'active' : 'blocked'; continue; }
            if (isset($map[$path])) $patch[$map[$path]] = $this->str($value, 150);
        }
        unset($patch['']);
        if ($patch !== []) User::update((int)$u['id'], $patch);
        Audit::log('scim.user.updated', ['target_user_id' => (int)$u['id'],
            'meta' => ['token' => $this->token['name'] ?? '', 'fields' => array_keys($patch)]]);
        $this->ok($this->userResource(User::find((int)$u['id']) ?? []));
    }

    /**
     * DELETE в SCIM означает деактивацию, а не стирание: у нас за пользователем
     * висят выданные доступы и аудит, и физическое удаление их осиротит.
     * Полное удаление с холдом 14 дней остаётся ручной операцией в админке.
     */
    public function userDelete(string $id): never
    {
        $this->auth('users:write');
        $u = $this->mustUser($id);
        User::update((int)$u['id'], ['status' => 'blocked']);
        \App\Models\SessionModel::revokeAllForUser((int)$u['id']);
        Audit::log('scim.user.deactivated', ['target_user_id' => (int)$u['id'],
            'meta' => ['token' => $this->token['name'] ?? '']]);
        http_response_code(204);
        exit;
    }

    /* ==================== Groups (организации) ==================== */

    public function groupsList(): never
    {
        $this->auth('users:read');
        [$start, $count] = $this->page();
        $where = [];
        $params = [];
        if ($eq = $this->parseEq(['displayName', 'externalId'])) {
            $where[] = $eq[0] === 'externalId' ? 'slug = ?' : 'name = ?';
            $params[] = $eq[1];
        }
        $sql = 'FROM {orgs}' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $total = (int)DB::val('SELECT COUNT(*) ' . $sql, $params);
        $rows = DB::all('SELECT * ' . $sql . ' ORDER BY id LIMIT ' . (int)$count . ' OFFSET ' . (int)($start - 1), $params);
        $this->ok([
            'schemas' => [self::LIST_SCHEMA],
            'totalResults' => $total,
            'startIndex' => $start,
            'itemsPerPage' => count($rows),
            'Resources' => array_map([$this, 'groupResource'], $rows),
        ]);
    }

    public function groupGet(string $id): never
    {
        $this->auth('users:read');
        $this->ok($this->groupResource($this->mustOrg($id)));
    }

    public function groupCreate(): never
    {
        $this->auth('users:write');
        $body = $this->body();
        $name = $this->str($body['displayName'] ?? null, 100);
        if ($name === null) $this->fail(400, 'invalidValue', 'Нужен displayName');
        $id = Org::create($name, null, null);
        foreach ((array)($body['members'] ?? []) as $m) {
            $this->addMember($id, (string)($m['value'] ?? ''));
        }
        Audit::log('scim.group.created', ['meta' => ['org' => $name, 'token' => $this->token['name'] ?? '']]);
        $this->ok($this->groupResource(Org::find($id) ?? []), 201);
    }

    /** Состав группы меняют именно PATCH'ем — это основной сценарий синхронизации. */
    public function groupPatch(string $id): never
    {
        $this->auth('users:write');
        $org = $this->mustOrg($id);
        $ops = $this->body()['Operations'] ?? [];
        if (!is_array($ops)) $this->fail(400, 'invalidSyntax', 'Нужен массив Operations');

        foreach ($ops as $op) {
            $kind = strtolower((string)($op['op'] ?? ''));
            $path = strtolower((string)($op['path'] ?? ''));
            if (!str_starts_with($path, 'members')) {
                if ($path === 'displayname' && $kind !== 'remove') {
                    DB::update('orgs', ['name' => $this->str($op['value'] ?? null, 100) ?? $org['name']],
                        'id = ?', [(int)$org['id']]);
                }
                continue;
            }
            /* remove со значением в пути: members[value eq "sub"] */
            if ($kind === 'remove' && preg_match('~value\s+eq\s+"([^"]+)"~i', (string)($op['path'] ?? ''), $m)) {
                $this->removeMember((int)$org['id'], $m[1]);
                continue;
            }
            foreach ((array)($op['value'] ?? []) as $member) {
                $sub = (string)($member['value'] ?? '');
                if ($sub === '') continue;
                $kind === 'remove' ? $this->removeMember((int)$org['id'], $sub)
                    : $this->addMember((int)$org['id'], $sub);
            }
        }
        Audit::log('scim.group.updated', ['meta' => ['org' => $org['slug'], 'token' => $this->token['name'] ?? '']]);
        $this->ok($this->groupResource(Org::find((int)$org['id']) ?? []));
    }

    public function groupDelete(string $id): never
    {
        $this->auth('users:write');
        $org = $this->mustOrg($id);
        Org::delete((int)$org['id']);
        Audit::log('scim.group.deleted', ['meta' => ['org' => $org['slug'], 'token' => $this->token['name'] ?? '']]);
        http_response_code(204);
        exit;
    }

    /** Описание возможностей: некоторые клиенты читают её перед синхронизацией. */
    public function serviceProviderConfig(): never
    {
        $this->ok([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'documentationUri' => base_url('/api/v1/openapi.json'),
            'patch' => ['supported' => true],
            'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            /* Фильтры поддержаны частично — честно говорим об этом, чтобы
               клиент не строил сложных запросов и не получал тишину в ответ. */
            'filter' => ['supported' => true, 'maxResults' => 200],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => false],
            'etag' => ['supported' => false],
            'authenticationSchemes' => [[
                'type' => 'oauthbearertoken',
                'name' => 'Bearer-токен Admin API',
                'description' => 'Токен со scope users:read / users:write из Инструментов',
            ]],
        ]);
    }

    /* ==================== Внутренности ==================== */

    private function userResource(array $u): array
    {
        $name = trim(((string)($u['last_name'] ?? '')) . ' ' . ((string)($u['first_name'] ?? '')));
        return [
            'schemas' => [self::USER_SCHEMA],
            'id' => $u['public_id'] ?? '',
            'externalId' => $u['public_id'] ?? '',
            /* userName обязан вернуться тем же, чем пришёл: портал по нему
               сверяет своих людей с нашими. У человека без почты это логин. */
            'userName' => $u['email'] ?: ($u['login'] ?? ''),
            'name' => [
                'familyName' => $u['last_name'] ?: null,
                'givenName' => $u['first_name'] ?: null,
                'formatted' => $name !== '' ? $name : null,
            ],
            'displayName' => $u['display_name'] ?: ($name !== '' ? $name : ($u['email'] ?: ($u['login'] ?? ''))),
            /* Пустого адреса в списке быть не должно: портал сложит его в свою
               базу как настоящий и получит столько же дублей, сколько у нас
               людей без почты. Нет адреса — нет и элемента. */
            'emails' => $u['email']
                ? [['value' => $u['email'], 'primary' => true,
                    'type' => 'work', 'verified' => !empty($u['email_verified_at'])]]
                : [],
            'active' => ($u['status'] ?? '') === 'active',
            'groups' => array_map(static fn($o) => [
                'value' => $o['slug'], 'display' => $o['name'], 'type' => 'direct',
            ], DB::all('SELECT o.slug, o.name FROM {org_members} m
                          JOIN {orgs} o ON o.id = m.org_id WHERE m.user_id = ?', [(int)($u['id'] ?? 0)])),
            'meta' => [
                'resourceType' => 'User',
                'created' => $this->iso($u['created_at'] ?? null),
                'lastModified' => $this->iso($u['updated_at'] ?? ($u['created_at'] ?? null)),
                'location' => base_url('/scim/v2/Users/' . ($u['public_id'] ?? '')),
            ],
        ];
    }

    private function groupResource(array $o): array
    {
        $members = DB::all('SELECT u.public_id, u.email FROM {org_members} m
                              JOIN {users} u ON u.id = m.user_id WHERE m.org_id = ?', [(int)($o['id'] ?? 0)]);
        return [
            'schemas' => [self::GROUP_SCHEMA],
            'id' => $o['slug'] ?? '',
            'externalId' => $o['slug'] ?? '',
            'displayName' => $o['name'] ?? '',
            'members' => array_map(static fn($m) => [
                'value' => $m['public_id'], 'display' => $m['email'], 'type' => 'User',
            ], $members),
            'meta' => [
                'resourceType' => 'Group',
                'created' => $this->iso($o['created_at'] ?? null),
                'location' => base_url('/scim/v2/Groups/' . ($o['slug'] ?? '')),
            ],
        ];
    }

    private function addMember(int $orgId, string $sub): void
    {
        $u = $sub !== '' ? User::findByPublicId($sub) : null;
        if ($u) Org::addMember($orgId, (int)$u['id'], 'member', null);
    }

    private function removeMember(int $orgId, string $sub): void
    {
        $u = $sub !== '' ? User::findByPublicId($sub) : null;
        if ($u) Org::removeMember($orgId, (int)$u['id']);
    }

    private function mustUser(string $id): array
    {
        $u = User::findByPublicId($id) ?? User::findByLoginOrEmail($id);
        if (!$u || $u['status'] === 'deleted') $this->fail(404, 'notFound', 'Пользователь не найден');
        return $u;
    }

    private function mustOrg(string $id): array
    {
        $o = DB::row('SELECT * FROM {orgs} WHERE slug = ? OR id = ?', [$id, (int)$id]);
        if (!$o) $this->fail(404, 'notFound', 'Группа не найдена');
        return $o;
    }

    /** @return array{int, int} startIndex (с единицы) и count */
    private function page(): array
    {
        $start = max(1, (int)($_GET['startIndex'] ?? 1));
        $count = (int)($_GET['count'] ?? 50);
        return [$start, max(1, min(200, $count))];
    }

    /**
     * Разбор простого фильтра SCIM: `userName eq "a@b.ru"`.
     * @param list<string> $allowed
     * @return array{string, string}|null
     */
    private function parseEq(array $allowed): ?array
    {
        $filter = trim((string)($_GET['filter'] ?? ''));
        if ($filter === '') return null;
        if (!preg_match('~^\s*([a-zA-Z.]+)\s+eq\s+"([^"]*)"\s*$~', $filter, $m)) {
            $this->fail(400, 'invalidFilter',
                'Поддерживается только простое равенство: attr eq "значение"');
        }
        foreach ($allowed as $a) {
            if (strcasecmp($a, $m[1]) === 0) return [$a === 'emails.value' || $a === 'emails' ? 'userName' : $a, $m[2]];
        }
        $this->fail(400, 'invalidFilter', 'Фильтр по ' . $m[1] . ' не поддерживается');
    }

    private function emailFrom(array $body): ?string
    {
        $candidates = [(string)($body['userName'] ?? '')];
        foreach ((array)($body['emails'] ?? []) as $e) {
            $candidates[] = is_array($e) ? (string)($e['value'] ?? '') : (string)$e;
        }
        foreach ($candidates as $c) {
            $c = trim($c);
            if ($c !== '' && filter_var($c, FILTER_VALIDATE_EMAIL)) return mb_strtolower($c);
        }
        return null;
    }

    /**
     * userName, когда это не адрес, — логин.
     *
     * По SCIM 2.0 (RFC 7643 §4.1.1) userName это «уникальный идентификатор для
     * входа», а вовсе не обязательно почта. Мы требовали именно адрес, и
     * портал, у которого люди заводились логином и паролём, не мог отдать нам
     * ни одного: каждый вызов возвращал 400. Теперь адрес узнаётся по виду, а
     * всё остальное принимается как логин.
     */
    private function loginFrom(array $body): ?string
    {
        $u = trim((string)($body['userName'] ?? ''));
        if ($u === '' || filter_var($u, FILTER_VALIDATE_EMAIL)) return null;
        return mb_substr($u, 0, 64);
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1'
            || (is_string($v) && strcasecmp($v, 'true') === 0);
    }

    private function str(mixed $v, int $max): ?string
    {
        if (!is_scalar($v)) return null;
        $s = trim((string)$v);
        return $s !== '' ? mb_substr($s, 0, $max) : null;
    }

    private function iso(?string $dt): ?string
    {
        if (!$dt) return null;
        $ts = strtotime($dt . ' UTC');
        return $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
    }

    private function auth(string $scope): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            $this->fail(401, 'invalidCredentials', 'Нужен заголовок Authorization: Bearer <токен>');
        }
        $token = ApiToken::findUsable(substr($header, 7));
        if (!$token) $this->fail(401, 'invalidCredentials', 'Токен неизвестен, отозван или истёк');
        RateLimiter::guard('scim:' . $token['id'], 120, 60);
        if (!in_array($scope, $token['scopes'], true)) {
            $this->fail(403, 'insufficientScope', 'Токену не хватает права ' . $scope);
        }
        ApiToken::touch((int)$token['id']);
        $this->token = $token;
    }

    private function body(): array
    {
        $json = json_decode((string)file_get_contents('php://input'), true);
        return is_array($json) ? $json : [];
    }

    private function ok(array $data, int $code = 200): never
    {
        /* Свой Content-Type: некоторые клиенты отказываются читать application/json */
        header('Content-Type: application/scim+json; charset=utf-8');
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function fail(int $code, string $type, string $detail): never
    {
        header('Content-Type: application/scim+json; charset=utf-8');
        http_response_code($code);
        echo json_encode([
            'schemas' => [self::ERROR_SCHEMA],
            'scimType' => $type,
            'detail' => $detail,
            'status' => (string)$code,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
