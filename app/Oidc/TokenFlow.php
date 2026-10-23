<?php

declare(strict_types=1);

namespace App\Oidc;

use App\Core\Audit;
use App\Core\Pkce;
use App\Core\RateLimiter;
use App\Models\Access;
use App\Models\AuthCode;
use App\Models\Client;
use App\Models\RefreshToken;
use App\Models\SessionModel;
use App\Models\User;
use App\Core\DB;

/** POST /token: обмен кода и ротация refresh (§3.3). */
final class TokenFlow
{
    public static function handle(): never
    {
        $client = self::authenticateClient();
        RateLimiter::guard('token:client:' . $client['client_id'], 60, 60);

        $grant = (string)($_POST['grant_type'] ?? '');
        if ($grant === 'authorization_code') self::exchangeCode($client);
        if ($grant === 'refresh_token') self::refresh($client);
        self::error('unsupported_grant_type', 'Use authorization_code or refresh_token');
    }

    /** client_secret_basic или client_secret_post; PKCE это не отменяет (§0). */
    public static function authenticateClient(): array
    {
        $clientId = '';
        $secret = '';
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($auth, 'Basic ')) {
            $decoded = base64_decode(substr($auth, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$clientId, $secret] = explode(':', $decoded, 2);
                $clientId = rawurldecode($clientId);
                $secret = rawurldecode($secret);
            }
        }
        if ($clientId === '') {
            $clientId = (string)($_POST['client_id'] ?? '');
            $secret = (string)($_POST['client_secret'] ?? '');
        }
        $client = $clientId !== '' ? Client::findByClientId($clientId) : null;
        if (!$client || !(int)$client['is_active'] || !Client::verifySecret($client, $secret)) {
            header('WWW-Authenticate: Basic realm="token"');
            self::error('invalid_client', 'Client authentication failed', 401);
        }
        return $client;
    }

    private static function exchangeCode(array $client): never
    {
        $code = (string)($_POST['code'] ?? '');
        $verifier = (string)($_POST['code_verifier'] ?? '');
        $redirectUri = (string)($_POST['redirect_uri'] ?? '');
        if ($code === '' || $verifier === '' || $redirectUri === '') {
            self::error('invalid_request', 'code, code_verifier and redirect_uri are required');
        }
        $row = AuthCode::findByCode($code);
        if (!$row || (int)$row['client_id'] !== (int)$client['id']) {
            self::error('invalid_grant', 'Unknown code');
        }
        if ($row['consumed_at'] !== null) self::replay($row, $client);
        if (strtotime($row['expires_at'] . ' UTC') <= time()) self::error('invalid_grant', 'Code expired');
        if (!hash_equals($row['redirect_uri'], $redirectUri)) self::error('invalid_grant', 'redirect_uri mismatch');
        if (!Pkce::verify($verifier, $row['code_challenge'])) self::error('invalid_grant', 'PKCE verification failed');

        $user = User::find((int)$row['user_id']);
        if (!\App\Models\User::canSignIn($user)) {
            self::error('invalid_grant', 'User is not active');
        }
        /* Гасим до выдачи и только если погасили именно мы (v4.70): второй
           одновременный обмен тем же кодом приходит сюда вторым и уходит как
           повтор, а не с собственным набором токенов. */
        if (!AuthCode::consume((int)$row['id'])) self::replay($row, $client);

        $sid = self::sidFor((int)$row['session_id'], (int)$client['id']);
        $authTime = strtotime($row['auth_time'] . ' UTC');
        $idToken = IdToken::issue($user, $client, $row['scope'], $row['nonce'], $sid ?? '', $authTime);
        $access = IdToken::issueAccess($user, $client, $row['scope'], $sid);

        $response = [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => IdToken::accessTtl(),
            'id_token' => $idToken,
            'scope' => $row['scope'],
        ];
        if (str_contains(' ' . $row['scope'] . ' ', ' offline_access ')) {
            $response['refresh_token'] = RefreshToken::issueFamily(
                (int)$user['id'], (int)$client['id'], (int)$row['session_id'], $row['scope']);
        }
        self::json($response);
    }

    private static function refresh(array $client): never
    {
        $token = (string)($_POST['refresh_token'] ?? '');
        if ($token === '') self::error('invalid_request', 'refresh_token is required');
        [$row, $newToken] = RefreshToken::rotate($token, (int)$client['id']);
        if ($row === null) self::error('invalid_grant', 'Refresh token is invalid, expired or reused');
        /* v5.4: сессия, из которой токен выдан, завершена — выходом, «Завершить
           сеанс» или блокировкой. Тогда и токен мёртв: иначе портал ещё
           тридцать дней обновлял доступ после «Выйти везде». Истёкшая по
           времени сессия токен не гасит — это и есть offline_access. */
        $sessRow = $row['session_id'] !== null
            ? DB::row('SELECT created_at, revoked_at FROM {sessions} WHERE id = ?', [(int)$row['session_id']]) : null;
        if ($sessRow !== null && $sessRow['revoked_at'] !== null) {
            RefreshToken::revokeFamily($row['family_id']);
            self::error('invalid_grant', 'The session this token belongs to has ended');
        }
        $user = User::find((int)$row['user_id']);
        if (!\App\Models\User::canSignIn($user)) {
            RefreshToken::revokeFamily($row['family_id']);
            self::error('invalid_grant', 'User is not active');
        }
        $accessRow = Access::get((int)$user['id'], (int)$client['id']);
        if (!$accessRow || $accessRow['status'] !== 'active') {
            RefreshToken::revokeFamily($row['family_id']);
            self::error('invalid_grant', 'Access to this client is revoked');
        }
        Audit::log('token.refresh', ['actor' => (int)$user['id'], 'on' => (int)$user['id'], 'client' => (int)$client['id']]);

        $sid = $row['session_id'] !== null ? self::sidFor((int)$row['session_id'], (int)$client['id']) : null;
        self::json([
            'access_token' => IdToken::issueAccess($user, $client, $row['scope'], $sid),
            'token_type' => 'Bearer',
            'expires_in' => IdToken::accessTtl(),
            /* auth_time — время входа, а не обновления (OIDC Core §12.2, v5.4):
               иначе портал, проверяющий свежесть входа, считал его только что
               сделанным. */
            'id_token' => IdToken::issue($user, $client, $row['scope'], null, $sid ?? '',
                $sessRow !== null ? (int)strtotime($sessRow['created_at'] . ' UTC') : time()),
            'scope' => $row['scope'],
            'refresh_token' => $newToken,
        ]);
    }

    /**
     * Код предъявлен повторно — сигнатура перехвата (§2.2).
     *
     * Кроме отказа, отзываем refresh-токены, выданные по этой связке человек +
     * портал + сессия (RFC 6749 §4.1.2, v4.70). Раньше только писали в аудит,
     * и тот, кто успел обменять перехваченный код первым, оставался с живым
     * refresh-токеном на тридцать дней.
     */
    private static function replay(array $row, array $client): never
    {
        $revoked = DB::run('UPDATE {refresh_tokens} SET revoked_at = ?
             WHERE user_id = ? AND client_id = ? AND session_id = ? AND revoked_at IS NULL',
            [now(), (int)$row['user_id'], (int)$client['id'], (int)$row['session_id']])->rowCount();
        Audit::log('code.replay', ['actor' => null, 'on' => (int)$row['user_id'], 'client' => (int)$client['id'],
            'meta' => ['code_prefix' => substr($row['code_hash'], 0, 8), 'refresh_revoked' => $revoked]]);
        self::error('invalid_grant', 'Code already used');
    }

    /** sid уже существующей связки сессия+клиент (без создания новой). */
    private static function sidFor(int $sessionId, int $clientDbId): ?string
    {
        $row = DB::row('SELECT sid FROM {session_clients} WHERE session_id = ? AND client_id = ? AND logged_out_at IS NULL',
            [$sessionId, $clientDbId]);
        return $row['sid'] ?? null;
    }

    public static function json(array $data): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $code, string $desc, int $http = 400): never
    {
        http_response_code($http);
        self::json(['error' => $code, 'error_description' => $desc]);
    }
}
