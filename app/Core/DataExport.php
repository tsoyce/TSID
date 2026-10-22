<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Access;
use App\Models\Passkey;
use App\Models\SessionModel;
use App\Models\User;

/**
 * Экспорт данных пользователя «скачать всё» (GDPR-стиль).
 * Задание ставится из профиля, собирается кроном (аудит бывает большим),
 * ссылка приходит письмом и живёт 24 часа.
 */
final class DataExport
{
    private const TTL = 86400;

    /**
     * Поставить задание. Возвращает plaintext-токен для ссылки или null,
     * если свежая выгрузка уже заказана (чтобы не плодить архивы).
     * Письмо шлёт вызывающий сразу: ссылка подождёт, пока крон соберёт файл.
     */
    public static function request(int $userId): ?string
    {
        $existing = DB::row('SELECT id FROM {export_jobs} WHERE user_id = ? AND status IN (?, ?) AND expires_at > ?',
            [$userId, 'pending', 'ready', now()]);
        if ($existing) return null;

        $token = Crypto::randomToken(32);
        DB::insert('export_jobs', [
            'user_id' => $userId,
            'status' => 'pending',
            'token_hash' => Crypto::hashToken($token),
            'created_at' => now(),
            'expires_at' => now_plus(self::TTL),
        ]);
        Audit::log('account.export_request', ['on' => $userId]);
        return $token;
    }

    /**
     * Заказать и сразу собрать.
     *
     * Раньше задание просто ложилось в очередь и ждало cron. Если планировщик
     * не настроен — а после установки он не настроен почти всегда, — архив
     * висел в «готовится» бесконечно, хотя интерфейс обещал «пару минут».
     * Данные одного человека собираются за доли секунды, очередь тут не нужна:
     * строим сразу, а cron остаётся страховкой для заданий, упавших по дороге.
     *
     * @return array{token: ?string, ready: bool} token=null — уже заказано
     */
    public static function requestAndBuild(int $userId): array
    {
        $token = self::request($userId);
        if ($token === null) return ['token' => null, 'ready' => false];

        $job = DB::row('SELECT id FROM {export_jobs} WHERE user_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
            [$userId, 'pending']);
        if (!$job) return ['token' => $token, 'ready' => false];

        try {
            self::build((int)$job['id']);
        } catch (\Throwable $e) {
            /* Останется pending — cron подберёт; пользователю честно скажем */
            Log::error('export build failed', ['job' => (int)$job['id'], 'err' => $e->getMessage()]);
            return ['token' => $token, 'ready' => false];
        }
        $status = (string)DB::val('SELECT status FROM {export_jobs} WHERE id = ?', [(int)$job['id']]);
        return ['token' => $token, 'ready' => $status === 'ready'];
    }

