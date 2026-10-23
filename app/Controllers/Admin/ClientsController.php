<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\ErrorPage;
use App\Core\Flash;
use App\Core\View;
use App\Models\Access;
use App\Models\Client;
use App\Models\ClientEvent;
use App\Models\OneTimeToken;
use App\Models\Role;
use App\Oidc\EventDispatcher;

/** /admin/clients: карточки порталов + мастер подключения «в два клика» (§6). */
final class ClientsController extends AdminBase
{
    /**
     * Кто вправе подключить сервис.
     *
     * Одно определение на кнопку и на проверку при отправке — иначе они
     * разъезжаются. Разъехались же: кнопку гасило условие «список не сужен»
     * (`$scope === null`), то есть стоило выбрать свою организацию в
     * переключателе — и подключить сервис становилось нечем. Сужение списка не
     * имеет отношения к праву: оно про то, что человек СМОТРИТ, а не что ему
     * можно. Тем более что работа внутри организации — обычный случай, а вовсе
     * не исключение.
     *
     * $orgId — организация, которой сервис достанется. Право спрашивается в её
     * области: владелец организации распоряжается своими сервисами сам, ради
     * этого области и заводились (SCOPES §2). Без организации сервис ничей, и
     * заводить такой вправе только администратор системы.
     */
    /** v5.4: адрес http(s) с хостом — или null. Один на создание и правку. */
    private static function httpUrl(string $url): ?string
    {
        return preg_match('~^https?://[^\s/?#]+~i', $url) === 1 && !preg_match('~\s~', $url) ? $url : null;
    }

    public static function mayCreate(?int $orgId = null): bool
    {
        if (!\App\Core\Auth::check()) return false;
        if ($orgId !== null) {
            return \App\Core\Auth::can('clients.write', \App\Core\Scope::org($orgId));
        }
        return \App\Core\Auth::isAdmin() && \App\Core\Auth::can('clients.write');
    }

