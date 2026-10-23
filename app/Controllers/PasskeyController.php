<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Flash;
use App\Core\LoginFlow;
use App\Core\RateLimiter;
use App\Core\WebAuthn;
use App\Models\Passkey;
use App\Models\User;

/**
 * Passkeys: добавление ключа из профиля и вход по ключу (в т.ч. QR с телефона).
 * Challenge живёт в PHP-сессии 5 минут; все ответы — JSON для fetch.
 */
final class PasskeyController
{
    private const CHALLENGE_TTL = 300;

    /* ---- Регистрация ключа (из /profile/security) ---- */

    public function registerOptions(): never
    {
        $user = Auth::user();
        if (Auth::impersonating()) json_response(['error' => t('В режиме имперсонизации нельзя добавлять ключи')], 403);
        $challenge = random_bytes(32);
        $_SESSION['webauthn_reg'] = ['c' => Crypto::b64url($challenge), 't' => time()];
        json_response(WebAuthn::registrationOptions(
            $user, Passkey::credentialIdsForUser((int)$user['id']), $challenge));
    }

    public function register(): never
    {
        $user = Auth::user();
        if (Auth::impersonating()) json_response(['error' => 'forbidden'], 403);
        $stored = $_SESSION['webauthn_reg'] ?? null;
        unset($_SESSION['webauthn_reg']);
        if (!$stored || $stored['t'] < time() - self::CHALLENGE_TTL) {
            json_response(['error' => t('Время вышло — попробуйте ещё раз')], 400);
        }
        try {
            $cred = WebAuthn::verifyRegistration(
                (string)($_POST['clientDataJSON'] ?? ''),
                (string)($_POST['attestationObject'] ?? ''),
                (string)Crypto::b64urlDecode($stored['c'])
            );
        } catch (\Throwable $e) {
            json_response(['error' => t('Ключ не прошёл проверку: :why', ['why' => $e->getMessage()])], 400);
        }
        if (Passkey::findByCredentialId($cred['credential_id'])) {
            json_response(['error' => t('Этот ключ уже зарегистрирован')], 400);
        }
        $label = trim((string)($_POST['label'] ?? '')) ?: t('Ключ от :date', ['date' => fmt_dt(now(), 'd.m.Y')]);
        Passkey::create((int)$user['id'], $cred, $label, trim((string)($_POST['transports'] ?? '')) ?: null);
        Audit::log('passkey.added', ['on' => (int)$user['id'], 'meta' => ['label' => $label]]);
        \App\Core\Notify::send($user, 'passkey.change', t('Добавлен passkey — :app', ['app' => \App\Core\Setting::appName()]),
            t('К вашему аккаунту добавлен новый ключ входа «:label».', ['label' => $label]));
        json_response(['ok' => true]);
    }

    public function delete(): never
    {
        $user = Auth::user();
        if (Auth::impersonating()) json_response(['error' => 'forbidden'], 403);
        if (Passkey::delete((int)($_POST['id'] ?? 0), (int)$user['id'])) {
            Audit::log('passkey.removed', ['on' => (int)$user['id']]);
            Flash::success('Passkey удалён.');
        }
        redirect('/profile/security');
    }

    /* ---- Вход по ключу (страница /login) ---- */

    public function loginOptions(): never
    {
        RateLimiter::guard('passkey:ip:' . RateLimiter::ipKey(), 20, 60);
        $challenge = random_bytes(32);
        $_SESSION['webauthn_auth'] = ['c' => Crypto::b64url($challenge), 't' => time()];
        json_response(WebAuthn::authOptions($challenge));
    }

    public function login(): never
    {
        RateLimiter::guard('passkey:ip:' . RateLimiter::ipKey(), 20, 60);
        $stored = $_SESSION['webauthn_auth'] ?? null;
        unset($_SESSION['webauthn_auth']);
        if (!$stored || $stored['t'] < time() - self::CHALLENGE_TTL) {
            json_response(['error' => t('Время вышло — попробуйте ещё раз')], 400);
        }
        $credentialId = (string)($_POST['id'] ?? '');
        $passkey = $credentialId !== '' ? Passkey::findByCredentialId($credentialId) : null;
        if (!$passkey) {
            Audit::log('login.fail', ['actor' => null, 'meta' => ['reason' => 'passkey_unknown']]);
            json_response(['error' => t('Ключ не найден. Возможно, он был удалён из аккаунта.')], 400);
        }
        try {
            $newCount = WebAuthn::verifyAssertion(
                (string)($_POST['clientDataJSON'] ?? ''),
                (string)($_POST['authenticatorData'] ?? ''),
                (string)($_POST['signature'] ?? ''),
                (string)Crypto::b64urlDecode($stored['c']),
                (string)$passkey['public_key'],
                (int)$passkey['sign_count'],
                $flags
            );
        } catch (\Throwable $e) {
            Audit::log('login.fail', ['actor' => null, 'on' => (int)$passkey['user_id'],
                'meta' => ['reason' => 'passkey', 'detail' => mb_substr($e->getMessage(), 0, 120)]]);
            json_response(['error' => t('Ключ не прошёл проверку: :why', ['why' => $e->getMessage()])], 400);
        }
        $user = User::find((int)$passkey['user_id']);
        if (!\App\Models\User::canSignIn($user)) {
            json_response(['error' => t('Аккаунт недоступен')], 403);
        }
        if (\App\Core\EmailGate::holds($user)) {
            json_response(['error' => t('Сначала подтвердите почту — письмо у вас в ящике.')], 403);
        }
        Passkey::recordUse((int)$passkey['id'], $newCount);

        $remember = !empty($_POST['remember']);
        /* Ключ с проверкой пользователя (PIN, биометрия) — два фактора сразу,
           код 2FA не переспрашиваем. Без неё (v5.5) ключ — только «владею», и
           при включённой 2FA он её не заменяет: раньше флаг UV не смотрели. */
        if ($user['totp_enabled_at'] !== null && !((int)$flags & 0x04)) {
            json_response(['ok' => true, 'redirect' => LoginFlow::stage2fa($user, $remember)]);
        }
        json_response(['ok' => true, 'redirect' => LoginFlow::finish($user, $remember, 'login.passkey')]);
    }
}
