<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\SessionModel;
use App\Models\User;

/**
 * Центральная SSO-сессия: cookie (имя из конфига, по умолчанию `sso`) → sso_sessions (в БД только sha256).
 * Имперсонизация superadmin'а живёт в PHP-сессии и оставляет след в аудите.
 */
final class Auth
{
    /** Имя cookie центральной сессии — из конфига (white-label). */
    public static function cookieName(): string
    {
        /* Во фрейме на странице портала с другого домена — своя cookie (v4.73). */
        return Framed::cookieName('');
    }

    private static ?array $user = null;
    private static ?array $session = null;
    private static bool $resolved = false;
    private static ?array $rolesCache = null;
    /** Права кэшируются на запрос: их спрашивают по нескольку раз на страницу. */
    private static ?array $permsCache = null;

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int)$u['id'] : null;
    }

    /** Текущий пользователь (с учётом имперсонизации). */
    public static function user(): ?array
    {
        self::resolve();
        if (self::$user && !empty($_SESSION['impersonate_user_id']) && self::isSuperadminReal()) {
            $target = User::find((int)$_SESSION['impersonate_user_id']);
            if ($target && $target['status'] !== 'deleted') return $target;
        }
        return self::$user;
    }

    /** Реальный пользователь без учёта имперсонизации. */
    public static function realUser(): ?array
    {
        self::resolve();
        return self::$user;
    }

    public static function impersonating(): bool
    {
        return !empty($_SESSION['impersonate_user_id']) && self::isSuperadminReal();
    }

    public static function sessionRecord(): ?array
    {
        self::resolve();
        return self::$session;
    }

    private static function resolve(): void
    {
        if (self::$resolved) return;
        self::$resolved = true;
        $token = $_COOKIE[self::cookieName()] ?? '';
        if ($token === '' || !Config::installed()) return;
        $sess = SessionModel::findActiveByToken($token);
        if (!$sess) return;
        $user = User::find((int)$sess['user_id']);
        if (!\App\Models\User::canSignIn($user)) return;
        self::$session = $sess;
        self::$user = $user;

        /* Замок считается ДО отметки времени: `touch()` обновляет last_seen_at,
           и после него простой всегда нулевой — замок не сработал бы никогда.
           Запираем прямо здесь, чтобы состояние пережило закрытие вкладки:
           замок, живущий в PHP-сессии, защищает ровно от того, кто уже ушёл. */
        if (QuickUnlock::shouldLock($sess, $user) && empty($sess['locked_at'])) {
            QuickUnlock::lock((int)$sess['id']);
            self::$session['locked_at'] = now();
        }

        SessionModel::touch($sess);
    }

    /** Заперта ли текущая сессия. */
    public static function locked(): bool
    {
        self::resolve();
        return self::$session !== null && QuickUnlock::locked(self::$session);
    }

    /** Вход: создаёт запись сессии, ставит cookie, регенерирует PHP-сессию. */
    public static function login(array $user, bool $remember): void
    {
        $ttl = $remember
            ? Setting::timeout('remember', (int)config('session.remember_lifetime', 2592000))
            : Setting::sessionLifetime();
        $token = Crypto::randomToken(32);
        SessionModel::create((int)$user['id'], $token, $remember, $ttl);
        self::setCookie($token, $remember ? $ttl : 0);
        session_regenerate_id(true);
        unset($_SESSION['impersonate_user_id']);
        self::$resolved = false;
        self::$user = null;
        self::$session = null;
        self::$rolesCache = null;
        self::$permsCache = null;
        User::update((int)$user['id'], ['last_login_at' => now()]);
    }

    /** Выход из текущей сессии (без событий порталам — это делает вызывающий). */
    public static function logout(): void
    {
        $sess = self::sessionRecord();
        if ($sess) SessionModel::revoke((int)$sess['id']);
        self::setCookie('', -3600);
        session_regenerate_id(true);
        unset($_SESSION['impersonate_user_id']);
        self::$user = null;
        self::$session = null;
        self::$rolesCache = null;
        self::$permsCache = null;
    }

    private static function setCookie(string $value, int $ttl): void
    {
        setcookie(self::cookieName(), $value, [
            'expires' => $ttl > 0 ? time() + $ttl : ($ttl < 0 ? time() - 3600 : 0),
            'path' => '/',
            'domain' => (string)config('session.cookie_domain', ''),
            'secure' => Framed::secure(),
            'httponly' => true,
            'samesite' => Framed::sameSite(),
        ]);
    }

    /* ---- Роли ---- */

    /** Глобальные роли текущего пользователя (слаги). */
    public static function globalRoles(): array
    {
        if (!self::check()) return [];
        if (self::$rolesCache === null || (self::$rolesCache['uid'] ?? 0) !== self::id()) {
            self::$rolesCache = ['uid' => self::id(), 'roles' => User::globalRoleSlugs((int)self::id())];
        }
        return self::$rolesCache['roles'];
    }

    public static function isSuperadmin(): bool
    {
        return in_array('superadmin', self::globalRoles(), true);
    }

    /**
     * Есть ли у текущего пользователя право (v4.3).
     *
     * Суперадмину разрешено всё без обращения к таблице: права ему не
     * выдаются галочками именно затем, чтобы их нельзя было снять с себя и
     * запереть систему.
     *
     * Права объединяются по всем ролям человека: роли добавляют возможности,
     * а не ограничивают друг друга.
     */
    /**
     * Есть ли право — и, если спросили, в конкретной области.
     *
     * Без области вопрос звучит «есть ли оно у человека вообще»: так работала
     * проверка до появления областей, и так же она нужна там, где объект ещё
     * не выбран — на входе в раздел, в меню, в поиске. Границу «только своё»
     * дальше держит сужение списков.
     *
     * С областью вопрос точнее: «можно ли ему это ЗДЕСЬ». Так спрашивают там,
     * где объект известен, — и тогда право, выданное на соседний портал, в
     * этом уже не считается.
     */
    public static function can(string $perm, ?Scope $scope = null): bool
    {
        if (!self::check()) return false;
        if (self::isSuperadmin()) return true;
        $uid = (int)self::id();

        if ($scope !== null) {
            /* Не кэшируем: областей много, а спрашивают про них точечно —
               кэш на каждую область стоил бы больше, чем экономил. */
            return in_array($perm, AccessResolver::permsFor($uid, $scope), true);
        }

        if (self::$permsCache === null || (self::$permsCache['uid'] ?? 0) !== $uid) {
            self::$permsCache = ['uid' => $uid, 'perms' => \App\Models\Role::permissionsOfUser($uid)];
        }
        return in_array($perm, self::$permsCache['perms'], true);
    }

    /** Нет права — 403. Отдельным методом, чтобы вызов читался одной строкой. */
    public static function requirePermission(string $perm): void
    {
        if (!self::can($perm)) ErrorPage::render(403);
    }

    /** Уровень доступа текущего пользователя (сводка для сервисов). */
    public static function level(): int
    {
        $u = self::user();
        return $u ? AccessLevel::of($u) : 0;
    }

    /** Superadmin реального пользователя (для имперсонизации). */
    public static function isSuperadminReal(): bool
    {
        $real = self::$user;
        return $real && in_array('superadmin', User::globalRoleSlugs((int)$real['id']), true);
    }

    public static function isAdmin(): bool
    {
        $roles = self::globalRoles();
        return in_array('superadmin', $roles, true) || in_array('admin', $roles, true);
    }

    /** id клиентов, где пользователь client_admin (для делегированной админки). */
    public static function clientAdminIds(): array
    {
        if (!self::check()) return [];
        return User::clientAdminClientIds((int)self::id());
    }

    /** Пускаем в /admin: глобальные админы или client_admin хотя бы одного портала. */
    public static function requireAdminArea(): void
    {
        if (!self::check()) {
            $_SESSION['return_to'] = $_SERVER['REQUEST_URI'] ?? '/admin';
            redirect('/login');
        }
        if (self::isAdmin() || self::clientAdminIds() !== []) {
            // 2FA для админ-ролей — опциональная политика (включается в настройках)
            if (Setting::get('policy.admin_require_2fa') === '1') {
                $u = self::realUser();
                if (empty($u['totp_enabled_at'])) {
                    Flash::info('Политика инсталляции требует 2FA для админ-ролей. Включите её — и админка откроется.');
                    redirect('/profile/security');
                }
            }
            return;
        }
        ErrorPage::render(403);
    }

    public static function requireSuperadmin(): void
    {
        if (!self::isSuperadmin() || self::impersonating()) ErrorPage::render(403);
    }
}
