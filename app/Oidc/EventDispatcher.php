<?php

declare(strict_types=1);

namespace App\Oidc;

use App\Core\Log;
use App\Models\Access;
use App\Models\ClientEvent;
use App\Models\SessionModel;

/**
 * Канал событий SSO → портал (§2.4): event-token (JWT RS256) POST'ом на
 * backchannel_uri. Немедленная попытка + ретраи кроном (1м, 5м, 30м, 6ч).
 */
final class EventDispatcher
{
    /** Поставить в очередь + сразу попытаться доставить (короткий таймаут). */
    public static function queue(array $client, string $event, array $payload): void
    {
        if (empty($client['backchannel_uri'])) return;
        $id = ClientEvent::enqueue((int)$client['id'], $event, $payload);
        try {
            self::attempt($id);
        } catch (\Throwable $e) {
            Log::warning('event_immediate_failed', ['event_id' => $id, 'err' => $e->getMessage()]);
        }
    }

    /** Одна попытка доставки события из очереди. */
    public static function attempt(int $eventId): bool
    {
        $row = \App\Core\DB::row(
            'SELECT e.*, c.backchannel_uri, c.client_id AS oauth_client_id, c.manifest_fetched_at
             FROM {client_events} e JOIN {clients} c ON c.id = e.client_id WHERE e.id = ?', [$eventId]);
        if (!$row) {
            // Клиент удалён — событие некому доставлять; иначе крон будет дёргать его вечно
            $orphan = \App\Core\DB::row('SELECT id, attempts FROM {client_events} WHERE id = ? AND status = ?', [$eventId, 'pending']);
            if ($orphan) ClientEvent::markFailedAttempt($eventId, 99, 'клиент удалён — событие осиротело');
            return false;
        }
        if ($row['status'] !== 'pending') return false;
        $attempts = (int)$row['attempts'] + 1;
        if (empty($row['backchannel_uri'])) {
            ClientEvent::markFailedAttempt($eventId, 99, 'backchannel_uri не задан');
            return false;
        }
        // v5.4: событие отправляет один — тот, чей UPDATE сдвинул счётчик (см. Mailer::attempt)
        if (\App\Core\DB::run('UPDATE {client_events} SET attempts = ? WHERE id = ? AND attempts = ? AND status = ?',
                [$attempts, $eventId, (int)$row['attempts'], 'pending'])->rowCount() !== 1) return false;
        $payload = json_decode((string)$row['payload'], true) ?: [];
        $token = IdToken::issueEvent($row, (string)$row['event'], $payload);
        [$ok, $err] = self::post((string)$row['backchannel_uri'], $token);
        if ($ok) {
            ClientEvent::markSent($eventId, $attempts);
            return true;
        }
        ClientEvent::markFailedAttempt($eventId, $attempts, $err);
        return false;
    }

    /** POST event_token; портал отвечает 200 — и точка (§2.4). */
    private static function post(string $uri, string $token): array
    {
        $ch = curl_init($uri);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['event_token' => $token, 'logout_token' => $token]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false, // редиректов в back-channel нет
            /* v5.4: только http(s) — адрес задаёт админ портала, а libcurl
               умеет gopher://, dict://, file://: нагрузка во внутреннюю сеть. */
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code >= 200 && $code < 300) return [true, ''];
        return [false, $code > 0 ? "HTTP $code" : ($err ?: 'нет ответа')];
    }

    /* ---- Сценарии ---- */

    /** «Выйти везде»: logout всем порталам этой сессии. */
    public static function sessionLogout(int $sessionId): void
    {
        $user = \App\Core\DB::row(
            'SELECT u.public_id FROM {sessions} s JOIN {users} u ON u.id = s.user_id WHERE s.id = ?', [$sessionId]);
        foreach (SessionModel::clientsOfSession($sessionId) as $sc) {
            // ВАЖНО: $sc['id'] — id строки session_clients; id клиента — $sc['client_id']
            self::queue(
                ['id' => (int)$sc['client_id'], 'backchannel_uri' => $sc['backchannel_uri']],
                'logout',
                ['sub' => $user['public_id'] ?? '', 'sid' => $sc['sid']]
            );
            SessionModel::markLoggedOut((int)$sc['id']);
        }
    }

    /** user.blocked / user.deleted / user.updated — всем порталам пользователя. */
    public static function userEvent(array $user, string $event, array $extra = []): void
    {
        foreach (Access::forUser((int)$user['id']) as $access) {
            $client = \App\Models\Client::find((int)$access['client_id']);
            if ($client) self::queue($client, $event, ['sub' => $user['public_id']] + $extra);
        }
    }

    /** Сколько дней удаление можно отменить. Потом — обезличивание (CronTasks). */
    public const DELETE_HOLD_DAYS = 14;

    /**
     * Человек удалён — и порталу сказано, в каком смысле (v4.62).
     *
     * Раньше событие было одно на четыре разных случая, и портал не мог
     * отличить «человек может вернуться» от «стереть всё». Правильного
     * поведения у него не было ни в одном: Архив, например, на любой
     * user.deleted только гасил сессии и оставлял человека у себя живым.
     *
     *   scheduled — помечен, отменить можно до restore_until;
     *   final     — срок вышел, аккаунт обезличен, человек не вернётся;
     *   purged    — администратор стёр аккаунт сразу и насовсем;
     *   merged    — слит с другим аккаунтом, merged_into — его sub.
     */
    public static function userDeleted(array $user, string $mode, array $extra = []): void
    {
        $payload = ['mode' => $mode] + $extra;
        if ($mode === 'scheduled') {
            $from = strtotime(((string)($user['deleted_at'] ?? '') ?: now()) . ' UTC') ?: time();
            $payload['restore_until'] = gmdate('c', $from + self::DELETE_HOLD_DAYS * 86400);
        }
        self::userEvent($user, 'user.deleted', $payload);
    }

    /**
     * Удаление отменено — человек вернулся (v4.62).
     *
     * Без этого события портал, честно отключивший человека по scheduled, так и
     * держал бы его отключённым: об отмене он не узнавал никак.
     */
    public static function userRestored(array $user): void
    {
        self::userEvent($user, 'user.restored');
    }

    /**
     * access.revoked — конкретному порталу (§9.1), и сказано, что сделать (v4.64):
     *   closed  — «Закрыть»: учётную запись в архив, вернуть можно (придёт access.granted);
     *   removed — «Убрать»: стереть учётную запись и её данные в сервисе.
     *
     * Решение владельца 12.09: две кнопки — два исхода. До 4.64 событие было одно
     * и без подробностей, и Архив на «Закрыть» и «Убрать» только гасил
     * сессии: человек оставался у него живым.
     */
    public static function accessRevoked(array $user, array $client, string $mode = 'closed'): void
    {
        self::queue($client, 'access.revoked', ['sub' => $user['public_id'], 'mode' => $mode]);
    }

    /** access.granted — доступ открыли снова: сервис возвращает закрытого из архива (v4.64). */
    public static function accessGranted(array $user, array $client): void
    {
        self::queue($client, 'access.granted', ['sub' => $user['public_id']]);
    }
}
