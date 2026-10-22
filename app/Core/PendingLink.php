<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * «Войти и привязать» (v4.88): сервис, подтвердивший личность, привязывается
 * к аккаунту, в который человек затем войдёт.
 *
 * Экран «Создать аккаунт?» находит похожий аккаунт (DuplicateHint), но
 * связать их сам не вправе: совпавшие имя и дата рождения — не доказательство.
 * Доказательство — вход в найденный аккаунт любым его способом (пароль,
 * ключ, ссылка, второй фактор). Поэтому связка ждёт в сессии и применяется
 * в LoginFlow::complete — там, куда сходятся все пути входа, и только после
 * второго фактора. Живёт десять минут, как незавершённая регистрация.
 */
final class PendingLink
{
    private const TTL = 600;

    /** Отложить связку: данные — из незавершённой регистрации после OAuth. */
    public static function stash(array $pending): void
    {
        $_SESSION['pending_link'] = [
            'provider' => (string)$pending['provider'],
            'provider_title' => (string)$pending['provider_title'],
            'uid' => (string)$pending['uid'],
            'profile' => (array)$pending['profile'],
            't' => time(),
        ];
    }

    /** @return array{provider: string, provider_title: string, uid: string, profile: array, t: int}|null */
    public static function peek(): ?array
    {
        $p = $_SESSION['pending_link'] ?? null;
        if (!is_array($p) || (int)($p['t'] ?? 0) < time() - self::TTL) {
            unset($_SESSION['pending_link']);
            return null;
        }
        return $p;
    }

    public static function forget(): void
    {
        unset($_SESSION['pending_link']);
    }

    /**
     * Привязать отложенный сервис к вошедшему. Связка сервиса с другим
     * аккаунтом, появившаяся за эти минуты, не перебивается.
     */
    public static function applyTo(array $user): void
    {
        $p = self::peek();
        self::forget();
        if ($p === null) return;
        $taken = DB::row('SELECT user_id FROM {linked_identities} WHERE provider = ? AND provider_uid = ?',
            [$p['provider'], $p['uid']]);
        if ($taken) {
            if ((int)$taken['user_id'] !== (int)$user['id']) {
                Flash::error(t(':provider уже привязан к другому аккаунту — эту связку не трогаем.', ['provider' => $p['provider_title']]));
            }
            return;
        }
        DB::insert('linked_identities', [
            'user_id' => (int)$user['id'],
            'provider' => $p['provider'],
            'provider_uid' => $p['uid'],
            'meta' => json_encode($p['profile'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
        Audit::log('social.linked', ['actor' => (int)$user['id'], 'on' => (int)$user['id'],
            'meta' => ['provider' => $p['provider'], 'via' => 'duplicate_hint']]);
        ProfileAbsorb::apply((int)$user['id'], $p['provider'], $p['profile']);
        Flash::success(t(':provider привязан к аккаунту.', ['provider' => $p['provider_title']]));
    }
}
