<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Flash;
use App\Core\Mailer;
use App\Core\Setting;
use App\Core\View;
use App\Models\OneTimeToken;
use App\Models\RefreshToken;
use App\Models\SessionModel;
use App\Models\User;
use App\Oidc\EventDispatcher;

/** Настройки аккаунта: логин/язык/пояс, смена email, удаление (§5.1). */
final class ProfileSettingsController
{
    public function index(): never
    {
        $user = Auth::user();
        // Стоит городская зона (напр., Europe/Moscow из инсталлятора)? Предвыберем её GMT-эквивалент
        $selected = $user['timezone'];
        if (!array_key_exists($selected, tz_options())) {
            try {
                $offset = (new \DateTimeZone($selected))->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));
                foreach (array_keys(tz_options()) as $id) {
                    if ((new \DateTimeZone($id))->getOffset(new \DateTime('now', new \DateTimeZone('UTC'))) === $offset) {
                        $selected = $id;
                        break;
                    }
                }
            } catch (\Throwable) {
                $selected = 'UTC';
            }
        }
        View::render('profile/settings', [
            'pageTitle' => 'Настройки аккаунта',
            'user' => $user,
            'timezones' => tz_options(),
            'tzSelected' => $selected,
            'notifyPrefs' => \App\Core\Notify::prefsFor((int)$user['id']),
            /* Экспорт появился в миграции 003 — без неё раздел просто не
               показывается, а страница настроек открывается как обычно. */
            'exportJob' => \App\Core\Migrations::ready('export_jobs')
                ? \App\Core\DB::row(
                    'SELECT * FROM {export_jobs} WHERE user_id = ? AND expires_at > ? ORDER BY id DESC LIMIT 1',
                    [(int)$user['id'], now()])
                : null,
            'exportReady' => \App\Core\Migrations::ready('export_jobs'),
            /* Жив ли планировщик — от этого зависит, честно ли обещать «скоро» */
            'cronAlive' => (static function (): bool {
                $hb = (string)\App\Core\Setting::get('cron.heartbeat', '');
                return $hb !== '' && strtotime($hb . ' UTC') > time() - 900;
            })(),
        ]);
    }

    /** Переключатели уведомлений безопасности (всё опционально). */
    public function saveNotifications(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation('/profile/settings');
        foreach (array_keys(\App\Core\Notify::EVENTS) as $event) {
            $key = 'notify_' . str_replace('.', '_', $event);
            \App\Core\Notify::setPref((int)$user['id'], $event, !empty($_POST[$key]));
        }
        Audit::log('profile.update', ['on' => (int)$user['id'], 'meta' => ['section' => 'notifications']]);
        Flash::success('Настройки уведомлений сохранены.');
        redirect('/profile/settings#notifications');
    }

    /** Заказать выгрузку данных: задание в очередь, ссылка письмом. */
    public function requestExport(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation('/profile/settings');
        $result = \App\Core\DataExport::requestAndBuild((int)$user['id']);
        if ($result['token'] === null) {
            Flash::info('Выгрузка уже заказана.');
            redirect('/profile/settings#data');
        }
        Mailer::sendLetter($user['email'], 'export_ready', [
            'name' => user_name($user),
            'url' => base_url('/profile/export/' . $result['token']),
        ]);
        /* Обещаем только то, что уже произошло: архив собран здесь же. Если
           сборка почему-то не удалась, задание подберёт cron — но и об этом
           говорим прямо, а не «ссылка придёт в течение пары минут». */
        Flash::success($result['ready']
            ? t('Архив готов — ссылка отправлена на почту.')
            : t('Заявка принята. Архив соберётся фоновой задачей, ссылка придёт письмом.'));
        redirect('/profile/settings#data');
    }

    /** Страница/скачивание выгрузки по токену из письма. */
    public function downloadExport(string $token): never
    {
        $job = \App\Core\DataExport::findByToken($token);
        /* v5.4: выгрузка — только своя. Ссылка из письма, попавшая к другому
           вошедшему (пересланное письмо), отдавала ему чужой архив. */
        if ($job && (int)$job['user_id'] !== (int)\App\Core\Auth::id()) $job = null;
        if (!$job) {
            Flash::error('Ссылка на выгрузку устарела или недействительна.');
            redirect('/profile/settings');
        }
        if ($job['status'] !== 'ready') {
            View::render('profile/export_wait', [
                'pageTitle' => 'Выгрузка готовится',
                'status' => $job['status'],
                'error' => $job['last_error'],
            ], 'layouts/minimal');
        }
        $path = SSO_ROOT . '/storage/' . $job['file_path'];
        if (!is_file($path)) {
            Flash::error('Файл выгрузки не найден — закажите её заново.');
            redirect('/profile/settings#data');
        }
        Audit::log('account.export_download', ['actor' => (int)$job['user_id'], 'on' => (int)$job['user_id']]);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="my-data-' . gmdate('Ymd') . '.zip"');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }

    public function saveAccount(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation('/profile/settings');
        /* Правило одно на все двери — App\Core\LoginName. Здесь оно жило
           отдельной регуляркой, а SCIM, Admin API, импорт и перенос с портала
           не проверяли ничего, кроме занятости. */
        $login = \App\Core\LoginName::normalize((string)($_POST['login'] ?? ''));
        $loginError = \App\Core\LoginName::check($login);
        if ($loginError !== null) {
            Flash::error($loginError);
            redirect('/profile/settings');
        }
        if ($login !== '' && User::loginTaken($login, (int)$user['id'])) {
            Flash::error('Такой логин уже занят.');
            redirect('/profile/settings');
        }
        $tz = (string)($_POST['timezone'] ?? $user['timezone']);
        if (!array_key_exists($tz, tz_options())) $tz = $user['timezone'];
        User::update((int)$user['id'], [
            'login' => $login ?: null,
            'timezone' => $tz,
            'locale' => in_array($_POST['locale'] ?? '', ['ru', 'en'], true) ? $_POST['locale'] : 'ru',
        ]);
        Audit::log('profile.update', ['on' => (int)$user['id'], 'meta' => ['section' => 'account']]);
        Flash::success('Настройки сохранены.');
        redirect('/profile/settings');
    }

    /* ---- Смена email: подтверждение на НОВЫЙ + «это не я» на старый (§5.1) ---- */

    public function requestEmailChange(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation('/profile/settings');
        $newEmail = mb_strtolower(trim((string)($_POST['new_email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if (empty($user['password_hash']) || !password_verify($password, (string)$user['password_hash'])) {
            Flash::error('Пароль не подошёл.');
            redirect('/profile/settings');
        }
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || $newEmail === $user['email']) {
            Flash::error('Проверьте новый адрес.');
            redirect('/profile/settings');
        }
        // Занятость чужим аккаунтом не палим — ответ одинаковый (§8.2)
        if (!User::emailTaken($newEmail, (int)$user['id'])) {
            $confirm = OneTimeToken::issue((int)$user['id'], 'email_change',
                ['kind' => 'confirm', 'new_email' => $newEmail]);
            Mailer::sendLetter($newEmail, 'email_change', [
                'name' => user_name($user),
                'url' => base_url('/email/confirm/' . $confirm),
                'new_email' => $newEmail,
            ]);
            /* Ревертный токен старому адресу живёт дольше подтверждающего.
               Выдаётся без user_id и привязывается после: issue() гасит
               прежние токены типа, а прежние «Это не я» гаснуть не должны. */
            $revert = \App\Models\OneTimeToken::issue(null, 'email_revert',
                ['kind' => 'revert', 'old_email' => $user['email'], 'user_id' => (int)$user['id']]);
            \App\Core\DB::run('UPDATE {one_time_tokens} SET user_id = ? WHERE token_hash = ?',
                [(int)$user['id'], \App\Core\Crypto::hashToken($revert)]);
            /* Уведомление и «откатить» — старому адресу. Когда почты не было
               вовсе, это не смена, а добавление: откатывать некуда и предупреждать
               некого, а попытка отправки на NULL кончилась бы исключением ровно
               там, где человек впервые заводит себе почту. */
            if (!empty($user['email'])) {
                Mailer::send($user['email'], t('Почта аккаунта меняется — :app', ['app' => Setting::appName()]), 'email_change_notice', [
                    'name' => user_name($user),
                    'new_email' => $newEmail,
                    'revert_url' => base_url('/email/revert/' . $revert),
                ]);
            }
            Audit::log('email.change_request', ['on' => (int)$user['id']]);
        }
        Flash::success(empty($user['email'])
            ? t('Отправили подтверждение на указанный адрес.')
            : t('Отправили подтверждение на новый адрес и уведомление на текущий.'));
        redirect('/profile/settings');
    }

    /** GET — только кнопка: по ссылке из письма ходят и сканеры (см. /verify). */
    public function showConfirmEmailChange(string $token): never
    {
        $row = OneTimeToken::peek($token, 'email_change');
        if (!$row || ($row['payload']['kind'] ?? '') !== 'confirm') {
            Flash::error('Ссылка устарела или уже использована.');
            redirect('/login');
        }
        \App\Core\View::render('auth/confirm', [
            'pageTitle' => 'Смена почты',
            'title' => t('Подтвердите новый адрес'),
            'lead' => (string)($row['payload']['new_email'] ?? ''),
            'button' => t('Подтвердить'),
            'action' => '/email/confirm/' . $token,
        ], 'layouts/auth');
    }

    public function confirmEmailChange(string $token): never
    {
        $row = OneTimeToken::consume($token, 'email_change');
        if (!$row || ($row['payload']['kind'] ?? '') !== 'confirm') {
            Flash::error('Ссылка устарела или уже использована.');
            redirect('/login');
        }
        $user = User::find((int)$row['user_id']);
        $newEmail = (string)($row['payload']['new_email'] ?? '');
        if (!$user || $newEmail === '' || User::emailTaken($newEmail, (int)$user['id'])) {
            Flash::error('Адрес уже занят другим аккаунтом — смена отменена.');
            redirect('/login');
        }
        User::update((int)$user['id'], ['email' => $newEmail, 'email_verified_at' => now()]);
        Audit::log('email.change_done', ['on' => (int)$user['id']]);
        EventDispatcher::userEvent(User::find((int)$user['id']), 'user.updated', ['fields' => ['email']]);
        Flash::success('Почта обновлена.');
        redirect(Auth::check() ? '/profile/settings' : '/login');
    }

    /* ---- Удаление: пароль → soft-delete, холд 14 дней (§5.1) ---- */

    public function requestDelete(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation('/profile/settings');
        $password = (string)($_POST['password'] ?? '');
        if (empty($user['password_hash']) || !password_verify($password, (string)$user['password_hash'])) {
            Flash::error('Пароль не подошёл — аккаунт не тронут.');
            redirect('/profile/settings');
        }
        if (Auth::isSuperadmin()) {
            Flash::error('Суперадмин не может удалить себя: сначала передайте права.');
            redirect('/profile/settings');
        }
        User::update((int)$user['id'], ['status' => 'deleted', 'deleted_at' => now()]);
        $cancel = OneTimeToken::issue((int)$user['id'], 'account_delete');
        Mailer::sendLetter($user['email'], 'delete_hold', [
            'name' => user_name($user),
            'cancel_url' => base_url('/delete/cancel/' . $cancel),
        ]);
        /* Порталам — «удалён, но две недели можно отменить»: портал отключает
           человека и ничего не стирает (v4.62). Сессии и токены — под нож. */
        EventDispatcher::userDeleted($user, 'scheduled');
        foreach (SessionModel::activeForUser((int)$user['id']) as $s) {
            EventDispatcher::sessionLogout((int)$s['id']);
        }
        SessionModel::revokeAllForUser((int)$user['id']);
        RefreshToken::revokeAllForUser((int)$user['id']);
        Audit::log('account.delete_request', ['on' => (int)$user['id']]);
        Auth::logout();
        redirect('/goodbye');
    }

    private function denyOnImpersonation(string $back): void
    {
        if (Auth::impersonating()) {
            Flash::error('В режиме имперсонизации смена пароля и почты запрещена.');
            redirect($back);
        }
    }
}
