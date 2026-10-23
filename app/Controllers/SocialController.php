<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\ErrorPage;
use App\Core\Flash;
use App\Core\LoginFlow;
use App\Core\RateLimiter;
use App\Core\View;
use App\Models\Role;
use App\Models\User;
use App\Social\Provider;
use App\Social\TelegramProvider;

/**
 * Вход и привязка через внешние личности (§ бэклог Phase 2 архитектуры).
 * Таблица sso_linked_identities ждала этого с миграции 001.
 */
final class SocialController
{
    public function start(string $provider): never
    {
        $p = $this->provider($provider);
        RateLimiter::guard('social:ip:' . RateLimiter::ipKey(), 20, 3600);

        if ($p instanceof TelegramProvider && $p->botId() === '') {
            /* Номера бота не узнать (токен не того вида) — страница с виджетом,
               как до 4.87; обычно же Telegram открывается переходом, ниже. */
            View::render('auth/telegram', [
                'pageTitle' => 'Вход через Telegram',
                'widget' => $p->widgetHtml(),
            ], 'layouts/auth');
        }

        $state = Crypto::randomToken(16);
        $_SESSION['social_state'] = ['v' => $state, 'p' => $p->id(), 't' => time()];
        redirect($p->authUrl($state));
    }

    /**
     * Возврат из Telegram (v4.87): данные входа — во фрагменте адреса.
     *
     * До сервера фрагмент не доходит, поэтому здесь страница: app.js
     * переносит данные в запрос обработчика, а подпись и state проверяет уже
     * он. На сервере эта страница ничего не принимает и не пишет.
     */
    public function telegramReturn(): never
    {
        View::render('auth/telegram_return', ['pageTitle' => t('Вход через Telegram')], 'layouts/auth');
    }