    public function index(): never
    {
        $this->requirePerm('clients.read');
        $scope = $this->scopeClientIds();
        $this->rememberListQuery('clients');
        $clients = Client::all();
        if ($scope !== null) {
            $clients = array_values(array_filter($clients, fn($c) => in_array((int)$c['id'], $scope, true)));
        }
        /* Числа — ОДНИМ запросом на весь список, а не двумя на каждую строку.
           Было по два: «сколько людей» и «когда заходили последний раз». На
           десяти порталах это двадцать один запрос вместо трёх, на сотне —
           двести один. Список растёт у всех, и растёт он молча: на трёх
           порталах разницы не видно вовсе. */
        $stats = [];
        foreach (\App\Core\DB::all(
            'SELECT client_id, COUNT(*) AS n, MAX(last_login_at) AS last
               FROM {user_client_access} GROUP BY client_id') as $row) {
            $stats[(int)$row['client_id']] = $row;
        }
        /* v4.90: плитка говорит о портале то, что отличает его от соседей, —
           чей он и не застряли ли у него события. Тоже одним запросом на всё. */
        $stuck = [];
        foreach (\App\Core\DB::all("SELECT client_id, COUNT(*) AS n FROM {client_events} WHERE status <> 'sent' GROUP BY client_id") as $row) {
            $stuck[(int)$row['client_id']] = (int)$row['n'];
        }
        $orgNames = [];
        foreach (\App\Models\Org::all() as $o) $orgNames[(int)$o['id']] = (string)$o['name'];
        foreach ($clients as &$c) {
            $s = $stats[(int)$c['id']] ?? null;
            $c['users_count'] = (int)($s['n'] ?? 0);
            $c['last_activity'] = $s['last'] ?? null;
            $c['events_stuck'] = $stuck[(int)$c['id']] ?? 0;
            $c['org_name'] = $orgNames[(int)($c['org_id'] ?? 0)] ?? null;
        }
        unset($c);
        /* Стоим в организации — сервис заведётся ей: именно это человек и имеет
           в виду, нажимая «подключить», не выходя из своей организации. */
        $ctx = \App\Core\AdminContext::current();
        $ctxOrg = $ctx->is(\App\Core\Scope::ORG) ? $ctx->id : null;
        View::render('admin/clients', [
            'pageTitle' => t('Порталы'),
            'clients' => $clients,
            'wizardOrgId' => $ctxOrg,
            'canCreate' => self::mayCreate($ctxOrg),
        ]);
    }

    /**
     * Название по умолчанию из адреса: «https://shop.site.com» → «Shop».
     * Кириллический домен транслитерируется, а не выбрасывается.
     */
    private static function nameFromUrl(string $url): string
    {
        $host = (string)parse_url($url, PHP_URL_HOST) ?: $url;
        $host = (string)preg_replace('~^www\.~i', '', $host);
        $label = explode('.', $host)[0] ?? $host;

        /* Punycode разворачиваем обратно. Браузер отдаёт кириллический домен
           как «xn--80aadkevhbkvnxnq8km», и без этого шага именно эта строка
           становилась названием портала — да ещё с заглавных букв, отчего
           выглядела совсем странно: «Xn--80Aadkevhbkvnxnq8Km». Слаг такое
           уже умел (Translit::domainSlug), название — нет. */
        if (str_starts_with($label, 'xn--') && function_exists('idn_to_utf8')) {
            $decoded = idn_to_utf8($label, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($decoded) && $decoded !== '') $label = $decoded;
        }

        /* Кириллицу оставляем кириллицей. Это имя для человека, а не слаг:
           «календарьсобытий.рф» → «Календарьсобытий», а не «Kalendarsobytiy».
           Транслитерация нужна машинному имени, и она там и осталась. */
        $clean = (string)preg_replace('~[^\p{L}\p{N} -]+~u', ' ', $label);
        $clean = trim((string)preg_replace('~\s+~u', ' ', $clean));
        return $clean !== '' ? mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8') : $host;
    }

    /** Подключение сервиса (§6.1): адрес → карточка с реквизитами. */
    public function create(): never
    {
        /* Организация назначается здесь же, а не отдельным шагом «а теперь
           присоедините». Раньше сервис рождался ничьим всегда, и человек,
           заводивший его из своей организации, обязан был пойти в её раздел и
           присоединить — при том, что другого намерения у него быть не могло. */
        $orgId = (int)($_POST['org_id'] ?? 0) ?: null;
        if ($orgId !== null && !\App\Models\Org::find($orgId)) $orgId = null;
        if (!self::mayCreate($orgId)) ErrorPage::render(403);

        $baseUrl = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
        /* Схему дописываем сами: человек вводит домен, а не URL. Требовать
           «https://» на первом же поле — верный способ получить ошибку вместо
           подключённого сервиса. */
        if ($baseUrl !== '' && !preg_match('~^https?://~i', $baseUrl)) {
            $baseUrl = 'https://' . $baseUrl;
        }
        if (!preg_match('~^https?://[^\s/]+~', $baseUrl)) {
            Flash::error(t('Нужен адрес сервиса — например, site.com'));
            redirect('/admin/clients');
        }
        /* Название по умолчанию — из домена: «site.com» → «Site». Правится в карточке. */
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') $name = self::nameFromUrl($baseUrl);
        $slug = trim((string)($_POST['slug'] ?? ''));
        if (!preg_match('~^[a-z0-9-]{2,50}$~', $slug)) {
            /* Кириллический домен транслитерируем, а не выбрасываем: раньше
               «календарьсобытий.рф» превращался в «portal», а «новости.example»
               в «example» и сталкивался с соседями по домену. */
            $slug = \App\Core\Translit::domainSlug($baseUrl, \App\Core\Translit::slug($name, 'portal'));
        }
        $base = $slug;
        for ($i = 2; Client::slugTaken($slug); $i++) $slug = $base . '-' . $i;

        $redirects = $this->linesToUris((string)($_POST['redirect_uris'] ?? ''), null);
        $postLogout = $this->linesToUris((string)($_POST['post_logout_uris'] ?? ''), $baseUrl . '/');

        $scopes = ['openid', 'profile', 'email', 'roles'];
        if (!empty($_POST['allow_offline'])) $scopes[] = 'offline_access';

        [$id, $clientId, $secret] = Client::create([
            'org_id' => $orgId,
            'name' => $name,
            'slug' => $slug,
            'description' => trim((string)($_POST['description'] ?? '')) ?: null,
            'base_url' => $baseUrl,
            'redirect_uris' => $redirects,
            'post_logout_uris' => $postLogout,
            'allowed_scopes' => $scopes,
            'backchannel_uri' => self::httpUrl(trim((string)($_POST['backchannel_uri'] ?? ''))),
            /* Быстрое подключение не спрашивает про доверие: свой портал —
               обычный случай, и лишний экран согласия на нём только мешает.
               Снять галочку можно в карточке. */
            'is_trusted' => isset($_POST['is_trusted']) ? (!empty($_POST['is_trusted']) ? 1 : 0) : 1,
            'same_site_family' => !empty($_POST['same_site_family']) ? 1 : 0,
            'access_policy' => in_array($_POST['access_policy'] ?? '', ['open', 'approval', 'invite'], true)
                ? $_POST['access_policy'] : 'open',
            'require_2fa' => !empty($_POST['require_2fa']) ? 1 : 0,
        ]);

        // Секрет и setup-ссылка — показываются один раз (§6.1)
        $setupToken = OneTimeToken::issue(null, 'client_setup', ['client_id' => $id]);
        /* Ссылка установки выдаётся сразу же: в обычном порядке подключения
           администратор скачивает набор и тут же его ставит, и отдельная
           кнопка «а теперь выдайте ссылку» была бы лишним шагом ровно там,
           где мы шаги убираем. Не успел за полчаса — кнопка в карточке. */
        $installUrl = \App\Core\PortalInstall::issue(Client::find($id) ?? [], (int)\App\Core\Auth::id());
        $_SESSION['fresh_client_secret'] = ['id' => $id, 'secret' => $secret,
            'setup' => $setupToken, 'install' => $installUrl];
        Audit::log('admin.client.create', ['client' => $id, 'meta' => ['name' => $name]]);
        \App\Core\WebhookDispatcher::emit('client.created', ['client_id' => $clientId, 'name' => $name, 'url' => $baseUrl]);
        \App\Core\AdminNotify::say('client.created',
            fn() => t('Подключён портал «:name».', ['name' => $name]),
            fn() => [t('Адрес') => $baseUrl, t('Кто') => user_handle(\App\Core\Auth::user() ?? []),
             t('Когда') => fmt_dt(now())]);
        if ($redirects === []) {
            Flash::info(t('Адрес возврата не указан — угадывать его SSO не будет. Поставите наш набор — адрес запишется сам; у сервиса свой модуль — SSO запомнит адрес при первом входе и предложит добавить его в карточке одной кнопкой.'));
        }
        redirect('/admin/clients/' . $id . '/artifacts');
    }

    /**
     * Адреса из поля «по одному на строку».
     *
     * `$default = null` — пустое поле остаётся пустым. Так теперь с адресом
     * возврата: угадывать его нельзя. «…/sso/callback.php» верен для нашего
     * набора и неверен для сервиса со своей маршрутизацией, а узнать, какой
     * адрес сервис шлёт на самом деле, можно только из его первого входа — это
     * делает RedirectMiss.
     */
    private function linesToUris(string $raw, ?string $default): array
    {
        $uris = array_values(array_filter(array_map('trim', preg_split('~\R+~', $raw) ?: []),
            fn($u) => (bool)preg_match('~^https?://~', $u)));
        return $uris !== [] ? $uris : ($default === null ? [] : [$default]);
    }

    /**
     * POST /admin/clients/{id}/redirect-miss — решить судьбу присланного адреса.
     *
     * «Добавить» — адрес уходит в зарегистрированные; «забыть» — убираем из
     * ожидающих. Добавить можно только то, что сервис действительно присылал:
     * произвольный адрес вписывают в поле «Redirect URI», где он виден целиком.
     */
    public function redirectMiss(string $id): never
    {
        $this->requirePerm('clients.write');
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $this->assertClientScope((int)$client['id']);

        $uri = (string)($_POST['uri'] ?? '');
        $kind = ($_POST['kind'] ?? '') === 'logout' ? 'logout' : 'redirect';
        if (($_POST['mode'] ?? '') === 'accept') {
            if (\App\Core\RedirectMiss::accept($client, $uri, $kind)) {
                Flash::success(t('Адрес возврата добавлен: :uri', ['uri' => $uri]));
            } else {
                Flash::error(t('Этот адрес сервис не присылал — добавить его можно только в поле «Redirect URI».'));
            }
        } else {
            \App\Core\RedirectMiss::forget((int)$client['id'], $uri, $kind);
            Flash::info(t('Забыли. Если сервис пришлёт этот адрес снова, он появится здесь опять.'));
        }
        redirect('/admin/clients/' . (int)$client['id'] . '/req');
    }

    /**
     * POST /admin/clients/{id}/manifest — спросить портал о нём самом.
     *
     * Ничего не применяет: роли только запоминаются, карту соответствия
     * администратор правит сам. Карта управляет доступами, и смена манифеста
     * на чужой стороне не должна менять права людям.
     */
    public function fetchManifest(string $id): never
    {
        $this->requirePerm('clients.write');
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $this->assertClientScope((int)$client['id']);

        $r = \App\Core\PortalManifest::fetch($client);
        if (!$r['ok']) {
            /* Отсутствие манифеста — обычное дело: модуль ещё не написан.
               Говорим спокойно, а не как об аварии. */
            Flash::info(t('Портал пока не отвечает манифестом: :why', ['why' => $r['error']]));
        } else {
            Flash::success(t('Портал ответил: ролей — :n, оформление — :b.', [
                'n' => $r['roles'], 'b' => $r['branding'] ? t('принято') : t('не прислано')]));
        }
        /* Кнопка живёт в «Подключении» (v4.68) — туда и возвращаем. */
        redirect('/admin/clients/' . (int)$client['id'] . '/artifacts#portal-link');
    }

    /**
     * POST /admin/clients/{id}/users/key — выдать или отозвать ключ переноса.
     *
     * Показывается один раз и здесь же: у нас он лежит открытым (иначе подпись
     * не посчитать), но администратору его всё равно надо куда-то скопировать,
     * и делать из карточки постоянную витрину секретов незачем.
     */
    public function usersKey(string $id): never
    {
        $this->requirePerm('clients.write');
        $this->requireGlobalAdmin();
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $this->assertClientScope((int)$client['id']);

        if (($_POST['mode'] ?? '') === 'revoke') {
            \App\Core\PortalUsers::revokeSecret($client);
            Flash::info(t('Ключ переноса отозван. Портал больше не отдаст нам своих людей.'));
            redirect('/admin/clients/' . (int)$client['id'] . '/artifacts');
        }
        $secret = \App\Core\PortalUsers::issueSecret($client);
        /* Ключ уходит на экран переноса полем с кнопкой «копировать», а не
           текстом тоста. Тост живёт пять секунд и уезжает — скопировать из
           него сорок восемь знаков не успевает никто, а показать ключ второй
           раз мы не показываем. Один показ — да; исчезающий — нет. */
        $_SESSION['fresh_users_key'] = ['client' => (int)$client['id'], 'key' => $secret];
        redirect('/admin/clients/' . (int)$client['id'] . '/users/preview');
    }

    /**
     * GET /admin/clients/{id}/users/preview — что будет, если нажать.
     *
     * Отдельным экраном, а не сразу переносом: перенос заводит учётные записи
     * в источнике истины, и «нажал и посмотрим» здесь не годится. Считает по
     * первой странице, никого не трогая.
     */
    public function usersPreview(string $id): never
    {
        $this->requirePerm('clients.write');
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $this->assertClientScope((int)$client['id']);

        /* Только что выданный ключ — один показ, здесь же, полем с кнопкой.
           Снимаем его из сессии сразу: перезагрузка страницы ключа уже не
           покажет, как и второй заход. */
        $freshKey = null;
        if ((($_SESSION['fresh_users_key']['client'] ?? null)) === (int)$client['id']) {
            $freshKey = (string)$_SESSION['fresh_users_key']['key'];
            unset($_SESSION['fresh_users_key']);
        }

        View::render('admin/client_users', [
            'pageTitle' => t('Перенос людей'),
            'c' => $client,
            'freshKey' => $freshKey,
            'preview' => \App\Core\PortalUsers::preview($client),
            'linked' => \App\Core\PortalUsers::linkedCount((int)$client['id']),
        ], 'layouts/base');
    }

    /** POST /admin/clients/{id}/users/pull — забрать людей. */
    public function usersPull(string $id): never
    {
        $this->requirePerm('clients.write');
        $this->requireGlobalAdmin();
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $this->assertClientScope((int)$client['id']);

        $r = \App\Core\PortalUsers::pull($client);
        if (!$r['ok']) {
            Flash::error(t('Перенос не пошёл: :why', ['why' => $r['error']]));
            redirect('/admin/clients/' . (int)$client['id'] . '/users/preview');
        }
        $msg = t('Перенос завершён: заведено — :new, дописано — :upd, пропущено — :skip.',
            ['new' => $r['created'], 'upd' => $r['updated'], 'skip' => $r['skipped']])
            . ' ' . t('Ролей в сервисе выдано: :n.', ['n' => $r['roles']]);
        /* Роли без пары называем по имени и первыми: иначе видно «перенос
           завершён» и людей без ролей — ровно то, с чего начался живой случай
           12.09 (v4.66). */
        $notes = [];
        if ($r['roles_unmapped'] !== []) {
            $notes[] = t('роли портала без пары в SSO: :list — заведите их в «Ролях» портала или сопоставьте в карте, затем заберите людей ещё раз',
                ['list' => implode(', ', array_keys($r['roles_unmapped']))]);
        }
        if ($r['roles_refused'] !== []) {
            $notes[] = t('общие роли SSO перенос не выдаёт: :list', ['list' => implode(', ', array_keys($r['roles_refused']))]);
        }
        $notes = array_merge($notes, $r['errors']);
        if ($notes !== []) {
            Flash::info($msg . ' ' . t('С оговорками: :why', ['why' => implode('; ', array_slice($notes, 0, 3))]));
        } else {
            Flash::success($msg);
        }
        redirect('/admin/clients/' . (int)$client['id'] . '/users/preview');
    }

    /** POST /admin/clients/{id}/rolemap — переписать карту ролей целиком. */
    public function saveRoleMap(string $id): never
    {
        $this->requirePerm('clients.write');
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $this->assertClientScope((int)$client['id']);

        $result = \App\Models\ClientRoleMap::replace((int)$client['id'], (array)($_POST['map'] ?? []));
        Flash::success(t('Карта сохранена: сопоставлено ролей — :n.', ['n' => $result['saved']]));
        redirect('/admin/clients/' . (int)$client['id'] . '/roles');
    }

    /** GET /admin/clients/{id}/rolemap.json — выгрузка для переноса. */
    public function exportRoleMap(string $id): never
    {
        $this->requirePerm('clients.read');
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $this->assertClientScope((int)$client['id']);

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="rolemap-'
            . \App\Core\Translit::slug((string)$client['name'], 'client') . '.json"');
        echo \App\Models\ClientRoleMap::toJson($client);
        exit;
    }

