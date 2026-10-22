<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\DB;

/** Токены Admin API: scope-based, в БД только sha256, показывается один раз. */
final class ApiToken
{
    /**
     * Право «всё» — уровень суперадмина для программы (v4.51).
     *
     * Отдельным кодом, а не «отметить все галочки»: набор прав со временем
     * растёт, и токен, выданный как «всё», обязан получать новое право вместе
     * с системой. Иначе через полгода «полный доступ» тихо перестанет быть
     * полным, и разбираться в этом будут по симптому «а почему 403».
     */
    public const SCOPE_ALL = '*';

    /** Коды прав: часть контракта API, не переводятся. */
    public const SCOPE_CODES = [
        'users:read', 'users:write',
        'clients:read', 'clients:write',
        'roles:read', 'roles:write',
        'orgs:read', 'orgs:write',
        'access:write',
        'settings:read', 'settings:write',
        'audit:read',
        self::SCOPE_ALL,
    ];

    /**
     * Права с человекочитаемыми подписями для админки.
     * Метод, а не const: `const` не допускает вызова t().
     *
     * @return array<string, string>
     */
    public static function scopes(): array
    {
        return [
            'users:read' => t('Чтение пользователей'),
            'users:write' => t('Создание, изменение, блокировка пользователей'),
            'clients:read' => t('Чтение порталов'),
            'clients:write' => t('Подключение, изменение и удаление порталов'),
            'roles:read' => t('Чтение ролей и выдач'),
            'roles:write' => t('Заведение ролей, выдача и отзыв'),
            'orgs:read' => t('Чтение организаций и их состава'),
            'orgs:write' => t('Заведение организаций, состав, доступы'),
            'access:write' => t('Открывать и закрывать людям доступ к порталам'),
            'settings:read' => t('Чтение настроек'),
            'settings:write' => t('Изменение настроек'),
            'audit:read' => t('Чтение журнала аудита'),
            self::SCOPE_ALL => t('Всё сразу — уровень суперадмина, включая права, которые появятся позже'),
        ];
    }

    /** Есть ли у токена это право. `*` покрывает любое. */
    public static function allows(array $scopes, string $need): bool
    {
        return in_array(self::SCOPE_ALL, $scopes, true) || in_array($need, $scopes, true);
    }

    /** Создать. Возвращает [id, plaintext-токен]. */
    public static function create(string $name, array $scopes, ?int $createdBy, ?int $days = null): array
    {
        $secret = Crypto::randomToken(32);
        $token = 'sso_' . $secret;
        $id = DB::insert('api_tokens', [
            'name' => mb_substr(trim($name), 0, 100),
            'token_hash' => Crypto::hashToken($token),
            'prefix' => substr($secret, 0, 8),
            'scopes' => json_encode(array_values(array_intersect($scopes, self::SCOPE_CODES)), JSON_UNESCAPED_SLASHES),
            'created_by' => $createdBy,
            'expires_at' => $days !== null && $days > 0 ? now_plus($days * 86400) : null,
            'created_at' => now(),
        ]);
        return [$id, $token];
    }

    /** Живой токен по значению из заголовка. */
    public static function findUsable(string $token): ?array
    {
        $row = DB::row('SELECT * FROM {api_tokens} WHERE token_hash = ?', [Crypto::hashToken($token)]);
        if (!$row || $row['revoked_at'] !== null) return null;
        if ($row['expires_at'] !== null && strtotime($row['expires_at'] . ' UTC') <= time()) return null;
        $row['scopes'] = is_string($row['scopes']) ? (json_decode($row['scopes'], true) ?: []) : (array)$row['scopes'];
        return $row;
    }

    public static function touch(int $id): void
    {
        DB::update('api_tokens', ['last_used_at' => now()], 'id = ?', [$id]);
    }

    /** Схема готова? Токены API появились в миграции 004. */
    public static function ready(): bool
    {
        return \App\Core\Migrations::ready('api_tokens');
    }

    public static function all(): array
    {
        if (!self::ready()) return [];
        return array_map(function ($t) {
            $t['scopes'] = is_string($t['scopes']) ? (json_decode($t['scopes'], true) ?: []) : (array)$t['scopes'];
            return $t;
        }, DB::all('SELECT * FROM {api_tokens} ORDER BY id DESC'));
    }

    public static function revoke(int $id): void
    {
        DB::update('api_tokens', ['revoked_at' => now()], 'id = ? AND revoked_at IS NULL', [$id]);
    }

    /**
     * Убрать отозванные насовсем.
     *
     * Отозванный токен не работает, но продолжает занимать строку на экране, и
     * через год их там будет сотня — список, который листают мимо, перестаёт
     * быть списком. Удалять живые нельзя: строка на экране это единственное
     * место, где видно, что токен вообще существует.
     *
     * Возвращает, сколько убрано.
     */
    public static function purgeRevoked(): int
    {
        if (!self::ready()) return 0;
        $n = (int)DB::val('SELECT COUNT(*) FROM {api_tokens} WHERE revoked_at IS NOT NULL');
        if ($n > 0) DB::run('DELETE FROM {api_tokens} WHERE revoked_at IS NOT NULL');
        return $n;
    }
}
