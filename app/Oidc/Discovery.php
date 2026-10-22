<?php

declare(strict_types=1);

namespace App\Oidc;

/** Discovery-документ (§3.1): все URL и поддерживаемые возможности. */
final class Discovery
{
    public static function document(): array
    {
        $base = base_url();
        return [
            'issuer' => $base,
            'authorization_endpoint' => $base . '/authorize',
            'token_endpoint' => $base . '/token',
            'userinfo_endpoint' => $base . '/userinfo',
            'jwks_uri' => $base . '/jwks.json',
            'end_session_endpoint' => $base . '/logout',
            'introspection_endpoint' => $base . '/introspect',
            'revocation_endpoint' => $base . '/revoke',
            'scopes_supported' => ['openid', 'profile', 'email', 'phone', 'roles', 'offline_access'],
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => [
                'iss', 'sub', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'sid',
                'name', 'family_name', 'given_name', 'preferred_username',
                'email', 'email_verified', 'phone_number', 'phone_number_verified',
                'picture', 'birthdate', 'gender', 'locale', 'zoneinfo', 'updated_at', 'roles', 'verified',
                /* Их тоже отдаём — список обязан называть всё (v4.70). */
                'access_level', 'access_tier', 'orgs', 'account_number',
            ],
            /* Языки берём из живого списка Lang, а не из константы: иначе после
               добавления словаря discovery продолжал бы врать про один только ru. */
            'ui_locales_supported' => array_keys(\App\Core\Lang::SUPPORTED),
            'backchannel_logout_supported' => true,
            'backchannel_logout_session_supported' => true,
            /* RFC 9207: в ответе /authorize есть iss (v4.70). */
            'authorization_response_iss_parameter_supported' => true,
            /* Нестандартное поле: подсказка порталам, что можно войти без
               редиректа. Клиенты, которые его не знают, просто не заметят. */
            'fedcm_config_endpoint' => $base . '/fedcm/config.json',
        ];
    }
}
