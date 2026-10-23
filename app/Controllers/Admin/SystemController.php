<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\DbInspect;
use App\Core\ErrorPage;
use App\Core\Flash;
use App\Core\Owner;
use App\Core\View;
use App\Models\User;

/**
 * /admin/system — раздел владельца инсталляции (v4.2).
 *
 * Всё в `/admin/settings` объединяет то, что ошибку можно исправить там же.
 * Здесь не так: неверный домен уводит админку на несуществующий адрес, неверные
 * реквизиты базы отрезают от данных всех, включая владельца. Поэтому отдельный
 * раздел, отдельный доступ и проверки до записи, а не после.
 *
 * Замысел целиком — SYSTEM_LIFECYCLE.md.
 */
final class SystemController extends AdminBase
{
    /**
     * Не владелец — 404, а не 403.
     *
     * 403 сообщает, что раздел существует. Суперадмина, который завёл портал,
     * существование кнопки «сбросить проект» не касается вообще.
     */
    private function requireOwner(): void
    {
        if (!Owner::isCurrent()) ErrorPage::render(404);
    }

    public function index(): never
    {
        $this->requireSuperadmin();
        $this->requireOwner();
        settings_page('system', 'admin/system', [
            'pageTitle' => t('Система'),
            'db' => DbInspect::current(),
            'owner' => Owner::user(),
            'candidates' => $this->superadmins(),
            'probe' => $_SESSION['system_probe'] ?? null,
            'move' => $_SESSION['system_move'] ?? null,
            'moved' => \App\Core\DomainMove::recent(),
            'consequences' => \App\Core\DomainMove::consequences(),
            'issuer' => (string)\App\Core\Config::get('app.url', ''),
            'backupFresh' => \App\Core\Reset::backupFresh(),
            'backupAt' => \App\Core\Reset::backupAt(),
            'codeFor' => (string)\App\Core\Setting::get('system.reset_level', ''),
        ]);
        unset($_SESSION['system_probe']);
    }

    /** Проверка чужого подключения. Ничего не переключает — только отвечает. */
    public function probeDb(): never
    {
        $this->requireSuperadmin();
        $this->requireOwner();
        $cfg = $this->dbInput();
        /* Результат кладём в сессию, а не рисуем прямо здесь: пароль от базы
           не должен уехать в историю браузера вместе с перезагрузкой страницы. */
        $_SESSION['system_probe'] = [
            'checks' => DbInspect::probe($cfg),
            'target' => $this->dbLabel($cfg),
        ];
        Audit::log('system.db.probe', ['meta' => ['target' => $_SESSION['system_probe']['target']]]);
        redirect('/admin/system#db');
    }

    /**
     * Переключение на другую базу.
     *
     * Порядок жёсткий: сначала все проверки, потом запись конфига. Приложение с
     * недоступной базой выглядит работающим — Setting::load() глушит исключение,
     * страницы отдают 200 на файловых дефолтах, и падают только POST-ы.
     * Диагностика такого состояния занимает часы, а проверка стоит одного if.
     */
    public function switchDb(): never
    {
        $this->requireSuperadmin();
        $this->requireOwner();
        $cfg = $this->dbInput();

        $checks = DbInspect::probe($cfg);
        $failed = array_values(array_filter($checks, static fn(array $c): bool => !$c['ok']));
        if ($failed !== []) {
            $_SESSION['system_probe'] = ['checks' => $checks, 'target' => $this->dbLabel($cfg)];
            /* Ответ драйвера — только в списке проверок (system_probe), где он
               оформлен цитатой. Во флеше он был второй, сырой копией и
               всплывал на любой следующей странице: прозвон нашёл его на
               /admin/tools. */
            Flash::error(t('Не переключились: не прошла проверка «:what». Что ответила база — в списке проверок ниже.', ['what' => $failed[0]['what']]));
            redirect('/admin/system#db');
        }

        $was = $this->dbLabel((array)\App\Core\Config::get('db', []));
        try {
            $backup = \App\Core\ConfigFile::update(['db' => $cfg]);
        } catch (\Throwable $e) {
            Flash::error(t('Конфиг не переписан: :why', ['why' => $e->getMessage()]));
            redirect('/admin/system#db');
        }

        /* В файловый лог, а не только в аудит: аудит живёт в той самой базе,
           которую мы только что поменяли, и следующая запись уйдёт уже в новую. */
        \App\Core\Log::warning('system.db.switch', [
            'was' => $was, 'now' => $this->dbLabel($cfg), 'backup' => basename($backup),
        ]);
        Audit::log('system.db.switch', ['meta' => ['was' => $was, 'now' => $this->dbLabel($cfg)]]);
        Flash::success(t('База переключена. Копия прежнего конфига: :file.', ['file' => basename($backup)]));
        redirect('/admin/system#db');
    }

