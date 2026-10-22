<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\DB;
use App\Core\ErrorPage;
use App\Core\Flash;
use App\Core\Mailer;
use App\Core\View;
use App\Models\Client;

/** /admin/logs: аудит + CSV, ошибки приложения (JSON-lines), почтовая очередь (§5.2). */
final class LogsController extends AdminBase
{
    private const PER_PAGE = 50;

    /* ---- Аудит ---- */

    private function auditQuery(): array
    {
        $scope = $this->scopeClientIds();
        $ctx = \App\Core\AdminContext::current();
        $where = [];
        $params = [];

        if ($ctx->is(\App\Core\Scope::ORG)) {
            /* Журнал организации — её собственные события ПЛЮС события её
               проектов; последние получают org_id при записи (v4.27). Именно
               ИЛИ, а не И: у события организации (переименование, смена
               состава) client_id пуст, и условие по порталам его бы срезало —
               журнал показывал бы всё, кроме самого главного. */
            $ids = $scope === null ? [] : $scope;
            $byClient = $ids === [] ? '' : ' OR ' . $this->inIds('a.client_id', $ids);
            $where[] = '(a.org_id = ?' . $byClient . ')';
            $params[] = $ctx->id;
        } elseif ($scope !== null) {
            /* Пустое сужение — не «показать всё», а «показывать нечего». */
            $where[] = $this->inIds('a.client_id', $scope);
        }
        $event = trim((string)($_GET['event'] ?? ''));
        if ($event !== '') {
            $where[] = 'a.event LIKE ?';
            $params[] = $event . '%';
        }
        $userQ = trim((string)($_GET['user'] ?? ''));
        if ($userQ !== '') {
            $where[] = '(u.email LIKE ? OR o.email LIKE ?)';
            $params[] = '%' . $userQ . '%';
            $params[] = '%' . $userQ . '%';
        }
        $clientF = (int)($_GET['client'] ?? 0);
        if ($clientF > 0) {
            $where[] = 'a.client_id = ?';
            $params[] = $clientF;
        }
        $ip = trim((string)($_GET['ip'] ?? ''));
        if ($ip !== '' && ($bin = @inet_pton($ip)) !== false) {
            $where[] = 'a.ip = ?';
            $params[] = $bin;
        }
        foreach (['from' => 'a.created_at >= ?', 'to' => 'a.created_at <= ?'] as $key => $cond) {
            $v = trim((string)($_GET[$key] ?? ''));
            if ($v !== '' && preg_match('~^\d{4}-\d{2}-\d{2}$~', $v)) {
                $where[] = $cond;
                $params[] = $v . ($key === 'to' ? ' 23:59:59' : ' 00:00:00');
            }
        }
        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
        return [$whereSql, $params];
    }

