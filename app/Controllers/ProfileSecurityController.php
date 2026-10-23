<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Flash;
use App\Core\LoginFlow;
use App\Core\RateLimiter;
use App\Core\Setting;
use App\Core\Totp;
use App\Core\View;
use App\Models\RefreshToken;
use App\Models\SessionModel;
use App\Models\User;

/** Безопасность: смена пароля, 2FA (TOTP + recovery-коды), оценка защищённости. */
final class ProfileSecurityController
{
    public function index(): never
    {
        $user = Auth::user();
        $pendingSecret = $_SESSION['totp_setup_secret'] ?? null;
        $freshRecovery = $_SESSION['fresh_recovery_codes'] ?? null;
        View::render('profile/security', [
            'pageTitle' => 'Безопасность',
            'user' => $user,
            'score' => User::securityScore($user),
            'totpEnabled' => $user['totp_enabled_at'] !== null,
            'hasPin' => \App\Core\QuickUnlock::hasPin($user),
            'pendingSecret' => $pendingSecret,
            'totpUri' => $pendingSecret
                ? Totp::uri($pendingSecret, $user['email'], Setting::appName())
                : null,
            'freshRecovery' => $freshRecovery,
            'recoveryLeft' => (int)DB::val('SELECT COUNT(*) FROM {recovery_codes} WHERE user_id = ? AND used_at IS NULL', [(int)$user['id']]),
            'passkeys' => \App\Models\Passkey::forUser((int)$user['id']),
            'socialProviders' => \App\Social\Provider::enabled(),
            'socialLinked' => DB::all('SELECT * FROM {linked_identities} WHERE user_id = ? ORDER BY id',
                [(int)$user['id']]),
        ]);
    }

    public function changePassword(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation();
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        /* Аккаунт от соцвхода или magic-ссылки пароля не имеет. Раньше форма
           всё равно требовала «текущий», которого не существует, — установить
           пароль было невозможно в принципе. Теперь: пусто — значит человек
           ставит первый пароль, и спрашивать нечего (он уже в своей сессии). */
        $hasPassword = !empty($user['password_hash']);
        if ($hasPassword && !Auth::passwordOk($user, $current)) {
            Flash::error('Текущий пароль не подошёл.');
            redirect('/profile/security');
        }
        $pwError = \App\Core\PasswordPolicy::check($new);
        if ($pwError !== null) {
            Flash::error($pwError);
            redirect('/profile/security');
        }
        User::update((int)$user['id'], [
            'password_hash' => password_hash($new, PASSWORD_ARGON2ID),
            'password_legacy' => 0,
        ]);
        // Остальные сессии — под нож, текущая живёт
        $currentId = (int)(Auth::sessionRecord()['id'] ?? 0);
        foreach (SessionModel::revokeAllForUser((int)$user['id'], $currentId) as $sid) {
            \App\Oidc\EventDispatcher::sessionLogout($sid);
        }
        RefreshToken::revokeAllForUser((int)$user['id']);
        Audit::log($hasPassword ? 'password.change' : 'password.set', ['on' => (int)$user['id']]);
        \App\Core\Notify::send($user, 'password.change',
            $hasPassword ? t('Пароль изменён — :app', ['app' => Setting::appName()]) : t('Установлен пароль — :app', ['app' => Setting::appName()]),
            $hasPassword
                ? t('Пароль вашего аккаунта только что изменили, остальные сессии завершены. Если это были не вы — немедленно восстановите доступ.')
                : t('Для вашего аккаунта только что установили пароль. Если это были не вы — немедленно восстановите доступ.'),
            ['action_url' => base_url('/recover'), 'action_label' => t('Это не я — восстановить доступ')]);
        Flash::success($hasPassword
            ? t('Пароль обновлён, остальные сессии завершены.')
            : t('Пароль установлен — теперь можно входить и по нему.'));
        redirect('/profile/security');
    }

    /* ---- 2FA: QR → подтверждение кодом → 10 recovery-кодов (§5.1) ---- */

    public function start2fa(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation();
        if ($user['totp_enabled_at'] !== null) redirect('/profile/security');
        $_SESSION['totp_setup_secret'] = Totp::generateSecret();
        redirect('/profile/security#2fa');
    }

