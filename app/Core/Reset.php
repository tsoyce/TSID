<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Сброс инсталляции (v4.2). Замысел — SYSTEM_LIFECYCLE.md §4.
 *
 * Два уровня, оба необратимы:
 *
 *  · «данные» — сносит людей и всё, что к ним привязано, оставляя порталы,
 *    роли, настройки, документы и ключи. Это то, чего не хватало чаще всего:
 *    погоняли, завели десяток тестовых аккаунтов, а настраивать порталы
 *    заново неохота.
 *  · «проект» — возвращает установку к состоянию сразу после инсталляции:
 *    пустая база со схемой, новые ключи подписи, новый APP_KEY и cron-токен,
 *    заново заведённый первый суперадмин.
 *
 * Ключи при полном сбросе стираются намеренно. Единственная причина их
 * сохранить — совместимость с порталами, но тот же сброс сносит и порталы:
 * сохранять не с кем. А сбрасывают в двух случаях, и во втором — «что-то
 * утекло» — оставленный приватный ключ ровно то, ради чего сброс затевался.
 */
final class Reset
{
    /** Сколько живёт свежесть бэкапа, отпирающая кнопку. */
    public const BACKUP_FRESH = 3600;

    /** Сколько живёт код подтверждения из письма. */
    public const CODE_TTL = 600;

    /**
     * Что сносит «сброс данных». Порядок — от зависимых к главным: внешние
     * ключи ON DELETE CASCADE есть не везде и не во всех драйверах одинаково.
     */
    private const DATA_TABLES = [
        'session_clients', 'sessions', 'refresh_tokens', 'auth_codes',
        'one_time_tokens', 'recovery_codes', 'passkeys', 'known_devices',
        'linked_identities', 'user_emails', 'user_photos', 'user_client_access',
        'org_members', 'user_roles', 'legal_consents', 'notification_prefs',
        'export_jobs', 'invites', 'audit_log', 'mail_queue', 'rate_limits',
        'webhook_deliveries', 'users',
    ];

    /** Что добавляет «сброс проекта» сверх данных. */
    private const PROJECT_TABLES = [
        'client_role_map', 'client_roles_seen', 'client_events',
        'org_client_access', 'orgs', 'clients', 'api_tokens', 'webhooks',
        'roles', 'settings',
    ];

    /** Свежий бэкап есть? Это единственное неотключаемое условие. */
    public static function backupFresh(): bool
    {
        $at = (string)Setting::get('system.backup_at', '');
        if ($at === '') return false;
        return time() - (int)strtotime($at . ' UTC') <= self::BACKUP_FRESH;
    }

    public static function backupAt(): ?string
    {
        $at = (string)Setting::get('system.backup_at', '');
        return $at !== '' ? $at : null;
    }

    /**
     * Выдать код подтверждения владельцу.
     *
     * Код уходит письмом, а не показывается на экране: он защищает как раз от
     * того, кто уже смотрит на этот экран в чужой открытой вкладке.
     */
    public static function issueCode(int $ownerId, string $email, string $level): string
    {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Setting::set('system.reset_code', password_hash($code, PASSWORD_DEFAULT));
        Setting::set('system.reset_code_at', now());
        Setting::set('system.reset_level', $level);
        Mailer::send($email, t('Код подтверждения сброса — :app', ['app' => Setting::appName()]), 'reset_code', [
            'code' => $code, 'level' => $level === 'project' ? t('полный сброс проекта') : t('сброс данных'),
        ]);
        Audit::log('system.reset.code', ['on' => $ownerId, 'meta' => ['level' => $level]]);
        return $code;
    }

    public static function checkCode(string $code, string $level): bool
    {
        $hash = (string)Setting::get('system.reset_code', '');
        $at = (string)Setting::get('system.reset_code_at', '');
        $forLevel = (string)Setting::get('system.reset_level', '');
        if ($hash === '' || $at === '' || $forLevel !== $level) return false;
        if (time() - (int)strtotime($at . ' UTC') > self::CODE_TTL) return false;
        return password_verify(trim($code), $hash);
    }

    public static function forgetCode(): void
    {
        Setting::delete('system.reset_code');
        Setting::delete('system.reset_code_at');
        Setting::delete('system.reset_level');
    }

