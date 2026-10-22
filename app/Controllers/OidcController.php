<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Flash;
use App\Core\Jwt;
use App\Core\Keys;
use App\Core\View;
use App\Models\Access;
use App\Models\Client;
use App\Models\RefreshToken;
use App\Models\User;
use App\Oidc\AuthorizeFlow;
use App\Oidc\Discovery;
use App\Oidc\IdToken;
use App\Oidc\TokenFlow;

/** OIDC endpoints (§3.1). Тяжёлая логика — в App\Oidc\*. */
final class OidcController
{
    public function discovery(): never
    {
        header('Cache-Control: public, max-age=3600');
        json_response(Discovery::document());
    }

    public function jwks(): never
    {
        header('Cache-Control: public, max-age=86400'); // кэшировать 24 ч (§3.1)
        json_response(Keys::jwks());
    }

    public function authorize(): never
    {
        AuthorizeFlow::handle($_GET);
    }

    /**
     * POST /portal/bootstrap — обмен билета установки на реквизиты (v4.22).
     *
     * Сюда приходит не браузер человека, а сервер портала: у него есть
     * одноразовый билет из ссылки установки, и этот билет — вся авторизация.
     * Ответ отдаётся ровно один раз, дальше билет мёртв.
     */
    public function portalBootstrap(): never
    {
        header('Cache-Control: no-store');
        $ticket = trim((string)($_POST['ticket'] ?? ''));
        if ($ticket === '') {
            json_response(['ok' => false, 'error' => 'Билет не передан.'], 400);
        }
        $res = \App\Core\PortalInstall::redeem($ticket);
        if (!$res['ok']) {
            json_response(['ok' => false, 'error' => $res['error']], 403);
        }
        json_response(['ok' => true] + $res['data']);
    }

    /**
     * GET /portal/ui?client_id= — как портал открывает вход (v4.72).
     *
     * Читает sso.js на странице портала, до нажатия «Войти»: окном поверх
     * сайта или всей страницей — решает владелец портала в «Экране входа».
     * Всё здесь публично — имя сервиса и так стоит на экране входа, — поэтому
     * ответ открыт любому сайту (`*`) и без cookie: чужой странице отсюда
     * нечего взять, а список разрешённых адресов портала пришлось бы держать
     * в двух местах. Неизвестный или выключенный портал — «как решил портал».
     */
    public function portalUi(): never
    {
        $headers = ['Access-Control-Allow-Origin' => '*'];
        $client = Client::findByClientId(trim((string)($_GET['client_id'] ?? '')));
        if ($client === null || empty($client['is_active'])) {
            json_response(['display' => null], 200, $headers);
        }
        $display = (string)($client['login_display'] ?? '');
        json_response([
            'display' => in_array($display, \App\Core\LoginLook::DISPLAYS, true) ? $display : null,
            'app' => \App\Core\Setting::appName(),
            'accent' => branding_theme_color(),
            'texts' => [
                'title' => t('Вход через :app', ['app' => \App\Core\Setting::appName()]),
                'text' => t('Окно входа открыто. Не видите его — оно могло уйти за это окно.'),
                'show' => t('Показать окно'),
                'cancel' => t('Отмена'),
                /* Окно на странице портала (v4.73). */
                'close' => t('Закрыть'),
                'slow' => t('Не открывается?'),
                'window' => t('Открыть отдельным окном'),
            ],
        ], 200, $headers);
    }

    public function token(): never
    {
        TokenFlow::handle();
    }

    /** GET/POST /userinfo: claims по Bearer access_token (§3.1). */
    public function userinfo(): never
    {
        $claims = $this->bearerClaims();
        $user = User::findByPublicId((string)$claims['sub']);
        $client = Client::findByClientId((string)$claims['cid']);
        if (!$user || !$client || !\App\Models\User::canSignIn($user)) {
            $this->unauthorized('Token subject is no longer active');
        }
        json_response(IdToken::claims($user, $client, (string)$claims['scope']) + ['sub' => $user['public_id']]);
    }

    /** POST /introspect: client-auth + token → {active, …} (RFC 7662). */
    public function introspect(): never
    {
        $client = TokenFlow::authenticateClient();
        $token = (string)($_POST['token'] ?? '');
        if ($token === '') TokenFlow::error('invalid_request', 'token is required');

        // JWT (access) или opaque (refresh)
        if (substr_count($token, '.') === 2) {
            $claims = Jwt::verify($token);
            if ($claims === null || ($claims['aud'] ?? '') !== 'userinfo') json_response(['active' => false]);
            $user = User::findByPublicId((string)($claims['sub'] ?? ''));
            if (!\App\Models\User::canSignIn($user)) json_response(['active' => false]);
            // Бан почти в реальном времени: сессия отозвана → токен мёртв (§0)
            if (!empty($claims['sid'])) {
                $alive = \App\Core\DB::row(
                    'SELECT s.id FROM {session_clients} sc JOIN {sessions} s ON s.id = sc.session_id
                     WHERE sc.sid = ? AND sc.logged_out_at IS NULL AND s.revoked_at IS NULL AND s.expires_at > ?',
                    [(string)$claims['sid'], now()]);
                if (!$alive) json_response(['active' => false]);
            }
            $tokenClient = Client::findByClientId((string)($claims['cid'] ?? ''));
            $access = $tokenClient ? Access::get((int)$user['id'], (int)$tokenClient['id']) : null;
            if (!$access || $access['status'] !== 'active') json_response(['active' => false]);
            json_response([
                'active' => true,
                'sub' => $claims['sub'],
                'client_id' => $claims['cid'],
                'scope' => $claims['scope'],
                'token_type' => 'Bearer',
                'exp' => $claims['exp'],
                'iat' => $claims['iat'],
                'sid' => $claims['sid'] ?? null,
            ]);
        }

        $row = RefreshToken::findValid($token);
        if (!$row || (int)$row['client_id'] !== (int)$client['id']) json_response(['active' => false]);
        $user = User::find((int)$row['user_id']);
        if (!\App\Models\User::canSignIn($user)) json_response(['active' => false]);
        json_response([
            'active' => true,
            'sub' => $user['public_id'],
            'client_id' => $client['client_id'],
            'scope' => $row['scope'],
            'token_type' => 'refresh_token',
            'exp' => strtotime($row['expires_at'] . ' UTC'),
        ]);
    }