    public function audit(): never
    {
        $this->requirePerm('logs.read');
        [$whereSql, $params] = $this->auditQuery();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $base = 'FROM {audit_log} a
                 LEFT JOIN {users} u ON u.id = a.actor_user_id
                 LEFT JOIN {users} o ON o.id = a.on_user_id
                 LEFT JOIN {clients} c ON c.id = a.client_id
                 WHERE ' . $whereSql;
        $total = (int)DB::val("SELECT COUNT(*) $base", $params);
        $rows = DB::all("SELECT a.*, u.email AS actor_email, o.email AS on_email, c.name AS client_name $base
            ORDER BY a.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        View::render('admin/logs', [
            'pageTitle' => t('Аудит'),
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => (int)ceil($total / self::PER_PAGE),
            'clients' => Client::all(),
        ]);
    }

    /* ---- Запросы и адреса (v4.72) ---- */

    /**
     * Журнал активности: каждый запрос к приложению. Только глобальному
     * администратору — журнал не делится по порталам, а видно в нём всех.
     */
    public function requests(): never
    {
        $this->requirePerm('logs.read');
        $this->requireGlobalAdmin();
        $userQ = trim((string)($_GET['user'] ?? ''));
        $userId = null;
        if ($userQ !== '') {
            $u = \App\Models\User::findByLoginOrEmail($userQ);
            $userId = $u !== null ? (int)$u['id'] : -1;
        }
        $filters = [
            'user' => $userId, 'ip' => trim((string)($_GET['ip'] ?? '')),
            'path' => trim((string)($_GET['path'] ?? '')), 'status' => (string)($_GET['status'] ?? ''),
            'before' => (int)($_GET['before'] ?? 0),
            /* Стуки сканеров — скрыты, пока их не попросили (v4.94). Ищут по
               адресу или ответу — значит, ищут именно их: тогда не прячем. */
            'knocks' => ($_GET['show'] ?? '') === 'all' || trim((string)($_GET['ip'] ?? '')) !== ''
                || ($_GET['status'] ?? '') === 'fail',
        ];
        View::render('admin/logs_requests', [
            'pageTitle' => t('Запросы'),
            'rows' => \App\Core\ActivityLog::find($filters, 100),
            'knocks' => $filters['knocks'] ? null : \App\Core\ActivityLog::knocks(),
            'days' => \App\Core\Setting::getInt('retention.activity_days', 30),
        ]);
    }

    /**
     * Страница адреса: кто с него приходил, что делал, заблокирован ли.
     * Живой случай 21.09: владелец видел адрес в журнале, но собрать по нему
     * картину — какие аккаунты, какие страницы, какие отказы — было негде.
     */
    public function ip(string $ip): never
    {
        $this->requirePerm('logs.read');
        $this->requireGlobalAdmin();
        $ip = rawurldecode($ip);
        $bin = @inet_pton($ip);
        if ($bin === false) ErrorPage::render(404);
        $ip = (string)inet_ntop($bin);
        View::render('admin/ip', [
            'pageTitle' => $ip,
            'ip' => $ip,
            'bin' => $bin,
            'block' => \App\Core\IpBlock::ruleFor($bin),
            'users' => \App\Core\ActivityLog::usersFrom($bin),
            'requests' => \App\Core\ActivityLog::find(['ip' => $ip], 100),
            'audit' => DB::all('SELECT a.*, u.email AS actor_email, o.email AS on_email FROM {audit_log} a
                LEFT JOIN {users} u ON u.id = a.actor_user_id LEFT JOIN {users} o ON o.id = a.on_user_id
                WHERE a.ip = ? ORDER BY a.id DESC LIMIT 50', [$bin]),
            'mine' => ip_bin() === $bin,
        ]);
    }

    public function exportCsv(): never
    {
        $this->requirePerm('logs.read');
        [$whereSql, $params] = $this->auditQuery();
        $rows = DB::all('SELECT a.*, u.email AS actor_email, o.email AS on_email, c.name AS client_name
             FROM {audit_log} a
             LEFT JOIN {users} u ON u.id = a.actor_user_id
             LEFT JOIN {users} o ON o.id = a.on_user_id
             LEFT JOIN {clients} c ON c.id = a.client_id
             WHERE ' . $whereSql . ' ORDER BY a.id DESC LIMIT 10000', $params);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM для Excel
        fputcsv($out, ['id', 'created_at_utc', 'event', 'actor', 'on_user', 'client', 'ip', 'user_agent', 'meta'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'], $r['created_at'], $r['event'],
                $r['actor_email'] ?? $r['actor_user_id'],
                $r['on_email'] ?? $r['on_user_id'],
                $r['client_name'] ?? '',
                ip_str($r['ip']), $r['user_agent'], $r['meta'],
            ], ';');
        }
        fclose($out);
        exit;
    }

    /* ---- Ошибки приложения: tail storage/logs/app-*.log (§5.2) ---- */

    /**
     * Выгрузка журнала ошибок одним файлом — чтобы его можно было приложить
     * к обращению целиком, а не пересказывать скриншотами.
     *
     * Отдаём как есть, JSON-lines: в нём уже лежит request_id, файл, строка и
     * стек. Ничего не вырезаем — иначе в разборе не хватит именно того, что
     * вырезали; секретов в этот лог не пишется по построению (§11.2).
     */
    public function errorsDownload(): never
    {
        $this->requirePerm('logs.read');
        $this->requireGlobalAdmin();
        $day = (string)($_GET['day'] ?? '');
        $files = (array)glob(SSO_ROOT . '/storage/logs/app-*.log');
        rsort($files);
        $file = $files[0] ?? null;
        foreach ($files as $f) {
            if ($day !== '' && str_contains(basename((string)$f), $day)) { $file = $f; break; }
        }
        if (!$file || !is_file($file)) {
            Flash::error(t('Файл журнала не найден.'));
            redirect('/admin/logs/errors');
        }
        \App\Core\Audit::log('admin.logs.export', ['meta' => ['file' => basename($file)]]);

        $name = 'app-log-' . \App\Core\Translit::slug(\App\Core\Setting::appName(), 'sso')
            . '-' . basename((string)$file, '.log') . '.jsonl';
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string)filesize($file));
        readfile($file);
        exit;
    }

