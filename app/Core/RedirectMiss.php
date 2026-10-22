<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Client;

/**
 * Адрес, который сервис прислал, а у нас его нет, — на входе и на выходе.
 *
 * Раньше подключение портала адрес возврата угадывало: «…/sso/callback.php».
 * Для нашего набора это правда — установщик кладёт такой файл. Для сервиса со
 * своей маршрутизацией это догадка, и она не сбывается: Архив слал
 * «…/sso/callback», у нас было записано «…/sso/callback.php», и вход
 * заканчивался страницей «адрес возврата не совпадает» — без единой подсказки,
 * какой адрес пришёл на самом деле.
 *
 * С выходом было то же самое (v4.60): Архив возвращал людей на
 * «https://portal.example», у нас был записан «https://portal.example/». Байт-в-байт
 * это разные адреса, и после «Выйти» человек оказывался на входе SSO вместо
 * своего сервиса.
 *
 * Теперь не угадываем, а запоминаем. Сервис сам присылает адрес; мы его не
 * исполняем — сравнение байт-в-байт остаётся законом (§3.5), — но записываем и
 * показываем администратору в карточке с кнопкой «добавить».
 *
 * Записываем только адреса с СОБСТВЕННОГО origin портала: та же схема, хост и
 * порт, что у base_url. Чужой адрес прислать может кто угодно, и если бы мы его
 * запоминали, карточку заваливали бы мусором, — а среди мусора администратор
 * однажды нажал бы «добавить» не глядя.
 */
final class RedirectMiss
{
    /** Семейства настроек: portal.redirect_miss.<id> и portal.logout_miss.<id>. */
    private const KEYS = ['redirect' => 'portal.redirect_miss.', 'logout' => 'portal.logout_miss.'];

    /** Сколько последних адресов помнить: больше пяти разных — уже не опечатка. */
    private const KEEP = 5;

    /** Тот же ли это origin, что у портала: схема, хост и порт. */
    public static function sameOrigin(array $client, string $uri): bool
    {
        $a = parse_url((string)($client['base_url'] ?? ''));
        $b = parse_url($uri);
        if (!is_array($a) || !is_array($b)) return false;

        $scheme = strtolower((string)($b['scheme'] ?? ''));
        if ($scheme !== strtolower((string)($a['scheme'] ?? ''))) return false;
        if (!in_array($scheme, ['https', 'http'], true)) return false;
        /* http — только локальная разработка, как у манифеста: по открытому
           каналу код авторизации читают по дороге. */
        if ($scheme === 'http' && !in_array($b['host'] ?? '', ['localhost', '127.0.0.1'], true)) return false;
        /* Логин в адресе и фрагмент — не то, что шлёт честный сервис. */
        if (isset($b['user']) || isset($b['pass']) || isset($b['fragment'])) return false;

        return strtolower((string)($a['host'] ?? '')) === strtolower((string)($b['host'] ?? ''))
            && (int)($a['port'] ?? 0) === (int)($b['port'] ?? 0);
    }

    /** Запомнить промах. Возвращает, записан ли он. */
    public static function record(array $client, string $uri, string $kind = 'redirect'): bool
    {
        if ($uri === '' || strlen($uri) > 255 || !self::sameOrigin($client, $uri)) return false;

        $list = self::all((int)$client['id'], $kind);
        $found = false;
        foreach ($list as $i => $m) {
            if ($m['uri'] !== $uri) continue;
            $list[$i]['n'] = (int)$m['n'] + 1;
            $list[$i]['at'] = now();
            $found = true;
        }
        if (!$found) $list[] = ['uri' => $uri, 'n' => 1, 'at' => now()];

        usort($list, static fn(array $x, array $y): int => strcmp((string)$y['at'], (string)$x['at']));
        Setting::set(self::key($kind) . (int)$client['id'],
            json_encode(array_slice($list, 0, self::KEEP), JSON_UNESCAPED_SLASHES));
        return true;
    }

    /**
     * Что ждёт решения администратора: без уже зарегистрированных.
     *
     * @return list<array{uri: string, n: int, at: string}>
     */
    public static function pending(array $client, string $kind = 'redirect'): array
    {
        return array_values(array_filter(self::all((int)$client['id'], $kind),
            static fn(array $m): bool => !self::registered($client, $m['uri'], $kind)));
    }

    /**
     * Добавить присланный адрес к зарегистрированным.
     *
     * Добавляется только то, что сервис действительно присылал: форма не должна
     * становиться способом вписать произвольный адрес в обход полей карточки,
     * где его хотя бы видно целиком.
     */
    public static function accept(array $client, string $uri, string $kind = 'redirect'): bool
    {
        $sent = false;
        foreach (self::pending($client, $kind) as $m) {
            if ($m['uri'] === $uri) $sent = true;
        }
        if (!$sent || !self::sameOrigin($client, $uri)) return false;

        $column = $kind === 'logout' ? 'post_logout_uris' : 'redirect_uris';
        $uris = array_values(array_unique(array_merge((array)$client[$column], [$uri])));
        Client::update((int)$client['id'], [$column => $uris]);
        self::forget((int)$client['id'], $uri, $kind);
        Audit::log($kind === 'logout' ? 'admin.client.logout_accept' : 'admin.client.redirect_accept',
            ['client' => (int)$client['id'], 'meta' => ['uri' => $uri]]);
        return true;
    }

    /** Убрать адрес из ожидающих. */
    public static function forget(int $clientId, string $uri, string $kind = 'redirect'): void
    {
        $list = array_values(array_filter(self::all($clientId, $kind),
            static fn(array $m): bool => $m['uri'] !== $uri));
        if ($list === []) {
            Setting::delete(self::key($kind) . $clientId);
        } else {
            Setting::set(self::key($kind) . $clientId, json_encode($list, JSON_UNESCAPED_SLASHES));
        }
    }

    private static function registered(array $client, string $uri, string $kind): bool
    {
        return $kind === 'logout'
            ? Client::postLogoutUriAllowed($client, $uri)
            : Client::redirectUriAllowed($client, $uri);
    }

    private static function key(string $kind): string
    {
        return self::KEYS[$kind] ?? self::KEYS['redirect'];
    }

    /** @return list<array{uri: string, n: int, at: string}> */
    private static function all(int $clientId, string $kind): array
    {
        $raw = json_decode((string)Setting::get(self::key($kind) . $clientId, '[]'), true);
        if (!is_array($raw)) return [];
        return array_values(array_filter($raw,
            static fn($m): bool => is_array($m) && isset($m['uri'], $m['at']) && is_string($m['uri'])));
    }
}
