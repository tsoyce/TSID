<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\DB;

/** Центральные SSO-сессии ({sessions}) + входы в порталы ({session_clients}). */
final class SessionModel
{
    public static function findActiveByToken(string $token): ?array
    {
        $row = DB::row('SELECT * FROM {sessions} WHERE token_hash = ?', [Crypto::hashToken($token)]);
        if (!$row) return null;
        if ($row['revoked_at'] !== null) return null;
        if (strtotime($row['expires_at'] . ' UTC') <= time()) return null;
        return $row;
    }

    public static function create(int $userId, string $token, bool $remember, int $ttl): int
    {
        return DB::insert('sessions', [
            'user_id' => $userId,
            'token_hash' => Crypto::hashToken($token),
            'ip' => ip_bin(),
            'user_agent' => request_ua(),
            'device_label' => device_label(),
            'is_remembered' => $remember ? 1 : 0,
            'created_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now_plus($ttl),
        ]);
    }

    /** last_seen обновляем не чаще раза в минуту; UA-смена — событие в аудит (§8.2). */
    public static function touch(array $sess): void
    {
        if (strtotime($sess['last_seen_at'] . ' UTC') > time() - 60) return;
        $data = ['last_seen_at' => now()];
        $ua = request_ua();
        if ($ua !== '' && $sess['user_agent'] !== null && $sess['user_agent'] !== $ua) {
            \App\Core\Audit::log('session.ua_changed', [
                'actor' => (int)$sess['user_id'], 'on' => (int)$sess['user_id'],
                'meta' => ['old' => mb_substr((string)$sess['user_agent'], 0, 80), 'new' => mb_substr($ua, 0, 80)],
            ]);
            $data['user_agent'] = $ua;
            $data['device_label'] = device_label();
        }
        DB::update('sessions', $data, 'id = ?', [$sess['id']]);
    }

    public static function revoke(int $id): void
    {
        DB::update('sessions', ['revoked_at' => now()], 'id = ? AND revoked_at IS NULL', [$id]);
    }

    /** Все живые сессии пользователя (для /profile/sessions и админки). */
    public static function activeForUser(int $userId): array
    {
        return DB::all(
            'SELECT * FROM {sessions} WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ?
             ORDER BY last_seen_at DESC', [$userId, now()]);
    }

    /**
     * Живые сессии, сложенные по устройству (v4.92): одна строка на браузер
     * с одного адреса. Раньше каждая сессия шла своей строкой, и у человека,
     * входившего по ссылкам из писем, «Сессии» были тремя экранами одинаковых
     * «Chrome · Windows». Текущая группа — первой, остальные по свежести.
     *
     * @param list<array> $rows строки activeForUser()
     * @return list<array{label:?string, ip:?string, mobile:bool, count:int, ids:list<int>,
     *                    created_at:string, last_seen_at:string, current:bool, remembered:bool}>
     */
    public static function groups(array $rows, int $currentId = 0): array
    {
        $out = [];
        foreach ($rows as $s) {
            $key = (string)($s['device_label'] ?? '') . '|' . bin2hex((string)($s['ip'] ?? ''));
            $out[$key] ??= ['label' => $s['device_label'] ?? null, 'ip' => $s['ip'] ?? null,
                'mobile' => is_mobile_ua((string)($s['user_agent'] ?? '')), 'count' => 0, 'ids' => [],
                'created_at' => (string)$s['created_at'], 'last_seen_at' => (string)$s['last_seen_at'],
                'current' => false, 'remembered' => false];
            $g = &$out[$key];
            $g['count']++;
            $g['ids'][] = (int)$s['id'];
            if ((string)$s['created_at'] < $g['created_at']) $g['created_at'] = (string)$s['created_at'];
            if ((string)$s['last_seen_at'] > $g['last_seen_at']) $g['last_seen_at'] = (string)$s['last_seen_at'];
            if ((int)$s['id'] === $currentId) $g['current'] = true;
            if (!empty($s['is_remembered'])) $g['remembered'] = true;
            unset($g);
        }
        $out = array_values($out);
        usort($out, static fn(array $a, array $b): int
            => [$b['current'], $b['last_seen_at']] <=> [$a['current'], $a['last_seen_at']]);
        return $out;
    }

    /**
     * Завершить сессии человека по списку — с рассылкой выхода порталам (v4.92).
     * Одна дорога для «Устройств» в профиле и для раздела «Сессии» в админке.
     * Чужие и уже закрытые номера пропускаются; $exceptId — сессия того, кто
     * нажимает: завершить самого себя строкой списка нельзя.
     *
     * @return list<int> номера завершённых
     */
    public static function endMany(int $userId, array $ids, int $exceptId = 0): array
    {
        $want = array_flip(array_map('intval', $ids));
        $done = [];
        foreach (self::activeForUser($userId) as $s) {
            $sid = (int)$s['id'];
            if (!isset($want[$sid]) || $sid === $exceptId) continue;
            \App\Oidc\EventDispatcher::sessionLogout($sid);
            self::revoke($sid);
            $done[] = $sid;
        }
        return $done;
    }

    /** Отозвать все сессии пользователя (кроме $exceptId). Возвращает id отозванных. */
    public static function revokeAllForUser(int $userId, ?int $exceptId = null): array
    {
        $rows = self::activeForUser($userId);
        $revoked = [];
        foreach ($rows as $row) {
            if ($exceptId !== null && (int)$row['id'] === $exceptId) continue;
            self::revoke((int)$row['id']);
            $revoked[] = (int)$row['id'];
        }
        return $revoked;
    }

    /* ---- Входы в порталы из сессии ---- */

    /** sid для пары сессия+клиент (создаётся при первом входе в портал). */
    public static function sidFor(int $sessionId, int $clientDbId): string
    {
        $row = DB::row('SELECT sid FROM {session_clients} WHERE session_id = ? AND client_id = ? AND logged_out_at IS NULL',
            [$sessionId, $clientDbId]);
        if ($row) return $row['sid'];
        $sid = Crypto::uuid4();
        DB::insert('session_clients', [
            'session_id' => $sessionId,
            'client_id' => $clientDbId,
            'sid' => $sid,
            'created_at' => now(),
        ]);
        return $sid;
    }

    /** Порталы, куда входили из сессии (для рассылки logout-событий). */
    public static function clientsOfSession(int $sessionId): array
    {
        return DB::all(
            'SELECT sc.*, c.name, c.backchannel_uri, c.client_id AS oauth_client_id
             FROM {session_clients} sc JOIN {clients} c ON c.id = sc.client_id
             WHERE sc.session_id = ? AND sc.logged_out_at IS NULL', [$sessionId]);
    }

    public static function markLoggedOut(int $sessionClientRowId): void
    {
        DB::update('session_clients', ['logged_out_at' => now()], 'id = ?', [$sessionClientRowId]);
    }
}
