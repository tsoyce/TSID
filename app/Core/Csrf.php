<?php

declare(strict_types=1);

namespace App\Core;

/** CSRF: токен в PHP-сессии + проверка Origin на POST (§8.2). */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = Crypto::randomToken(32);
        }
        return $_SESSION['_csrf'];
    }

    public static function check(?string $token): bool
    {
        return is_string($token) && $token !== ''
            && hash_equals($_SESSION['_csrf'] ?? '', $token);
    }

    /** Общая проверка POST-запроса: CSRF-токен + Origin. 419 при провале. */
    public static function verifyRequest(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            $self = parse_url(base_url(), PHP_URL_HOST);
            $got = parse_url($origin, PHP_URL_HOST);
            if ($self !== null && $got !== null && !hash_equals($self, (string)$got)) {
                ErrorPage::render(419);
            }
        }
        if (!self::check($_POST['_csrf'] ?? null)) {
            // fetch-запросам (passkeys, AJAX-проверки) — JSON, а не HTML-страница
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '') {
                json_response(['error' => 'csrf', 'message' => t('Сессия формы истекла — обновите страницу.')], 419);
            }
            ErrorPage::render(419);
        }
    }
}