    public function confirm2fa(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation();
        $secret = (string)($_SESSION['totp_setup_secret'] ?? '');
        $code = (string)($_POST['code'] ?? '');
        if ($secret === '') redirect('/profile/security');
        if (!Totp::verify($secret, $code)) {
            Flash::error('Код не совпал. Проверьте время на телефоне и попробуйте ещё раз.');
            redirect('/profile/security#2fa');
        }
        unset($_SESSION['totp_setup_secret']);
        User::update((int)$user['id'], [
            'totp_secret' => Crypto::encrypt($secret),
            'totp_enabled_at' => now(),
        ]);
        $codes = $this->issueRecoveryCodes((int)$user['id']);
        $_SESSION['fresh_recovery_codes'] = $codes; // показ один раз
        Audit::log('2fa.enabled', ['on' => (int)$user['id']]);
        /* Тумблер «Включена или выключена 2FA» стоял в уведомлениях с v2.2 и
           не срабатывал: письма не было ни при включении, ни при выключении.
           А это ровно то событие, о котором владелец аккаунта обязан узнать —
           чужими руками включённая 2FA запирает его снаружи. */
        \App\Core\Notify::send($user, '2fa.change',
            t('Включена двухфакторная защита — :app', ['app' => \App\Core\Setting::appName()]),
            t('В вашем аккаунте включили вход по одноразовому коду. Если это были не вы — смените пароль немедленно.'));
        Flash::success('Двухфакторная защита включена. Сохраните recovery-коды!');
        redirect('/profile/security#recovery');
    }

    /**
     * Задать или сменить PIN.
     *
     * Пароль здесь не переспрашиваем: сессия уже открыта и не заперта — то
     * есть человек либо только что вошёл, либо только что отпёрся. Требовать
     * пароль ради четырёх цифр значит вернуть ровно то, от чего PIN избавляет.
     */
    public function setPin(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation();
        $pin = trim((string)($_POST['pin'] ?? ''));
        $pin2 = trim((string)($_POST['pin2'] ?? ''));
        $err = \App\Core\QuickUnlock::checkPinFormat($pin);
        if ($err !== null) {
            Flash::error($err);
            redirect('/profile/security#lock');
        }
        if ($pin !== $pin2) {
            Flash::error(t('Коды не совпали.'));
            redirect('/profile/security#lock');
        }
        \App\Core\QuickUnlock::setPin((int)$user['id'], $pin);
        Flash::success(t('PIN сохранён.'));
        redirect('/profile/security#lock');
    }

    public function clearPin(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation();
        \App\Core\QuickUnlock::clearPin((int)$user['id']);
        Flash::info(t('PIN убран.'));
        redirect('/profile/security#lock');
    }

    public function disable2fa(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation();
        $password = (string)($_POST['password'] ?? '');
        /* Без пароля (соцвход, ссылка из письма) — текущий код из приложения
           (v5.5). Раньше форма требовала пароль, которого нет, и выключить 2FA
           такой человек не мог вовсе. */
        if (empty($user['password_hash'])) {
            RateLimiter::guard('reauth:' . (int)$user['id'], 10, 3600);
            $secret = Crypto::decrypt((string)$user['totp_secret']);
            $step = Totp::matchStep($secret, preg_replace('~\D~', '', $password));
            $ok = $step !== null && User::claimTotpStep((int)$user['id'], $step);
        } else {
            $ok = Auth::passwordOk($user, $password);
        }
        if (!$ok) {
            Flash::error(empty($user['password_hash'])
                ? t('Код не подошёл — 2FA осталась включённой.')
                : t('Пароль не подошёл — 2FA осталась включённой.'));
            redirect('/profile/security');
        }
        User::update((int)$user['id'], ['totp_secret' => null, 'totp_enabled_at' => null]);
        DB::run('DELETE FROM {recovery_codes} WHERE user_id = ?', [(int)$user['id']]);
        unset($_SESSION['fresh_recovery_codes']);
        Audit::log('2fa.disabled', ['on' => (int)$user['id']]);
        \App\Core\Notify::send($user, '2fa.change',
            t('Выключена двухфакторная защита — :app', ['app' => \App\Core\Setting::appName()]),
            t('В вашем аккаунте выключили вход по одноразовому коду. Если это были не вы — включите её снова и смените пароль.'));
        Flash::info('2FA выключена. Для входа в админку её придётся включить снова.');
        redirect('/profile/security');
    }

    /** Скачивание recovery-кодов .txt (доступно, пока они в сессии). */
    public function downloadRecovery(): never
    {
        $codes = $_SESSION['fresh_recovery_codes'] ?? null;
        if (!$codes) redirect('/profile/security');
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="recovery-codes.txt"');
        echo t(':app — коды восстановления 2FA', ['app' => Setting::appName()]) . "\n";
        echo t('Каждый код — одноразовый. Храните как пароль.') . "\n\n";
        foreach ($codes as $c) echo $c . "\n";
        exit;
    }

    private function issueRecoveryCodes(int $userId): array
    {
        DB::run('DELETE FROM {recovery_codes} WHERE user_id = ?', [$userId]);
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(6)));
            $code = implode('-', str_split($raw, 4));
            $codes[] = $code;
            DB::insert('recovery_codes', [
                'user_id' => $userId,
                'code_hash' => hash('sha256', str_replace('-', '', $code)),
            ]);
        }
        return $codes;
    }

    private function denyOnImpersonation(): void
    {
        if (Auth::impersonating()) {
            Flash::error('В режиме имперсонизации изменения запрещены.');
            redirect('/profile/security');
        }
    }
}
