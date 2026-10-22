<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Смена домена инсталляции (v4.2). Замысел — SYSTEM_LIFECYCLE.md §2.
 *
 * Самая опасная операция раздела и единственная, которую делают в обычной
 * жизни: переезд с тестового поддомена, смена бренда. Опасна тем, что
 * ошибившийся администратор теряет доступ к самой админке — исправлять
 * придётся руками в файле по SSH.
 *
 * Поэтому здесь не «поле ввода и кнопка сохранить», а три отдельных шага:
 * доказать, что новый адрес уже отвечает этим же экземпляром; показать
 * последствия числами из базы; применить с резервной копией и уведомлениями.
 */
final class DomainMove
{
    private const NONCE_KEY = 'system.move.nonce';
    private const NONCE_FOR = 'system.move.host';
    private const PREV_KEY = 'system.move.prev_url';
    private const AT_KEY = 'system.move.at';

    /** Сколько часов после переезда предлагаем откат. */
    public const ROLLBACK_HOURS = 24;

    /**
     * Выдать одноразовое слово для проверки нового адреса.
     *
     * Слово привязано к хосту: полученное для одного адреса не должно
     * подтверждать другой.
     */
    public static function issueNonce(string $url): string
    {
        $nonce = bin2hex(random_bytes(16));
        Setting::set(self::NONCE_KEY, $nonce);
        Setting::set(self::NONCE_FOR, self::origin($url));
        return $nonce;
    }

    public static function nonce(): string
    {
        return (string)Setting::get(self::NONCE_KEY, '');
    }

    public static function nonceHost(): string
    {
        return (string)Setting::get(self::NONCE_FOR, '');
    }

    /**
     * Отдаётся по адресу /.well-known/sso-move (до 4.89 — tsoy-move, он тоже отвечает) на **текущем** домене.
     * Проверяющий открывает этот же путь на новом адресе: если там тот же
     * экземпляр, слово совпадёт.
     */
    public static function publicNonce(): string
    {
        return self::nonce();
    }

