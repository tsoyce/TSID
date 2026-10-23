<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Flash;
use App\Core\Mailer;
use App\Core\Setting;
use App\Core\View;
use App\Models\OneTimeToken;
use App\Models\Role;
use App\Models\User;

/** /admin/tools: импорт пользователей (§13), кэши, миграции (§5.2). */
final class ToolsController extends AdminBase
{
    /** POST /admin/tools/housekeeping — выполнить чистку немедленно. */
    public function housekeeping(): never
    {
        $this->requirePerm('housekeeping');
        $this->requireGlobalAdmin();
        /* Именно то же, что делает планировщик, — просто сейчас. Дублировать
           логику нельзя: разошлась бы с cron при первой же правке. */
        $log = \App\Core\CronTasks::runNow(['logs_rotate', 'exports_cleanup', 'expired']);
        Flash::success(t('Чистка выполнена: :log', ['log' => implode('; ', $log)]));
        redirect('/admin/tools#housekeeping');
    }

    /**
     * POST /admin/tools/invite — ссылка-приглашение на регистрацию.
     *
     * Нужна там, где регистрация закрыта: дверь заперта, а этому человеку войти
     * надо. Не путать с приглашением в ПОРТАЛ — то про доступ к сервису и живёт
     * в его карточке; это про появление аккаунта вообще.
     */
    public function signupInvite(): never
    {
        $this->requirePerm('users.write');
        $this->requireGlobalAdmin();
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::error(t('Похоже, в адресе опечатка.'));
            redirect('/admin/tools#invites');
        }
        $inv = \App\Core\Signup::issueInvite($email !== '' ? $email : null, (int)\App\Core\Auth::id());
        $_SESSION['fresh_signup_invite'] = ['url' => $inv['url'], 'email' => $email];
        Audit::log('admin.signup.invite', ['meta' => ['email' => $email ?: '—']]);
        redirect('/admin/tools#invites');
    }

    /** POST /admin/tools/orphans — убрать файлы без записей в базе. */
    public function orphans(): never
    {
        $this->requirePerm('housekeeping');
        $this->requireGlobalAdmin();
        $r = \App\Core\Housekeeping::purgeOrphans();
        Flash::success(t('Сироты убраны: аватаров — :a, фотографий — :p, выгрузок — :e.',
            ['a' => $r['avatars'], 'p' => $r['photos'], 'e' => $r['exports']]));
        redirect('/admin/tools#housekeeping');
    }

    /** Жив ли планировщик — от этого зависит, нужна ли кнопка «сейчас». */
    private function cronAlive(): bool
    {
        $beat = (string)\App\Core\Setting::get('cron.heartbeat', '');
        return $beat !== '' && strtotime($beat . ' UTC') > time() - 3600;
    }

    public function index(): never
    {
        $this->requireGlobalAdmin();
        $preview = $_SESSION['import_preview'] ?? null;
        settings_page('tools', 'admin/tools', [
            'pageTitle' => t('Инструменты'),
            'preview' => $preview,
            'pendingMigrations' => $this->pendingMigrations(),
            /* Отчёт импорта — одноразовый: показали и забыли. Раньше он жил в
               сессии до её конца и выводился при каждом заходе на страницу:
               отчёт об утренней загрузке человек видел и вечером, открыв
               инструменты совсем по другому делу. */
            'importReport' => (static function (): ?array {
                $r = $_SESSION['import_report'] ?? null;
                unset($_SESSION['import_report']);
                return is_array($r) ? $r : null;
            })(),
            'usage' => \App\Core\Housekeeping::usage(),
            'cronAlive' => $this->cronAlive(),
            'apiTokens' => \App\Models\ApiToken::all(),
            'apiScopes' => \App\Models\ApiToken::scopes(),
            'settingsPlan' => (function () {
                $p = $_SESSION['settings_plan'] ?? null;
                unset($_SESSION['settings_plan']);
                return $p;
            })(),
            'signupInvite' => (function () {
                $i = $_SESSION['fresh_signup_invite'] ?? null;
                unset($_SESSION['fresh_signup_invite']);
                return $i;
            })(),
            'freshApiToken' => (function () {
                $t = $_SESSION['fresh_api_token'] ?? null;
                unset($_SESSION['fresh_api_token']);
                return $t;
            })(),
        ]);
        }

    private function pendingMigrations(): array
    {
        $applied = array_column(DB::all('SELECT version FROM {migrations}'), 'version');
        $files = array_map(fn($f) => basename($f, '.sql'), (array)glob(SSO_ROOT . '/migrations/*.sql'));
        sort($files);
        return array_values(array_diff($files, $applied));
    }

    /** Импорт §13: шаг 1 — загрузка+превью, шаг 2 — маппинг колонок и запись. */
    public function import(): never
    {
        $this->requireGlobalAdmin();
        $step = (string)($_POST['step'] ?? 'upload');

        if ($step === 'upload') {
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                Flash::error('Файл не загрузился.');
                redirect('/admin/tools');
            }
            $raw = (string)file_get_contents($f['tmp_name']);
            $rows = $this->parseImport($raw, (string)$f['name']);
            if ($rows === [] || count($rows) < 1) {
                Flash::error('Не удалось разобрать файл: нужен CSV (разделитель , или ;) или JSON-массив объектов.');
                redirect('/admin/tools');
            }
            if (count($rows) > 5000) {
                Flash::info('Файл большой: за раз импортируются первые 5000 строк — прогоните его несколько раз.');
                $rows = array_slice($rows, 0, 5000);
            }
            $_SESSION['import_preview'] = [
                'columns' => array_keys($rows[0]),
                'sample' => array_slice($rows, 0, 5),
                'rows' => $rows,
                'filename' => (string)$f['name'],
            ];
            unset($_SESSION['import_report']);
            redirect('/admin/tools#import');
        }

        if ($step === 'cancel') {
            unset($_SESSION['import_preview'], $_SESSION['import_report']);
            redirect('/admin/tools');
        }

        // step=map: колонки → поля
        $preview = $_SESSION['import_preview'] ?? null;
        if (!$preview) redirect('/admin/tools');
        $map = [];
        foreach (['email', 'login', 'last_name', 'first_name', 'password_hash', 'created_at', 'phone'] as $field) {
            $col = (string)($_POST['map_' . $field] ?? '');
            if ($col !== '' && in_array($col, $preview['columns'], true)) $map[$field] = $col;
        }
        /* Достаточно любого из двух — почты или логина. Раньше требовалась
           именно почта, и перенести людей из сервиса, где регистрация шла
           логином и паролём, было нельзя вовсе: файл отвергался на первом шаге.
           Ровно такие сервисы и приходят первыми — свой почтовый сервер
           заводят далеко не все. */
        if (!isset($map['email']) && !isset($map['login'])) {
            Flash::error('Сопоставьте хотя бы одно поле: email или логин — по чему-то человека надо узнавать.');
            redirect('/admin/tools#import');
        }
        $sendSetLink = !empty($_POST['send_set_link']);
        $report = ['created' => 0, 'skipped' => 0, 'errors' => [], 'phone_dropped' => []];
        foreach ($preview['rows'] as $i => $row) {
            $email = isset($map['email']) ? mb_strtolower(trim((string)($row[$map['email']] ?? ''))) : '';
            $login = isset($map['login']) ? trim((string)($row[$map['login']] ?? '')) : '';
            /* Пустая почта — законный случай, кривая — нет: «-», «нет»,
               «none@» в выгрузках встречаются постоянно, и молча заводить по
               ним аккаунт значит потерять человека. */
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $report['errors'][] = t('строка :n: email «:email» некорректен', ['n' => $i + 2, 'email' => $email]);
                continue;
            }
            if ($email === '' && $login === '') {
                $report['errors'][] = t('строка :n: ни почты, ни логина — узнавать человека не по чему', ['n' => $i + 2]);
                continue;
            }
            if ($email !== '' && User::emailTaken($email)) {
                $report['skipped']++;
                continue;
            }
            /* Человек без почты узнаётся только логином, поэтому занятый логин
               здесь не «пропустим и заведём без него», а пропуск строки: иначе
               получится аккаунт, в который невозможно войти. */
            /* Логин не по правилу — не повод бросить строку: у человека может
               быть почта, и тогда он войдёт по ней. Бросаем только логин. */
            if ($login !== '' && !\App\Core\LoginName::ok($login)) {
                if ($email === '') { $report['skipped']++; continue; }
                $login = '';
            }
            if ($login !== '' && User::loginTaken($login)) {
                if ($email === '') { $report['skipped']++; continue; }
                $login = '';
            }
            $hash = isset($map['password_hash']) ? trim((string)($row[$map['password_hash']] ?? '')) : '';
            $isHash = $hash !== '' && (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$')
                    || str_starts_with($hash, '$2b$') || str_starts_with($hash, '$argon2'));
            $created = isset($map['created_at']) ? trim((string)($row[$map['created_at']] ?? '')) : '';
            $createdAt = ($created !== '' && strtotime($created) !== false)
                ? gmdate('Y-m-d H:i:s', (int)strtotime($created)) : now();
            /* Телефон — как логин: занятый номер не повод терять человека. На
               users.phone стоит UNIQUE, а проверки здесь не было вовсе: второй
               человек с тем же номером — у кого-то в базе или выше в этом же
               файле — падал на ограничении, и строка терялась целиком, а в отчёт
               уходил сырой текст базы. Заводим без телефона и говорим об этом.
               Номер нормализуем тем же правилом, что профиль: «+7 900…» и
               «+7900…» — один номер. */
            $phone = isset($map['phone']) ? User::normalizePhone((string)($row[$map['phone']] ?? '')) : '';
            $phoneDropped = $phone !== '' && User::phoneTaken($phone);
            if ($phoneDropped) $phone = '';
            $data = [
                'email' => $email !== '' ? $email : null,
                'login' => $login !== '' ? $login : null,
                'last_name' => isset($map['last_name']) ? (trim((string)($row[$map['last_name']] ?? '')) ?: null) : null,
                'first_name' => isset($map['first_name']) ? (trim((string)($row[$map['first_name']] ?? '')) ?: null) : null,
                'phone' => $phone !== '' ? $phone : null,
                // чужой bcrypt принимаем как есть: перехэш при первом входе (§13.1)
                'password_hash' => $isHash ? $hash : null,
                'password_legacy' => $isHash ? 1 : 0,
                'status' => 'pending',
                'created_at' => $createdAt,
            ];
            try {
                $uid = User::create($data);
                \App\Core\Signup::grantDefaultRole($uid);
                /* Ссылку «задайте пароль» слать некуда, если почты нет. Такой
                   человек приходит с готовым хэшем — а если не пришёл, пароль
                   ему назначает администратор портала вручную. */
                if (!$isHash && $sendSetLink && $email !== '') {
                    $token = OneTimeToken::issue($uid, 'password_set');
                    Mailer::sendLetter($email, 'reset', [
                        'name' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
                        'url' => base_url('/password/set/' . $token),
                    ]);
                }
                $report['created']++;
                if ($phoneDropped) $report['phone_dropped'][] = $i + 2;
            } catch (\Throwable $e) {
                /* Сырое сообщение базы в отчёт не кладём: «SQLSTATE[23000]:
                   Integrity constraint violation…» ничего не говорит загрузившему
                   и выдаёт устройство схемы. Сырое — в журнал, в отчёт — причина
                   словами. Путь к нему нашёлся замером — занятый телефон; теперь
                   он проверяется выше, а разбор остаётся на случай иного
                   ограничения. */
                \App\Core\Log::warning('admin.import.row', ['row' => $i + 2, 'error' => $e->getMessage()]);
                $report['errors'][] = t('строка :n: :why', ['n' => $i + 2, 'why' => self::importReason($e)]);
            }
        }
        unset($_SESSION['import_preview']);
        $_SESSION['import_report'] = $report;
        Audit::log('admin.import.run', ['meta' => [
            'file' => $preview['filename'], 'created' => $report['created'], 'skipped' => $report['skipped'],
        ]]);
        Flash::success(t('Импорт завершён: создано :created, пропущено :skipped.', ['created' => $report['created'], 'skipped' => $report['skipped']]));
        redirect('/admin/tools#import');
    }

    private function parseImport(string $raw, string $filename): array
    {
        if (str_ends_with(strtolower($filename), '.json') || str_starts_with(ltrim($raw), '[')) {
            $json = json_decode($raw, true);
            return is_array($json) ? array_values(array_filter($json, 'is_array')) : [];
        }
        // CSV: авто-детект разделителя , или ;
        $lines = preg_split('~\R~', trim($raw)) ?: [];
        if (count($lines) < 2) return [];
        $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $header = str_getcsv($lines[0], $sep);
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') continue;
            $vals = str_getcsv($line, $sep);
            $row = [];
            foreach ($header as $i => $col) {
                $row[trim((string)$col)] = $vals[$i] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Экран применения миграций. Отдельный от /admin/tools, потому что тот
     * читает токены API и падает, если не применена именно та миграция,
     * которая эти токены заводит. Здесь — только список версий и кнопка.
     */
    public function migrateScreen(): never
    {
        $this->requireGlobalAdmin();
        View::render('admin/migrate', [
            'pageTitle' => t('Миграции'),
            'pending' => \App\Core\Migrations::pending(),
            'applied' => \App\Core\Migrations::applied(),
        ]);
    }

    public function migrate(): never
    {
        $this->requireGlobalAdmin();
        $result = \App\Core\Migrations::apply();

        /* Кнопку жмут из полосы в шапке, а она висит на любой странице админки.
           Поэтому возвращаемся туда, откуда нажали, а свой адрес держим лишь
           запасным — на случай, если поля возврата в форме не оказалось.
           На провале — экран миграций: там видно, какая упала и почему, и
           оставаться на прежней странице с одним красным всплытием бесполезно. */
        if ($result['failed'] !== null) {
            Audit::log('admin.migrate.failed', ['meta' => [
                'applied' => $result['applied'], 'failed' => $result['failed']]]);
            Flash::error(t('Миграция :v не применилась: :err', [
                'v' => $result['failed'], 'err' => $result['error'] ?? '']));
            redirect('/admin/migrate');
        }
        if ($result['applied'] !== []) {
            Audit::log('admin.migrate', ['meta' => ['applied' => $result['applied']]]);
        }
        Flash::success($result['applied'] === []
            ? t('Все миграции уже применены.')
            : t('Применены: :list', ['list' => implode(', ', $result['applied'])]));
        redirect(back_to('/admin/tools'));
    }

    /** Скачать полный бэкап: ключи + конфиг + дамп (§ROADMAP v2.2). */
    public function backup(): never
    {
        $this->requirePerm('backup');
        $this->requireSuperadmin();
        try {
            $path = \App\Core\Backup::build();
        } catch (\Throwable $e) {
            Flash::error(t('Бэкап не собрался: :why', ['why' => $e->getMessage()]));
            redirect('/admin/tools');
        }
        /* Архив с ключами подписи не остаётся во временной папке, даже если
           скачивание оборвали (v5.6): раньше обрыв убивал скрипт до unlink. */
        register_shutdown_function(static fn() => @unlink($path));
        ignore_user_abort(true);
        Audit::log('admin.backup.download', ['meta' => ['size' => filesize($path)]]);
        $name = 'backup-' . \App\Core\Translit::slug(\App\Core\Setting::appName(), 'sso')
            . '-' . gmdate('Ymd-Hi') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        flush();
        /* Отметка для системного раздела: сброс не отпирается, пока бэкап не
           сделан. Именно факт скачивания, а не галочка «я сделал» — и
           скачивания до конца (v5.6): оборванный архив отметку не ставит. */
        if (!connection_aborted()) \App\Core\Setting::set('system.backup_at', now());
        exit;
    }

    /**
     * GET /admin/tools/settings.json — слепок настроек одним файлом.
     *
     * Секреты по умолчанию не уезжают: файл настроек ходит по почте и лежит в
     * загрузках. Забрать их можно осознанно — тем же приёмом, что у
     * integration.md с `?with_secret=1`.
     */
    public function settingsExport(): never
    {
        $this->requirePerm('settings.write');
        /* v5.4: как у экрана настроек (SettingsController::save) — только
           суперадмин. Через перенос admin с settings.write менял SMTP и
           забирал секреты соцвхода, хотя на сам экран его не пускают. */
        $this->requireSuperadmin();
        $withSecrets = ($_GET['with_secrets'] ?? '') === '1';
        $snap = \App\Core\SettingsTransfer::export($withSecrets);
        Audit::log('admin.settings.export', ['meta' => [
            'keys' => count($snap['settings']), 'secrets' => $withSecrets ? 1 : 0,
        ]]);
        $name = 'settings-' . \App\Core\Translit::slug(Setting::appName(), 'sso')
            . '-' . gmdate('Ymd-Hi') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        echo json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * POST /admin/tools/settings-import — сначала показать, что произойдёт.
     *
     * Тот же порядок, что у переноса людей и переноса базы: экран «что
     * изменится» и только потом кнопка. Настройки правят вход — «нажал и
     * посмотрим» тут не годится.
     */
    public function settingsImport(): never
    {
        $this->requirePerm('settings.write');
        /* v5.4: как у экрана настроек (SettingsController::save) — только
           суперадмин. Через перенос admin с settings.write менял SMTP и
           забирал секреты соцвхода, хотя на сам экран его не пускают. */
        $this->requireSuperadmin();
        $step = (string)($_POST['step'] ?? 'upload');

        if ($step === 'apply') {
            $json = json_decode((string)($_SESSION['settings_import'] ?? ''), true);
            $n = \App\Core\SettingsTransfer::apply($json);
            unset($_SESSION['settings_import']);
            if ($n === 0) {
                Flash::error(t('Ничего не применилось: файл не подошёл или менять было нечего.'));
            } else {
                Flash::success(t('Настройки применены: :n :word.', [
                    'n' => $n, 'word' => plural($n, 'ключ', 'ключа', 'ключей')]));
            }
            redirect('/admin/tools#settings');
        }

        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Flash::error(t('Файл не загрузился.'));
            redirect('/admin/tools#settings');
        }
        /* Потолок нарочно скромный: слепок настроек — десятки килобайт, и файл
           на мегабайты означает, что грузят не то. */
        if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
            Flash::error(t('Файл больше 2 МБ — это не слепок настроек.'));
            redirect('/admin/tools#settings');
        }
        $raw = (string)file_get_contents((string)$file['tmp_name']);
        $json = json_decode($raw, true);
        $plan = \App\Core\SettingsTransfer::plan($json);
        if (!$plan['ok']) {
            Flash::error($plan['error']);
            redirect('/admin/tools#settings');
        }
        $_SESSION['settings_import'] = $raw;
        $_SESSION['settings_plan'] = $plan + ['from' => (string)($json['app'] ?? '—'),
            'version' => (string)($json['version'] ?? '—')];
        redirect('/admin/tools#settings');
    }

    /** Выдать токен Admin API — показывается один раз. */
    public function createApiToken(): never
    {
        $this->requireSuperadmin();
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::error('У токена должно быть название — по нему он опознаётся в аудите.');
            redirect('/admin/tools#api');
        }
        $scopes = (array)($_POST['scopes'] ?? []);
        $days = (int)($_POST['days'] ?? 0) ?: null;
        [$id, $token] = \App\Models\ApiToken::create($name, $scopes, (int)\App\Core\Auth::id(), $days);
        $_SESSION['fresh_api_token'] = $token;
        /* Право «*» — уровень суперадмина для программы. Оно обходит второй
           фактор и замок: у токена их просто нет. Запрещать не будем — владелец
           вправе, — но сказать обязаны, и особенно про бессрочность: такой
           токен переживёт и повод, ради которого выдан (v4.51). */
        $isAll = in_array(\App\Models\ApiToken::SCOPE_ALL, $scopes, true);
        Audit::log('admin.api_token.create', ['meta' => ['name' => $name, 'id' => $id,
            'scopes' => $scopes, 'superadmin' => $isAll, 'days' => $days]]);
        Flash::success($isAll
            ? t('Токен уровня суперадмина создан. Скопируйте его — второй раз не покажем.')
              . ($days === null ? ' ' . t('Он бессрочный: отзовите его, когда надобность отпадёт.') : '')
            : t('Токен создан. Скопируйте его — второй раз не покажем.'));
        redirect('/admin/tools#api');
    }

    public function revokeApiToken(string $id): never
    {
        $this->requireSuperadmin();
        \App\Models\ApiToken::revoke((int)$id);
        Audit::log('admin.api_token.revoke', ['meta' => ['id' => (int)$id]]);
        Flash::success('Токен отозван.');
        redirect('/admin/tools#api');
    }

    /**
     * Убрать отозванные токены из списка.
     *
     * Отозванный токен не работает, но строку занимает, и через год список
     * состоит из них. Показать ключ ещё раз нельзя — в базе лежит только его
     * хэш, — поэтому единственное осмысленное действие с отозванным это убрать
     * его с глаз.
     */
    public function purgeApiTokens(): never
    {
        $this->requireSuperadmin();
        $n = \App\Models\ApiToken::purgeRevoked();
        Audit::log('admin.api_token.purge', ['meta' => ['removed' => $n]]);
        Flash::success($n > 0
            ? t('Убрано отозванных токенов: :n.', ['n' => $n])
            : t('Отозванных токенов не было.'));
        redirect('/admin/tools#api');
    }

    public function clearCache(): never
    {
        $this->requirePerm('housekeeping');
        $this->requireGlobalAdmin();
        $n = 0;
        foreach ((array)glob(SSO_ROOT . '/storage/cache/*') as $f) {
            if (is_file($f) && basename($f) !== 'openssl.cnf') { @unlink($f); $n++; }
        }
        Setting::delete('health.smtp');
        Setting::delete('health.smtp_at');
        Flash::success(t('Кэш очищен (:n :word).', ['n' => $n, 'word' => plural($n, 'файл', 'файла', 'файлов')]));
        redirect('/admin/tools');
    }
    /**
     * Почему строка импорта не записалась — словами, без сырого текста базы.
     *
     * Сообщения валидации и так человеческие — их отдаём как есть. Ошибку базы
     * узнаём по классу или по «SQLSTATE» в тексте: самый частый случай (дубль
     * внутри одного файла) называем, остальное отсылаем в журнал.
     */
    public static function importReason(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if (!($e instanceof \PDOException) && !str_contains($msg, 'SQLSTATE')) return $msg;
        /* Какая колонка упёрлась в ограничение — берём из текста базы, а не
           угадываем. Первая версия угадывала и на дубль телефона отвечала
           «такой адрес или логин уже встречался»: замер показал, что врёт. */
        if (preg_match('~(?:users\.|uq_(?:users_)?)(phone|email|login|account_number)\b~i', $msg, $m)) {
            return match (strtolower($m[1])) {
                'phone' => t('такой телефон уже есть у другого человека'),
                'email' => t('такой адрес уже есть у другого человека'),
                'login' => t('такой логин уже есть у другого человека'),
                default => t('такой номер аккаунта уже есть у другого человека'),
            };
        }
        if (preg_match('~SQLSTATE\[23000\]|UNIQUE constraint|Duplicate entry~i', $msg)) {
            return t('такое значение уже есть у другого человека');
        }
        return t('база не приняла строку — подробности в журнале приложения');
    }
}
