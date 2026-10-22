<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Client;

/**
 * Манифест портала: что сервис рассказывает о себе SSO (v4.1).
 *
 * Портал публикует статический JSON по `{base_url}/.well-known/sso-portal.json`.
 * Секретов там нет — список ролей и логотип он и так показывает посетителям, —
 * поэтому запрос без аутентификации: она удвоила бы работу на чужой стороне
 * ради нулевой выгоды.
 *
 * Дисциплина скачивания та же, что у фотографий профиля в v3.8, и по той же
 * причине: адрес приходит из внешних данных, а запрос уходит с нашего сервера.
 * Только https, только собственный хост портала, без перехода по редиректам
 * (иначе разрешённый хост первым же 302 увёл бы куда угодно), лимиты размера
 * и времени.
 *
 * Чего манифест НЕ может, и это не оговорка, а граница:
 *
 *  · завести роль в SSO — портал сообщает о своих ролях, а какие роли есть у
 *    нас, решаем мы;
 *  · изменить карту соответствия — она управляет доступами, и смена манифеста
 *    не должна менять права людям;
 *  · прислать CSS, HTML или скрипт — см. Bridge::brandingOf(), там объяснено.
 */
final class PortalManifest
{
    /** Больше — не манифест, а способ занять память. */
    private const MAX_BYTES = 64 * 1024;

    /** Семейство настроек: portal.manifest_error.<id портала> — последняя неудача. */
    private const ERR = 'portal.manifest_error.';

    public static function ready(): bool
    {
        return Migrations::ready('client_roles_seen');
    }

    /** Адрес манифеста по базовому адресу портала. */
    public static function urlFor(array $client): ?string
    {
        $base = rtrim((string)($client['base_url'] ?? ''), '/');
        if ($base === '' || !str_starts_with($base, 'https://')) {
            /* http допустим только для локальной разработки: манифест по
               открытому каналу можно подменить по дороге. */
            if (!preg_match('~^http://(localhost|127\.0\.0\.1)([:/]|$)~', $base)) return null;
        }
        return $base . '/.well-known/sso-portal.json';
    }

    /**
     * Сходить к порталу и сохранить то, что он рассказал.
     *
     * @return array{ok: bool, error: ?string, roles: int, branding: bool, users: bool}
     */
    public static function fetch(array $client): array
    {
        $fail = static function (string $why) use ($client): array {
            /* Причину запоминаем: её показывает карточка, по ней считается
               отступ до следующей попытки. */
            self::noteFailure((int)($client['id'] ?? 0), $why);
            return ['ok' => false, 'error' => $why, 'roles' => 0, 'branding' => false, 'users' => false];
        };

        if (!self::ready()) return $fail(t('Схема базы отстаёт от кода.'));
        $url = self::urlFor($client);
        if ($url === null) return $fail(t('У портала не задан адрес по https.'));

        $got = self::download($url);
        if ($got['code'] !== 200) {
            return $fail(self::diagnose($got['code'], $got['head'], $got['body'], $got['error']));
        }
        if (strlen($got['body']) > self::MAX_BYTES) {
            return $fail(t('Ответ больше :n КБ — манифест таким не бывает.', ['n' => (int)(self::MAX_BYTES / 1024)]));
        }
        $body = $got['body'];

        $data = json_decode($body, true);
        if (!is_array($data) || (int)($data['sso_portal'] ?? 0) !== 1) {
            /* Отсутствие манифеста — обычное дело: модуль ещё не написан.
               Это не ошибка, о которой надо кричать. */
            return $fail(is_array($data)
                ? t('Это не манифест портала: нет поля sso_portal.')
                : self::diagnose(200, $got['head'], $body));
        }

        $roles = self::storeRoles((int)$client['id'], $data['roles'] ?? []);
        $branding = Bridge::storeBranding($client, $data['branding'] ?? []);
        /* Адрес выгрузки людей — только адрес. Ключ переноса выдаёт админ, и без
           него портал ничего не отдаст: манифест не может ни включить перенос,
           ни назвать секрет. Он лишь сообщает, что модуль на месте. */
        $users = PortalUsers::ready() && PortalUsers::storeEndpoint($client, $data['users'] ?? []);

        Setting::delete(self::ERR . (int)$client['id']);
        Client::update((int)$client['id'], [
            'manifest_url' => mb_substr($url, 0, 255),
            'manifest_fetched_at' => now(),
        ]);
        Audit::log('client.manifest', ['meta' => [
            'client' => (int)$client['id'], 'roles' => $roles, 'branding' => $branding, 'users' => $users,
        ]]);
        return ['ok' => true, 'error' => null, 'roles' => $roles, 'branding' => $branding, 'users' => $users];
    }

