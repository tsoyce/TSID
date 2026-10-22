<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\EmailGate;
use App\Core\Flash;
use App\Core\LoginFlow;
use App\Core\RateLimiter;
use App\Core\View;
use App\Models\OneTimeToken;
use App\Models\User;

/**
 * Экран «Подтвердите почту» для новых аккаунтов (v4.72) — см. App\Core\EmailGate.
 *
 * Человек здесь ещё не вошёл: сессия помнит только, кто ждёт. Поэтому всё,
 * что можно сделать с этого экрана, — ввести код, попросить письмо ещё раз и
 * исправить адрес, в котором опечатка. Ни профиля, ни порталов, ни смены пароля.
 */
final class EmailGateController
{
    public function wait(): never
    {
        $user = EmailGate::waiting();
        if ($user === null) redirect('/login');
        /* Уже подтвердили — ссылкой в соседней вкладке или администратор
           вручную. Держать на экране ожидания незачем: входим и идём дальше. */
        if (!EmailGate::holds($user)) {
            LoginFlow::complete($user, EmailGate::remember());
        }
        View::render('auth/verify_wait', [
            'pageTitle' => t('Подтвердите почту'),
            'email' => (string)$user['email'],
            'client' => LoginFlow::pendingClient(),
        ], 'layouts/auth');
    }

    /** POST /verify/code — код из письма. */
    public function code(): never
    {
        $user = EmailGate::waiting();
        if ($user === null) redirect('/login');
        /* Шесть цифр — миллион вариантов. Десять попыток за четверть часа на
           человека и тридцать на адрес машины: опечатка проходит, перебор нет. */
        RateLimiter::guard('gate:code:' . (int)$user['id'], 10, 900);
        RateLimiter::guard('gate:code:ip:' . RateLimiter::ipKey(), 30, 900);
        if (OneTimeToken::consumeCode((int)$user['id'], 'email_code', (string)($_POST['code'] ?? ''))) {
            EmailGate::pass($user, 'code');
            LoginFlow::complete(User::find((int)$user['id']), EmailGate::remember());
        }
        Audit::log('email.code_fail', ['actor' => null, 'on' => (int)$user['id']]);
        Flash::error(t('Код не подошёл. Нужен код из последнего письма — прежние гаснут, когда приходит новый.'));
        redirect('/verify/wait');
    }

    /** POST /verify/wait/resend — прислать письмо ещё раз. */
    public function resend(): never
    {
        $user = EmailGate::waiting();
        if ($user === null) redirect('/login');
        RateLimiter::guard('gate:resend:' . (int)$user['id'], 5, 3600);
        EmailGate::send($user);
        Audit::log('email.verify_resent', ['actor' => null, 'on' => (int)$user['id']]);
        Flash::success(t('Отправили ещё раз. Загляните и в «Спам» — туда такие письма попадают чаще всего.'));
        redirect('/verify/wait');
    }

    /**
     * POST /verify/wait/email — исправить адрес, пока он не подтверждён.
     *
     * Та же логика, что у смены неподтверждённого адреса в профиле: адрес
     * ничем не доказан, скорее всего в нём опечатка, и требовать подтверждения
     * со старого — запереть человека в его же опечатке. Занятый адрес не
     * называем занятым: иначе экран превращается в проверялку «есть ли у вас
     * такой человек».
     */
    public function change(): never
    {
        $user = EmailGate::waiting();
        if ($user === null) redirect('/login');
        RateLimiter::guard('gate:change:' . (int)$user['id'], 5, 3600);
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::error(t('Похоже, в адресе опечатка.'));
            redirect('/verify/wait');
        }
        if (!\App\Core\EmailDomains::allows($email)) {
            Flash::error(\App\Core\EmailDomains::refusal());
            redirect('/verify/wait');
        }
        if (!User::emailTaken($email, (int)$user['id'])) {
            User::update((int)$user['id'], ['email' => $email, 'email_verified_at' => null]);
            Audit::log('email.pending_changed', ['actor' => null, 'on' => (int)$user['id'], 'meta' => ['to' => $email]]);
            EmailGate::send(User::find((int)$user['id']));
        }
        Flash::success(t('Готово. Письмо ушло на новый адрес.'));
        redirect('/verify/wait');
    }
}
