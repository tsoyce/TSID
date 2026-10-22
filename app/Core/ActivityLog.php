<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Журнал активности: каждый запрос к приложению (v4.72).
 *
 * Аудит пишет события — «вошёл», «сменил пароль», «выдал роль». Между ними не
 * видно ничего: какие страницы человек открывал, куда стучался без прав, что
 * отправлял и получал ли отказ. Живой случай 21.09: владелец видел вход
 * непрошеного гостя, но не мог понять, что тот пытался сделать.
 *
 * Пишется в конце запроса: кто (вошедший или никто), откуда, метод, адрес,
 * имена параметров, ответ и время. Секретов нет. Из адреса вырезаются токены
 * (ссылки из писем, приглашения, установки), из строки запроса остаются только
 * имена — значения там бывают кодами и состоянием входа. Хранится по сроку из
 * «Политик входа», по умолчанию 30 дней.
 *
 * Не пишутся: статика, служебные точки, которые дёргают машины (ключи,
 * discovery, обмен кода), и предпросмотр оформления — он перерисовывается на
 * каждую правку.
 */
final class ActivityLog
{
    /** Адреса, которые не пишем: машины и шум, а не действия людей. */
    private const QUIET = [
        /* cover и fonts — с v4.94: обложки и шрифты отдаёт приложение, а не
           веб-сервер, и они попали в журнал как «действия». Замер на боевой
           27.09: ~2 500 строк в сутки из 6 300 — каждая страница тянула
           обложку и два шрифта. */
        '~^/(assets|brand|branding|client-icon|avatars|avatar|photo|pwa|cover|fonts)/~',
        '~^/captcha\.png$~',
        '~^/(favicon\.ico|robots\.txt|manifest\.webmanifest|sw\.js|status|jwks\.json)$~',
        '~^/\.well-known/~',
        '~^/(token|introspect|revoke|userinfo)$~',
        '~^/admin/(clients/\d+/look/preview|appearance/preview|look/palette)$~',
        '~^/portal/ui$~',
    ];

    /** Действия словами: начало адреса → подпись. Первое совпадение выигрывает. */
    private const LABELS = [
        ['POST', '~^/login$~', 'Вход паролем'],
        ['GET', '~^/login$~', 'Открыл вход'],
        ['POST', '~^/login/2fa$~', 'Код второго фактора'],
        ['POST', '~^/login/link$~', 'Запросил ссылку для входа'],
        ['*', '~^/magic/~', 'Ссылка для входа из письма'],
        ['POST', '~^/register$~', 'Регистрация'],
        ['GET', '~^/register$~', 'Открыл регистрацию'],
        ['*', '~^/verify/~', 'Подтверждение почты'],
        ['*', '~^/(recover|reset)~', 'Восстановление пароля'],
        ['GET', '~^/authorize$~', 'Вход в сервис'],
        ['*', '~^/consent$~', 'Согласие на вход в сервис'],
        ['*', '~^/logout~', 'Выход'],
        ['*', '~^/passkey/~', 'Ключ устройства'],
        ['*', '~^/social/~', 'Вход через сервис'],
        ['*', '~^/profile/security~', 'Кабинет: безопасность'],
        ['*', '~^/profile/settings~', 'Кабинет: настройки'],
        ['*', '~^/profile/services~', 'Кабинет: сервисы'],
        ['*', '~^/profile/devices~', 'Кабинет: устройства'],
        ['*', '~^/profile~', 'Кабинет'],
        ['*', '~^/admin/users~', 'Админка: пользователи'],
        ['*', '~^/admin/clients~', 'Админка: порталы'],
        ['*', '~^/admin/orgs~', 'Админка: организации'],
        ['*', '~^/admin/roles~', 'Админка: роли'],
        ['*', '~^/admin/logs~', 'Админка: журналы'],
        ['*', '~^/admin/(settings|appearance|blocks)~', 'Админка: настройки'],
        ['*', '~^/admin~', 'Админка'],
        ['*', '~^/api/~', 'Admin API'],
        ['*', '~^/scim/~', 'SCIM'],
    ];

