<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\OneTimeToken;
use App\Models\User;

/**
 * «Сначала подтвердите почту» для новых аккаунтов (v4.72).
 *
 * Живой случай 21.09: человек заводил аккаунты на адреса-псевдонимы и входил
 * сразу — регистрация пускала внутрь до подтверждения, а подтвердить можно
 * было когда-нибудь потом или никогда. Теперь владелец может закрыть эту
 * дверь: аккаунт, заведённый регистрацией, входит только после подтверждения.
 *
 * В письме — и код, и ссылка (v4.74): кому удобнее ввести код, вводит код, кому
 * удобнее нажать — нажимает. В 4.72 это были два режима на выбор владельца, и
 * человеку, который привык к другому способу, выбор не оставляли. Правило
 * касается только новых регистраций: у них ставится флаг users.email_gate.
 * Действующие аккаунты, вход через сервисы, перенос с порталов, импорт и API
 * флага не получают и живут как раньше. Выключил владелец правило — флаг
 * перестаёт что-либо значить, запертых не остаётся.
 */
final class EmailGate
{
    public const MODES = ['off', 'on'];

    /** Сколько живёт ожидание в сессии: дольше человек уже ушёл. */
    private const SESSION_TTL = 3600;

    /**
     * off — как раньше, on — новый аккаунт входит после подтверждения. Значения
     * 4.72 («link», «code») — это тоже «on»: у установки, где правило уже
     * включено, оно не выключится само после обновления.
     */
    public static function mode(): string
    {
        $m = (string)Setting::get('policy.verify_email', 'off');
        return in_array($m, ['on', 'link', 'code'], true) ? 'on' : 'off';
    }

    public static function active(): bool
    {
        return self::mode() !== 'off';
    }

    /** Закрыт ли вход этому человеку до подтверждения почты. */
    public static function holds(?array $user): bool
    {
        return $user !== null && self::active()
            && (int)($user['email_gate'] ?? 0) === 1
            && ($user['email_verified_at'] ?? null) === null
            && !empty($user['email']);
    }

    /**
     * Остановить у двери: запомнить, кто ждёт, отправить письмо, показать экран.
     *
     * Письмо — не на каждое нажатие «Войти»: иначе вход с неподтверждённой
     * почтой превращается в кнопку «засыпать ящик письмами». Пять в час на
     * человека; дальше экран тот же, а новое письмо — кнопкой «ещё раз», у
     * которой свой счётчик.
     */
    public static function stop(array $user, bool $remember): never
    {
        $_SESSION['email_gate'] = ['user_id' => (int)$user['id'], 'remember' => $remember, 'at' => time()];
        if (RateLimiter::hit('gate:send:' . (int)$user['id'], 5, 3600)) self::send($user);
        redirect('/verify/wait');
    }

    /** Кто сейчас ждёт подтверждения в этой сессии, если ждёт. */
    public static function waiting(): ?array
    {
        $g = $_SESSION['email_gate'] ?? null;
        if (!is_array($g) || (int)($g['at'] ?? 0) < time() - self::SESSION_TTL) {
            unset($_SESSION['email_gate']);
            return null;
        }
        $user = User::find((int)$g['user_id']);
        if ($user === null || !User::canSignIn($user)) {
            unset($_SESSION['email_gate']);
            return null;
        }
        return $user;
    }

    /** Запомнить ли вход, когда дверь откроется, — как отметил человек на форме. */
    public static function remember(): bool
    {
        return !empty($_SESSION['email_gate']['remember']);
    }

    /** Письмо подтверждения: и код, и ссылка — одним письмом. */
    public static function send(array $user): void
    {
        if (empty($user['email'])) return;
        $code = OneTimeToken::issueCode((int)$user['id'], 'email_code');
        $token = OneTimeToken::issue((int)$user['id'], 'email_verify');
        Mailer::sendLetter((string)$user['email'], 'verify_code', [
            'name' => user_name($user), 'code' => $code, 'url' => base_url('/verify/' . $token),
        ]);
    }

    /** Почта подтверждена: отметка, статус, дверь открыта. */
    public static function pass(array $user, string $how): void
    {
        $data = ['email_verified_at' => now()];
        if (($user['status'] ?? '') === 'pending') $data['status'] = 'active';
        if (DB::columnExists('users', 'email_gate')) $data['email_gate'] = 0;
        User::update((int)$user['id'], $data);
        Audit::log('email.verify', ['actor' => (int)$user['id'], 'on' => (int)$user['id'], 'meta' => ['how' => $how]]);
    }

    /** Отпустить ожидание в сессии (вход состоялся или человек ушёл). */
    public static function forget(): void
    {
        unset($_SESSION['email_gate']);
    }
}
