<?php
/**
 * English dictionary. Missing keys fall back to Russian (see App\Core\Lang).
 */

declare(strict_types=1);

/*
 * Two key conventions live side by side, on purpose:
 *
 *  · dot-keys below (`login.submit`) — the user-facing area, translated first;
 *  · Russian source strings as keys in en-admin.php — the admin area.
 *
 * The admin panel has ~320 strings, many of them whole sentences. Inventing an
 * identifier for each would add a lookup step to every template line and give
 * nothing back: `Lang::t()` already falls back to the key, so a missing
 * translation shows the Russian original instead of a bare `admin.x.y`.
 * See the header of en-admin.php for the full reasoning.
 */
return array_merge(require __DIR__ . '/en-admin.php', [
    /* --- Common --- */
    'common.save' => 'Save changes',
    'common.cancel' => 'Cancel',
    'common.delete' => 'Delete',
    'common.back' => 'Back',
    'common.next' => 'Next',
    'common.copy' => 'Copy',
    'common.copied' => 'Copied',
    'common.show' => 'show',
    'common.hide' => 'hide',
    'common.email' => 'Email',
    'common.password' => 'Password',
    'common.login_field' => 'Username',
    'common.optional' => 'optional',
    'common.theme' => 'Theme: auto',
    'common.language' => 'Language',
    'common.never' => '—',
    'common.footer_tagline' => 'one account for the whole ecosystem',

    /* --- Sign in --- */
    'login.title' => 'Sign in',
    'login.identity' => 'Username or email',
    'login.remember' => 'Remember me',
    'login.forgot' => 'Forgot password?',
    'login.submit' => 'Sign in',
    'login.or' => 'or',
    'login.passkey' => 'Sign in with a passkey',
    'login.magic_label' => 'Sign in with an email link',
    'login.magic_send' => 'Send',
    'login.no_account' => 'No account yet?',
    'login.create' => 'Create one',
    'login.context' => 'You are signing in to',
    'login.failed' => 'Wrong username or password.',
    'login.locked' => 'Too many failed attempts. Wait 15 minutes or reset your password.',
    'login.magic_sent' => 'If that email is registered, a sign-in link is already on its way.',

    /* --- Second factor --- */
    '2fa.title' => 'Confirm sign-in',
    '2fa.hint' => 'Enter the 6-digit code from your authenticator app.',
    '2fa.submit' => 'Confirm',
    '2fa.use_recovery' => 'Use a recovery code',
    '2fa.recovery_hint' => 'Enter one of your one-time recovery codes.',
    '2fa.back_to_code' => 'Back to the app code',
    '2fa.wrong' => 'That code did not work. Try again.',
    '2fa.too_many' => 'Five wrong codes. Please sign in again.',

    /* --- Sign up --- */
    'register.title' => 'Create account',
    'register.first_name' => 'First name',
    'register.last_name' => 'Last name',
    'register.submit' => 'Create account',
    'register.agreement' => 'By creating an account you accept the personal data policy.',
    'register.have_account' => 'Already have an account?',
    'register.sign_in' => 'Sign in',
    'register.context' => 'An account to sign in to',
    'register.err_name' => 'What should we call you?',
    'register.err_email' => 'That address looks like a typo.',
    'register.created' => 'Account created. Check your inbox to confirm the email.',

    /* --- Recovery --- */
    'recover.title' => 'Recover access',
    'recover.hint' => 'Enter your email — we will send a password reset link.',
    'recover.submit' => 'Send the link',
    'recover.back' => 'Back to sign-in',
    'recover.sent' => 'If the email exists, the message has been sent.',
    'reset.title' => 'New password',
    'reset.new_password' => 'New password',
    'reset.submit' => 'Save password',
    'reset.sessions_note' => 'All active sessions will be terminated after saving.',
    'reset.done' => 'Password updated. Sign in with the new one.',
    'reset.expired' => 'This link has expired or was already used. Request a new one.',

    /* --- Sign out --- */
    'logout.title' => 'Sign out',
    'logout.question' => 'End this session only, or sign out on every device and service?',
    'logout.here' => 'Sign out here only',
    'logout.everywhere' => 'Sign out everywhere',
    'logout.done' => 'Signed out.',
    'goodbye.title' => 'Account scheduled for deletion',
    'goodbye.text' => 'Everything will be erased in 14 days. Changed your mind? The email we sent has a cancel button.',

    /* --- Profile --- */
    'profile.title' => 'Profile',
    'profile.edit' => 'Edit',
    'profile.id_card' => 'Identity card',
    'profile.name' => 'Name',
    'profile.birthdate' => 'Date of birth',
    'profile.member_since' => 'Member since',
    'profile.verified' => 'verified',
    'profile.not_verified' => 'not verified',
    'profile.security' => 'Security',
    'profile.services' => 'Services',
    'profile.devices' => 'Devices',
    'profile.last_logins' => 'Recent sign-ins',
    'profile.settings' => 'Settings',
    'profile.saved' => 'Changes saved.',

    /* --- Consent and access --- */
    'consent.request_from' => 'Request from',
    'consent.will_receive' => 'The service will receive:',
    'consent.allow' => 'Allow',
    'consent.note' => 'Your password is never shared — only the data listed above.',
    'gate.pending_title' => 'Request under review',
    'gate.blocked_title' => 'Access closed',
    'gate.invite_title' => 'Invitation only',

    /* --- Invitation --- */
    'invite.title' => 'Invitation to',
    'invite.accept' => 'Accept invitation',
    'invite.login_and_accept' => 'Sign in and accept',
    'invite.signed_as' => 'You are signed in as',
    'invite.no_account' => 'No account yet?',
    'invite.create_fast' => 'Create one in 30 seconds',

    /* --- Errors --- */
    'error.400' => 'Bad request',
    'error.403' => 'Access denied',
    'error.404' => 'Page not found',
    'error.419' => 'Form expired',
    'error.429' => 'Too many requests',
    'error.500' => 'Something broke',
    'error.home' => 'Go home',

    /* --- Emails --- */
    'email.hello' => 'Hello',
    'email.auto_note' => 'This message was sent automatically — no need to reply.',
    'email.verify_subject' => 'Confirm your email',
    'email.verify_action' => 'Confirm email',
    'email.reset_subject' => 'Password reset',
    'email.reset_action' => 'Set a new password',
    'email.magic_action' => 'Sign in',
]);
