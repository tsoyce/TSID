<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\ErrorPage;
use App\Core\Flash;
use App\Core\Jwt;
use App\Core\LoginFlow;
use App\Core\Mailer;
use App\Core\RateLimiter;
use App\Core\Totp;
use App\Core\View;
use App\Models\Client;
use App\Models\OneTimeToken;
use App\Models\Role;
use App\Models\SessionModel;
use App\Models\User;
use App\Oidc\EventDispatcher;

/** Вход, 2FA, magic-ссылка, выход, сервисные переходы по токенам. */
final class AuthController
{
    public function home(): never
    {
        if (Auth::check()) redirect('/profile');

        /* Гостевая показывается при двух условиях сразу: владелец не выключил
           витрину в «Оформлении сайта» И отметил хоть один портал. Пустая
           витрина хуже, чем сразу форма входа: лишний экран на пути к тому,
           зачем человек пришёл. */
        $portals = \App\Core\Appearance::showcasePortals();
        if ($portals === []) redirect('/login');

        View::render('auth/login', [
            'pageTitle' => \App\Core\Setting::appName(),
            'client' => null,
            'popup' => false,
            'identity' => '',
            'captcha' => \App\Core\Captcha::requiredForLogin(),
            'portals' => $portals,
        ], 'layouts/guest');
    }

    public function showLogin(): never
    {
        /* Вошедшего без запроса уводим в кабинет — но только если нет и ключа
           возврата. Раньше ключ не спрашивали: вернулся на /login?rt=… уже
           вошедшим (запрос из сессии съеден первым нажатием) — и оказался в
           кабинете SSO вместо портала. С ключом показываем форму: переспросить
           пароль безопасно, а молча выдать код нельзя — ключ мог прийти из
           prompt=login, и переспрос пропустился бы. */
        if (Auth::check() && empty($_SESSION['oauth_request']) && \App\Core\Resume::fromRequest() === '') {
            redirect('/profile');
        }
        /* Вход во фрейме на странице портала, а сессия, только что заведённая
           /authorize, сюда не доехала: браузер не пускает cookie во фрейм даже
           изолированной (v4.73). Дальше вход во фрейме не пойдёт — предлагаем
           отдельное окно. */
        if (\App\Core\Framed::cookiesBlocked()) {
            $framed = \App\Core\Framed::request();
            $framedClient = \App\Models\Client::findByClientId((string)($framed['client_id'] ?? ''));
            if ($framedClient !== null) {
                View::render('auth/frame_blocked', [
                    'pageTitle' => t('Вход откроется отдельным окном'), 'client' => $framedClient, 'popup' => true,
                ], 'layouts/auth');
            }
        }
        View::render('auth/login', [
            'pageTitle' => 'Вход',
            'client' => LoginFlow::pendingClient(),
            'popup' => LoginFlow::pendingIsPopup(),
            'identity' => '',
            'captcha' => \App\Core\Captcha::requiredForLogin(),
        ], 'layouts/auth');
    }

    /** GET /captcha.png — картинка текущей задачи. */
    public function captchaImage(): never
    {
        header('Content-Type: image/png');
        /* Ни секунды кэша: картинка одноразовая и меняется с каждой задачей */
        header('Cache-Control: no-store, max-age=0');
        /* ?new=1 — человек нажал «другая картинка». Заводим новую задачу, а не
           перерисовываем ту же: смысл кнопки в том, что предыдущую не удалось
           разобрать. Старая при этом гаснет — подобрать ответ перебором картинок
           нельзя. */
        $q = isset($_GET['new'])
            ? \App\Core\Captcha::issue()
            : \App\Core\Captcha::question();
        echo \App\Core\Captcha::png($q);
        exit;
    }

