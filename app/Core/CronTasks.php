<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\AuthCode;
use App\Models\ClientEvent;
use App\Models\OneTimeToken;
use App\Models\RefreshToken;
use App\Oidc\EventDispatcher;

/**
 * Cron-задачи (§11.4): одна точка входа, внутри — расписание.
 * Планировщик дергает раз в минуту; каждая задача сама знает свою частоту.
 */
final class CronTasks
{
    /**
     * Запустить названные задачи немедленно, минуя интервалы.
     *
     * Нужно кнопке «почистить сейчас»: планировщик после установки настроен
     * далеко не всегда, а ждать сутки до logs_rotate человек не обязан.
     * Логика не дублируется — берётся тот же список задач.
     *
     * @param  list<string> $only
     * @return list<string>
     */
    public static function runNow(array $only): array
    {
        $log = [];
        foreach (self::tasks() as [$name, , $fn]) {
            if (!in_array($name, $only, true)) continue;
            try {
                Setting::set('cron.last.' . $name, now());
                $log[] = "$name: " . $fn();
            } catch (\Throwable $e) {
                $log[] = "$name: ОШИБКА " . $e->getMessage();
                Log::exception($e, "cron.$name");
            }
        }
        return $log;
    }

    /**
     * Список задач — один источник для run() и runNow(). Раздельные копии
     * разошлись бы при первой же правке, и «почистить сейчас» делало бы не то,
     * что делает планировщик.
     */
    private static function tasks(): array
    {
        return [
            ['auth_codes', 300, fn() => 'удалено ' . AuthCode::purgeExpired()],
            ['expired', 3600, function () {
                $s = DB::run('DELETE FROM {sessions} WHERE expires_at < ?', [now_plus(-86400)])->rowCount();
                $o = OneTimeToken::purgeExpired();
                $r = RefreshToken::purgeExpired();
                return "сессий $s, ott $o, refresh $r";
            }],
            ['mail_queue', 60, function () {
                $rows = DB::all('SELECT id FROM {mail_queue} WHERE status = ? ORDER BY id LIMIT 20', ['pending']);
                $sent = 0;
                foreach ($rows as $row) {
                    if (Mailer::attempt((int)$row['id'])) $sent++;
                }
                return 'отправлено ' . $sent . ' из ' . count($rows);
            }],
            ['client_events', 60, function () {
                $due = ClientEvent::due(50);
                $ok = 0;
                foreach ($due as $ev) {
                    if (EventDispatcher::attempt((int)$ev['id'])) $ok++;
                }
                return 'доставлено ' . $ok . ' из ' . count($due);
            }],
            ['webhooks', 60, function () {
                $due = WebhookDispatcher::due(30);
                $ok = 0;
                foreach ($due as $d) {
                    if (WebhookDispatcher::attempt((int)$d['id'])) $ok++;
                }
                return 'доставлено ' . $ok . ' из ' . count($due);
            }],
            ['logs_rotate', 86400, function () {
                // Сроки хранения настраиваются в /admin/settings → Политики (0 = вечно)
                $days = Setting::getInt('retention.app_logs_days', 30);
                $removed = 0;
                if ($days > 0) {
                    foreach ((array)glob(SSO_ROOT . '/storage/logs/app-*.log') as $f) {
                        if (filemtime($f) < time() - $days * 86400) { @unlink($f); $removed++; }
                    }
                }
                foreach ((array)glob(SSO_ROOT . '/storage/logs/mail/*.eml') as $f) {
                    if (filemtime($f) < time() - 7 * 86400) { @unlink($f); $removed++; }
                }
                $out = "файлов удалено: $removed";

                // Аудит и почтовая очередь — по своим срокам
                $auditDays = Setting::getInt('retention.audit_days', 0);
                if ($auditDays > 0) {
                    $n = DB::run('DELETE FROM {audit_log} WHERE created_at < ?',
                        [now_plus(-$auditDays * 86400)])->rowCount();
                    $out .= ", аудита: $n";
                }
                /* Журнал активности (v4.72) — самый объёмный: запись на каждый запрос. */
                $n = ActivityLog::purge(Setting::getInt('retention.activity_days', 30));
                if ($n > 0) $out .= ", активности: $n";
                $mailDays = Setting::getInt('retention.mail_days', 30);
                if ($mailDays > 0) {
                    /* v4.98: и неотправленные, и доставки событий и вебхуков —
                       тем же сроком. Чистились только отправленные письма, а
                       failed, webhook_deliveries и client_events (с JSON
                       внутри) копились вечно. Ждущие доставки не трогаем. */
                    $before = now_plus(-$mailDays * 86400);
                    $n = DB::run('DELETE FROM {mail_queue} WHERE status IN (?, ?) AND created_at < ?',
                        ['sent', 'failed', $before])->rowCount();
                    $out .= ", писем: $n";
                    $n = DB::run('DELETE FROM {webhook_deliveries} WHERE status <> ? AND created_at < ?',
                        ['pending', $before])->rowCount()
                        + DB::run('DELETE FROM {client_events} WHERE status <> ? AND created_at < ?',
                        ['pending', $before])->rowCount();
                    if ($n > 0) $out .= ", доставок: $n";
                }
                /* Счётчики лимитов: строка на каждый адрес и почту навсегда.
                   Самое длинное окно — часы; двое суток — с запасом. */
                $n = DB::run('DELETE FROM {rate_limits} WHERE window_start < ?', [now_plus(-2 * 86400)])->rowCount();
                if ($n > 0) $out .= ", счётчиков лимитов: $n";
                return $out;
            }],
            ['exports_cleanup', 3600, function () {
                // Протухшие выгрузки данных: файл + запись
                $rows = DB::all('SELECT id, file_path FROM {export_jobs} WHERE expires_at < ? AND status != ?',
                    [now(), 'expired']);
                foreach ($rows as $r) {
                    if ($r['file_path'] && is_file(SSO_ROOT . '/storage/' . $r['file_path'])) {
                        @unlink(SSO_ROOT . '/storage/' . $r['file_path']);
                    }
                    DB::update('export_jobs', ['status' => 'expired', 'file_path' => null], 'id = ?', [(int)$r['id']]);
                }
                return 'выгрузок протухло: ' . count($rows);
            }],
            ['exports_build', 60, function () {
                $pending = DB::all('SELECT id FROM {export_jobs} WHERE status = ? ORDER BY id LIMIT 3', ['pending']);
                foreach ($pending as $job) {
                    \App\Core\DataExport::build((int)$job['id']);
                }
                return 'собрано выгрузок: ' . count($pending);
            }],
            ['heartbeat', 300, function () {
                Setting::set('cron.heartbeat', now());
                return 'ok';
            }],
            ['anonymize', 86400, fn() => 'анонимизировано ' . self::anonymizeDeleted()],
            /* Манифесты порталов: раз в час обходим тех, кого ещё не спрашивали,
               и тех, у кого сведения устарели.
               Почему здесь, а не на входе. В дорожной карте я предлагал дёргать
               манифест после первого успешного входа через портал — от этого
               пришлось отказаться: запрос к чужому серверу с таймаутами занимает
               до восьми секунд, и они легли бы прямо на вход человека. Вход
               обязан быть быстрым; фоновая задача узнаёт то же самое, просто
               на час позже. */
            ['portal_manifests', 3600, fn() => PortalManifest::refreshDue()],
            /* Обложки витрины с сайтов порталов (v4.76): сама задача берёт
               каждый портал не чаще раза в сутки. */
            ['portal_covers', 3600, fn() => \App\Core\PortalCover::refreshDue()],
            /* Свои адреса: discovery, ключи, экран входа. Панель хостинга может
               перезаписать конфиг веб-сервера молча — узнаём в тот же час. */
            ['self_watch', 3600, fn() => SelfWatch::run()],
        ];
    }

