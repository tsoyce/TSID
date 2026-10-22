<?php
/**
 * Что портал делает по событиям {{APP_NAME}}.
 *
 * ЭТО ЕДИНСТВЕННОЕ МЕСТО, которое обязан дописать портал. Всё остальное в
 * наборе работает само: вход, возврат, подпись, проверка токена, а с v4.62 —
 * и закрытие всех сессий человека, которого удалили, заблокировали или
 * которому закрыли доступ.
 *
 * Событие приходит на backchannel.php, тот проверяет подпись и зовёт функцию
 * отсюда. Если функции нет или она вернула false — событие НЕ теряется: оно
 * ложится в var/sso-events.ndjson, и его можно разобрать позже. Молча
 * пропадать событию нельзя: именно так и получается портал, у которого живёт
 * аккаунт человека, удалённого в SSO полгода назад.
 *
 * $sub — вечный идентификатор человека в {{APP_NAME}}. Это и есть ключ, по
 * которому портал обязан находить свою запись. Не email: почта меняется.
 */

declare(strict_types=1);

/**
 * Человека удалили или заблокировали в {{APP_NAME}}.
 *
 * Сессии мост уже погасил сам. Здесь — учётная запись:
 *
 *   user.blocked — только отключить: разблокируют — войдёт снова.
 *
 *   user.deleted — отключить, а что делать с данными, говорит $payload['mode']:
 *     scheduled — удаление можно отменить до $payload['restore_until'].
 *                 Отключить, данные НЕ трогать: отменит — придёт user.restored;
 *     final     — срок вышел, аккаунт в {{APP_NAME}} обезличен, человек не
 *                 вернётся. Удалить или обезличить данные по своим правилам;
 *     purged    — администратор стёр аккаунт сразу и насовсем. Удалить всё;
 *     merged    — аккаунт слит с другим, новый sub — $payload['merged_into'].
 *                 Перенести записи на него, а не удалять;
 *     нет поля  — {{APP_NAME}} старше 4.62: как scheduled.
 *
 * Вернуть true, если обработали. false или исключение — событие уйдёт в файл,
 * и его будет видно в самопроверке.
 */
function sso_on_user_gone(string $sub, string $event, array $payload): bool
{
    // ЗАМЕНИТЕ на работу со своей базой. Пример для типовой схемы:
    //
    // $mode = $event === 'user.deleted' ? (string)($payload['mode'] ?? 'scheduled') : 'blocked';
    // if ($mode === 'merged' && !empty($payload['merged_into'])) {
    //     // если записи с merged_into у вас ещё нет — просто перепривязать
    //     $pdo->prepare('UPDATE users SET sso_sub = ? WHERE sso_sub = ?')
    //         ->execute([$payload['merged_into'], $sub]);
    //     return true;
    // }
    // if ($mode === 'final' || $mode === 'purged') {
    //     $pdo->prepare('DELETE FROM users WHERE sso_sub = ?')->execute([$sub]);   // и его данные
    //     return true;
    // }
    // $pdo->prepare('UPDATE users SET is_active = 0, disabled_at = NOW() WHERE sso_sub = ?')
    //     ->execute([$sub]);
    // return true;

    return false;   // не обработано — событие ляжет в var/sso-events.ndjson
}

/**
 * Удаление отменено в {{APP_NAME}} — человек вернулся (v4.62).
 *
 * Включить запись, отключённую по user.deleted с mode = scheduled.
 */
function sso_on_user_restored(string $sub, array $payload): bool
{
    // $pdo->prepare('UPDATE users SET is_active = 1, disabled_at = NULL WHERE sso_sub = ?')
    //     ->execute([$sub]);
    // return true;

    return false;
}

/**
 * Профиль изменился: имя, почта, аватар.
 *
 * Свежие данные брать из /userinfo — в событии их нет намеренно, чтобы
 * подпись не таскала персональные данные по сети лишний раз.
 */
function sso_on_user_updated(string $sub, array $payload): bool
{
    return false;
}

/**
 * Человека убрали из ЭТОГО портала, а сам аккаунт в {{APP_NAME}} жив (v4.64).
 *
 * Сессии мост уже закрыл. Что делать с учётной записью, говорит $payload['mode']:
 *   closed  — администратор нажал «Закрыть»: отключить, данные НЕ трогать —
 *             откроют доступ снова, придёт access.granted;
 *   removed — «Убрать»: стереть учётную запись и её данные;
 *   нет поля — {{APP_NAME}} старше 4.64: только сессии, запись не трогать.
 */
function sso_on_access_revoked(string $sub, array $payload): bool
{
    // $mode = (string)($payload['mode'] ?? '');
    // if ($mode === 'removed') { /* удалить запись и данные по sso_sub */ return true; }
    // if ($mode === 'closed')  { /* UPDATE users SET is_active = 0 WHERE sso_sub = ? */ return true; }
    return false;
}

/**
 * Доступ к порталу открыли снова (v4.64): включить запись, отключённую по
 * access.revoked с mode = closed. Заблокированную вашим администратором — не трогать.
 */
function sso_on_access_granted(string $sub, array $payload): bool
{
    return false;
}

/**
 * Кто вправе открыть экран настроек SSO — sso/admin.php (v4.63).
 *
 * По умолчанию — администратор этого портала в {{APP_NAME}} (уровень 70 и
 * выше) или владелец установки. Если у вас своя проверка администратора —
 * поставьте её сюда. $user — то, что callback.php положил в
 * $_SESSION['sso_user'], или null, если не вошли.
 */
function sso_is_admin(?array $user): bool
{
    if ($user === null) return false;
    $owner = (string)(sso_secrets()['owner_sub'] ?? '');
    return (int)($user['access_level'] ?? 0) >= 70
        || ($owner !== '' && hash_equals($owner, (string)($user['sub'] ?? '')));
}