    /**
     * Перенести данные в другую базу и переключиться на неё.
     *
     * На время переноса включаем режим обслуживания: строки, записанные после
     * начала копирования, иначе просто потерялись бы, и заметил бы это не мы.
     */
    public function transferDb(): never
    {
        $this->requireSuperadmin();
        $this->requireOwner();
        $cfg = $this->dbInput();
        $back = '/admin/system#db';

        /* Потолок задаётся здесь же, а не в отдельном разделе настроек: узнают
           о нём ровно в тот момент, когда в него упёрлись. Поле стоит рядом с
           кнопкой, значение сохраняется до проверки — поднял и нажал ещё раз. */
        if (isset($_POST['transfer_limit_mb'])) {
            \App\Core\Setting::set('system.transfer_limit_mb',
                (string)max(1, min(4096, (int)$_POST['transfer_limit_mb'])));
        }

        $pre = \App\Core\DbTransfer::preflight();
        if (!$pre['ok']) {
            Flash::error($pre['detail']);
            redirect($back);
        }

        $wasMaintenance = \App\Core\Setting::get('maintenance.enabled') === '1';
        \App\Core\Setting::set('maintenance.enabled', '1');
        $res = \App\Core\DbTransfer::run($cfg);
        if (!$wasMaintenance) \App\Core\Setting::set('maintenance.enabled', '0');

        if (!$res['ok']) {
            Flash::error(t('Перенос не выполнен: :why', ['why' => $res['detail']]));
            redirect($back);
        }

        /* Переносом дело не кончается: пока конфиг смотрит на старую базу,
           работа продолжается в ней, и копия начинает отставать с первой же
           секунды. Поэтому сразу проверяем приёмник и переключаемся. */
        $checks = DbInspect::probe($cfg);
        $failed = array_values(array_filter($checks, static fn(array $c): bool => !$c['ok']));
        if ($failed !== []) {
            $_SESSION['system_probe'] = ['checks' => $checks, 'target' => $this->dbLabel($cfg)];
            Flash::error(t('Данные перенесены, но приёмник не прошёл проверку — остались на прежней базе: :what', ['what' => $failed[0]['what']]));
            redirect($back);
        }
        try {
            $backup = \App\Core\ConfigFile::update(['db' => $cfg]);
        } catch (\Throwable $e) {
            Flash::error(t('Данные перенесены, но конфиг не переписан: :why', ['why' => $e->getMessage()]));
            redirect($back);
        }
        Audit::log('system.db.transfer', ['meta' => ['to' => $this->dbLabel($cfg),
            'rows' => array_sum($res['rows'])]]);
        Flash::success(t(':detail. Работаем с новой базой. Копия прежнего конфига: :file.', ['detail' => $res['detail'], 'file' => basename($backup)]));
        redirect($back);
    }

    /** @return array<string, string|int> */
    private function dbInput(): array
    {
        return [
            'driver' => (string)($_POST['driver'] ?? 'mysql'),
            'host' => trim((string)($_POST['host'] ?? '')),
            'port' => (int)($_POST['port'] ?? 3306),
            'name' => trim((string)($_POST['name'] ?? '')),
            'user' => trim((string)($_POST['user'] ?? '')),
            'pass' => (string)($_POST['pass'] ?? ''),
            'prefix' => trim((string)($_POST['prefix'] ?? '')),
            'charset' => 'utf8mb4',
        ];
    }

