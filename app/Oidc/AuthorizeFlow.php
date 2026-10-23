<?php

declare(strict_types=1);

namespace App\Oidc;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\ErrorPage;
use App\Core\Framed;
use App\Core\LoginFlow;
use App\Core\Pkce;
use App\Core\RateLimiter;
use App\Core\View;
use App\Models\Access;
use App\Models\AuthCode;
use App\Models\Client;
use App\Models\SessionModel;

/** GET /authorize — канонический поток входа (§2.2) + политика доступа (§9). */
final class AuthorizeFlow
{
    public static function handle(array $q): never
    {
        RateLimiter::guard('authorize:ip:' . RateLimiter::ipKey(), 30, 60);

        /* 1. Клиент и redirect_uri: при провале — страница на месте, БЕЗ редиректа (§3.5) */
        $client = Client::findByClientId((string)($q['client_id'] ?? ''));
        if (!$client || !(int)$client['is_active']) {
            ErrorPage::oauth('Неизвестный сервис', 'Параметр client_id не зарегистрирован или сервис отключён.');
        }
        $redirectUri = (string)($q['redirect_uri'] ?? '');
        if ($redirectUri === '' || !Client::redirectUriAllowed($client, $redirectUri)) {
            /* Не пускаем — сравнение байт-в-байт остаётся законом. Но адрес со
               своего origin портала запоминаем и показываем администратору с
               кнопкой «добавить»: подключение раньше угадывало «…/callback.php»,
               сервис слал «…/callback», и понять, какой адрес пришёл, было
               нечем. См. RedirectMiss. */
            $noted = $redirectUri !== '' && \App\Core\RedirectMiss::record($client, $redirectUri);
            ErrorPage::oauth('Адрес возврата не совпадает',
                t('redirect_uri не входит в список, зарегистрированный для «:portal». Сравнение — байт-в-байт.', ['portal' => $client['name']]),
                $noted ? ['uri' => $redirectUri, 'noted' => true] : []);
        }

        /* Вход во фрейме на странице портала (v4.73): встраивать этот ответ и
           всё, что за ним, может только этот портал. Ниже клиент и адрес
           возврата уже проверены по списку. */
        if (($q['display'] ?? '') === Framed::DISPLAY) Framed::allowFraming($q);

        /* 2. Остальные ошибки — стандартным редиректом с error и state */
        $state = (string)($q['state'] ?? '');
        $err = fn(string $code, string $desc) => self::errorRedirect($redirectUri, $code, $desc, $state, $q);

        if (($q['response_type'] ?? '') !== 'code') $err('invalid_request', 'response_type must be "code"');
        if ($state === '') $err('invalid_request', 'state is required');
        $scope = trim((string)($q['scope'] ?? ''));
        $scopes = $scope === '' ? [] : explode(' ', $scope);
        if (!in_array('openid', $scopes, true)) $err('invalid_request', 'scope must include "openid"');
        $scopes = array_values(array_intersect($scopes, array_merge($client['allowed_scopes'], ['openid'])));
        $scope = implode(' ', $scopes);
        $nonce = (string)($q['nonce'] ?? '');
        if ($nonce === '') $err('invalid_request', 'nonce is required');
        if (!Pkce::validChallenge($q['code_challenge'] ?? null, $q['code_challenge_method'] ?? null)) {
            $err('invalid_request', 'PKCE S256 code_challenge is required');
        }

        $prompt = (string)($q['prompt'] ?? '');
        $sess = Auth::sessionRecord();
        $user = Auth::user();

        /* 3. Тихая проверка prompt=none — только для семейства *.example (§1.1/§2.5) */
        if ($prompt === 'none') {
            if (!(int)$client['same_site_family']) $err('invalid_request', 'prompt=none is not allowed for this client');
            if (!$user || !$sess) $err('login_required', 'No active SSO session');
        }

        /* v5.4: запертая сессия кода не выдаёт. Замок запирал кабинет и
           админку, а /authorize флага `auth` не носит — и взявший оставленный
           телефон молча входил в любой портал. После отпирания — сюда же. */
        if ($user && Auth::locked()) {
            if ($prompt === 'none') $err('login_required', 'Session is locked');
            $_SESSION['return_to'] = '/authorize?' . http_build_query($q);
            redirect('/lock');
        }

        /* 4. Нужен вход или переспрос пароля.

           `max_age=0` означает «пусть подтвердит пароль ПРЯМО сейчас» — так
           сервис страхует опасное действие. Сравнение было строгим (`>`), и
           ноль не срабатывал никогда: свежая сессия живёт ноль секунд, а
           `0 > 0` — ложь. Сервис просил переспросить, мы не спрашивали и
           отвечали токеном как ни в чём не бывало (v4.47).

           Чинить одним переводом в `>=` нельзя: после переспроса сессия снова
           нулевого возраста, условие снова истинно — и человек ходит по кругу
           «вход → authorize → вход». Поэтому исполненное требование снимаем с
           запроса, ровно как это давно сделано для `prompt=login`. */
        $maxAge = isset($q['max_age']) && ctype_digit(trim((string)$q['max_age']))
            ? (int)$q['max_age'] : null;
        $authAge = $sess !== null ? time() - strtotime($sess['created_at'] . ' UTC') : null;
        $tooOld = $maxAge !== null && $authAge !== null
            && ($maxAge === 0 || $authAge > $maxAge);
        if (!$user || $prompt === 'login' || $tooOld) {
            if ($prompt === 'none') $err('login_required', 'Re-authentication required');
            $stored = $q;
            unset($stored['prompt']); // после входа не зацикливаем форс
            unset($stored['max_age']); // и переспрос возраста — тоже одноразовый
            unset($stored['idp_hint']); // и подсказка сервиса: после входа она не нужна
            $_SESSION['oauth_request'] = $stored;
            /* Сразу через сервис (v4.86): idp_hint=yandex ведёт мимо экрана
               выбора — как kc_idp_hint у Keycloak. Так окно, открытое из фрейма
               щелчком по «Продолжить с Яндекс ID», не просит выбрать сервис ещё
               раз, а портал может поставить свою кнопку «Войти через Яндекс».
               Только включённый сервис, только если портал его показывает и не
               во фрейме (страницы сервисов фреймы запрещают); иначе подсказка
               молча не действует — обычный экран входа. */
            $hinted = \App\Social\Provider::enabledById((string)($q['idp_hint'] ?? ''));
            if ($hinted !== null && ($q['display'] ?? '') !== Framed::DISPLAY
                && \App\Core\Bridge::methodShown($client, 'social')) {
                redirect('/social/' . $hinted->id() . '/start');
            }
            /* Ключ возврата в адресе — подстраховка на случай, если сессия не
               доживёт до отправки формы. Без него потерянная сессия означала
               «человек оказался в личном кабинете SSO вместо своего сервиса». */
            $rt = \App\Core\Resume::issue($stored);
            redirect('/login' . ($rt !== '' ? '?rt=' . urlencode($rt) : ''));
        }

        /* 5. Доступ ≠ роль (§9.1) */
        $accessStatus = Access::resolveForLogin((int)$user['id'], $client);
        if ($accessStatus !== 'active') {
            if ($prompt === 'none') $err('access_denied', 'Access to this client is not granted');
            self::renderGate($accessStatus, $client, $q);
        }

        /* 6. Step-up: портал требует 2FA (§6.1) */
        if ((int)$client['require_2fa'] && $user['totp_enabled_at'] === null) {
            if ($prompt === 'none') $err('access_denied', '2FA required');
            $_SESSION['oauth_request'] = $q; // вернёмся после включения
            View::render('oauth/require_2fa', [
                'pageTitle' => 'Нужна двухфакторная защита',
                'client' => $client,
                'popup' => LoginFlow::compact($q),
            ], 'layouts/auth');
        }

        /* 7. Первый вход в этот сервис (§5.1).

           Было так: недоверенный портал спрашивал согласие на данные, а
           доверенный не спрашивал ничего — человек, уже вошедший в SSO,
           оказывался внутри нового сервиса молча, с заведённым там аккаунтом
           и не понимая, куда попал. «Доверенный» — это про то, что мы доверяем
           сервису данные, а не про то, что человека можно не спрашивать.

           Теперь экран один на оба случая, и метка одна — `consented_at`:
           недоверенному он показывает ещё и список данных, доверенному просто
           говорит, куда человек входит. Выключается настройкой: установке, где
           все сервисы свои и вход сквозной, лишний экран не нужен. */
        if (self::mustConsent($user, $client)) {
            if ($prompt === 'none') $err('access_denied', 'Consent required');
            $_SESSION['consent_request'] = $q;
            /* Тот же ключ возврата, что и на входе. Согласие — место, где одно
               нажатие приходит дважды: кнопка возвращается через пятнадцать
               секунд, человек жмёт ещё раз, а запрос из сессии уже съеден
               первым нажатием. Раньше второе нажатие уводило в личный кабинет. */
            $rt = \App\Core\Resume::issue($q);
            redirect('/consent' . ($rt !== '' ? '?rt=' . urlencode($rt) : ''));
        }

        self::issueAndRedirect($q, $client, $user, $sess);
    }