    public function errors(): never
    {
        $this->requirePerm('logs.read');
        $this->requireGlobalAdmin();
        $level = (string)($_GET['level'] ?? '');
        $files = (array)glob(SSO_ROOT . '/storage/logs/app-*.log');
        rsort($files);
        $day = (string)($_GET['day'] ?? '');

        /* Поиск по номеру инцидента — отдельным путём, а не фильтром поверх
           общей выборки. Номер точен: по нему ищем по всем дням и без
           ограничения на последние строки, иначе обещание страницы 500
           («событие найдётся в логах») не держится (v4.31). */
        $incident = trim((string)($_GET['incident'] ?? ''));
        if ($incident !== '') {
            $hits = \App\Core\Incident::find($incident);
            View::render('admin/logs_errors', [
                'pageTitle' => t('Журнал приложения'),
                'groups' => self::groupErrors(array_column($hits, 'entry')),
                'total' => count($hits),
                'days' => array_map(fn($f) => substr(basename($f, '.log'), 4), $files),
                'day' => '',
                'level' => '',
                'incident' => $incident,
                'incidentValid' => \App\Core\Incident::valid($incident),
                'incidentFile' => $hits[0]['file'] ?? '',
            ]);
        }
        $file = $files[0] ?? null;
        foreach ($files as $f) {
            if ($day !== '' && str_contains(basename($f), $day)) { $file = $f; break; }
        }
        $entries = [];
        if ($file && is_file($file)) {
            $lines = array_slice((array)file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -800);
            foreach (array_reverse($lines) as $line) {
                $j = json_decode($line, true);
                if (!is_array($j)) continue;
                if ($level !== '' && ($j['level'] ?? '') !== $level) continue;
                $entries[] = $j;
                if (count($entries) >= 200) break;
            }
        }
        View::render('admin/logs_errors', [
            'pageTitle' => t('Журнал приложения'),
            'groups' => self::groupErrors($entries),
            'total' => count($entries),
            'days' => array_map(fn($f) => substr(basename($f, '.log'), 4), $files),
            'day' => $day,
            'level' => $level,
            'incident' => '',
            'incidentValid' => true,
            'incidentFile' => '',
        ]);
    }

    /**
     * Убрать старые журналы приложения.
     *
     * Кнопка нужна, потому что до неё чистка была только по расписанию, а
     * планировщик после установки настроен далеко не всегда. Сегодняшний день
     * не трогаем: в него пишут прямо сейчас.
     */
    public function errorsPurge(): never
    {
        $this->requirePerm('logs.read');
        $this->requireGlobalAdmin();
        $this->requirePerm('housekeeping');
        $days = max(1, (int)($_POST['keep_days'] ?? 14));
        $res = \App\Core\Housekeeping::purgeLogs($days);
        \App\Core\Audit::log('housekeeping.logs', ['meta' => $res + ['keep_days' => $days]]);
        Flash::success($res['files'] > 0
            ? t('Убрано файлов журнала: :n (:size).',
                ['n' => $res['files'], 'size' => \App\Core\Housekeeping::human($res['bytes'])])
            : t('Убирать нечего: журналов старше :n дней нет.', ['n' => $days]));
        redirect('/admin/logs/errors');
    }

    /** Убрать отправленные письма: очередь — не архив. */
    public function mailPurge(): never
    {
        $this->requirePerm('logs.read');
        $this->requireGlobalAdmin();
        $this->requirePerm('housekeeping');
        $days = max(0, (int)($_POST['keep_days'] ?? 7));
        $n = \App\Core\Housekeeping::purgeSentMail($days);
        \App\Core\Audit::log('housekeeping.mail', ['meta' => ['removed' => $n, 'keep_days' => $days]]);
        Flash::success($n > 0
            ? t('Убрано отправленных писем: :n.', ['n' => $n])
            : t('Убирать нечего: отправленных писем старше :n дней нет.', ['n' => $days]));
        redirect('/admin/logs/mail');
    }

