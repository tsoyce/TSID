<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\AccountNumber;
use App\Core\Crypto;
use App\Core\DB;

final class User
{
    /**
     * Может ли человек входить и держать токены: активен или ждёт подтверждения
     * почты (pending — так приходят все, кого перенесли с порталов).
     *
     * Одна проверка на все пути (v4.70). Литерал был переписан в четырнадцати
     * местах и в трёх разошёлся: обмен кода и /userinfo пускали pending, а
     * обновление токена и /introspect — нет. Портал с offline_access выкидывал
     * перенесённого человека через пятнадцать минут, а refresh заодно отзывал
     * всю семью токенов.
     */
    public const SIGN_IN_STATUSES = ['active', 'pending'];

    public static function canSignIn(?array $user): bool
    {
        return $user !== null && in_array($user['status'] ?? '', self::SIGN_IN_STATUSES, true);
    }

    public static function find(int $id): ?array
    {
        return DB::row('SELECT * FROM {users} WHERE id = ?', [$id]);
    }

    /**
     * Принять TOTP-шаг, если он новее последнего принятого (v4.70).
     *
     * Условие стоит в самом UPDATE: два одновременных входа одним кодом не
     * проходят оба. На схеме без колонки (миграция 026 не применена) пускаем
     * как раньше — отставшая схема не должна запирать людей снаружи.
     */
    public static function claimTotpStep(int $userId, int $step): bool
    {
        if (!DB::columnExists('users', 'totp_last_step')) return true;
        return DB::update('users', ['totp_last_step' => $step],
            'id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)', [$userId, $step]) === 1;
    }

    /**
     * Погасить код восстановления. true — код был жив и погашен этим вызовом.
     *
     * Одним условным UPDATE, без чтения перед ним (v4.70): проверка и
     * гашение — один шаг, и одноразовый код не проходит дважды наперегонки.
     * Регистр, пробелы и дефисы не важны — код диктуют с бумаги.
     */
    public static function useRecoveryCode(int $userId, string $code): bool
    {
        $hash = hash('sha256', strtoupper(str_replace([' ', '-'], '', $code)));
        return DB::update('recovery_codes', ['used_at' => now()],
            'user_id = ? AND code_hash = ? AND used_at IS NULL', [$userId, $hash]) === 1;
    }

    public static function findByPublicId(string $sub): ?array
    {
        return DB::row('SELECT * FROM {users} WHERE public_id = ?', [$sub]);
    }

    public static function findByEmail(string $email): ?array
    {
        return DB::row('SELECT * FROM {users} WHERE email = ?', [mb_strtolower(trim($email))]);
    }

    public static function findByLoginOrEmail(string $identity): ?array
    {
        $identity = trim($identity);
        return DB::row('SELECT * FROM {users} WHERE email = ? OR login = ? LIMIT 1',
            [mb_strtolower($identity), $identity]);
    }

    /**
     * Поиск по номеру аккаунта. Ввод принимается в любом виде — «4827-13905»,
     * с пробелами, голыми цифрами: люди копируют номер вместе с разделителем.
     * Неправильная контрольная цифра отсекается до запроса в базу.
     */
    public static function findByNumber(string $input): ?array
    {
        $number = AccountNumber::normalize($input);
        if ($number === null) return null;
        try {
            return DB::row('SELECT * FROM {users} WHERE account_number = ?', [$number]);
        } catch (\Throwable) {
            /* Колонки ещё нет — миграция 008 не применена. Поиск по номеру
               просто ничего не находит, остальная админка при этом работает. */
            return null;
        }
    }

    /**
     * Выдать аккаунту номер, если его ещё нет. Возвращает номер.
     *
     * Уникальность держит индекс, но полагаться на исключение как на
     * управляющую конструкцию не хочется: проверяем заранее и пробуем ещё раз.
     * При девяноста миллионах номеров и разумном числе аккаунтов до второй
     * попытки дело почти не доходит.
     */
    public static function assignNumber(int $id): string
    {
        $current = DB::val('SELECT account_number FROM {users} WHERE id = ?', [$id]);
        if (is_string($current) && $current !== '') return $current;

        for ($try = 0; $try < 10; $try++) {
            $number = AccountNumber::generate();
            if (DB::val('SELECT id FROM {users} WHERE account_number = ?', [$number]) !== null) continue;
            DB::update('users', ['account_number' => $number], 'id = ?', [$id]);
            return $number;
        }
        throw new \RuntimeException('Не удалось подобрать свободный номер аккаунта за 10 попыток');
    }

