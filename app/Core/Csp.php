<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Политика безопасности содержимого — одна на запрос, в одном месте.
 *
 * Зачем отдельный класс ради одного заголовка. `form-action` стоял `'self'` на
 * всех страницах, и на живой установке это обрывало главное, что SSO делает:
 * возврат на портал. Форма согласия уходит на /consent, ответ — 302 на
 * /authorize, дальше 302 на портал, а Chrome сверяет form-action с КАЖДЫМ шагом
 * редиректа. Чужой origin — и цепочка обрывается молча: кнопка «Продолжить»
 * крутит спиннер вечно, ошибки нет нигде, кроме консоли. Так же обрывались вход
 * паролем с возвратом в сервис, «Не сейчас» и выход с возвратом на портал.
 * Проходил только тот, кто уже вошёл: у него возврат идёт GET-редиректом, без
 * формы. Все проверки ходили через curl, а curl CSP не исполняет.
 *
 * Правило: `form-action` пускает на `'self'` и на origin портала, КУДА ЧЕЛОВЕК
 * СЕЙЧАС ВОЗВРАЩАЕТСЯ. Origin берётся только оттуда, куда адрес попадает уже
 * проверенным байт-в-байт по списку портала: запрос авторизации в сессии
 * (`oauth_request`, `consent_request`) и ключ возврата `rt`, лежащий в базе.
 * Подделать ключ нельзя — несуществующий ничего не добавит. Вне потока входа
 * политика та же, что и была.
 *
 * Страница, у которой свой проверенный адрес (подтверждение выхода с возвратом
 * на портал), добавляет его сама — `allowFormTarget()` переотправит заголовок.
 */
final class Csp
{
    /** @var array<string, list<string>> директива => источники, в порядке вывода */
    private static array $directives = [];

    /**
     * Задать политику запроса и отправить её.
     *
     * @param array<string, list<string>> $directives всё, кроме form-action
     */
    public static function start(array $directives): void
    {
        self::$directives = $directives;
        self::$directives['form-action'] = array_values(array_unique(
            array_merge(["'self'"], self::pendingReturnOrigins())));
        self::send();
        /* Вход окном на странице портала (v4.73): экраны входа можно встроить
           ровно тому порталу, с которого идёт вход во фрейме. База может быть
           недоступна — тогда фрейма нет, а честный ответ 503 ниже важнее. */
        try {
            $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            if (Config::installed() && Framed::isFlowPath($path)) Framed::allowFraming();
        } catch (\Throwable) {
            // остаётся запрет фреймов
        }
    }

    /** Разрешить формам этой страницы уводить на origin проверенного адреса. */
    public static function allowFormTarget(string $uri): void
    {
        $origin = self::origin($uri);
        if ($origin === null) return;
        $current = self::$directives['form-action'] ?? ["'self'"];
        if (in_array($origin, $current, true)) return;
        $current[] = $origin;
        self::$directives['form-action'] = $current;
        self::send();
    }

    /**
     * Добавить источник в директиву этой страницы и переотправить заголовок.
     *
     * Для страниц, которым нужно чуть больше общей политики: редактор экрана
     * входа показывает предпросмотр во фрейме со своего же адреса (v4.71).
     */
    public static function allow(string $directive, string $source): void
    {
        $current = self::$directives[$directive] ?? [];
        if (in_array($source, $current, true)) return;
        $current[] = $source;
        self::$directives[$directive] = $current;
        self::send();
    }

    /**
     * Встраивание этой страницы во фрейм — только своими страницами.
     *
     * Общая политика запрещает фреймы вовсе: экран входа во фрейме чужого сайта
     * — классический clickjacking. Предпросмотр редактора показывается во
     * фрейме админки, и ему, и только ему, разрешено 'self' (v4.71).
     */
    public static function allowSelfFraming(): void
    {
        self::$directives['frame-ancestors'] = ["'self'"];
        if (!headers_sent()) header('X-Frame-Options: SAMEORIGIN');
        self::send();
    }

    /**
     * Встраивание этой страницы — только указанными адресами (v4.73).
     *
     * X-Frame-Options убирается: списка адресов он не умеет, а DENY рядом с
     * разрешающим frame-ancestors читался бы браузерами по-разному.
     *
     * @param list<string> $origins
     */
    public static function setFrameAncestors(array $origins): void
    {
        self::$directives['frame-ancestors'] = $origins;
        if (!headers_sent()) header_remove('X-Frame-Options');
        self::send();
    }

    /** Заголовок целиком — тем же порядком директив, что задан. */
    public static function header(): string
    {
        $parts = [];
        foreach (self::$directives as $name => $sources) {
            $parts[] = $name . ' ' . implode(' ', $sources);
        }
        return implode('; ', $parts);
    }

    /** Origin адреса: схема, хост и порт. Только http(s) — остальное не адрес возврата. */
    public static function origin(string $uri): ?string
    {
        $u = parse_url($uri);
        if (!is_array($u) || empty($u['host'])) return null;
        $scheme = strtolower((string)($u['scheme'] ?? ''));
        if (!in_array($scheme, ['https', 'http'], true)) return null;
        /* Хост идёт в заголовок как есть: пробел или точка с запятой в нём
           разорвали бы политику на части. parse_url такого не пропускает, но
           заголовок безопасности не место для доверия на слово. */
        $host = strtolower((string)$u['host']);
        if (!preg_match('~^[a-z0-9.\-\[\]:]+$~', $host)) return null;
        return $scheme . '://' . $host . (isset($u['port']) ? ':' . (int)$u['port'] : '');
    }

    /** @return list<string> origins порталов, куда человек сейчас возвращается */
    private static function pendingReturnOrigins(): array
    {
        $out = [];
        foreach (['oauth_request', 'consent_request'] as $key) {
            $origin = self::origin((string)($_SESSION[$key]['redirect_uri'] ?? ''));
            if ($origin !== null) $out[] = $origin;
        }
        /* Ключ возврата — страховка на случай потерянной сессии. Смотрим, не
           гася: это тот же запрос, и он ещё понадобится форме. */
        $rt = Resume::fromRequest();
        if ($rt !== '') {
            $q = Resume::peek($rt);
            $origin = self::origin((string)($q['redirect_uri'] ?? ''));
            if ($origin !== null) $out[] = $origin;
        }
        return array_values(array_unique($out));
    }

    private static function send(): void
    {
        if (!headers_sent()) header('Content-Security-Policy: ' . self::header());
    }
}
