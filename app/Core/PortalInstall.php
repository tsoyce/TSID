<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Client;
use App\Models\OneTimeToken;
use App\Models\Role;
use App\Models\User;

/**
 * Установка моста одной ссылкой (v4.22).
 *
 * Задача своими словами: до сих пор подключение портала выглядело так —
 * скачай набор, найди в карточке client_secret, впиши его в config.php, вернись
 * за ключом переноса, впиши и его, не забудь про манифест. Пять ручных
 * действий, каждое из которых можно сделать неправильно, а половину ошибок
 * видно только по симптому вроде «кнопка есть, а вход не работает».
 *
 * Теперь: администратор выдаёт одну ссылку и открывает её на портале. Портал
 * сам приходит сюда с билетом и забирает всё — реквизиты, ключ переноса,
 * адреса — и заодно узнаёт, кто именно его устанавливает. Ручным остаётся одно
 * действие, которое иначе не сделать: залить каталог `sso/` на сайт.
 *
 * Почему билет отдаёт секреты и это допустимо:
 *
 *  · Живёт полчаса и ровно один обмен. Не успел — выдай новый, это одна кнопка.
 *  · При обмене секрет портала ПЕРЕВЫПУСКАЕТСЯ. Билет становится единственным
 *    источником действующего секрета: утёкшая копия старого набора умирает
 *    сама. Прежний секрет ещё сутки принимается — ровно чтобы работающий
 *    портал не лёг в момент переустановки.
 *  · Каждый обмен пишется в аудит вместе с адресом, откуда пришли.
 *
 * Чего билет НЕ делает: не создаёт портал, не меняет права, не выдаёт ничего
 * про людей. Он отдаёт реквизиты одного уже заведённого портала — не больше.
 */
final class PortalInstall
{
    /** Полчаса: ссылку открывают сразу или не открывают вовсе. */
    public const TTL = 1800;

    /**
     * Выдать ссылку установки.
     *
     * Заодно назначает выдавшему роль администратора этого портала: портал
     * потом узнаёт владельца по роли в токене, и если роли нет — узнавать
     * нечего. Раньше это было отдельным ручным шагом, о котором вспоминали
     * после того, как заперлись в собственной админке.
     */
    public static function issue(array $client, int $byUserId): string
    {
        $role = Role::clientAdmin();
        Role::assignScoped($byUserId, (int)$role['id'], Scope::client((int)$client['id']), $byUserId);

        $ticket = OneTimeToken::issue($byUserId, 'portal_install', [
            'client_id' => (int)$client['id'],
        ]);
        Audit::log('client.install_link', ['client' => (int)$client['id'],
            'meta' => ['ttl' => self::TTL]]);
        return rtrim((string)$client['base_url'], '/') . '/sso/install.php?ticket=' . $ticket;
    }

    /**
     * Обменять билет на реквизиты. Ровно один раз.
     *
     * @return array{ok: bool, error: ?string, data: array<string, mixed>}
     */
    public static function redeem(string $ticket): array
    {
        $fail = static fn(string $why): array => ['ok' => false, 'error' => $why, 'data' => []];

        $row = OneTimeToken::peek($ticket, 'portal_install');
        if (!$row) return $fail('Ссылка установки недействительна или уже использована.');

        $client = Client::find((int)($row['payload']['client_id'] ?? 0));
        if (!$client) return $fail('Портал не найден.');
        if (!(int)$client['is_active']) return $fail('Портал выключен — включите его в карточке и выдайте ссылку заново.');

        /* Гасим билет ДО выдачи секретов: если что-то развалится дальше, лучше
           потерянная установка, чем билет, которым можно попробовать ещё раз. */
        OneTimeToken::consume($ticket, 'portal_install');

        /* Секрет перевыпускаем: билет становится единственным источником
           действующего. Старый живёт сутки — работающий портал не должен
           лечь в момент переустановки. */
        $secret = Client::rotateSecret((int)$client['id']);
        $usersSecret = PortalUsers::ready() && (string)($client['users_secret'] ?? '') !== ''
            ? (string)$client['users_secret']
            : (PortalUsers::ready() ? PortalUsers::issueSecret($client) : '');

        $owner = null;
        $byUserId = (int)($row['user_id'] ?? 0);
        if ($byUserId > 0) {
            $u = User::find($byUserId);
            if ($u) {
                /* Владельца называем теми же полями, которыми потом приедет
                   токен: портал должен узнать того же человека, а не гадать. */
                $owner = [
                    'sub' => (string)$u['public_id'],
                    'login' => (string)($u['login'] ?? ''),
                    'email' => (string)($u['email'] ?? ''),
                    'name' => user_title($u),
                ];
            }
        }

        Audit::log('client.installed', ['client' => (int)$client['id'], 'on' => $byUserId ?: null,
            'meta' => ['ip' => request_ip(), 'users_key' => $usersSecret !== '']]);
        WebhookDispatcher::emit('client.installed', [
            'client_id' => (string)$client['client_id'], 'name' => (string)$client['name'],
        ]);
        AdminNotify::say('client.installed',
            fn() => t('Портал «:name» забрал свои реквизиты по ссылке установки. Вместе с ними перевыпущен client_secret: прежний перестанет приниматься через сутки.',
                ['name' => (string)$client['name']]),
            fn() => [t('Портал') => (string)$client['name'], t('Адрес') => request_ip()]);

        $base = rtrim((string)$client['base_url'], '/');
        /* Адрес возврата набора — не догадка, а факт: установщик кладёт
           callback.php ровно сюда. Подключение портала больше не угадывает
           адрес, поэтому, если его ещё нет, записываем этот сейчас — иначе
           первый же вход упрётся в «адрес возврата не совпадает». */
        if ((array)$client['redirect_uris'] === []) {
            $client['redirect_uris'] = [$base . '/sso/callback.php'];
            Client::update((int)$client['id'], ['redirect_uris' => $client['redirect_uris']]);
        }
        return ['ok' => true, 'error' => null, 'data' => [
            'app_name' => Setting::appName(),
            'issuer' => rtrim(base_url(), '/'),
            'client_id' => (string)$client['client_id'],
            'client_secret' => $secret,
            'users_secret' => $usersSecret,
            'redirect_uri' => (string)($client['redirect_uris'][0] ?? $base . '/sso/callback.php'),
            'post_logout_uri' => (string)($client['post_logout_uris'][0] ?? $base . '/'),
            'backchannel_uri' => (string)($client['backchannel_uri'] ?? $base . '/sso/backchannel.php'),
            'scopes' => implode(' ', array_diff((array)$client['allowed_scopes'], ['offline_access'])),
            'portal' => [
                'name' => (string)$client['name'],
                'slug' => (string)$client['slug'],
                'base_url' => $base,
            ],
            'owner' => $owner,
        ]];
    }
}
