<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\ErrorPage;
use App\Core\Flash;
use App\Core\View;
use App\Models\Access;
use App\Models\Client;
use App\Models\RefreshToken;
use App\Models\SessionModel;
use App\Models\User;
use App\Models\UserPhoto;
use App\Oidc\EventDispatcher;

/** Профиль: карточка, редактирование, аватары, сессии, сервисы (§5.1). */
final class ProfileController
{
    public function index(): never
    {
        $user = Auth::user();
        $sessions = array_slice(SessionModel::activeForUser((int)$user['id']), 0, 3);
        $services = Access::forUser((int)$user['id']);
        $logins = DB::all(
            'SELECT created_at, ip, user_agent, client_id FROM {audit_log}
             WHERE on_user_id = ? AND ' . \App\Core\Stats::loginIn() . ' ORDER BY id DESC LIMIT 5',
            array_merge([(int)$user['id']], \App\Core\Stats::LOGIN_EVENTS));
        View::render('profile/index', [
            'pageTitle' => 'Профиль',
            'user' => $user,
            'score' => User::securityScore($user),
            'sessions' => $sessions,
            'currentSessionId' => (int)(Auth::sessionRecord()['id'] ?? 0),
            'services' => $services,
            'logins' => $logins,
        ]);
    }

    /**
     * MRZ-строка ID-карты: машиночитаемая зона паспорта (§10.1).
     *
     * До v3.2 здесь был только UUID — то есть ровно то место, где у настоящего
     * документа стоит транслитерированное имя, пустовало. Теперь имя есть, а
     * транслитерация идёт по ИКАО Doc 9303, как в паспорте: Щербаков →
     * SHCHERBAKOV, Юрий → IURII.
     */
    public static function mrz(array $user): string
    {
        /* Латинское написание берём то, что человек указал сам. Автоматика не
           может знать, что в документах стоит ARTYOM PETROV, а не ARTEM PETROV:
           правил транслитерации несколько, и у каждого своя выдача. */
        [$last, $first] = self::latinName($user);
        $line = \App\Core\Translit::mrz($last, $first, \App\Core\Setting::appName());
        $sub = strtoupper(str_replace('-', '', (string)$user['public_id']));
        return $line . "\n" . 'SUB<' . implode('<', str_split($sub, 8)) . '<<<';
    }

    /**
     * Латиница для документа: сначала то, что человек указал сам, потом
     * автоматика как запасной вариант.
     *
     * @return array{string, string} фамилия, имя
     */
    public static function latinName(array $user): array
    {
        $last = trim((string)($user['latin_last_name'] ?? ''));
        $first = trim((string)($user['latin_first_name'] ?? ''));
        return [
            $last !== '' ? $last : (string)($user['last_name'] ?? ''),
            $first !== '' ? $first : (string)($user['first_name'] ?? ''),
        ];
    }

    /** Чистка поля латинского имени: A–Z, пробел, дефис, апостроф. */
    private static function latinInput(mixed $raw): ?string
    {
        $s = trim((string)$raw);
        if ($s === '') return null;
        $s = (string)preg_replace('~[^A-Za-z \'-]~u', '', $s);
        $s = trim((string)preg_replace('~\s+~', ' ', $s));
        return $s !== '' ? mb_substr($s, 0, 100) : null;
    }

    /** Подсказка «как получится, если не заполнять» — для плейсхолдеров формы. */
    public static function latinGuess(array $user): array
    {
        return [
            \App\Core\Translit::latin((string)($user['last_name'] ?? '')),
            \App\Core\Translit::latin((string)($user['first_name'] ?? '')),
        ];
    }

    public function edit(): never
    {
        View::render('profile/edit', [
            'pageTitle' => 'Редактирование профиля',
            'user' => Auth::user(),
            'photos' => UserPhoto::forUser((int)Auth::user()['id']),
        ]);
    }

