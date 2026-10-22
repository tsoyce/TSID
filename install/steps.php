<?php
/** Обработчики шагов инсталлятора и AJAX-проверки. */

declare(strict_types=1);

/** AJAX: живые проверки БД и SMTP. */
function install_ajax(string $action, array $post): array
{
    if ($action === 'db_test') {
        try {
            $pdo = install_pdo([
                'driver' => $post['db_driver'] ?? 'mysql',
                'host' => trim((string)($post['db_host'] ?? 'localhost')),
                'port' => (int)($post['db_port'] ?? 3306),
                'name' => trim((string)($post['db_name'] ?? '')),
                'user' => trim((string)($post['db_user'] ?? '')),
                'pass' => (string)($post['db_pass'] ?? ''),
            ]);
            if (($post['db_driver'] ?? 'mysql') === 'sqlite') {
                return ['ok' => true, 'message' => 'SQLite открыт (режим разработки).'];
            }
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            $charset = $pdo->query("SHOW VARIABLES LIKE 'character_set_database'")->fetch()['Value'] ?? '?';
            $note = str_starts_with((string)$charset, 'utf8mb4') ? 'кодировка ok' : "кодировка $charset (рекомендуется utf8mb4)";
            return ['ok' => true, 'message' => "Подключился. MySQL/MariaDB $version, $note."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => install_db_hint($e)];
        }
    }
    if ($action === 'smtp_test') {
        try {
            \App\Core\Mailer::transport(
                trim((string)$post['test_email']),
                'SSO — тестовое письмо',
                '<p>SMTP настроен верно. Это тестовое письмо из инсталлятора.</p>',
                'SMTP настроен верно.',
                [
                    'host' => trim((string)$post['smtp_host']),
                    'port' => (int)($post['smtp_port'] ?: 587),
                    'encryption' => (string)$post['smtp_enc'],
                    'user' => trim((string)$post['smtp_user']),
                    'pass' => (string)$post['smtp_pass'],
                    'from_email' => trim((string)$post['smtp_from']) ?: trim((string)$post['smtp_user']),
                    'from_name' => 'SSO',
                ]
            );
            return ['ok' => true, 'message' => 'Письмо отправлено — проверьте ящик (и «Спам»).'];
        } catch (\Throwable $e) {
            $debug = \App\Core\Mailer::lastDebug(4);
            return ['ok' => false, 'message' => 'Не получилось: ' . $e->getMessage()
                . ($debug !== '' ? ' | ' . $debug : '')];
        }
    }
    return ['ok' => false, 'message' => 'Неизвестная проверка'];
}

