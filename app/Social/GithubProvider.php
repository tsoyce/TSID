<?php

declare(strict_types=1);

namespace App\Social;

/** GitHub OAuth 2.0 — для dev-аудитории. Email берём из /user/emails (primary). */
final class GithubProvider extends Provider
{
    public function id(): string { return 'github'; }
    public function title(): string { return 'GitHub'; }

    public function authUrl(string $state): string
    {
        return 'https://github.com/login/oauth/authorize?' . http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'scope' => 'read:user user:email',
            'state' => $state,
        ]);
    }

    public function handleCallback(array $query): array
    {
        $code = (string)($query['code'] ?? '');
        if ($code === '') throw new \RuntimeException('GitHub не вернул код авторизации');

        $tokens = $this->httpPost('https://github.com/login/oauth/access_token', [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);
        if (empty($tokens['access_token'])) {
            throw new \RuntimeException('GitHub не выдал токен: ' . ($tokens['error_description'] ?? '?'));
        }
        $auth = ['Authorization: Bearer ' . $tokens['access_token'], 'User-Agent: SSO'];

        $user = $this->httpGet('https://api.github.com/user', $auth);
        if (empty($user['id'])) throw new \RuntimeException('GitHub не отдал профиль');

        /* Адрес берём ТОЛЬКО из /user/emails и только помеченный verified.
           Поле email в /user — это публичный адрес профиля, и GitHub не
           обещает, что он подтверждён: туда можно вписать что угодно, в том
           числе чужой адрес. До v4.2 мы брали именно его, а к /user/emails
           обращались лишь когда он пуст. Это давало захват чужого аккаунта:
           вписал victim@example.com в профиль GitHub, вошёл через него — и SSO
           находил учётку жертвы по совпадению почты и привязывал к ней
           соцвход. Подтверждение адреса имеет смысл только тогда, когда его
           подтвердил тот, кто за него отвечает. */
        $email = null;
        $emails = $this->httpGet('https://api.github.com/user/emails', $auth);
        if (is_array($emails)) {
            foreach ($emails as $e) {
                if (!empty($e['primary']) && !empty($e['verified'])) {
                    $email = $e['email'];
                    break;
                }
            }
            /* Основного подтверждённого нет — годится любой подтверждённый:
               аккаунт без единого подтверждённого адреса email не получает. */
            if ($email === null) {
                foreach ($emails as $e) {
                    if (!empty($e['verified'])) { $email = $e['email']; break; }
                }
            }
        }
        return [
            'uid' => (string)$user['id'],
            'email' => $email,
            'name' => $user['name'] ?? ($user['login'] ?? null),
            'avatar' => $user['avatar_url'] ?? null,
        ];
    }
}
