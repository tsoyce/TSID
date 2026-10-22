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
    public function showRegister(): never
    {
        /* Дверь закрыта и ключа нет — говорим об этом сразу, а не после того,
           как человек заполнил четыре поля. Приглашение с привязкой к почте
           подставляет её в форму: спрашивать адрес, который мы уже знаем, —
           лишний шаг там, где мы шаги убираем. */
        $invite = \App\Core\Signup::inviteFromRequest();
        if (!\App\Core\Signup::open() && $invite === null) {
            View::render('auth/register_closed', [
                'pageTitle' => t('Регистрация закрыта'),
                'client' => LoginFlow::pendingClient(),
            ], 'layouts/auth');
        }
        View::render('auth/register', [
            'pageTitle' => 'Регистрация',
            'client' => LoginFlow::pendingClient(),
            'popup' => LoginFlow::pendingIsPopup(),
            'old' => ['email' => (string)($invite['payload']['email'] ?? '')],
            'errors' => [],
        ], 'layouts/auth');
    }

    public function doRegister(): never
    {
        RateLimiter::guard('register:ip:' . RateLimiter::ipKey(), 3, 3600);
        $old = [
            'first_name' => trim((string)($_POST['first_name'] ?? '')),
            'last_name' => trim((string)($_POST['last_name'] ?? '')),
            'email' => mb_strtolower(trim((string)($_POST['email'] ?? ''))),
        ];
        $password = (string)($_POST['password'] ?? '');
        $errors = [];
        /* Проверяем на отправке тоже, а не только при показе формы: форму
           присылают и мимо браузера, а закрытая дверь, которую открывает
           лишний POST, — не закрытая дверь. */
        if (!\App\Core\Signup::allowed($old['email'])) {
            $errors['email'] = t('Регистрация закрыта. Нужна ссылка-приглашение, а если она у вас есть — откройте её целиком: приглашение может быть выдано на другой адрес.');
        }
        if ($old['first_name'] === '') $errors['first_name'] = t('Как к вам обращаться?');
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Похоже, в адресе опечатка.';
        } elseif (!\App\Core\EmailDomains::allows($old['email'])) {
            /* Ограничение по домену — только для самостоятельной регистрации.
               Приглашение, импорт, SCIM и API его не спрашивают: там аккаунт
               заводит хозяин установки, и он уже решил. */
            $errors['email'] = \App\Core\EmailDomains::refusal();
        }
        $pwError = \App\Core\PasswordPolicy::check($password);
        if ($pwError !== null) {
            $errors['password'] = $pwError;
        }
        /* Согласие — явными галочками, как на экране соцвхода. До v4.2 здесь
           стояла пассивная строка «создавая аккаунт, вы принимаете…»: клик она
           экономила, но согласием не является, и с обычной регистрацией
           расходилась с соцвходом, где галочки были с v3.9. */
        $docs = \App\Core\Legal::requiringConsent();
        $accepted = (array)($_POST['accept'] ?? []);
        foreach (array_keys($docs) as $key) {
            if (!in_array($key, $accepted, true)) {
                $errors['accept'] = 'Чтобы создать аккаунт, нужно принять все документы.';
                break;
            }
        }
        // Антиперебор: занятый email не палим — говорим то же самое, но письмом
        $emailTaken = $errors === [] && User::emailTaken($old['email']);
        if ($errors !== []) {
            View::render('auth/register', [
                'pageTitle' => 'Регистрация',
                'client' => LoginFlow::pendingClient(),
                'popup' => LoginFlow::pendingIsPopup(),
                'old' => $old, 'errors' => $errors,
            ], 'layouts/auth');
        }
        if ($emailTaken) {
            Flash::success('Проверьте почту: мы отправили письмо со следующим шагом.');
            $existing = User::findByEmail($old['email']);
            if ($existing) {
                Mailer::send($old['email'], t('Попытка регистрации в :app', ['app' => Setting::appName()]), 'register_existing', [
                    'name' => user_name($existing),
                ]);
            }
            redirect('/login');
        }

        $fields = [
            'email' => $old['email'],
            'first_name' => $old['first_name'],
            'last_name' => $old['last_name'] ?: null,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            'status' => 'pending',
            /* Язык интерфейса, в котором человек регистрировался (v4.74): на нём
               придёт письмо с кодом и все следующие. */
            'locale' => \App\Core\Lang::current(),
        ];
        /* Подтверждение почты обязательно (v4.72) — флаг только у тех, кто
           заведён регистрацией сейчас. На отставшей схеме колонки нет: такая
           установка живёт по-старому, а не падает на каждой регистрации. */
        $gated = \App\Core\EmailGate::active() && \App\Core\DB::columnExists('users', 'email_gate');
        if ($gated) $fields['email_gate'] = 1;
        $uid = User::create($fields);
        \App\Core\Legal::record($uid, array_keys($docs));
        \App\Core\Signup::grantDefaultRole($uid);
        /* Приглашение гасим только теперь: аккаунт уже есть. Погасив раньше, мы
           сожгли бы ключ на любой ошибке в форме — и человек остался бы перед
           закрытой дверью с недействительной ссылкой. */
        \App\Core\Signup::consumeInvite();
        Audit::log('register', ['actor' => $uid, 'on' => $uid]);
        \App\Core\WebhookDispatcher::emit('user.created', [
            'sub' => User::find($uid)['public_id'], 'email' => $old['email'], 'name' => $old['first_name'],
        ]);
        \App\Core\AdminNotify::say('user.registered',
            fn() => t('Новый аккаунт: :who.', ['who' => $old['email']]),
            fn() => [t('Имя') => trim($old['first_name'] . ' ' . $old['last_name']),
             t('Когда') => fmt_dt(now()), t('IP') => ip_str(ip_bin())]);
        /* Дверь закрыта до подтверждения: письмо (ссылкой или кодом) и экран
           ожидания вместо входа. */
        if ($gated) \App\Core\EmailGate::stop(User::find($uid), false);
        $this->sendVerifyEmail(User::find($uid));
        $user = User::find($uid);
        Flash::success('Аккаунт создан. Мы отправили письмо — подтвердите почту.');
        LoginFlow::complete($user, false, 'login.success');
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
        View::render('auth/recover', ['pageTitle' => 'Восстановление доступа'], 'layouts/auth');
    }

    public function doRecover(): never
    {
        RateLimiter::guard('recover:ip:' . RateLimiter::ipKey(), 3, 3600);
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
