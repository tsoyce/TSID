<?php

declare(strict_types=1);

namespace App\Social;

use App\Core\Setting;

/**
 * Внешние личности: Telegram, Яндекс, VK, GitHub.
 * Каждый провайдер — ~80 строк: URL авторизации, обмен кода, нормализация профиля.
 * Все выключены по умолчанию (принцип опциональности): включаются в админке
 * вместе с client_id/secret.
 */
abstract class Provider
{
    /** Машинное имя = значение ENUM в sso_linked_identities. */
    abstract public function id(): string;

    /** Человеческое название для кнопки. */
    abstract public function title(): string;

    /** Куда отправить пользователя (кроме Telegram — у него виджет). */
    abstract public function authUrl(string $state): string;

    /**
     * Обработать возврат. Возвращает нормализованный профиль:
     * ['uid' => внешний id, 'email' => ?string, 'name' => ?string, 'avatar' => ?string]
     * @throws \RuntimeException при любой неудаче
     */
    abstract public function handleCallback(array $query): array;

    public function isEnabled(): bool
    {
        return Setting::get('social.' . $this->id() . '.enabled') === '1'
            && $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function clientId(): string
    {
        return (string)Setting::get('social.' . $this->id() . '.client_id', '');
    }

    public function clientSecret(): string
    {
        return (string)Setting::get('social.' . $this->id() . '.secret', '');
    }

    public function redirectUri(): string
    {
        return base_url('/social/' . $this->id() . '/callback');
    }

    /** Знак сервиса из спрайта (v4.86): кнопка узнаётся по нему, как у Google и X. */
    public function icon(): string
    {
        return 'brand-' . $this->id();
    }

    /**
     * Включённый сервис по машинному имени — или null (v4.86).
     *
     * Для подсказки idp_hint: портал или окно входа говорят «сразу через
     * Яндекс», и чужое имя или выключенный сервис просто не срабатывают.
     */
    public static function enabledById(string $id): ?self
    {
        foreach (self::enabled() as $p) {
            if ($p->id() === $id) return $p;
        }
        return null;
    }

    /* ---------- Общие помощники ---------- */

    protected function httpPost(string $url, array $fields, array $headers = []): array
    {
        return $this->http('POST', $url, http_build_query($fields), $headers);
    }

    protected function httpGet(string $url, array $headers = []): array
    {
        return $this->http('GET', $url, null, $headers);
    }

    private function http(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new \RuntimeException('провайдер недоступен: ' . $err);
        $json = json_decode((string)$resp, true);
        if (!is_array($json)) throw new \RuntimeException('провайдер ответил не-JSON');
        return $json;
    }

    /* ---------- Реестр ---------- */

    /** @return array<string, Provider> */
    public static function all(): array
    {
        static $list = null;
        if ($list === null) {
            $list = [];
            foreach ([TelegramProvider::class, YandexProvider::class, VkProvider::class, GithubProvider::class] as $cls) {
                /* Нет класса — пропускаем, а не падаем. Провайдер здесь
                   необязательный: его кнопка может быть выключена, и вся его
                   работа сводится к одной кнопке на экране входа.
                   Отсутствие файла означает ровно одно — он не доехал при
                   заливке, — и цена этому должна быть «нет одной кнопки», а не
                   «главная и вход отдают 500». Так и случилось на боевой:
                   `Class "App\Social\VkProvider" not found` уронил ВЕСЬ экран
                   входа, потому что список собирался безусловно. */
                if (!class_exists($cls)) {
                    \App\Core\Log::warning('social.provider_missing', ['class' => $cls]);
                    continue;
                }
                $p = new $cls();
                $list[$p->id()] = $p;
            }
        }
        return $list;
    }

    public static function get(string $id): ?self
    {
        return self::all()[$id] ?? null;
    }

    /** Только включённые — для кнопок на экране входа. */
    public static function enabled(): array
    {
        return array_filter(self::all(), fn(self $p) => $p->isEnabled());
    }
}
