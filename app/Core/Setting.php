<?php

declare(strict_types=1);

namespace App\Core;

/** Настройки из БД ({settings}) с кэшем на запрос. Правятся в /admin/settings. */
final class Setting
{
    private static ?array $cache = null;

    private static function load(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            if (Config::installed()) {
                try {
                    foreach (DB::all('SELECT `key`, `value` FROM {settings}') as $row) {
                        self::$cache[$row['key']] = $row['value'];
                    }
                } catch (DbUnavailable $e) {
                    /* Соединения нет — это не «отстала схема», и делать вид,
                       что всё в порядке, нельзя: страницы отрисуются на
                       файловых дефолтах, отдадут 200, а упадут только POST-ы.
                       Пусть падает сразу и честно. */
                    self::$cache = null;
                    throw $e;
                } catch (\Throwable) {
                    // Таблицы settings ещё нет (миграция не применена) — дефолты
                }
            }
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::load();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function getInt(string $key, int $default): int
    {
        $v = self::get($key);
        return is_numeric($v) ? (int)$v : $default;
    }

    public static function getJson(string $key, array $default = []): array
    {
        $v = self::get($key);
        if (!is_string($v) || $v === '') return $default;
        $decoded = json_decode($v, true);
        return is_array($decoded) ? $decoded : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        DB::upsert('settings', ['key' => $key, 'value' => (string)$value, 'updated_at' => now()],
            ['value' => (string)$value, 'updated_at' => now()], 'key');
        if (self::$cache !== null) self::$cache[$key] = (string)$value;
    }

    public static function delete(string $key): void
    {
        DB::run('DELETE FROM {settings} WHERE `key` = ?', [$key]);
        unset(self::$cache[$key]);
    }

    /**
     * Забыть прочитанное и взять из базы заново.
     *
     * Кэш живёт на процесс, и это правильно для веб-запроса. Но там, где
     * настройки меняет кто-то другой — соседний процесс, HTTP-запрос из теста,
     * CLI-задача, — прочитанное становится враньём. Явный сброс честнее, чем
     * отказ от кэша: на каждой странице настроек читаются десятки ключей.
     */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /* Политики безопасности с дефолтами из §8.1 (правятся в админке). */
    public static function passwordMin(): int { return self::getInt('policy.password_min', (int)config('security.password_min', 12)); }
    public static function lockoutAttempts(): int { return self::getInt('policy.lockout_attempts', (int)config('security.lockout_attempts', 10)); }
    public static function lockoutMinutes(): int { return self::getInt('policy.lockout_minutes', (int)config('security.lockout_minutes', 15)); }
    public static function sessionLifetime(): int { return self::timeout('session', (int)config('session.lifetime', 43200)); }

    /**
     * Сроки жизни, все в секундах.
     *
     * До v4.2 они лежали кто где: сессия — в политиках, срок access-токена
     * числом 900 в TokenFlow, сроки ссылок — константами в OneTimeToken.
     * Укоротить их без правки кода было нельзя, а именно укорачивать и просят.
     * Ключ `timeout.session` унаследован от `policy.session_lifetime`, чтобы
     * уже настроенные установки не откатились к умолчанию.
     */
    public static function timeout(string $key, int $default): int
    {
        if ($key === 'session') {
            $legacy = self::get('policy.session_lifetime');
            if ($legacy !== null && self::get('timeout.session') === null) return (int)$legacy;
        }
        return self::getInt('timeout.' . $key, $default);
    }
    public static function appName(): string { return (string)self::get('branding.name', config('app.name', 'SSO')); }

    /**
     * Часовой пояс установки.
     *
     * Задавался только в файле конфигурации — то есть по ssh. При этом он
     * решает, в каком времени видит даты человек, не выбравший свой пояс, и в
     * каком времени приходят письма. Настройка перекрывает файл; файл остаётся
     * запасным значением, чтобы установка без базы всё равно знала, который час.
     */
    public static function timezone(): string
    {
        $tz = (string)self::get('app.timezone', '');
        if ($tz !== '' && array_key_exists($tz, tz_options())) return $tz;
        return (string)config('app.timezone', 'Europe/Moscow');
    }
}