    /** Подпись без пароля: она уходит в аудит, в лог и на экран. */
    private function dbLabel(array $cfg): string
    {
        return ($cfg['driver'] ?? '?') . ' · ' . (($cfg['driver'] ?? '') === 'sqlite'
            ? (string)($cfg['name'] ?? '')
            : ($cfg['host'] ?? '') . ':' . ($cfg['port'] ?? '') . '/' . ($cfg['name'] ?? ''));
    }

    /** Передача владения: подтверждает принимающий, а не отдающий. */
    public function transferOwner(): never
    {
        $this->requireSuperadmin();
        $this->requireOwner();
        $target = (int)($_POST['user_id'] ?? 0);
        $user = User::find($target);
        if (!$user || !in_array('superadmin', User::globalRoleSlugs($target), true)) {
            Flash::error('Владельцем может стать только суперадмин.');
            redirect('/admin/system');
        }
        if ($target === Owner::id()) {
            Flash::error(t('Этот аккаунт уже владелец.'));
            redirect('/admin/system');
        }
        $token = \App\Models\OneTimeToken::issue($target, 'owner_transfer',
            ['from' => Owner::id()]);
        \App\Core\Mailer::as($user['email'], static fn() => \App\Core\Mailer::send($user['email'],
            t('Передача владения — :app', ['app' => \App\Core\Setting::appName()]), 'owner_transfer', [
                'name' => user_name($user),
                'url' => base_url('/admin/system/owner/accept/' . $token),
            ]));
        Audit::log('system.owner.offer', ['on' => $target]);
        Flash::success(t('Отправили письмо. Владение перейдёт, когда получатель подтвердит.'));
        redirect('/admin/system');
    }

    /** Принимающий подтверждает ссылкой из письма — GET только показывает кнопку. */
    public function showAcceptOwner(string $token): never
    {
        if (!\App\Models\OneTimeToken::peek($token, 'owner_transfer')) {
            Flash::error('Ссылка устарела или уже использована.');
            redirect('/admin');
        }
        View::render('auth/confirm', [
            'pageTitle' => t('Передача владения'),
            'title' => t('Принять владение инсталляцией?'),
            'lead' => t('Вам станут доступны смена домена, управление базой и сброс проекта.'),
            'button' => t('Принять'),
            'action' => '/admin/system/owner/accept/' . $token,
        ], 'layouts/auth');
    }

    public function acceptOwner(string $token): never
    {
        /* v4.98: сначала сверка, потом гашение. Открытая в чужой сессии
           ссылка сгорала, и владение приходилось передавать заново. */
        $row = \App\Models\OneTimeToken::peek($token, 'owner_transfer');
        if ($row && \App\Core\Auth::id() !== (int)$row['user_id']) {
            Flash::error('Войдите под аккаунтом, которому предложили владение.');
            redirect('/login');
        }
        $row = $row ? \App\Models\OneTimeToken::consume($token, 'owner_transfer') : null;
        if (!$row) {
            Flash::error('Ссылка устарела или уже использована.');
            redirect('/admin');
        }
        $target = (int)$row['user_id'];
        if (!Owner::set($target, (int)($row['payload']['from'] ?? 0) ?: null, 'transfer')) {
            Flash::error('Передать владение не удалось: аккаунт больше не суперадмин.');
            redirect('/admin');
        }
        Flash::success('Владение принято.');
        redirect('/admin/system');
    }

    /* ---- Смена домена (SYSTEM_LIFECYCLE.md §2) ---- */

