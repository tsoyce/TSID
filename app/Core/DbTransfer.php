<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Перенос данных в другую базу (v4.2). Замысел — SYSTEM_LIFECYCLE.md §3.3.
 *
 * Схему в приёмнике создают те же миграции, а строки переносятся построчно.
 * Так работает и между драйверами: MySQL → SQLite для dev-копии прода и
 * обратно. Дампом это сделать было бы нельзя — синтаксис у них разный.
 *
 * Потолок обязателен. На shared-хостинге перенос упирается в
 * `max_execution_time` и память, а оборвавшийся перенос оставляет полбазы —
 * состояние хуже, чем «не начинали». Число вынесено в настройку: на VPS его
 * поднимают, на дешёвом хостинге опускают. Убрать нельзя.
 */
final class DbTransfer
{
    public const DEFAULT_LIMIT_MB = 200;

    /** Порядок важен: родительские таблицы раньше зависимых. */
    private const ORDER = [
        'migrations', 'settings', 'roles', 'users', 'user_roles', 'clients',
        'orgs', 'org_members', 'org_client_access', 'client_role_map',
        'client_roles_seen', 'client_events', 'user_client_access',
        'sessions', 'session_clients', 'refresh_tokens', 'auth_codes',
        'one_time_tokens', 'recovery_codes', 'passkeys', 'known_devices',
        'linked_identities', 'user_emails', 'user_photos', 'legal_consents',
        'notification_prefs', 'export_jobs', 'invites', 'api_tokens',
        'webhooks', 'webhook_deliveries', 'mail_queue', 'rate_limits',
        'audit_log',
    ];

    public static function limitMb(): int
    {
        return max(1, Setting::getInt('system.transfer_limit_mb', self::DEFAULT_LIMIT_MB));
    }

    /**
     * Можно ли вообще начинать.
     *
     * @return array{ok: bool, detail: string, size: int}
     */
    public static function preflight(): array
    {
        $cur = DbInspect::current();
        $size = (int)($cur['size'] ?? 0);
        $limit = self::limitMb() * 1024 * 1024;
        if (!$cur['ok']) {
            return ['ok' => false, 'detail' => 'Текущая база не отвечает', 'size' => $size];
        }
        if ($size > $limit) {
            return ['ok' => false, 'size' => $size, 'detail' => 'База больше потолка ('
                . Housekeeping::human($size) . ' против ' . self::limitMb()
                . ' МБ). Перенесите дампом вручную — через веб-интерфейс такой объём не доедет'];
        }
        return ['ok' => true, 'detail' => 'Размер в пределах потолка', 'size' => $size];
    }

    /**
     * Перенести всё в приёмник и вернуть счётчики.
     *
     * Приёмник обязан быть пустым от наших таблиц: переносить поверх чужих
     * данных — способ смешать две инсталляции так, что не разобрать.
     *
     * @return array{ok: bool, detail: string, rows: array<string,int>}
     */
    public static function run(array $target): array
    {
        try {
            $dst = DbInspect::connect($target);
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'Приёмник недоступен: ' . $e->getMessage(), 'rows' => []];
        }
        $prefix = (string)($target['prefix'] ?? 'sso_');

        /* Схему создаём миграциями — тем же кодом, что и инсталлятор. */
        require_once SSO_ROOT . '/install/helpers.php';
        try {
            install_run_migrations($dst, $prefix, (string)($target['driver'] ?? 'mysql'));
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'Схема не создалась: ' . $e->getMessage(), 'rows' => []];
        }

        /* После миграций в приёмнике уже есть служебные строки (роли, версии).
           Чистим, иначе первичные ключи столкнутся с переносимыми. */
        $order = self::ORDER;
        foreach (array_reverse($order) as $t) {
            try { $dst->exec('DELETE FROM `' . $prefix . $t . '`'); } catch (\Throwable) {}
        }

        $rows = [];
        $src = DB::pdo();
        $srcPrefix = (string)Config::get('db.prefix', 'sso_');
        foreach ($order as $t) {
            try {
                $st = $src->query('SELECT * FROM `' . $srcPrefix . $t . '`');
            } catch (\Throwable) {
                continue;   // таблицы нет на отстающей схеме — не повод падать
            }
            $n = 0;
            $ins = null;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if ($ins === null) {
                    $cols = array_keys($row);
                    $ins = $dst->prepare('INSERT INTO `' . $prefix . $t . '` (`'
                        . implode('`, `', $cols) . '`) VALUES ('
                        . implode(', ', array_fill(0, count($cols), '?')) . ')');
                }
                $ins->execute(array_values($row));
                $n++;
            }
            if ($n > 0) $rows[$t] = $n;
        }

        Log::warning('system.db.transfer', ['rows' => array_sum($rows),
            'tables' => count($rows), 'to' => (string)($target['name'] ?? '')]);
        return ['ok' => true, 'detail' => 'Перенесено строк: ' . array_sum($rows), 'rows' => $rows];
    }
}
