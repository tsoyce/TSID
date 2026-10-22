<?php

declare(strict_types=1);

namespace App\Social;

/**
 * VK OAuth 2.0. Особенность: email приходит рядом с токеном (не в профиле)
 * и бывает не всегда — тогда SSO попросит добавить почту вручную.
 */
final class VkProvider extends Provider
{
    public function id(): string { return 'vk'; }
    public function title(): string { return 'VK'; }

    public function authUrl(string $state): string
    {
        return 'https://oauth.vk.com/authorize?' . http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'email',
            'state' => $state,
            'v' => '5.199',
        ]);
    }

    public function handleCallback(array $query): array
    {
        $code = (string)($query['code'] ?? '');
        if ($code === '') throw new \RuntimeException('VK не вернул код авторизации');

        $tokens = $this->httpGet('https://oauth.vk.com/access_token?' . http_build_query([
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]));
        if (empty($tokens['access_token']) || empty($tokens['user_id'])) {
            throw new \RuntimeException('VK не выдал токен: ' . ($tokens['error_description'] ?? '?'));
        }

        $profile = $this->httpGet('https://api.vk.com/method/users.get?' . http_build_query([
            'user_ids' => $tokens['user_id'],
            'fields' => 'photo_200,bdate',
            'access_token' => $tokens['access_token'],
            'v' => '5.199',
        ]));
        $info = $profile['response'][0] ?? [];

        return [
            'uid' => (string)$tokens['user_id'],
            'email' => $tokens['email'] ?? null,   // приходит вместе с токеном, если дали доступ
            'name' => trim(($info['first_name'] ?? '') . ' ' . ($info['last_name'] ?? '')) ?: null,
            'first_name' => $info['first_name'] ?? null,
            'last_name' => $info['last_name'] ?? null,
            /* bdate приходит как «15.6.1990», а без года — просто «15.6».
               Разбирает и отсеивает Birthdate::parse. */
            'birthdate' => \App\Core\Birthdate::parse($info['bdate'] ?? null),
            'avatar' => $info['photo_200'] ?? null,
        ];
    }
}
