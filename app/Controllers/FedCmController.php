<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Setting;
use App\Models\Access;
use App\Models\Client;
use App\Oidc\IdToken;

/**
 * FedCM — Federated Credential Management (§6, v3.2).
 *
 * Зачем: обычный вход уводит пользователя на страницу SSO и возвращает обратно
 * редиректом. FedCM позволяет браузеру показать нативный диалог выбора аккаунта
 * прямо на сайте портала — без ухода со страницы. Портал вызывает
 * `navigator.credentials.get({identity: ...})`, браузер сам ходит по эндпоинтам
 * ниже и возвращает готовый id_token.
 *
 * Важно: это дополнение к OIDC, а не замена. Браузеры без FedCM просто идут
 * привычным путём через /authorize, и портал ничего не теряет.
 *
 * Протокол требует четырёх вещей (спецификация W3C FedCM):
 *   /.well-known/web-identity   — какие конфиги разрешены на этом origin;
 *   /fedcm/config.json          — где лежат остальные эндпоинты;
 *   /fedcm/accounts             — кто сейчас залогинен (только с cookie);
 *   /fedcm/client-metadata      — ссылки на политики портала;
 *   /fedcm/assertion            — выдача id_token после согласия.
 *
 * Браузер сам ставит `Sec-Fetch-Dest: webidentity` на эти запросы — проверяем
 * заголовок и не отвечаем на обычный fetch со стороннего скрипта.
 */
final class FedCmController
{
    /** Файл на корне origin: перечисляет допустимые пути конфигов. */
    public function wellKnown(): never
    {
        header('Content-Type: application/json');
        json_response(['provider_urls' => [base_url('/fedcm/config.json')]]);
    }

    /** Точка входа: браузер читает её первой и узнаёт остальные адреса. */
    public function config(): never
    {
        $this->requireWebIdentity();
        header('Content-Type: application/json');
        json_response([
            'accounts_endpoint' => '/fedcm/accounts',
            'client_metadata_endpoint' => '/fedcm/client-metadata',
            'id_assertion_endpoint' => '/fedcm/assertion',
            'login_url' => '/login',
            'branding' => [
                'background_color' => \App\Core\Palette::normalize(branding_accent() ?: '#1D4ED8'),
                'color' => \App\Core\Palette::derive(branding_accent() ?: '#1D4ED8')['on_ink'],
                'name' => Setting::appName(),
                'icons' => [[
                    'url' => base_url('/branding/fedcm-icon.png'),
                    'size' => 64,
                ]],
            ],
        ]);
    }

    /**
     * Кто залогинен. Отдаётся только по cookie самого SSO и только браузеру:
     * это персональные данные, и утечь они не должны даже частично.
     */
    public function accounts(): never
    {
        $this->requireWebIdentity();
        if (!Auth::check()) {
            /* 401 — сигнал браузеру показать кнопку «Войти», а не ошибку */
            json_response(['accounts' => []], 401);
        }
        $u = Auth::user();
        header('Content-Type: application/json');
        json_response(['accounts' => [[
            'id' => $u['public_id'],
            'name' => user_name($u),
            'email' => $u['email'],
            'given_name' => $u['first_name'] ?: null,
            'picture' => avatar_url($u, 128),
            /* Порталы, которым пользователь уже дал согласие: для них браузер
               покажет «Продолжить как…», а не экран согласия заново. */
            'approved_clients' => $this->approvedClients((int)$u['id']),
        ]]]);
    }

    /** Ссылки на условия портала — их браузер показывает в своём диалоге. */
    public function clientMetadata(): never
    {
        $this->requireWebIdentity();
        $client = Client::findByClientId(trim((string)($_GET['client_id'] ?? '')));
        header('Content-Type: application/json');
        /* Отдельных полей под политику и условия у портала нет — спецификация
           разрешает их не присылать, браузер просто не покажет ссылки. */
        json_response([
            'privacy_policy_url' => null,
            'terms_of_service_url' => null,
            'client_name' => $client['name'] ?? null,
        ]);
    }

    /**
     * Выдача id_token. Сюда браузер приходит только после того, как
     * пользователь ткнул в свой аккаунт в нативном диалоге.
     */
    public function assertion(): never
    {
        $this->requireWebIdentity();
        /* Origin портала подставляет браузер, подделать его со страницы нельзя */
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '') $this->fail('origin отсутствует');

