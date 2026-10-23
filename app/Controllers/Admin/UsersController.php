<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\ErrorPage;
use App\Core\Flash;
use App\Core\Mailer;
use App\Core\Setting;
use App\Core\View;
use App\Models\Access;
use App\Models\Client;
use App\Models\OneTimeToken;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\SessionModel;
use App\Models\User;
use App\Oidc\EventDispatcher;

/** /admin/users: таблица с фильтрами + карточка с вкладками (§5.2). */
final class UsersController extends AdminBase
{
    private const PER_PAGE = 25;

    public function index(): never
    {
        $this->requirePerm('users.read');
        $scope = $this->scopeClientIds();
        $this->rememberListQuery('users');
        $q = trim((string)($_GET['q'] ?? ''));
        $status = (string)($_GET['status'] ?? '');
        $clientF = (int)($_GET['client'] ?? 0);
        $roleF = (int)($_GET['role'] ?? 0);
        $flag = (string)($_GET['flag'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));

        $where = [];
        $params = [];
        /* Условие берём общее с API: «кого показывать» — один вопрос, и ответ
           на него обязан быть один. См. User::statusWhere(). */
        [$stWhere, $stParams] = User::statusWhere($status, 'u');
        $where[] = $stWhere;
        array_push($params, ...$stParams);
        if ($scope !== null) {
            $where[] = 'u.id IN (SELECT user_id FROM {user_client_access} WHERE '
                . $this->inIds('client_id', $scope) . ')';
        }
        if ($q !== '') {
            [$sqlPart, $qParams] = user_search_clause($q);
            $where[] = $sqlPart;
            array_push($params, ...$qParams);
        }
        if ($clientF > 0) {
            if ($scope !== null && !in_array($clientF, $scope, true)) ErrorPage::render(403);
            $where[] = 'u.id IN (SELECT user_id FROM {user_client_access} WHERE client_id = ?)';
            $params[] = $clientF;
        }
        if ($roleF > 0) {
            $where[] = 'u.id IN (SELECT user_id FROM {user_roles} WHERE role_id = ?)';
            $params[] = $roleF;
        }
        if ($flag === '2fa') $where[] = 'u.totp_enabled_at IS NOT NULL';
        if ($flag === 'no_email') $where[] = 'u.email_verified_at IS NULL';

        $whereSql = implode(' AND ', $where);
        $total = (int)DB::val("SELECT COUNT(*) FROM {users} u WHERE $whereSql", $params);
        $users = DB::all(
            "SELECT u.* FROM {users} u WHERE $whereSql ORDER BY u.id DESC LIMIT " . self::PER_PAGE
            . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);

        // Роли и порталы одним запросом на страницу
        $ids = array_map(fn($u) => (int)$u['id'], $users);
        $rolesBy = $accessBy = [];
        if ($ids !== []) {
            $in = implode(',', $ids);
            /* Роли складываем по НАЗВАНИЮ, а не по выдаче.
               Одна и та же роль выдаётся в разных областях — на систему, на
               организацию, на сервис, — и список честно печатал её столько раз,
               сколько выдач: у владельца выходила стена одинаковых плашек, из
               которой ничего не следует. Список отвечает на вопрос «кто это»,
               а не «как именно ему это досталось»: подробности — в карточке. */
            foreach (DB::all("SELECT ur.user_id, r.slug, r.name, r.client_id FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id WHERE ur.user_id IN ($in)") as $r) {
                $rolesBy[(int)$r['user_id']][(string)$r['slug']] = $r;
            }
            foreach ($rolesBy as $uid => $set) $rolesBy[$uid] = array_values($set);
            foreach (DB::all("SELECT a.user_id, c.name FROM {user_client_access} a JOIN {clients} c ON c.id = a.client_id WHERE a.user_id IN ($in)") as $a) {
                $accessBy[(int)$a['user_id']][] = $a['name'];
            }
        }

        View::render('admin/users', [
            'pageTitle' => t('Пользователи'),
            'users' => $users,
            'rolesBy' => $rolesBy,
            'accessBy' => $accessBy,
            'total' => $total,
            'page' => $page,
            'pages' => (int)ceil($total / self::PER_PAGE),
            'clients' => $scope === null ? Client::all() : array_filter(Client::all(), fn($c) => in_array((int)$c['id'], $scope, true)),
            'roles' => Role::all(),
            'filters' => compact('q', 'status', 'clientF', 'roleF', 'flag'),
            /* v4.95: боты разбираются своей страницей — отсюда к ней одна строка. */
            'bots' => Auth::isAdmin() && Auth::can('users.delete') ? \App\Core\BotSignal::suspectCount() : 0,
        ]);
    }