    /** Запуск всех «созревших» задач. Возвращает лог. */
    public static function run(): array
    {
        /* v4.98: один запуск за раз — и из CLI, и из /cron. Отметка «когда
           последний раз» ставится не атомарно, и запуск, начатый, пока прошлый
           ещё ждёт медленный SMTP, брал те же задачи и те же письма. */
        $dir = SSO_ROOT . '/storage';
        $lock = @fopen($dir . '/cron.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['пропущено: ещё идёт прошлый запуск'];
        }
        try {
            return self::runUnlocked();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function runUnlocked(): array
    {
        $log = [];
        foreach (self::tasks() as [$name, $interval, $fn]) {
            $key = 'cron.last.' . $name;
            $last = (string)Setting::get($key, '');
            if ($last !== '' && strtotime($last . ' UTC') > time() - $interval) continue;
            Setting::set($key, now());
            try {
                $log[] = "$name: " . $fn();
            } catch (\Throwable $e) {
                $log[] = "$name: ОШИБКА " . $e->getMessage();
                Log::exception($e, "cron.$name");
            }
        }
        return $log;
    }

    /**
     * Анонимизация soft-deleted старше 14 дней (§5.1): email→hash, ФИО→NULL,
     * аватар и галерея удалены.
     *
     * Публичный, чтобы вызываться напрямую: это самая необратимая операция в
     * системе, а проверять её через общий run() с суточным интервалом значило
     * бы не проверять вовсе.
     */
    public static function anonymizeDeleted(): int
    {
        $rows = DB::all(
            'SELECT * FROM {users} WHERE status = ? AND deleted_at IS NOT NULL AND deleted_at < ? AND email NOT LIKE ?',
            ['deleted', now_plus(-EventDispatcher::DELETE_HOLD_DAYS * 86400), 'deleted-%']);
        foreach ($rows as $u) {
            $uid = (int)$u['id'];
            /* Затирание — одним куском (v4.45). Обрыв между затиранием данных
               и удалением кодов оставлял худший вариант: личные данные уже
               стёрты, а коды восстановления и одноразовые токены живы — то
               есть аккаунт, которого «нет», всё ещё можно восстановить. */
            DB::tx(static function () use ($u, $uid): void {
            DB::update('users', [
                'email' => 'deleted-' . substr(hash('sha256', $u['email']), 0, 24) . '@anonymized.invalid',
                'login' => null,
                'phone' => null,
                'password_hash' => null,
                'last_name' => null, 'first_name' => null, 'middle_name' => null,
                'display_name' => null, 'avatar_path' => null, 'birthdate' => null,
                'totp_secret' => null, 'totp_enabled_at' => null,
                'updated_at' => now(),
            ], 'id = ?', [$uid]);
            DB::run('DELETE FROM {recovery_codes} WHERE user_id = ?', [$uid]);
            DB::run('DELETE FROM {one_time_tokens} WHERE user_id = ?', [$uid]);
            Audit::log('account.delete_done', ['actor' => null, 'on' => $uid]);
            });

            /* Порталам — что удаление стало окончательным. Раньше обезличивание
               проходило молча, и портал, отключивший человека по scheduled, так
               и ждал его возвращения (v4.62). Строки доступа обезличивание не
               трогает — значит, есть кому сказать. */
            EventDispatcher::userDeleted($u, 'final');

            /* Файлы — после фиксации: транзакция их не откатит, а снимки
               человека, чья анонимизация сорвалась, вернуть будет нечем.
               Подкаталог photos обязателен к обходу: glob по '/*' в него не
               заходит, unlink на каталоге не срабатывает, и rmdir не смог бы
               удалить непустую папку — фотографии пережили бы анонимизацию. */
            $dir = SSO_ROOT . '/storage/avatars/' . $u['public_id'];
            foreach ((array)glob($dir . '/photos/*') as $f) @unlink((string)$f);
            @rmdir($dir . '/photos');
            foreach ((array)glob($dir . '/*') as $f) @unlink((string)$f);
            @rmdir($dir);
        }
        return count($rows);
    }
}
