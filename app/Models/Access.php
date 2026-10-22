<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Доступ к порталу — отдельный факт, не роль (§9.1). */
final class Access
{
    public static function get(int $userId, int $clientDbId): ?array
    {
        return DB::row('SELECT * FROM {user_client_access} WHERE user_id = ? AND client_id = ?',
            [$userId, $clientDbId]);
    }

    /**
     * Проверка при /authorize. Возвращает статус:
     * 'active' — пускаем; 'pending' — заявка ждёт; 'blocked' — отказ; 'denied' — политика invite.
     */
    public static function resolveForLogin(int $userId, array $client): string
    {
        $access = self::get($userId, (int)$client['id']);
        if ($access !== null) return $access['status'];

        // Доступ через организацию: политика клиента не спрашивается — решение
        // уже принято администратором, выдавшим доступ организации целиком (§v3.0)
        $viaOrg = Org::accessVia($userId, (int)$client['id']);
        if ($viaOrg !== false) {
            self::grant($userId, (int)$client['id'], null, 'active');
            if ($viaOrg['role_id'] !== null) {
                Role::assign($userId, (int)$viaOrg['role_id'], null);
            }
            \App\Core\Audit::log('access.granted', ['on' => $userId, 'client' => (int)$client['id'],
                'meta' => ['via' => 'org', 'org' => $viaOrg['slug']]]);
            return 'active';
        }

        switch ($client['access_policy']) {
            case 'open':
                self::grant($userId, (int)$client['id'], null, 'active');
                return 'active';
            case 'approval':
                self::grant($userId, (int)$client['id'], null, 'pending');
                \App\Core\Audit::log('access.requested', ['on' => $userId, 'client' => (int)$client['id']]);
                return 'pending';
            case 'invite':
            default:
                return 'denied';
        }
    }

    public static function grant(int $userId, int $clientDbId, ?int $grantedBy, string $status = 'active'): void
    {
        $existing = self::get($userId, $clientDbId);
        if ($existing) {
            DB::update('user_client_access', ['status' => $status, 'granted_by' => $grantedBy],
                'user_id = ? AND client_id = ?', [$userId, $clientDbId]);
            if ($status === 'active' && (string)$existing['status'] !== 'active') {
                self::announce('access.granted', $userId, $clientDbId);
            }
            return;
        }
        DB::insert('user_client_access', [
            'user_id' => $userId,
            'client_id' => $clientDbId,
            'status' => $status,
            'granted_by' => $grantedBy,
            'created_at' => now(),
        ]);
        if ($status === 'active') self::announce('access.granted', $userId, $clientDbId);
    }

    public static function setStatus(int $userId, int $clientDbId, string $status): void
    {
        $was = self::get($userId, $clientDbId);
        DB::update('user_client_access', ['status' => $status], 'user_id = ? AND client_id = ?', [$userId, $clientDbId]);
        /* Отзыв — только если доступ БЫЛ: закрыть отклонённую заявку не значит
           что-то отобрать, и портал о ней не слышал. «Закрыть» — это closed:
           сервис уводит учётную запись в архив (v4.64). */
        if ($status !== 'active' && ($was['status'] ?? '') === 'active') {
            self::announce('access.revoked', $userId, $clientDbId, 'closed');
        }
    }

    /**
     * Убрать запись о доступе.
     *
     * $mode — что сделать сервису: removed («Убрать» в админке) — стереть учётную
     * запись и данные; closed (человек сам отказался от сервиса в профиле) — в
     * архив: терять его данные из-за одного клика нельзя. Сообщаем, если доступ
     * был — открытым или уже закрытым: до 4.64 «Убрать» после «Закрыть» молчало,
     * и сервис так и не узнавал, что человека убрали совсем. У отклонённой
     * заявки доступа не было — о ней не сообщаем.
     */
    public static function remove(int $userId, int $clientDbId, string $mode = 'removed'): void
    {
        $was = self::get($userId, $clientDbId);
        DB::run('DELETE FROM {user_client_access} WHERE user_id = ? AND client_id = ?', [$userId, $clientDbId]);
        if (in_array($was['status'] ?? '', ['active', 'blocked'], true)) {
            self::announce('access.revoked', $userId, $clientDbId, $mode);
        }
    }

    /**
     * Сказать порталам, что доступ выдан или отобран.
     *
     * События `access.granted` и `access.revoked` стояли в каталоге вебхуков с
     * v2.3 и не отправлялись ни разу: галочку можно было поставить, и она
     * ничего не делала. Портал, которому доступ важен, узнавал о нём только
     * при следующем входе человека — то есть с задержкой в неопределённость.
     *
     * Сообщаем отсюда, а не из контроллеров: доступ выдают из админки, по
     * приглашению, через организацию и через API, и повторить вызов в четырёх
     * местах значит однажды забыть в пятом.
     *
     * Отзыв уходит ещё и в приёмник событий самого портала (v4.62). Вебхук
     * слушают подписчики, а портал — свой backchannel. «Убрать» в карточке
     * портала стирало строку доступа и не говорило порталу ничего: человек
     * оставался в нём со всеми сессиями. А отзыв через API, наоборот, звал
     * рассылку по ВСЕМ порталам человека и выкидывал его отовсюду.
     */
    private static function announce(string $event, int $userId, int $clientDbId, string $mode = ''): void
    {
        $user = \App\Models\User::find($userId);
        $client = \App\Models\Client::find($clientDbId);
        if (!$user || !$client) return;
        $payload = [
            'sub' => $user['public_id'],
            'email' => $user['email'],
            'client_id' => $client['client_id'],
        ];
        if ($mode !== '') $payload['mode'] = $mode;
        \App\Core\WebhookDispatcher::emit($event, $payload);
        if ($event === 'access.revoked') \App\Oidc\EventDispatcher::accessRevoked($user, $client, $mode !== '' ? $mode : 'closed');
        /* Возврат доступа — тоже в приёмник: закрытого сервис увёл в архив и без
           этого события держал бы там и после того, как доступ открыли (v4.64). */
        if ($event === 'access.granted') \App\Oidc\EventDispatcher::accessGranted($user, $client);
    }

    public static function recordLogin(int $userId, int $clientDbId): void
    {
        $access = self::get($userId, $clientDbId);
        if (!$access) return;
        $data = ['last_login_at' => now()];
        if ($access['first_login_at'] === null) $data['first_login_at'] = now();
        DB::update('user_client_access', $data, 'user_id = ? AND client_id = ?', [$userId, $clientDbId]);
    }

    public static function recordConsent(int $userId, int $clientDbId): void
    {
        DB::update('user_client_access', ['consented_at' => now()], 'user_id = ? AND client_id = ?', [$userId, $clientDbId]);
    }

    /** Сервисы пользователя для /profile/services и админки. */
    public static function forUser(int $userId): array
    {
        return DB::all(
            'SELECT a.*, c.name, c.slug, c.base_url, c.icon_path, c.access_policy, c.is_trusted, c.is_active
             FROM {user_client_access} a JOIN {clients} c ON c.id = a.client_id
             WHERE a.user_id = ? ORDER BY c.sort_order, c.name', [$userId]);
    }

    /** Заявки на доступ (pending) — для админки портала. */
    public static function pendingForClient(int $clientDbId): array
    {
        return DB::all(
            'SELECT a.*, u.email, u.display_name, u.first_name, u.last_name, u.login, u.public_id, u.avatar_path
             FROM {user_client_access} a JOIN {users} u ON u.id = a.user_id
             WHERE a.client_id = ? AND a.status = ? ORDER BY a.created_at', [$clientDbId, 'pending']);
    }
}
