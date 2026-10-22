<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * Чистка помеченных на удаление аккаунтов — пачкой, а не по одному.
 *
 * Удаление окончательно и поштучно: опасная зона, пароль, набранное имя. Для
 * одного аккаунта это правильно — решение осознанное и редкое. Но после
 * пробного запуска, импорта с ошибкой или чужого бота помеченных оказывается
 * не один, а сотня, и сто раз пройти путь «зона → пароль → имя → удалить» это
 * не защита, а способ отправить человека чистить базу руками. Вот там он и
 * ошибётся по-настоящему.
 *
 * Почему только аккаунты. Порталы и организации отложенного удаления не имеют
 * вовсе: подтвердил — и их нет. Копиться им негде, и «массовая чистка
 * удалённых порталов» удаляла бы пустоту. Отложенное удаление есть только у
 * человека, и только потому, что человек может передумать.
 *
 * Границы пачки:
 *  · берётся ТОЛЬКО статус `deleted`. Живой аккаунт сюда не попадает никаким
 *    фильтром — «удалить всех неактивных» здесь невозможно в принципе;
 *  · суперадмины и владелец не удаляются, даже если помечены;
 *  · срок «передумать» соблюдается: аккаунт младше срока хранения не трогаем,
 *    иначе пачка отменяет то, ради чего отложенное удаление и заведено;
 *  · сначала список и число, удаление — вторым нажатием.
 */
final class BulkPurge
{
    /** Максимум за один заход: длинная работа по тысячам строк рвётся по времени. */
    public const CHUNK = 200;

    /** Сколько дней аккаунт лежит помеченным, прежде чем его можно смести пачкой. */
    public const HOLD_DAYS = 14;

    /**
     * Кого удалит пачка. Список, а не только счётчик: человек должен увидеть
     * имена до того, как нажмёт.
     *
     * @return list<array>
     */
    public static function plan(): array
    {
        $rows = DB::all(
            'SELECT id, public_id, email, login, display_name, first_name, last_name,
                    account_number, deleted_at
             FROM {users} WHERE status = ? ORDER BY deleted_at LIMIT ' . self::CHUNK, ['deleted']
        );
        $ownerId = Owner::id();
        $edge = time() - self::HOLD_DAYS * 86400;
        return array_values(array_filter($rows, static function (array $u) use ($ownerId, $edge): bool {
            if ((int)$u['id'] === $ownerId) return false;
            if (in_array('superadmin', User::globalRoleSlugs((int)$u['id']), true)) return false;
            /* Без отметки времени судить не о чем — пропускаем: такие остаются
               поштучной опасной зоне. */
            if (empty($u['deleted_at'])) return false;
            return strtotime((string)$u['deleted_at'] . ' UTC') <= $edge;
        }));
    }

    /** Сколько помечено всего — включая тех, чей срок ещё не вышел. */
    public static function markedTotal(): int
    {
        return (int)DB::val('SELECT COUNT(*) FROM {users} WHERE status = ?', ['deleted']);
    }

    /**
     * Удалить всех из плана.
     *
     * План строится заново тем же кодом, что показывал экран: «увидел одно,
     * удалилось другое» должно быть невозможно по построению.
     *
     * @return array{users:int, rows:int}
     */
    public static function run(): array
    {
        $done = ['users' => 0, 'rows' => 0];
        foreach (self::plan() as $u) {
            /* До удаления: событие рассылается по строкам доступа, а purge()
               их сносит — см. тот же порядок в карточке человека. */
            \App\Oidc\EventDispatcher::userDeleted($u, 'purged');
            $removed = User::purge((int)$u['id']);
            $done['rows'] += array_sum($removed);
            $done['users']++;
            /* В файловый лог поимённо: запись в аудите сошлётся на аккаунт,
               которого больше нет, и восстановить по ней будет нечего. */
            Log::warning('admin.user.purge', [
                'email' => $u['email'], 'sub' => $u['public_id'], 'bulk' => true,
            ]);
            WebhookDispatcher::emit('user.deleted',
                ['sub' => $u['public_id'], 'email' => $u['email']]);
        }
        if ($done['users'] > 0) {
            Audit::log('admin.bulk.purge', ['meta' => $done]);
            AdminNotify::say('bulk.purged',
                fn() => t('Массовая чистка: удалено :n :word, строк — :rows.', [
                    'n' => $done['users'],
                    'word' => plural($done['users'], 'аккаунт', 'аккаунта', 'аккаунтов'),
                    'rows' => $done['rows'],
                ]),
                fn() => [t('Кто') => user_handle(Auth::user() ?? []), t('Когда') => fmt_dt(now())]);
        }
        return $done;
    }
}
