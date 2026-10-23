<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Webhook;

/**
 * Доставка webhooks: JSON + HMAC-подпись, ретраи теми же шагами, что и
 * backchannel-события (1м → 5м → 30м → 6ч). Получатель проверяет подпись так:
 *   hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret) === $signature
 */
final class WebhookDispatcher
{
    private const RETRY_STEPS = [60, 300, 1800, 21600];

    /** Разослать событие всем подписчикам. Ничего не делает, если их нет. */
    public static function emit(string $event, array $payload): void
    {
        try {
            $subs = Webhook::subscribers($event);
            foreach ($subs as $w) {
                $id = DB::insert('webhook_deliveries', [
                    'webhook_id' => (int)$w['id'],
                    'event' => $event,
                    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status' => 'pending',
                    'next_attempt_at' => now(),
                    'created_at' => now(),
                ]);
                /* За ответом: это HTTP-запрос к чужому серверу, и на входе он
                   стоял между человеком и его порталом. */
                Defer::after(static fn() => self::attempt($id));
            }
        } catch (\Throwable $e) {
            // Вебхуки — вспомогательный канал: их сбой не должен ломать основное действие
            Log::warning('webhook_emit_failed', ['event' => $event, 'err' => $e->getMessage()]);
        }
    }

    /** Одна попытка доставки. Возвращает true при успехе. */
    public static function attempt(int $deliveryId): bool
    {
        $row = DB::row('SELECT d.*, w.url, w.secret, w.is_active FROM {webhook_deliveries} d
             JOIN {webhooks} w ON w.id = d.webhook_id WHERE d.id = ?', [$deliveryId]);
        if (!$row || $row['status'] !== 'pending') return false;
        if (!(int)$row['is_active']) {
            DB::update('webhook_deliveries', ['status' => 'failed', 'last_error' => 'вебхук выключен'],
                'id = ?', [$deliveryId]);
            return false;
        }

        $attempts = (int)$row['attempts'] + 1;
        // v4.97.2: доставку берёт один — тот, чей UPDATE сдвинул счётчик (см. Mailer::attempt)
        if (DB::run('UPDATE {webhook_deliveries} SET attempts = ? WHERE id = ? AND attempts = ? AND status = ?',
                [$attempts, $deliveryId, (int)$row['attempts'], 'pending'])->rowCount() !== 1) return false;
        $body = json_encode([
            'event' => $row['event'],
            'created_at' => gmdate('c', strtotime($row['created_at'] . ' UTC')),
            'issuer' => base_url(),
            'data' => json_decode((string)$row['payload'], true),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        [$ok, $code, $err] = self::post((string)$row['url'], (string)$body, (string)$row['secret'], (string)$row['event']);

        if ($ok) {
            DB::update('webhook_deliveries', [
                'status' => 'sent', 'attempts' => $attempts, 'response_code' => $code,
                'sent_at' => now(), 'last_error' => null,
            ], 'id = ?', [$deliveryId]);
            DB::update('webhooks', ['last_status' => 'ok', 'last_sent_at' => now()], 'id = ?', [(int)$row['webhook_id']]);
            return true;
        }

        $final = $attempts > count(self::RETRY_STEPS);
        DB::update('webhook_deliveries', [
            'status' => $final ? 'failed' : 'pending',
            'attempts' => $attempts,
            'response_code' => $code ?: null,
            'next_attempt_at' => $final ? null : now_plus(self::RETRY_STEPS[$attempts - 1] ?? 21600),
            'last_error' => mb_substr($err, 0, 255),
        ], 'id = ?', [$deliveryId]);
        DB::update('webhooks', ['last_status' => 'ошибка: ' . mb_substr($err, 0, 24), 'last_sent_at' => now()],
            'id = ?', [(int)$row['webhook_id']]);
        return false;
    }

    /** Созревшие доставки — для cron. */
    public static function due(int $limit = 30): array
    {
        return DB::all('SELECT id FROM {webhook_deliveries} WHERE status = ? AND next_attempt_at <= ?
             ORDER BY id LIMIT ' . (int)$limit, ['pending', now()]);
    }

    /** @return array{0:bool,1:int,2:string} */
    private static function post(string $url, string $body, string $secret, string $event): array
    {
        $ts = (string)time();
        $signature = hash_hmac('sha256', $ts . '.' . $body, $secret);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            /* v4.97.2: только http(s) — адрес задаёт админ портала, а libcurl
               умеет gopher://, dict://, file://: нагрузка во внутреннюю сеть. */
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'User-Agent: ' . Setting::appName() . '-webhook/1.0',
                /* X-SSO-* с 4.89; прежние X-TSOY-* — рядом, до 5.0. */
                'X-SSO-Event: ' . $event,
                'X-SSO-Timestamp: ' . $ts,
                'X-SSO-Signature: sha256=' . $signature,
                'X-TSOY-Event: ' . $event,
                'X-TSOY-Timestamp: ' . $ts,
                'X-TSOY-Signature: sha256=' . $signature,
            ],
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code >= 200 && $code < 300) return [true, $code, ''];
        return [false, $code, $code > 0 ? "HTTP $code" : ($err ?: 'нет ответа')];
    }

    /** Пробное событие из админки — сразу, без очереди. */
    public static function test(array $webhook): array
    {
        $body = json_encode([
            'event' => 'ping',
            'created_at' => gmdate('c'),
            'issuer' => base_url(),
            'data' => ['message' => 'Проверка связи из ' . Setting::appName()],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        [$ok, $code, $err] = self::post((string)$webhook['url'], (string)$body, (string)$webhook['secret'], 'ping');
        return ['ok' => $ok, 'code' => $code, 'error' => $err];
    }
}