    /** POST /revoke: отзыв refresh-token и всей его семьи (§3.1). */
    public function revoke(): never
    {
        $client = TokenFlow::authenticateClient();
        $token = (string)($_POST['token'] ?? '');
        if ($token !== '' && substr_count($token, '.') !== 2) {
            $row = \App\Core\DB::row('SELECT * FROM {refresh_tokens} WHERE token_hash = ?',
                [\App\Core\Crypto::hashToken($token)]);
            if ($row && (int)$row['client_id'] === (int)$client['id']) {
                RefreshToken::revokeFamily($row['family_id']);
                Audit::log('token.revoke', ['actor' => null, 'on' => (int)$row['user_id'], 'client' => (int)$client['id']]);
            }
        }
        // RFC 7009: 200 даже для неизвестного токена — не палим существование
        json_response(['revoked' => true]);
    }

    /* ---- Первый вход в сервис (§5.1): и доверенный, и нет ---- */

    /**
     * Запрос, ради которого человек здесь.
     *
     * Сессия первая, ключ возврата — подстраховка. Без него потерянный или уже
     * съеденный запрос означал «в личный кабинет», то есть человек, нажавший
     * «продолжить» второй раз, оказывался не в своём сервисе, а у нас.
     */
    private static function consentRequest(): ?array
    {
        $req = $_SESSION['consent_request'] ?? null;
        if (is_array($req) && !empty($req['client_id'])) return $req;
        $rt = \App\Core\Resume::fromRequest();
        return $rt === '' ? null : \App\Core\Resume::peek($rt);
    }

    public function showConsent(): never
    {
        $req = self::consentRequest();
        $client = $req ? Client::findByClientId((string)$req['client_id']) : null;
        if (!$client) redirect('/profile');
        $scopes = explode(' ', (string)($req['scope'] ?? 'openid'));
        View::render('oauth/consent', [
            'pageTitle' => 'Запрос доступа',
            'client' => $client,
            'scopes' => $scopes,
            'popup' => \App\Core\LoginFlow::compact($req),
        ], 'layouts/auth');
    }

    public function doConsent(): never
    {
        $req = self::consentRequest();
        $client = $req ? Client::findByClientId((string)$req['client_id']) : null;
        if (!$client) redirect('/profile');
        $user = Auth::user();

        /* Галочка обязательна, когда установка требует принять документы:
           «продолжить» без неё — не согласие с ними, а промах по кнопке.
           Браузер её и так требует, но форму присылают и мимо него. Без
           документов галочки нет вовсе (v4.70): согласие — сама кнопка.
           Запрос при этом НЕ теряем: первая версия гасила его до проверки, и
           человек, забывший галочку, вылетал в кабинет — без объяснения и без
           возможности вернуться в поток иначе, чем начав вход заново. */
        if (($_POST['decision'] ?? '') === 'allow' && empty($_POST['agree'])
            && \App\Core\Legal::requiringConsent() !== []) {
            Flash::error(t('Отметьте галочку — без неё это не согласие.'));
            /* Ключ возврата тащим за собой: без него человек, забывший
               галочку, терял портал на ровном месте. */
            $rt = \App\Core\Resume::fromRequest();
            redirect('/consent' . ($rt !== '' ? '?rt=' . urlencode($rt) : ''));
        }

        unset($_SESSION['consent_request']);
        if (($_POST['decision'] ?? '') === 'allow') {
            Access::recordConsent((int)$user['id'], (int)$client['id']);
            Audit::log('consent.granted', ['client' => (int)$client['id']]);
            redirect('/authorize?' . http_build_query($req));
        }
        Audit::log('consent.denied', ['client' => (int)$client['id']]);
        AuthorizeFlow::errorRedirect((string)$req['redirect_uri'], 'access_denied',
            'User denied the consent', (string)($req['state'] ?? ''), $req);
    }

    /* ---- Bearer-аутентификация для /userinfo ---- */

    private function bearerClaims(): array
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($auth, 'Bearer ')) $this->unauthorized('Bearer token required');
        $claims = Jwt::verify(substr($auth, 7));
        if ($claims === null || ($claims['aud'] ?? '') !== 'userinfo') $this->unauthorized('Invalid or expired token');
        return $claims;
    }

    private function unauthorized(string $desc): never
    {
        header('WWW-Authenticate: Bearer error="invalid_token", error_description="' . $desc . '"');
        json_response(['error' => 'invalid_token', 'error_description' => $desc], 401);
    }
}