/** POST шага. Возвращает ['ok'=>bool, 'errors'=>[]]. */
function install_handle_post(int $step, array $post, array &$st): array
{
    $d =& $st['data'];
    switch ($step) {
        case 0:
            return ['ok' => true];

        case 1:
            return install_env_blocked()
                ? ['ok' => false, 'errors' => ['Устраните пункты, отмеченные ❌ — без них продолжать нельзя.']]
                : ['ok' => true];

        case 2:
            $db = [
                'driver' => in_array($post['db_driver'] ?? '', ['mysql', 'sqlite'], true) ? $post['db_driver'] : 'mysql',
                'host' => trim((string)($post['db_host'] ?? 'localhost')),
                'port' => (int)($post['db_port'] ?? 3306),
                'name' => trim((string)($post['db_name'] ?? '')),
                'user' => trim((string)($post['db_user'] ?? '')),
                'pass' => (string)($post['db_pass'] ?? ''),
                'prefix' => preg_match('~^[a-z][a-z0-9_]{0,10}$~', (string)($post['db_prefix'] ?? '')) ? $post['db_prefix'] : 'sso_',
            ];
            if ($db['driver'] === 'sqlite' && $db['name'] === '') {
                $db['name'] = SSO_ROOT . '/storage/dev.sqlite';
            }
            try {
                install_pdo($db);
            } catch (\Throwable $e) {
                return ['ok' => false, 'errors' => ['Подключение не удалось: ' . install_db_hint($e)]];
            }
            $d['db'] = $db;
            return ['ok' => true];

        case 3:
            $db = $d['db'];
            $pdo = install_pdo($db);
            $mode = (string)($post['mode'] ?? 'install');
            if (install_tables_exist($pdo, $db['prefix'], $db['driver']) && $mode === 'ask') {
                return ['ok' => false, 'errors' => ['exists']];
            }
            if ($mode === 'wipe') {
                install_drop_tables($pdo, $db['prefix'], $db['driver']);
            }
            try {
                $d['migration_log'] = install_run_migrations($pdo, $db['prefix'], $db['driver']);
            } catch (\Throwable $e) {
                return ['ok' => false, 'errors' => ['Миграция упала: ' . $e->getMessage()]];
            }
            return ['ok' => true];

        case 4:
            // Криптография: RSA-пара + APP_KEY + cron-токен (§7.1 шаг 4)
            if (empty($d['kid'])) {
                $d['kid'] = \App\Core\Keys::generate(SSO_ROOT . '/storage/keys');
                $d['app_key'] = base64_encode(random_bytes(32));
                $d['cron_token'] = \App\Core\Crypto::randomToken(24);
            }
            return ['ok' => true];

        case 5:
            $errors = [];
            $email = mb_strtolower(trim((string)($post['email'] ?? '')));
            $login = trim((string)($post['login'] ?? ''));
            $pass = (string)($post['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email выглядит некорректно.';
            if ($login !== '' && !preg_match('~^[a-z0-9_.-]{3,32}$~i', $login)) $errors[] = 'Логин: 3–32 символа, латиница/цифры/точки/дефисы.';
            // Суперадмин сам решает, каким будет его пароль — требований нет
            if ($pass === '') $errors[] = 'Пароль не может быть пустым.';
            if ($errors) return ['ok' => false, 'errors' => $errors];
            $d['admin'] = [
                'email' => $email,
                'login' => $login !== '' ? $login : null,
                'last_name' => trim((string)($post['last_name'] ?? '')),
                'first_name' => trim((string)($post['first_name'] ?? '')),
                'password_hash' => password_hash($pass, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2]),
            ];
            return ['ok' => true];

        case 6:
            $issuer = rtrim(trim((string)($post['issuer'] ?? '')), '/');
            if (!preg_match('~^https?://[^\s/]+$~', $issuer)) {
                return ['ok' => false, 'errors' => ['Issuer URL должен быть вида https://sso.example.ru — без пути и слэша на конце.']];
            }
            $d['issuer'] = $issuer;
            $d['app_name'] = trim((string)($post['app_name'] ?? '')) ?: 'SSO';
            $skipMail = !empty($post['skip_mail']);
            $d['mail'] = $skipMail ? ['driver' => (string)($post['mail_driver'] ?? '') === 'log' ? 'log' : 'smtp'] : [
                'driver' => (string)($post['mail_driver'] ?? 'smtp') === 'log' ? 'log' : 'smtp',
                'host' => trim((string)($post['smtp_host'] ?? '')),
                'port' => (int)($post['smtp_port'] ?? 587),
                'encryption' => in_array($post['smtp_enc'] ?? '', ['tls', 'ssl', 'none'], true) ? $post['smtp_enc'] : 'tls',
                'user' => trim((string)($post['smtp_user'] ?? '')),
                'pass' => (string)($post['smtp_pass'] ?? ''),
                'from_email' => trim((string)($post['smtp_from'] ?? '')),
            ];
            return install_finalize($d);
    }
    return ['ok' => false, 'errors' => ['Неизвестный шаг']];
}

/** Финал: конфиг, суперадмин, роли, настройки, lock. Всё атомарно, насколько возможно. */
function install_finalize(array &$d): array
{
    try {
        $db = $d['db'];
        $pdo = install_pdo($db);
        $p = $db['prefix'];
        $nowUtc = gmdate('Y-m-d H:i:s');

        // Роли-система (§9.2)
        $roleIds = [];
        foreach ([['superadmin', 'Суперадмин', 'Полный доступ: ключи, настройки, имперсонизация'],
                  ['admin', 'Администратор', 'Пользователи и порталы, без ключей и настроек'],
                  ['user', 'Пользователь', 'Роль по умолчанию']] as [$slug, $name, $desc]) {
            $stq = $pdo->prepare("SELECT id FROM {$p}roles WHERE slug = ? AND client_id IS NULL");
            $stq->execute([$slug]);
            $id = $stq->fetchColumn();
            if ($id === false) {
                $pdo->prepare("INSERT INTO {$p}roles (client_id, slug, name, description, is_system, created_at) VALUES (NULL, ?, ?, ?, 1, ?)")
                    ->execute([$slug, $name, $desc, $nowUtc]);
                $id = $pdo->lastInsertId();
            }
            $roleIds[$slug] = (int)$id;

            /* Права и уровень — из того же каталога, что у миграции 014.
               Свой список здесь завести нельзя: роли создаёт инсталлятор уже
               ПОСЛЕ миграций, и её PHP-шаг этих ролей ещё не видит — без
               этих строк на свежей установке у всех оставался уровень 20 и
               пустая таблица прав. */
            $level = \App\Core\Permission::DEFAULT_LEVEL[$slug] ?? 20;
            $pdo->prepare("UPDATE {$p}roles SET access_level = ? WHERE id = ?")
                ->execute([$level, (int)$id]);

            $has = $pdo->prepare("SELECT COUNT(*) FROM {$p}role_permissions WHERE role_id = ?");
            $has->execute([(int)$id]);
            if ((int)$has->fetchColumn() === 0) {
                $ins = $pdo->prepare("INSERT INTO {$p}role_permissions (role_id, perm) VALUES (?, ?)");
                foreach (\App\Core\Permission::DEFAULTS[$slug] ?? [] as $perm) {
                    $ins->execute([(int)$id, $perm]);
                }
            }
        }

        // Суперадмин (§7.1 шаг 5): email_verified, verified badge
        $a = $d['admin'];
        $stq = $pdo->prepare("SELECT id FROM {$p}users WHERE email = ?");
        $stq->execute([$a['email']]);
        $uid = $stq->fetchColumn();
        if ($uid === false) {
            $uuid = \App\Core\Crypto::uuid4();
            /* Номер аккаунта выдаём здесь же: инсталлятор пишет суперадмина
               напрямую, минуя User::create, а PHP-шаг миграции 008 к этому
               моменту уже отработал — на пустой таблице. Без этой строки
               единственный аккаунт свежей установки остался бы без номера. */
            $number = \App\Core\AccountNumber::generate();
            $pdo->prepare("INSERT INTO {$p}users (public_id, account_number, login, email, email_verified_at, password_hash,
                last_name, first_name, locale, timezone, is_verified_badge, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ru', 'Europe/Moscow', 1, 'active', ?, ?)")
                ->execute([$uuid, $number, $a['login'], $a['email'], $nowUtc, $a['password_hash'],
                    $a['last_name'] ?: null, $a['first_name'] ?: null, $nowUtc, $nowUtc]);
            $uid = (int)$pdo->lastInsertId();
        }
        foreach (['superadmin', 'user'] as $slug) {
            $stq = $pdo->prepare("SELECT 1 FROM {$p}user_roles WHERE user_id = ? AND role_id = ?");
            $stq->execute([$uid, $roleIds[$slug]]);
            if ($stq->fetchColumn() === false) {
                $pdo->prepare("INSERT INTO {$p}user_roles (user_id, role_id, granted_by, created_at) VALUES (?, ?, NULL, ?)")
                    ->execute([$uid, $roleIds[$slug], $nowUtc]);
            }
        }

        // Настройки в БД
        $set = $pdo->prepare(($db['driver'] === 'sqlite')
            ? "INSERT INTO {$p}settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON CONFLICT(`key`) DO UPDATE SET `value`=excluded.`value`"
            : "INSERT INTO {$p}settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
        $registry = json_encode([$d['kid'] => ['created' => $nowUtc, 'retired_at' => null]]);
        foreach ([
            ['branding.name', $d['app_name']],
            ['keys.active_kid', $d['kid']],
            ['keys.registry', $registry],
            ['cron.heartbeat', ''],
        ] as [$k, $v]) {
            $set->execute([$k, $v, $nowUtc]);
        }

        // Конфиг + lock (§7 финал)
        install_write_config($d);
        file_put_contents(SSO_ROOT . '/config/installed.lock', gmdate('c') . "\n");

        // Аудит установки
        $pdo->prepare("INSERT INTO {$p}audit_log (created_at, actor_user_id, event, meta) VALUES (?, ?, 'install.done', ?)")
            ->execute([$nowUtc, $uid, json_encode(['version' => '2.0.0'])]);

        unset($_SESSION['install']);
        $_SESSION['install_done_info'] = [
            'issuer' => $d['issuer'],
            'cron_token' => $d['cron_token'],
        ];
        return ['ok' => true];
    } catch (\Throwable $e) {
        return ['ok' => false, 'errors' => ['Финальный шаг не удался: ' . $e->getMessage()]];
    }
}