    public function doLogin(): never
    {
        RateLimiter::guard('login:ip:' . RateLimiter::ipKey(), 5, 60);
        $identity = trim((string)($_POST['identity'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $remember = !empty($_POST['remember']);
        $fail = function (?string $msg = null) use ($identity): never {
            Flash::error($msg ?? t('Неверный логин или пароль.'));
            View::render('auth/login', [
                'pageTitle' => 'Вход',
                'client' => LoginFlow::pendingClient(),
                'popup' => LoginFlow::pendingIsPopup(),
                'identity' => $identity,
                'captcha' => \App\Core\Captcha::requiredForLogin(),
            ], 'layouts/auth');
        };
        /* Капча проверяется до пароля: смысл в том, чтобы отсечь автомат
           до обращения к базе, а не после. */
        if (\App\Core\Captcha::requiredForLogin()
            && !\App\Core\Captcha::verify((string)($_POST['captcha'] ?? ''))) {
            \App\Core\Captcha::noteFailure();
            $fail(t('Проверка не пройдена. Попробуйте ещё раз.'));
        }
        if ($identity === '' || $password === '') $fail();

        $user = User::findByLoginOrEmail($identity);
        if ($user) {
            RateLimiter::guard('login:acct:' . $user['id'], 10, 3600);
        }
        if (!\App\Models\User::canSignIn($user) || empty($user['password_hash'])) {
            Audit::log('login.fail', ['actor' => null, 'on' => $user['id'] ?? null, 'meta' => ['reason' => 'identity']]);
            \App\Core\Captcha::noteFailure();
            $fail();
        }
        if (User::isLocked($user)) {
            Audit::log('login.locked', ['actor' => null, 'on' => (int)$user['id']]);
            $fail(t('Слишком много неудачных попыток. Подождите 15 минут или сбросьте пароль.'));
        }
        if (!password_verify($password, (string)$user['password_hash'])) {
            User::registerFailedLogin($user);
            Audit::log('login.fail', ['actor' => null, 'on' => (int)$user['id'], 'meta' => ['reason' => 'password']]);
            \App\Core\Captcha::noteFailure();
            $fail();
        }
        // Прозрачный перехэш legacy bcrypt → argon2id (§13.1)
        if ($user['password_legacy'] || password_needs_rehash((string)$user['password_hash'], PASSWORD_ARGON2ID)) {
            User::update((int)$user['id'], [
                'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
                'password_legacy' => 0,
            ]);
        }
        \App\Core\Captcha::clearFailures();
        if ($user['totp_enabled_at'] !== null) {
            LoginFlow::require2fa($user, $remember);
        }
        LoginFlow::complete($user, $remember);
    }

    /* ---- 2FA ---- */

    public function show2fa(): never
    {
        $user = LoginFlow::pending2faUser();
        if (!$user) redirect('/login');
        View::render('auth/2fa', [
            'pageTitle' => 'Подтверждение входа',
            'popup' => LoginFlow::pendingIsPopup(),
            'useRecovery' => isset($_GET['recovery']),
        ], 'layouts/auth');
    }

    public function do2fa(): never
    {
        $user = LoginFlow::pending2faUser();
        if (!$user) redirect('/login');
        $p =& $_SESSION['pending_2fa'];
        $remember = (bool)$p['remember'];

        /* Лимит на человека, а не только на PHP-сессию (v4.70). Счётчик в
           сессии обнулялся повторным вводом пароля: каждый заход давал ещё
           пять попыток кода. Десять за четверть часа хватает любому, кто
           ошибся пальцем, и не хватает перебору. Удачный вход счётчик
           сбрасывает: считаются промахи, а не входы. */
        $bucket = '2fa:user:' . (int)$user['id'];
        RateLimiter::guard($bucket, 10, 900);

        $recovery = trim((string)($_POST['recovery_code'] ?? ''));
        if ($recovery !== '') {
            if (User::useRecoveryCode((int)$user['id'], $recovery)) {
                Audit::log('2fa.recovery_used', ['actor' => (int)$user['id'], 'on' => (int)$user['id']]);
                RateLimiter::clear($bucket);
                LoginFlow::complete($user, $remember);
            }
        } else {
            $code = (string)($_POST['code'] ?? '');
            $secret = Crypto::decrypt((string)$user['totp_secret']);
            /* Код годится один раз: шаг должен быть новее последнего принятого.
               Подсмотренный код больше не входит второй раз в свои полторы
               минуты жизни (v4.70). */
            $step = Totp::matchStep($secret, $code);
            if ($step !== null && User::claimTotpStep((int)$user['id'], $step)) {
                RateLimiter::clear($bucket);
                LoginFlow::complete($user, $remember);
            }
        }

        $p['attempts'] = ($p['attempts'] ?? 0) + 1;
        Audit::log('login.2fa_fail', ['actor' => null, 'on' => (int)$user['id']]);
        if ($p['attempts'] >= 5) { // 5 попыток → откат на шаг пароля (§5.1)
            unset($_SESSION['pending_2fa']);
            Flash::error('Код не подошёл 5 раз. Войдите заново.');
            redirect('/login');
        }
        Flash::error('Код не подошёл. Попробуйте ещё раз.');
        redirect('/login/2fa' . ($recovery !== '' ? '?recovery' : ''));
    }

    /* ---- Magic link ---- */

    public function sendMagicLink(): never
    {
        RateLimiter::guard('magic:ip:' . RateLimiter::ipKey(), 3, 3600);
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        /* Лимит на адрес — условие отправки, а не счётчик для отчёта (v4.70).
           Результат проверки раньше отбрасывался: сменой IP чужой ящик
           засыпали письмами со ссылками входа. У восстановления пароля та же
           проверка стояла правильно. */
        if (filter_var($email, FILTER_VALIDATE_EMAIL)
            && RateLimiter::hit('magic:email:' . substr(hash('sha256', $email), 0, 16), 3, 3600)) {
            $user = User::findByEmail($email);
            if (\App\Models\User::canSignIn($user)) {
                $token = OneTimeToken::issue((int)$user['id'], 'login_link');
                Mailer::sendLetter($email, 'magic', [
                    'name' => user_name($user),
                    'url' => base_url('/magic/' . $token),
                ]);
            }
        }
        // Ответ одинаковый для существующего и несуществующего email (§8.2)
        Flash::success('Если такой email зарегистрирован — письмо со ссылкой уже в пути.');
        redirect('/login');
    }

    /**
     * GET /magic/{token} — только показать, не входить (v4.70).
     *
     * Ссылку из письма открывают не одни люди: почтовые сканеры и антивирусы
     * проходят по ней сами, и GET, гасивший токен, отдавал им вход, а человеку
     * оставлял «ссылка уже использована». Тот же механизм, что у подтверждения
     * почты: форма POST, которую браузер отправит сам.
     */
    public function magicLogin(string $token): never
    {
        if (OneTimeToken::peek($token, 'login_link') === null) {
            Flash::error('Ссылка устарела или уже использована. Запросите новую.');
            redirect('/login');
        }
        View::render('auth/confirm', [
            'pageTitle' => 'Вход по ссылке',
            'title' => t('Вход по ссылке'),
            'lead' => t('Входим в аккаунт — секунду.'),
            'button' => t('Войти'),
            'action' => '/magic/' . $token,
            'auto' => true,
        ], 'layouts/auth');
    }

    /** POST /magic/{token} — войти по ссылке. */
    public function magicConfirm(string $token): never
    {
        $row = OneTimeToken::consume($token, 'login_link');
        if (!$row) {
            Flash::error('Ссылка устарела или уже использована. Запросите новую.');
            redirect('/login');
        }
        $user = User::find((int)$row['user_id']);
        if (!\App\Models\User::canSignIn($user)) redirect('/login');
        /* Ссылка пришла на эту почту и открыта — владение ящиком доказано.
           Неподтверждённая почта с этой минуты подтверждена (v4.72). */
        if ($user['email_verified_at'] === null) {
            \App\Core\EmailGate::pass($user, 'magic_link');
            $user = User::find((int)$user['id']);
        }
        if ($user['totp_enabled_at'] !== null) {
            LoginFlow::require2fa($user, false);
        }
        LoginFlow::complete($user, false, 'login.magic_link');
    }

    /* ---- Выход (§2.4) ---- */

    public function logoutChoice(): never
    {
        [$client, $postLogout] = self::logoutTarget((string)($_GET['id_token_hint'] ?? ''),
            (string)($_GET['client_id'] ?? ''), (string)($_GET['post_logout_redirect_uri'] ?? ''), true);
        $state = (string)($_GET['state'] ?? '');
        if (!Auth::check()) {
            redirect(self::afterLogout($client, $postLogout, $state));
        }
        /* Форма выхода уводит на портал 302-м — CSP form-action обрывала бы это
           молча, как обрывала возврат после согласия. Адрес проверен выше. */
        $target = self::afterLogout($client, $postLogout, '');
        if (str_starts_with($target, 'http')) \App\Core\Csp::allowFormTarget($target);
        View::render('auth/logout', [
            'pageTitle' => 'Выход',
            'client' => $client,
            'post_logout' => $postLogout,
            'state' => $state,
            /* «Отмена» — туда, откуда пришли, а не в личный кабинет SSO. */
            'back' => $client ? $target : '/profile',
        ], 'layouts/auth');
    }

    public function doLogout(): never
    {
        [$client, $postLogout] = self::logoutTarget('', (string)($_POST['client_id'] ?? ''),
            (string)($_POST['post_logout'] ?? ''), false);
        $state = (string)($_POST['state'] ?? '');
        if (!Auth::check()) redirect(self::afterLogout($client, $postLogout, $state));
        $mode = (string)($_POST['mode'] ?? 'here');
        $sess = Auth::sessionRecord();

        if ($mode === 'everywhere') {
            if ($sess) EventDispatcher::sessionLogout((int)$sess['id']);
            Auth::logout();
            /* Портал — в журнал: по пустому полю «портал» у каждого выхода и
               выяснилось, что SSO его на выходе не узнавал. */
            Audit::log('logout.everywhere', ['client' => $client ? (int)$client['id'] : null]);
        } elseif ($client !== null) {
            // Пришли с портала: локальную сессию портал уже погасил, SSO-сессия живёт
            if ($sess) {
                foreach (SessionModel::clientsOfSession((int)$sess['id']) as $sc) {
                    if ((int)$sc['client_id'] === (int)$client['id']) SessionModel::markLoggedOut((int)$sc['id']);
                }
            }
            Audit::log('logout', ['client' => (int)$client['id']]);
        } else {
            Auth::logout();
            Audit::log('logout', []);
        }

        $to = self::afterLogout($client, $postLogout, $state);
        if ($to === '/login') Flash::success('Вы вышли.');
        redirect($to);
    }

    /**
     * С какого портала пришли на выход и куда вернуть.
     *
     * Живой дефект: после «Выйти» на портале человек оказывался на входе SSO. В
     * журнале у каждого выхода с портала поле «портал» было пустым: SSO узнавал
     * его только по id_token_hint, а Архив подсказку не доносил. Без портала
     * отбрасывался любой адрес возврата, даже зарегистрированный. Сам адрес он
     * к тому же слал без завершающего слэша — «https://portal.example» против
     * записанного «https://portal.example/».
     *
     * Портал узнаётся по порядку: подсказка → client_id (стандарт выхода его
     * прямо предусматривает) → единственный портал с таким адресом возврата →
     * единственный портал с таким origin. Адрес, которого у портала нет, не
     * исполняется — сравнение байт-в-байт остаётся законом, — но запоминается и
     * показывается в карточке с кнопкой «добавить».
     *
     * @return array{0: ?array, 1: string} [портал, проверенный адрес или '']
     */
    private static function logoutTarget(string $hint, string $clientId, string $postLogout, bool $record): array
    {
        $postLogout = trim($postLogout);
        $client = null;
        if ($hint !== '') {
            $claims = Jwt::verify($hint, 3600 * 24 * 30); // подпись важнее exp: hint мог протухнуть
            $aud = $claims['aud'] ?? null;
            if (is_array($aud)) $aud = $aud[0] ?? null;
            if (is_string($aud) && $aud !== '') $client = Client::findByClientId($aud);
        }
        if (!$client && $clientId !== '') $client = Client::findByClientId($clientId);
        if (!$client && $postLogout !== '') {
            $client = Client::byPostLogoutUri($postLogout) ?? Client::byOrigin($postLogout);
        }
        if ($client && !(int)$client['is_active']) $client = null;

        if ($postLogout !== '' && (!$client || !Client::postLogoutUriAllowed($client, $postLogout))) {
            if ($client && $record) \App\Core\RedirectMiss::record($client, $postLogout, 'logout');
            $postLogout = ''; // непроверяемый адрес не исполняем — не open-redirect (§3.5)
        }
        return [$client, $postLogout];
    }

    /**
     * Куда уйти после выхода: зарегистрированный адрес, иначе сам портал (его
     * base_url заводил администратор), иначе вход SSO — только если портал так
     * и не узнан.
     */
    private static function afterLogout(?array $client, string $postLogout, string $state): string
    {
        if ($postLogout !== '') {
            return $postLogout . ($state !== ''
                ? (str_contains($postLogout, '?') ? '&' : '?') . 'state=' . rawurlencode($state) : '');
        }
        $base = (string)($client['base_url'] ?? '');
        if ($base !== '' && \App\Core\Csp::origin($base) !== null) return $base;
        return '/login';
    }

    /** Переключение языка интерфейса; вошедшему сохраняем выбор в профиль. */
    public function switchLang(string $code): never
    {
        \App\Core\Lang::set($code);
        if (Auth::check() && !Auth::impersonating()) {
            User::update((int)Auth::id(), ['locale' => \App\Core\Lang::current()]);
        }
        redirect(referer_path('/'));
    }

    public function goodbye(): never
    {
        View::render('auth/goodbye', ['pageTitle' => 'Аккаунт удалён'], 'layouts/auth');
    }

    /* ---- Сервисные переходы по токенам ---- */

    /** Отмена удаления аккаунта из письма. */
    public function cancelDelete(string $token): never
    {
        $row = OneTimeToken::consume($token, 'account_delete');
        if (!$row) ErrorPage::render(404);
        $user = User::find((int)$row['user_id']);
        if ($user && $user['status'] === 'deleted' && $user['deleted_at'] !== null) {
            User::update((int)$user['id'], ['status' => 'active', 'deleted_at' => null]);
            Audit::log('account.delete_cancel', ['actor' => (int)$user['id'], 'on' => (int)$user['id']]);
            /* Порталам — что человек вернулся. Они отключили его по «удалён,
               можно отменить» и без этого события держали бы отключённым (v4.62). */
            \App\Oidc\EventDispatcher::userRestored($user);
        }
        Flash::success('Удаление отменено — аккаунт снова активен.');
        redirect('/login');
    }

    /** «Это не я»: откат смены email + заморозка сессий. */
    /** GET — только кнопка: по ссылке из письма ходят и сканеры (см. /verify). */
    public function showRevertEmailChange(string $token): never
    {
        $row = OneTimeToken::peek($token, 'email_change');
        if (!$row || ($row['payload']['kind'] ?? '') !== 'revert') ErrorPage::render(404);
        \App\Core\View::render('auth/confirm', [
            'pageTitle' => t('Отмена смены почты'),
            'title' => t('Вернуть прежнюю почту?'),
            'lead' => t('Все сессии будут завершены — войдите заново и смените пароль.'),
            'button' => t('Отменить смену'),
            'action' => '/email/revert/' . $token,
        ], 'layouts/auth');
    }

    public function revertEmailChange(string $token): never
    {
        $row = OneTimeToken::consume($token, 'email_change');
        if (!$row || ($row['payload']['kind'] ?? '') !== 'revert') ErrorPage::render(404);
        $user = User::find((int)$row['user_id']);
        if ($user) {
            $old = (string)($row['payload']['old_email'] ?? '');
            if ($old !== '' && !User::emailTaken($old, (int)$user['id'])) {
                User::update((int)$user['id'], ['email' => $old]);
            }
            OneTimeToken::cancelByTypeForUser((int)$user['id'], 'email_change');
            SessionModel::revokeAllForUser((int)$user['id']);
            \App\Models\RefreshToken::revokeAllForUser((int)$user['id']);
            Audit::log('email.change_revert', ['actor' => null, 'on' => (int)$user['id']]);
        }
        Flash::success('Смена почты отменена, все сессии завершены. Войдите и смените пароль.');
        redirect('/login');
    }

    /** Одноразовая ссылка «стать админом портала» (§6.1 setup_token). */
    public function clientSetup(string $token): never
    {
        $row = OneTimeToken::peek($token, 'client_setup');
        if (!$row) {
            ErrorPage::render(404, ['title' => 'Ссылка недействительна',
                'message' => 'Setup-ссылка использована или устарела. Попросите новую в админке SSO.']);
        }
        if (!Auth::check()) {
            $_SESSION['return_to'] = '/setup/' . $token;
            Flash::info('Войдите, чтобы получить права администратора портала.');
            redirect('/login');
        }
        OneTimeToken::consume($token, 'client_setup');
        $clientDbId = (int)($row['payload']['client_id'] ?? 0);
        $client = Client::find($clientDbId);
        if (!$client) ErrorPage::render(404);
        $role = Role::clientAdmin();
        Role::assignScoped((int)Auth::id(), (int)$role['id'], \App\Core\Scope::client($clientDbId), null);
        Audit::log('admin.role.assign', ['on' => (int)Auth::id(), 'client' => $clientDbId,
            'meta' => ['role' => 'client_admin', 'via' => 'setup_token']]);
        Flash::success(t('Вы назначены администратором портала «:portal».', ['portal' => $client['name']]));
        redirect('/admin/clients/' . $clientDbId);
    }
}