    /** POST /admin/clients/{id}/rolemap/import — загрузка карты из файла. */
    public function importRoleMap(string $id): never
    {
        $this->requirePerm('clients.write');
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $this->assertClientScope((int)$client['id']);

        $f = $_FILES['map'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 256 * 1024) {
            Flash::error(t('Файл не загрузился.'));
            redirect('/admin/clients/' . (int)$client['id'] . '/roles');
        }
        $map = \App\Models\ClientRoleMap::fromJson((string)file_get_contents($f['tmp_name']));
        if ($map === null) {
            /* Не тот файл — обычное дело. Говорим об этом прямо, а не падаем
               посреди импорта с ошибкой разбора. */
            Flash::error(t('Это не карта ролей: в файле нет объекта roles.'));
            redirect('/admin/clients/' . (int)$client['id'] . '/roles');
        }
        $result = \App\Models\ClientRoleMap::replace((int)$client['id'], $map);
        if ($result['unknown'] !== []) {
            Flash::info(t('Пропущены неизвестные роли: :list', [
                'list' => implode(', ', array_slice($result['unknown'], 0, 10)),
            ]));
        }
        Flash::success(t('Карта загружена: сопоставлено ролей — :n.', ['n' => $result['saved']]));
        redirect('/admin/clients/' . (int)$client['id'] . '/roles');
    }

    /**
     * Портал раздела. Все разделы оболочки начинаются одинаково, и повторять
     * четыре строки восемь раз значит однажды забыть одну из них.
     */
    private function client(string $id, string $perm = 'clients.read'): array
    {
        $this->requirePerm($perm);
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $this->assertClientScope((int)$client['id']);
        return $client;
    }

    /** Числа у разделов в колонке: видно, где пусто, не заходя туда. */
    private function clientCounts(array $client): array
    {
        $id = (int)$client['id'];
        return [
            'people' => Client::usersCount($id),
            'roles' => count(Role::forClient($id)),
            'invites' => count(\App\Models\Invite::forClient($id)),
            /* Только недоставленные (v4.67). Считали двадцать последних событий
               вместе с доставленными, и у каждого живого портала в колонке
               висело «Доставка 5» — как сигнал, что там что-то не так. Число у
               раздела должно звать внутрь, когда есть зачем; ноль не рисуется. */
            'events' => (int)\App\Core\DB::val("SELECT COUNT(*) FROM {client_events} WHERE client_id = ? AND status <> 'sent'", [$id]),
        ];
    }

