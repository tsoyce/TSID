<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Очередь событий SSO → портал (§2.4). Ретраи: 1м, 5м, 30м, 6ч → failed. */
final class ClientEvent
{
    public const RETRY_STEPS = [60, 300, 1800, 21600];

    public static function enqueue(int $clientDbId, string $event, array $payload): int
    {
        return DB::insert('client_events', [
            'client_id' => $clientDbId,
            'event' => $event,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending',
            'next_attempt_at' => now(),
            'created_at' => now(),
        ]);
    }

    public static function due(int $limit = 50): array
    {
        return DB::all(
            'SELECT e.*, c.backchannel_uri, c.client_id AS oauth_client_id, c.name AS client_name
             FROM {client_events} e JOIN {clients} c ON c.id = e.client_id
             WHERE e.status = ? AND e.next_attempt_at <= ?
             ORDER BY e.id LIMIT ' . (int)$limit, ['pending', now()]);
    }

    public static function markSent(int $id, int $attempts): void
    {
        DB::update('client_events', [
            'status' => 'sent', 'sent_at' => now(), 'attempts' => $attempts, 'last_error' => null,
        ], 'id = ?', [$id]);
    }

    public static function markFailedAttempt(int $id, int $attempts, string $error): void
    {
        if ($attempts > count(self::RETRY_STEPS)) {
            DB::update('client_events', [
                'status' => 'failed', 'attempts' => $attempts, 'last_error' => mb_substr($error, 0, 255),
            ], 'id = ?', [$id]);
            return;
        }
        $delay = self::RETRY_STEPS[$attempts - 1] ?? 21600;
        DB::update('client_events', [
            'attempts' => $attempts,
            'next_attempt_at' => now_plus($delay),
            'last_error' => mb_substr($error, 0, 255),
        ], 'id = ?', [$id]);
    }

    /** Последние события клиента — для карточки портала в админке. */
    public static function recentForClient(int $clientDbId, int $limit = 20): array
    {
        return DB::all('SELECT * FROM {client_events} WHERE client_id = ? ORDER BY id DESC LIMIT ' . (int)$limit,
            [$clientDbId]);
    }

    public static function retryNow(int $id): void
    {
        DB::update('client_events', ['status' => 'pending', 'next_attempt_at' => now()], 'id = ?', [$id]);
    }
}