    /** Человек раздела: одинаковое начало у всех разделов оболочки. */
    private function person(string $id, string $perm = 'users.read'): array
    {
        $this->requirePerm($perm);
        $user = User::find((int)$id);
        if (!$user) ErrorPage::render(404);
        $this->assertUserScope((int)$user['id']);
        return $user;
    }

    private function personCounts(array $user): array
    {
        $id = (int)$user['id'];
        return [
            'access' => count(Access::forUser($id)),
            'roles' => count(Role::ofUser($id)),
            'sessions' => count(SessionModel::activeForUser($id)),
        ];
    }

    private function personPage(array $user, string $section, array $data): never
    {
        object_page(\App\Core\UserMap::class, $user, $section, 'admin/user/' . $section,
            [
                'pageTitle' => user_name($user),
                'u' => $user,
                'isGlobalAdmin' => $this->permittedClientIds() === null,
            ] + $data,
            '', $this->personCounts($user), $this->listBackUrl('users', '/admin/users'));
    }

    public function card(string $id): never
    {
        $user = $this->person($id);
        $this->personPage($user, 'profile', [
            'roles' => Role::ofUser((int)$user['id']),
            /* Систематизировано (v4.50): на всю систему осмысленно выдать
               только системные роли, а не весь список из всех порталов. */
            'allRoles' => \App\Core\RoleOffer::inScope(\App\Core\Scope::system()),
        ]);
    }

    public function accessSection(string $id): never
    {
        $user = $this->person($id);
        $scope = $this->permittedClientIds();
        $this->personPage($user, 'access', [
            'access' => Access::forUser((int)$user['id']),
            'clients' => $scope === null ? Client::all()
                : array_filter(Client::all(), fn($c) => in_array((int)$c['id'], $scope, true)),
        ]);
    }

