<?php

declare(strict_types=1);

namespace App\Core;

/**
 * История аккаунта для самого человека (v5.3).
 *
 * До v5.3 кабинет показывал пять последних входов — и всё. Чужой вход неделю
 * назад, перебор пароля, отключённый второй фактор человек мог увидеть только
 * в письме, если письма включены. Здесь — то же, что видит админ в журнале,
 * но про себя и словами: входы с устройством и сервисом, неудачные попытки,
 * изменения защиты.
 *
 * Берём события, где человек — объект (on_user_id), и его собственные
 * действия без объекта, кроме служебных: что он делал в админке над
 * порталами и настройками, — не его история, а история установки.
 */
final class UserHistory
{
    /** Машинный шум: обмен кода и обновление токена идут на каждый заход в портал. */
    public const NOISE = ['code.issued', 'token.refresh'];

    /** Чужие для кабинета семейства — действия в админке и от машин. */
    private const FOREIGN = ['admin.%', 'system.%', 'api.%', 'scim.%', 'settings.%', 'housekeeping.%', 'legal.%', 'danger.%', 'install.%'];

    /** Фильтры экрана: ключ => подпись. */
    public static function kinds(): array
    {
        return ['all' => t('Всё'), 'logins' => t('Входы'), 'alerts' => t('Неудачи')];
    }

    /**
     * Страница истории: до $limit событий старше $before (id), новые сверху.
     * @return array{rows: list<array>, more: ?int} more — id, с которого продолжить
     */
    public static function page(int $userId, string $kind = 'all', ?int $before = null, int $limit = 50): array
    {
        $foreign = implode(' AND ', array_fill(0, count(self::FOREIGN), 'a.event NOT LIKE ?'));
        $where = '(a.on_user_id = ? OR (a.actor_user_id = ? AND a.on_user_id IS NULL AND ' . $foreign . '))'
            . ' AND a.event NOT IN (' . implode(', ', array_fill(0, count(self::NOISE), '?')) . ')';
        $params = array_merge([$userId, $userId], self::FOREIGN, self::NOISE);
        if ($kind === 'logins') {
            $where .= ' AND a.' . Stats::loginIn();
            $params = array_merge($params, Stats::LOGIN_EVENTS);
        } elseif ($kind === 'alerts') {
            /* Тот же признак, что красит строки в журнале (Audit::alarming). */
            $where .= " AND (a.event LIKE '%fail%' OR a.event LIKE '%replay%' OR a.event LIKE '%reuse%'
                        OR a.event LIKE '%locked%' OR a.event LIKE '%denied%' OR a.event LIKE '%refused%')";
        }
        if ($before !== null) {
            $where .= ' AND a.id < ?';
            $params[] = $before;
        }
        $rows = DB::all('SELECT a.id, a.created_at, a.event, a.ip, a.user_agent, a.meta, a.client_id, c.name AS client_name
                         FROM {audit_log} a LEFT JOIN {clients} c ON c.id = a.client_id
                         WHERE ' . $where . ' ORDER BY a.id DESC LIMIT ' . ($limit + 1), $params);
        $more = null;
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $more = (int)end($rows)['id'];
        }
        return ['rows' => $rows, 'more' => $more];
    }

    /**
     * Неудачные попытки войти в аккаунт за последние $days дней — для
     * предупреждения сверху: «пароль подбирали 14 раз».
     */
    public static function recentFails(int $userId, int $days = 7): int
    {
        return (int)DB::val('SELECT COUNT(*) FROM {audit_log} WHERE on_user_id = ? AND event = ? AND created_at >= ?',
            [$userId, 'login.fail', now_plus(-$days * 86400)]);
    }

    /** Подпись дня по местному времени: «Сегодня», «Вчера» или дата. */
    public static function dayLabel(string $utc, ?int $now = null): string
    {
        $tz = Stats::tz();
        $day = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
        $today = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz);
        if ($day === $today->format('Y-m-d')) return t('Сегодня');
        if ($day === $today->modify('-1 day')->format('Y-m-d')) return t('Вчера');
        return fmt_dt($utc, 'd.m.Y');
    }
}
