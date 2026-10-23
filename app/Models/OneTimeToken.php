<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\DB;

/** Одноразовые токены: verify/reset/email_change/login_link/delete/client_setup/password_set. */
final class OneTimeToken
{
    public const TTL = [
        'email_verify' => 3 * 86400,
        'password_reset' => 3600,
        'email_change' => 3600,
        /* «Это не я» старому адресу (v4.97.2): свой тип и неделя жизни. Под
           типом email_change его гасила следующая же смена почты — захвативший
           аккаунт делал вторую смену, и ссылка у хозяина умирала. */
        'email_revert' => 7 * 86400,
        'login_link' => 900,
        'account_delete' => 14 * 86400,
        'client_setup' => 7 * 86400,
        /* Полчаса: ссылку установки открывают сразу или не открывают вовсе, а
           отдаёт она реквизиты портала. Не успел — выдай новую, это кнопка. */
        'portal_install' => \App\Core\PortalInstall::TTL,
        'password_set' => 7 * 86400,
        /* Возврат на портал после входа: полчаса с запасом. Дольше вход не
           длится, а короче — не переживёт медленный ввод пароля и восстановление. */
        'authorize_resume' => 1800,
        /* Приглашение в систему: неделя. Столько живёт договорённость «заведи
           себе аккаунт», а дольше держать открытую дверь незачем. */
        'signup_invite' => 7 * 86400,
        /* Сутки: передача владения — решение, которое принимают сегодня, а не
           «когда-нибудь». Долгоживущая ссылка на права владельца в чужом
           почтовом ящике — лишний риск без выигрыша. */
        'owner_transfer' => 86400,
        /* Код подтверждения почты (v4.72): пятнадцать минут — столько ждут
           письмо и вводят код, дольше шестизначному коду жить незачем. */
        'email_code' => 900,
    ];

    /**
     * Срок жизни ссылки: настройка перекрывает константу.
     * Константы остаются умолчаниями — установка без настроек работает как раньше.
     */
    private static function ttl(string $type): int
    {
        return \App\Core\Setting::timeout('token_' . $type, self::TTL[$type] ?? 3600);
    }

    /**
     * Срок жизни токена — тот же, что при выдаче.
     *
     * Нужен письму: оно обязано называть настоящий срок. До этого срок был
     * вписан в текст руками («Ссылка действует 3 дня»), а сам он настраивается
     * и может быть от пятнадцати минут. Сократив его, владелец получал письмо,
     * которое обещает три дня, и человека с протухшей через час ссылкой.
     */
    public static function lifetime(string $type): int
    {
        return self::ttl($type);
    }

    /**
     * Выдать короткий цифровой код вместо ссылки (v4.72); прежние гасятся.
     *
     * Код живёт тем же сроком и в той же таблице, что ссылки. Хэш берётся от
     * пары «человек:код», а не от одного кода: шесть цифр повторяются, и без
     * привязки к человеку чужой код оказался бы в той же строке поиска.
     * Перебор держит ограничение попыток у того, кто код проверяет.
     */
    public static function issueCode(int $userId, string $type, int $digits = 6): string
    {
        DB::run('UPDATE {one_time_tokens} SET consumed_at = ? WHERE user_id = ? AND type = ? AND consumed_at IS NULL',
            [now(), $userId, $type]);
        $code = str_pad((string)random_int(0, 10 ** $digits - 1), $digits, '0', STR_PAD_LEFT);
        DB::insert('one_time_tokens', [
            'user_id' => $userId,
            'type' => $type,
            'token_hash' => Crypto::hashToken($userId . ':' . $code),
            'payload' => null,
            'expires_at' => now_plus(self::ttl($type)),
            'created_at' => now(),
        ]);
        return $code;
    }

    /** Проверить и погасить код человека. true — код верный, живой и погашен этим вызовом. */
    public static function consumeCode(int $userId, string $type, string $code): bool
    {
        $code = (string)preg_replace('~\D~', '', $code);
        if ($code === '') return false;
        $row = DB::row('SELECT id, expires_at FROM {one_time_tokens} WHERE token_hash = ? AND type = ? AND user_id = ? AND consumed_at IS NULL',
            [Crypto::hashToken($userId . ':' . $code), $type, $userId]);
        if (!$row || strtotime($row['expires_at'] . ' UTC') <= time()) return false;
        return self::claim((int)$row['id']);
    }

    /** Выдать токен; прежние того же типа для пользователя гасятся. Возвращает plaintext. */
    public static function issue(?int $userId, string $type, array $payload = []): string
    {
        if ($userId !== null) {
            DB::run('UPDATE {one_time_tokens} SET consumed_at = ? WHERE user_id = ? AND type = ? AND consumed_at IS NULL',
                [now(), $userId, $type]);
        }
        $token = Crypto::randomToken(32);
        DB::insert('one_time_tokens', [
            'user_id' => $userId,
            'type' => $type,
            'token_hash' => Crypto::hashToken($token),
            'payload' => $payload !== [] ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'expires_at' => now_plus(self::ttl($type)),
            'created_at' => now(),
        ]);
        return $token;
    }

    /** Живой токен по значению и типу (payload уже декодирован). */
    public static function peek(string $token, string $type): ?array
    {
        $row = DB::row('SELECT * FROM {one_time_tokens} WHERE token_hash = ? AND type = ?',
            [Crypto::hashToken($token), $type]);
        if (!$row || $row['consumed_at'] !== null) return null;
        if (strtotime($row['expires_at'] . ' UTC') <= time()) return null;
        $row['payload'] = $row['payload'] ? (json_decode($row['payload'], true) ?: []) : [];
        return $row;
    }

    /**
     * Проверить и погасить.
     *
     * Гасим условным UPDATE и смотрим, досталась ли строка нам (v4.70). Раньше
     * проверка и гашение шли порознь, и два одновременных запроса с одной
     * ссылкой — сброс пароля, смена почты, передача владения — проходили оба.
     */
    public static function consume(string $token, string $type): ?array
    {
        $row = self::peek($token, $type);
        if ($row === null) return null;
        return self::claim((int)$row['id']) ? $row : null;
    }

    /** Погасить строку. true — погасили мы, false — её уже погасил другой запрос. */
    public static function claim(int $id): bool
    {
        return DB::update('one_time_tokens', ['consumed_at' => now()], 'id = ? AND consumed_at IS NULL', [$id]) === 1;
    }

    public static function cancelByTypeForUser(int $userId, string $type): void
    {
        DB::run('UPDATE {one_time_tokens} SET consumed_at = ? WHERE user_id = ? AND type = ? AND consumed_at IS NULL',
            [now(), $userId, $type]);
    }

    public static function purgeExpired(): int
    {
        return DB::run('DELETE FROM {one_time_tokens} WHERE expires_at < ?', [now_plus(-7 * 86400)])->rowCount();
    }
}
