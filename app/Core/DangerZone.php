<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Опасная зона: необратимое действие за отдельной страницей и паролем (v4.2).
 *
 * Появилась для удаления портала, но механизм от портала не зависит и потому
 * живёт здесь: организации, а завтра что-нибудь ещё, нуждаются ровно в том же.
 * Копировать проверку по контроллерам — верный способ однажды забыть её в
 * одном из них, и именно там, где она нужнее всего.
 *
 * Область (`scope`) — строка вида `client:5`, `org:3`. Открытая зона привязана
 * к конкретному объекту: подтвердив пароль ради одного портала, человек не
 * должен получить открытую дверь ко всем остальным.
 */
final class DangerZone
{
    /** Сколько живёт открытая зона. Десять минут — на одно осознанное действие. */
    public const TTL = 600;

    /** Сколько попыток пароля до блокировки. Перебор здесь не отличается от входа. */
    private const ATTEMPTS = 5;
    private const ATTEMPTS_WINDOW = 900;

    public static function unlocked(string $scope): bool
    {
        return (int)($_SESSION['danger_zone'][$scope] ?? 0) > time();
    }

    /**
     * Проверить пароль и открыть зону.
     *
     * Пароль спрашиваем не для красоты: сессия админа живёт часами, и от чужой
     * открытой вкладки CSRF-токен не защищает. Аккаунт без пароля (вход только
     * по passkey или соцсети) зону не открывает — иначе проверка стала бы
     * необязательной для тех, у кого её нет.
     *
     * @return array{ok: bool, error: string}
     */
    public static function unlock(string $scope, string $password): array
    {
        RateLimiter::guard('danger:' . (Auth::id() ?? 0), self::ATTEMPTS, self::ATTEMPTS_WINDOW);

        $me = Auth::realUser();
        if (!$me) return ['ok' => false, 'error' => t('Сессия истекла — войдите заново.')];
        if (empty($me['password_hash'])) {
            return ['ok' => false, 'error' => t('У вашего аккаунта нет пароля — задайте его в профиле, без него опасную зону не открыть.')];
        }
        if (!password_verify($password, (string)$me['password_hash'])) {
            Audit::log('danger.denied', ['meta' => ['scope' => $scope]]);
            return ['ok' => false, 'error' => t('Пароль не подошёл.')];
        }

        $_SESSION['danger_zone'][$scope] = time() + self::TTL;
        Audit::log('danger.open', ['meta' => ['scope' => $scope]]);
        return ['ok' => true, 'error' => ''];
    }

    /** Закрыть зону: после выполненного действия открытая дверь ни к чему. */
    public static function close(string $scope): void
    {
        unset($_SESSION['danger_zone'][$scope]);
    }
}
