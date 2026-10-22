<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\LoginName;
use App\Models\Client;
use App\Models\ClientRoleMap;
use App\Models\Role;
use App\Models\User;

/**
 * Перенос людей с портала — кнопкой, а не файлом (v4.6).
 *
 * Импорт CSV (§13.1) закрывает разовый переезд, но требует человека с выгрузкой
 * в руках: выгрузи, скачай, сопоставь колонки, загрузи. Пока портал один, это
 * терпимо; когда их пять и у каждого свой админ — нет. Здесь портал отдаёт
 * своих людей по запросу, а администратор SSO забирает их одним нажатием.
 *
 * НАПРАВЛЕНИЕ. Спрашиваем мы, отвечает портал. Обратное — «портал сам присылает
 * своих людей» — выглядит удобнее и не годится: тогда любой, кто дотянулся до
 * ключа портала, кладёт учётные записи в источник истины по своей инициативе.
 * Отвечать на вопрос и решать — разные полномочия, и второе остаётся у нас.
 *
 * КЛЮЧ отдельный от client_secret и хранится открытым. Открытым — потому что
 * подпись считают обе стороны, а из argon2-хэша ключ не достать (тот же приём,
 * что у webhooks). Отдельный — потому что он живёт в другом файле на портале,
 * отзывается отдельно и, если его сменили, вход при этом не ломается.
 *
 * ПОДПИСЬ. Ключ по проводу не ходит: уходит HMAC-SHA256 от метки времени и
 * строки запроса. Портал считает то же самое и сравнивает. Метка времени
 * обязательна — иначе подслушанный запрос можно повторять вечно.
 *
 * СОПОСТАВЛЕНИЕ — то, ради чего заведена таблица связей. Повторный перенос
 * обязан обновлять уже перенесённых, а не плодить двойников. Порядок поиска
 * идёт от самого надёжного признака к самому слабому:
 *
 *   1. связь (портал, внешний номер) — заведомо тот же человек;
 *   2. почта — совпадение адреса это совпадение человека;
 *   3. логин — но только если почты нет ни у нас, ни у него: одинаковый логин
 *      у двух разных людей на разных сервисах встречается сплошь и рядом,
 *      и склеить по нему чужие аккаунты хуже, чем завести лишний.
 */
final class PortalUsers
{
    /** Больше — не страница людей, а способ занять память. */
    private const MAX_BYTES = 2 * 1024 * 1024;

    /** Сколько человек просим за один запрос. */
    public const PAGE = 200;

    /** Потолок за одно нажатие: длиннее — это уже фоновая задача, а не кнопка. */
    public const MAX_PULL = 5000;

    public static function ready(): bool
    {
        return Migrations::ready('client_user_links');
    }

    /** Настроен ли перенос: портал назвал адрес и ключ выдан. */
    public static function configured(array $client): bool
    {
        return self::ready()
            && trim((string)($client['users_url'] ?? '')) !== ''
            && trim((string)($client['users_secret'] ?? '')) !== '';
    }

    /**
     * Выдать (или сменить) ключ переноса. Возвращает открытое значение —
     * второй раз его показать неоткуда, как и всякий секрет.
     */
    public static function issueSecret(array $client): string
    {
        $secret = 'pusec_' . Crypto::randomToken(24);
        Client::update((int)$client['id'], ['users_secret' => $secret]);
        Audit::log('client.users_secret', ['meta' => ['client' => (int)$client['id']]]);
        return $secret;
    }

    public static function revokeSecret(array $client): void
    {
        Client::update((int)$client['id'], ['users_secret' => null]);
        Audit::log('client.users_secret_revoked', ['meta' => ['client' => (int)$client['id']]]);
    }

    /**
     * Запомнить адрес выгрузки из манифеста.
     *
     * Адрес обязан вести на хост самого портала — по той же причине, по которой
     * это проверяется у логотипа: иначе манифест заставит нас ходить с ключом
     * куда укажут, в том числе во внутреннюю сеть.
     */
    public static function storeEndpoint(array $client, mixed $users): bool
    {
        if (!is_array($users)) return false;
        $url = trim((string)($users['url'] ?? ''));
        if ($url === '') return false;

        $base = rtrim((string)($client['base_url'] ?? ''), '/');
        $full = str_starts_with($url, 'http') ? $url : $base . '/' . ltrim($url, '/');
        if (!self::sameHost($full, $base)) {
            Log::warning('portal_users.foreign_host', ['url' => $full, 'base' => $base]);
            return false;
        }

        $fill = ['users_url' => mb_substr($full, 0, 255)];
        if (isset($users['count']) && is_numeric($users['count'])) {
            $fill['users_count'] = max(0, (int)$users['count']);
        }
        Client::update((int)$client['id'], $fill);
        return true;
    }

