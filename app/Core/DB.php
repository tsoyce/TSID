<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Тонкая PDO-обёртка. В SQL таблицы пишутся как {users} — префикс подставляется здесь.
 * Прод — MySQL 8 / MariaDB; driver=sqlite поддержан для локальной разработки и тестов.
 */
final class DB
{
    private static ?PDO $pdo = null;
    private static ?string $driverOverride = null;
    private static ?string $prefixOverride = null;

    /**
     * Принять готовое соединение — только для установщика.
     *
     * На третьем шаге установки конфига ещё нет: реквизиты базы лежат в данных
     * мастера, а PDO установщик открывает сам. Из-за этого PHP-шаги миграций
     * там не выполнялись вовсе — установщик гонял только .sql. Пока PHP-шаги
     * лишь досыпали данные (номера аккаунтов, владелец), это не стреляло: на
     * пустой базе досыпать нечего. Первый же шаг, меняющий схему (017), встал
     * молча: версия записана, а колонка прежняя.
     */
    public static function adopt(PDO $pdo, string $driver, string $prefix): void
    {
        self::$pdo = $pdo;
        self::$driverOverride = $driver;
        self::$prefixOverride = $prefix;
        /* Знание о колонках принадлежит соединению, а не процессу. Перенос
           базы и установщик подменяют соединение на другое — с другой схемой,
           — и запомненный ответ «колонка есть» стал бы враньём про приёмник. */
        self::$columnCache = [];
        self::$tableCache = [];
    }

    /**
     * Вернуть всё как было: следующий запрос снова откроет соединение по конфигу.
     *
     * Обязательна в паре с adopt(). Перенос базы вызывает миграции для ПРИЁМНИКА
     * из живого приложения, и без возврата всё, что шло после переноса, уходило
     * бы в чужую базу — включая проверки, которые этот перенос и проверяют.
     */
    public static function release(): void
    {
        self::$pdo = null;
        self::$driverOverride = null;
        self::$prefixOverride = null;
        self::$columnCache = [];
        self::$tableCache = [];
    }

    /** @var array<string, bool> «таблица.колонка» → есть ли она. Сбрасывается со сменой соединения. */
    private static array $columnCache = [];

    /** @var array<string, bool> логическое имя таблицы → есть ли она. Тот же срок жизни. */
    private static array $tableCache = [];

