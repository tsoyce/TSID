<?php

declare(strict_types=1);

namespace App\Social;

use App\Core\Setting;

/**
 * Telegram Login Widget. Не OAuth: виджет отдаёт данные прямо в браузер,
 * подлинность проверяется HMAC-SHA256 на секрете бота (sha256 от токена как ключ).
 * «client_id» здесь — имя бота, «secret» — его токен.
 */
final class TelegramProvider extends Provider
{
    public function id(): string { return 'telegram'; }
    public function title(): string { return 'Telegram'; }

    /**
     * Вход переходом (v4.87): страница Telegram в том же окне, а не виджет.
     *
     * Владелец 26.09 входил в Портфолио через Telegram и насчитал три окна: вкладка
     * портала, окно SSO со страницей-виджетом, и окно, которое виджет открывает
     * сам. У Telegram есть и переход: oauth.telegram.org/auth с return_to —
     * им виджет пользуется там, где окна нельзя (браузеры внутри приложений).
     * После подтверждения Telegram возвращает на return_to с данными входа во
     * фрагменте адреса (#tgAuthResult=…), подпись та же, что у виджета.
     * Номер бота — начало его токена (до двоеточия); нет номера — нет и
     * перехода, остаётся страница с виджетом.
     */
    public function authUrl(string $state): string
    {
        $bot = $this->botId();
        if ($bot === '') return base_url('/social/telegram/start');
        return 'https://oauth.telegram.org/auth?' . http_build_query([
            'bot_id' => $bot,
            'origin' => \App\Core\Csp::origin(base_url('/')),
            'request_access' => 'write',
            'lang' => lang(),
            'return_to' => base_url('/social/telegram/return?state=' . rawurlencode($state)),
        ]);
    }

    /** Номер бота — из его токена «123456789:AA…»; пусто, если токен не такой. */
    public function botId(): string
    {
        return preg_match('~^(\d{3,20}):[\w-]{20,}$~', trim($this->clientSecret()), $m) ? $m[1] : '';
    }

    public function botName(): string
    {
        return $this->clientId();
    }

    /**
     * Проверка подписи виджета (документация Telegram):
     *   secret_key = SHA256(bot_token)
     *   hash = HMAC_SHA256(data_check_string, secret_key)
     * data_check_string — поля кроме hash, отсортированные, «k=v» через \n.
     */
    public function handleCallback(array $query): array
    {
        $hash = (string)($query['hash'] ?? '');
        if ($hash === '') throw new \RuntimeException('Telegram не передал подпись');

        $pairs = [];
        foreach ($query as $k => $v) {
            if ($k === 'hash' || $k === 'state') continue;
            $pairs[] = $k . '=' . $v;
        }
        sort($pairs);
        $secretKey = hash('sha256', $this->clientSecret(), true);
        $expected = hash_hmac('sha256', implode("\n", $pairs), $secretKey);
        if (!hash_equals($expected, $hash)) {
            throw new \RuntimeException('подпись Telegram не сходится');
        }
        // Данные виджета живут сутки — дальше считаем их протухшими
        if ((int)($query['auth_date'] ?? 0) < time() - 86400) {
            throw new \RuntimeException('данные Telegram устарели, попробуйте ещё раз');
        }
        if (empty($query['id'])) throw new \RuntimeException('Telegram не передал id');

        $name = trim((string)($query['first_name'] ?? '') . ' ' . (string)($query['last_name'] ?? ''));
        return [
            'uid' => (string)$query['id'],
            'email' => null,   // Telegram почту не отдаёт — SSO попросит её отдельно
            'name' => $name !== '' ? $name : ($query['username'] ?? null),
            /* Виджет отдаёт имя и фамилию по отдельности — так и передаём.
               Склеенное 'name' остаётся запасным вариантом для тех, у кого
               заполнено только оно. */
            'first_name' => $query['first_name'] ?? null,
            'last_name' => $query['last_name'] ?? null,
            'avatar' => $query['photo_url'] ?? null,
        ];
    }

    /** Скрипт виджета для страницы входа. */
    public function widgetHtml(): string
    {
        $bot = $this->botName();
        if ($bot === '') return '';
        return '<script async src="https://telegram.org/js/telegram-widget.js?22"'
            . ' data-telegram-login="' . esc($bot) . '"'
            . ' data-size="large" data-radius="8"'
            . ' data-auth-url="' . esc(base_url('/social/telegram/callback')) . '"'
            . ' data-request-access="write"></script>';
    }
}