    private static function sameHost(string $url, string $base): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $baseHost = strtolower((string)parse_url($base, PHP_URL_HOST));
        return $host !== '' && $host === $baseHost;
    }

    /**
     * Одна страница людей с портала.
     *
     * @return array{ok: bool, error: ?string, users: list<array>, total: ?int}
     */
    public static function page(array $client, int $offset, int $limit = self::PAGE): array
    {
        $fail = static fn(string $why): array =>
            ['ok' => false, 'error' => $why, 'users' => [], 'total' => null];

        if (!self::configured($client)) return $fail(t('Перенос не настроен: нет адреса выгрузки или ключа.'));
        $base = rtrim((string)($client['base_url'] ?? ''), '/');
        $url = (string)$client['users_url'];
        if (!self::sameHost($url, $base)) return $fail(t('Адрес выгрузки ведёт на чужой хост.'));

        /* Подписываем ВЕСЬ запрос, а не только свои два параметра.
           Портал сверяет подпись по сырой строке — по той, что видит сервер, — и
           если в адресе выгрузки уже был свой параметр («?v=2»), он окажется в
           этой строке, но не в подписи. Дальше: «подпись не сошлась» на запросе,
           с которым всё в порядке, и поиск уходит в сторону ключа. Собираем
           итоговый адрес и подписываем именно его хвост. */
        $sep = str_contains($url, '?') ? '&' : '?';
        $target = $url . $sep . 'limit=' . $limit . '&offset=' . max(0, $offset);
        $query = (string)parse_url($target, PHP_URL_QUERY);
        $ts = (string)time();
        $sig = hash_hmac('sha256', $ts . '.' . $query, (string)$client['users_secret']);

        $ch = curl_init($target);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            /* Редиректы не выполняем: иначе проверка хоста ничего не значит, а
               уходит запрос с подписью — увести его тем более нельзя. */
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'SSO/' . SSO_VERSION . ' (portal users)',
            /* Заголовки X-SSO-* с 4.89; прежние X-TSOY-* уходят рядом с теми же
               значениями, пока порталы не обновят набор (до 5.0). */
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-SSO-Client: ' . (string)$client['client_id'],
                'X-SSO-Timestamp: ' . $ts,
                'X-SSO-Signature: sha256=' . $sig,
                'X-TSOY-Client: ' . (string)$client['client_id'],
                'X-TSOY-Timestamp: ' . $ts,
                'X-TSOY-Signature: sha256=' . $sig,
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($code === 401 || $code === 403) return $fail(t('Портал не принял ключ переноса.'));
        if (!is_string($body) || $code !== 200) return $fail(t('Портал не ответил или отдал не то (HTTP :code).', ['code' => $code]));
        if (strlen($body) > self::MAX_BYTES) return $fail(t('Ответ портала слишком велик.'));

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['users']) || !is_array($data['users'])) {
            return $fail(t('В ответе нет списка users.'));
        }
        return [
            'ok' => true, 'error' => null,
            'users' => array_values(array_filter($data['users'], 'is_array')),
            'total' => isset($data['total']) && is_numeric($data['total']) ? (int)$data['total'] : null,
        ];
    }

    /**
     * Разбор одной записи от портала в наш вид. Ничего не пишет.
     *
     * @return array{external_id: string, email: ?string, login: ?string, roles: list<string>, data: array}|null
     */
    public static function normalize(array $row): ?array
    {
        $ext = trim((string)($row['id'] ?? $row['external_id'] ?? ''));
        if ($ext === '') return null;

        $email = mb_strtolower(trim((string)($row['email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
        $login = mb_substr(trim((string)($row['login'] ?? $row['username'] ?? '')), 0, 64);
        if ($email === '' && $login === '') return null;

        /* Чужой bcrypt/argon принимаем как есть и метим legacy — при первом
           успешном входе он перехэшируется (§13.1). Всё остальное, что портал
           мог положить в это поле (md5, sha1, открытый текст), не пароль в
           нашем понимании: молча принять его значило бы завести аккаунт,
           который считается защищённым, не будучи им. */
        $hash = trim((string)($row['password_hash'] ?? ''));
        $isHash = $hash !== '' && (bool)preg_match('~^\$(2[aby]?|argon2i?d?)\$~', $hash);

        $created = trim((string)($row['created_at'] ?? ''));
        $createdAt = ($created !== '' && strtotime($created) !== false)
            ? gmdate('Y-m-d H:i:s', (int)strtotime($created)) : now();

        /* Роли человека В ПОРТАЛЕ — его слаги, те же, что в манифесте (v4.66).
           Списком или одной строкой; мусор отбрасываем, больше двадцати не берём. */
        $given = $row['roles'] ?? $row['role'] ?? [];
        $roles = [];
        foreach (is_array($given) ? $given : [$given] as $r) {
            if (is_string($r) && preg_match('~^[\p{L}\p{N}_.:-]{1,100}$~u', trim($r))) $roles[trim($r)] = true;
        }
        $roles = array_slice(array_map('strval', array_keys($roles)), 0, 20);

        return [
            'external_id' => mb_substr($ext, 0, 190),
            'email' => $email !== '' ? $email : null,
            'login' => $login !== '' ? $login : null,
            'roles' => $roles,
            'data' => [
                'email' => $email !== '' ? $email : null,
                'login' => $login !== '' ? $login : null,
                'first_name' => mb_substr(trim((string)($row['first_name'] ?? '')), 0, 100) ?: null,
                'last_name' => mb_substr(trim((string)($row['last_name'] ?? '')), 0, 100) ?: null,
                'phone' => mb_substr(trim((string)($row['phone'] ?? '')), 0, 20) ?: null,
                'password_hash' => $isHash ? $hash : null,
                'password_legacy' => $isHash ? 1 : 0,
                'email_verified_at' => ($email !== '' && !empty($row['email_verified'])) ? now() : null,
                'created_at' => $createdAt,
            ],
        ];
    }

    /**
     * Кого из наших имеет в виду эта запись портала.
     *
     * Порядок признаков — от надёжного к слабому, см. заголовок класса.
     * @return array{0: ?array, 1: string} найденный человек и по какому признаку
     */
    public static function match(int $clientId, array $norm): array
    {
        $link = DB::row('SELECT user_id FROM {client_user_links} WHERE client_id = ? AND external_id = ?',
            [$clientId, $norm['external_id']]);
        if ($link) {
            $u = User::find((int)$link['user_id']);
            if ($u) return [$u, 'link'];
        }
        if ($norm['email'] !== null) {
            $u = User::findByEmail($norm['email']);
            if ($u) return [$u, 'email'];
        }
        /* По логину сводим ТОЛЬКО безадресных. Одинаковый логин у разных людей
           на разных сервисах — обычное дело, и склеить по нему чужие аккаунты
           хуже, чем завести лишний: лишний виден и правится, склейка отдаёт
           одному человеку доступы другого. */
        if ($norm['email'] === null && $norm['login'] !== null) {
            $u = DB::row('SELECT * FROM {users} WHERE login = ? AND email IS NULL', [$norm['login']]);
            if ($u) return [$u, 'login'];
        }
        return [null, 'none'];
    }

    /**
     * Что будет, если нажать: считает, никого не трогая.
     *
     * @return array{ok: bool, error: ?string, total: ?int, new: int, linked: int,
     *               skipped: int, sample: list<array>}
     */
    public static function preview(array $client, int $limit = 50): array
    {
        $p = self::page($client, 0, min($limit, self::PAGE));
        if (!$p['ok']) {
            return ['ok' => false, 'error' => $p['error'], 'total' => null,
                    'new' => 0, 'linked' => 0, 'skipped' => 0, 'sample' => []];
        }
        $new = $linked = $skipped = 0;
        $sample = [];
        foreach ($p['users'] as $row) {
            $norm = self::normalize($row);
            if ($norm === null) { $skipped++; continue; }
            [$found, $how] = self::match((int)$client['id'], $norm);
            if ($found) $linked++; else $new++;
            if (count($sample) < 5) {
                $sample[] = [
                    'external_id' => $norm['external_id'],
                    'handle' => $norm['email'] ?? ('@' . $norm['login']),
                    'name' => trim(($norm['data']['first_name'] ?? '') . ' ' . ($norm['data']['last_name'] ?? '')),
                    'how' => $how,
                    'password' => $norm['data']['password_hash'] !== null,
                    'roles' => $norm['roles'],
                ];
            }
        }
        return ['ok' => true, 'error' => null, 'total' => $p['total'],
                'new' => $new, 'linked' => $linked, 'skipped' => $skipped, 'sample' => $sample];
    }

    /**
     * Забрать всех и разложить по местам.
     *
     * @return array{ok: bool, error: ?string, created: int, updated: int,
     *               skipped: int, errors: list<string>}
     */
    public static function pull(array $client, int $max = self::MAX_PULL): array
    {
        $stat = ['ok' => true, 'error' => null, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [],
                 'roles' => 0, 'roles_unmapped' => [], 'roles_refused' => []];
        if (!self::configured($client)) {
            return ['ok' => false, 'error' => t('Перенос не настроен: нет адреса выгрузки или ключа.')] + $stat;
        }
        /* Роль новичка — из политики, а не по имени «user»: она настраивается
           в разделе регистрации и обязана быть одинаковой во всех точках, где
           заводится аккаунт (см. Signup). */
        $defaultRole = Signup::defaultRoleId();
        $roles = self::roleTargets((int)$client['id']);
        $offset = 0;

        while ($offset < $max) {
            $p = self::page($client, $offset, self::PAGE);
            if (!$p['ok']) {
                /* Первая страница не пришла — это отказ. Оборвалось на середине —
                   то, что уже перенесено, остаётся: повтор допереносит остальных,
                   потому что связь по внешнему номеру делает шаг повторяемым. */
                if ($offset === 0) return ['ok' => false, 'error' => $p['error']] + $stat;
                $stat['errors'][] = t('Оборвалось на :n: :why', ['n' => $offset, 'why' => $p['error']]);
                break;
            }
            if ($p['users'] === []) break;

            foreach ($p['users'] as $row) {
                $norm = self::normalize($row);
                if ($norm === null) { $stat['skipped']++; continue; }
                try {
                    self::apply((int)$client['id'], $norm, $defaultRole, $roles, $stat);
                } catch (\Throwable $e) {
                    $stat['errors'][] = $norm['external_id'] . ': ' . $e->getMessage();
                }
            }
            $offset += count($p['users']);
            if (count($p['users']) < self::PAGE) break;
        }

        Client::update((int)$client['id'], ['users_pulled_at' => now()]);
        Audit::log('client.users_pull', ['meta' => [
            'client' => (int)$client['id'], 'created' => $stat['created'],
            'updated' => $stat['updated'], 'skipped' => $stat['skipped'], 'roles' => $stat['roles'],
        ]]);
        return $stat;
    }

    /** Один человек: завести или дописать, и в обоих случаях связать. */
    private static function apply(int $clientId, array $norm, ?int $defaultRole, array $roles, array &$stat): void
    {
        [$found, $how] = self::match($clientId, $norm);
        $data = $norm['data'];

        if ($found === null) {
            /* Логин мог оказаться занят кем-то, кто пришёл не отсюда. Заводить
               человека без логина, когда логин — единственное, чем он назван,
               нельзя: получится аккаунт, в который не войти. */
            if ($data['login'] !== null
                && (User::loginTaken($data['login']) || !LoginName::ok($data['login']))) {
                if ($data['email'] === null) { $stat['skipped']++; return; }
                $data['login'] = null;
            }
            $data['status'] = 'pending';
            $uid = User::create($data);
            if ($defaultRole !== null) Role::assign($uid, $defaultRole);
            $stat['created']++;
        } else {
            $uid = (int)$found['id'];
            /* Дописываем только пустое. Портал — источник истины о себе, но не
               о человеке: если он у нас уже назвался иначе или сменил почту,
               перенос не имеет права это откатывать. Пароль тем более: свой
               здешний пароль всегда главнее унаследованного. */
            $fill = [];
            foreach (['first_name', 'last_name', 'phone', 'email', 'login'] as $f) {
                if ($data[$f] !== null && empty($found[$f])) $fill[$f] = $data[$f];
            }
            if ($data['password_hash'] !== null && empty($found['password_hash'])) {
                $fill['password_hash'] = $data['password_hash'];
                $fill['password_legacy'] = 1;
            }
            if (isset($fill['login'])
                && (User::loginTaken($fill['login'], $uid) || !LoginName::ok($fill['login']))) {
                unset($fill['login']);
            }
            if (isset($fill['email']) && User::emailTaken($fill['email'], $uid)) unset($fill['email']);
            if ($fill !== []) User::update($uid, $fill);
            $stat['updated']++;
        }

        if ($how !== 'link') {
            DB::upsert('client_user_links', [
                'client_id' => $clientId, 'user_id' => $uid,
                'external_id' => $norm['external_id'], 'linked_at' => now(),
            ], ['user_id' => $uid, 'linked_at' => now()], 'client_id, external_id');
        }

        /* Доступ к сервису, ОТКУДА человек и пришёл.
           Раньше перенос заводил аккаунт и записывал связь — и всё: человека не
           было ни в списке людей портала, ни в людях его организации (тот
           считается по доступам). Получалось, что мы забрали людей сервиса, а в
           самом сервисе их нет. Портал — источник истины о том, кто им
           пользуется, и спрашивать его политику доступа тут незачем: эти люди
           уже внутри.

           Только если записи ещё нет: повторный перенос не имеет права снять
           блокировку, поставленную администратором руками. */
        if (\App\Models\Access::get($uid, $clientId) === null) {
            \App\Models\Access::grant($uid, $clientId, null, 'active');
        }

        /* Роли человека в портале (v4.66). Живой случай 12.09: людей Портфолио
           перенесли, а роли им не раздались — перенос ролей не читал вовсе, а
           выгрузка их и не отдавала. Только добавляем: повторный перенос не
           вправе снять роль, выданную администратором руками, а «портал перестал
           называть роль» не отличить от «портал не прислал поле». */
        if ($norm['roles'] === []) return;
        $scope = Scope::client($clientId);
        $before = (int)DB::val('SELECT COUNT(*) FROM {user_roles} WHERE user_id = ?', [$uid]);
        foreach ($norm['roles'] as $slug) {
            $ids = $roles['map'][$slug] ?? (isset($roles['portal'][$slug]) ? [$roles['portal'][$slug]] : []);
            if ($ids === []) {
                $why = isset($roles['refused'][$slug]) ? 'roles_refused' : 'roles_unmapped';
                $stat[$why][$slug] = ($stat[$why][$slug] ?? 0) + 1;
                continue;
            }
            foreach ($ids as $roleId) Role::assignScoped($uid, $roleId, $scope);
        }
        $stat['roles'] += (int)DB::val('SELECT COUNT(*) FROM {user_roles} WHERE user_id = ?', [$uid]) - $before;
    }

    /**
     * Какие наши роли выдавать по слагу роли портала (v4.66).
     *
     * Сначала карта ролей портала — в обратную сторону: «роль SSO → как она
     * называется у портала». Пары в карте нет — роль с тем же слагом, заведённая
     * за этим порталом (так их заводит «Спросить у портала»).
     *
     * ГРАНИЦА. Выдаём только в пределах портала: его собственные роли и
     * системную «Админ портала» — в его же области. Любую другую общую роль
     * перенос не выдаёт, даже сопоставленную в карте: иначе портал своей
     * выгрузкой раздавал бы права на весь SSO. Карта говорит порталу, кто есть
     * кто, — решать это за нас она порталу не даёт.
     *
     * @return array{map: array<string, list<int>>, portal: array<string, int>, refused: array<string, true>}
     */
    private static function roleTargets(int $clientId): array
    {
        $clientAdmin = Role::findBySlug('client_admin');
        $out = ['map' => [], 'portal' => [], 'refused' => []];
        if (ClientRoleMap::ready()) {
            foreach (DB::all('SELECT m.external_name, r.id, r.client_id FROM {client_role_map} m
                               JOIN {roles} r ON r.id = m.role_id WHERE m.client_id = ?', [$clientId]) as $m) {
                $own = $m['client_id'] !== null
                    ? (int)$m['client_id'] === $clientId
                    : $clientAdmin !== null && (int)$m['id'] === (int)$clientAdmin['id'];
                if ($own) $out['map'][(string)$m['external_name']][] = (int)$m['id'];
                else $out['refused'][(string)$m['external_name']] = true;
            }
        }
        foreach (Role::forClient($clientId) as $r) $out['portal'][(string)$r['slug']] = (int)$r['id'];
        return $out;
    }

    /** Сколько людей этого портала мы уже знаем. */
    public static function linkedCount(int $clientId): int
    {
        if (!self::ready()) return 0;
        return (int)DB::val('SELECT COUNT(*) FROM {client_user_links} WHERE client_id = ?', [$clientId]);
    }
}
