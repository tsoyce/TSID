<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Client;

/**
 * Вход окном поверх страницы портала — экран входа SSO во фрейме (v4.73).
 *
 * Владелец хотел как у SocialLib: «Войти» на портале открывает окно прямо на
 * его странице, а не отдельное окно браузера. Портал (sso.js) показывает
 * затемнение с карточкой и кладёт в неё фрейм `/authorize?…&display=frame`.
 * Дальше идёт обычный поток входа, только в компактной раскладке, а в конце
 * SSO не уводит фрейм на портал, а отдаёт странице портала адрес возврата с
 * кодом (postMessage). Страница переходит по нему сама — колбэк портала
 * работает как при входе страницей, правок на его стороне не нужно.
 *
 * Три вещи, без которых фрейм не работает, собраны здесь.
 *
 * 1. Кому можно встраивать. Общая политика запрещает фреймы вовсе
 *    (frame-ancestors 'none'): экран входа во фрейме чужого сайта — это
 *    clickjacking. Разрешение даётся только экранам входа, только пока идёт
 *    вход с display=frame и только адресам ЭТОГО портала — из его
 *    зарегистрированных адресов возврата и базового адреса.
 *
 * 2. Cookie на чужом домене. Сессии SSO живут в cookie SameSite=Lax, и во
 *    фрейме на сайте другого домена (Портфолио, Календарь событий) браузер их не
 *    отправляет и не принимает. Для такого фрейма заводится своя пара cookie —
 *    SameSite=None; Partitioned: браузер держит её отдельно для этого сайта
 *    (CHIPS), и она не видна нигде, кроме фрейма на нём. Вход во фрейме у
 *    такого портала — свой, отдельный от входа в SSO напрямую. Портал на том же
 *    домене (Архив) обходится обычными cookie — и видит общий вход.
 *
 * 3. Что во фрейме не получится. Вход через сервисы (их страницы запрещают
 *    фреймы) и ссылка из письма (она откроется в новой вкладке, а не во
 *    фрейме) — фрейм просит портал открыть вход отдельным окном. Браузер не
 *    пустил cookie во фрейм вовсе — то же самое.
 */
final class Framed
{
    public const DISPLAY = 'frame';

    /** Экраны потока входа — только их можно встраивать. */
    private const PATHS = ['/authorize', '/login', '/register', '/recover', '/reset', '/verify', '/consent'];

    private static bool $partitioned = false;

    /**
     * До старта сессии: не отдельная ли это пара cookie фрейма на чужом сайте.
     *
     * Своя cookie пришла — значит, мы во фрейме на чужом сайте: вне его она
     * не видна (изолирована сайтом страницы). Новый вход — /authorize с
     * display=frame, запрошенный фреймом с чужого сайта.
     */
    public static function detect(): void
    {
        $base = (string)Config::get('session.name', 'sso');
        if (isset($_COOKIE[$base . '_fs']) || isset($_COOKIE[$base . '_f'])) {
            self::$partitioned = true;
            return;
        }
        $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        self::$partitioned = $path === '/authorize'
            && ($_GET['display'] ?? '') === self::DISPLAY
            && ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe'
            && ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site';
    }

    /** Имя cookie: служебная сессия (_s) или вход (''), у фрейма на чужом сайте — свои. */
    public static function cookieName(string $suffix): string
    {
        $base = (string)Config::get('session.name', 'sso');
        if (!self::$partitioned) return $base . $suffix;
        return $base . ($suffix === '_s' ? '_fs' : '_f');
    }

    /**
     * Параметры cookie. У фрейма на чужом сайте — SameSite=None; Partitioned:
     * PHP 8.4 не знает атрибута Partitioned, и он дописывается к значению
     * SameSite — оно уходит в заголовок как есть. Secure обязателен для обоих
     * атрибутов; http://localhost браузеры считают безопасным.
     */
    public static function sameSite(): string
    {
        return self::$partitioned ? 'None; Partitioned' : 'Lax';
    }

    public static function secure(): bool
    {
        return self::$partitioned || is_https();
    }

    /**
     * Запрос авторизации, который идёт во фрейме, — или null.
     *
     * Сессия — первый источник, ключ возврата — подстраховка: без него страница
     * «браузер не пустил cookie» не знала бы, какому порталу её показывать.
     *
     * @return array<string, mixed>|null
     */
    public static function request(): ?array
    {
        foreach (['oauth_request', 'consent_request'] as $key) {
            $q = $_SESSION[$key] ?? null;
            if (is_array($q) && ($q['display'] ?? '') === self::DISPLAY) return $q;
        }
        $rt = Resume::fromRequest();
        if ($rt !== '') {
            $q = Resume::peek($rt);
            if ($q !== null && ($q['display'] ?? '') === self::DISPLAY) return $q;
        }
        return null;
    }

    /** Экран потока входа (встраивать можно только его). */
    public static function isFlowPath(string $path): bool
    {
        foreach (self::PATHS as $p) {
            if ($path === $p || str_starts_with($path, $p . '/')) return true;
        }
        return false;
    }

    /**
     * Адреса портала, которым можно встраивать экран входа: его адреса
     * возврата и базовый адрес — то, что зарегистрировал администратор.
     *
     * @return list<string>
     */
    public static function originsOf(array $client): array
    {
        $out = [];
        foreach (array_merge((array)($client['redirect_uris'] ?? []), [(string)($client['base_url'] ?? '')]) as $uri) {
            $o = Csp::origin((string)$uri);
            if ($o !== null) $out[] = $o;
        }
        return array_values(array_unique($out));
    }

    /** Origin страницы портала, которой отдаётся адрес возврата, — origin redirect_uri. */
    public static function parentOrigin(?array $q = null): ?string
    {
        $q ??= self::request();
        return $q !== null ? Csp::origin((string)($q['redirect_uri'] ?? '')) : null;
    }

    /**
     * Разрешить этой странице встраивание порталом, если идёт вход во фрейме.
     * Вызывается из Csp::start() для всех экранов входа и из /authorize —
     * там запрос ещё не в сессии.
     */
    public static function allowFraming(?array $q = null): void
    {
        $q ??= self::request();
        if ($q === null) return;
        $client = Client::findByClientId((string)($q['client_id'] ?? ''));
        if ($client === null || empty($client['is_active'])) return;
        $origins = self::originsOf($client);
        if ($origins === []) return;
        Csp::setFrameAncestors($origins);
    }

    /**
     * Браузер не пустил cookie во фрейм: вход во фрейме идёт, а сессия пустая.
     *
     * /authorize только что положил запрос в сессию и отправил сюда; если его
     * здесь нет, cookie не доехала — вход во фрейме не состоится, и пора
     * предложить отдельное окно. Проверяется на входе в экран входа.
     */
    public static function cookiesBlocked(): bool
    {
        return ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe'
            && empty($_SESSION['oauth_request'])
            && self::request() !== null;
    }
}