    /**
     * «Стук»: гость по адресу, которого нет (v4.94). Так выглядят сканеры —
     * пачками по двести запросов в минуту перебирают `/wp-login.php`, пробы
     * PHP-CGI и REST WordPress. Замер на боевой 27.09: 842 таких за сутки с
     * пяти адресов, и между ними терялись действия людей. Не прячем их
     * совсем — «Запросы» показывают, сколько скрыто и кто стучал чаще всех.
     */
    private const KNOCK = 'a.user_id IS NULL AND a.status IN (404, 405)';

    private static float $started = 0.0;

    /** Отметить начало запроса. */
    public static function start(): void
    {
        self::$started = microtime(true);
        register_shutdown_function([self::class, 'finish']);
    }

    /** Записать запрос — в самом конце, когда известны ответ и вошедший. */
    public static function finish(): void
    {
        try {
            if (PHP_SAPI === 'cli' || !Config::installed()) return;
            $path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
            if (self::quiet($path)) return;
            if (!DB::columnExists('activity_log', 'path')) return;
            $user = null;
            try {
                $user = Auth::realUser();
            } catch (\Throwable) {
                // сессия могла не подняться — пишем без человека
            }
            DB::insert('activity_log', [
                'created_at' => now(),
                'user_id' => $user !== null ? (int)$user['id'] : null,
                'ip' => ip_bin(),
                'method' => mb_substr((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), 0, 8),
                'path' => mb_substr(self::redact($path), 0, 255),
                'query_keys' => self::queryKeys((string)($_SERVER['QUERY_STRING'] ?? '')),
                'status' => max(0, min(999, (int)http_response_code())),
                'ms' => self::$started > 0 ? (int)round((microtime(true) - self::$started) * 1000) : 0,
                'user_agent' => request_ua() ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::exception($e, 'activity_write_failed');
        }
    }

    /** Адрес не пишется в журнал: статика, машины, предпросмотр. */
    public static function quiet(string $path): bool
    {
        foreach (self::QUIET as $re) if (preg_match($re, $path)) return true;
        return false;
    }

    /**
     * Токены из адреса — прочь: длинные случайные куски пути заменяются на «…».
     * /magic/Ab3…43 знака → /magic/…; числа (номера объектов) остаются.
     */
    public static function redact(string $path): string
    {
        return (string)preg_replace('~/[A-Za-z0-9_\-]{16,}(?=/|$)~', '/…', $path);
    }

    /** Имена параметров строки запроса — без значений. */
    public static function queryKeys(string $query): ?string
    {
        if ($query === '') return null;
        parse_str($query, $q);
        $keys = array_keys($q);
        sort($keys);
        $out = mb_substr(implode(',', $keys), 0, 255);
        return $out !== '' ? $out : null;
    }

    /** Подпись действия для экрана. */
    public static function label(string $method, string $path): string
    {
        foreach (self::LABELS as [$m, $re, $label]) {
            if (($m === '*' || $m === $method) && preg_match($re, $path)) return t($label);
        }
        return $method . ' ' . $path;
    }

    /**
     * Записи — по человеку, адресу или всем сразу, свежие сверху.
     *
     * @param array{user?:int, ip?:string, path?:string, status?:string, before?:int} $f
     */
    public static function find(array $f, int $limit = 100): array
    {
        if (!DB::columnExists('activity_log', 'path')) return [];
        $where = [];
        $params = [];
        if (!empty($f['user'])) { $where[] = 'a.user_id = ?'; $params[] = (int)$f['user']; }
        if (!empty($f['ip']) && ($bin = @inet_pton((string)$f['ip'])) !== false) { $where[] = 'a.ip = ?'; $params[] = $bin; }
        if (!empty($f['path'])) { $where[] = 'a.path LIKE ?'; $params[] = '%' . $f['path'] . '%'; }
        if (($f['status'] ?? '') === 'fail') { $where[] = 'a.status >= 400'; }
        if (!empty($f['before'])) { $where[] = 'a.id < ?'; $params[] = (int)$f['before']; }
        if (($f['knocks'] ?? true) === false) { $where[] = 'NOT (' . self::KNOCK . ')'; }
        return DB::all('SELECT a.*, u.email AS user_email, u.first_name, u.last_name FROM {activity_log} a
            LEFT JOIN {users} u ON u.id = a.user_id'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY a.id DESC LIMIT ' . max(1, min(500, $limit)), $params);
    }

    /**
     * Сводка стуков за сутки: сколько и с каких адресов чаще всего (v4.94).
     *
     * @return array{n:int, top:list<array{ip:string, n:int}>}
     */
    public static function knocks(int $hours = 24): array
    {
        if (!DB::columnExists('activity_log', 'path')) return ['n' => 0, 'top' => []];
        $since = gmdate('Y-m-d H:i:s', time() - $hours * 3600);
        $n = (int)DB::val('SELECT COUNT(*) FROM {activity_log} a WHERE a.created_at > ? AND ' . self::KNOCK, [$since]);
        $top = [];
        if ($n > 0) {
            foreach (DB::all('SELECT a.ip, COUNT(*) AS n FROM {activity_log} a WHERE a.created_at > ? AND a.ip IS NOT NULL AND '
                . self::KNOCK . ' GROUP BY a.ip ORDER BY n DESC LIMIT 3', [$since]) as $r) {
                $ip = @inet_ntop((string)$r['ip']);
                if ($ip !== false) $top[] = ['ip' => $ip, 'n' => (int)$r['n']];
            }
        }
        return ['n' => $n, 'top' => $top];
    }

    /**
     * Адреса человека: откуда он приходил, сколько раз, когда впервые и последний.
     *
     * @return list<array{ip:string, n:int, first:string, last:string}>
     */
    public static function addressesOf(int $userId): array
    {
        if (!DB::columnExists('activity_log', 'path')) return [];
        return DB::all('SELECT ip, COUNT(*) AS n, MIN(created_at) AS first, MAX(created_at) AS last
            FROM {activity_log} WHERE user_id = ? AND ip IS NOT NULL GROUP BY ip ORDER BY last DESC LIMIT 20', [$userId]);
    }

    /** Кто приходил с этого адреса. */
    public static function usersFrom(string $ipBin): array
    {
        $seen = [];
        if (DB::columnExists('activity_log', 'path')) {
            foreach (DB::all('SELECT user_id, COUNT(*) AS n, MAX(created_at) AS last FROM {activity_log}
                WHERE ip = ? AND user_id IS NOT NULL GROUP BY user_id', [$ipBin]) as $r) {
                $seen[(int)$r['user_id']] = ['n' => (int)$r['n'], 'last' => (string)$r['last']];
            }
        }
        /* Аудит — второй источник: он хранится дольше и помнит входы до v4.72. */
        foreach (DB::all('SELECT COALESCE(actor_user_id, on_user_id) AS uid, COUNT(*) AS n, MAX(created_at) AS last
            FROM {audit_log} WHERE ip = ? AND COALESCE(actor_user_id, on_user_id) IS NOT NULL GROUP BY COALESCE(actor_user_id, on_user_id)', [$ipBin]) as $r) {
            $uid = (int)$r['uid'];
            $seen[$uid] = ['n' => ($seen[$uid]['n'] ?? 0) + (int)$r['n'],
                'last' => max((string)($seen[$uid]['last'] ?? ''), (string)$r['last'])];
        }
        if ($seen === []) return [];
        $users = DB::all('SELECT id, email, login, first_name, last_name, status FROM {users} WHERE id IN ('
            . implode(',', array_map('intval', array_keys($seen))) . ')');
        foreach ($users as &$u) $u += $seen[(int)$u['id']];
        unset($u);
        usort($users, static fn($a, $b) => strcmp((string)$b['last'], (string)$a['last']));
        return $users;
    }

    /** Удалить старше срока. */
    public static function purge(int $days): int
    {
        if ($days <= 0 || !DB::columnExists('activity_log', 'path')) return 0;
        return DB::run('DELETE FROM {activity_log} WHERE created_at < ?', [now_plus(-$days * 86400)])->rowCount();
    }
}
