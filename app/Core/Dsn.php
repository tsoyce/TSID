<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Строка подключения к базе — в одном месте (v4.4.8).
 *
 * Она собиралась тремя одинаковыми `sprintf` в трёх файлах: рабочее
 * подключение (DB), проверка чужой базы перед переносом (DbInspect) и
 * инсталлятор. Пока формат один, три копии выглядят безобидно — расходятся
 * они в тот день, когда к строке добавляют параметр. Добавят его в одном
 * месте, а сломается в другом: инсталлятор скажет «база доступна», рабочее
 * подключение — нет, и разбирать это придётся по различию в строке, которого
 * никто не ожидает.
 *
 * Здесь только чистое преобразование «массив → строка»: ни конфига, ни
 * состояния. Поэтому файл можно звать и из инсталлятора, который намеренно
 * живёт до появления конфигурации.
 */
final class Dsn
{
    /**
     * @param array<string, mixed> $cfg ключи driver, host, port, name, charset
     */
    public static function build(array $cfg): string
    {
        if ((string)($cfg['driver'] ?? 'mysql') === 'sqlite') {
            return 'sqlite:' . (string)($cfg['name'] ?? '');
        }
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string)($cfg['host'] ?? '') !== '' ? (string)$cfg['host'] : 'localhost',
            (int)($cfg['port'] ?? 0) ?: 3306,
            (string)($cfg['name'] ?? ''),
            (string)($cfg['charset'] ?? '') !== '' ? (string)$cfg['charset'] : 'utf8mb4'
        );
    }

    /**
     * Настройки PDO, общие для всех подключений.
     *
     * Исключения вместо кодов ошибок и ассоциативные массивы — то, на что
     * рассчитан весь остальной код. Разойдись это между подключениями, и
     * запрос, отработавший в одном месте, молча вернул бы не то в другом.
     *
     * @return array<int, mixed>
     */
    public static function options(): array
    {
        return [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
    }
}
