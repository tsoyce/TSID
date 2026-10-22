<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\QuickUnlock;
use App\Core\View;

/**
 * Экран замка: «это вы?» вместо «кто вы?».
 *
 * Отпирают отпечатком (ключ устройства) или PIN. Пароль тут не спрашивается
 * вовсе: у кого есть пароль, тот может просто выйти и войти заново — кнопка
 * «выйти» на экране есть. Замок нужен для того, чтобы НЕ вводить пароль.
 */
final class LockController
{
    public function show(): never
    {
        $user = Auth::user();
        if (!$user) redirect('/login');
        if (!Auth::locked()) redirect('/profile');

        View::render('auth/lock', [
            'pageTitle' => 'Подтвердите, что это вы',
            'user' => $user,
            'hasPin' => QuickUnlock::hasPin($user),
            'hasPasskey' => QuickUnlock::hasPasskey((int)$user['id']),
            'error' => '',
        ], 'layouts/auth');
    }

    public function pin(): never
    {
        $user = Auth::user();
        $sess = Auth::sessionRecord();
        if (!$user || !$sess) redirect('/login');
        if (!Auth::locked()) redirect('/profile');

        $pin = trim((string)($_POST['pin'] ?? ''));
        if (QuickUnlock::tryPin($user, $sess, $pin)) {
            /* Адрес возврата — из сессии, но записан он из адреса запроса:
               `//чужой.сайт` в пути уводил бы после PIN наружу (v4.70). */
            $back = local_path((string)($_SESSION['return_to'] ?? ''), '/profile');
            unset($_SESSION['return_to']);
            redirect($back);
        }
        /* Сессию могли погасить за пятую неудачу — тогда человек уже никто. */
        if (!Auth::check()) {
            Flash::error(t('Слишком много попыток. Войдите заново.'));
            redirect('/login');
        }
        View::render('auth/lock', [
            'pageTitle' => 'Подтвердите, что это вы',
            'user' => $user,
            'hasPin' => QuickUnlock::hasPin($user),
            'hasPasskey' => QuickUnlock::hasPasskey((int)$user['id']),
            'error' => t('Код не подошёл.'),
        ], 'layouts/auth');
    }
}