    /**
     * GET /.well-known/sso-move (и прежний tsoy-move, до 5.0) — слово-подтверждение переезда.
     *
     * Без авторизации: читает его наш же экземпляр, пришедший по новому адресу.
     * Секрета в нём нет — это случайное слово, живущее до применения переезда, и
     * знание его не даёт ничего, кроме ответа «да, это тот же экземпляр».
     */
    public function moveNonce(): never
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        $nonce = \App\Core\DomainMove::publicNonce();
        if ($nonce === '') {
            http_response_code(404);
            exit('');
        }
        exit($nonce);
    }

    /** Шаг 1: проверить, что новый адрес уже отвечает этим же экземпляром. */
    public function checkDomain(): never
    {
        $this->requireSuperadmin();
        $this->requireOwner();
        $url = rtrim(trim((string)($_POST['url'] ?? '')), '/');
        \App\Core\DomainMove::issueNonce($url);
        $_SESSION['system_move'] = [
            'url' => $url,
            'check' => \App\Core\DomainMove::verify($url),
            'nonce' => \App\Core\DomainMove::nonce(),
        ];
        redirect('/admin/system#domain');
    }

    /** Шаг 3: применить. Проверка повторяется здесь же — экран мог полежать. */
    public function moveDomain(): never
    {
        $this->requireSuperadmin();
        $this->requireOwner();
        $url = rtrim(trim((string)($_POST['url'] ?? '')), '/');
        $res = \App\Core\DomainMove::apply($url);
        unset($_SESSION['system_move']);
        if (!$res['ok']) {
            Flash::error(t('Домен не изменён: :why', ['why' => $res['detail']]));
            redirect('/admin/system#domain');
        }
        Flash::success(t('Домен изменён. Писем администраторам порталов: :n. Копия прежнего конфига: :file.', ['n' => $res['mailed'], 'file' => basename($res['backup'])]));
        /* Уходим на новый адрес: остаться на старом значит смотреть на систему,
           которой там больше нет, и считать, что всё цело. */
        redirect($url . '/admin/system');
    }

    public function remindPortals(): never
    {
        $this->requireSuperadmin();
        $this->requireOwner();
        $sent = \App\Core\DomainMove::remind();
        Flash::success($sent > 0
            ? t('Отправили ещё :n писем администраторам порталов.', ['n' => $sent])
            : t('Писем не ушло: у порталов нет своих администраторов.'));
        redirect('/admin/system#domain');
    }

    public function rollbackDomain(): never
    {
        $this->requireSuperadmin();
        $this->requireOwner();
        $prev = \App\Core\DomainMove::recent();
        if (!$prev || !\App\Core\DomainMove::rollback()) {
            Flash::error('Откатывать нечего.');
            redirect('/admin/system');
        }
        Flash::success('Вернули прежний адрес. Passkey это не восстанавливает.');
        redirect($prev['prev'] . '/admin/system');
    }

    /* ---- Сброс (SYSTEM_LIFECYCLE.md §4) ---- */

    /** Шаг 1: код на почту владельца. Без свежего бэкапа даже не начинаем. */
    /**
     * POST /admin/system/purge — смести помеченных на удаление пачкой.
     *
     * Слово «удалить» вместо пароля: опасная зона уже спросила пароль, а
     * второе подтверждение здесь про другое — про то, что человек прочитал
     * список. Набранное слово это доказывает, повторный пароль — нет.
     */
    public function bulkPurge(): never
    {
        $this->requirePerm('users.delete');
        $this->requireSuperadmin();
        $this->requireOwner();
        if (mb_strtolower(trim((string)($_POST['confirm'] ?? ''))) !== mb_strtolower(t('удалить'))) {
            Flash::error(t('Набрано неверно — никого не тронули.'));
            redirect('/admin/system#purge');
        }
        $done = \App\Core\BulkPurge::run();
        if ($done['users'] === 0) {
            Flash::info(t('Удалять было нечего.'));
        } else {
            Flash::success(t('Удалено :n :word, строк убрано — :rows.', [
                'n' => $done['users'],
                'word' => plural($done['users'], 'аккаунт', 'аккаунта', 'аккаунтов'),
                'rows' => $done['rows']]));
        }
        redirect('/admin/system#purge');
    }

    public function resetCode(): never
    {
        $this->requireSuperadmin();
        $this->requireOwner();
        $level = ($_POST['level'] ?? '') === 'project' ? 'project' : 'data';
        if (!\App\Core\Reset::backupFresh()) {
            Flash::error('Сначала скачайте бэкап — кнопка отпирается им, а не галочкой.');
            redirect('/admin/tools');
        }
        $owner = Owner::user();
        \App\Core\Reset::issueCode((int)$owner['id'], (string)$owner['email'], $level);
        Flash::success(t('Код отправлен на :email. Он действует 10 минут.', ['email' => $owner['email']]));
        redirect('/admin/system#reset');
    }

    /**
     * Шаг 2: выполнить.
     *
     * Четыре ограждения проверяются здесь же, а не только рисуются на экране:
     * свежий бэкап, набранный руками адрес инсталляции, код из письма и
     * запись в файловый лог (аудит уносит сама операция).
     */
    public function resetRun(): never
    {
        $this->requireSuperadmin();
        $this->requireOwner();
        $level = ($_POST['level'] ?? '') === 'project' ? 'project' : 'data';
        $back = '/admin/system#reset';

        if (!\App\Core\Reset::backupFresh()) {
            Flash::error('Бэкап устарел. Скачайте свежий и повторите.');
            redirect($back);
        }
        $typed = trim((string)($_POST['confirm'] ?? ''));
        $expected = (string)(parse_url((string)\App\Core\Config::get('app.url'), PHP_URL_HOST) ?: '');
        if ($typed === '' || $typed !== $expected) {
            Flash::error('Адрес инсталляции набран неверно — ничего не тронуто.');
            redirect($back);
        }
        if (!\App\Core\Reset::checkCode((string)($_POST['code'] ?? ''), $level)) {
            Flash::error('Код не подошёл или устарел.');
            redirect($back);
        }

        $owner = Owner::user();
        $email = (string)$owner['email'];
        $name = user_name($owner);
        \App\Core\Reset::forgetCode();

        if ($level === 'data') {
            $counts = \App\Core\Reset::data();
            /* Владелец снесён вместе со всеми: заводим его заново тем же
               адресом, иначе после сброса в систему не войти вообще никому. */
            $this->restoreOwner($email, $name);
            Flash::success(t('Данные сброшены: строк удалено :n. Ваш аккаунт заведён заново — задайте пароль по ссылке из письма.', ['n' => array_sum($counts)]));
            redirect('/login');
        }

        \App\Core\Reset::project($email, $name);
        Flash::success(t('Проект сброшен. Ссылка на установку пароля отправлена на :email.', ['email' => $email]));
        redirect('/login');
    }

    /** После сброса данных владелец обязан существовать — иначе войти некому. */
    private function restoreOwner(string $email, string $name): void
    {
        $userId = \App\Models\User::create([
            'email' => mb_strtolower($email),
            'first_name' => $name !== '' ? $name : 'Владелец',
            'status' => 'pending',
        ]);
        $role = \App\Models\Role::findBySlug('superadmin');
        if ($role) \App\Models\Role::assign($userId, (int)$role['id']);
        Owner::set($userId, null, 'reset');
        $token = \App\Models\OneTimeToken::issue($userId, 'password_set');
        \App\Core\Mailer::send($email, t('Данные сброшены — :app', ['app' => \App\Core\Setting::appName()]), 'reset_done', [
            'name' => $name, 'url' => base_url('/password/set/' . $token),
        ]);
    }

    /** @return list<array{id:int,email:string,name:string}> */
    private function superadmins(): array
    {
        $rows = \App\Core\DB::all(
            /* `login` тоже: user_name() доходит до него, когда у человека нет
               ни отображаемого имени, ни ФИО, — и на строке без этой колонки
               падала вся страница владельца. */
            'SELECT u.id, u.email, u.login, u.first_name, u.last_name, u.display_name
               FROM {users} u
               JOIN {user_roles} ur ON ur.user_id = u.id
               JOIN {roles} r ON r.id = ur.role_id
              WHERE r.slug = ? AND r.client_id IS NULL AND u.status <> ?
              ORDER BY u.id',
            ['superadmin', 'deleted']
        );
        return array_map(static fn(array $u): array => [
            'id' => (int)$u['id'], 'email' => (string)$u['email'], 'name' => user_name($u),
        ], $rows);
    }
}
