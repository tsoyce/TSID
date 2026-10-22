<?php

declare(strict_types=1);

namespace App\Social;

/** Яндекс OAuth 2.0 — самый простой из четвёрки, отдаёт email сразу. */
final class YandexProvider extends Provider
{
    public function id(): string { return 'yandex'; }
    public function title(): string { return t('Яндекс ID'); }

    public function authUrl(string $state): string
    {
        return 'https://oauth.yandex.ru/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);
    }

    public function handleCallback(array $query): array
    {
        $code = (string)($query['code'] ?? '');
        if ($code === '') throw new \RuntimeException('Яндекс не вернул код авторизации');

        $tokens = $this->httpPost('https://oauth.yandex.ru/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ]);
        if (empty($tokens['access_token'])) {
            throw new \RuntimeException('Яндекс не выдал токен: ' . ($tokens['error_description'] ?? $tokens['error'] ?? '?'));
        }

        $info = $this->httpGet('https://login.yandex.ru/info?format=json',
            ['Authorization: OAuth ' . $tokens['access_token']]);
        if (empty($info['id'])) throw new \RuntimeException('Яндекс не отдал профиль');

        $avatar = null;
        if (!empty($info['default_avatar_id']) && empty($info['is_avatar_empty'])) {
            $avatar = 'https://avatars.yandex.net/get-yapic/' . $info['default_avatar_id'] . '/islands-200';
        }
        /* Телефон и пол приходят в том же ответе, если приложению выданы права
           login:default_phone и login:info — до v3.6 мы их просто не читали. */
        $phone = null;
        if (!empty($info['default_phone']['number'])) {
            $phone = (string)$info['default_phone']['number'];
        }
        $gender = match ($info['sex'] ?? null) {
            'male' => 'male', 'female' => 'female', default => null,
        };

        return [
            'uid' => (string)$info['id'],
            'email' => $info['default_email'] ?? ($info['emails'][0] ?? null),
            'name' => $info['real_name'] ?? ($info['display_name'] ?? null),
            'first_name' => $info['first_name'] ?? null,
            'last_name' => $info['last_name'] ?? null,
            /* birthday — «1990-06-15»; год человек может скрыть, тогда «0000-06-15» */
            'birthdate' => \App\Core\Birthdate::parse($info['birthday'] ?? null),
            'avatar' => $avatar,
            'phone' => $phone,
            'gender' => $gender,
        ];
    }
}