        if (!Auth::check()) $this->fail('нет сессии', 401);
        $user = Auth::user();

        $clientId = trim((string)($_POST['client_id'] ?? ''));
        $accountId = trim((string)($_POST['account_id'] ?? ''));
        $nonce = trim((string)($_POST['nonce'] ?? ''));

        if ($accountId !== $user['public_id']) $this->fail('аккаунт не совпадает с сессией');

        $client = Client::findByClientId($clientId);
        if (!$client || (int)$client['is_active'] !== 1) $this->fail('портал неизвестен');

        /* Origin обязан совпасть с зарегистрированным адресом портала — иначе
           любой сайт, знающий client_id, получал бы токены на чужого клиента. */
        if (!$this->originAllowed($origin, $client)) {
            Audit::log('fedcm.origin_rejected', [
                'user_id' => (int)$user['id'], 'client_id' => (int)$client['id'],
                'meta' => ['origin' => $origin],
            ]);
            $this->fail('origin не принадлежит порталу');
        }

        /* Доступ решается тем же кодом, что и при обычном входе: политика
           портала, организации и роли — одни на оба пути. */
        $status = Access::resolveForLogin((int)$user['id'], $client);
        if ($status !== 'active') {
            /* Экран заявки на доступ живёт внутри /authorize, отдельной ссылки
               на него нет — отдаём отказ, и портал уходит на обычный редирект. */
            $this->fail('нет доступа к порталу', 403);
        }

        /* sid общий с обычным входом: back-channel logout должен разлогинивать
           портал одинаково, каким бы путём тот ни получил токен. */
        $sess = Auth::sessionRecord() ?? [];
        $sid = isset($sess['id'])
            ? \App\Models\SessionModel::sidFor((int)$sess['id'], (int)$client['id']) : '';
        $token = IdToken::issue(
            $user, $client,
            'openid profile email roles',
            $nonce !== '' ? $nonce : null,
            $sid,
            (int)strtotime((string)($sess['created_at'] ?? 'now')));

        Audit::log('fedcm.assertion', [
            'user_id' => (int)$user['id'], 'client_id' => (int)$client['id'],
            'meta' => ['origin' => $origin],
        ]);

        header('Content-Type: application/json');
        json_response(['token' => $token]);
    }

    /* ---------- служебное ---------- */

    /**
     * Заголовок ставит сам браузер и подделать его из JS нельзя — это и есть
     * защита от того, чтобы чужой скрипт дёргал /fedcm/accounts как обычный API.
     */
    private function requireWebIdentity(): void
    {
        $dest = (string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '');
        if ($dest !== 'webidentity') {
            /* Код передаём аргументом: json_response сам зовёт http_response_code
               и без него затирает уже выставленный статус на 200. */
            json_response(['error' => 'endpoint предназначен только для FedCM-запросов браузера'], 400);
        }
    }

    /** @return list<string> client_id порталов, которым доступ уже выдан */
    private function approvedClients(int $userId): array
    {
        $rows = DB::all(
            'SELECT c.client_id FROM {user_client_access} a
               JOIN {clients} c ON c.id = a.client_id
              WHERE a.user_id = ? AND a.status = ? AND c.is_active = 1',
            [$userId, 'active']);
        return array_map(static fn($r) => (string)$r['client_id'], $rows);
    }

    /** Origin разрешён, если совпадает с base_url или с любым redirect_uri. */
    private function originAllowed(string $origin, array $client): bool
    {
        $norm = static function (string $url): string {
            $p = parse_url($url);
            if (!isset($p['scheme'], $p['host'])) return '';
            $port = isset($p['port']) ? ':' . $p['port'] : '';
            return strtolower($p['scheme'] . '://' . $p['host'] . $port);
        };
        /* Client::findByClientId уже раскодировал JSON-колонки в массивы —
           повторный json_decode давал «Array to string conversion». */
        $allowed = [$norm((string)$client['base_url'])];
        foreach ((array)($client['redirect_uris'] ?? []) as $uri) {
            if (is_string($uri)) $allowed[] = $norm($uri);
        }
        return in_array($norm($origin), array_filter($allowed), true);
    }

    /** @param array<string, mixed> $extra */
    private function fail(string $why, int $code = 400, array $extra = []): never
    {
        json_response(['error' => ['code' => 'access_denied', 'message' => $why]] + $extra, $code);
    }
}