    /** Нужен ли экран первого входа (шаг 7). Один ответ на /authorize и FedCM (v5.4). */
    public static function mustConsent(array $user, array $client): bool
    {
        $access = Access::get((int)$user['id'], (int)$client['id']);
        $firstTime = ($access['consented_at'] ?? null) === null;
        $mustAsk = !(int)$client['is_trusted']
            || \App\Core\Setting::get('policy.welcome_screen', '1') === '1';
        return $firstTime && $mustAsk;
    }

    /** Выдача кода и возврат на портал — финал успешного /authorize. */
    public static function issueAndRedirect(array $q, array $client, array $user, array $sess): never
    {
        $scope = implode(' ', array_values(array_intersect(
            explode(' ', trim((string)($q['scope'] ?? 'openid'))),
            array_merge($client['allowed_scopes'], ['openid'])
        )));
        $authTime = gmdate('Y-m-d H:i:s', strtotime($sess['created_at'] . ' UTC'));
        SessionModel::sidFor((int)$sess['id'], (int)$client['id']);
        $code = AuthCode::issue(
            (int)$client['id'], (int)$user['id'], (int)$sess['id'],
            (string)$q['redirect_uri'], $scope,
            (string)($q['nonce'] ?? '') ?: null,
            (string)$q['code_challenge'], $authTime
        );
        Access::recordLogin((int)$user['id'], (int)$client['id']);
        Audit::log('code.issued', ['actor' => (int)$user['id'], 'on' => (int)$user['id'], 'client' => (int)$client['id']]);
        $sep = str_contains((string)$q['redirect_uri'], '?') ? '&' : '?';
        /* iss — чей это ответ (RFC 9207, v4.70): портал с несколькими
           провайдерами сверяет его и не понесёт чужой код не туда. */
        $back = $q['redirect_uri'] . $sep . http_build_query(['code' => $code, 'state' => $q['state'], 'iss' => base_url()]);
        /* «Вы вошли» перед возвратом (v4.72) — если портал так настроен и
           человек только что вошёл ради него. Метка гасится в любом случае:
           иначе экран всплыл бы при следующем заходе в другой сервис. */
        $fresh = \App\Core\LoginFlow::takeFresh((string)$client['client_id']);
        /* Во фрейме на странице портала адрес возврата отдаётся ей, а не фрейму
           (v4.73): колбэк портала отработает всей вкладкой, как при входе
           страницей. */
        $origin = ($q['display'] ?? '') === Framed::DISPLAY ? Framed::parentOrigin($q) : null;
        if ($fresh && (int)($client['login_welcome'] ?? 0) === 1 && ($q['prompt'] ?? '') !== 'none') {
            \App\Core\View::render('auth/welcome', [
                'pageTitle' => t('Вы вошли'), 'client' => $client, 'user' => $user, 'continue' => $back,
                'popup' => LoginFlow::compact($q), 'origin' => $origin,
            ], 'layouts/auth');
        }
        if ($origin !== null) self::handoff($client, $back, $origin, t('Вы вошли'));
        redirect($back);
    }