    public function callback(string $provider): never
    {
        $p = $this->provider($provider);
        RateLimiter::guard('social:ip:' . RateLimiter::ipKey(), 20, 3600);

        /* state сверяем у всех, кто начал вход переходом, — с 4.87 и у
           Telegram: подпись доказывает, что данные от Telegram, а state — что
           вход начал этот браузер (иначе чужую подписанную ссылку можно
           подсунуть и войти человеком в чужой аккаунт). Страница с виджетом
           state не передаёт, и там её подпись — единственная проверка. */
        if (!$p instanceof TelegramProvider || $p->botId() !== '') {
            $stored = $_SESSION['social_state'] ?? null;
            unset($_SESSION['social_state']);
            $got = (string)($_GET['state'] ?? '');
            if (!$stored || $stored['p'] !== $p->id() || $stored['t'] < time() - 900
                || !hash_equals((string)$stored['v'], $got)) {
                $this->fail('Сессия входа устарела. Начните заново.');
            }
        }

        try {
            $profile = $p->handleCallback($_GET);
        } catch (\Throwable $e) {
            Audit::log('social.fail', ['actor' => null, 'meta' => ['provider' => $p->id(),
                'reason' => mb_substr($e->getMessage(), 0, 120)]]);
            $this->fail(t('Не удалось войти через :provider: :why', ['provider' => $p->title(), 'why' => $e->getMessage()]));
        }

        $uid = (string)$profile['uid'];
        $linked = DB::row('SELECT * FROM {linked_identities} WHERE provider = ? AND provider_uid = ?',
            [$p->id(), $uid]);

        /* 1. Пользователь уже вошёл — это привязка к текущему аккаунту */
        if (Auth::check()) {
            $me = Auth::user();
            if ($linked && (int)$linked['user_id'] !== (int)$me['id']) {
                /* Соцаккаунт привязан к другой учётке — почти всегда это дубль,
                   который соцвход завёл раньше. До v3.6 здесь был тупик
                   «уже привязан к другому пользователю» без выхода. Теперь —
                   предложение объединить. Пройденный OAuth уже доказал
                   владение соцаккаунтом; если у дубля есть пароль, экран
                   слияния спросит ещё и его. */
                $_SESSION['pending_merge'] = [
                    'dup_user_id' => (int)$linked['user_id'],
                    'provider' => $p->id(),
                    'provider_title' => $p->title(),
                    't' => time(),
                ];
                redirect('/profile/merge');
            }
            if (!$linked) {
                $this->link((int)$me['id'], $p->id(), $uid, $profile);
                $this->absorbProfile((int)$me['id'], $p->id(), $profile);
                Flash::success(t(':provider привязан к аккаунту.', ['provider' => $p->title()]));
            }
            redirect('/profile/security#social');
        }

        /* 2. Известная личность — просто входим */
        if ($linked) {
            $user = User::find((int)$linked['user_id']);
            if (!\App\Models\User::canSignIn($user)) {
                /* Дубль мог быть слит: тогда живой аккаунт — тот, в который влили */
                if ($user && !empty($user['merged_into'])) {
                    $target = User::find((int)$user['merged_into']);
                    if (\App\Models\User::canSignIn($target)) {
                        DB::update('linked_identities', ['user_id' => (int)$target['id']],
                            'id = ?', [(int)$linked['id']]);
                        $this->absorbProfile((int)$target['id'], $p->id(), $profile);
                        LoginFlow::afterFirstFactor($target, false, 'login.social');
                    }
                }
                $this->fail(t('Аккаунт недоступен. Обратитесь к администратору.'));
            }
            DB::update('linked_identities', ['meta' => json_encode($profile, JSON_UNESCAPED_UNICODE)],
                'id = ?', [(int)$linked['id']]);
            $this->absorbProfile((int)$user['id'], $p->id(), $profile);
            LoginFlow::afterFirstFactor($user, false, 'login.social');
        }

        /* 3. Новый для нас человек: связываем по ПОДТВЕРЖДЁННОЙ почте или заводим
           аккаунт. Ищем и по дополнительным адресам — это главное лекарство от
           дублей: gmail, оставленный Google-входом, найдёт аккаунт при входе
           через другого провайдера с тем же адресом. */
        $email = $profile['email'] ? mb_strtolower((string)$profile['email']) : null;
        if ($email !== null) {
            $existing = \App\Models\UserEmail::findOwner($email);
            if ($existing && !\App\Models\User::canSignIn($existing)) $existing = null;
            /* v4.98: только к аккаунту, чья почта подтверждена. Подтверждение
               по умолчанию выключено, и аккаунт с чужим адресом и своим
               паролем мог завести кто угодно — хозяин адреса входил сюда через
               провайдера и работал в аккаунте, от которого у того есть пароль.
               Хозяину ящика путь открыт: восстановление по почте. */
            if ($existing && $existing['email_verified_at'] === null) {
                Audit::log('social.link_refused', ['on' => (int)$existing['id'],
                    'meta' => ['provider' => $p->id(), 'why' => 'email_unverified']]);
                $this->fail(t('Аккаунт с этой почтой есть, но адрес в нём не подтверждён. Восстановите доступ по почте — после этого вход через :provider заработает.',
                    ['provider' => $p->title()]));
            }
            if ($existing) {
                // Почту подтвердил сам провайдер, и в аккаунте она подтверждена — связываем без пароля
                $this->link((int)$existing['id'], $p->id(), $uid, $profile);
                Audit::log('social.linked', ['actor' => (int)$existing['id'], 'on' => (int)$existing['id'],
                    'meta' => ['provider' => $p->id(), 'via' => 'email_match']]);
                $this->absorbProfile((int)$existing['id'], $p->id(), $profile);
                LoginFlow::afterFirstFactor(User::find((int)$existing['id']), false, 'login.social');
            }
        }

        /* Совсем новый человек. Аккаунт здесь НЕ создаётся: до v3.9 соцвход
           заводил учётку молча, и человек узнавал о ней постфактум. Между
           «провайдер подтвердил личность» и «аккаунт создан» должен стоять он
           сам — тем более что именно здесь даются согласия с документами. */
        $_SESSION['pending_signup'] = [
            'provider' => $p->id(),
            'provider_title' => $p->title(),
            'uid' => $uid,
            'profile' => $profile,
            't' => time(),
        ];
        redirect('/social/signup');
    }

