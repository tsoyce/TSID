<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\ErrorPage;
use App\Core\Flash;
use App\Core\View;
use App\Models\Client;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;

/** /admin/orgs: организации и групповая выдача доступа к порталам. */
final class OrgsController extends AdminBase
{
    public function index(): never
    {
        $this->requirePerm('orgs.read');
        $this->requireGlobalAdmin();
        View::render('admin/orgs', [
            'pageTitle' => t('Организации'),
            'orgs' => Org::all(),
        ]);
    }

    public function create(): never
    {
        $this->requirePerm('orgs.write');
        $this->requireGlobalAdmin();
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::error('У организации должно быть название.');
            redirect('/admin/orgs');
        }
        $id = Org::create($name, trim((string)($_POST['description'] ?? '')) ?: null, (int)Auth::id());
        Audit::log('admin.org.create', ['org' => $id, 'meta' => ['name' => $name]]);
        Flash::success('Организация создана. Добавьте участников и выдайте доступ к порталам.');
        redirect('/admin/orgs/' . $id);
    }

    /**
     * Организация, к которой относится раздел. 404 — если её нет.
     *
     * Все разделы оболочки начинаются одинаково, и повторять три строки
     * семь раз значит однажды забыть одну из них.
     */
    private function org(string $id, string $perm = 'orgs.read'): array
    {
        $this->requirePerm($perm);
        $this->requireGlobalAdmin();
        $org = Org::find((int)$id);
        if (!$org) ErrorPage::render(404);
        return $org;
    }

    /**
     * Числа у разделов в колонке: сколько сервисов, людей, выдач.
     *
     * Считаются один раз на страницу и показываются на всех разделах сразу —
     * так видно, где пусто, не заходя туда. Пустые не рисуются вовсе: ноль
     * рядом с названием читается как ошибка, а не как «здесь ничего нет».
     */
    private function counts(int $orgId): array
    {
        return [
            'projects' => count(Org::projects($orgId)),
            /* Все люди организации, а не только состав (v4.69): список в
               разделе один, и число у раздела обязано с ним совпадать. */
            'people' => count($this->peopleOf($orgId)),
            'access' => count(Org::clients($orgId)),
        ];
    }

    public function card(string $id): never
    {
        $org = $this->org($id);
        org_page($org, 'overview', 'admin/org/overview', [
            'pageTitle' => $org['name'],
            'projects' => Org::projects((int)$org['id']),
            'members' => Org::members((int)$org['id']),
            'clients' => Org::clients((int)$org['id']),
            /* Все люди организации — то же число, что у раздела в колонке (v4.91). */
            'people' => count($this->peopleOf((int)$org['id'])),
        ], '', $this->counts((int)$org['id']));
    }

    /** Сервисы организации — то, чем она владеет (SCOPES §1.4). */
    public function projects(string $id): never
    {
        $org = $this->org($id);
        $owned = Org::projects((int)$org['id']);
        $ownedIds = array_map(static fn(array $c): int => (int)$c['id'], $owned);
        org_page($org, 'projects', 'admin/org/projects', [
            'pageTitle' => t('Сервисы — :name', ['name' => $org['name']]),
            'projects' => $owned,
            /* Кандидаты на присоединение: ничьи порталы. Чужие в списке не
               показываем — забрать проект у другой организации мимоходом, из
               выпадающего списка, нельзя. */
            'free' => array_values(array_filter(Client::all(),
                static fn(array $c): bool => ($c['org_id'] ?? null) === null)),
            'ownedIds' => $ownedIds,
            /* Право спрашивается в области ЭТОЙ организации: её владелец
               заводит себе сервисы сам, для того области и заводились. */
            'canCreate' => ClientsController::mayCreate((int)$org['id']),
        ], '', $this->counts((int)$org['id']));
    }

    /** Люди: состав организации и те, кто ходит в её сервисы. */
    public function people(string $id): never
    {
        $org = $this->org($id);
        $orgId = (int)$org['id'];
        $projectIds = array_map(static fn(array $c): int => (int)$c['id'], Org::projects($orgId));

        org_page($org, 'people', 'admin/org/people', [
            'pageTitle' => t('Пользователи — :name', ['name' => $org['name']]),
            'people' => $this->peopleOf($orgId, $projectIds),
            'projectCount' => count($projectIds),
            'roles' => Org::ROLES,
            /* Роли на область организации — в строке участника (v4.68), тем же
               куском, что у людей сервиса. Действия организации доступны только
               глобальному администратору — кнопки выдачи рисуем ему же. */
            'scope' => $scope = \App\Core\Scope::org($orgId),
            'grantable' => \App\Core\RoleOffer::inScope($scope),
            'offerWhy' => \App\Core\RoleOffer::why($scope),
            'rolesByUser' => \App\Core\RoleOffer::grantsByUser($scope),
            'mayRoles' => $this->permittedClientIds() === null && \App\Core\Auth::can('roles.write'),
        ], '', $this->counts($orgId));
    }

    /**
     * Люди организации одним списком (v4.69): участники и те, кто ходит в её
     * сервисы. Двумя таблицами роль на организацию нельзя было выдать человеку
     * из сервиса, а «Полномочие» и «Роли» в строке участника показывали одно и
     * то же дважды — полномочие это и есть роли org_owner / org_admin.
     *
     * Сначала участники (по старшинству), за ними остальные — по свежести.
     *
     * @param list<int>|null $projectIds
     * @return list<array>
     */
    private function peopleOf(int $orgId, ?array $projectIds = null): array
    {
        $projectIds ??= array_map(static fn(array $c): int => (int)$c['id'], Org::projects($orgId));
        $rows = [];
        foreach (Org::members($orgId) as $m) {
            $rows[(int)$m['user_id']] = $m + ['is_member' => true, 'services' => 0];
        }
        if ($projectIds !== []) {
            $service = \App\Core\DB::all(
                'SELECT u.*, u.id AS user_id, COUNT(DISTINCT a.client_id) AS services
                   FROM {users} u JOIN {user_client_access} a ON a.user_id = u.id
                  WHERE ' . $this->inIds('a.client_id', $projectIds) . ' AND u.status <> ?
                  GROUP BY u.id ORDER BY u.id DESC LIMIT 200', ['deleted']);
            foreach ($service as $u) {
                $uid = (int)$u['user_id'];
                if (isset($rows[$uid])) {
                    $rows[$uid]['services'] = (int)$u['services'];
                    continue;
                }
                $rows[$uid] = $u + ['is_member' => false];
            }
        }
        return array_values($rows);
    }

    /**
     * Раздела «Роли» у организации больше нет (v4.68): своих ролей у неё нет, а
     * выдача на её область переехала в строку участника «Пользователей». Там же
     * рисовалась вторая таблица тех же людей со своим списком — два места для
     * одного действия. Адрес живёт ради закладок и старых ссылок.
     */
    public function roles(string $id): never
    {
        $org = $this->org($id);
        redirect('/admin/orgs/' . (int)$org['id'] . '/people');
    }

    /** Доступы: чужие порталы, куда пускают участников организации. */
    public function access(string $id): never
    {
        $org = $this->org($id, 'orgs.write');
        org_page($org, 'access', 'admin/org/access', [
            'pageTitle' => t('Доступы — :name', ['name' => $org['name']]),
            'clients' => Org::clients((int)$org['id']),
            'allClients' => Client::all(),
            'allRoles' => Role::all(),
        ], '', $this->counts((int)$org['id']));
    }

    /** Журнал: события организации и её сервисов. */
    public function logs(string $id): never
    {
        $org = $this->org($id, 'logs.read');
        $orgId = (int)$org['id'];
        $projectIds = array_map(static fn(array $c): int => (int)$c['id'], Org::projects($orgId));

        /* События организации ПЛЮС события её сервисов. Именно «плюс»: у
           события организации client_id пуст, и условие по порталам срезало бы
           самое главное (v4.27). */
        $byClient = $projectIds === [] ? '' : ' OR ' . $this->inIds('a.client_id', $projectIds);
        $rows = \App\Core\DB::all(
            'SELECT a.*, u.email AS actor_email, o.email AS on_email, c.name AS client_name
               FROM {audit_log} a
               LEFT JOIN {users} u ON u.id = a.actor_user_id
               LEFT JOIN {users} o ON o.id = a.on_user_id
               LEFT JOIN {clients} c ON c.id = a.client_id
              WHERE (a.org_id = ?' . $byClient . ')
              ORDER BY a.id DESC LIMIT 200', [$orgId]);

        org_page($org, 'logs', 'admin/org/logs', [
            'pageTitle' => t('Журнал — :name', ['name' => $org['name']]),
            'rows' => $rows,
            'projectCount' => count($projectIds),
        ], '', $this->counts($orgId));
    }

    /** Опасная зона организации — тот же механизм, что у портала. */
    public function danger(string $id): never
    {
        $this->requirePerm('orgs.delete');
        $this->requireGlobalAdmin();
        $org = Org::find((int)$id);
        if (!$org) ErrorPage::render(404);
        org_page($org, 'danger', 'admin/org/danger', [
            'pageTitle' => $org['name'],
            'unlocked' => \App\Core\DangerZone::unlocked('org:' . (int)$org['id']),
            'membersCount' => count(Org::members((int)$org['id'])),
            'clientsCount' => count(Org::clients((int)$org['id'])),
        ]);
    }

    public function dangerUnlock(string $id): never
    {
        $this->requirePerm('orgs.delete');
        $this->requireGlobalAdmin();
        $org = Org::find((int)$id);
        if (!$org) ErrorPage::render(404);
        $res = \App\Core\DangerZone::unlock('org:' . (int)$org['id'], (string)($_POST['password'] ?? ''));
        if (!$res['ok']) Flash::error($res['error']);
        redirect('/admin/orgs/' . $org['id'] . '/danger');
    }

    public function action(string $id): never
    {
        $this->requireGlobalAdmin();
        $org = Org::find((int)$id);
        if (!$org) ErrorPage::render(404);
        $orgId = (int)$org['id'];

        /* Право под каждое действие — таблицей до switch (см. порталы). */
        $act = (string)($_POST['action'] ?? '');
        $this->requirePerm($act === 'delete' ? 'orgs.delete' : 'orgs.write');

        /* Куда вернуть: в тот раздел, откуда пришли. Действие меняет часть
           работы, а не место работы, и выбрасывать человека на обзор после
           каждой правки значит заставлять его возвращаться руками. */
        $back = [
            'member_add' => '/people', 'member_remove' => '/people', 'set_role' => '/people',
            'role_grant' => '/people', 'role_revoke' => '/people',
            'project_attach' => '/projects', 'project_detach' => '/projects',
            'grant_client' => '/access', 'revoke_client' => '/access',
        ][$act] ?? '';

        switch ($act) {
            /* ── Сервисы организации: владение ─────────────────────────── */
            case 'project_attach':
                $client = Client::find((int)($_POST['client_id'] ?? 0));
                if (!$client) { Flash::error(t('Портал не найден.')); break; }
                if (($client['org_id'] ?? null) !== null) {
                    /* Чужой проект мимоходом не забираем: смена владельца —
                       решение, а не пункт выпадающего списка. */
                    Flash::error(t('Этот портал уже принадлежит другой организации. Сменить владельца можно в его карточке.'));
                    break;
                }
                Client::update((int)$client['id'], ['org_id' => $orgId]);
                \App\Core\AccessResolver::forget();
                Audit::log('admin.org.project_attach', ['org' => $orgId, 'client' => (int)$client['id']]);
                Flash::success(t('«:name» теперь сервис организации. Роли, выданные на неё, действуют и здесь.',
                    ['name' => $client['name']]));
                break;

            case 'project_detach':
                $client = Client::find((int)($_POST['client_id'] ?? 0));
                if (!$client || (int)($client['org_id'] ?? 0) !== $orgId) {
                    Flash::error(t('Это не сервис данной организации.'));
                    break;
                }
                Client::update((int)$client['id'], ['org_id' => null]);
                \App\Core\AccessResolver::forget();
                Audit::log('admin.org.project_detach', ['org' => $orgId, 'client' => (int)$client['id']]);
                Flash::success(t('«:name» отсоединён. Роли организации в нём больше не действуют.',
                    ['name' => $client['name']]));
                break;

            /* ── Роли на область организации ───────────────────────────── */
            case 'role_grant':
                $uid = (int)($_POST['user_id'] ?? 0);
                $rid = (int)($_POST['role_id'] ?? 0);
                $role = Role::find($rid);
                if ($uid <= 0 || !$role) { Flash::error(t('Выберите человека и роль.')); break; }
                if ($role['client_id'] !== null) {
                    /* Роль портала на организацию не ложится: её дом уже,
                       чем область выдачи (SCOPES §2.1). */
                    Flash::error(t('Роль сервиса нельзя выдать на организацию — только системную.'));
                    break;
                }
                /* Список рисуется в разметке, а приходит POST: спрятать
                   пункт и считать вопрос закрытым — значит охранять дверь
                   занавеской (v4.50). */
                if (!\App\Core\RoleOffer::allows(\App\Core\Scope::org($orgId), $rid)) {
                    Flash::error(t('Эту роль нельзя выдать на организацию: её место — система или отдельный сервис.'));
                    break;
                }
                /* Роль на организацию — участнику (v4.69). Её выдают теперь и
                   человеку из сервиса организации прямо в общем списке — он при
                   этом становится участником: роль без членства не видна ни в
                   составе, ни в claim orgs. */
                $joined = !\App\Core\DB::val('SELECT 1 FROM {org_members} WHERE org_id = ? AND user_id = ?',
                    [$orgId, $uid]);
                if ($joined) Org::addMember($orgId, $uid, 'member', (int)Auth::id());
                Role::assignScoped($uid, $rid, \App\Core\Scope::org($orgId), (int)Auth::id());
                Audit::log('admin.org.role_grant', ['org' => $orgId, 'on' => $uid,
                    'meta' => ['role' => $role['slug'], 'joined' => $joined]]);
                Flash::success(t('Роль «:role» выдана на всю организацию.', ['role' => $role['name']])
                    . ($joined ? ' ' . t('Человек добавлен в состав организации.') : ''));
                break;

            case 'role_revoke':
                $uid = (int)($_POST['user_id'] ?? 0);
                $rid = (int)($_POST['role_id'] ?? 0);
                Role::revokeScoped($uid, $rid, \App\Core\Scope::org($orgId));
                Audit::log('admin.org.role_revoke', ['org' => $orgId, 'on' => $uid,
                    'meta' => ['role_id' => $rid]]);
                Flash::success(t('Роль отозвана во всех сервисах организации.'));
                break;

            /* Прежние имена действий сохранены: на них ссылаются старые формы
               и закладки. Новые разделы зовут их же под понятными именами. */
            case 'member_add':
            case 'add_member':
                /* Из строки общего списка приходит user_id, из формы — почта или
                   логин (v4.69). Форма обещала «почта или логин», а искала только
                   по почте: человек, перенесённый с портала по логину, не
                   добавлялся вовсе. */
                $user = !empty($_POST['user_id'])
                    ? User::find((int)$_POST['user_id'])
                    : User::findByLoginOrEmail(trim((string)($_POST['email'] ?? '')));
                if (!$user) {
                    Flash::error('Пользователь с такой почтой или логином не найден. Сначала пригласите его в систему.');
                    break;
                }
                Org::addMember($orgId, (int)$user['id'], (string)($_POST['role'] ?? 'member'), (int)Auth::id());
                Audit::log('admin.org.member_add', ['org' => $orgId, 'on' => (int)$user['id'], 'org' => (int)$org['id'], 'meta' => ['org' => $org['slug']]]);
                Flash::success(t(':name добавлен в организацию.', ['name' => user_name($user)]));
                break;

            case 'member_remove':
            case 'remove_member':
                Org::removeMember($orgId, (int)($_POST['user_id'] ?? 0));
                Audit::log('admin.org.member_remove', ['org' => $orgId, 'on' => (int)($_POST['user_id'] ?? 0),
                    'meta' => ['org' => $org['slug']]]);
                Flash::success('Участник удалён из организации.');
                break;

            case 'grant_client':
                $client = Client::find((int)($_POST['client_id'] ?? 0));
                if (!$client) break;
                Org::grantClient($orgId, (int)$client['id'], (int)($_POST['role_id'] ?? 0) ?: null, (int)Auth::id());
                Audit::log('admin.org.client_grant', ['org' => $orgId, 'client' => (int)$client['id'],
                    'meta' => ['org' => $org['slug']]]);
                Flash::success(t('Доступ к «:portal» выдан всей организации.', ['portal' => $client['name']]));
                break;

            case 'revoke_client':
                Org::revokeClient($orgId, (int)($_POST['client_id'] ?? 0));
                Audit::log('admin.org.client_revoke', ['org' => $orgId, 'client' => (int)($_POST['client_id'] ?? 0),
                    'meta' => ['org' => $org['slug']]]);
                Flash::info(t('Доступ организации отозван. Уже выданные персональные доступы остаются — снимите их в карточках пользователей, если нужно.'));
                break;

            /* Переименование и описание: организация живёт годами, а название
               отдела меняется чаще, чем состав. Слаг не трогаем — он уезжает
               в claim `orgs` и на той стороне уже разобран по строке. */
            case 'rename':
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') {
                    Flash::error('У организации должно быть название.');
                    break;
                }
                Org::rename($orgId, $name, trim((string)($_POST['description'] ?? '')) ?: null);
                Audit::log('admin.org.update', ['org' => $orgId, 'meta' => ['org' => $org['slug'], 'name' => $name]]);
                /* «Организация включена» — тумблер той же формы (v4.91). С 4.29
                   в обзоре стояла кнопка, которая слала «disable»/«enable», а
                   таких действий здесь нет: выключить организацию из обзора
                   было нельзя, кнопка молча ничего не делала. */
                if (isset($_POST['active_present'])) {
                    $want = !empty($_POST['is_active']) ? 1 : 0;
                    if ($want !== (int)$org['is_active']) {
                        \App\Core\DB::update('orgs', ['is_active' => $want], 'id = ?', [$orgId]);
                        Audit::log('admin.org.toggle', ['org' => $orgId, 'meta' => ['org' => $org['slug'], 'active' => $want]]);
                        Flash::success($want ? t('Организация включена.') : t('Организация выключена: участники больше не получают доступ через неё.'));
                        break;
                    }
                }
                Flash::success('Организация обновлена.');
                break;

            /* Роль внутри организации меняется на месте: убирать человека и
               добавлять заново значило бы терять дату вступления. */
            case 'set_role':
                $userId = (int)($_POST['user_id'] ?? 0);
                $role = (string)($_POST['role'] ?? '');
                if (!isset(Org::ROLES[$role])) {
                    Flash::error('Такой роли в организации нет.');
                    break;
                }
                if (!Org::setRole($orgId, $userId, $role)) {
                    Flash::error('Участник не найден.');
                    break;
                }
                Audit::log('admin.org.member_role', ['org' => $orgId, 'on' => $userId,
                    'meta' => ['org' => $org['slug'], 'role' => $role]]);
                Flash::success('Роль в организации изменена.');
                break;

            case 'toggle':
                $now = (int)$org['is_active'] ? 0 : 1;
                \App\Core\DB::update('orgs', ['is_active' => $now], 'id = ?', [$orgId]);
                /* Единственное действие раздела, которое раньше не попадало в
                   аудит, — а выключение организации снимает доступ со всех её
                   участников разом. */
                Audit::log('admin.org.toggle', ['org' => $orgId, 'meta' => ['org' => $org['slug'], 'active' => $now]]);
                Flash::success($now ? t('Организация включена.') : t('Организация выключена: участники больше не получают доступ через неё.'));
                break;

            case 'delete':
                /* Удаление — только из опасной зоны и только после пароля.
                   Раньше здесь стояла кнопка с одним «вы уверены?», хотя
                   уносит она состав и все доступы разом. */
                if (!\App\Core\DangerZone::unlocked('org:' . $orgId)) {
                    Flash::error('Опасная зона закрыта — подтвердите пароль заново.');
                    redirect('/admin/orgs/' . $orgId . '/danger');
                }
                if (trim((string)($_POST['confirm'] ?? '')) !== (string)$org['slug']) {
                    Flash::error('Слаг организации набран неверно — ничего не тронуто.');
                    redirect('/admin/orgs/' . $orgId . '/danger');
                }
                $removed = Org::purge($orgId);
                \App\Core\DangerZone::close('org:' . $orgId);
                \App\Core\Log::warning('admin.org.delete', ['org' => $org['slug'], 'removed' => $removed]);
                Audit::log('admin.org.delete', ['org' => $orgId, 'meta' => ['name' => $org['name'], 'removed' => $removed]]);
                Flash::success(t('Организация «:org» удалена. Строк убрано: :n.', ['org' => $org['name'], 'n' => array_sum($removed)]));
                redirect('/admin/orgs');
        }
        redirect('/admin/orgs/' . $orgId . $back);
    }
}