    public function rolesSection(string $id): never
    {
        $user = $this->person($id);
        $this->personPage($user, 'roles', [
            'roles' => Role::ofUser((int)$user['id']),
            /* Систематизировано (v4.50): на всю систему осмысленно выдать
               только системные роли, а не весь список из всех порталов. */
            'allRoles' => \App\Core\RoleOffer::inScope(\App\Core\Scope::system()),
            /* Область у каждой выдачи: без неё «роль есть» ничего не говорит
               о том, где она действует (SCOPES §2). */
            'grants' => \App\Core\AccessResolver::ready()
                ? \App\Core\DB::all(
                    'SELECT r.id, r.slug, r.name, r.access_level, ur.scope_type, ur.scope_id
                       FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id
                      WHERE ur.user_id = ? ORDER BY r.access_level DESC', [(int)$user['id']])
                : [],
        ]);
    }

    public function sessions(string $id): never
    {
        $user = $this->person($id);
        $this->personPage($user, 'sessions', [
            'sessions' => SessionModel::activeForUser((int)$user['id']),
        ]);
    }

    /** Активность человека (v4.72): каждый его запрос и адреса, с которых он приходил. */
    public function activity(string $id): never
    {
        $user = $this->person($id, 'logs.read');
        $this->requireGlobalAdmin();
        $this->personPage($user, 'activity', [
            'addresses' => \App\Core\ActivityLog::addressesOf((int)$user['id']),
            'rows' => \App\Core\ActivityLog::find(['user' => (int)$user['id'],
                'status' => (string)($_GET['status'] ?? ''), 'before' => (int)($_GET['before'] ?? 0)], 100),
        ]);
    }

    public function audit(string $id): never
    {
        $user = $this->person($id, 'logs.read');
        $this->personPage($user, 'audit', [
            /* История объекта — тем же читателем, что у портала и организации.
               У человека она двусторонняя: и что делали с ним, и что делал он. */
            'history' => \App\Core\ObjectHistory::of('user', (int)$user['id']),
            /* Связи рядом с историей: видно, что у человека есть, не уходя
               в соседние разделы. */
            'roles' => Role::ofUser((int)$user['id']),
            'access' => Access::forUser((int)$user['id']),
        ]);
    }

    /**
     * Действия над человеком: блокировка, ссылка для входа, имперсонизация.
     *
     * Не «опасная зона»: та за паролем и про окончательное удаление, и живёт
     * своей страницей. Смешать их значило бы прятать повседневное за паролем
     * либо, наоборот, положить необратимое рядом с обычными кнопками.
     */
    public function actions(string $id): never
    {
        $user = $this->person($id, 'users.write');
        $this->personPage($user, 'actions', []);
    }

    /** Правка профиля из админки (глобальные админы). */
    public function update(string $id): never
    {
        $this->requirePerm('users.write');
        $this->requireGlobalAdmin();
        $user = User::find((int)$id);
        if (!$user) ErrorPage::render(404);
        User::update((int)$user['id'], [
            'last_name' => trim((string)($_POST['last_name'] ?? '')) ?: null,
            'first_name' => trim((string)($_POST['first_name'] ?? '')) ?: null,
            'display_name' => trim((string)($_POST['display_name'] ?? '')) ?: null,
            'is_verified_badge' => !empty($_POST['is_verified_badge']) ? 1 : 0,
        ]);
        Audit::log('admin.user.update', ['on' => (int)$user['id']]);
        EventDispatcher::userEvent(User::find((int)$user['id']), 'user.updated', ['fields' => ['profile']]);
        Flash::success('Профиль обновлён.');
        redirect('/admin/users/' . $user['id']);
    }

    /** Действия: block/unblock/verify_email/reset_link/delete/impersonate. */
    /** Опасная зона пользователя — тот же механизм, что у порталов. */
    public function danger(string $id): never
    {
        $this->requirePerm('users.delete');
        $this->requireGlobalAdmin();
        $user = User::find((int)$id);
        if (!$user) ErrorPage::render(404);
        $this->personPage($user, 'danger', [
            'unlocked' => \App\Core\DangerZone::unlocked('user:' . (int)$user['id']),
            'isSuper' => in_array('superadmin', User::globalRoleSlugs((int)$user['id']), true),
        ]);
    }

    /**
     * GET /admin/users/{id}/merge — объединить с другим аккаунтом (v4.88).
     *
     * Владелец 27.09: «было бы неплохо иметь функционал по объединению
     * аккаунтов из админки». Страница показывает возможные дубли
     * (DuplicateHint), ищет второй аккаунт по почте, логину или номеру и
     * ставит обе стороны рядом: что у каждой есть и что куда переедет.
     * Слияние необратимо, поэтому оно за тем же паролем, что и удаление.
     */
    public function merge(string $id): never
    {
        $this->requirePerm('users.delete');
        $this->requireGlobalAdmin();
        $user = User::find((int)$id);
        if (!$user || $user['status'] === 'deleted') ErrorPage::render(404);
        $q = trim((string)($_GET['with'] ?? ''));
        $other = $q !== '' ? \App\Core\MergeCompare::find($q) : null;
        if ($other !== null && ((int)$other['id'] === (int)$user['id'] || $other['status'] === 'deleted')) $other = null;
        $this->personPage($user, 'merge', [
            'unlocked' => \App\Core\DangerZone::unlocked('user:' . (int)$user['id']),
            'hints' => \App\Core\DuplicateHint::forUser($user),
            'query' => $q,
            'other' => $other,
            'sides' => $other !== null ? [\App\Core\MergeCompare::side($user), \App\Core\MergeCompare::side($other)] : [],
        ]);
    }

    /** POST /admin/users/{id}/merge — слить: keep=this — второй вливается сюда, keep=other — наоборот. */
    public function mergeConfirm(string $id): never
    {
        $this->requirePerm('users.delete');
        $this->requireGlobalAdmin();
        $user = User::find((int)$id);
        if (!$user || $user['status'] === 'deleted') ErrorPage::render(404);
        if (!\App\Core\DangerZone::unlocked('user:' . (int)$user['id'])) {
            Flash::error(t('Сначала подтвердите пароль — слияние необратимо.'));
            redirect('/admin/users/' . (int)$user['id'] . '/merge');
        }
        $other = User::find((int)($_POST['with'] ?? 0));
        if (!$other || (int)$other['id'] === (int)$user['id'] || $other['status'] === 'deleted') {
            Flash::error(t('Второй аккаунт не найден.'));
            redirect('/admin/users/' . (int)$user['id'] . '/merge');
        }
        [$main, $dup] = ($_POST['keep'] ?? '') === 'other' ? [$other, $user] : [$user, $other];
        /* Вливаемый аккаунт исчезает. Последнего, кто может управлять
           системой, так не убрать — как и удалением. */
        if (in_array('superadmin', User::globalRoleSlugs((int)$dup['id']), true)) {
            Flash::error(t('Вливаемый аккаунт — суперадмин. Оставьте его, а влейте другой, или сначала снимите роль.'));
            redirect('/admin/users/' . (int)$user['id'] . '/merge?with=' . (int)$other['id']);
        }
        /* v5.4: и главный тоже. Влив свой аккаунт в суперадмина, admin
           приносил туда свои ключи и соцвходы — и входил суперадмином. */
        if (in_array('superadmin', User::globalRoleSlugs((int)$main['id']), true)) $this->requireSuperadmin();
        try {
            $stat = \App\Core\AccountMerge::into((int)$main['id'], (int)$dup['id'], (int)Auth::id());
        } catch (\Throwable $e) {
            Flash::error(t('Не удалось объединить: :why', ['why' => $e->getMessage()]));
            redirect('/admin/users/' . (int)$user['id'] . '/merge?with=' . (int)$other['id']);
        }
        Flash::success(t('Аккаунты объединены: :dup влит в :main. Перенесено: сервисов входа :i, ключей :k, доступов :a, ролей :r, организаций :o.', [
            'dup' => user_handle($dup), 'main' => user_handle($main),
            'i' => $stat['moved_identities'], 'k' => $stat['moved_passkeys'], 'a' => $stat['moved_access'],
            'r' => $stat['moved_roles'], 'o' => $stat['moved_orgs'],
        ]));
        redirect('/admin/users/' . (int)$main['id']);
    }

    public function dangerUnlock(string $id): never
    {
        $this->requirePerm('users.delete');
        $this->requireGlobalAdmin();
        $user = User::find((int)$id);
        if (!$user) ErrorPage::render(404);
        $res = \App\Core\DangerZone::unlock('user:' . (int)$user['id'], (string)($_POST['password'] ?? ''));
        if (!$res['ok']) Flash::error($res['error']);
        /* Тот же пароль открывает и объединение (v4.88) — вернуть туда, откуда пришли. */
        if (($_POST['back'] ?? '') === 'merge') {
            $with = (int)($_POST['with'] ?? 0);
            redirect('/admin/users/' . $user['id'] . '/merge' . ($with > 0 ? '?with=' . $with : ''));
        }
        redirect('/admin/users/' . $user['id'] . '/danger');
    }

    public function action(string $id): never
    {
        $user = User::find((int)$id);
        if (!$user) ErrorPage::render(404);
        $this->assertUserScope((int)$user['id']);
        $action = (string)($_POST['action'] ?? '');
        $back = '/admin/users/' . $user['id'];

        // client_admin может только одобрять доступы своего портала — не трогать аккаунт
        if (!Auth::isAdmin()) ErrorPage::render(403);

        /* Право под каждое действие. Один `switch` на десяток случаев — как раз
           то место, где проверку легко забыть, поэтому она стоит до него
           таблицей: добавить случай и не заметить, что права нет, не выйдет. */
        $needs = [
            'block' => 'users.write', 'unblock' => 'users.write',
            'verify_email' => 'users.write', 'reset_link' => 'users.write',
            'login_link' => 'users.write', 'account_number' => 'users.write',
            'logout_everywhere' => 'users.write', 'logout_sessions' => 'users.write',
            'delete' => 'users.delete', 'purge' => 'users.delete',
            'impersonate' => 'impersonate',
        ];
        if (isset($needs[$action])) $this->requirePerm($needs[$action]);

        switch ($action) {
            case 'block':
                if (\App\Core\UserBlock::forbidden((int)$user['id'])) {
                    Flash::error('Суперадмина нельзя заблокировать.');
                    redirect($back);
                }
                \App\Core\UserBlock::apply($user, 'admin');
                \App\Core\AdminNotify::say('user.blocked',
                    fn() => t('Аккаунт :who заблокирован.', ['who' => user_handle($user)]),
                    fn() => [t('Кто') => user_handle(\App\Core\Auth::user() ?? []), t('Когда') => fmt_dt(now())]);
                Audit::log('admin.user.block', ['on' => (int)$user['id']]);
                Flash::success(t('Пользователь заблокирован, порталы оповещены.'));
                break;

            case 'unblock':
                User::update((int)$user['id'], ['status' => $user['email_verified_at'] ? 'active' : 'pending', 'locked_until' => null, 'failed_logins' => 0]);
                Audit::log('admin.user.unblock', ['on' => (int)$user['id']]);
                Flash::success('Пользователь разблокирован.');
                break;

            case 'verify_email':
                User::update((int)$user['id'], ['email_verified_at' => now(),
                    'status' => $user['status'] === 'pending' ? 'active' : $user['status']]
                    + (\App\Core\DB::columnExists('users', 'email_gate') ? ['email_gate' => 0] : []));
                Audit::log('admin.user.update', ['on' => (int)$user['id'], 'meta' => ['field' => 'email_verified']]);
                Flash::success('Почта подтверждена вручную.');
                break;

            /* Номер аккаунта меняет только суперадмин и только вручную: это то,
               что человек называет в поддержке, и менять его «на всякий случай»
               нельзя. Старый номер остаётся в аудите — иначе после подмены
               обращения к прежнему номеру повисли бы в воздухе. */
            case 'account_number':
                if (!Auth::isSuperadmin()) {
                    Flash::error('Номер аккаунта меняет только суперадмин.');
                    redirect($back);
                }
                $raw = (string)($_POST['account_number'] ?? '');
                $number = \App\Core\AccountNumber::normalize($raw);
                if ($number === null) {
                    Flash::error('Номер не подходит: девять цифр с верной контрольной цифрой.');
                    redirect($back);
                }
                $owner = User::findByNumber($number);
                if ($owner && (int)$owner['id'] !== (int)$user['id']) {
                    Flash::error('Этот номер уже у другого аккаунта.');
                    redirect($back);
                }
                $was = (string)($user['account_number'] ?? '');
                User::update((int)$user['id'], ['account_number' => $number]);
                Audit::log('admin.user.update', ['on' => (int)$user['id'],
                    'meta' => ['field' => 'account_number', 'was' => $was, 'now' => $number]]);
                Flash::success(t('Номер аккаунта изменён на :number.', ['number' => \App\Core\AccountNumber::format($number)]));
                break;

            /**
             * Ссылка для входа — для поддержки: человек не помнит пароль и не
             * имеет доступа к почте, а зайти надо сейчас.
             *
             * Показывается один раз и живёт 15 минут: это фактически вход в
             * чужой аккаунт, и лежать в переписке она не должна. Владельцу
             * уходит уведомление — он обязан знать, что кто-то такую ссылку
             * для него сделал. Без уведомления это был бы тихий доступ.
             */
            case 'login_link':
                $this->requireGlobalAdmin();
                /* v5.4: ссылка входа — это вход в чужой аккаунт. В аккаунт
                   суперадмина её делает только суперадмин, иначе admin
                   поднимал себя до суперадмина одной кнопкой. */
                if (in_array('superadmin', User::globalRoleSlugs((int)$user['id']), true)) $this->requireSuperadmin();
                $token = OneTimeToken::issue((int)$user['id'], 'login_link');
                $_SESSION['fresh_login_link'] = [
                    'user' => (int)$user['id'], 'url' => base_url('/magic/' . $token),
                ];
                /* Мимо настроек уведомлений намеренно: это единственное, что
                   отличает помощь поддержки от тихого входа в чужой аккаунт.
                   Отключаемым такое письмо быть не должно.

                   Если почты нет, предупредить владельца нечем, и остаётся
                   только журнал — он ведётся всегда и виден самому человеку в
                   профиле. Отказать в ссылке было бы хуже: такому человеку
                   больше нечем помочь вовсе. */
                /* Письмо человеку, а не администратору — на языке получателя (v4.74). */
                if (!empty($user['email'])) Mailer::as($user['email'], static fn() => Mailer::send($user['email'],
                    t('Для вашего аккаунта создана ссылка входа — :app', ['app' => Setting::appName()]),
                    'security_alert', [
                        'name' => user_name($user),
                        'message' => t('Администратор :admin создал одноразовую ссылку для входа в ваш аккаунт. Она действует 15 минут. Если вы об этом не просили — смените пароль и сообщите владельцу сервиса.',
                            ['admin' => user_name(Auth::realUser() ?? [])]),
                        'event' => 'admin.login_link',
                        'when' => fmt_dt(now(), 'd.m.Y H:i'),
                        'device' => device_text(device_label()),
                        'ip' => ip_masked(ip_bin()),
                        'action_url' => base_url('/profile/security'),
                        'action_label' => t('Проверить безопасность'),
                    ]));
                Audit::log('admin.user.login_link', ['on' => (int)$user['id']]);
                Flash::success('Ссылка создана. Она одноразовая и живёт 15 минут — передайте её сейчас.');
                break;

            /**
             * Окончательное удаление, минуя 14-дневный холд.
             *
             * Холд защищает от случайности, а не от намерения: он даёт время
             * передумать тому, кто нажал не туда. Намерение подтверждается
             * иначе — паролем в опасной зоне и набранным адресом. Держать
             * человека в базе две недели после осознанного решения незачем,
             * а иногда и нельзя: удаление по требованию бывает срочным.
             */
            case 'purge':
                $this->requireGlobalAdmin();
                if (!\App\Core\DangerZone::unlocked('user:' . (int)$user['id'])) {
                    Flash::error('Опасная зона закрыта — подтвердите пароль заново.');
                    redirect('/admin/users/' . $user['id'] . '/danger');
                }
                if (in_array('superadmin', User::globalRoleSlugs((int)$user['id']), true)) {
                    Flash::error('Суперадмина удалить нельзя: сначала снимите роль.');
                    redirect('/admin/users/' . $user['id'] . '/danger');
                }
                /* Набрать просят то, чем аккаунт назван, а не обязательно адрес:
                   у человека из портала, где регистрация шла логином, почты
                   нет, и сверка с пустой строкой пропускала бы пустую форму —
                   то есть снимала бы подтверждение вовсе. */
                $handle = user_handle($user);
                if ($handle === '—' || trim((string)($_POST['confirm'] ?? '')) !== $handle) {
                    Flash::error('Набрано неверно — аккаунт не тронут.');
                    redirect('/admin/users/' . $user['id'] . '/danger');
                }
                /* Порталы оповещаем ДО удаления, и это не придирка к порядку:
                   событие рассылается по строкам доступа, а purge() их сносит.
                   После него рассылать некому — портал так и остаётся с живым
                   аккаунтом человека, которого у нас уже нет. Уходил только
                   вебхук, а мост слушает не его, а свой приёмник событий. */
                EventDispatcher::userDeleted($user, 'purged');
                $removed = User::purge((int)$user['id']);
                \App\Core\DangerZone::close('user:' . (int)$user['id']);
                /* В файловый лог тоже: запись в аудите ссылается на аккаунт,
                   которого больше нет, и по ней уже ничего не восстановить. */
                \App\Core\Log::warning('admin.user.purge', [
                    'email' => $user['email'], 'sub' => $user['public_id'], 'removed' => $removed,
                ]);
                Audit::log('admin.user.purge', ['meta' => [
                    'email' => $user['email'], 'sub' => $user['public_id'], 'removed' => $removed,
                ]]);
                \App\Core\WebhookDispatcher::emit('user.deleted',
                    ['sub' => $user['public_id'], 'email' => $user['email'], 'mode' => 'purged']);
                Flash::success(t('Аккаунт удалён окончательно. Строк убрано: :n.', ['n' => array_sum($removed)]));
                redirect('/admin/users');

            case 'reset_link':
                // Пароль руками не задаётся никогда (§5.2) — только ссылка
                /* v5.4: ссылка сброса суперадмину без почты показывается
                   здесь — тот же вход в чужой аккаунт, что и login_link. */
                if (in_array('superadmin', User::globalRoleSlugs((int)$user['id']), true)) $this->requireSuperadmin();
                $token = OneTimeToken::issue((int)$user['id'], 'password_reset');
                /* Человеку без почты письмо слать некуда, а восстановиться он
                   сам не может — это плата за перенос из сервиса, где почты не
                   спрашивали (§13.2). Тогда ссылку показываем администратору
                   один раз, и он передаёт её тем каналом, каким уже общается с
                   этим человеком. Иначе единственным выходом остаётся «заведите
                   ему аккаунт заново», а это потеря истории и доступов. */
                if (empty($user['email'])) {
                    $_SESSION['fresh_login_link'] = [
                        'user' => (int)$user['id'], 'url' => base_url('/recover/' . $token),
                        'kind' => 'reset',
                    ];
                    Audit::log('admin.user.update', ['on' => (int)$user['id'],
                        'meta' => ['field' => 'reset_link', 'shown' => true]]);
                    Flash::info('У аккаунта нет почты — ссылка показана здесь. Она одноразовая; передайте её человеку сами.');
                    break;
                }
                Mailer::sendLetter($user['email'], 'reset', [
                    'name' => user_name($user), 'url' => base_url('/recover/' . $token),
                ]);
                Audit::log('admin.user.update', ['on' => (int)$user['id'], 'meta' => ['field' => 'reset_link']]);
                Flash::success('Ссылка сброса отправлена на почту пользователя.');
                break;

            case 'delete':
                if (in_array('superadmin', User::globalRoleSlugs((int)$user['id']), true)) {
                    Flash::error('Суперадмина нельзя удалить.');
                    redirect($back);
                }
                User::update((int)$user['id'], ['status' => 'deleted', 'deleted_at' => now()]);
                foreach (SessionModel::activeForUser((int)$user['id']) as $s) {
                    EventDispatcher::sessionLogout((int)$s['id']);
                }
                SessionModel::revokeAllForUser((int)$user['id']);
                RefreshToken::revokeAllForUser((int)$user['id']);
                /* «Помечен, две недели можно отменить»: портал отключает, но не
                   стирает (v4.62). Стереть сразу — это purge. */
                EventDispatcher::userDeleted($user, 'scheduled');
                Audit::log('admin.user.delete', ['on' => (int)$user['id']]);
                \App\Core\WebhookDispatcher::emit('user.deleted', ['sub' => $user['public_id'], 'email' => $user['email'],
                    'mode' => 'scheduled']);
                Flash::success('Аккаунт помечен на удаление (анонимизация через 14 дней).');
                redirect('/admin/users');

            case 'logout_everywhere':
                foreach (SessionModel::activeForUser((int)$user['id']) as $s) {
                    EventDispatcher::sessionLogout((int)$s['id']);
                }
                SessionModel::revokeAllForUser((int)$user['id']);
                Audit::log('session.revoke_all', ['on' => (int)$user['id'], 'meta' => ['by' => 'admin']]);
                Flash::success('Все сессии пользователя завершены.');
                redirect('/admin/users/' . $user['id'] . '/sessions');

            /* Одно устройство из списка «Сессии» (v4.92) — той же дорогой, что
               «Устройства» в профиле. Свою текущую сессию так не закрыть. */
            case 'logout_sessions':
                $ids = array_filter(array_map('intval', explode(',', (string)($_POST['session_ids'] ?? ''))));
                $done = SessionModel::endMany((int)$user['id'], $ids, (int)(Auth::sessionRecord()['id'] ?? 0));
                if ($done !== []) {
                    Audit::log('session.revoke', ['on' => (int)$user['id'], 'meta' => ['by' => 'admin', 'sessions' => $done]]);
                    Flash::success(t('Завершено сессий: :n.', ['n' => count($done)]));
                }
                redirect('/admin/users/' . $user['id'] . '/sessions');

            case 'impersonate':
                $this->requireSuperadmin(); // только superadmin и со следом (§5.2)
                $_SESSION['impersonate_user_id'] = (int)$user['id'];
                Audit::log('admin.impersonate.start', ['on' => (int)$user['id']]);
                Flash::info(t('Вы смотрите как :name. Плашка сверху — выход из режима.', ['name' => user_name($user)]));
                redirect('/profile');
        }
        redirect($back);
    }

    public function stopImpersonate(): never
    {
        if (!empty($_SESSION['impersonate_user_id'])) {
            Audit::log('admin.impersonate.stop', ['on' => (int)$_SESSION['impersonate_user_id']]);
            unset($_SESSION['impersonate_user_id']);
        }
        redirect('/admin/users');
    }

    /** Доступы к порталам: выдать/отозвать/одобрить/отклонить (§9.1). */
    public function accessAction(string $id): never
    {
        $this->requirePerm('users.write');
        $user = User::find((int)$id);
        if (!$user) ErrorPage::render(404);
        $client = Client::find((int)($_POST['client_id'] ?? 0));
        if (!$client) ErrorPage::render(404);
        $this->assertClientScope((int)$client['id']);
        $action = (string)($_POST['access'] ?? '');
        $actorId = (int)Auth::id();
        /* Адрес возврата из формы — только путь на себя (v4.70): раньше поле
           уходило в Location как есть, и абсолютный адрес уводил на чужой сайт. */
        $back = local_path((string)($_POST['back'] ?? ''), '/admin/users/' . $user['id']);

        /* Себе доступ из админки не закрывают и не убирают (v4.67). Кнопка стоит
           и в своей же строке «Людей» портала: одно нажатие — и администратор
           выкидывает себя из сервиса, которым управляет, а после «Убрать» сервис
           ещё и стирает его данные там. */
        if ((int)$user['id'] === $actorId && in_array($action, ['revoke', 'deny'], true)) {
            Flash::error('Себе доступ здесь не закрывают — это делает другой администратор.');
            redirect($back);
        }

        switch ($action) {
            case 'grant':
            case 'approve':
                Access::grant((int)$user['id'], (int)$client['id'], $actorId, 'active');
                Audit::log('access.granted', ['on' => (int)$user['id'], 'client' => (int)$client['id']]);
                Flash::success('Доступ выдан.');
                break;
            case 'deny':
                $was = Access::get((int)$user['id'], (int)$client['id']);
                Access::remove((int)$user['id'], (int)$client['id']);
                Audit::log('access.denied', ['on' => (int)$user['id'], 'client' => (int)$client['id']]);
                Flash::success(($was['status'] ?? '') === 'pending' ? t('Заявка отклонена.')
                    : t('Запись о доступе убрана — сервис сотрёт учётную запись этого человека и его данные.'));
                break;
            case 'revoke':
                $was = (string)(Access::get((int)$user['id'], (int)$client['id'])['status'] ?? '');
                Access::setStatus((int)$user['id'], (int)$client['id'], 'blocked');
                DB::run('UPDATE {refresh_tokens} SET revoked_at = ? WHERE user_id = ? AND client_id = ? AND revoked_at IS NULL',
                    [now(), (int)$user['id'], (int)$client['id']]);
                /* Порталу сообщает сам Access::setStatus — одно место на все пути (v4.62). */
                Audit::log('access.revoked', ['on' => (int)$user['id'], 'client' => (int)$client['id']]);
                /* Сообщение — по тому, что было (v4.67): «Отклонить» у заявки идёт
                   сюда же, и «учётная запись ушла в архив» про человека, у которого
                   доступа не было и в сервис ничего не уходило, — неправда. */
                Flash::success(match ($was) {
                    'active' => t('Доступ закрыт — в сервисе учётная запись ушла в архив; откроете доступ — вернётся.'),
                    'pending' => t('Заявка отклонена — сюда человек не войдёт; передумаете — «Открыть».'),
                    default => t('Доступ и так был закрыт.'),
                });
                break;
        }
        redirect($back);
    }

    /** Роли: назначить/снять (глобальные — только глобальный админ). */
    public function rolesAction(string $id): never
    {
        $this->requirePerm('users.write');
        $user = User::find((int)$id);
        if (!$user) ErrorPage::render(404);
        $role = Role::find((int)($_POST['role_id'] ?? 0));
        if (!$role) ErrorPage::render(404);
        if ($role['client_id'] === null) {
            $this->requireGlobalAdmin();
            if ($role['slug'] === 'superadmin') $this->requireSuperadmin();
        } else {
            $this->assertClientScope((int)$role['client_id']);
        }
        /* Два имени одного действия: страницы говорят общим протоколом
           (`action=role_grant`), а прежние формы — своим (`do=assign`).
           Принимаем оба: менять контракт работающей точки ради
           единообразия значило бы чинить то, что не сломано, — но и
           держать общий орган на особом протоколе нельзя (v4.50). */
        $act = (string)($_POST['action'] ?? $_POST['do'] ?? '');
        $isGrant = $act === 'assign' || $act === 'role_grant';
        $back = back_to('/admin/users/' . $user['id']);
        /* Список рисуется в разметке, а приходит POST: без этой проверки
           роль чужой области ушла бы в выдачу простой подменой поля. */
        if ($isGrant && !\App\Core\RoleOffer::allows(\App\Core\Scope::system(), (int)$role['id'])) {
            Flash::error(t('Эту роль нельзя выдать на всю систему: её место — в области сервиса или организации.'));
            redirect($back);
        }
        if ($isGrant) {
            Role::assign((int)$user['id'], (int)$role['id'], (int)Auth::id());
            Audit::log('admin.role.assign', ['on' => (int)$user['id'],
                'client' => $role['client_id'] !== null ? (int)$role['client_id'] : null,
                'meta' => ['role' => $role['slug']]]);
            Flash::success('Роль назначена.');
        } else {
            if ($role['slug'] === 'superadmin' && (int)$user['id'] === (int)Auth::id()) {
                Flash::error('Нельзя снять superadmin с самого себя.');
                redirect($back);
            }
            /* Снимаем в той области, в строке которой нажали (v4.92). Раньше
               форма слала одну роль, и «Отозвать» у «Админ портала» для одного
               сервиса снимало её со всех сервисов разом: запрос удалял все
               выдачи этой роли человеку. Без области (старые формы) — как было. */
            $scopeType = (string)($_POST['scope_type'] ?? '');
            $scopeId = (int)($_POST['scope_id'] ?? 0);
            if (in_array($scopeType, [\App\Core\Scope::SYSTEM, \App\Core\Scope::ORG, \App\Core\Scope::CLIENT], true)) {
                if ($scopeType === \App\Core\Scope::CLIENT && $scopeId > 0 && Client::find($scopeId) !== null) {
                    $this->assertClientScope($scopeId);
                }
                $scope = match ($scopeType) {
                    \App\Core\Scope::ORG => \App\Core\Scope::org($scopeId),
                    \App\Core\Scope::CLIENT => \App\Core\Scope::client($scopeId),
                    default => \App\Core\Scope::system(),
                };
                Role::revokeScoped((int)$user['id'], (int)$role['id'], $scope);
            } else {
                Role::revoke((int)$user['id'], (int)$role['id']);
            }
            Audit::log('admin.role.revoke', ['on' => (int)$user['id'],
                'client' => $role['client_id'] !== null ? (int)$role['client_id'] : null,
                'meta' => ['role' => $role['slug']] + ($scopeType !== '' ? ['scope' => $scopeType . ':' . $scopeId] : [])]);
            Flash::success('Роль снята.');
        }
        redirect($back);
    }
}
