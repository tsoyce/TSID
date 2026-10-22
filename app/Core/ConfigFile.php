<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Запись config/config.php (v4.2).
 *
 * Раньше генератор жил в `install/helpers.php`. Это было нормально, пока конфиг
 * писали один раз при установке, — но каталог `/install` положено удалять после
 * неё, а системному разделу конфиг нужно переписывать: смена домена, смена
 * базы. Генератор переехал сюда, инсталлятор зовёт его же: двух форматов
 * конфига в проекте быть не должно.
 *
 * Файл перерисовывается целиком из значений, а не правится по месту: разбирать
 * PHP регулярками ради «поменять одну строчку» — способ однажды получить файл,
 * который не парсится. Плата — потерянные ручные комментарии; значения при
 * этом сохраняются все, включая те, которых нет в интерфейсе.
 */
final class ConfigFile
{
    public static function path(): string
    {
        return SSO_ROOT . '/config/config.php';
    }

    /**
     * Текущие значения в том виде, в каком их принимает render().
     * Читаем из Config, а не из файла: так работает и при первой установке.
     */
    public static function current(): array
    {
        return [
            'app_name' => (string)Config::get('app.name', 'SSO'),
            'issuer' => (string)Config::get('app.url', ''),
            'app_key' => (string)Config::get('app.key', ''),
            'env' => (string)Config::get('app.env', 'production'),
            'timezone' => (string)Config::get('app.timezone', 'Europe/Moscow'),
            'locale' => (string)Config::get('app.locale', 'ru'),
            'db' => [
                'driver' => (string)Config::get('db.driver', 'mysql'),
                'host' => (string)Config::get('db.host', 'localhost'),
                'port' => (int)Config::get('db.port', 3306),
                'name' => (string)Config::get('db.name', ''),
                'user' => (string)Config::get('db.user', ''),
                'pass' => (string)Config::get('db.pass', ''),
                'prefix' => (string)Config::get('db.prefix', 'sso_'),
                'charset' => (string)Config::get('db.charset', 'utf8mb4'),
            ],
            'session' => [
                'name' => (string)Config::get('session.name', 'sso'),
                'lifetime' => (int)Config::get('session.lifetime', 43200),
                'remember_lifetime' => (int)Config::get('session.remember_lifetime', 2592000),
                'cookie_domain' => (string)Config::get('session.cookie_domain', ''),
            ],
            'kid' => (string)Config::get('keys.active_kid', ''),
            'mail' => [
                'driver' => (string)Config::get('mail.driver', 'smtp'),
                'host' => (string)Config::get('mail.host', ''),
                'port' => (int)Config::get('mail.port', 587),
                'encryption' => (string)Config::get('mail.encryption', 'tls'),
                'user' => (string)Config::get('mail.user', ''),
                'pass' => (string)Config::get('mail.pass', ''),
                'from_email' => (string)(Config::get('mail.from')[0] ?? ''),
            ],
            'security' => [
                'password_min' => (int)Config::get('security.password_min', 12),
                'lockout_attempts' => (int)Config::get('security.lockout_attempts', 10),
                'lockout_minutes' => (int)Config::get('security.lockout_minutes', 15),
            ],
            'cron_token' => (string)Config::get('cron.token', ''),
        ];
    }

