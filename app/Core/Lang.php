<?php

declare(strict_types=1);

namespace App\Core;

/**
 * i18n: плоские словари в app/Lang/{code}.php.
 * Язык выбирается по цепочке: явный выбор в сессии → профиль пользователя →
 * ui_locales из OIDC-запроса → Accept-Language → язык инсталляции.
 * Отсутствующий ключ возвращается как есть — интерфейс не ломается на полпути.
 */
final class Lang
{
    public const SUPPORTED = ['ru' => 'Русский', 'en' => 'English'];

    private static ?string $current = null;
    private static array $dict = [];

    public static function current(): string
    {
        if (self::$current !== null) return self::$current;

        $candidates = [];
        if (!empty($_SESSION['lang'])) $candidates[] = (string)$_SESSION['lang'];
        if (Config::installed()) {
            try {
                $u = Auth::user();
                if ($u && !empty($u['locale'])) $candidates[] = (string)$u['locale'];
            } catch (\Throwable) {
                // до установки/при сбое БД просто идём дальше по цепочке
            }
        }
        if (!empty($_SESSION['oauth_request']['ui_locales'])) {
            foreach (preg_split('~\s+~', (string)$_SESSION['oauth_request']['ui_locales']) ?: [] as $l) {
                $candidates[] = substr($l, 0, 2);
            }
        }
        foreach (self::fromHeader() as $l) $candidates[] = $l;
        $candidates[] = (string)config('app.locale', 'ru');

        foreach ($candidates as $c) {
            $c = strtolower(substr($c, 0, 2));
            if (isset(self::SUPPORTED[$c])) return self::$current = $c;
        }
        return self::$current = 'ru';
    }

    /** @return string[] коды из Accept-Language по убыванию q */
    private static function fromHeader(): array
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if ($header === '') return [];
        $out = [];
        foreach (explode(',', $header) as $part) {
            $bits = explode(';q=', trim($part));
            $out[substr($bits[0], 0, 2)] = (float)($bits[1] ?? 1);
        }
        arsort($out);
        return array_keys($out);
    }

    public static function set(string $code): void
    {
        $code = strtolower(substr($code, 0, 2));
        if (!isset(self::SUPPORTED[$code])) return;
        $_SESSION['lang'] = $code;
        self::$current = $code;
        self::$dict = [];
    }

    /**
     * Выполнить на другом языке — не трогая выбор человека в сессии (v4.74).
     *
     * Письмо отрисовывается на языке получателя: администратор, отправивший
     * ссылку сброса англоязычному человеку, не должен присылать ему русское
     * письмо. set() для этого не годится — он записывает выбор в сессию и
     * переключил бы язык самому администратору. Язык не из списка — как есть.
     */
    public static function using(?string $code, callable $fn): mixed
    {
        $code = strtolower(substr((string)$code, 0, 2));
        if (!isset(self::SUPPORTED[$code])) return $fn();
        $prev = self::$current;
        self::$current = $code;
        try {
            return $fn();
        } finally {
            self::$current = $prev;
        }
    }

    /**
     * Перевод. `:name` в строке заменяется значением из $params.
     * Ключа нет — возвращаем сам ключ: лучше английский идентификатор,
     * чем пустое место или падение.
     */
    public static function t(string $key, array $params = []): string
    {
        $lang = self::current();
        if (!isset(self::$dict[$lang])) {
            $file = SSO_ROOT . '/app/Lang/' . $lang . '.php';
            self::$dict[$lang] = is_file($file) ? (array)require $file : [];
            // Русский — базовый словарь: то, что не переведено, покажем на нём
            if ($lang !== 'ru' && !isset(self::$dict['ru'])) {
                $ru = SSO_ROOT . '/app/Lang/ru.php';
                self::$dict['ru'] = is_file($ru) ? (array)require $ru : [];
            }
        }
        $text = self::$dict[$lang][$key] ?? (self::$dict['ru'][$key] ?? $key);
        foreach ($params as $k => $v) {
            $text = str_replace(':' . $k, (string)$v, $text);
        }
        return $text;
    }
}