    /**
     * Сброс данных: людей нет, устройство инсталляции цело.
     *
     * @return array<string, int> сколько строк снесено по таблицам
     */
    public static function data(): array
    {
        $counts = self::wipe(self::DATA_TABLES);

        /* Файлы людей на диске: аватары, галереи, выгрузки. База очищена, и
           оставленные файлы стали бы сиротами, которых никто уже не свяжет
           с аккаунтом. */
        self::rmTree(SSO_ROOT . '/storage/avatars');
        self::rmTree(SSO_ROOT . '/storage/exports');
        Setting::delete('system.backup_at');

        Log::warning('system.reset.data', ['rows' => array_sum($counts)]);
        return $counts;
    }

    /**
     * Полный сброс: остаётся только config.php, и тот с новыми секретами.
     *
     * Конфиг не удаляем: без него следующий запрос уходит на инсталлятор, а
     * каталог /install после установки положено удалять — установка встала бы
     * намертво. Вместо этого чистим базу, перевыпускаем секреты и заводим
     * первого суперадмина заново.
     *
     * @return array{email: string, link: string}
     */
    public static function project(string $ownerEmail, string $ownerName): array
    {
        self::wipe(array_merge(self::DATA_TABLES, self::PROJECT_TABLES));
        self::rmTree(SSO_ROOT . '/storage/avatars');
        self::rmTree(SSO_ROOT . '/storage/exports');
        self::rmTree(SSO_ROOT . '/storage/logs/mail');

        /* Ключи подписи — прочь. Порталов, с которыми надо было бы сохранить
           совместимость, эта же операция только что снесла. */
        foreach ((array)glob(Keys::dir() . '/*') as $f) {
            if (is_file($f)) @unlink($f);
        }
        $kid = Keys::generate();

        ConfigFile::update([
            'app_key' => base64_encode(random_bytes(32)),
            'cron_token' => Crypto::randomToken(24),
            'kid' => $kid,
        ]);

        /* Роли и настройки снесены — заводим минимум заново тем же кодом, что
           и инсталлятор, чтобы не завести второй источник правды. */
        self::seedRoles();
        $userId = \App\Models\User::create([
            'email' => mb_strtolower($ownerEmail),
            'first_name' => $ownerName !== '' ? $ownerName : 'Владелец',
            'status' => 'pending',
        ]);
        $role = \App\Models\Role::findBySlug('superadmin');
        if ($role) \App\Models\Role::assign($userId, (int)$role['id']);
        Owner::set($userId, null, 'reset');

        $token = \App\Models\OneTimeToken::issue($userId, 'password_set');
        $link = base_url('/password/set/' . $token);
        Mailer::send($ownerEmail, t('Проект сброшен — :app', ['app' => Setting::appName()]), 'reset_done', [
            'name' => $ownerName, 'url' => $link,
        ]);

        Log::warning('system.reset.project', ['owner' => $ownerEmail, 'kid' => $kid]);
        return ['email' => $ownerEmail, 'link' => $link];
    }

    /** @param list<string> $tables @return array<string, int> */
    private static function wipe(array $tables): array
    {
        $counts = [];
        $driver = (string)Config::get('db.driver', 'mysql');
        if ($driver !== 'sqlite') DB::run('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            try {
                $n = (int)DB::val('SELECT COUNT(*) FROM {' . $t . '}');
                DB::run('DELETE FROM {' . $t . '}');
                if ($n > 0) $counts[$t] = $n;
            } catch (\Throwable $e) {
                /* Таблицы может не быть на отстающей схеме — это не повод
                   останавливать сброс на середине. */
                Log::warning('reset.table_skipped', ['table' => $t, 'why' => $e->getMessage()]);
            }
        }
        if ($driver !== 'sqlite') DB::run('SET FOREIGN_KEY_CHECKS = 1');
        Setting::flush();
        return $counts;
    }

    /** Минимальный набор ролей — тот же, что заводит инсталлятор. */
    private static function seedRoles(): void
    {
        foreach ([
            ['superadmin', 'Суперадмин', 'Полный доступ: ключи, настройки, имперсонизация'],
            ['admin', 'Администратор', 'Пользователи и порталы, без ключей и настроек'],
            ['user', 'Пользователь', 'Роль по умолчанию'],
        ] as [$slug, $title, $descr]) {
            if (\App\Models\Role::findBySlug($slug)) continue;
            DB::insert('roles', ['client_id' => null, 'slug' => $slug, 'name' => $title,
                'description' => $descr, 'is_system' => 1, 'created_at' => now()]);
        }
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach ((array)glob($dir . '/*') as $f) {
            if (is_dir($f)) {
                self::rmTree($f);
                @rmdir($f);
            } elseif (basename((string)$f) !== '.gitkeep') {
                @unlink($f);
            }
        }
    }
}