    /**
     * Свести записи журнала в группы по «уровень + сообщение».
     *
     * Замер живого журнала: 460 строк за день — это ШЕСТЬ разных сообщений.
     * Экран показывал их по одной карточке на запись и растягивался на 27
     * экранов прокрутки. Читать такое нельзя: одна и та же строка повторяется
     * сотню раз подряд, а редкая и важная тонет между её копиями.
     *
     * Группа несёт то, чего не было видно вовсе, — сколько раз и в каком
     * промежутке. Сами записи никуда не деваются: они внутри группы, по
     * времени, и раскрываются нажатием.
     *
     * @return list<array{level:string,msg:string,count:int,first:string,last:string,items:list<array>}>
     */
    public static function groupErrors(array $entries): array
    {
        $groups = [];
        foreach ($entries as $e) {
            $level = (string)($e['level'] ?? '?');
            $msg = (string)($e['msg'] ?? '');
            $key = $level . "\0" . $msg;
            if (!isset($groups[$key])) {
                $groups[$key] = ['level' => $level, 'msg' => $msg, 'count' => 0,
                                 'first' => '', 'last' => '', 'items' => []];
            }
            $groups[$key]['count']++;
            $groups[$key]['items'][] = $e;
            /* Записи приходят от новых к старым, поэтому «последняя по времени»
               встречается первой. Пустые метки не затирают уже найденные. */
            $ts = (string)($e['ts'] ?? '');
            if ($ts !== '') {
                if ($groups[$key]['last'] === '') $groups[$key]['last'] = $ts;
                $groups[$key]['first'] = $ts;
            }
        }
        /* Порядок: сначала то, что случилось чаще, — именно оно обычно и
           означает поломку. При равном счёте свежее выше. */
        $out = array_values($groups);
        usort($out, static fn(array $a, array $b): int
            => [$b['count'], $b['last']] <=> [$a['count'], $a['last']]);
        return $out;
    }

    /* ---- Почтовая очередь (§5.2) ---- */

    public function mail(): never
    {
        $this->requirePerm('logs.read');
        $this->requireGlobalAdmin();
        $status = (string)($_GET['status'] ?? '');
        $where = in_array($status, ['pending', 'sent', 'failed'], true) ? 'WHERE status = ?' : '';
        $params = $where !== '' ? [$status] : [];
        $rows = DB::all("SELECT * FROM {mail_queue} $where ORDER BY id DESC LIMIT 100", $params);
        $view = (int)($_GET['view'] ?? 0);
        $hb = (string)\App\Core\Setting::get('cron.heartbeat', '');
        View::render('admin/logs_mail', [
            'pageTitle' => t('Почта'),
            'rows' => $rows,
            'status' => $status,
            'viewMail' => $view > 0 ? DB::row('SELECT * FROM {mail_queue} WHERE id = ?', [$view]) : null,
            'cronStale' => $hb === '' || strtotime($hb . ' UTC') < time() - 600,
            'counts' => [
                'pending' => (int)DB::val('SELECT COUNT(*) FROM {mail_queue} WHERE status = ?', ['pending']),
                'sent' => (int)DB::val('SELECT COUNT(*) FROM {mail_queue} WHERE status = ?', ['sent']),
                'failed' => (int)DB::val('SELECT COUNT(*) FROM {mail_queue} WHERE status = ?', ['failed']),
            ],
        ]);
    }

    /** Вернуть всё failed в очередь + сразу попробовать первую пачку. */
    public function mailRetryAll(): never
    {
        $this->requirePerm('logs.read');
        $this->requireGlobalAdmin();
        $n = DB::run('UPDATE {mail_queue} SET status = ?, attempts = 0 WHERE status = ?', ['pending', 'failed'])->rowCount();
        $sent = 0;
        foreach (DB::all('SELECT id FROM {mail_queue} WHERE status = ? ORDER BY id LIMIT 10', ['pending']) as $m) {
            if (Mailer::attempt((int)$m['id'])) $sent++;
        }
        Flash::success(t('Возвращено в очередь: :n; отправлено сразу: :sent. Остальное дошлёт крон.', ['n' => $n, 'sent' => $sent]));
        redirect('/admin/logs/mail');
    }

    public function mailRetry(string $id): never
    {
        $this->requirePerm('logs.read');
        $this->requireGlobalAdmin();
        $mail = DB::row('SELECT * FROM {mail_queue} WHERE id = ?', [(int)$id]);
        if (!$mail) ErrorPage::render(404);
        DB::update('mail_queue', ['status' => 'pending'], 'id = ?', [(int)$id]);
        $ok = Mailer::attempt((int)$id);
        $ok ? Flash::success('Письмо отправлено.') : Flash::error('Снова не ушло — детали в очереди.');
        redirect('/admin/logs/mail');
    }
}