    /**
     * Запомнить роли портала. Только запомнить: карту соответствия это не
     * трогает, иначе портал управлял бы доступами через свой манифест.
     *
     * @return int сколько ролей увидели
     */
    private static function storeRoles(int $clientId, mixed $roles): int
    {
        if (!is_array($roles)) return 0;
        $n = 0;
        foreach ($roles as $role) {
            /* Допускаем и объект {slug,title}, и просто строку: писать модуль
               будут разные люди, и придираться к форме там, где смысл понятен,
               значит ломать интеграцию на ровном месте. */
            $slug = is_array($role) ? (string)($role['slug'] ?? '') : (is_string($role) ? $role : '');
            $title = is_array($role) ? (string)($role['title'] ?? '') : '';
            $slug = trim($slug);
            if ($slug === '' || mb_strlen($slug) > 100) continue;

            DB::upsert('client_roles_seen', [
                'client_id' => $clientId, 'slug' => $slug,
                'title' => $title !== '' ? mb_substr($title, 0, 150) : null,
                'seen_at' => now(),
            ], ['title' => $title !== '' ? mb_substr($title, 0, 150) : null, 'seen_at' => now()],
                'client_id, slug');
            $n++;
        }
        return $n;
    }

    /**
     * Обойти порталы, которых пора спросить: ещё ни разу не спрашивали или
     * спрашивали больше суток назад.
     *
     * Молча и без последствий: портал без манифеста — обычное дело, модуль там
     * может быть просто не написан. Роли только копятся, карта соответствия не
     * трогается — иначе смена файла на чужой стороне меняла бы права людям.
     *
     * За один проход берём немного: обход не должен превращаться в веерный
     * запрос по всем порталам сразу.
     */
    public static function refreshDue(int $limit = 5): string
    {
        if (!self::ready()) return 'схема не готова';
        $rows = DB::all(
            'SELECT * FROM {clients} WHERE is_active = 1
             AND (manifest_fetched_at IS NULL OR manifest_fetched_at < ?)
             ORDER BY manifest_fetched_at IS NULL DESC, manifest_fetched_at ASC
             LIMIT ' . max(1, $limit), [now_plus(-86400)]);

        $ok = 0;
        $asked = 0;
        foreach ($rows as $c) {
            /* Отступ после неудачи. Без него портал, у которого манифеста нет
               и не будет, опрашивался бы каждый час вечно: manifest_fetched_at
               проставляется только при успехе, и он вечно оставался бы
               кандидатом. Отметка о попытке живёт отдельно от отметки об
               успехе — колонка не должна врать своим именем. */
            $key = 'bridge.attempt.' . (int)$c['id'];
            $last = (string)Setting::get($key, '');
            if ($last !== '' && strtotime($last . ' UTC') > time() - self::retryAfter((int)$c['id'])) continue;
            Setting::set($key, now());
            $asked++;

            $r = self::fetch($c);
            if ($r['ok']) $ok++;
        }
        return 'опрошено ' . $asked . ' из ' . count($rows) . ', ответили ' . $ok;
    }

    /** @return list<array> роли, о которых рассказал портал */
    public static function rolesSeen(int $clientId): array
    {
        if (!self::ready()) return [];
        return DB::all('SELECT * FROM {client_roles_seen} WHERE client_id = ? ORDER BY slug', [$clientId]);
    }

    /**
     * Почему манифеста нет — словами, которые ведут к починке.
     *
     * Раньше на любую неудачу была одна фраза — «портал не ответил или отдал не
     * то», — и при подключении Архива она ничем не помогла. Настоящих
     * причин три, и различаются они по ответу: 404, который отдал сам nginx
     * (правило панели отдаёт /.well-known с диска), 403 на путях с точкой
     * (.htaccess режет «скрытое») и честное «модуля ещё нет». Один разбор на
     * всех, кто спрашивает: карточку портала и прозвон.
     */
    public static function diagnose(int $code, string $head, string $body, string $curlError = ''): string
    {
        if ($code === 0) {
            return t('Портал не ответил: :why', ['why' => $curlError !== '' ? $curlError : t('нет соединения')]);
        }
        /* Страницу ошибки nginx узнаём по телу, а не по заголовку Server:
           за nginx-прокси любой ответ Apache тоже подписан «nginx». */
        $byNginx = (bool)preg_match('~<center>\s*nginx~i', $body);
        $isHtml = stripos($body, '<html') !== false || stripos($body, '<!doctype') !== false;

        if ($code >= 300 && $code < 400) {
            $to = preg_match('~^location:\s*(\S+)~mi', $head, $m) ? $m[1] : '';
            return t('Портал ответил редиректом (HTTP :code:to). За редиректами SSO не ходит — отдайте манифест по прямому адресу.',
                ['code' => $code, 'to' => $to !== '' ? ' → ' . $to : '']);
        }
        if ($code === 404 && $byNginx) {
            return t('HTTP 404 отдал сам nginx — до приложения портала запрос не дошёл. Обычно это правило «location ~ /.well-known { allow all; }», которое отдаёт такие пути с диска (так настраивают сайты шаблоны BrainyCP и других панелей). На стороне портала его нужно сузить до «location ^~ /.well-known/acme-challenge/ { allow all; }» — сертификаты выпускаются по-прежнему.');
        }
        if ($code === 403) {
            return t('HTTP 403: сервер портала запрещает пути, начинающиеся с точки. На стороне портала нужно исключение для .well-known — в .htaccess: <FilesMatch "^\.(?!well-known$)">, в nginx: location ~ /\.(?!well-known/) { deny all; }.');
        }
        if ($code === 404) {
            return t('HTTP 404: манифеста по этому адресу нет. Если модуль портала его ещё не отдаёт — это нормально.');
        }
        if ($code >= 500) {
            return t('HTTP :code: приложение портала падает на этом адресе.', ['code' => $code]);
        }
        if ($code === 200 && $isHtml) {
            return t('HTTP 200, но вместо JSON пришла страница сайта — адрес перехватил маршрут по умолчанию.');
        }
        return t('HTTP :code — это не ответ манифеста.', ['code' => $code]);
    }

    /** Последняя неудача: когда, почему и какая по счёту подряд. */
    public static function lastError(int $clientId): ?array
    {
        $raw = json_decode((string)Setting::get(self::ERR . $clientId, ''), true);
        return is_array($raw) && isset($raw['why'], $raw['at']) ? $raw : null;
    }

    /**
     * Через сколько секунд спрашивать снова.
     *
     * После успеха — через сутки: сведения меняются редко. После неудачи —
     * через час и дальше вдвое дольше с каждой следующей, до суток. Раньше после
     * неудачи ждали те же сутки, и это било по самому частому случаю: сервис
     * починил путь к манифесту, а в карточке до завтра висит «не отвечает».
     * Удвоение — чтобы портал, у которого манифеста нет и не будет, не
     * опрашивался каждый час вечно: к шестой неудаче он снова раз в сутки.
     */
    public static function retryAfter(int $clientId): int
    {
        $e = self::lastError($clientId);
        if ($e === null) return 86400;
        $fails = max(1, (int)($e['fails'] ?? 1));
        return (int)min(86400, 3600 * (2 ** min(10, $fails - 1)));
    }

    private static function noteFailure(int $clientId, string $why): void
    {
        if ($clientId <= 0) return;
        $prev = self::lastError($clientId);
        Setting::set(self::ERR . $clientId, json_encode([
            'at' => now(),
            'why' => mb_substr($why, 0, 600),
            'fails' => (int)($prev['fails'] ?? 0) + 1,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Скачать манифест: без переходов по редиректам и с тем, что нужно для
     * разбора неудачи, — кодом, заголовками и телом.
     *
     * @return array{code: int, head: string, body: string, error: string}
     */
    private static function download(string $url): array
    {
        $head = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            /* Редиректы не выполняем: иначе проверка хоста ничего не значит —
               портал увёл бы нас первым же 302 куда угодно, в том числе во
               внутреннюю сеть. */
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'SSO/' . SSO_VERSION . ' (portal manifest)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$head): int {
                if (strlen($head) < 8192) $head .= $line;
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return ['code' => $code, 'head' => $head, 'body' => is_string($body) ? $body : '', 'error' => $error];
    }
}