    /**
     * Отдать адрес возврата странице портала, в которую встроен вход (v4.73).
     * Сообщение уходит только на origin адреса возврата; не дошло — кнопка
     * ведёт по тому же адресу всю вкладку.
     */
    private static function handoff(array $client, string $url, string $origin, string $title): never
    {
        View::render('auth/handoff', [
            'pageTitle' => $title, 'title' => $title, 'client' => $client,
            'continue' => $url, 'origin' => $origin, 'popup' => true,
        ], 'layouts/auth');
    }

    /** Экраны «заявка на рассмотрении» / «нет доступа» (§9.1). */
    private static function renderGate(string $status, array $client, array $q = []): never
    {
        $map = [
            'pending' => [t('Заявка на рассмотрении'),
                t('Доступ к «:portal» выдаётся по подтверждению. Заявка создана — администратор портала увидит её в ближайшее время.', ['portal' => $client['name']])],
            'blocked' => [t('Доступ закрыт'),
                t('Ваш доступ к «:portal» отозван. Если это ошибка — напишите администратору портала.', ['portal' => $client['name']])],
            'denied' => [t('Вход по приглашениям'),
                t('Портал «:portal» доступен только по приглашению. Попросите администратора выдать вам доступ.', ['portal' => $client['name']])],
        ];
        [$title, $message] = $map[$status] ?? $map['denied'];
        View::render('oauth/gate', [
            'pageTitle' => $title,
            'title' => $title,
            'message' => $message,
            'client' => $client,
            'popup' => LoginFlow::compact($q),
        ], 'layouts/auth');
    }

    public static function errorRedirect(string $redirectUri, string $code, string $desc, string $state, array $q = []): never
    {
        $params = ['error' => $code, 'error_description' => $desc];
        if ($state !== '') $params['state'] = $state;
        $params['iss'] = base_url();
        $sep = str_contains($redirectUri, '?') ? '&' : '?';
        $url = $redirectUri . $sep . http_build_query($params);
        /* Во фрейме отказ тоже уходит странице портала — иначе его колбэк
           открылся бы внутри окна входа (v4.73). */
        if (($q['display'] ?? '') === Framed::DISPLAY) {
            $client = Client::findByClientId((string)($q['client_id'] ?? ''));
            $origin = Framed::parentOrigin($q);
            if ($client !== null && $origin !== null) {
                self::handoff($client, $url, $origin, t('Возвращаемся в сервис'));
            }
        }
        redirect($url);
    }
}
