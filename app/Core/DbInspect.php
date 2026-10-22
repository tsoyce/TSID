<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Что известно о базе данных (v4.2).
 *
 * До этого узнать хоть что-то о базе можно было только открыв config.php по
 * SSH: какой драйвер, куда подключены, сколько занимает, все ли миграции
 * применены. Состояние надо видеть до того, как что-то менять, — особенно
 * когда «менять» означает переключение на другую базу.
 *
 * Проверка чужого подключения (`probe`) ничего не переключает и рабочее
 * соединение не трогает: она открывает второй PDO, спрашивает и закрывает.
 * Именно это делает страшную операцию обычной — сначала убедился, потом
 * переключил.
 */
final class DbInspect
{
    /** Состояние текущего подключения. Ошибки не бросаем: карточка обязана открыться. */
    public static function current(): array
    {
        $cfg = (array)Config::get('db', []);
        $out = [
            'driver' => (string)($cfg['driver'] ?? 'mysql'),
            'host' => (string)($cfg['host'] ?? ''),
            'port' => (int)($cfg['port'] ?? 0),
            'name' => (string)($cfg['name'] ?? ''),
            'prefix' => (string)($cfg['prefix'] ?? ''),
            'ok' => false, 'version' => null, 'ping_ms' => null,
            'tables' => 0, 'size' => null, 'rows' => [], 'error' => null,
        ];
        try {
            $pdo = DB::pdo();
            /* Меряем запрос, а не получение соединения: к этому моменту оно уже
               открыто этим же запросом, и «0 мс» было бы не измерением, а нулём
               по построению. */
            $t0 = microtime(true);
            $pdo->query('SELECT 1')->fetchColumn();
            $out['ping_ms'] = round((microtime(true) - $t0) * 1000, 1);
            $out['ok'] = true;
            $out['version'] = self::version($pdo, $out['driver']);
            $out['tables'] = count(self::tables($pdo, $out['driver'], $out['prefix']));
            $out['size'] = self::size($pdo, $out['driver'], $out['name']);
            $out['rows'] = self::rowCounts($pdo, $out['prefix']);
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    /**
     * Проверить чужие реквизиты, ничего не меняя.
     *
     * Возвращает список проверок: каждая — [пройдена, что проверяли, подробности].
     * Порядок важен: сначала «соединились ли вообще», потом всё остальное —
     * иначе человек читает пять красных строк вместо одной понятной.
     */
    public static function probe(array $cfg): array
    {
        $checks = [];
        $prefix = (string)($cfg['prefix'] ?? '');
        try {
            $pdo = self::connect($cfg);
        } catch (\Throwable $e) {
            return [['ok' => false, 'what' => 'Соединение', 'detail' => $e->getMessage()]];
        }
        $driver = (string)($cfg['driver'] ?? 'mysql');
        $checks[] = ['ok' => true, 'what' => 'Соединение',
            'detail' => (string)self::version($pdo, $driver)];

        $tables = self::tables($pdo, $driver, $prefix);
        $checks[] = ['ok' => $tables !== [], 'what' => 'Таблицы с префиксом «' . $prefix . '»',
            'detail' => $tables === [] ? 'не найдены — база пустая или префикс другой'
                                       : count($tables) . ' шт.'];
        if ($tables === []) return $checks;

        /* Расхождение миграций — самая частая беда при переключении: база
           скопирована месяц назад, а код с тех пор ушёл вперёд. */
        $theirs = self::migrations($pdo, $prefix);
        $ours = Migrations::applied();
        $missing = array_values(array_diff($ours, $theirs));
        $extra = array_values(array_diff($theirs, $ours));
        $checks[] = ['ok' => $missing === [] && $extra === [], 'what' => 'Миграции',
            'detail' => $missing === [] && $extra === []
                ? 'совпадают (' . count($theirs) . ')'
                : trim(($missing !== [] ? 'нет: ' . implode(', ', $missing) . '. ' : '')
                     . ($extra !== [] ? 'лишние: ' . implode(', ', $extra) : ''))];

        /* Владелец в чужой базе — признак того, что это вообще наша база,
           а не соседний проект с тем же префиксом. */
        $ownerId = Owner::id();
        $found = false;
        if ($ownerId !== null) {
            try {
                $st = $pdo->prepare('SELECT id FROM `' . $prefix . 'users` WHERE id = ?');
                $st->execute([$ownerId]);
                $found = $st->fetch() !== false;
            } catch (\Throwable) {
                $found = false;
            }
        }
        $checks[] = ['ok' => $found, 'what' => 'Владелец на месте',
            'detail' => $found ? 'аккаунт #' . $ownerId . ' найден'
                               : 'аккаунта владельца в этой базе нет — это чужие данные'];
        return $checks;
    }

    /** Отдельное соединение: рабочее не подменяем и не закрываем. */
    public static function connect(array $cfg): PDO
    {
        $driver = (string)($cfg['driver'] ?? 'mysql');
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                 PDO::ATTR_TIMEOUT => 5];
        $dsn = Dsn::build($cfg);
        if ($driver === 'sqlite') return new PDO($dsn, null, null, $opts);
        return new PDO($dsn, (string)($cfg['user'] ?? ''), (string)($cfg['pass'] ?? ''), $opts);
    }

    private static function version(PDO $pdo, string $driver): ?string
    {
        try {
            if ($driver === 'sqlite') {
                return 'SQLite ' . $pdo->query('SELECT sqlite_version()')->fetchColumn();
            }
            return 'MySQL ' . $pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private static function tables(PDO $pdo, string $driver, string $prefix): array
    {
        try {
            $rows = $driver === 'sqlite'
                ? $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN)
                : $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ((array)$rows as $t) {
            if ($prefix === '' || str_starts_with((string)$t, $prefix)) $out[] = (string)$t;
        }
        return $out;
    }

    /** @return list<string> */
    private static function migrations(PDO $pdo, string $prefix): array
    {
        try {
            $rows = $pdo->query('SELECT version FROM `' . $prefix . 'migrations`')
                ->fetchAll(PDO::FETCH_COLUMN);
            return array_map('strval', (array)$rows);
        } catch (\Throwable) {
            return [];
        }
    }

    private static function size(PDO $pdo, string $driver, string $name): ?int
    {
        try {
            if ($driver === 'sqlite') {
                $f = $name !== '' && $name[0] !== '/' ? SSO_ROOT . '/' . $name : $name;
                $s = @filesize($f);
                return $s === false ? null : (int)$s;
            }
            $st = $pdo->prepare('SELECT SUM(data_length + index_length) FROM information_schema.tables
                                  WHERE table_schema = ?');
            $st->execute([$name]);
            $v = $st->fetchColumn();
            return $v === false || $v === null ? null : (int)$v;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Строки в таблицах, за которыми стоит следить: они растут сами и именно
     * они распухают. Точный счёт, а не оценка из information_schema: разница
     * между «миллион» и «полтора» здесь и есть повод для чистки.
     *
     * @return array<string, int>
     */
    private static function rowCounts(PDO $pdo, string $prefix): array
    {
        $out = [];
        foreach (['users', 'sessions', 'audit_log', 'mail_queue', 'one_time_tokens'] as $t) {
            try {
                $out[$t] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $prefix . $t . '`')->fetchColumn();
            } catch (\Throwable) {
                // таблицы может не быть на отстающей схеме — это не повод падать
            }
        }
        arsort($out);
        return $out;
    }
}
