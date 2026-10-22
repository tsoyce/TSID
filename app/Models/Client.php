<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\DB;

/** Клиенты (порталы). Секрет — только argon2id-хэш; показывается один раз. */
final class Client
{
    public static function find(int $id): ?array
    {
        return self::decode(DB::row('SELECT * FROM {clients} WHERE id = ?', [$id]));
    }

    public static function findByClientId(string $clientId): ?array
    {
        return self::decode(DB::row('SELECT * FROM {clients} WHERE client_id = ?', [$clientId]));
    }

    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM {clients}' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name';
        return array_map([self::class, 'decode'], DB::all($sql));
    }

    /**
     * Порталы для гостевой витрины.
     *
     * Только те, что владелец отметил сам: перечень внутренних сервисов —
     * информация об устройстве организации, и отдавать её любому, кто набрал
     * адрес, нельзя. Выключенные порталы не показываем даже отмеченные — вести
     * человека к неработающему сервису хуже, чем не вести никуда.
     *
     * @return list<array<string, mixed>>
     */
    public static function publicOnes(): array
    {
        if (!\App\Core\Migrations::ready('clients')) return [];
        try {
            $rows = DB::all('SELECT * FROM {clients} WHERE show_on_home = 1 AND is_active = 1
                             ORDER BY sort_order, name');
        } catch (\Throwable) {
            /* Колонки ещё нет — код обновили, миграцию не применили. Витрина
               просто не показывается: страница входа работает и без неё. */
            return [];
        }
        return array_map([self::class, 'decode'], $rows);
    }

    /** JSON-колонки → массивы. */
    private static function decode(?array $c): ?array
    {
        if ($c === null) return null;
        foreach (['redirect_uris', 'post_logout_uris', 'allowed_scopes'] as $col) {
            $c[$col] = is_string($c[$col]) ? (json_decode($c[$col], true) ?: []) : (array)$c[$col];
        }
        return $c;
    }

    /**
     * Поля портала, которые меняются снаружи — и из карточки, и по API.
     *
     * Заведено после прогона по живой установке: суперадминский токен не мог
     * включить порталу `same_site_family`, то есть не мог настроить молчаливое
     * возвращение (`prompt=none`) — то самое, ради чего SSO и заводят. Карточка
     * умела, API — нет, и разошлись они молча, потому что списка полей не было
     * вовсе: каждый писал свой набор ключей руками.
     *
     * Тип задаёт разбор, а не только проверку: `list` приедет массивом, `bool` —
     * нулём или единицей, `str?` — пустая строка станет NULL.
     *
     * Читается по этой же карте (ApiController::clientDto): поле, которое можно
     * записать, обязано и показываться. Иначе снаружи не видно, что именно
     * записалось.
     */
    public const EDITABLE = [
        'name' => 'str',
        'slug' => 'str',
        'description' => 'str?',
        'base_url' => 'str',
        'redirect_uris' => 'list',
        'post_logout_uris' => 'list',
        'allowed_scopes' => 'list',
        'backchannel_uri' => 'str?',
        'access_policy' => 'enum:open,approval,invite',
        'is_active' => 'bool',
        'is_trusted' => 'bool',
        'require_2fa' => 'bool',
        'same_site_family' => 'bool',
        'show_on_home' => 'bool',
        'org_id' => 'int?',
    ];

    /**
     * «Кого пускать» словами — одно место на выбор в реквизитах и метку в
     * списке порталов (v4.70). Список писал машинное «open» на каждой
     * карточке, а выбор — фразы по восемьдесят знаков, которые обрезались в
     * узкой колонке на полуслове: «Любого, у кого есть аккаунт (».
     */
    public static function policyLabel(string $policy): string
    {
        return match ($policy) {
            'open' => t('Любой с аккаунтом'),
            'approval' => t('По заявке'),
            'invite' => t('По приглашению'),
            default => $policy,
        };
    }

    /**
     * Что правится у портала, но полем не является.
     *
     * `icon_path` — не значение, а результат загрузки файла: принимать его
     * строкой значило бы разрешить токену указать на любой путь на диске.
     */
    public const NOT_A_FIELD = ['icon_path'];

    /**
     * Разобрать присланное по карте. Чего нет в теле — того нет и в ответе:
     * PATCH меняет названное, а не переписывает портал целиком.
     *
     * @return array<string,mixed>
     */
    public static function patchFrom(array $body): array
    {
        $out = [];
        foreach (self::EDITABLE as $field => $type) {
            if (!array_key_exists($field, $body)) continue;
            $v = $body[$field];
            if (str_starts_with($type, 'enum:')) {
                if (in_array($v, explode(',', substr($type, 5)), true)) $out[$field] = $v;
                continue;
            }
            $out[$field] = match ($type) {
                'list' => array_values(array_filter(
                    array_map(static fn ($x): string => trim((string)$x), (array)$v),
                    static fn (string $x): bool => $x !== '')),
                'bool' => (int)(bool)$v,
                'int?' => $v === null || $v === '' ? null : (int)$v,
                'str?' => trim((string)$v) === '' ? null : trim((string)$v),
                default => trim((string)$v),
            };
        }
        /* Колонки может не быть: на установке с неприменённой миграцией 020
           `org_id` отсутствует, и безусловная запись ломала бы портал целиком —
           тот же случай, что поймала проверка отставшей схемы. */
        if (array_key_exists('org_id', $out) && !DB::columnExists('clients', 'org_id')) {
            unset($out['org_id']);
        }
        return $out;
    }

    /** Создание из мастера §6. Возвращает [id, client_id, client_secret(plaintext)]. */
    public static function create(array $data): array
    {
        $clientId = 'cli_' . Crypto::randomToken(12);
        $secret = Crypto::randomToken(48);
        $row = [
            'client_id' => $clientId,
            'client_secret_hash' => password_hash($secret, PASSWORD_ARGON2ID),
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'base_url' => rtrim($data['base_url'], '/'),
            'redirect_uris' => json_encode(array_values($data['redirect_uris']), JSON_UNESCAPED_SLASHES),
            'post_logout_uris' => json_encode(array_values($data['post_logout_uris']), JSON_UNESCAPED_SLASHES),
            'backchannel_uri' => $data['backchannel_uri'] ?: null,
            'allowed_scopes' => json_encode($data['allowed_scopes'] ?? ['openid', 'profile', 'email', 'roles'], JSON_UNESCAPED_SLASHES),
            'is_trusted' => (int)($data['is_trusted'] ?? 1),
            'same_site_family' => (int)($data['same_site_family'] ?? 0),
            'access_policy' => $data['access_policy'] ?? 'open',
            'require_2fa' => (int)($data['require_2fa'] ?? 0),
            'sort_order' => (int)(DB::val('SELECT COALESCE(MAX(sort_order),0)+1 FROM {clients}') ?? 1),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        /* Владелец сразу: сервис, заведённый из организации, ей и принадлежит.
           Колонку спрашиваем, а не считаем данностью: на установке с
           неприменённой миграцией 020 её нет, и безусловная запись ломала
           подключение сервиса целиком — поймано проверкой отставшей схемы. */
        if (!empty($data['org_id']) && DB::columnExists('clients', 'org_id')) {
            $row['org_id'] = (int)$data['org_id'];
        }
        $id = DB::insert('clients', $row);
        return [$id, $clientId, $secret];
    }

    public static function update(int $id, array $data): void
    {
        foreach (['redirect_uris', 'post_logout_uris', 'allowed_scopes'] as $col) {
            if (isset($data[$col]) && is_array($data[$col])) {
                $data[$col] = json_encode(array_values($data[$col]), JSON_UNESCAPED_SLASHES);
            }
        }
        $data['updated_at'] = now();
        DB::update('clients', $data, 'id = ?', [$id]);
    }

    /** Перевыпуск секрета: старый живёт 24 ч (§5.2). Возвращает новый секрет. */
    public static function rotateSecret(int $id): string
    {
        $client = self::find($id);
        $secret = Crypto::randomToken(48);
        self::update($id, [
            'client_secret_hash' => password_hash($secret, PASSWORD_ARGON2ID),
            'client_secret_old_hash' => $client['client_secret_hash'],
            'client_secret_old_expires_at' => now_plus(24 * 3600),
        ]);
        return $secret;
    }

    /** Проверка секрета: актуальный или ещё живой старый. */
    public static function verifySecret(array $client, string $secret): bool
    {
        if ($secret === '') return false;
        if (password_verify($secret, (string)$client['client_secret_hash'])) return true;
        if ($client['client_secret_old_hash'] !== null
            && $client['client_secret_old_expires_at'] !== null
            && strtotime($client['client_secret_old_expires_at'] . ' UTC') > time()
            && password_verify($secret, (string)$client['client_secret_old_hash'])) {
            return true;
        }
        return false;
    }

    /** Байт-в-байт проверка redirect_uri (§3.2). */
    public static function redirectUriAllowed(array $client, string $uri): bool
    {
        foreach ($client['redirect_uris'] as $allowed) {
            if (hash_equals($allowed, $uri)) return true;
        }
        return false;
    }

    /**
     * Портал, у которого ровно этот адрес возврата после выхода, — если он такой
     * один. Подсказка id_token_hint до SSO доходит не всегда, а зарегистрированный
     * адрес сам говорит, чей он.
     */
    public static function byPostLogoutUri(string $uri): ?array
    {
        $hits = array_values(array_filter(self::all(true),
            static fn(array $c): bool => self::postLogoutUriAllowed($c, $uri)));
        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * Портал, у которого ровно этот origin в base_url, — если он такой один.
     *
     * Последний способ узнать портал на выходе: адрес незарегистрирован (у
     * Архива — без завершающего слэша), подсказки нет, но origin
     * однозначно указывает на сервис. Сам незарегистрированный адрес при этом
     * не исполняется — только узнаётся, чей он.
     */
    public static function byOrigin(string $uri): ?array
    {
        $origin = \App\Core\Csp::origin($uri);
        if ($origin === null) return null;
        $hits = array_values(array_filter(self::all(true),
            static fn(array $c): bool => \App\Core\Csp::origin((string)$c['base_url']) === $origin));
        return count($hits) === 1 ? $hits[0] : null;
    }

    public static function postLogoutUriAllowed(array $client, string $uri): bool
    {
        foreach ($client['post_logout_uris'] as $allowed) {
            if (hash_equals($allowed, $uri)) return true;
        }
        return false;
    }

    public static function slugTaken(string $slug, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM {clients} WHERE slug = ?';
        $params = [$slug];
        if ($exceptId !== null) { $sql .= ' AND id != ?'; $params[] = $exceptId; }
        return DB::row($sql, $params) !== null;
    }

    /**
     * Удалить портал со всем, что к нему привязано.
     *
     * Каскады в схеме есть, но полагаться только на них нельзя: у SQLite они
     * работают лишь при включённом `PRAGMA foreign_keys`, а часть таблиц
     * (refresh_tokens, auth_codes, client_events) ссылается на портал без
     * внешнего ключа вовсе. Оставленные строки — это выданные токены, которые
     * никто уже не отзовёт, и роль client_admin, которая продолжает висеть
     * на людях.
     *
     * @return array<string,int> что и сколько удалено
     */
    public static function purge(int $clientDbId): array
    {
        /* Одним куском (v4.45): порядок здесь и так выверен — роли снимаются с
           людей раньше самих ролей, — но выверенный порядок спасает только от
           неверной последовательности, а не от обрыва посреди неё. Половина
           снятых ролей и живой портал — состояние, из которого нет выхода:
           повторное удаление уже не найдёт того, что снесли в первый раз.
           Роли портала снимаем с людей раньше самих ролей: иначе останутся
           user_roles, указывающие в пустоту. */
        $counts = DB::tx(static function () use ($clientDbId): array {
        $counts = [];
        $roleIds = array_column(
            DB::all('SELECT id FROM {roles} WHERE client_id = ?', [$clientDbId]), 'id');
        foreach ($roleIds as $rid) {
            $part = DB::sweep(['user_roles'], 'role_id', (int)$rid);
            $counts['user_roles'] = ($counts['user_roles'] ?? 0) + ($part['user_roles'] ?? 0);
        }
        /* Выдачи с областью этого портала — отдельно от ролей: с v4.24 роль
           «Админ портала» системная и остаётся жить, а вот выданное на снесённый
           портал держать нельзя. Иначе человек навсегда админ того, чего нет:
           область указывает в пустоту, а строка считается действующей. */
        $scoped = DB::run('DELETE FROM {user_roles} WHERE scope_type = ? AND scope_id = ?',
            [\App\Core\Scope::CLIENT, $clientDbId])->rowCount();
        $counts['user_roles'] = ($counts['user_roles'] ?? 0) + $scoped;
        if (($counts['user_roles'] ?? 0) === 0) unset($counts['user_roles']);

        $counts += DB::sweep([
            'refresh_tokens', 'auth_codes', 'client_events', 'session_clients',
            'user_client_access', 'org_client_access', 'invites',
            'client_role_map', 'client_roles_seen', 'roles',
        ], 'client_id', $clientDbId);

        DB::run('DELETE FROM {clients} WHERE id = ?', [$clientDbId]);
        return $counts;
        });
        /* Картинки портала — после записи в базу, а не до: откатившаяся
           транзакция не должна оставить живой портал без лица (v4.71). До этого
           иконка, логотип из манифеста и обложка оставались на диске навсегда. */
        $dir = SSO_ROOT . '/storage/avatars/clients/';
        foreach ([$clientDbId . '.webp', 'brand-' . $clientDbId . '.webp', 'cover-' . $clientDbId . '.webp'] as $f) {
            if (is_file($dir . $f)) @unlink($dir . $f);
        }
        /* Обложка на витрине (v4.76) и запись о ней — туда же. */
        \App\Core\PortalCover::remove($clientDbId);
        /* И обложка панели входа — картинки, видео, набор (v4.81). */
        \App\Core\CoverSet::purge($clientDbId);
        return $counts;
    }

    public static function usersCount(int $clientDbId): int
    {
        return (int)DB::val('SELECT COUNT(*) FROM {user_client_access} WHERE client_id = ?', [$clientDbId]);
    }
}
