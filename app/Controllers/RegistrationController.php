<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Flash;
use App\Core\LoginFlow;
use App\Core\Mailer;
use App\Core\RateLimiter;
use App\Core\Setting;
use App\Core\View;
use App\Models\OneTimeToken;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\SessionModel;
use App\Models\User;

/** Регистрация, подтверждение почты, восстановление и установка пароля. */
final class RegistrationController
{
    /*
     * Регистрация по шагам (v4.96): почта → код из письма → имя и пароль.
     *
     * Было: одна форма из четырёх полей, аккаунт заводился сразу, а почту
     * подтверждали потом. Боты заполняли форму с чужими адресами — и в базе
     * копились аккаунты, которых никто не подтвердит. Теперь аккаунт рождается
     * последним шагом и уже с подтверждённой почтой; до того есть только
     * запрос в сессии и одноразовый код в письме.
     *
     * Шаг с кодом — только когда подтверждение почты включено (настройка
     * «Регистрация → подтверждать почту»). Выключено — после почты сразу имя:
     * механизм один, в нём просто нет второго шага.
     *
     * Состояние — в сессии: ['email', 'stage' => code|profile, 'token',
     * 'invite', 'verified', 'at']. Код и ссылка — одна строка одноразовых
     * токенов без владельца: ссылка — сам токен, код — его хэш в payload.
     */
    private const SIGNUP_TTL = 3600;

    /** Запрос регистрации этой сессии, если он ещё живой. */
    private static function pending(): ?array
    {
        $s = $_SESSION['signup'] ?? null;
        if (!is_array($s) || (int)($s['at'] ?? 0) < time() - self::SIGNUP_TTL) {
            unset($_SESSION['signup']);
            return null;
        }
        return $s;
    }

    /** На экран регистрации — с приглашением и ключом возврата, если они есть. */
    private static function back(): never
    {
        $q = array_filter([
            'invite' => (string)(self::pending()['invite'] ?? \App\Core\Signup::inviteFromRequest()['token'] ?? ''),
            'rt' => \App\Core\Resume::fromRequest(),
        ]);
        redirect('/register' . ($q !== [] ? '?' . http_build_query($q) : ''));
    }

    private static function render(string $step, array $data = []): never
    {
        View::render('auth/register', $data + [
            'pageTitle' => 'Регистрация',
            'client' => LoginFlow::pendingClient(),
            'popup' => LoginFlow::pendingIsPopup(),
            'step' => $step,
            'steps' => \App\Core\EmailGate::active() ? 3 : 2,
            'email' => (string)(self::pending()['email'] ?? ''),
            'old' => [],
            'errors' => [],
            'captcha' => $step === 'email' && \App\Core\Captcha::requiredForRegister(),
        ], 'layouts/auth');
    }

    public function showRegister(): never
    {
        /* Дверь закрыта и ключа нет — говорим об этом сразу, а не после того,
           как человек начал заполнять. Приглашение с привязкой к почте
           подставляет её в форму: спрашивать адрес, который мы уже знаем, —
           лишний шаг там, где мы шаги убираем. */
        $invite = \App\Core\Signup::inviteFromRequest();
        $pending = self::pending();
        if (!\App\Core\Signup::open() && $invite === null && $pending === null) {
            View::render('auth/register_closed', [
                'pageTitle' => t('Регистрация закрыта'),
                'client' => LoginFlow::pendingClient(),
            ], 'layouts/auth');
        }
        if ($pending !== null) self::render((string)$pending['stage']);
        self::render('email', ['old' => ['email' => (string)($_SESSION['signup_retry'] ?? $invite['payload']['email'] ?? '')]]);
    }

