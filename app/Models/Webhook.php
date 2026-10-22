<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\DB;

/**
 * Webhooks для сторонних систем (CRM, аналитика, боты).
 * Отличие от backchannel-канала §2.4: тот говорит на языке OIDC конкретному
 * порталу, этот — простой JSON с HMAC-подписью кому угодно.
 */
final class Webhook
{
    /** Коды событий: их знает подписчик, они не переводятся. */
    public const EVENT_CODES = [
        'user.created', 'user.blocked', 'user.deleted', 'login.success',
        'login.failed', 'access.granted', 'access.revoked', 'client.created',
        'client.installed',
    ];

    /**
     * Каталог событий: что можно подписать.
     *
     * Метод, а не const: подписи переводятся, а `const` не допускает вызова
     * функции. Коды при этом остаются константой — по ним работают подписчики,
     * и меняться от языка админки они не должны.
     *
     * @return array<string, string> код → подпись на языке интерфейса
     */
    public static function events(): array
    {
        return [
            'user.created' => t('Регистрация пользователя'),
            'user.blocked' => t('Пользователь заблокирован'),
            'user.deleted' => t('Пользователь удалён'),
            'login.success' => t('Успешный вход'),
            'login.failed' => t('Неудачная попытка входа'),
            'access.granted' => t('Выдан доступ к порталу'),
            'access.revoked' => t('Отозван доступ к порталу'),
            'client.created' => t('Подключён новый портал'),
            'client.installed' => t('Портал забрал реквизиты по ссылке установки'),
            /* Отправлялось с v4.2, но подписаться было нельзя: в каталоге его
               не было, а он рисует галочки. То есть событие уходило в пустоту
               — ровно та половина расхождения, которую не видно со стороны
               кода: `emit()` на месте, всё «работает». */
            'issuer.changed' => t('Сменился адрес установки'),
        ];
    }

    /** Схема готова? Webhooks появились в миграции 004. */
    public static function ready(): bool
    {
        return \App\Core\Migrations::ready('webhooks', 'webhook_deliveries');
    }

    public static function all(): array
    {
        if (!self::ready()) return [];
        return array_map([self::class, 'decode'], DB::all('SELECT * FROM {webhooks} ORDER BY id DESC'));
    }

    public static function find(int $id): ?array
    {
        return self::decode(DB::row('SELECT * FROM {webhooks} WHERE id = ?', [$id]));
    }

    /** Активные подписчики конкретного события. */
    public static function subscribers(string $event): array
    {
        $out = [];
        foreach (self::all() as $w) {
            if (!(int)$w['is_active']) continue;
            if (in_array($event, $w['events'], true)) $out[] = $w;
        }
        return $out;
    }

    private static function decode(?array $w): ?array
    {
        if ($w === null) return null;
        $w['events'] = is_string($w['events']) ? (json_decode($w['events'], true) ?: []) : (array)$w['events'];
        return $w;
    }

    /** Создать. Секрет возвращается один раз — он нужен для проверки подписи. */
    public static function create(array $data, ?int $createdBy): array
    {
        $secret = 'whsec_' . Crypto::randomToken(24);
        $events = array_values(array_intersect($data['events'] ?? [], self::EVENT_CODES));
        $id = DB::insert('webhooks', [
            'name' => mb_substr(trim((string)$data['name']), 0, 100),
            'url' => trim((string)$data['url']),
            'secret' => $secret,
            'events' => json_encode($events, JSON_UNESCAPED_SLASHES),
            'is_active' => 1,
            'created_by' => $createdBy,
            'created_at' => now(),
        ]);
        return [$id, $secret];
    }

    public static function update(int $id, array $data): void
    {
        if (isset($data['events'])) {
            $data['events'] = json_encode(
                array_values(array_intersect($data['events'], self::EVENT_CODES)), JSON_UNESCAPED_SLASHES);
        }
        DB::update('webhooks', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        DB::run('DELETE FROM {webhooks} WHERE id = ?', [$id]);
    }

    public static function recentDeliveries(int $webhookId, int $limit = 15): array
    {
        return DB::all('SELECT * FROM {webhook_deliveries} WHERE webhook_id = ? ORDER BY id DESC LIMIT ' . (int)$limit,
            [$webhookId]);
    }
}