    /** Создание: email нормализуется, public_id (sub) генерится здесь. */
    public static function create(array $data): int
    {
        $data += [
            'public_id' => Crypto::uuid4(),
            /* Язык установки; кто заводит аккаунт сам, передаёт язык своего
               интерфейса (v4.74) — на нём ему и пойдут письма. */
            'locale' => (string)config('app.locale', 'ru'),
            'timezone' => \App\Core\Setting::timezone(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $data = self::normalizeHandles($data);
        /* Логин по умолчанию — из почты (v4.72): для всех новых аккаунтов, как
           бы они ни появились. Заданный явно не трогаем; действующих — тоже. */
        if (($data['login'] ?? null) === null && !empty($data['email'])) {
            $data['login'] = \App\Core\LoginName::fromEmail((string)$data['email'],
                static fn(string $l): bool => self::loginTaken($l));
        }
        $id = DB::insert('users', $data);
        /* Номер выдаётся сразу после вставки, а не внутри неё: свободный номер
           надо подбирать с проверкой, и отдельным шагом это делается честно.
           Если миграция 008 ещё не применена, аккаунт заводится без номера —
           регистрация не должна падать из-за отстающей схемы. */
        try {
            self::assignNumber($id);
        } catch (\Throwable $e) {
            \App\Core\Log::warning('account_number.skip', ['user' => $id, 'why' => $e->getMessage()]);
        }
        return $id;
    }

    public static function update(int $id, array $data): void
    {
        $data['updated_at'] = now();
        $data = self::normalizeHandles($data);
        DB::update('users', $data, 'id = ?', [$id]);
    }

    /**
     * Почта и логин к единому виду: почта в нижний регистр, пустое — в NULL.
     *
     * Пустая строка и NULL для базы разные вещи. Уникальный ключ считает NULL
     * несравнимым и пропускает сколько угодно строк, а вот вторая пустая строка
     * упирается в тот же ключ — и человек без почты не заводится, потому что
     * место уже занято таким же безымянным. Внешне это выглядит как «почта
     * занята», хотя её нет ни у кого.
     */
    private static function normalizeHandles(array $data): array
    {
        if (array_key_exists('email', $data)) {
            $email = mb_strtolower(trim((string)$data['email']));
            $data['email'] = $email === '' ? null : $email;
        }
        if (array_key_exists('login', $data)) {
            $login = trim((string)$data['login']);
            $data['login'] = $login === '' ? null : $login;
        }
        return $data;
    }

    public static function emailTaken(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM {users} WHERE email = ?';
        $params = [mb_strtolower(trim($email))];
        if ($exceptId !== null) { $sql .= ' AND id != ?'; $params[] = $exceptId; }
        return DB::row($sql, $params) !== null;
    }

    /**
     * Телефон в том виде, в каком он лежит в базе: цифры и плюс, до 20 знаков.
     *
     * Правило жило двумя копиями — в профиле и в поглощении профиля провайдера,
     * — а импорт людей не знал его вовсе: писал номер как есть, с пробелами и
     * скобками, и без обрезки. Длиннее колонки на MySQL — тот же отказ, что
     * был с ENUM: на SQLite его не видно.
     */
    public static function normalizePhone(string $raw): string
    {
        return mb_substr((string)preg_replace('~[^0-9+]~', '', $raw), 0, 20);
    }

    /**
     * Занят ли номер. На users.phone стоит UNIQUE: чужой номер не присваивается
     * — ни в профиле, ни при переносе людей из сервиса.
     */
    public static function phoneTaken(string $phone, ?int $exceptId = null): bool
    {
        $phone = self::normalizePhone($phone);
        if ($phone === '') return false;
        $sql = 'SELECT id FROM {users} WHERE phone = ?';
        $params = [$phone];
        if ($exceptId !== null) { $sql .= ' AND id != ?'; $params[] = $exceptId; }
        return DB::row($sql, $params) !== null;
    }

    public static function loginTaken(string $login, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM {users} WHERE login = ?';
        $params = [trim($login)];
        if ($exceptId !== null) { $sql .= ' AND id != ?'; $params[] = $exceptId; }
        return DB::row($sql, $params) !== null;
    }

    /* ---- Роли ---- */

    public static function globalRoleSlugs(int $userId): array
    {
        /* По области ВЫДАЧИ, а не по тому, где роль определена: с v4.24
           «Админ портала» — системная роль, выданная с областью портала, и по
           прежнему условию (`r.client_id IS NULL`) она попадала бы сюда как
           глобальная — то есть портал получал бы `client_admin` без префикса и
           считал человека своим админом.

           Пока миграция областей не применена — спрашиваем по-старому.
           Приложение обязано переживать отставшую схему: код обновляют раньше,
           чем гоняют миграции, и этот запрос стоит на пути ВЫДАЧИ ТОКЕНА.
           Падение здесь означает не «журнал не пишется», а «вход в порталы не
           работает вовсе». */
        if (!\App\Core\AccessResolver::ready()) {
            return array_column(DB::all(
                'SELECT r.slug FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id
                 WHERE ur.user_id = ? AND r.client_id IS NULL', [$userId]
            ), 'slug');
        }
        return array_column(DB::all(
            'SELECT r.slug FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id
             WHERE ur.user_id = ? AND ur.scope_type = ?', [$userId, \App\Core\Scope::SYSTEM]
        ), 'slug');
    }

    /** Роли для claim `roles`: глобальные + скоуп-роли клиента с префиксом (§9.3). */
    public static function rolesClaim(int $userId, ?int $clientDbId): array
    {
        $roles = self::globalRoleSlugs($userId);
        if (!in_array('user', $roles, true)) $roles[] = 'user';
        if ($clientDbId !== null) {
            /* Роли этого портала — по области выдачи. Префикс берём у портала
               области, а не у портала, которому принадлежит определение роли:
               с v4.24 определение может быть системным, а действует роль всё
               равно в одном сервисе. Форма claim при этом не изменилась —
               портал по-прежнему получает `слаг-портала:роль`.

               Роли, выданные на организацию портала, сюда тоже попадают: это
               и есть наследование из SCOPES §2. Пока clients.org_id пуст,
               ветка не находит ничего. */
            $scoped = \App\Core\AccessResolver::ready()
                ? DB::all(
                    'SELECT c.slug AS cslug, r.slug FROM {user_roles} ur
                     JOIN {roles} r ON r.id = ur.role_id
                     JOIN {clients} c ON c.id = ?
                     WHERE ur.user_id = ?
                       AND ((ur.scope_type = ? AND ur.scope_id = ?)
                         OR (ur.scope_type = ? AND ur.scope_id = c.org_id))',
                    [$clientDbId, $userId, \App\Core\Scope::CLIENT, $clientDbId, \App\Core\Scope::ORG])
                /* Отставшая схема — прежний запрос: роль портала опознаётся по
                   тому, где она определена. Токен обязан выдаваться и там. */
                : DB::all(
                    'SELECT c.slug AS cslug, r.slug FROM {user_roles} ur
                     JOIN {roles} r ON r.id = ur.role_id
                     JOIN {clients} c ON c.id = r.client_id
                     WHERE ur.user_id = ? AND r.client_id = ?', [$userId, $clientDbId]);
            foreach ($scoped as $row) {
                $roles[] = $row['cslug'] . ':' . $row['slug'];
            }
        }
        $roles = array_values(array_unique($roles));

        /* Карта соответствия, если её завели: портал видит свои названия и не
           знает о ролях, которые его не касаются. Не завели — отдаём слаги как
           раньше, иначе появление новой возможности молча обрезало бы доступы
           у всех, кто её ещё не настроил. */
        if ($clientDbId !== null) {
            $roles = ClientRoleMap::apply($clientDbId, $roles);
        }
        return $roles;
    }

    /**
     * Удалить аккаунт окончательно, со всем, что к нему привязано.
     *
     * В отличие от `status = deleted` с 14-дневным холдом и последующей
     * анонимизацией — здесь строк не остаётся вовсе. Список таблиц явный,
     * а не расчёт на каскады: у SQLite они действуют лишь при включённом
     * PRAGMA, и часть таблиц ссылается на пользователя без внешнего ключа.
     * Оставленные строки — это живые сессии и refresh-токены аккаунта,
     * которого уже нет.
     *
     * Записи аудита не трогаем: журнал безопасности переживает своих
     * фигурантов, иначе разбирать инциденты будет не по чему. Ссылка на
     * удалённого остаётся числом — и это правильно.
     *
     * @return array<string,int>
     */
    /** Состояния человека — единственный перечень на проект. */
    public const STATUSES = ['pending', 'active', 'blocked', 'deleted'];

    /**
     * Условие «кого показывать в списке» — одно на админку и на API.
     *
     * Удалённый человек остаётся строкой в базе (мягкое удаление §5.1:
     * анонимизация через 14 дней), и вопрос «показывать ли его в общем списке»
     * решается ровно один раз. Раньше решался дважды: админка удалённых
     * прятала, API — показывал, и на живой установке API отвечал «людей 7»
     * там, где человек видел троих. Разошлись молча, как и всё, что написано
     * в двух местах.
     *
     * `all` — показать всех, включая удалённых. Это осознанный запрос, а не
     * умолчание.
     *
     * @return array{0:string,1:array} [условие, параметры]
     */
    public static function statusWhere(string $status, string $alias = ''): array
    {
        $col = ($alias !== '' ? $alias . '.' : '') . 'status';
        if ($status === 'all') return ['1=1', []];
        if (in_array($status, self::STATUSES, true)) return ["$col = ?", [$status]];
        return ["$col != ?", ['deleted']];
    }

    public static function purge(int $userId): array
    {
        /* Удаление — одним куском (v4.45).
           Семнадцать удалений шли подряд без транзакции. Обрыв на середине
           давал худший из возможных исходов: аккаунт остаётся, а сессий,
           ролей, ключей и согласий у него уже нет. Это не удалённый
           пользователь и не живой — это сломанный, и починить его нечем.
           Либо человека нет целиком, либо он не тронут. */
        [$counts, $pub] = DB::tx(static function () use ($userId): array {
            $counts = DB::sweep([
                'recovery_codes', 'user_client_access', 'user_roles', 'sessions',
                'auth_codes', 'refresh_tokens', 'one_time_tokens', 'linked_identities',
                'passkeys', 'user_emails', 'user_photos', 'legal_consents',
                'notification_prefs', 'known_devices', 'export_jobs', 'org_members',
                /* Журнал активности (v4.72): что человек открывал — тоже его данные. */
                'activity_log',
            ], 'user_id', $userId);
            $pub = (string)(DB::val('SELECT public_id FROM {users} WHERE id = ?', [$userId]) ?? '');
            DB::run('DELETE FROM {users} WHERE id = ?', [$userId]);
            return [$counts, $pub];
        });

        /* Файлы на диске: аватар и галерея лежат в каталоге по public_id, и
           без этого остались бы сиротами, которых уже никто не свяжет.
           Удаляем ПОСЛЕ фиксации: файловую систему транзакция не откатывает,
           и снимок, стёртый при отменённом удалении, не вернуть ничем. Осиротевший
           каталог — мусор; пропавшая фотография живого человека — потеря. */
        if ($pub !== '') {
            $dir = SSO_ROOT . '/storage/avatars/' . $pub;
            foreach ((array)glob($dir . '/photos/*') as $f) @unlink((string)$f);
            @rmdir($dir . '/photos');
            foreach ((array)glob($dir . '/*') as $f) @unlink((string)$f);
            @rmdir($dir);
        }
        return $counts;
    }

    /** id клиентов, где у пользователя роль client_admin. */
    public static function clientAdminClientIds(int $userId): array
    {
        /* Портал берём из ОБЛАСТИ выдачи: роль «Админ портала» с v4.24 одна на
           установку, и какой именно портал ей администрируют, знает только
           область. Организация тоже считается — её роль покрывает все проекты.

           Ищем не по имени роли, а по ПРАВУ её изменять: администрировать
           портал может и «Админ портала», и владелец организации, и любая
           роль, которой это право выдали руками. Привязка к слагу
           `client_admin` означала бы, что таблица прав декоративна. */
        /* Отставшая схема — прежний способ: портал берётся из определения роли.
           Делегированная админка стоит на пути входа в /admin, и падение здесь
           заперло бы администратора снаружи. */
        if (!\App\Core\AccessResolver::ready()) {
            return array_map('intval', array_column(DB::all(
                'SELECT r.client_id FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id
                 WHERE ur.user_id = ? AND r.slug = ? AND r.client_id IS NOT NULL',
                [$userId, 'client_admin']), 'client_id'));
        }

        $rows = DB::all(
            'SELECT DISTINCT ur.scope_type, ur.scope_id FROM {user_roles} ur
               JOIN {role_permissions} rp ON rp.role_id = ur.role_id
              WHERE ur.user_id = ? AND rp.perm = ? AND ur.scope_type IN (?, ?)',
            [$userId, 'clients.write', \App\Core\Scope::CLIENT, \App\Core\Scope::ORG]);

        $ids = [];
        foreach ($rows as $row) {
            if ($row['scope_type'] === \App\Core\Scope::CLIENT) {
                $ids[] = (int)$row['scope_id'];
                continue;
            }
            foreach (DB::all('SELECT id FROM {clients} WHERE org_id = ?', [(int)$row['scope_id']]) as $c) {
                $ids[] = (int)$c['id'];
            }
        }
        return array_values(array_unique($ids));
    }

    /* ---- Блокировка и lockout ---- */

    public static function registerFailedLogin(array $user): void
    {
        $fails = (int)$user['failed_logins'] + 1;
        $data = ['failed_logins' => $fails];
        $locked = $fails >= \App\Core\Setting::lockoutAttempts();
        if ($locked) {
            $data['locked_until'] = now_plus(\App\Core\Setting::lockoutMinutes() * 60);
            $data['failed_logins'] = 0;
        }
        self::update((int)$user['id'], $data);

        /* Момент, когда сработала блокировка, — единственный, о котором стоит
           говорить: одна опечатка не событие, а десять подряд уже похожи на
           подбор. Переключатель «Серия неудачных попыток входа» существовал в
           настройках с v2.2 и не срабатывал ни разу: событие объявили, а
           отправлять его забыли. Настройка, которая ничего не делает, хуже её
           отсутствия — человек считает, что предупреждён. */
        /* Неудачная попытка — событие для портала, а не только для человека:
           по нему строят свои графики и свои блокировки. Стояло в каталоге
           вебхуков с v2.3 и не отправлялось ни разу. Отдаём `sub`, но не
           пароль и не то, что вводили: в чужой журнал это попадать не должно. */
        \App\Core\WebhookDispatcher::emit('login.failed', [
            'sub' => $user['public_id'],
            'attempts' => $fails,
            'locked' => $locked,
        ]);

        if (!$locked) return;
        $minutes = \App\Core\Setting::lockoutMinutes();
        /* Будит письма незнакомец — текст собирается на языке получателя (v4.74). */
        \App\Core\Notify::send($user, 'login.failed_burst',
            fn() => t('Подбор пароля — :app', ['app' => \App\Core\Setting::appName()]),
            fn() => t('В аккаунт пытались войти :n раз подряд с неверным паролем. Вход временно закрыт на :min мин.', [
                'n' => \App\Core\Setting::lockoutAttempts(), 'min' => $minutes,
            ]));
        \App\Core\AdminNotify::say('login.burst',
            fn() => t('Аккаунт :who закрыт на :min мин после :n неудачных попыток входа.', [
                'who' => user_handle($user), 'min' => $minutes,
                'n' => \App\Core\Setting::lockoutAttempts(),
            ]),
            fn() => [t('Когда') => fmt_dt(now()), t('IP') => ip_str(ip_bin())]);
    }

    public static function isLocked(array $user): bool
    {
        return $user['locked_until'] !== null && strtotime($user['locked_until'] . ' UTC') > time();
    }

    public static function resetLockout(int $id): void
    {
        self::update($id, ['failed_logins' => 0, 'locked_until' => null]);
    }

    /** @return array{label: string, ok: bool} подпись уже переведена */
    private static function scoreItem(bool $ok, string $done, string $todo): array
    {
        return ['label' => $ok ? $done : $todo, 'ok' => $ok];
    }

    /** Оценка защищённости для /profile/security (честный прогресс из фактов). */
    public static function securityScore(array $u): array
    {
        $hasPasskey = false;
        try {
            $hasPasskey = \App\Models\Passkey::countForUser((int)$u['id']) > 0;
        } catch (\Throwable) {
            // миграция 002 ещё не применена — не мешаем странице
        }
        /* Подпись — по состоянию (v4.70). Одна подпись на оба случая читалась
           как утверждение: серым с пустым кружком стояло «Двухфакторная
           защита включена» — у человека, у которого она выключена. */
        $items = [
            self::scoreItem($u['email_verified_at'] !== null, t('Почта подтверждена'), t('Почта не подтверждена')),
            self::scoreItem($u['totp_enabled_at'] !== null, t('Двухфакторная защита включена'), t('Двухфакторная защита не включена')),
            self::scoreItem(!empty($u['password_hash']) && !$u['password_legacy'], t('Пароль задан'), t('Пароль не задан')),
            self::scoreItem($hasPasskey, t('Passkey добавлен'), t('Passkey не добавлен')),
        ];
        $ok = count(array_filter($items, fn($i) => $i['ok']));
        return ['items' => $items, 'percent' => (int)round($ok / count($items) * 100)];
    }
}