    /** Шаг 1: почта. */
    public function doRegister(): never
    {
        RateLimiter::guard('register:ip:' . RateLimiter::ipKey(), 3, 3600);
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $fail = static fn(string $why) => self::render('email', ['old' => ['email' => $email], 'errors' => ['email' => $why]]);
        /* v4.95: боты регистрировались с браузеров, которых у людей давно нет, —
           64 из 64, а у людей ни разу (см. App\Core\BotSignal). Отказ до всего
           остального: письмо чужому ящику не уходит. */
        $outdated = \App\Core\BotSignal::refuseOutdated() ? \App\Core\BotSignal::outdated(request_ua()) : null;
        if ($outdated !== null) {
            Audit::log('register.refused', ['actor' => null, 'meta' => ['why' => $outdated]]);
            self::render('email', ['old' => ['email' => $email],
                'errors' => ['form' => t('Ваш браузер устарел (:b) — с него зарегистрироваться не получится. Обновите браузер или откройте эту страницу в другом.', ['b' => $outdated])]]);
        }
        /* v4.96: переключатель «Требовать при регистрации» был в настройках с
           v4.0, а регистрация его не спрашивала — включённый, он не делал ничего. */
        if (\App\Core\Captcha::requiredForRegister() && !\App\Core\Captcha::verify((string)($_POST['captcha'] ?? ''))) {
            self::render('email', ['old' => ['email' => $email], 'errors' => ['form' => t('Проверка не пройдена. Попробуйте ещё раз.')]]);
        }
        /* Проверяем на отправке тоже, а не только при показе формы: форму
           присылают и мимо браузера, а закрытая дверь, которую открывает
           лишний POST, — не закрытая дверь. */
        if (!\App\Core\Signup::allowed($email)) {
            $fail(t('Регистрация закрыта. Нужна ссылка-приглашение, а если она у вас есть — откройте её целиком: приглашение может быть выдано на другой адрес.'));
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $fail(t('Похоже, в адресе опечатка.'));
        /* Ограничение по домену — только для самостоятельной регистрации.
           Приглашение, импорт, SCIM и API его не спрашивают: там аккаунт
           заводит хозяин установки, и он уже решил. */
        if (!\App\Core\EmailDomains::allows($email)) $fail(\App\Core\EmailDomains::refusal());
        unset($_SESSION['signup_retry']);
        $invite = (string)(\App\Core\Signup::inviteFromRequest()['token'] ?? '');
        $gated = \App\Core\EmailGate::active();
        $taken = User::emailTaken($email);
        /* Антиперебор: занятый адрес не называем занятым — владельцу уходит
           письмо «кто-то пытался зарегистрироваться», а на экране то же, что
           у всех: «введите код». Код не подойдёт: его нет. */
        if ($taken) {
            $existing = User::findByEmail($email);
            if ($existing) {
                Mailer::send($email, t('Попытка регистрации в :app', ['app' => Setting::appName()]), 'register_existing', [
                    'name' => user_name($existing),
                ]);
            }
            if (!$gated) {
                Flash::success('Проверьте почту: мы отправили письмо со следующим шагом.');
                redirect('/login');
            }
        }
        $_SESSION['signup'] = ['email' => $email, 'stage' => $gated ? 'code' : 'profile', 'token' => null,
            'invite' => $invite ?: null, 'verified' => false, 'at' => time()];
        if ($gated && !$taken) self::sendCode();
        self::back();
    }

    /** Письмо с кодом и ссылкой; прежний запрос этой сессии гаснет. */
    private static function sendCode(): void
    {
        $s = self::pending();
        if ($s === null) return;
        if (!empty($s['token'])) {
            $old = OneTimeToken::peek((string)$s['token'], 'email_code');
            if ($old !== null) OneTimeToken::claim((int)$old['id']);
        }
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token = OneTimeToken::issue(null, 'email_code', [
            'email' => $s['email'], 'code' => \App\Core\Crypto::hashToken($s['email'] . ':' . $code),
            'invite' => $s['invite'],
        ]);
        $_SESSION['signup']['token'] = $token;
        Mailer::sendLetter((string)$s['email'], 'verify_code', [
            'name' => '', 'code' => $code, 'url' => base_url('/register/continue/' . $token),
        ]);
    }

    /** Шаг 2: код из письма. */
    public function code(): never
    {
        $s = self::pending();
        if ($s === null || $s['stage'] !== 'code') self::back();
        /* Шесть цифр — миллион вариантов. Десять попыток за четверть часа на
           запрос и тридцать на адрес машины: опечатка проходит, перебор нет. */
        RateLimiter::guard('signup:code:' . substr(hash('sha256', (string)$s['email']), 0, 16), 10, 900);
        RateLimiter::guard('signup:code:ip:' . RateLimiter::ipKey(), 30, 900);
        $code = (string)preg_replace('~\D~', '', (string)($_POST['code'] ?? ''));
        $row = !empty($s['token']) ? OneTimeToken::peek((string)$s['token'], 'email_code') : null;
        if ($row !== null && $code !== ''
            && hash_equals((string)($row['payload']['code'] ?? ''), \App\Core\Crypto::hashToken($s['email'] . ':' . $code))
            && OneTimeToken::claim((int)$row['id'])) {
            $_SESSION['signup'] = ['stage' => 'profile', 'verified' => true, 'token' => null, 'at' => time()] + $s;
            self::back();
        }
        Audit::log('email.code_fail', ['actor' => null, 'meta' => ['signup' => true]]);
        Flash::error(t('Код не подошёл. Нужен код из последнего письма — прежние гаснут, когда приходит новый.'));
        self::back();
    }

    /** Прислать код ещё раз. */
    public function resend(): never
    {
        $s = self::pending();
        if ($s === null || $s['stage'] !== 'code') self::back();
        RateLimiter::guard('signup:resend:' . substr(hash('sha256', (string)$s['email']), 0, 16), 5, 3600);
        if (!User::emailTaken((string)$s['email'])) self::sendCode();
        Flash::success(t('Отправили ещё раз. Загляните и в «Спам» — туда такие письма попадают чаще всего.'));
        self::back();
    }

    /** Другой адрес: назад к первому шагу, с прежним адресом в поле — поправить опечатку. */
    public function restart(): never
    {
        $s = self::pending();
        if ($s !== null && !empty($s['token'])) {
            $row = OneTimeToken::peek((string)$s['token'], 'email_code');
            if ($row !== null) OneTimeToken::claim((int)$row['id']);
        }
        $_SESSION['signup_retry'] = (string)($s['email'] ?? '');
        unset($_SESSION['signup']);
        self::back();
    }

    /**
     * GET /register/continue/{token} — ссылка из письма. Только кнопка: ссылку
     * открывают и сканеры почты, и превью мессенджеров (см. showVerifyEmail).
     * Открыть её можно и в другом браузере — регистрация продолжится там.
     */
    public function showContinue(string $token): never
    {
        if (OneTimeToken::peek($token, 'email_code') === null) {
            Flash::error(t('Ссылка устарела. Начните регистрацию заново — это одно поле.'));
            redirect('/register');
        }
        View::render('auth/confirm', [
            'pageTitle' => 'Регистрация',
            'title' => t('Подтвердите почту'),
            'lead' => t('Остался один шаг — имя и пароль.'),
            'button' => t('Подтвердить'),
            'action' => '/register/continue/' . $token,
        ], 'layouts/auth');
    }

    public function continueByLink(string $token): never
    {
        $row = OneTimeToken::consume($token, 'email_code');
        $email = (string)($row['payload']['email'] ?? '');
        if ($row === null || $email === '') {
            Flash::error(t('Ссылка устарела. Начните регистрацию заново — это одно поле.'));
            redirect('/register');
        }
        $invite = $row['payload']['invite'] ?? null;
        $_SESSION['signup'] = ['email' => $email, 'stage' => 'profile', 'token' => null,
            'invite' => is_string($invite) && $invite !== '' ? $invite : null, 'verified' => true, 'at' => time()];
        self::back();
    }

    /** Шаг 3: имя и пароль — и только теперь аккаунт. */
    public function finish(): never
    {
        $s = self::pending();
        if ($s === null || $s['stage'] !== 'profile') self::back();
        $email = (string)$s['email'];
        $old = [
            'first_name' => trim((string)($_POST['first_name'] ?? '')),
            'last_name' => trim((string)($_POST['last_name'] ?? '')),
        ];
        $password = (string)($_POST['password'] ?? '');
        $errors = [];
        if ($old['first_name'] === '') $errors['first_name'] = t('Как к вам обращаться?');
        $pwError = \App\Core\PasswordPolicy::check($password);
        if ($pwError !== null) $errors['password'] = $pwError;
        /* Согласие — явными галочками, как на экране соцвхода (v4.2): согласие,
           проставленное за человека, — не согласие. */
        $docs = \App\Core\Legal::requiringConsent();
        $accepted = (array)($_POST['accept'] ?? []);
        foreach (array_keys($docs) as $key) {
            if (!in_array($key, $accepted, true)) {
                $errors['accept'] = 'Чтобы создать аккаунт, нужно принять все документы.';
                break;
            }
        }
        if ($errors !== []) self::render('profile', ['old' => $old, 'errors' => $errors]);
        /* Пока человек вводил имя, адрес мог занять другой — тем же путём или
           администратор. Второго аккаунта на одну почту не будет. */
        if (User::emailTaken($email)) {
            unset($_SESSION['signup']);
            Flash::error(t('Аккаунт с этой почтой уже есть — войдите в него.'));
            redirect('/login');
        }
        /* Приглашение — из запроса регистрации: на третьем шаге его в адресе
           уже может не быть (ссылка из письма открыта в другом браузере). */
        if (!empty($s['invite'])) $_POST['invite'] = $s['invite'];
        if (!\App\Core\Signup::allowed($email)) {
            unset($_SESSION['signup']);
            Flash::error(t('Регистрация закрыта. Нужна ссылка-приглашение, а если она у вас есть — откройте её целиком: приглашение может быть выдано на другой адрес.'));
            redirect('/register');
        }

        $verified = !empty($s['verified']);
        $fields = [
            'email' => $email,
            'first_name' => $old['first_name'],
            'last_name' => $old['last_name'] ?: null,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            'status' => $verified ? 'active' : 'pending',
            'email_verified_at' => $verified ? now() : null,
            /* Язык интерфейса, в котором человек регистрировался (v4.74): на нём
               придут все следующие письма. */
            'locale' => \App\Core\Lang::current(),
        ];
        $uid = User::create($fields);
        unset($_SESSION['signup']);
        \App\Core\Legal::record($uid, array_keys($docs));
        \App\Core\Signup::grantDefaultRole($uid);
        /* Приглашение гасим только теперь: аккаунт уже есть. Погасив раньше, мы
           сожгли бы ключ на любой ошибке в форме. */
        \App\Core\Signup::consumeInvite();
        Audit::log('register', ['actor' => $uid, 'on' => $uid, 'meta' => $verified ? ['email' => 'verified'] : null]);
        \App\Core\WebhookDispatcher::emit('user.created', [
            'sub' => User::find($uid)['public_id'], 'email' => $email, 'name' => $old['first_name'],
        ]);
        \App\Core\AdminNotify::say('user.registered',
            fn() => t('Новый аккаунт: :who.', ['who' => $email]),
            fn() => [t('Имя') => trim($old['first_name'] . ' ' . $old['last_name']),
             t('Когда') => fmt_dt(now()), t('IP') => ip_str(ip_bin())]);
        /* Подтверждение выключено — письмо со ссылкой уходит как раньше, а
           вход сразу: почту человек подтвердит, когда дойдёт до неё. */
        if (!$verified) {
            $this->sendVerifyEmail(User::find($uid));
            Flash::success('Аккаунт создан. Мы отправили письмо — подтвердите почту.');
        }
        LoginFlow::complete(User::find($uid), false, 'login.success');
    }

    private function sendVerifyEmail(array $user): void
    {
        $token = OneTimeToken::issue((int)$user['id'], 'email_verify');
        Mailer::sendLetter($user['email'], 'verify', [
            'name' => user_name($user),
            'url' => base_url('/verify/' . $token),
        ]);
    }

    /**
     * POST /verify/resend — прислать письмо подтверждения ещё раз.
     *
     * Письмо теряется: попадает в спам, отбивается почтовым сервером, приходит
     * через час. До этого выхода не было вовсе — человек с неподтверждённой
     * почтой мог только зарегистрироваться заново на другой адрес.
     */
    public function resendVerify(): never
    {
        $user = \App\Core\Auth::user();
        if (!$user || $user['email_verified_at'] !== null || empty($user['email'])) {
            redirect('/profile');
        }
        /* Ограничение по аккаунту, а не по IP: за одной конторой один адрес, и
           общий счётчик закрыл бы кнопку всем из-за одного нетерпеливого. */
        RateLimiter::guard('verify:resend:' . (int)$user['id'], 5, 3600);
        $this->sendVerifyEmail($user);
        Audit::log('email.verify_resent', ['actor' => (int)$user['id'], 'on' => (int)$user['id']]);
        Flash::success('Письмо отправлено ещё раз. Проверьте папку «Спам» — туда попадает чаще всего.');
        redirect(back_to('/profile'));
    }

    /** Экран смены неподтверждённого адреса. */
    public function showChangePending(): never
    {
        $user = \App\Core\Auth::user();
        if (!$user || $user['email_verified_at'] !== null) redirect('/profile');
        View::render('auth/change_pending', [
            'pageTitle' => 'Смена адреса',
            'current' => (string)($user['email'] ?? ''),
            'error' => '',
        ], 'layouts/auth');
    }

    /**
     * POST /verify/change — заменить адрес, который ещё не подтверждён.
     *
     * Здесь НЕ спрашиваем пароль и НЕ шлём письмо на старый адрес — в отличие
     * от смены подтверждённой почты в настройках. Разница принципиальная:
     * подтверждённый адрес доказано принадлежит человеку, и его надо защищать;
     * неподтверждённый не доказан ничем — вероятнее всего, в нём опечатка,
     * из-за которой письмо и не пришло. Требовать подтверждения с адреса,
     * который не работает, значит запереть человека в его же опечатке.
     */
    public function changePending(): never
    {
        $user = \App\Core\Auth::user();
        if (!$user || $user['email_verified_at'] !== null) redirect('/profile');
        RateLimiter::guard('verify:change:' . (int)$user['id'], 5, 3600);

        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $fail = static function (string $why) use ($user): never {
            View::render('auth/change_pending', [
                'pageTitle' => 'Смена адреса',
                'current' => (string)($user['email'] ?? ''),
                'error' => $why,
            ], 'layouts/auth');
        };
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $fail(t('Похоже, в адресе опечатка.'));
        if (!\App\Core\EmailDomains::allows($email)) $fail(\App\Core\EmailDomains::refusal());
        if ($email === mb_strtolower((string)($user['email'] ?? ''))) {
            $fail(t('Это тот же самый адрес. Если письмо не дошло — отправьте его ещё раз.'));
        }
        /* Занятый адрес не называем занятым: иначе форма превращается в
           проверялку «есть ли у вас такой человек». Говорим то же, что и в
           успешном случае, но письма не шлём. */
        if (User::emailTaken($email, (int)$user['id'])) {
            Flash::success('Готово. Проверьте почту — там письмо со следующим шагом.');
            redirect('/profile');
        }
        User::update((int)$user['id'], ['email' => $email, 'email_verified_at' => null]);
        Audit::log('email.pending_changed', ['actor' => (int)$user['id'], 'on' => (int)$user['id'],
            'meta' => ['to' => $email]]);
        $this->sendVerifyEmail(User::find((int)$user['id']));
        Flash::success('Адрес изменён. Проверьте почту — там письмо со следующим шагом.');
        redirect('/profile');
    }

    /**
     * GET /verify/{token} — только показываем кнопку, токен не гасим.
     * По ссылке из письма ходит не только человек: Outlook Safe Links,
     * антивирусы и превью в мессенджерах дёргают её сами, и на GET аккаунт
     * подтверждался бы без участия владельца почты. Заодно это закрывает
     * подтверждение чужой почты по пересланному письму.
     */
    public function showVerifyEmail(string $token): never
    {
        if (!OneTimeToken::peek($token, 'email_verify')) {
            Flash::error('Ссылка подтверждения устарела. Запросите новую в профиле.');
            redirect('/login');
        }
        View::render('auth/confirm', [
            'pageTitle' => 'Подтверждение почты',
            'title' => t('Подтвердите почту'),
            'lead' => t('Остался один шаг.'),
            'button' => t('Подтвердить'),
            'action' => '/verify/' . $token,
        ], 'layouts/auth');
    }

    public function verifyEmail(string $token): never
    {
        $row = OneTimeToken::consume($token, 'email_verify');
        if (!$row) {
            Flash::error('Ссылка подтверждения устарела. Запросите новую в профиле.');
            redirect('/login');
        }
        $user = User::find((int)$row['user_id']);
        if ($user) {
            \App\Core\EmailGate::pass($user, 'link');
            /* Ссылку открыли в том же браузере, где ждут подтверждения, —
               сразу входим и продолжаем, куда человек шёл (v4.72). В другом
               браузере — только подтверждаем: ссылка из письма не должна
               открывать сессию там, где пароль не вводили. */
            $waiting = \App\Core\EmailGate::waiting();
            if ($waiting !== null && (int)$waiting['id'] === (int)$user['id']) {
                $remember = \App\Core\EmailGate::remember();
                Flash::success('Почта подтверждена.');
                LoginFlow::complete(User::find((int)$user['id']), $remember);
            }
        }
        Flash::success('Почта подтверждена.');
        // Если пришли из /authorize — вернёмся в флоу (§5.1)
        redirect(\App\Core\Auth::check() ? LoginFlow::nextUrl() : '/login');
    }

    /* ---- Восстановление пароля ---- */

    public function showRecover(): never
    {
        View::render('auth/recover', ['pageTitle' => 'Восстановление доступа',
            'captcha' => \App\Core\Captcha::requiredForRecover()], 'layouts/auth');
    }

    public function doRecover(): never
    {
        RateLimiter::guard('recover:ip:' . RateLimiter::ipKey(), 3, 3600);
        /* v4.96: «Требовать при восстановлении» было в настройках, а экран его
           не спрашивал — как и регистрация. */
        if (\App\Core\Captcha::requiredForRecover() && !\App\Core\Captcha::verify((string)($_POST['captcha'] ?? ''))) {
            Flash::error(t('Проверка не пройдена. Попробуйте ещё раз.'));
            redirect('/recover');
        }
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)
            && RateLimiter::hit('recover:email:' . substr(hash('sha256', $email), 0, 16), 3, 3600)) {
            $user = User::findByEmail($email);
            if (\App\Models\User::canSignIn($user)) {
                $token = OneTimeToken::issue((int)$user['id'], 'password_reset');
                Audit::log('password.reset_request', ['actor' => null, 'on' => (int)$user['id']]);
                Mailer::sendLetter($email, 'reset', [
                    'name' => user_name($user),
                    'url' => base_url('/recover/' . $token),
                ]);
            }
        }
        Flash::success('Если email существует — письмо отправлено.');
        redirect('/recover');
    }

    public function showReset(string $token): never
    {
        if (!OneTimeToken::peek($token, 'password_reset')) {
            Flash::error('Ссылка устарела или уже использована. Запросите новую.');
            redirect('/recover');
        }
        View::render('auth/reset', ['pageTitle' => 'Новый пароль', 'token' => $token, 'action' => '/recover/' . $token], 'layouts/auth');
    }

    public function doReset(string $token): never
    {
        $this->applyNewPassword($token, 'password_reset');
    }

    /** Установка пароля импортированным пользователям (§13.1). */
    public function showSetPassword(string $token): never
    {
        if (!OneTimeToken::peek($token, 'password_set')) {
            Flash::error('Ссылка устарела. Запросите восстановление пароля.');
            redirect('/recover');
        }
        View::render('auth/reset', ['pageTitle' => 'Задайте пароль', 'token' => $token, 'action' => '/password/set/' . $token], 'layouts/auth');
    }

    public function doSetPassword(string $token): never
    {
        $this->applyNewPassword($token, 'password_set');
    }

    private function applyNewPassword(string $token, string $type): never
    {
        $password = (string)($_POST['password'] ?? '');
        $pwError = \App\Core\PasswordPolicy::check($password);
        if ($pwError !== null) {
            Flash::error($pwError);
            redirect(($type === 'password_reset' ? '/recover/' : '/password/set/') . $token);
        }
        $row = OneTimeToken::consume($token, $type);
        if (!$row) {
            Flash::error('Ссылка устарела. Запросите новую.');
            redirect('/recover');
        }
        $user = User::find((int)$row['user_id']);
        if (!$user) redirect('/login');
        $data = [
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            'password_legacy' => 0,
            'failed_logins' => 0,
            'locked_until' => null,
        ];
        if ($type === 'password_set' && $user['status'] === 'pending' && $user['email_verified_at'] === null) {
            $data['email_verified_at'] = now(); // ссылка пришла на его почту
            $data['status'] = 'active';
        }
        User::update((int)$user['id'], $data);
        // Успешный сброс гасит все сессии (§5.1) — новую пользователь создаст входом
        SessionModel::revokeAllForUser((int)$user['id']);
        RefreshToken::revokeAllForUser((int)$user['id']);
        Audit::log('password.reset_done', ['actor' => (int)$user['id'], 'on' => (int)$user['id']]);
        Flash::success('Пароль обновлён. Войдите с новым паролем.');
        redirect('/login');
    }
}