    public static function render(array $d): string
    {
        $tpl = <<<'PHP'
<?php
/** SSO — конфиг, записан %DATE%. Права: 0640, вне git. */
return [
  'app' => [
    'name'     => %APP_NAME%,
    'url'      => %ISSUER%,
    'key'      => %APP_KEY%,
    'env'      => %ENV%,              // production|debug
    'timezone' => %TZ%,
    'locale'   => %LOCALE%,
  ],
  'db' => [
    'driver' => %DB_DRIVER%,
    'host' => %DB_HOST%, 'port' => %DB_PORT%, 'name' => %DB_NAME%,
    'user' => %DB_USER%, 'pass' => %DB_PASS%,
    'prefix' => %DB_PREFIX%, 'charset' => %DB_CHARSET%,
  ],
  'session' => [
    'name' => %SESS_NAME%, 'lifetime' => %SESS_TTL%,
    'remember_lifetime' => %SESS_REMEMBER%,
    'cookie_domain' => %SESS_DOMAIN%,             // пусто = текущий хост
  ],
  'keys' => [ 'dir' => __DIR__ . '/../storage/keys', 'active_kid' => %KID% ],
  'mail' => [
    'driver' => %MAIL_DRIVER%,                    // smtp|log
    'host' => %SMTP_HOST%, 'port' => %SMTP_PORT%, 'encryption' => %SMTP_ENC%,
    'user' => %SMTP_USER%, 'pass' => %SMTP_PASS%,
    'from' => [%FROM_EMAIL%, %APP_NAME%],
  ],
  'security' => [
    'password_min' => %PW_MIN%, 'lockout_attempts' => %LOCK_N%, 'lockout_minutes' => %LOCK_MIN%,
  ],
  'cron' => [ 'token' => %CRON_TOKEN% ],
];
PHP;
        $e = static fn(mixed $v): string => var_export($v, true);
        $db = (array)($d['db'] ?? []);
        $s = (array)($d['session'] ?? []);
        $m = (array)($d['mail'] ?? []);
        $sec = (array)($d['security'] ?? []);
        return strtr($tpl, [
            '%DATE%' => gmdate('Y-m-d H:i') . ' UTC',
            '%APP_NAME%' => $e((string)($d['app_name'] ?? 'SSO')),
            '%ISSUER%' => $e((string)($d['issuer'] ?? '')),
            '%APP_KEY%' => $e((string)($d['app_key'] ?? '')),
            '%ENV%' => $e((string)($d['env'] ?? 'production')),
            '%TZ%' => $e((string)($d['timezone'] ?? 'Europe/Moscow')),
            '%LOCALE%' => $e((string)($d['locale'] ?? 'ru')),
            '%DB_DRIVER%' => $e((string)($db['driver'] ?? 'mysql')),
            '%DB_HOST%' => $e((string)($db['host'] ?? 'localhost')),
            '%DB_PORT%' => $e((int)($db['port'] ?? 3306)),
            '%DB_NAME%' => $e((string)($db['name'] ?? '')),
            '%DB_USER%' => $e((string)($db['user'] ?? '')),
            '%DB_PASS%' => $e((string)($db['pass'] ?? '')),
            '%DB_PREFIX%' => $e((string)($db['prefix'] ?? 'sso_')),
            '%DB_CHARSET%' => $e((string)($db['charset'] ?? 'utf8mb4')),
            '%SESS_NAME%' => $e((string)($s['name'] ?? 'sso')),
            '%SESS_TTL%' => $e((int)($s['lifetime'] ?? 43200)),
            '%SESS_REMEMBER%' => $e((int)($s['remember_lifetime'] ?? 2592000)),
            '%SESS_DOMAIN%' => $e((string)($s['cookie_domain'] ?? '')),
            '%KID%' => $e((string)($d['kid'] ?? '')),
            '%MAIL_DRIVER%' => $e((string)($m['driver'] ?? 'smtp')),
            '%SMTP_HOST%' => $e((string)($m['host'] ?? '')),
            '%SMTP_PORT%' => $e((int)($m['port'] ?? 587)),
            '%SMTP_ENC%' => $e((string)($m['encryption'] ?? 'tls')),
            '%SMTP_USER%' => $e((string)($m['user'] ?? '')),
            '%SMTP_PASS%' => $e((string)($m['pass'] ?? '')),
            '%FROM_EMAIL%' => $e((string)($m['from_email'] ?? '')
                ?: 'no-reply@' . (parse_url((string)($d['issuer'] ?? ''), PHP_URL_HOST) ?: 'localhost')),
            '%PW_MIN%' => $e((int)($sec['password_min'] ?? 12)),
            '%LOCK_N%' => $e((int)($sec['lockout_attempts'] ?? 10)),
            '%LOCK_MIN%' => $e((int)($sec['lockout_minutes'] ?? 15)),
            '%CRON_TOKEN%' => $e((string)($d['cron_token'] ?? '')),
        ]);
    }

    public static function write(array $d): void
    {
        file_put_contents(self::path(), self::render($d));
        @chmod(self::path(), 0640);
    }

    /** Копия перед изменением. Возвращает путь или '' — файла ещё нет. */
    public static function backup(): string
    {
        if (!is_file(self::path())) return '';
        /* Имя не должно повторяться — иначе вторая правка затирает копию первой,
           и откатываться уже не к чему. Полагаться на точность часов тут нельзя
           ни в минутах, ни в секундах: правки идут подряд. Занято — добавляем
           счётчик, и гарантия становится свойством имени, а не везения. */
        $base = SSO_ROOT . '/config/config.php.bak-' . gmdate('Ymd-His');
        $to = $base;
        for ($i = 2; file_exists($to) && $i < 100; $i++) $to = $base . '-' . $i;
        return @copy(self::path(), $to) ? $to : '';
    }

    /**
     * Изменить часть значений, сохранив остальные.
     *
     * Сначала рендерим и проверяем, что получившийся файл — валидный PHP,
     * и только потом пишем. Записать сломанный конфиг значит уронить установку
     * целиком, а не «получить ошибку в интерфейсе».
     *
     * @return string путь к резервной копии
     * @throws \RuntimeException если результат не разбирается
     */
    public static function update(array $changes): string
    {
        $data = array_replace_recursive(self::current(), $changes);
        $php = self::render($data);

        $tmp = SSO_ROOT . '/storage/config-check-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($tmp, $php);
        $parsed = @include $tmp;
        @unlink($tmp);
        if (!is_array($parsed) || !isset($parsed['app']['url'], $parsed['db']['driver'])) {
            throw new \RuntimeException('Новый конфиг не разбирается — файл не тронут');
        }

        $backup = self::backup();
        file_put_contents(self::path(), $php);
        @chmod(self::path(), 0640);
        return $backup;
    }
}