    /** GET /social/signup — «Создать аккаунт?» после подтверждения провайдером. */
    public function signup(): never
    {
        $pending = $this->pendingSignup();
        $profile = $pending['profile'];

        View::render('auth/signup', [
            'pageTitle' => t('Создать аккаунт?'),
            'providerTitle' => $pending['provider_title'],
            /* Список конкретный, а не «данные профиля»: человек должен видеть,
               что именно приедет, а не соглашаться с формулировкой. */
            'willGet' => array_values(array_filter([
                $profile['email'] ?? null,
                trim(((string)($profile['first_name'] ?? '')) . ' ' . ((string)($profile['last_name'] ?? '')))
                    ?: ($profile['name'] ?? null),
                !empty($profile['phone']) ? t('номер телефона') : null,
                !empty($profile['avatar']) ? t('фотография профиля') : null,
            ])),
            'docs' => \App\Core\Legal::requiringConsent(),
            /* Похоже, аккаунт уже есть (v4.88): совпал телефон или фамилия,
               имя и дата рождения. Сами не связываем — предлагаем войти. */
            'dup' => \App\Core\DuplicateHint::forProfile($profile),
        ], 'layouts/auth');
    }

    /**
     * GET /social/signup/link — «Это я, войти и привязать» (v4.88).
     *
     * Незавершённая регистрация становится отложенной связкой: после входа в
     * свой аккаунт любым способом сервис привяжется к нему (PendingLink).
     */
    public function signupLink(): never
    {
        $pending = $this->pendingSignup();
        unset($_SESSION['pending_signup']);
        \App\Core\PendingLink::stash($pending);
        redirect('/login');
    }

    /** POST /social/signup — согласие получено, заводим аккаунт. */
    public function signupConfirm(): never
    {
        $pending = $this->pendingSignup();
        $p = $this->provider($pending['provider']);
        $profile = $pending['profile'];
        $uid = (string)$pending['uid'];

        /* Галочки не предустановлены и не подразумеваются: без явного согласия
           с каждым включённым документом аккаунт не создаётся. */
        $docs = \App\Core\Legal::requiringConsent();
        $accepted = (array)($_POST['accept'] ?? []);
        foreach (array_keys($docs) as $key) {
            if (!in_array($key, $accepted, true)) {
                Flash::error(t('Чтобы создать аккаунт, нужно принять все документы.'));
                redirect('/social/signup');
            }
        }

        $email = $profile['email'] ? mb_strtolower((string)$profile['email']) : null;
        $uidEmail = $email ?? ('social-' . $p->id() . '-' . substr(hash('sha256', $uid), 0, 16) . '@no-email.invalid');
        /* Имя раскладываем по полям, а не сваливаем в first_name целиком:
           до v3.8 «Артём Петров» полностью уезжал в имя, фамилия оставалась
           пустой, и это потом вылезало в удостоверении и в MRZ. */
        $newId = User::create([
            'locale' => \App\Core\Lang::current(),
            'email' => $uidEmail,
            'first_name' => $profile['first_name'] ?? null,
            'last_name' => $profile['last_name'] ?? null,
            /* Ни имени, ни фамилии по отдельности — пусть будет хотя бы
               подпись, иначе в списках окажется голый email. */
            'display_name' => (empty($profile['first_name']) && empty($profile['last_name']))
                ? ($profile['name'] ?: $p->title()) : null,
            'birthdate' => $profile['birthdate'] ?? null,
            'email_verified_at' => $email !== null ? now() : null,
            'status' => $email !== null ? 'active' : 'pending',
        ]);
        \App\Core\Legal::record($newId, array_keys($docs));
        \App\Core\Signup::grantDefaultRole($newId);
        $this->link($newId, $p->id(), $uid, $profile);
        unset($_SESSION['pending_signup']);

        Audit::log('register', ['actor' => $newId, 'on' => $newId, 'meta' => ['via' => $p->id()]]);
        \App\Core\WebhookDispatcher::emit('user.created', [
            'sub' => User::find($newId)['public_id'], 'email' => $email, 'via' => $p->id(),
        ]);
        if ($email === null) {
            Flash::info('Аккаунт создан. Добавьте почту — без неё не получится восстановить доступ.');
        }
        $this->absorbProfile($newId, $p->id(), $profile);
        LoginFlow::afterFirstFactor(User::find($newId), false, 'login.social');
    }