    /**
     * Ответил ли новый адрес нашим же словом.
     *
     * @return array{ok: bool, detail: string}
     */
    public static function verify(string $url): array
    {
        $url = rtrim(trim($url), '/');
        $host = self::host($url);
        if ($host === '' || !preg_match('~^https?://~', $url)) {
            return ['ok' => false, 'detail' => 'Адрес должен начинаться с http:// или https://'];
        }
        /* Сравниваем origin, а не хост: переезд с http на https на том же
           домене — тоже переезд, и запрещать его было бы неверно. */
        if (self::origin($url) === self::origin((string)Config::get('app.url', ''))) {
            return ['ok' => false, 'detail' => 'Это текущий адрес — менять нечего'];
        }
        if (!str_starts_with($url, 'https://') && !self::isLocal($host)) {
            return ['ok' => false, 'detail' => 'Только https: OAuth без TLS — не OAuth'];
        }
        $nonce = self::nonce();
        if ($nonce === '' || self::nonceHost() !== self::origin($url)) {
            $nonce = self::issueNonce($url);
        }

        $ch = curl_init($url . '/.well-known/sso-move');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,   // разрешённый хост первым же 302 увёл бы куда угодно
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'SSO-move-check',
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            if ($status === 0) {
                return ['ok' => false, 'detail' => 'Адрес не отвечает: '
                    . ($err !== '' ? $err : 'нет соединения')
                    . '. Проверьте DNS и сертификат; на части хостингов сервер не может '
                    . 'обратиться к собственному домену — тогда проверка не пройдёт даже '
                    . 'при исправном адресе'];
            }
            if ($status === 404) {
                /* 404 по этому пути значит одно из двух, и различить их можно
                   одним запросом. Чаще всего виноват не наш код, а правило
                   веб-сервера: `location ^~ /.well-known/` для сертификатов
                   перехватывает весь каталог и до PHP запрос не доходит. */
                return ['ok' => false, 'detail' => 'По адресу '
                    . rtrim($url, '/') . '/.well-known/sso-move ответ 404. '
                    . 'Откройте на том же домене /.well-known/openid-configuration: '
                    . 'если он тоже 404 — весь каталог /.well-known/ перехватывает '
                    . 'веб-сервер (обычно правило для сертификатов), и запрос до PHP '
                    . 'не доходит. Если открывается — значит, домен ведёт на другую '
                    . 'копию, где переезд не начат'];
            }
            return ['ok' => false, 'detail' => "Адрес отвечает $status — это ещё не наш экземпляр"];
        }
        if (trim((string)$body) !== $nonce) {
            return ['ok' => false, 'detail' => 'Отвечает другой экземпляр: слово не совпало'];
        }
        return ['ok' => true, 'detail' => 'Новый адрес отвечает этим же экземпляром'];
    }

    /**
     * Последствия числами из базы, а не прописью.
     *
     * Экран, который пишет «возможно, что-то перестанет работать», хуже
     * отсутствующего: он не даёт принять решение.
     */
    public static function consequences(): array
    {
        $out = ['passkeys' => 0, 'passkey_only' => [], 'clients' => [],
                'clients_no_admin' => [], 'links' => 0, 'sessions' => 0];
        try {
            $out['passkeys'] = (int)DB::val('SELECT COUNT(*) FROM {passkeys}');
            /* Люди, у которых passkey — единственный способ войти. Для них смена
               домена означает не «войдите заново», а «обратитесь в поддержку». */
            $rows = DB::all(
                'SELECT DISTINCT u.email FROM {passkeys} p
                   JOIN {users} u ON u.id = p.user_id
                  WHERE (u.password_hash IS NULL OR u.password_hash = \'\')
                    AND (u.totp_enabled_at IS NULL)'
            );
            $out['passkey_only'] = array_column($rows, 'email');
            $out['sessions'] = (int)DB::val('SELECT COUNT(*) FROM {sessions} WHERE revoked_at IS NULL');
            $out['links'] = (int)DB::val(
                'SELECT COUNT(*) FROM {one_time_tokens} WHERE consumed_at IS NULL AND expires_at > ?',
                [now()]
            );
            foreach (DB::all('SELECT id, name, base_url FROM {clients} WHERE is_active = 1 ORDER BY name') as $c) {
                /* Админов ищем по ОБЛАСТИ выдачи: роль одна на установку,
                   портал знает только область (v4.24). */
                $admins = DB::all(
                    'SELECT u.email FROM {users} u
                       JOIN {user_roles} ur ON ur.user_id = u.id
                       JOIN {roles} r ON r.id = ur.role_id
                      WHERE r.slug = ? AND ur.scope_type = ? AND ur.scope_id = ? AND u.status <> ?',
                    ['client_admin', \App\Core\Scope::CLIENT, (int)$c['id'], 'deleted']
                );
                $emails = array_column($admins, 'email');
                $entry = ['name' => $c['name'], 'base_url' => $c['base_url'], 'admins' => $emails];
                if ($emails === []) $out['clients_no_admin'][] = $entry;
                $out['clients'][] = $entry;
            }
        } catch (\Throwable $e) {
            Log::warning('move.consequences_failed', ['why' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * Применить переезд.
     *
     * @return array{ok: bool, detail: string, backup: string, mailed: int}
     */
    public static function apply(string $url): array
    {
        $url = rtrim(trim($url), '/');
        $check = self::verify($url);
        if (!$check['ok']) {
            return ['ok' => false, 'detail' => $check['detail'], 'backup' => '', 'mailed' => 0];
        }
        $was = (string)Config::get('app.url', '');
        $before = self::consequences();

        try {
            $backup = ConfigFile::update(['issuer' => $url]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage(), 'backup' => '', 'mailed' => 0];
        }

        Setting::set(self::PREV_KEY, $was);
        Setting::set(self::AT_KEY, now());
        Setting::delete(self::NONCE_KEY);
        Setting::delete(self::NONCE_FOR);

        WebhookDispatcher::emit('issuer.changed', ['old_issuer' => $was, 'new_issuer' => $url]);
        $mailed = self::notifyPortalAdmins($before['clients'], $was, $url);

        Log::warning('system.domain.changed', ['was' => $was, 'now' => $url,
            'backup' => basename($backup), 'mailed' => $mailed]);
        Audit::log('system.domain.changed', ['meta' => ['was' => $was, 'now' => $url]]);

        return ['ok' => true, 'detail' => 'Домен изменён', 'backup' => $backup, 'mailed' => $mailed];
    }

    /** Вернуть прежний адрес. Passkey это не воскресит — об этом говорит интерфейс. */
    public static function rollback(): bool
    {
        $prev = (string)Setting::get(self::PREV_KEY, '');
        if ($prev === '') return false;
        $now = (string)Config::get('app.url', '');
        try {
            ConfigFile::update(['issuer' => $prev]);
        } catch (\Throwable) {
            return false;
        }
        Setting::delete(self::PREV_KEY);
        Setting::delete(self::AT_KEY);
        WebhookDispatcher::emit('issuer.changed', ['old_issuer' => $now, 'new_issuer' => $prev]);
        Log::warning('system.domain.rollback', ['back_to' => $prev]);
        Audit::log('system.domain.rollback', ['meta' => ['back_to' => $prev]]);
        return true;
    }

    /**
     * Повторно разослать письма администраторам порталов.
     *
     * Статуса «портал подтвердил» здесь нет и не будет: ломается проверка
     * `iss` на чужой стороне, и увидеть это мы не можем. Портал шлёт людей и
     * когда сломан — ломается уже обратный путь. Рисовать галочку
     * «подтвердил» значило бы выдумывать то, чего мы не знаем; вместо неё —
     * список, кому ушло письмо, и кнопка отправить ещё раз.
     */
    public static function remind(): int
    {
        $moved = self::recent();
        if (!$moved) return 0;
        $now = (string)Config::get('app.url', '');
        $sent = self::notifyPortalAdmins(self::consequences()['clients'], $moved['prev'], $now);
        Audit::log('system.domain.remind', ['meta' => ['sent' => $sent]]);
        return $sent;
    }

    /** Недавний переезд, к которому ещё предлагаем откат. */
    public static function recent(): ?array
    {
        $prev = (string)Setting::get(self::PREV_KEY, '');
        $at = (string)Setting::get(self::AT_KEY, '');
        if ($prev === '' || $at === '') return null;
        $age = time() - (int)strtotime($at . ' UTC');
        if ($age > self::ROLLBACK_HOURS * 3600) return null;
        return ['prev' => $prev, 'at' => $at, 'hours' => (int)floor($age / 3600)];
    }

    /**
     * Письма администраторам порталов: вебхук получает машина, а конфиг меняет
     * человек. Портал без своего админа остаётся с одним вебхуком — и мастер
     * показывает такие порталы отдельно, а не делает вид, что все предупреждены.
     */
    private static function notifyPortalAdmins(array $clients, string $was, string $now): int
    {
        $sent = 0;
        foreach ($clients as $c) {
            foreach ($c['admins'] as $email) {
                Mailer::as((string)$email, static fn() => Mailer::send((string)$email,
                    t('Адрес входа изменился — :app', ['app' => Setting::appName()]), 'issuer_changed', [
                        'portal' => $c['name'], 'old_issuer' => $was, 'new_issuer' => $now,
                    ]));
                $sent++;
            }
        }
        return $sent;
    }

    private static function host(string $url): string
    {
        return (string)(parse_url($url, PHP_URL_HOST) ?: '');
    }

    /** scheme://host[:port] — то, что на самом деле отличает один адрес от другого. */
    private static function origin(string $url): string
    {
        $p = parse_url($url);
        if (!isset($p['host'])) return '';
        return ($p['scheme'] ?? 'https') . '://' . $p['host']
            . (isset($p['port']) ? ':' . (int)$p['port'] : '');
    }

    private static function isLocal(string $host): bool
    {
        return $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.localhost');
    }
}
