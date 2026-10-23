<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Client;
use App\Models\User;

/**
 * Общая механика завершения входа: используется паролем, 2FA, magic-ссылкой
 * и регистрацией. Знает, куда вернуть пользователя (OAuth-флоу или профиль).
 */
final class LoginFlow
{
    /** Клиент из отложенного OAuth-запроса — для «Вы входите в X» на экранах входа. */
    public static function pendingClient(): ?array
    {
        $req = $_SESSION['oauth_request'] ?? null;
        if ($req && !empty($req['client_id'])) return Client::findByClientId((string)$req['client_id']);
        /* Сессия по дороге умерла, а ключ возврата в адресе есть (v4.83): вход
           всё равно вернёт на портал — по тому же ключу, — а экран был
           безымянным, без «Вы входите в …» и без темы портала. Ключ только
           называет портал: сам он ничего не открывает. */
        $rt = Resume::fromRequest();
        $q = $rt !== '' ? Resume::peek($rt) : null;
        return $q !== null && !empty($q['client_id']) ? Client::findByClientId((string)$q['client_id']) : null;
    }

    /**
     * Метка «только что вошёл ради этого портала» — для экрана «Вы вошли» (v4.72).
     *
     * Ставится при настоящем входе (пароль, ссылка, ключ, соцвход), и только
     * если вход начат с портала: человек, вошедший в кабинет SSO, через пять
     * минут открывает портал — и никакого «Вы вошли» ему не нужно, он ничего
     * не вводил. Тихий заход уже вошедшего метки не ставит вовсе.
     */
    public static function markFresh(): void
    {
        $cid = (string)($_SESSION['oauth_request']['client_id'] ?? '');
        if ($cid !== '') $_SESSION['fresh_login'] = ['client' => $cid, 'at' => time()];
    }

    /** Снять метку: true — вход только что и ради этого портала. Снимается в любом случае. */
    public static function takeFresh(string $clientId): bool
    {
        $f = $_SESSION['fresh_login'] ?? null;
        unset($_SESSION['fresh_login']);
        return is_array($f) && ($f['client'] ?? '') === $clientId && (int)($f['at'] ?? 0) > time() - 600;
    }

    public static function pendingIsPopup(): bool
    {
        return self::compact((array)($_SESSION['oauth_request'] ?? []));
    }

    /**
     * Компактная раскладка: вход окном (popup) и вход во фрейме на странице
     * портала (frame, v4.73) — колонок и подвала там нет места.
     */
    public static function compact(array $q): bool
    {
        return in_array((string)($q['display'] ?? ''), ['popup', Framed::DISPLAY], true);
    }

    /**
     * Первый фактор пройден: дальше — второй, если он включён, иначе вход.
     *
     * v5.4: одна развилка на все пути первого фактора — пароль, ссылка из
     * письма, соцвход. Была скопирована в два места и забыта в третьем: вход
     * через Яндекс или GitHub не спрашивал код 2FA вовсе.
     */
    public static function afterFirstFactor(array $user, bool $remember, string $auditEvent = 'login.success'): never
    {
        if ($user['totp_enabled_at'] !== null) self::require2fa($user, $remember);
        self::complete($user, $remember, $auditEvent);
    }

    /** Успешный вход: сессия, аудит, редирект в нужное место. */
    public static function complete(array $user, bool $remember, string $auditEvent = 'login.success'): never
    {
        /* Подтверждение почты обязательно, а почта не подтверждена (v4.72):
           вход — после неё. Одна проверка на все пути сюда: пароль, второй
           фактор, соцвход, регистрация. */
        if (EmailGate::holds($user)) EmailGate::stop($user, $remember);
        EmailGate::forget();
        User::resetLockout((int)$user['id']);
        Auth::login($user, $remember);
        Audit::log($auditEvent, ['actor' => (int)$user['id'], 'on' => (int)$user['id']]);
        /* «Войти и привязать» (v4.88): вход в найденный аккаунт — и есть
           доказательство, что он этого человека; здесь сходятся все пути
           входа, уже после второго фактора. */
        PendingLink::applyTo($user);
        Notify::afterLogin($user); // запоминает устройство, предупреждает о новом
        WebhookDispatcher::emit('login.success', [
            'sub' => $user['public_id'], 'email' => $user['email'], 'device' => device_label(),
        ]);
        unset($_SESSION['pending_2fa']);
        self::markFresh();
        redirect(self::nextUrl());
    }

    /** Куда идти после входа: отложенный OAuth → ключ возврата → return_to → профиль. */
    public static function nextUrl(): string
    {
        $req = $_SESSION['oauth_request'] ?? null;
        if ($req) {
            unset($_SESSION['oauth_request']);
            /* И prompt, и max_age снимаем: оба означают «переспроси», а
               переспросили только что. Оставь их — и человек пойдёт по
               кругу «вход → authorize → вход». */
            unset($req['prompt'], $req['max_age']);
            /* Ключ возврата не гасим. Раньше гасили — и второе нажатие «Войти»
               (первое ушло, а ответ задержался или его оборвала CSP) приходило
               ни с чем и уводило в личный кабинет. Живой случай 10.09: два входа
               подряд, у второго нет выдачи кода, человек в кабинете. Ключ живёт
               полчаса и сам по себе ничего не открывает: код привязан к PKCE
               портала, а без сессии SSO его не выдадут. */
            return '/authorize?' . http_build_query($req);
        }

        /* Сессии нет, а ключ возврата есть: значит человек всё-таки пришёл из
           сервиса, просто сессия по дороге умерла. Возвращаем туда, куда он
           шёл, — иначе он окажется в личном кабинете SSO, которого не просил. */
        $rt = Resume::fromRequest();
        if ($rt !== '') {
            $q = Resume::peek($rt);   // не гасим — см. выше
            if ($q !== null) {
                unset($q['prompt'], $q['max_age']);
                return '/authorize?' . http_build_query($q);
            }
        }
        $to = $_SESSION['return_to'] ?? null;
        unset($_SESSION['return_to']);
        return local_path(is_string($to) ? $to : '', '/profile');
    }

    /** Шаг 2FA: пароль верен, ждём TOTP. */
    public static function require2fa(array $user, bool $remember): never
    {
        $_SESSION['pending_2fa'] = [
            'user_id' => (int)$user['id'],
            'remember' => $remember,
            'attempts' => 0,
            'issued_at' => time(),
        ];
        redirect('/login/2fa');
    }

    public static function pending2faUser(): ?array
    {
        $p = $_SESSION['pending_2fa'] ?? null;
        if (!$p || $p['issued_at'] < time() - 600) {
            unset($_SESSION['pending_2fa']);
            return null;
        }
        $user = User::find((int)$p['user_id']);
        if (!\App\Models\User::canSignIn($user)) return null;
        return $user;
    }
}
