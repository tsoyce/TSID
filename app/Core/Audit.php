<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Аудит бизнес-событий (Приложение C). Пишется синхронно.
 * meta — только безопасные детали; секреты и токены — никогда.
 */
final class Audit
{
    /**
     * @param array $opts ['actor'=>id|null, 'on'=>id|null, 'client'=>id|null, 'meta'=>array]
     * actor по умолчанию — текущий пользователь (реальный, не имперсонируемый).
     */
    public static function log(string $event, array $opts = []): void
    {
        try {
            $actor = array_key_exists('actor', $opts) ? $opts['actor'] : (Auth::realUser()['id'] ?? null);
            $meta = $opts['meta'] ?? null;
            if (Auth::impersonating()) {
                $meta = ($meta ?? []) + ['impersonating' => true];
            }
            $clientId = isset($opts['client']) ? (int)$opts['client'] : null;

            /* Организацию проставляем сами, если событие про портал: проект
               принадлежит организации, и его события принадлежат ей же.
               Иначе «покажи, что происходило в моей организации» отвечало бы
               только про саму организацию — переименования да смену состава, —
               а всё, ради чего она заведена, оставалось бы за кадром (v4.27).

               При записи, а не выборкой потом: портал может сменить владельца,
               и пересчёт задним числом переписал бы историю. Событие
               принадлежит той организации, в которой произошло. */
            $orgId = isset($opts['org']) ? (int)$opts['org'] : null;
            if ($orgId === null && $clientId !== null) {
                $orgId = AccessResolver::orgOfClient($clientId);
            }

            $row = [
                'created_at' => now(),
                'actor_user_id' => $actor !== null ? (int)$actor : null,
                'on_user_id' => isset($opts['on']) ? (int)$opts['on'] : null,
                'client_id' => $clientId,
                'event' => mb_substr($event, 0, 48),
                'ip' => PHP_SAPI === 'cli' ? null : ip_bin(),
                'user_agent' => PHP_SAPI === 'cli' ? 'cli' : request_ua(),
                'meta' => $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ];

            /* Третье измерение объекта — организация. Пишем ТОЛЬКО если колонка
               есть: код обновляют раньше, чем применяют миграцию, и на такой
               установке запись падала на каждом событии. Падение проглочено
               (см. catch ниже) — то есть журнал молча переставал вестись весь,
               целиком, а узнавалось об этом из файла с ошибками. Именно это и
               случилось на боевой: «Unknown column 'org_id'» на каждый вход,
               каждую правку настроек, каждый ключ.

               Колонка появилась в миграции 015; проверка кэшируется на
               соединение, так что стоит она один запрос за процесс. */
            if (DB::columnExists('audit_log', 'org_id')) {
                $row['org_id'] = $orgId;
            }

            DB::insert('audit_log', $row);
        } catch (\Throwable $e) {
            Log::exception($e, 'audit_write_failed');
        }
    }

    /**
     * Событие человеческими словами — один словарь на весь продукт (v4.70).
     *
     * Словарь жил в истории объекта, а дашборд и журнал аудита показывали
     * машинные имена: «client.installed», «client.users_secret». Незнакомое
     * событие остаётся машинным именем — по нему ищут в общих логах.
     */
    public static function title(string $event): string
    {
        return isset(self::TITLES[$event]) ? t(self::TITLES[$event]) : $event;
    }

    /**
     * Группы событий для фильтра журнала — по началу имени (v4.71).
     * Фильтр просил набрать «login.» или «admin.»; теперь выбирают словами.
     */
    public const GROUPS = [
        'login.' => 'Входы', 'logout' => 'Выходы', '2fa.' => 'Второй фактор',
        'register' => 'Регистрации', 'password.' => 'Пароли', 'session.' => 'Сессии',
        'code.' => 'Коды авторизации', 'token.' => 'Токены', 'consent.' => 'Согласия',
        'access.' => 'Доступ к порталам', 'account.' => 'Аккаунты', 'profile.' => 'Профили',
        'client.' => 'События порталов', 'admin.' => 'Действия администраторов',
        'api.' => 'Через API', 'scim.' => 'Через SCIM',
    ];

    /** Событие о тревоге: отказ, повтор, перебор — красным в списках. */
    public static function alarming(string $event): bool
    {
        return (bool)preg_match('~fail|replay|reuse|locked|denied|refused~', $event);
    }