    public function save(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation();
        $birth = trim((string)($_POST['birthdate'] ?? ''));
        if ($birth !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $birth)) $birth = '';
        $data = [
            'last_name' => mb_substr(trim((string)($_POST['last_name'] ?? '')), 0, 100) ?: null,
            'first_name' => mb_substr(trim((string)($_POST['first_name'] ?? '')), 0, 100) ?: null,
            /* Только латиница, дефис и апостроф: поле уезжает в MRZ, где
               кириллице делать нечего. Пусто = считать автоматически. */
            'latin_last_name' => self::latinInput($_POST['latin_last_name'] ?? ''),
            'latin_first_name' => self::latinInput($_POST['latin_first_name'] ?? ''),
            'gender' => in_array($_POST['gender'] ?? '', ['male', 'female'], true)
                ? $_POST['gender'] : null,
            'display_name' => mb_substr(trim((string)($_POST['display_name'] ?? '')), 0, 150) ?: null,
            'birthdate' => $birth ?: null,
        ];
        /* Телефон: только цифры и плюс; UNIQUE — чужой номер не присваивается.
           Смена номера сбрасывает подтверждение: подтверждён был старый. */
        $phone = \App\Models\User::normalizePhone((string)($_POST['phone'] ?? ''));
        if ($phone !== (string)($user['phone'] ?? '')) {
            if (\App\Models\User::phoneTaken($phone, (int)$user['id'])) {
                Flash::error('Этот номер уже привязан к другому аккаунту.');
                redirect('/profile/edit');
            }
            $data['phone'] = $phone !== '' ? $phone : null;
            $data['phone_verified_at'] = null;
        }
        User::update((int)$user['id'], $data);
        Audit::log('profile.update', ['on' => (int)$user['id']]);
        EventDispatcher::userEvent(User::find((int)$user['id']), 'user.updated', ['fields' => ['profile']]);
        Flash::success('Изменения сохранены.');
        redirect('/profile');
    }

    /** Убрать дополнительный адрес (оставили соцвход или слияние). */
    public function deleteEmail(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation('/profile/edit');
        \App\Models\UserEmail::remove((int)$user['id'], (int)($_POST['id'] ?? 0));
        Audit::log('profile.email_removed', ['on' => (int)$user['id']]);
        Flash::success('Адрес убран.');
        redirect('/profile/edit');
    }

    /* ---- Аватар: GD → webp 512/128/32, EXIF умирает при перекодировании (§8.2) ---- */

    public function uploadAvatar(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation();
        $f = $_FILES['avatar'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            Flash::error('Файл не загрузился. Попробуйте другой.');
            redirect('/profile/edit');
        }
        if ($f['size'] > 5 * 1024 * 1024) {
            Flash::error('Аватар — до 5 МБ.');
            redirect('/profile/edit');
        }
        $img = \App\Core\Image::fromBytes((string)file_get_contents($f['tmp_name']));
        if ($img === false) {
            Flash::error('Это не похоже на изображение.');
            redirect('/profile/edit');
        }
        imagedestroy($img);

        /* Загруженное тоже попадает в галерею и сразу становится аватаркой:
           человек выбрал его осознанно. Так галерея — единственное место, где
           живут все фотографии, а не «загруженное отдельно, приехавшее
           отдельно». */
        $photoId = UserPhoto::store($user, (string)file_get_contents($f['tmp_name']), 'upload',
            mb_substr((string)($f['name'] ?? 'upload'), 0, 255) . ':' . time());
        if ($photoId === null) {
            Flash::error('Не удалось сохранить фото.');
            redirect('/profile/edit');
        }
        UserPhoto::makeCurrent($user, $photoId);
        Audit::log('avatar.update', ['on' => (int)$user['id']]);
        EventDispatcher::userEvent(User::find((int)$user['id']), 'user.updated', ['fields' => ['picture']]);
        Flash::success('Аватар обновлён.');
        redirect('/profile/edit');
    }

    /** POST /profile/photo/use — выбрать фото галереи аватаркой. */
    public function usePhoto(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation();
        $ok = UserPhoto::makeCurrent($user, (int)($_POST['photo_id'] ?? 0));
        if ($ok) {
            Audit::log('avatar.update', ['on' => (int)$user['id'], 'meta' => ['via' => 'gallery']]);
            EventDispatcher::userEvent(User::find((int)$user['id']), 'user.updated', ['fields' => ['picture']]);
            Flash::success('Аватар обновлён.');
        } else {
            Flash::error('Такого фото нет.');
        }
        redirect('/profile/edit');
    }

    /** POST /profile/photo/delete — убрать фото из галереи. */
    public function deletePhoto(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation();
        if (UserPhoto::remove($user, (int)($_POST['photo_id'] ?? 0))) {
            Flash::success('Фото удалено.');
        }
        redirect('/profile/edit');
    }

    public function deleteAvatar(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation();
        $dir = SSO_ROOT . '/storage/avatars/' . $user['public_id'];
        foreach ((array)glob($dir . '/*') as $f) @unlink($f);
        User::update((int)$user['id'], ['avatar_path' => null]);
        Flash::success('Аватар удалён — вернулись инициалы.');
        redirect('/profile/edit');
    }

    /** GET /avatar/{sub}/{file} — отдача из storage (вне webroot). */
    public function serveAvatar(string $sub, string $file): never
    {
        if (!str_starts_uuid($sub) || !in_array($file, ['512.webp', '128.webp', '32.webp'], true)) {
            ErrorPage::render(404);
        }
        $path = SSO_ROOT . '/storage/avatars/' . $sub . '/' . $file;
        $this->serveWebp($path);
    }

    /**
     * GET /photo/{sub}/{file} — снимок из галереи.
     *
     * Имя файла случайное и проверяется по строгой маске: в путь не должно
     * попасть ничего, кроме того, что мы сами сгенерировали. Галерея видна
     * всем, кто знает sub, — ровно как и аватар, который из неё же и сделан.
     */
    public function servePhoto(string $sub, string $file): never
    {
        if (!str_starts_uuid($sub) || preg_match('~^[a-f0-9]{16}\.webp$~', $file) !== 1) {
            ErrorPage::render(404);
        }
        $this->serveWebp(SSO_ROOT . '/storage/avatars/' . $sub . '/photos/' . $file);
    }

    /**
     * GET /brand/{id}.webp — логотип портала для мостика.
     *
     * Отдаём свою копию, а не проксируем чужой адрес: ссылка на портал
     * раскрывала бы ему IP каждого, кто открыл экран входа.
     */
    public function serveBrandLogo(string $file): never
    {
        /* Обложка панели портала (v4.71) — рядом с логотипом и тем же путём:
           имя из адреса разбирается по шаблону, а не подставляется в путь. */
        /* Обложка на витрине (v4.76) — тем же путём. Обложка панели входа с
           4.81 — своим адресом /cover/. */
        if (preg_match("~^card-(\d+)\.webp$~", $file, $m) === 1) {
            $this->serveWebp(\App\Core\PortalCover::path((int)$m[1]));
        }
        if (preg_match("~^(\d+)\.webp$~", $file, $m) !== 1) ErrorPage::render(404);
        $this->serveWebp(SSO_ROOT . '/storage/avatars/clients/brand-' . (int)$m[1] . '.webp');
    }

    /** GET /cover/{file} — картинка или видео обложки панели входа (v4.81). Публично: нужна экрану входа. */
    public function serveCover(string $file): never
    {
        \App\Core\CoverSet::serve($file);
    }

    /** GET /fonts/{slug}/{file} — файл шрифта из Google, скачанный к нам (v4.78). Публично: нужен экрану входа. */
    public function serveFont(string $slug, string $file): never
    {
        $path = \App\Core\FontLibrary::file($slug, $file);
        if ($path === null) ErrorPage::render(404);
        header('Content-Type: font/woff2');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }

    private function serveWebp(string $path): never
    {
        if (!is_file($path)) ErrorPage::render(404);
        header('Content-Type: image/webp');
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }

    /** GET /branding/{file} — логотип и favicon инсталляции (публично). */
    public function serveBranding(string $file): never
    {
        /* Белый список, а не «отдай что попросят»: имя приходит из адреса, и
           без списка это был бы обход каталога. */
        $types = ['logo.webp' => 'image/webp', 'favicon.png' => 'image/png'];
        if (!isset($types[$file])) ErrorPage::render(404);
        $path = SSO_ROOT . '/storage/avatars/branding/' . $file;
        if (!is_file($path)) ErrorPage::render(404);
        header('Content-Type: ' . $types[$file]);
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }

    /** GET /client-icon/{id} — иконка портала (публично: видна на экране входа). */
    public function serveClientIcon(string $id): never
    {
        $path = SSO_ROOT . '/storage/avatars/clients/' . (int)$id . '.webp';
        if (!is_file($path)) ErrorPage::render(404);
        header('Content-Type: image/webp');
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }

    /* ---- Устройства (§5.1) ---- */

    public function sessions(): never
    {
        $user = Auth::user();
        View::render('profile/sessions', [
            'pageTitle' => 'Устройства',
            'sessions' => SessionModel::activeForUser((int)$user['id']),
            'currentSessionId' => (int)(Auth::sessionRecord()['id'] ?? 0),
        ]);
    }

    /**
     * История аккаунта (v5.3): входы, неудачные попытки и изменения защиты —
     * всё, что раньше было видно только админу в журнале.
     */
    public function activity(): never
    {
        $user = Auth::user();
        $kind = (string)($_GET['k'] ?? 'all');
        if (!isset(\App\Core\UserHistory::kinds()[$kind])) $kind = 'all';
        $before = isset($_GET['before']) && ctype_digit((string)$_GET['before']) ? (int)$_GET['before'] : null;
        View::render('profile/activity', [
            'pageTitle' => 'История',
            'kind' => $kind,
            'page' => \App\Core\UserHistory::page((int)$user['id'], $kind, $before),
            'fails' => \App\Core\UserHistory::recentFails((int)$user['id']),
            'continued' => $before !== null,
        ]);
    }

    public function revokeSession(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation();
        $current = (int)(Auth::sessionRecord()['id'] ?? 0);
        if (($_POST['all'] ?? '') === '1') {
            // «Завершить все остальные» — с рассылкой logout порталам (§5.1)
            foreach (SessionModel::activeForUser((int)$user['id']) as $s) {
                if ((int)$s['id'] === $current) continue;
                EventDispatcher::sessionLogout((int)$s['id']);
                SessionModel::revoke((int)$s['id']);
            }
            Audit::log('session.revoke_all', ['on' => (int)$user['id']]);
            Flash::success('Все остальные сессии завершены.');
        } else {
            /* Строка «Устройств» — это устройство, а не одна сессия (v4.92):
               форма шлёт все его сессии списком. Прежнее одиночное поле
               принимаем — открытая до обновления страница не сломается. */
            $ids = array_filter(array_map('intval', explode(',', (string)($_POST['session_ids'] ?? $_POST['session_id'] ?? ''))));
            $done = SessionModel::endMany((int)$user['id'], $ids, $current);
            if ($done !== []) {
                Audit::log('session.revoke', ['on' => (int)$user['id'], 'meta' => ['sessions' => $done]]);
                Flash::success(count($done) === 1 ? t('Сессия завершена.')
                    : t('Завершено сессий: :n.', ['n' => count($done)]));
            }
        }
        redirect('/profile/sessions');
    }

    /* ---- Мои сервисы (§5.1) ---- */

    public function services(): never
    {
        $user = Auth::user();
        $services = Access::forUser((int)$user['id']);
        /* Предлагаем только те сервисы, которые сами вызвались быть на виду.
           Раньше сюда шли ВСЕ активные порталы, и человек, зашедший в свой
           профиль, видел список чужих проектов — включая те, о которых знать
           ему незачем: личный блокнот владельца выглядел как приглашение.
           Флаг для этого уже есть — «Показывать на главной» в карточке
           портала, — просто здесь он не спрашивался. Второй переключатель
           заводить незачем: вопрос один и тот же, «предлагать ли этот сервис
           тем, кто в нём ещё не был». */
        $allClients = Client::publicOnes();
        $connectedIds = array_map(fn($s) => (int)$s['client_id'], $services);
        $rolesByClient = [];
        foreach (\App\Models\Role::ofUser((int)$user['id']) as $r) {
            if ($r['client_id'] !== null) $rolesByClient[(int)$r['client_id']][] = $r['name'];
        }
        View::render('profile/services', [
            'pageTitle' => 'Мои сервисы',
            'services' => $services,
            'others' => array_filter($allClients, fn($c) => !in_array((int)$c['id'], $connectedIds, true)),
            'rolesByClient' => $rolesByClient,
        ]);
    }

    /** «Отозвать доступ» — для недоверенных клиентов (§5.1). */
    public function revokeService(): never
    {
        $user = Auth::user();
        $this->denyOnImpersonation();
        $client = Client::find((int)($_POST['client_id'] ?? 0));
        if ($client && !(int)$client['is_trusted']) {
            /* Человек сам отказался от сервиса — «закрыть», а не «стереть»:
               терять его данные там из-за одного клика нельзя (v4.64). */
            Access::remove((int)$user['id'], (int)$client['id'], 'closed');
            DB::run('UPDATE {refresh_tokens} SET revoked_at = ? WHERE user_id = ? AND client_id = ? AND revoked_at IS NULL',
                [now(), (int)$user['id'], (int)$client['id']]);
            /* Порталу сообщает сам Access::remove — одно место на все пути (v4.62). */
            Audit::log('access.revoked', ['on' => (int)$user['id'], 'client' => (int)$client['id'], 'meta' => ['by' => 'user']]);
            Flash::success(t('Доступ сервиса «:portal» отозван.', ['portal' => $client['name']]));
        }
        redirect('/profile/services');
    }

    /** Имперсонизация — только чтение: правки чужого профиля от имени запрещены (§5.2). */
    private function denyOnImpersonation(): void
    {
        if (Auth::impersonating()) {
            Flash::error('В режиме имперсонизации изменения запрещены.');
            redirect('/profile');
        }
    }
}