    /** Собрать архив по заданию (вызывается из cron). */
    public static function build(int $jobId): void
    {
        $job = DB::row('SELECT * FROM {export_jobs} WHERE id = ? AND status = ?', [$jobId, 'pending']);
        if (!$job) return;
        $user = User::find((int)$job['user_id']);
        if (!$user) {
            DB::update('export_jobs', ['status' => 'failed', 'last_error' => 'пользователь не найден'], 'id = ?', [$jobId]);
            return;
        }
        try {
            $dir = SSO_ROOT . '/storage/exports';
            if (!is_dir($dir)) mkdir($dir, 0750, true);
            $rel = 'exports/export-' . $jobId . '-' . substr($job['token_hash'], 0, 12) . '.zip';
            $abs = SSO_ROOT . '/storage/' . $rel;

            $zip = new \ZipArchive();
            if ($zip->open($abs, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('не удалось создать архив');
            }
            $zip->addFromString('profile.json', self::json(self::profile($user)));
            $zip->addFromString('sessions.json', self::json(self::sessions((int)$user['id'])));
            $zip->addFromString('services.json', self::json(self::services((int)$user['id'])));
            $zip->addFromString('passkeys.json', self::json(self::passkeys((int)$user['id'])));
            $zip->addFromString('audit.csv', self::auditCsv((int)$user['id']));
            $avatar = SSO_ROOT . '/storage/avatars/' . $user['public_id'] . '/512.webp';
            if (is_file($avatar)) $zip->addFile($avatar, 'avatar.webp');
            $zip->addFromString('README.txt', self::readme($user));
            $zip->close();

            DB::update('export_jobs', [
                'status' => 'ready',
                'file_path' => $rel,
                'size_bytes' => filesize($abs) ?: 0,
                'ready_at' => now(),
            ], 'id = ?', [$jobId]);
            Log::info('export_ready', ['job' => $jobId, 'user' => (int)$user['id']]);
        } catch (\Throwable $e) {
            DB::update('export_jobs', ['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 255)],
                'id = ?', [$jobId]);
            Log::exception($e, 'export_failed');
        }
    }

    /** Профиль: всё, что храним, кроме хэшей и секретов. */
    private static function profile(array $u): array
    {
        return [
            'sub' => $u['public_id'],
            'account_number' => $u['account_number'] ?? null,
            'email' => $u['email'],
            'email_verified_at' => $u['email_verified_at'],
            'login' => $u['login'],
            'phone' => $u['phone'],
            'first_name' => $u['first_name'],
            'last_name' => $u['last_name'],
            'display_name' => $u['display_name'],
            'birthdate' => $u['birthdate'],
            /* v3.6: новые персональные данные обязаны попадать в выгрузку —
               человек имеет право видеть всё, что о нём хранится */
            'gender' => $u['gender'] ?? null,
            'latin_first_name' => $u['latin_first_name'] ?? null,
            'latin_last_name' => $u['latin_last_name'] ?? null,
            /* Согласия — то, чем оператор доказывает правомерность обработки.
               Человек имеет право видеть эту же запись со своей стороны. */
            'consents' => array_map(static fn($c) => [
                'document' => \App\Core\Legal::title($c['doc_key']),
                'version' => (int)$c['doc_version'],
                'accepted_at' => $c['accepted_at'],
            ], \App\Core\Legal::consentsOf((int)$u['id'])),
            /* Галерея — тоже персональные данные: человек должен видеть, какие
               фотографии о нём хранятся и откуда каждая приехала. */
            'photos' => array_map(static fn($p) => [
                'source' => $p['source'], 'added' => $p['created_at'],
                'is_avatar' => (int)($u['avatar_photo_id'] ?? 0) === (int)$p['id'],
            ], \App\Models\UserPhoto::forUser((int)$u['id'])),
            'extra_emails' => array_map(static fn($e) => [
                'email' => $e['email'], 'source' => $e['source'],
                'verified' => $e['verified_at'] !== null,
            ], \App\Models\UserEmail::forUser((int)$u['id'])),
            'locale' => $u['locale'],
            'timezone' => $u['timezone'],
            'verified_badge' => (bool)$u['is_verified_badge'],
            'two_factor_enabled' => $u['totp_enabled_at'] !== null,
            'status' => $u['status'],
            'created_at' => $u['created_at'],
            'last_login_at' => $u['last_login_at'],
        ];
    }

    private static function sessions(int $userId): array
    {
        return array_map(fn($s) => [
            'device' => $s['device_label'],
            'ip' => ip_str($s['ip']),
            'created_at' => $s['created_at'],
            'last_seen_at' => $s['last_seen_at'],
            'expires_at' => $s['expires_at'],
            'remembered' => (bool)$s['is_remembered'],
        ], SessionModel::activeForUser($userId));
    }

    private static function services(int $userId): array
    {
        return array_map(fn($a) => [
            'portal' => $a['name'],
            'url' => $a['base_url'],
            'status' => $a['status'],
            'first_login_at' => $a['first_login_at'],
            'last_login_at' => $a['last_login_at'],
        ], Access::forUser($userId));
    }

    /** Метаданные ключей — без публичных ключей и credential_id целиком. */
    private static function passkeys(int $userId): array
    {
        return array_map(fn($p) => [
            'label' => $p['label'],
            'created_at' => $p['created_at'],
            'last_used_at' => $p['last_used_at'],
            'transports' => $p['transports'],
        ], Passkey::forUser($userId));
    }

    private static function auditCsv(int $userId): string
    {
        $rows = DB::all('SELECT a.created_at, a.event, a.ip, a.user_agent, c.name AS client
             FROM {audit_log} a LEFT JOIN {clients} c ON c.id = a.client_id
             WHERE a.on_user_id = ? ORDER BY a.id DESC LIMIT 5000', [$userId]);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['Когда (UTC)', 'Событие', 'IP', 'Устройство', 'Портал'], ';');
        foreach ($rows as $r) {
            fputcsv($fh, [$r['created_at'], $r['event'], ip_str($r['ip']),
                device_label((string)$r['user_agent']), $r['client'] ?? ''], ';');
        }
        rewind($fh);
        return (string)stream_get_contents($fh);
    }

    private static function json(array $data): string
    {
        return (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function readme(array $u): string
    {
        return "Выгрузка данных · " . Setting::appName() . "\n"
            . str_repeat('=', 40) . "\n\n"
            . "Аккаунт: " . $u['email'] . "\n"
            . "Собрано: " . gmdate('Y-m-d H:i') . " UTC\n\n"
            . "profile.json    все поля вашего профиля\n"
            . "sessions.json   активные сессии (устройства и IP)\n"
            . "services.json   порталы, куда вы входили\n"
            . "passkeys.json   метаданные ключей (сами ключи не покидают ваши устройства)\n"
            . "audit.csv       журнал событий по вашему аккаунту (до 5000 последних)\n"
            . "avatar.webp     ваш аватар, если загружен\n\n"
            . "Пароль, TOTP-секрет и приватные ключи в выгрузку не входят —\n"
            . "они хранятся только в виде хэшей и шифротекста.\n";
    }

    /** Задание по токену из письма (любого статуса — страница покажет «готовится»). */
    public static function findByToken(string $token): ?array
    {
        $row = DB::row('SELECT * FROM {export_jobs} WHERE token_hash = ?', [Crypto::hashToken($token)]);
        if (!$row || strtotime($row['expires_at'] . ' UTC') <= time()) return null;
        return $row;
    }
}
