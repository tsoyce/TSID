<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Migrations;

/**
 * Карта соответствия ролей: как роль SSO называется на стороне портала (v4.0).
 *
 * Задача своими словами: SSO и сервис X должны договориться, кто есть кто. До
 * v4.0 портал получал claim `roles` со слагами SSO и разбирался сам — то есть
 * договорённость жила в чужой голове и в переписке.
 *
 * Два правила, ради которых всё это устроено именно так:
 *
 *  · **Роль без соответствия порталу не передаётся.** Не «передаётся как есть»,
 *    а не передаётся: набор ролей — это внутреннее устройство инсталляции, и
 *    порталу знать о ролях, которые его не касаются, незачем.
 *  · **Пустая карта ничего не ломает.** Если карту не заводили, портал получает
 *    слаги как раньше. Иначе включение новой возможности молча обрезало бы
 *    доступы у всех, кто её ещё не настроил.
 */
final class ClientRoleMap
{
    public static function ready(): bool
    {
        return Migrations::ready('client_role_map');
    }

    /**
     * Карта портала: слаг роли SSO → внешнее название.
     * @return array<string, string>
     */
    public static function forClient(int $clientId): array
    {
        if (!self::ready()) return [];
        /* Только общие роли SSO (v4.69). Собственные роли портала — его же
           словарь: они уходят ему как есть, мимо карты. Пока они стояли в карте,
           «admin» портала и общий «admin» делили один ключ — в форме это были
           два поля map[admin], и второе при сохранении затирало первое. */
        $rows = DB::all('SELECT r.slug, m.external_name FROM {client_role_map} m
            JOIN {roles} r ON r.id = m.role_id WHERE m.client_id = ? AND r.client_id IS NULL', [$clientId]);
        return array_column($rows, 'external_name', 'slug');
    }

    /**
     * Переписать карту целиком.
     *
     * @param array<string, string> $map слаг роли SSO → внешнее название
     * @return array{saved: int, unknown: list<string>} что записали и какие
     *         слаги не нашлись — импорт из чужой инсталляции обязан сообщать,
     *         что он пропустил, а не молча терять строки
     */
    public static function replace(int $clientId, array $map): array
    {
        if (!self::ready()) return ['saved' => 0, 'unknown' => []];

        /* Только общие роли SSO (v4.69): свои роли портала уходят ему без
           карты, а чужие клиентские попадать в неё не должны тем более. */
        $roles = DB::all('SELECT id, slug FROM {roles} WHERE client_id IS NULL');
        $byslug = array_column($roles, 'id', 'slug');

        $unknown = [];
        $saved = 0;
        DB::run('DELETE FROM {client_role_map} WHERE client_id = ?', [$clientId]);
        foreach ($map as $slug => $external) {
            $slug = trim((string)$slug);
            $external = trim((string)$external);
            /* Пустое значение — это «не передавать», а не ошибка: именно так
               роль исключают из карты, не удаляя её из системы. */
            if ($external === '') continue;
            if (!isset($byslug[$slug])) { $unknown[] = $slug; continue; }
            DB::insert('client_role_map', [
                'client_id' => $clientId,
                'role_id' => (int)$byslug[$slug],
                'external_name' => mb_substr($external, 0, 100),
                'created_at' => now(),
            ]);
            $saved++;
        }
        Audit::log('client.role_map', ['on' => null,
            'meta' => ['client' => $clientId, 'saved' => $saved, 'unknown' => $unknown]]);
        return ['saved' => $saved, 'unknown' => $unknown];
    }

    /**
     * Применить карту к списку слагов для claim.
     *
     * Пустая карта означает «не настраивали» — отдаём как есть. Настроенная
     * карта фильтрует: то, чего в ней нет, порталу не достаётся.
     *
     * @param list<string> $slugs
     * @return list<string>
     */
    public static function apply(int $clientId, array $slugs): array
    {
        $map = self::forClient($clientId);
        if ($map === []) return $slugs;

        /* Роли, которые определил сам портал, уходят ему голой ролью, мимо
           карты: это его собственные имена (v4.69). Приставку «слаг-портала:»
           получают и общие роли, выданные в его области («Админ портала»,
           роли организации), — те идут через карту, как и прежде: по одной
           приставке их не отличить, поэтому сверяемся со списком ролей портала. */
        $own = (string)(Client::find($clientId)['slug'] ?? '') . ':';
        $ownSlugs = array_column(Role::forClient($clientId), 'slug');
        $out = [];
        foreach ($slugs as $slug) {
            if ($own !== ':' && str_starts_with($slug, $own)) {
                $bare = substr($slug, strlen($own));
                if (in_array($bare, $ownSlugs, true)) {
                    $out[] = $bare;
                    continue;
                }
            }
            /* Клиентские роли приезжают как «портал:роль» — сопоставляем по
               самой роли, префикс к договорённости отношения не имеет. */
            $bare = str_contains($slug, ':') ? substr($slug, strrpos($slug, ':') + 1) : $slug;
            if (isset($map[$bare])) $out[] = $map[$bare];
        }
        return array_values(array_unique($out));
    }

    /**
     * Предложить сопоставление по тому, что портал о себе рассказал.
     *
     * Именно предложить: результат уходит в подсказку поля, а не в значение.
     * Карта управляет доступами, и заполнять её автоматически по содержимому
     * чужого файла — значит отдать управление правами наружу.
     *
     * Сходство ищется грубо и намеренно: слаги приводятся к буквам и цифрам,
     * дальше точное совпадение либо вхождение одного в другой. Умнее не надо —
     * ошибочная подсказка хуже отсутствующей, человек её не заметит и подтвердит.
     *
     * @return array<string, string> слаг роли SSO → предложенное имя у портала
     */
    public static function suggest(int $clientId, array $ssoRoles): array
    {
        $seen = \App\Core\PortalManifest::rolesSeen($clientId);
        if ($seen === []) return [];

        $norm = static fn(string $s): string
            => (string)preg_replace('~[^a-z0-9]~', '', mb_strtolower($s));

        $out = [];
        foreach ($ssoRoles as $role) {
            $a = $norm((string)$role['slug']);
            if ($a === '') continue;
            foreach ($seen as $p) {
                $b = $norm((string)$p['slug']);
                if ($b === '') continue;
                if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
                    $out[$role['slug']] = (string)$p['slug'];
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Карта в JSON для переноса. Формат плоский и читаемый человеком: его
     * открывают глазами и правят руками чаще, чем обрабатывают программой.
     */
    public static function toJson(array $client): string
    {
        return (string)json_encode([
            'client' => $client['slug'] ?? $client['client_id'],
            'roles' => self::forClient((int)$client['id']),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Разобрать привезённый JSON. Возвращает карту или null, если это не она —
     * человек вполне может загрузить не тот файл, и падать с ошибкой разбора
     * посреди импорта не годится.
     *
     * @return array<string, string>|null
     */
    public static function fromJson(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['roles']) || !is_array($data['roles'])) return null;
        $map = [];
        foreach ($data['roles'] as $slug => $external) {
            if (!is_string($slug) || !is_string($external)) continue;
            $map[$slug] = $external;
        }
        return $map;
    }
}