    /** Общие данные разделов: их спрашивают почти все шаблоны. */
    private function clientCommon(array $client): array
    {
        return [
            'pageTitle' => $client['name'],
            'c' => $client,
            'isGlobalAdmin' => $this->permittedClientIds() === null,
        ];
    }

    private function clientPage(array $client, string $section, array $data,
                               string $headActions = ''): never
    {
        object_page(\App\Core\ClientMap::class, $client, $section,
            'admin/client/' . $section, $this->clientCommon($client) + $data,
            $headActions, $this->clientCounts($client),
            $this->listBackUrl('clients', '/admin/clients'));
    }

    /**
     * Обзор сервиса (v4.91): сколько людей и когда входили, как открывается
     * вход и откуда оформление, и что требует внимания — связь с порталом,
     * адреса возврата, недоставленные события, заявки. Каждый пункт ведёт в
     * раздел, где это чинится.
     */
    public function card(string $id): never
    {
        $client = $this->client($id);
        $cid = (int)$client['id'];
        $misses = count(\App\Core\RedirectMiss::pending($client, 'redirect')) + count(\App\Core\RedirectMiss::pending($client, 'logout'));
        $this->clientPage($client, 'overview', [
            'stats' => [
                'people' => Client::usersCount($cid),
                'week' => (int)\App\Core\DB::val('SELECT COUNT(*) FROM {user_client_access} WHERE client_id = ? AND last_login_at >= ?',
                    [$cid, now_plus(-7 * 86400)]),
                'last' => \App\Core\DB::val('SELECT MAX(last_login_at) FROM {user_client_access} WHERE client_id = ?', [$cid]),
                'pending' => count(Access::pendingForClient($cid)),
                'stuck' => (int)\App\Core\DB::val("SELECT COUNT(*) FROM {client_events} WHERE client_id = ? AND status <> 'sent'", [$cid]),
                'roles' => count(Role::forClient($cid)),
                'misses' => $misses,
            ],
            'manifestError' => \App\Core\PortalManifest::lastError($cid),
            'owner' => !empty($client['org_id']) ? \App\Models\Org::find((int)$client['org_id']) : null,
            'grantedOrgs' => \App\Models\Org::forClient($cid),
        ]);
    }

    /** Реквизиты (с 4.91 — /req; до этого открывались первыми). */
    public function req(string $id): never
    {
        $client = $this->client($id);
        $this->clientPage($client, 'req', [
            'orgs' => \App\Models\Org::forClient((int)$client['id']),
            /* Список для выбора владельца — отдельно от списка тех, кому
               роздан доступ: это разные отношения (SCOPES §1.4). */
            'allOrgs' => \App\Models\Org::all(),
        ]);
    }

    public function artifacts(string $id): never
    {
        $client = $this->client($id);
        /* Реквизиты показываются один раз — и именно здесь, а не в шапке
           карточки: раздел «Подключение» и есть то место, куда человек идёт
           за ними. */
        $fresh = null;
        if (($_SESSION['fresh_client_secret']['id'] ?? null) === (int)$client['id']) {
            $fresh = $_SESSION['fresh_client_secret'];
            unset($_SESSION['fresh_client_secret']);
        }
        $this->clientPage($client, 'artifacts', ['fresh' => $fresh]);
    }

    public function people(string $id): never
    {
        $client = $this->client($id, 'users.read');
        $cid = (int)$client['id'];
        $this->clientPage($client, 'people', [
            'pending' => Access::pendingForClient($cid),
            'orgs' => \App\Models\Org::forClient($cid),
            /* Управление доступом живёт здесь же, а не только в карточке
               человека. Раздел назывался «Люди» и был при этом списком для
               чтения: посмотреть, кто ходит, можно, а пустить или закрыть —
               нет. Чтобы дать доступ, приходилось идти в карточку человека,
               то есть заходить со стороны, противоположной вопросу. */
            'mayManage' => \App\Core\Auth::can('users.write'),
            'users' => \App\Core\DB::all(
                'SELECT u.*, a.status AS access_status, a.last_login_at
                   FROM {users} u JOIN {user_client_access} a ON a.user_id = u.id
                  WHERE a.client_id = ? AND u.status <> ?
                  ORDER BY a.last_login_at IS NULL, a.last_login_at DESC LIMIT 200',
                [$cid, 'deleted']),

            /* Роль выдаётся здесь же, в строке человека.
               Раньше за этим шли в раздел «Роли», где кого выбирают из
               выпадающего списка на пятьсот имён: вопрос «выдать роль вот
               этому» решался поиском того же человека во втором списке. А он
               уже перед глазами — в этой самой таблице. */
            'mayRoles' => \App\Core\Auth::can('roles.write'),
            'scope' => $scope = \App\Core\Scope::client($cid),
            /* Что осмысленно выдать здесь, решает RoleOffer, а не запрос
               «свои плюс системные»: последний вырастал до двенадцати пунктов
               с дублями `admin` и `user` и ролями чужих областей (v4.50). */
            'grantable' => \App\Core\RoleOffer::inScope($scope),
            'offerWhy' => \App\Core\RoleOffer::why($scope),
            /* Кому что выдано — одним запросом на страницу, а не по запросу на
               строку: людей тут до двухсот (§8, правило про запрос в переборе). */
            'rolesByUser' => \App\Core\RoleOffer::grantsByUser($scope),
        ]);
    }

    /**
     * Дать доступ человеку прямо из карточки сервиса.
     *
     * Обратная сторона того же отношения уже была: в карточке ЧЕЛОВЕКА можно
     * выбрать сервис. Отсюда — нельзя, и чтобы пустить пятерых в один сервис,
     * приходилось открывать пять карточек людей. Вопрос при этом стоит про
     * сервис, а не про человека, и отвечать на него надо там, где он задан.
     */
    public function grantAccess(string $id): never
    {
        $client = $this->client($id, 'users.write');
        $identity = trim((string)($_POST['identity'] ?? ''));
        $back = '/admin/clients/' . (int)$client['id'] . '/people';
        if ($identity === '') {
            Flash::error(t('Укажите почту или логин.'));
            redirect($back);
        }
        $user = \App\Models\User::findByLoginOrEmail($identity);
        if (!$user) {
            /* Не заводим аккаунт молча: «дать доступ» и «создать человека» —
               разные намерения, и второе не должно случаться по опечатке. */
            Flash::error(t('Человек с такой почтой или логином не найден. Заведите аккаунт в разделе «Пользователи».'));
            redirect($back);
        }
        Access::grant((int)$user['id'], (int)$client['id'], (int)\App\Core\Auth::id(), 'active');
        Audit::log('access.granted', ['on' => (int)$user['id'], 'client' => (int)$client['id'],
            'meta' => ['from' => 'client_card']]);
        Flash::success(t('Доступ выдан: :who', ['who' => user_title($user)]));
        redirect($back);
    }