    /**
     * Соединение. Неудача открытия — DbUnavailable, а не голое PDOException.
     *
     * Разница принципиальна: ошибку запроса («нет такой колонки») приложение
     * обязано переживать — код обновили, миграцию ещё не применили. Отсутствие
     * соединения переживать нельзя, но до v4.2 оба случая ловились одинаково,
     * и мёртвая установка выглядела работающей.
     */
    public static function pdo(): PDO
    {
        try {
            return self::connect();
        } catch (\PDOException $e) {
            throw new DbUnavailable($e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    private static function connect(): PDO
    {
        if (self::$pdo === null) {
            $driver = Config::get('db.driver', 'mysql');
            if ($driver === 'sqlite') {
                $dsn = Dsn::build(['driver' => 'sqlite', 'name' => Config::get('db.name')]);
                self::$pdo = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                self::$pdo->exec('PRAGMA foreign_keys = ON');
                self::$pdo->exec('PRAGMA busy_timeout = 5000');
            } else {
                $dsn = Dsn::build([
                    'driver' => 'mysql',
                    'host' => Config::get('db.host', 'localhost'),
                    'port' => Config::get('db.port', 3306),
                    'name' => Config::get('db.name'),
                    'charset' => Config::get('db.charset', 'utf8mb4'),
                ]);
                self::$pdo = new PDO($dsn, (string)Config::get('db.user'), (string)Config::get('db.pass'), [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    /* Буферизация явно. Без неё незакрытый результат запирает
                       соединение: следующий запрос падает с «Cannot execute
                       queries while other unbuffered queries are active». Так
                       упала миграция 023 — SELECT в .sql оставил открытый
                       набор строк, и PHP-шаг следом не выполнился. Причину
                       того случая убрали, но зависеть от того, что никто
                       больше не оставит результат незакрытым, нельзя. */
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES'",
                ]);
            }
        }
        return self::$pdo;
    }

    public static function driver(): string
    {
        return self::$driverOverride ?? (string)Config::get('db.driver', 'mysql');
    }

    /** {users} → sso_users. */
    public static function sql(string $sql): string
    {
        $prefix = self::$prefixOverride ?? (string)Config::get('db.prefix', 'sso_');
        return preg_replace('~\{([a-z_]+)\}~', $prefix . '$1', $sql);
    }

    /**
     * Есть ли таблица. Нужна там, где код обгоняет схему: код обновили, а
     * миграцию ещё не применили. Без такой проверки страница отдавала 500,
     * и /admin/tools — единственное место, где миграции запускаются, — падал
     * вместе с остальными, то есть выбраться из состояния было нельзя.
     *
     * @param string $logical логическое имя без префикса: 'orgs', 'webhooks'
     */
    public static function tableExists(string $logical): bool
    {
        $cache = &self::$tableCache;
        if (isset($cache[$logical])) return $cache[$logical];

        $name = self::sql('{' . $logical . '}');
        try {
            if (self::driver() === 'sqlite') {
                $found = self::pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
            } else {
                $found = self::pdo()->prepare(
                    'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            }
            $found->execute([$name]);
            return $cache[$logical] = $found->fetchColumn() !== false;
        } catch (\Throwable) {
            /* БД недоступна — пусть об этом расскажет вызов настоящего запроса */
            return false;
        }
    }

    /**
     * Есть ли колонка в таблице. Тот же смысл, что у tableExists, но на уровень
     * мельче: миграция может добавлять колонку в давно существующую таблицу, и
     * тогда наличие таблицы ни о чём не говорит.
     *
     * До v3.8 этого не было, и код проверял «применена ли миграция» по соседней
     * таблице из того же файла — работало, но держалось на том, что автор помнит
     * состав файла.
     */
    public static function columnExists(string $logical, string $column): bool
    {
        $cache = &self::$columnCache;
        $key = $logical . '.' . $column;
        if (isset($cache[$key])) return $cache[$key];
        if (!self::tableExists($logical)) return $cache[$key] = false;

        $name = self::sql('{' . $logical . '}');
        try {
            if (self::driver() === 'sqlite') {
                /* PRAGMA не принимает плейсхолдеры, поэтому имя таблицы
                   подставляется строкой — оно наше, из карты префиксов, а не
                   пользовательский ввод. */
                $rows = self::pdo()->query('PRAGMA table_info(' . $name . ')');
                foreach ($rows ?: [] as $r) {
                    if (strcasecmp((string)$r['name'], $column) === 0) return $cache[$key] = true;
                }
                return $cache[$key] = false;
            }
            $found = self::pdo()->prepare('SELECT 1 FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
            $found->execute([$name, $column]);
            return $cache[$key] = $found->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare(self::sql($sql));
        $st->execute($params);
        return $st;
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $r = self::run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /**
     * Убрать зависимые строки объекта и сказать, сколько их было.
     *
     * Один и тот же цикл был написан трижды — в Org::purge, Client::purge и
     * User::purge: «посчитать, удалить, пережить отсутствие таблицы». Пока
     * копий было две, это выглядело совпадением; на третьей стало ясно, что
     * это форма, а не случай.
     *
     * Отсутствие таблицы не прерывает удаление намеренно: схема может
     * отставать от кода (обновили файлы, миграцию не применили), и в этот
     * момент удаление объекта нужно не меньше, а больше обычного.
     *
     * Возвращаются только непустые: список из двадцати нулей ничего не
     * сообщает тому, кто его читает.
     *
     * @param list<string> $tables
     * @return array<string, int>
     */
    public static function sweep(array $tables, string $column, int $id): array
    {
        $counts = [];
        foreach ($tables as $t) {
            try {
                $n = (int)self::val('SELECT COUNT(*) FROM {' . $t . '} WHERE ' . $column . ' = ?', [$id]);
                if ($n > 0) $counts[$t] = $n;
                self::run('DELETE FROM {' . $t . '} WHERE ' . $column . ' = ?', [$id]);
            } catch (\Throwable) {
                // таблицы может не быть на отстающей схеме — удаление не прерываем
            }
        }
        return $counts;
    }

    /** INSERT из ассоциативного массива → id. */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO {' . $table . '} (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values($data));
        return (int)self::pdo()->lastInsertId();
    }

    /** UPDATE по условию. $where — 'id = ?', $whereParams — [5]. */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($data)));
        $st = self::run("UPDATE {{$table}} SET $set WHERE $where", [...array_values($data), ...$whereParams]);
        return $st->rowCount();
    }

    /** Атомарный INSERT … ON DUPLICATE KEY / ON CONFLICT (rate limits и пр.). */
    public static function upsert(string $table, array $insert, array $updateOnConflict, string $conflictKey): void
    {
        $cols = array_keys($insert);
        $base = 'INSERT INTO {' . $table . '} (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        if (self::driver() === 'sqlite') {
            $set = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($updateOnConflict)));
            /* Ключ конфликта бывает составным: «client_id, slug» — под UNIQUE по
               паре колонок. Обрамлять его целиком одним обратным апострофом
               нельзя: SQLite примет это за имя одной колонки и скажет «no such
               column: client_id, slug». Разбираем по запятой. */
            $key = implode(',', array_map(
                static fn(string $c): string => '`' . trim($c) . '`',
                explode(',', $conflictKey)));
            $sql = "$base ON CONFLICT($key) DO UPDATE SET $set";
        } else {
            $set = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($updateOnConflict)));
            $sql = "$base ON DUPLICATE KEY UPDATE $set";
        }
        self::run($sql, [...array_values($insert), ...array_values($updateOnConflict)]);
    }

    /**
     * Выполнить работу одним куском: либо целиком, либо никак.
     *
     * Вложение допускается. PDO вложенных транзакций не умеет — повторный
     * beginTransaction() бросает исключение, — поэтому внутренний вызов просто
     * присоединяется к внешнему: границей остаётся самый верхний. Иначе каждая
     * пара «удалить портал, а внутри удалить его роли» становилась бы ловушкой,
     * которая срабатывает не при написании кода, а при первом же запуске.
     *
     * Что сюда НЕ кладут: сетевые вызовы. Транзакция держит блокировки строк,
     * а HTTP к чужому серверу может тянуться секундами и не ответить вовсе —
     * база всё это время ждёт. Оповещения порталов идут после commit, даже
     * если из-за этого придётся собрать их список заранее.
     */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) return $fn();

        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            /* rollBack() сам бросает, если соединение уже упало, — а упавшее
               соединение и есть частый повод сюда попасть. Настоящую причину
               в этом случае потерять нельзя: она в $e. */
            try { $pdo->rollBack(); } catch (\Throwable) {}
            throw $e;
        }
    }

    /** Применение .sql миграции (MySQL-диалект; для sqlite — трансляция). */
    /**
     * Выполнить миграцию.
     *
     * «Уже существует» не считается ошибкой и пропускается. Причина: миграция
     * может упасть на середине — например, таблицы создались, а индекс нет.
     * Версия при этом не записывается, и повтор натыкается на уже созданную
     * таблицу, снова падает, и так навсегда: выбраться из этого состояния
     * штатными средствами было нельзя. Любая другая ошибка по-прежнему
     * прерывает миграцию — молча портить схему хуже, чем остановиться.
     *
     * @return list<string> пропущенные фрагменты (для журнала)
     */
    public static function runMigrationSql(string $rawSql): array
    {
        $sql = self::sql($rawSql);
        if (self::driver() === 'sqlite') {
            $sql = SqliteCompat::translate($sql);
        }
        $skipped = [];
        foreach (SqliteCompat::splitStatements($sql) as $stmt) {
            if (trim($stmt) === '') continue;
            /* Фрагмент из одних комментариев пропускаем: миграция, которой
               нечего менять в схеме (вся работа в PHP-шаге), состоит ровно из
               них, а MySQL на такой запрос отвечает «Query was empty». */
            if (trim((string)preg_replace('~(^|\n)\s*--[^\n]*~', '', $stmt)) === '') continue;
            try {
                self::pdo()->exec($stmt);
            } catch (\PDOException $e) {
                if (!self::alreadyExists($e)) throw $e;
                $skipped[] = trim(preg_replace('~\s+~', ' ', mb_substr($stmt, 0, 60)) ?? '');
            }
        }
        return $skipped;
    }

    /** Ошибка вида «объект уже создан»: MySQL 1050/1060/1061, SQLite — по тексту. */
    private static function alreadyExists(\PDOException $e): bool
    {
        $code = (string)($e->errorInfo[1] ?? '');
        if (in_array($code, ['1050', '1060', '1061'], true)) return true;
        return stripos($e->getMessage(), 'already exists') !== false
            || stripos($e->getMessage(), 'duplicate column') !== false;
    }
}
