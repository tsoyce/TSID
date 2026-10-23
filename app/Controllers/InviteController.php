<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Flash;
use App\Core\RateLimiter;
use App\Core\View;
use App\Models\Invite;

/**
 * Приём инвайт-ссылки: /invite/{token}.
 * Вошёл — доступ выдаётся в один клик; не вошёл — отправляем на вход/регистрацию
 * и возвращаем обратно (принцип «30 секунд»).
 */
final class InviteController
{
    public function show(string $token): never
    {
        RateLimiter::guard('invite:ip:' . RateLimiter::ipKey(), 20, 3600);
        $invite = Invite::findUsable($token);
        if (!$invite) {
            View::render('oauth/gate', [
                'pageTitle' => 'Приглашение недействительно',
                'title' => 'Приглашение недействительно',
                'message' => 'Ссылка отозвана, исчерпана или срок её действия истёк. Попросите администратора портала выдать новую.',
                'client' => ['name' => t('портал'), 'base_url' => '/'],
            ], 'layouts/auth');
        }

        $user = Auth::user();
        // Именное приглашение: принять может только тот адрес, на который выписано
        if ($user && $invite['email'] !== null
            && mb_strtolower((string)$invite['email']) !== mb_strtolower((string)$user['email'])) {
            View::render('oauth/gate', [
                'pageTitle' => 'Приглашение для другого адреса',
                'title' => 'Приглашение выписано на другой email',
                'message' => t('Эта ссылка предназначена для :invited, а вы вошли как :you. Войдите под нужным аккаунтом.',
                    ['invited' => self::maskEmail((string)$invite['email']), 'you' => $user['email']]),
                'client' => ['name' => $invite['client_name'], 'base_url' => $invite['base_url']],
            ], 'layouts/auth');
        }

        View::render('auth/invite', [
            'pageTitle' => t('Приглашение в :portal', ['portal' => $invite['client_name']]),
            'invite' => $invite,
            'token' => $token,
            'user' => $user,
        ], 'layouts/auth');
    }

    public function accept(string $token): never
    {
        $invite = Invite::findUsable($token);
        if (!$invite) {
            Flash::error('Приглашение уже недействительно.');
            redirect('/profile/services');
        }
        if (!Auth::check()) {
            // Вернём сюда же после входа или регистрации
            $_SESSION['return_to'] = '/invite/' . $token;
            redirect('/login');
        }
        $user = Auth::user();
        if ($invite['email'] !== null
            && mb_strtolower((string)$invite['email']) !== mb_strtolower((string)$user['email'])) {
            Flash::error('Приглашение выписано на другой адрес.');
            redirect('/profile/services');
        }
        /* v5.4: именное — только на подтверждённый адрес. Подтверждение почты
           по умолчанию выключено, и чужой адрес можно было вписать себе при
           регистрации — вместе с доступом и ролью, выписанными его хозяину. */
        if ($invite['email'] !== null && empty($user['email_verified_at'])) {
            Flash::error(t('Сначала подтвердите адрес почты: приглашение выписано на него.'));
            redirect('/profile/settings');
        }

        Invite::accept($invite, (int)$user['id']);
        Audit::log('access.granted', [
            'on' => (int)$user['id'],
            'client' => (int)$invite['client_id'],
            'meta' => ['via' => 'invite'],
        ]);
        $portal = ['portal' => $invite['client_name']];
        \App\Core\Notify::send($user, 'access.granted',
            t('Доступ к :portal открыт', $portal),
            t('Вы приняли приглашение — доступ к порталу «:portal» выдан.', $portal),
            ['action_url' => $invite['base_url'], 'action_label' => t('Перейти в :portal', $portal)]);

        Flash::success(t('Доступ к «:portal» открыт.', $portal));
        redirect($invite['base_url']);
    }

    private static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($name, 0, 2);
        return $visible . str_repeat('•', max(1, mb_strlen($name) - 2)) . '@' . $domain;
    }
}