    public function rolesSection(string $id): never
    {
        $client = $this->client($id);
        $cid = (int)$client['id'];
        $this->clientPage($client, 'roles', [
            'roles' => Role::forClient($cid),
            'seen' => \App\Core\PortalManifest::rolesSeen($cid),
            /* Выдачи людям здесь больше нет (v4.68): она живёт в строке
               человека в «Пользователях», и вторая копия на этой странице
               давала два места для одного действия. */
            /* Сколько человек с каждой ролью — одним запросом на страницу, а не
               по запросу на строку: строк бывает три, а бывает тридцать. */
            'roleGrantCount' => array_column(\App\Core\DB::all(
                'SELECT ur.role_id, COUNT(*) AS n FROM {user_roles} ur
                   JOIN {roles} r ON r.id = ur.role_id
                  WHERE r.client_id = ? GROUP BY ur.role_id', [$cid]), 'n', 'role_id'),
            /* Глобальные роли плюс роли самого портала: чужие клиентские
               в карту попадать не должны. */
            /* Только общие роли SSO (v4.69): свои роли портал получает как
               есть, мимо карты. С ними в форме стояли два поля map[admin] и два
               map[user], и второе при сохранении затирало первое. */
            'mappableRoles' => \App\Core\DB::all('SELECT id, slug, name FROM {roles}
                WHERE client_id IS NULL ORDER BY slug'),
        ]);
    }

    public function invites(string $id): never
    {
        $client = $this->client($id, 'clients.write');
        $this->clientPage($client, 'invites', [
            'invites' => \App\Models\Invite::forClient((int)$client['id']),
            'roles' => \App\Core\RoleOffer::inScope(\App\Core\Scope::client((int)$client['id'])),
            'freshInvite' => (($_SESSION['fresh_invite']['client'] ?? null) === (int)$client['id'])
                ? (function () { $u = $_SESSION['fresh_invite']['url']; unset($_SESSION['fresh_invite']); return $u; })()
                : null,
        ]);
    }

    /**
     * «Экран входа» портала (v4.71): как выглядит вход, когда человек пришёл
     * отсюда. До этого оформление задавалось только манифестом портала, и
     * поменять его можно было, лишь поправив чужой сайт.
     */
    public function look(string $id): never
    {
        $client = $this->client($id);
        /* Предпросмотр — во фрейме со своего адреса; общая политика фреймы
           запрещает, и открываем ровно 'self' и ровно на этой странице. */
        \App\Core\Csp::allow('frame-src', "'self'");
        $decode = static fn(?string $json): mixed => json_decode((string)$json, true);
        $this->clientPage($client, 'look', [
            'mode' => \App\Core\Bridge::mode($client),
            'portalTheme' => \App\Core\Bridge::portalTheme($client),
            'customTheme' => \App\Core\Bridge::sanitize($decode($client['look_theme'] ?? null), 'portal'),
            'siteTheme' => \App\Core\Bridge::sanitize($decode((string)\App\Core\Setting::get('look.theme', '')), 'site'),
            'methods' => \App\Core\Bridge::methodsOf($client),
            'canWrite' => \App\Core\Auth::can('clients.write'),
        ]);
    }

    public function lookSave(string $id): never
    {
        $client = $this->client($id, 'clients.write');
        $res = \App\Core\LoginLook::saveForClient($client, $_POST, $_FILES);
        if (!$res['ok']) {
            Flash::error((string)$res['error']);
        } else {
            Flash::success(t('Экран входа сохранён.'));
            foreach ($res['warnings'] as $w) Flash::info($w);
        }
        redirect('/admin/clients/' . (int)$client['id'] . '/look');
    }

    /** Страница для фрейма предпросмотра: настоящий экран входа с черновиком. */
    public function lookPreview(string $id): never
    {
        $client = $this->client($id);
        \App\Core\LoginLook::renderPreview($client, $_GET);
    }

    /** Аналитика сервиса (v5.2): тот же период, что на пульте, — по одному порталу. */
    public function analytics(string $id): never
    {
        $client = $this->client($id);
        $days = \App\Core\Stats::period($_GET['p'] ?? null);
        $this->clientPage($client, 'analytics', [
            'days' => $days,
            'a' => \App\Core\Stats::portal((int)$client['id'], $days),
        ]);
    }

    public function events(string $id): never
    {
        $client = $this->client($id);
        $this->clientPage($client, 'events', [
            'events' => ClientEvent::recentForClient((int)$client['id']),
        ]);
    }

    public function history(string $id): never
    {
        $client = $this->client($id, 'logs.read');
        $this->clientPage($client, 'history', [
            'history' => \App\Core\ObjectHistory::of('client', (int)$client['id']),
            /* Связи рядом с историей: из раздела видно, что сломается при
               удалении, без хождения по остальным. */
            'orgs' => \App\Models\Org::forClient((int)$client['id']),
            'roles' => Role::forClient((int)$client['id']),
        ]);
    }

    /** POST /admin/clients/{id}/cover-fetch — взять картинку с сайта портала сейчас, не дожидаясь суток. */
    public function coverFetch(string $id): never
    {
        $this->requirePerm('clients.write');
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $this->assertClientScope((int)$client['id']);
        $res = \App\Core\PortalCover::fetchFromSite($client);
        $res['ok'] ? Flash::success($res['detail']) : Flash::error($res['detail']);
        redirect('/admin/clients/' . (int)$client['id'] . '/req');
    }

    public function update(string $id): never
    {
        $this->requirePerm('clients.write');
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $this->assertClientScope((int)$client['id']);
        $iconPath = $this->handleIconUpload($client);
        /* Обложка на витрине (v4.76). Своя картинка главнее картинки с сайта. */
        if (!empty($_POST['card_cover_delete'])) {
            \App\Core\PortalCover::remove((int)$client['id']);
        } elseif ((($_FILES['card_cover']['error'] ?? UPLOAD_ERR_NO_FILE)) === UPLOAD_ERR_OK
            && !\App\Core\PortalCover::upload((int)$client['id'], (string)$_FILES['card_cover']['tmp_name'])) {
            Flash::error(t('Обложка: нужна картинка JPEG, PNG, GIF или WebP до 5 МБ.'));
        }
        $baseUrl = rtrim(trim((string)($_POST['base_url'] ?? $client['base_url'])), '/');
        /* v5.4: та же проверка, что при создании. Без неё в «Реквизитах»
           сохранялся javascript:… или пустая строка — и уходил в href
           кабинета, письма и редирект приглашения. */
        $urlError = null;
        if (self::httpUrl($baseUrl) === null) {
            $urlError = t('Адрес сервиса не изменён: нужен адрес http(s)://.');
            $baseUrl = (string)$client['base_url'];
        }
        $backchannel = trim((string)($_POST['backchannel_uri'] ?? ''));
        if ($backchannel !== '' && self::httpUrl($backchannel) === null) {
            $urlError = t('Адрес приёмника событий не изменён: нужен адрес http(s)://.');
            $backchannel = (string)($client['backchannel_uri'] ?? '');
        }
        $scopes = array_values(array_diff($client['allowed_scopes'], ['offline_access']));
        if (!empty($_POST['allow_offline'])) $scopes[] = 'offline_access';

        /* Машинное имя переименовывается. Раньше оно назначалось при создании
           навсегда, и портал, которому оно досталось кривым, оставался с ним
           жить — а видно его и в claim `roles`, и в имени файлов набора.
           Неверный слаг не отменяет остальную правку формы: сохраняем всё
           прочее и говорим, что именно не приняли. */
        $slug = mb_strtolower(trim((string)($_POST['slug'] ?? '')));
        $slugError = null;
        if ($slug !== '' && $slug !== (string)$client['slug']) {
            if (!preg_match('~^[a-z0-9-]{2,50}$~', $slug)) {
                $slugError = t('Машинное имя не изменено: только латиница, цифры и дефис, от 2 до 50 знаков.');
            } elseif (Client::slugTaken($slug, (int)$client['id'])) {
                $slugError = t('Машинное имя «:slug» уже занято другим порталом.', ['slug' => $slug]);
            }
        }
        $slugNew = ($slug !== '' && $slugError === null) ? $slug : (string)$client['slug'];

        /* Организация-владелец. Пустое значение — «ничей проект», это законное
           состояние: портал уровня системы никакой организации не принадлежит.
           Несуществующий id молча не принимаем — иначе проект уедет в пустоту,
           и роли организации перестанут действовать без единого сообщения. */
        $orgId = trim((string)($_POST['org_id'] ?? ''));
        $orgNew = $orgId === '' ? null : (int)$orgId;
        if ($orgNew !== null && \App\Models\Org::find($orgNew) === null) {
            $orgNew = $client['org_id'] === null ? null : (int)$client['org_id'];
        }
        /* v5.4: сменить владельца — то же право, что завести портал в нём
           (mayCreate). Админ портала уводил его в любую организацию или
           делал ничьим — мимо владельца-организации. */
        $orgWas = $client['org_id'] === null ? null : (int)$client['org_id'];
        if ($orgNew !== $orgWas && !self::mayCreate($orgNew)) $orgNew = $orgWas;

        Client::update((int)$client['id'], [
            'allowed_scopes' => $scopes,
            'name' => trim((string)($_POST['name'] ?? $client['name'])) ?: $client['name'],
            'slug' => $slugNew,
            'org_id' => $orgNew,
            'description' => trim((string)($_POST['description'] ?? '')) ?: null,
            'base_url' => $baseUrl,
            'redirect_uris' => $this->linesToUris((string)($_POST['redirect_uris'] ?? ''), null),
            'post_logout_uris' => $this->linesToUris((string)($_POST['post_logout_uris'] ?? ''), $baseUrl . '/'),
            'backchannel_uri' => $backchannel ?: null,
            'is_trusted' => !empty($_POST['is_trusted']) ? 1 : 0,
            'same_site_family' => !empty($_POST['same_site_family']) ? 1 : 0,
            'access_policy' => in_array($_POST['access_policy'] ?? '', ['open', 'approval', 'invite'], true)
                ? $_POST['access_policy'] : $client['access_policy'],
            'require_2fa' => !empty($_POST['require_2fa']) ? 1 : 0,
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'show_on_home' => !empty($_POST['show_on_home']) ? 1 : 0,
            'icon_path' => $iconPath,
        ]);
        /* Манифест перечитываем сразу, если есть что проверять: сменился адрес
           портала или прошлая попытка кончилась неудачей. Раньше ждали сутки,
           и надпись «не отвечает манифестом» висела до завтра после того, как
           сервис уже починил путь. Отложенно — чтобы сохранение не ждало чужой
           сервер до восьми секунд. */
        if ($baseUrl !== (string)$client['base_url']
            || \App\Core\PortalManifest::lastError((int)$client['id']) !== null) {
            $fresh = Client::find((int)$client['id']);
            if ($fresh) \App\Core\Defer::after(static fn() => \App\Core\PortalManifest::fetch($fresh));
        }
        Audit::log('admin.client.update', ['client' => (int)$client['id']]);
        if ($slugError !== null || $urlError !== null) {
            Flash::error((string)($slugError ?? $urlError));
        } elseif ($slugNew !== (string)$client['slug']) {
            /* Переименование машинного имени видно снаружи: пока карта ролей
               пуста, портал получает роли префиксом «слаг:роль». Молчать об
               этом нельзя — на той стороне разбор сломается тихо. */
            Audit::log('admin.client.slug', ['client' => (int)$client['id'],
                'meta' => ['from' => (string)$client['slug'], 'to' => $slugNew]]);
            Flash::success(t('Портал обновлён. Машинное имя теперь «:slug» — если карта ролей пуста, портал начнёт получать роли с новым префиксом.',
                ['slug' => $slugNew]));
        } else {
            Flash::success('Портал обновлён.');
        }
        redirect('/admin/clients/' . $client['id'] . '/req');
    }

    /** Иконка портала: перекодируем в webp 64×64 (как аватары, §8.2). */
    private function handleIconUpload(array $client): ?string
    {
        $dir = SSO_ROOT . '/storage/avatars/clients';
        $file = $dir . '/' . (int)$client['id'] . '.webp';
        if (!empty($_POST['icon_delete'])) {
            @unlink($file);
            return null;
        }
        $f = $_FILES['icon'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            return $client['icon_path']; // ничего не меняли
        }
        if ($f['size'] > 2 * 1024 * 1024 || !function_exists('imagewebp')) {
            Flash::error('Иконка: до 2 МБ, и серверному GD нужна поддержка webp.');
            return $client['icon_path'];
        }
        $img = \App\Core\Image::fromBytes((string)file_get_contents($f['tmp_name']));
        if ($img === false) {
            Flash::error('Иконка не похожа на изображение.');
            return $client['icon_path'];
        }
        $w = imagesx($img); $h = imagesy($img);
        $side = min($w, $h);
        $dst = imagecreatetruecolor(64, 64);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), 64, 64, $side, $side);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        imagewebp($dst, $file, 90);
        imagedestroy($dst);
        imagedestroy($img);
        return 'clients/' . (int)$client['id'] . '.webp';
    }

    /** Действия карточки: rotate_secret / setup_link / retry_event. */
    /**
     * Опасная зона портала: отдельная страница за паролем.
     *
     * Раньше удаление стояло блоком в карточке — то есть попадалось на глаза
     * тому, кто зашёл поправить redirect_uri. Кнопке, которая уносит client_id,
     * секрет и все доступы, там не место. Сам механизм зоны общий
     * (App\Core\DangerZone): организации пользуются тем же.
     */
    public function danger(string $id): never
    {
        $this->requirePerm('clients.delete');
        $this->requireSuperadmin();
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $this->clientPage($client, 'danger', [
            'unlocked' => \App\Core\DangerZone::unlocked($this->dangerScope((int)$client['id'])),
            'usersOfClient' => Client::usersCount((int)$client['id']),
        ]);
    }

    public function dangerUnlock(string $id): never
    {
        $this->requirePerm('clients.delete');
        $this->requireSuperadmin();
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $res = \App\Core\DangerZone::unlock($this->dangerScope((int)$client['id']),
            (string)($_POST['password'] ?? ''));
        if (!$res['ok']) Flash::error($res['error']);
        redirect('/admin/clients/' . $client['id'] . '/danger');
    }

    private function dangerScope(int $clientDbId): string
    {
        return 'client:' . $clientDbId;
    }

    public function action(string $id): never
    {
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $this->assertClientScope((int)$client['id']);

        /* Право под каждое действие — таблицей до switch, чтобы новый случай
           нельзя было добавить, забыв про проверку. */
        $act = (string)($_POST['action'] ?? '');
        $needs = [
            'rotate_secret' => 'clients.write', 'setup_link' => 'clients.write',
            'retry_event' => 'clients.write',
            'create_invite' => 'clients.write', 'revoke_invite' => 'clients.write',
            'role_grant' => 'roles.write', 'role_revoke' => 'roles.write',
            'role_adopt' => 'roles.write', 'role_relevel' => 'roles.write',
            'delete' => 'clients.delete',
        ];
        if (isset($needs[$act])) $this->requirePerm($needs[$act]);

        switch ($act) {
            /* Завести у себя роль, о которой сообщил портал.
               Уровень — из справочника по имени: «blog_editor» опознаётся как
               редактор и получает 60, «admin» — администратор сервиса, 70,
               незнакомое имя — 20. Прав SSO такая роль не получает никогда, как
               бы она ни называлась (ROLES.md §4). */
            case 'role_adopt': {
                $slug = mb_strtolower(trim((string)($_POST['slug'] ?? '')));
                if (!preg_match('~^[a-z0-9._-]{2,64}$~', $slug)) {
                    Flash::error(t('Непонятное имя роли.'));
                    break;
                }
                if (Role::findBySlug($slug, (int)$client['id'])) {
                    Flash::error(t('Такая роль в этом сервисе уже есть.'));
                    break;
                }
                $seen = \App\Core\DB::row(
                    'SELECT title FROM {client_roles_seen} WHERE client_id = ? AND slug = ?',
                    [(int)$client['id'], $slug]);
                $level = \App\Core\RoleCatalog::levelForIncoming($slug);
                $rid = Role::create((int)$client['id'], $slug,
                    (string)($seen['title'] ?? '') ?: $slug,
                    t('Заведена по сообщению портала.'));
                Role::setLevel($rid, $level);
                Role::setPermissions($rid, []);   // роль портала прав SSO не несёт
                Audit::log('admin.client.role_adopt', ['client' => (int)$client['id'],
                    'meta' => ['role' => $slug, 'level' => $level]]);
                Flash::success(t('Роль «:slug» заведена с уровнем :n.',
                    ['slug' => $slug, 'n' => $level]));
                redirect('/admin/clients/' . (int)$client['id'] . '/roles');
            }

            /* Выставить заведённой роли уровень по имени. До v4.61 «admin»
               портала заводился с двадцаткой — у Архива это читалось как
               «администратор … 20 · Пользователь». Молча переписывать нельзя:
               уровень меняет то, что портал видит о людях, — поэтому кнопка, и
               только вверх, до того, что значит имя. */
            case 'role_relevel': {
                $slug = mb_strtolower(trim((string)($_POST['slug'] ?? '')));
                $role = Role::findBySlug($slug, (int)$client['id']);
                if (!$role) {
                    Flash::error(t('Такой роли у этого сервиса нет.'));
                    break;
                }
                $from = (int)($role['access_level'] ?? 0);
                $to = max($from, \App\Core\RoleCatalog::levelForIncoming($slug));
                Role::setLevel((int)$role['id'], $to);
                Audit::log('admin.client.role_relevel', ['client' => (int)$client['id'],
                    'meta' => ['role' => $slug, 'from' => $from, 'to' => $to]]);
                Flash::success(t('Роль «:slug»: уровень :from → :to.',
                    ['slug' => $slug, 'from' => $from, 'to' => $to]));
                redirect('/admin/clients/' . (int)$client['id'] . '/roles');
            }

            /* ── Роли в области сервиса ─────────────────────────────────── */
            case 'role_grant':
            case 'role_revoke': {
                $uid = (int)($_POST['user_id'] ?? 0);
                $rid = (int)($_POST['role_id'] ?? 0);
                $role = Role::find($rid);
                if ($uid <= 0 || !$role) { Flash::error(t('Выберите человека и роль.')); break; }
                /* Чужая портальная роль в этой области бессмысленна: её дом —
                   другой сервис, и claim она получит там, а не здесь. */
                if ($role['client_id'] !== null && (int)$role['client_id'] !== (int)$client['id']) {
                    Flash::error(t('Эта роль принадлежит другому сервису.'));
                    break;
                }
                $scope = \App\Core\Scope::client((int)$client['id']);
                /* Список рисуется в разметке, а приходит POST. Спрятать пункт и
                   считать вопрос закрытым — значит охранять дверь занавеской:
                   роль чужой области или та, о которой портал не слышал, ушла
                   бы в выдачу простой подменой поля (v4.50). Отзыв не
                   проверяем: снять уже выданное можно всегда, иначе роль,
                   выданную до сужения списка, стало бы нечем убрать. */
                if ($act === 'role_grant' && !\App\Core\RoleOffer::allows($scope, $rid)) {
                    Flash::error(t('Эту роль в этом сервисе выдать нельзя: он её не знает.'));
                    break;
                }
                if ($act === 'role_grant') {
                    Role::assignScoped($uid, $rid, $scope, (int)\App\Core\Auth::id());
                    Audit::log('admin.client.role_grant', ['client' => (int)$client['id'], 'on' => $uid,
                        'meta' => ['role' => $role['slug']]]);
                    Flash::success(t('Роль «:role» выдана в этом сервисе.', ['role' => $role['name']]));
                } else {
                    Role::revokeScoped($uid, $rid, $scope);
                    Audit::log('admin.client.role_revoke', ['client' => (int)$client['id'], 'on' => $uid,
                        'meta' => ['role' => $role['slug']]]);
                    Flash::success(t('Роль отозвана.'));
                }
                \App\Core\AccessResolver::forget();
                /* Возврат туда, откуда выдали. Роль теперь выдаётся из двух
                   мест — из раздела ролей и из строки человека в разделе
                   «Пользователи», — и жёсткий адрес уносил бы со второго на
                   первый: выдал роль одному, а тебя увело в другой раздел
                   искать следующего. */
                /* Выдача живёт в «Пользователях» (v4.68) — туда и по умолчанию. */
                redirect(back_to('/admin/clients/' . (int)$client['id'] . '/people'));
            }
            case 'rotate_secret':
                $this->requireGlobalAdmin();
                $secret = Client::rotateSecret((int)$client['id']);
                $_SESSION['fresh_client_secret'] = ['id' => (int)$client['id'], 'secret' => $secret, 'setup' => null];
                Audit::log('admin.client.secret_rotate', ['client' => (int)$client['id']]);
                Flash::success('Секрет перевыпущен. Старый работает ещё 24 часа.');
                break;
            case 'setup_link':
                $this->requireGlobalAdmin();
                $token = OneTimeToken::issue(null, 'client_setup', ['client_id' => (int)$client['id']]);
                $_SESSION['fresh_client_secret'] = ['id' => (int)$client['id'], 'secret' => null, 'setup' => $token];
                Flash::success('Новая setup-ссылка сгенерирована (одноразовая, 7 дней).');
                break;
            case 'install_link':
                $this->requireGlobalAdmin();
                /* Ссылка ведёт на сам портал: там её примет sso/install.php,
                   сходит сюда с билетом и запишет реквизиты у себя. Руками
                   после этого вписывать нечего — в этом вся затея. */
                $_SESSION['fresh_client_secret'] = ['id' => (int)$client['id'], 'secret' => null,
                    'setup' => null, 'install' => \App\Core\PortalInstall::issue($client, (int)\App\Core\Auth::id())];
                Flash::success(t('Ссылка установки готова. Откройте её на портале — реквизиты он заберёт сам. Действует :n минут и один раз.',
                    ['n' => (int)(\App\Core\PortalInstall::TTL / 60)]));
                break;
            case 'test_event':
                $this->requireGlobalAdmin();
                /* Чеклист приёмки просит портал проверить, что «выйти везде»
                   гасит его сессию и что блокировка выкидывает человека. А
                   проверить это было нечем: событие приходит от нас, подделать
                   его нельзя — подпись. Отсюда кнопка: настоящее подписанное
                   событие, по которому приёмник виден целиком.

                   Тип отдельный (`test`), а не «понарошку logout»: приёмник не
                   должен гасить чьи-то живые сессии от нажатия кнопки в чужой
                   админке. Портал на неизвестный тип обязан ответить 200 и
                   ничего не делать — это и проверяется. */
                if (empty($client['backchannel_uri'])) {
                    Flash::error(t('У портала не указан адрес приёмника событий — слать некуда.'));
                    break;
                }
                EventDispatcher::queue($client, 'test', [
                    'note' => 'Проверочное событие из админки. Ответьте 200 и ничего не делайте.',
                    'sent_at' => now(),
                ]);
                $last = ClientEvent::recentForClient((int)$client['id'])[0] ?? null;
                Audit::log('admin.client.test_event', ['client' => (int)$client['id']]);
                Flash::success(($last['status'] ?? '') === 'sent'
                    ? t('Событие доставлено: приёмник ответил 200.')
                    : t('Событие поставлено в очередь — результат виден во вкладке «Доставка».'));
                break;
            case 'retry_event':
                /* v5.4: событие — только этого портала: номер приходит из
                   формы, и админ портала A перезапускал доставки портала B. */
                $eventId = (int)($_POST['event_id'] ?? 0);
                if (!\App\Core\DB::val('SELECT 1 FROM {client_events} WHERE id = ? AND client_id = ?',
                        [$eventId, (int)$client['id']])) ErrorPage::render(404);
                ClientEvent::retryNow($eventId);
                EventDispatcher::attempt($eventId);
                Flash::success('Попытка доставки выполнена.');
                break;

            case 'create_invite':
                $email = mb_strtolower(trim((string)($_POST['invite_email'] ?? '')));
                /* v5.4: роль из POST сверяется с тем, что в этом портале можно
                   выдать, — список в форме лишь занавеска (см. RoleOffer). */
                $inviteRole = (int)($_POST['invite_role'] ?? 0) ?: null;
                if ($inviteRole !== null
                    && !\App\Core\RoleOffer::allows(\App\Core\Scope::client((int)$client['id']), $inviteRole)) {
                    Flash::error(t('Эту роль в этом сервисе выдать нельзя: он её не знает.'));
                    redirect('/admin/clients/' . $client['id'] . '/invites');
                }
                $token = \App\Models\Invite::create((int)$client['id'], [
                    'role_id' => $inviteRole,
                    'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                    'max_uses' => (int)($_POST['invite_uses'] ?? 1),
                    'days' => (int)($_POST['invite_days'] ?? 7),
                    'note' => mb_substr(trim((string)($_POST['invite_note'] ?? '')), 0, 100) ?: null,
                ], (int)\App\Core\Auth::id());
                $_SESSION['fresh_invite'] = ['client' => (int)$client['id'], 'url' => base_url('/invite/' . $token)];
                Audit::log('admin.invite.create', ['client' => (int)$client['id']]);
                Flash::success('Ссылка-приглашение создана.');
                redirect('/admin/clients/' . $client['id'] . '/invites');

            /* Удаление портала. Только глобальный админ: client_admin своего
               портала — самый заинтересованный и самый неподходящий для этого
               человек. Подтверждение — слаг руками: «вы уверены?» на кнопке,
               которая уносит client_id, секрет и все доступы, не работает. */
            case 'delete':
                $this->requireGlobalAdmin();
                /* Зона должна быть открыта паролем — и открыта недавно. Иначе
                   вся защита сводится к тому, что кнопка лежала этажом ниже. */
                if (!\App\Core\DangerZone::unlocked($this->dangerScope((int)$client['id']))) {
                    Flash::error('Опасная зона закрыта — подтвердите пароль заново.');
                    redirect('/admin/clients/' . $client['id'] . '/danger');
                }
                if (trim((string)($_POST['confirm'] ?? '')) !== (string)$client['slug']) {
                    Flash::error('Слаг портала набран неверно — портал не тронут.');
                    redirect('/admin/clients/' . $client['id'] . '/danger');
                }
                /* Back-channel logout не шлём намеренно: сессия SSO общая для
                   всех порталов, и разослать по ней logout значило бы выкинуть
                   людей заодно из остальных. Записи этого портала уходят с ним,
                   его собственная сессия на той стороне истечёт сама, а сказать
                   ему что-то осмысленное мы уже не можем — его больше нет. */
                $counts = Client::purge((int)$client['id']);
                /* Иконка лежит по id, а не по icon_path: путь в базе —
                   отпечаток для сброса кэша, файл всегда один и тот же. */
                @unlink(SSO_ROOT . '/storage/avatars/clients/' . (int)$client['id'] . '.webp');
                Audit::log('admin.client.delete', ['meta' => [
                    'name' => $client['name'], 'client_id' => $client['client_id'], 'removed' => $counts,
                ]]);
                Flash::success(t('Портал «:portal» удалён. Строк убрано: :n.', ['portal' => $client['name'], 'n' => array_sum($counts)]));
                redirect('/admin/clients');

            case 'revoke_invite':
                if (\App\Models\Invite::revoke((int)($_POST['invite_id'] ?? 0), (int)$client['id'])) {
                    Audit::log('admin.invite.revoke', ['client' => (int)$client['id']]);
                    Flash::success('Приглашение отозвано.');
                }
                redirect('/admin/clients/' . $client['id'] . '/invites');
        }
        redirect('/admin/clients/' . $client['id'] . '/artifacts');
    }

    /* ---- Артефакты §6: integration.md и PHP-kit ---- */

    public function integrationMd(string $id): never
    {
        $this->requirePerm('clients.read');
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $this->assertClientScope((int)$client['id']);
        $md = KitBuilder::integrationMd($client, $this->freshSecretFor((int)$client['id']));
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="integration-' . $client['slug'] . '.md"');
        echo $md;
        exit;
    }

    public function kitZip(string $id): never
    {
        $this->requirePerm('clients.read');
        $client = Client::find((int)$id);
        if (!$client) ErrorPage::render(404);
        $this->assertClientScope((int)$client['id']);
        $zipPath = KitBuilder::buildZip($client, $this->freshSecretFor((int)$client['id']));
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="sso-kit-' . $client['slug'] . '.zip"');
        header('Content-Length: ' . (string)filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    /** Секрет в файлы попадает только по осознанному чекбоксу (§6.1). */
    private function freshSecretFor(int $clientDbId): ?string
    {
        if (($_GET['with_secret'] ?? '') !== '1') return null;
        $fresh = $_SESSION['fresh_client_secret'] ?? null;
        return ($fresh['id'] ?? null) === $clientDbId ? ($fresh['secret'] ?? null) : null;
    }
}