    /** GET /social/signup/cancel — передумал: не остаётся ни аккаунта, ни связки. */
    public function signupCancel(): never
    {
        unset($_SESSION['pending_signup']);
        \App\Core\PendingLink::forget();
        Flash::info(t('Аккаунт не создан.'));
        redirect('/login');
    }

    /**
     * Незавершённая регистрация из сессии. Кладёт её только callback после
     * пройденного OAuth, поэтому на экран не попасть с улицы; TTL как у
     * слияния в v3.6 — десять минут, дальше начинать заново.
     */
    private function pendingSignup(): array
    {
        $pending = $_SESSION['pending_signup'] ?? null;
        if (!is_array($pending) || (int)($pending['t'] ?? 0) < time() - 600) {
            unset($_SESSION['pending_signup']);
            Flash::error(t('Сессия входа устарела. Начните заново.'));
            redirect('/login');
        }
        return $pending;
    }

    /**
     * Что провайдер знает о человеке — забираем, ничего не перетирая (v3.6):
     * телефон и пол дозаполняются только в пустые поля, а email провайдера,
     * отличный от основного, копится в дополнительных адресах. Именно эти
     * адреса потом спасают от дублей при входе через другого провайдера.
     */
    /**
     * Забрать из ответа провайдера то, чего в профиле ещё нет.
     * Логика живёт в App\Core\ProfileAbsorb — там её проверяет тест, а здесь
     * она проверялась бы только живым соцвходом, то есть никогда.
     */
    private function absorbProfile(int $userId, string $provider, array $profile): void
    {
        \App\Core\ProfileAbsorb::apply($userId, $provider, $profile);
    }

    /** Отвязка. Нельзя снять последний способ входа — иначе человек запрёт себя снаружи. */
    public function unlink(): never
    {
        $user = Auth::user();
        if (Auth::impersonating()) ErrorPage::render(403);
        $id = (int)($_POST['id'] ?? 0);
        $row = DB::row('SELECT * FROM {linked_identities} WHERE id = ? AND user_id = ?', [$id, (int)$user['id']]);
        if (!$row) redirect('/profile/security#social');

        $others = (int)DB::val('SELECT COUNT(*) FROM {linked_identities} WHERE user_id = ? AND id != ?',
            [(int)$user['id'], $id]);
        $hasPassword = !empty($user['password_hash']);
        $hasPasskey = \App\Models\Passkey::countForUser((int)$user['id']) > 0;
        if ($others === 0 && !$hasPassword && !$hasPasskey) {
            Flash::error('Это единственный способ входа. Сначала задайте пароль или добавьте passkey.');
            redirect('/profile/security#social');
        }

        DB::run('DELETE FROM {linked_identities} WHERE id = ?', [$id]);
        Audit::log('social.unlinked', ['on' => (int)$user['id'], 'meta' => ['provider' => $row['provider']]]);
        Flash::success('Привязка удалена.');
        redirect('/profile/security#social');
    }

    private function link(int $userId, string $provider, string $uid, array $profile): void
    {
        DB::insert('linked_identities', [
            'user_id' => $userId,
            'provider' => $provider,
            'provider_uid' => $uid,
            'meta' => json_encode($profile, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    private function provider(string $id): Provider
    {
        $p = Provider::get($id);
        if (!$p || !$p->isEnabled()) {
            ErrorPage::render(404, ['title' => 'Провайдер недоступен',
                'message' => 'Вход через этот сервис выключен администратором.']);
        }
        return $p;
    }

    private function fail(string $message): never
    {
        Flash::error($message);
        redirect(Auth::check() ? '/profile/security#social' : '/login');
    }
}
