<?php

declare(strict_types=1);

namespace App\Oidc;

use App\Core\Jwt;
use App\Models\User;

/** Сборка id_token и userinfo-claims (§3.3). Фильтрация — по scope. */
final class IdToken
{
    /** Claims по scope — общая часть для id_token и /userinfo. */
    public static function claims(array $user, array $client, string $scope): array
    {
        $scopes = explode(' ', $scope);
        $c = ['sub' => $user['public_id']];
        if (in_array('profile', $scopes, true)) {
            $fio = trim(implode(' ', array_filter([$user['first_name'], $user['last_name']])));
            $c += [
                'name' => $user['display_name'] ?: ($fio !== '' ? $fio : null),
                'family_name' => $user['last_name'],
                'given_name' => $user['first_name'],
                'preferred_username' => $user['login'],
                'picture' => avatar_url($user, 512),
                'birthdate' => $user['birthdate'],
                /* gender — стандартный claim scope profile (OIDC Core §5.1);
                   отдаём только если человек его указал */
                'gender' => $user['gender'] ?? null,
                'locale' => $user['locale'],
                'zoneinfo' => $user['timezone'],
                'updated_at' => strtotime(($user['updated_at'] ?? now()) . ' UTC'),
            ];
            /* Номер аккаунта — нестандартный claim, поэтому вне списка выше и
               без него портал прекрасно живёт. Нужен там, где человек называет
               номер в поддержке портала, а не SSO: по нему находят того же
               человека, не спрашивая sub, который человек не знает.
               Идентификатором субъекта не является — им остаётся sub. */
            if (!empty($user['account_number'])) {
                $c['account_number'] = $user['account_number'];
            }
        }
        /* Почты может не быть — так приходят люди из портала, где регистрация
           шла логином и паролём. Тогда claim не отдаётся вовсе, а не пустой
           строкой: по спецификации OIDC отсутствующее значение и означает
           «нет данных», а пустая строка выглядит как настоящий адрес и в базе
           портала ляжет рядом с чужими такими же пустыми. Портал обязан быть
           готов к отсутствию email — так и написано в его integration.md. */
        if (in_array('email', $scopes, true) && !empty($user['email'])) {
            $c['email'] = $user['email'];
            $c['email_verified'] = $user['email_verified_at'] !== null;
        }
        if (in_array('phone', $scopes, true)) {
            $c['phone_number'] = $user['phone'];
            $c['phone_number_verified'] = $user['phone_verified_at'] !== null;
        }
        if (in_array('roles', $scopes, true)) {
            $c['roles'] = User::rolesClaim((int)$user['id'], (int)$client['id']);
            // Организации пользователя: ["acme:owner", …] — портал маппит их сам
            $orgs = \App\Models\Org::claimFor((int)$user['id']);
            if ($orgs !== []) $c['orgs'] = $orgs;

            /* Сводка для портала: одно число, по которому можно ветвиться, не
               изучая наш словарь ролей. Число для сравнений (`>= 70`), слово
               для читаемости — одно и то же в двух представлениях.
               Разбор — ACCESS_MODEL.md, области — SCOPES.md.

               Уровень считается ДЛЯ ЭТОГО портала. Раньше — по всей установке,
               и админ одного сервиса приезжал администратором во все прочие:
               роли в claim фильтровались по порталу, а число нет. */
            $level = \App\Core\AccessLevel::forClient($user, (int)$client['id']);
            $c['access_level'] = $level;
            $c['access_tier'] = \App\Core\AccessLevel::tier($level);
        }
        $c['verified'] = (bool)$user['is_verified_badge']; // «галочка» экосистемы
        return array_filter($c, fn($v) => $v !== null);
    }

    /** id_token: 10 минут (§3.3). */
    public static function issue(array $user, array $client, string $scope, ?string $nonce,
                                 string $sid, int $authTime): string
    {
        $now = time();
        $claims = [
            'iss' => base_url(),
            'aud' => $client['client_id'],
            'exp' => $now + 600,
            'iat' => $now,
            'auth_time' => $authTime,
            'sid' => $sid,
        ] + self::claims($user, $client, $scope);
        if ($nonce !== null && $nonce !== '') $claims['nonce'] = $nonce;
        return Jwt::sign($claims);
    }

    /** access_token: JWT RS256, aud "userinfo", 15 минут, stateless (§3.3). */
    /** Срок access-токена настраивается: короче — быстрее вступает в силу блокировка. */
    public static function accessTtl(): int
    {
        return \App\Core\Setting::timeout('access_token', 900);
    }

    public static function issueAccess(array $user, array $client, string $scope, ?string $sid): string
    {
        $now = time();
        return Jwt::sign([
            'iss' => base_url(),
            'aud' => 'userinfo',
            'sub' => $user['public_id'],
            'cid' => $client['client_id'],
            'scope' => $scope,
            'sid' => $sid,
            'exp' => $now + self::accessTtl(),
            'iat' => $now,
            'jti' => bin2hex(random_bytes(8)),
        ]);
    }

    /** Имя события выхода по OIDC Back-Channel Logout 1.0, §2.4. */
    public const BACKCHANNEL_LOGOUT = 'http://schemas.openid.net/event/backchannel-logout';

    /** event-token для backchannel-канала (§2.4). */
    public static function issueEvent(array $client, string $event, array $payload): string
    {
        $now = time();
        $events = [$event => (object)array_diff_key($payload, ['sub' => 1, 'sid' => 1])];
        /* Выход — ещё и под стандартным именем (v4.70). Discovery обещает
           backchannel_logout_supported, а чужая библиотека OIDC ищет в events
           именно этот ключ: без него выход до неё не доходил.

           Только порталу без нашего манифеста. Манифест публикует наш набор
           или мост — они читают `logout`, а незнакомый ключ старый набор
           записывал бы в «необработанные» при каждом выходе: так устроены
           приёмники Портфолио и Архива, замерено на боевой 23.09. */
        if ($event === 'logout' && empty($client['manifest_fetched_at'])) {
            $events[self::BACKCHANNEL_LOGOUT] = new \stdClass();
        }
        $claims = [
            'iss' => base_url(),
            'aud' => $client['oauth_client_id'] ?? $client['client_id'],
            'iat' => $now,
            'exp' => $now + 120,
            'jti' => bin2hex(random_bytes(8)),
            'events' => $events,
        ];
        foreach (['sub', 'sid'] as $top) {
            if (isset($payload[$top])) $claims[$top] = $payload[$top];
        }
        return Jwt::sign($claims);
    }
}