    /**
     * Детали события строкой «ключ: значение · …» вместо сырого JSON.
     * Слеши не экранируются, вложенное — компактным JSON.
     */
    public static function metaText(?string $json): string
    {
        if ($json === null || $json === '') return '';
        $meta = json_decode($json, true);
        if (!is_array($meta)) return $json;
        $parts = [];
        foreach ($meta as $k => $v) {
            if (is_bool($v)) $v = $v ? t('да') : t('нет');
            elseif (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            elseif ($v === null) $v = '—';
            $parts[] = $k . ': ' . $v;
        }
        return implode(' · ', $parts);
    }

    private const TITLES = [
        'login.success' => 'Вход выполнен',
        'login.passkey' => 'Вход по ключу устройства',
        'login.magic_link' => 'Вход по ссылке из письма',
        'login.fail' => 'Неудачная попытка входа',
        'login.2fa_fail' => 'Неверный код второго фактора',
        'login.locked' => 'Вход заблокирован после попыток',
        'logout' => 'Выход',
        'logout.everywhere' => 'Выход на всех устройствах',
        'register' => 'Регистрация',
        'register.refused' => 'Отказ в регистрации: устаревший браузер',
        'email.verify' => 'Почта подтверждена',
        'email.verify_resent' => 'Письмо подтверждения отправлено ещё раз',
        'email.pending_changed' => 'Исправлен неподтверждённый адрес',
        'email.code_fail' => 'Неверный код подтверждения почты',
        'admin.ip.block' => 'Заблокирован адрес',
        'admin.ip.unblock' => 'Снята блокировка адреса',
        'password.reset_request' => 'Запрошен сброс пароля',
        '2fa.enabled' => 'Включена двухфакторная защита',
        '2fa.disabled' => 'Выключена двухфакторная защита',
        '2fa.recovery_used' => 'Использован код восстановления',
        'pin.set' => 'Задан PIN замка',
        'pin.unlock' => 'Замок снят PIN-кодом',
        'pin.cleared' => 'PIN замка сброшен',
        'profile.update' => 'Профиль изменён',
        'profile.absorbed' => 'Профиль объединён с другим',
        'account.merged' => 'Аккаунты объединены',
        'account.export_request' => 'Запрошена выгрузка данных',
        'account.delete_done' => 'Аккаунт обезличен',
        'session.revoke' => 'Сессия закрыта',
        'session.revoke_all' => 'Закрыты все сессии',
        'code.issued' => 'Выдан код авторизации',
        'code.replay' => 'Повторное использование кода — отказано',
        'token.refresh' => 'Обновлён токен',
        'token.refresh_reuse' => 'Повторное использование refresh-токена — сессии закрыты',
        'token.revoke' => 'Токен отозван',
        'consent.granted' => 'Согласие дано',
        'consent.denied' => 'В согласии отказано',
        'access.granted' => 'Выдан доступ',
        'access.revoked' => 'Доступ отозван',
        'access.requested' => 'Запрошен доступ',
        'access.denied' => 'В доступе отказано',
        'admin.user.update' => 'Изменён профиль',
        'admin.user.block' => 'Пользователь заблокирован',
        'admin.user.unblock' => 'Блокировка снята',
        'admin.user.delete' => 'Аккаунт удалён',
        'admin.user.purge' => 'Аккаунт удалён окончательно',
        'admin.user.login_link' => 'Выдана ссылка для входа',
        'admin.bulk.purge' => 'Удалены аккаунты пачкой',
        'admin.import.run' => 'Импорт людей',
        'admin.invite.create' => 'Создано приглашение',
        'admin.role.assign' => 'Назначена роль',
        'admin.role.revoke' => 'Роль снята',
        'admin.role.create' => 'Роль заведена',
        'admin.role.delete' => 'Роль удалена',
        'admin.role.permissions' => 'Изменены права роли',
        'admin.impersonate.start' => 'Вход от имени',
        'admin.impersonate.stop' => 'Выход из режима «от имени»',
        'admin.org.create' => 'Организация создана',
        'admin.org.update' => 'Изменены реквизиты',
        'admin.org.toggle' => 'Включена или выключена',
        'admin.org.delete' => 'Организация удалена',
        'admin.org.member_add' => 'Добавлен участник',
        'admin.org.member_remove' => 'Участник удалён',
        'admin.org.member_role' => 'Изменена роль участника',
        'admin.org.role_grant' => 'Выдана роль в организации',
        'admin.org.role_revoke' => 'Снята роль в организации',
        'admin.org.client_grant' => 'Выдан доступ к порталу',
        'admin.org.client_revoke' => 'Отозван доступ к порталу',
        'admin.client.create' => 'Портал подключён',
        'admin.client.update' => 'Изменены реквизиты',
        'admin.client.delete' => 'Портал удалён',
        'admin.client.slug' => 'Изменено машинное имя портала',
        'admin.client.secret_rotate' => 'Перевыпущен секрет',
        'admin.client.redirect_accept' => 'Принят адрес возврата',
        'admin.client.role_grant' => 'Выдана роль в сервисе',
        'admin.client.role_revoke' => 'Снята роль в сервисе',
        'admin.client.role_adopt' => 'Заведена роль от портала',
        'admin.client.role_relevel' => 'Выставлен уровень роли',
        'admin.client.test_event' => 'Отправлено пробное событие',
        'admin.client.look' => 'Изменён экран входа',
        'api.client.create' => 'Портал подключён через API',
        'api.client.update' => 'Портал изменён через API',
        'api.user.create' => 'Человек заведён через API',
        'scim.user.created' => 'Человек заведён через SCIM',
        'client.installed' => 'Портал установил набор',
        'client.install_link' => 'Выдана ссылка установки',
        'client.users_secret' => 'Выдан ключ переноса',
        'client.users_secret_revoked' => 'Ключ переноса отозван',
        'client.users_pull' => 'Люди перенесены с портала',
        'client.role_map' => 'Изменена карта ролей',
        'client.manifest' => 'Портал прислал манифест',
        'admin.settings.update' => 'Изменены настройки',
        'admin.settings.export' => 'Настройки выгружены',
        'admin.settings.import' => 'Настройки загружены',
        'admin.appearance.update' => 'Изменено оформление',
        'admin.backup.download' => 'Скачана резервная копия',
        'legal.updated' => 'Изменён документ',
        'danger.open' => 'Открыта опасная зона',
        'system.owner.set' => 'Назначен владелец установки',
        'housekeeping.orphans' => 'Уборка осиротевших записей',
    ];
}
