<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Flash;
use App\Core\Keys;
use App\Core\Mailer;
use App\Core\Setting;
use App\Core\View;

/** /admin/settings: брендинг, SMTP, политики, ключи, обслуживание (§5.2). */
final class SettingsController extends AdminBase
{
    /**
     * Сроки: ключ → [минимум, максимум, умолчание] в секундах.
     *
     * Один источник правды для формы и для сохранения — иначе поле принимает
     * значение, которое контроллер тут же обрежет, и человек этого не заметит.
     * Умолчания совпадают с константами в коде: раздел ничего не меняет, пока
     * его не открыли и не сохранили.
     */
    public const TIMEOUTS = [
        'session' => [300, 30 * 86400, 43200],
        'remember' => [3600, 365 * 86400, 2592000],
        'access_token' => [60, 86400, 900],
        'token_email_verify' => [900, 30 * 86400, 3 * 86400],
        'token_password_reset' => [300, 7 * 86400, 3600],
    ];

    /** Разделы, живущие в этом контроллере: ключ → файл шаблона. */
    private const SECTIONS = ['branding', 'smtp', 'policies', 'signup', 'timeouts',
                              'social', 'keys', 'docs', 'letters', 'alerts', 'maintenance'];

    /**
     * Раздел настроек — свой адрес у каждого.
     *
     * Раньше это была одна страница с восемью вкладками: на раздел нельзя было
     * дать ссылку, а восемь форм несли восемь полос «сохранить» на одном
     * экране. Теперь адрес есть, форма одна и кнопка одна.
     */
    public function index(string $section = 'branding'): never
    {
        $this->requirePerm('settings.read');
        $this->requireSuperadmin();
        if (!in_array($section, self::SECTIONS, true)) $section = 'branding';

        settings_page($section, 'admin/settings/' . $section, [
            'pageTitle' => t(\App\Core\SettingsMap::find($section)['title'] ?? 'Настройки'),
            'activeKid' => Keys::activeKid(),
            'keyAge' => Keys::activeAgeDays(),
            'registry' => Setting::getJson('keys.registry'),
        ]);
    }

    public function save(): never
    {
        $this->requirePerm('settings.write');
        $this->requireSuperadmin();
        $section = (string)($_POST['section'] ?? '');
        switch ($section) {
            case 'branding':
                Setting::set('branding.name', trim((string)($_POST['name'] ?? '')) ?: 'SSO');
                $accent = trim((string)($_POST['accent'] ?? ''));
                if ($accent === '' || preg_match('~^#[0-9a-fA-F]{6}$~', $accent)) {
                    $accent === '' ? Setting::delete('branding.accent') : Setting::set('branding.accent', $accent);
                }
                Setting::set('branding.footer', mb_substr(trim((string)($_POST['footer'] ?? '')), 0, 200));
                /* Подпись панели входа. Длины ограничены здесь, а не только
                   атрибутом maxlength: он живёт в браузере, а форму присылают
                   и мимо него. */
                $this->handleBrandingUploads();
                break;
            case 'smtp':
                foreach (['host', 'user', 'from_email', 'from_name'] as $k) {
                    Setting::set('mail.' . $k, trim((string)($_POST['smtp_' . $k] ?? '')));
                }
                Setting::set('mail.port', (string)max(1, (int)($_POST['smtp_port'] ?? 587)));
                Setting::set('mail.encryption', in_array($_POST['smtp_encryption'] ?? '', ['tls', 'ssl', 'none'], true)
                    ? (string)$_POST['smtp_encryption'] : 'tls');
                if ((string)($_POST['smtp_pass'] ?? '') !== '') {
                    Setting::set('mail.pass', (string)$_POST['smtp_pass']);
                }
                Setting::set('mail.driver', ($_POST['smtp_driver'] ?? '') === 'log' ? 'log' : 'smtp');
                break;
            /* Два раздела — две ветки, а не одна общая.
               Общая ветка писала бы ВСЕ ключи из $_POST, а форма присылает
               только свои поля: сохранение «Регистрации» молча обнулило бы
               тумблеры «Входа», потому что их в запросе нет. Разбор чуть
               длиннее, зато сохранение раздела трогает только его. */
            case 'policies':
                // Всё опционально: минимум длины можно опустить до 1, требования — тумблерами
                Setting::set('policy.password_min', (string)max(1, min(64, (int)($_POST['password_min'] ?? 12))));
                foreach (['password_digits', 'password_letters', 'password_upper', 'password_special',
                          'admin_require_2fa', 'require_phone', 'require_gender',
                          /* Маска IP в СВОЁМ профиле. В админке её не было
                             никогда: по «91.132.✻.✻» инцидент не разберёшь. */
                          'mask_own_ip'] as $flag) {
                    Setting::set('policy.' . $flag, !empty($_POST[$flag]) ? '1' : '0');
                }
                Setting::set('policy.lockout_attempts', (string)max(3, min(30, (int)($_POST['lockout_attempts'] ?? 10))));
                Setting::set('policy.lockout_minutes', (string)max(1, min(1440, (int)($_POST['lockout_minutes'] ?? 15))));
                Setting::set('policy.lock_minutes', (string)max(0, min(1440, (int)($_POST['lock_minutes'] ?? 0))));
                // Сроки хранения: 0 = хранить вечно
                foreach (['audit_days', 'activity_days', 'app_logs_days', 'mail_days'] as $k) {
                    Setting::set('retention.' . $k, (string)max(0, min(3650, (int)($_POST['retention_' . $k] ?? 0))));
                }
                break;

            case 'signup':
                Setting::set('policy.signup_open', !empty($_POST['signup_open']) ? '1' : '0');
                /* Роль новичка принимаем только из предложенного списка: он и
                   собран так, чтобы туда нельзя было попасть роли старше
                   «пользователя». Иначе одна опечатка в поле раздаёт права
                   каждому, кто зарегистрируется следующим. */
                $wantRole = trim((string)($_POST['default_role'] ?? 'user'));
                if (isset(\App\Core\Signup::roleChoices()[$wantRole])) {
                    Setting::set('policy.default_role', $wantRole);
                }
                Setting::set('policy.welcome_screen', !empty($_POST['welcome_screen']) ? '1' : '0');
                /* Из перечня, а не из формы как есть (v4.72). */
                /* Один переключатель (v4.74): в письме и код, и ссылка. */
                Setting::set('policy.verify_email', !empty($_POST['verify_email']) ? 'on' : 'off');
                Setting::set('policy.email_domains',
                    mb_substr(trim((string)($_POST['email_domains'] ?? '')), 0, 2000));
                /* Имя для входа: границы длины и свои занятые имена. Набор
                   символов не настраивается — см. App\Core\LoginName. */
                $lMin = max(\App\Core\LoginName::MIN_FLOOR,
                    min(\App\Core\LoginName::MAX_CEIL, (int)($_POST['login_min'] ?? 3)));
                $lMax = max($lMin, min(\App\Core\LoginName::MAX_CEIL, (int)($_POST['login_max'] ?? 32)));
                Setting::set('policy.login_min', (string)$lMin);
                Setting::set('policy.login_max', (string)$lMax);
                Setting::set('policy.login_reserved',
                    mb_substr(trim((string)($_POST['login_reserved'] ?? '')), 0, 2000));
                /* Капча: порог, а не «включить всем». Ниже двух попыток порог
                   не опускаем — иначе капча встречает человека с первой опечатки. */
                Setting::set('captcha.enabled', !empty($_POST['captcha_enabled']) ? '1' : '0');
                Setting::set('captcha.on_register', !empty($_POST['captcha_on_register']) ? '1' : '0');
                Setting::set('captcha.on_recover', !empty($_POST['captcha_on_recover']) ? '1' : '0');
                Setting::set('captcha.after_attempts', (string)max(2, min(20, (int)($_POST['captcha_after_attempts'] ?? 3))));
                Setting::set('captcha.ttl', (string)max(60, min(3600, (int)($_POST['captcha_ttl'] ?? 300))));
                /* Провайдер из каталога, а не из формы как есть: чужое значение
                   молча превратило бы проверку в неизвестно что. */
                $capProvider = (string)($_POST['captcha_provider'] ?? 'own');
                Setting::set('captcha.provider',
                    isset(\App\Core\Captcha::PROVIDERS[$capProvider]) ? $capProvider : 'own');
                Setting::set('captcha.site_key', trim((string)($_POST['captcha_site_key'] ?? '')));
                Setting::set('captcha.secret', trim((string)($_POST['captcha_secret'] ?? '')));
                break;

            /* Сроки. Просили укоротить — значит, нижние границы низкие, а не
               «безопасные». Верхние есть только там, где длинный срок молча
               ломает смысл: сессия на год — это не сессия. Ноль нигде не
               означает «вечно»: вечная ссылка сброса пароля хуже, чем её
               отсутствие. */
            case 'timeouts':
                foreach (self::TIMEOUTS as $key => [$min, $max, $default]) {
                    $v = (int)($_POST['t_' . $key] ?? $default);
                    Setting::set('timeout.' . $key, (string)max($min, min($max, $v)));
                }
                /* Пояс — из перечня, а не из формы как есть: чужая строка
                   уронила бы DateTimeZone на первом же выводе даты. */
                $tz = (string)($_POST['timezone'] ?? '');
                if (array_key_exists($tz, tz_options())) Setting::set('app.timezone', $tz);
                break;

            case 'social':
                // Каждый провайдер включается отдельно и по умолчанию выключен
                foreach (\App\Social\Provider::all() as $p) {
                    $id = $p->id();
                    Setting::set('social.' . $id . '.enabled', !empty($_POST['enabled_' . $id]) ? '1' : '0');
                    Setting::set('social.' . $id . '.client_id', trim((string)($_POST['client_id_' . $id] ?? '')));
                    if (($_POST['secret_' . $id] ?? '') !== '') {
                        Setting::set('social.' . $id . '.secret', (string)$_POST['secret_' . $id]);
                    }
                }
                break;

            case 'maintenance':
                Setting::set('maintenance.enabled', !empty($_POST['enabled']) ? '1' : '0');
                Setting::set('maintenance.allowlist', trim((string)($_POST['allowlist'] ?? '')));
                break;

            case 'docs':
                /* Владелец и контакт переехали в «Реквизиты»: два места для
                   одних данных расходятся при первой же правке. Здесь их
                   больше не пишем, а старые значения не трогаем — на них ещё
                   могут стоять подстановки в чужих текстах. */
                foreach (array_keys(\App\Core\Legal::DOCS) as $key) {
                    /* Кнопка «взять шаблон» приходит тем же POST: подставляем
                       заготовку вместо присланного текста, чтобы не заставлять
                       человека копировать её руками. */
                    $body = ($_POST['template'] ?? '') === $key
                        ? \App\Core\Legal::template($key)
                        : (string)($_POST['body_' . $key] ?? '');
                    \App\Core\Legal::save($key, $body, !empty($_POST['enabled_' . $key]));
                }
                break;
            /* Тексты писем. Пустое поле — не «стереть», а «как в поставке»:
               иначе, очистив строку, владелец получил бы письмо без объяснения
               вместо письма с нашим. */
            case 'letters':
                Setting::set('mail.footer',
                    mb_substr(trim((string)($_POST['footer'] ?? '')), 0, 600));
                foreach (array_keys(\App\Core\MailTemplates::LETTERS) as $letter) {
                    foreach (['subject', 'greeting', 'body', 'button'] as $part) {
                        \App\Core\MailTemplates::save($letter, $part,
                            (string)($_POST[$letter . '_' . $part] ?? ''));
                    }
                }
                break;
            /* Уведомления владельцу. Тумблеры именуются с подчёркиванием
               вместо точки: `ev_user.registered` в имени поля PHP превратил бы
               в массив, и настройка молча не сохранялась бы. */
            case 'alerts':
                foreach (array_keys(\App\Core\AdminNotify::EVENTS) as $event) {
                    $field = 'ev_' . str_replace('.', '_', $event);
                    Setting::set('notify.admin.' . $event, !empty($_POST[$field]) ? '1' : '0');
                }
                Setting::set('notify.admin.to',
                    mb_substr(trim((string)($_POST['to'] ?? '')), 0, 1000));
                Setting::set('notify.admin.window',
                    (string)max(1, min(1440, (int)($_POST['window'] ?? \App\Core\AdminNotify::WINDOW_DEFAULT))));
                break;
            default:
                Flash::error('Неизвестная секция настроек.');
                redirect('/admin/settings');
        }
        Audit::log('admin.settings.update', ['meta' => ['section' => $section]]);
        Flash::success('Настройки сохранены.');
        /* Якорь — идентификатор ВКЛАДКИ, а не имя секции. Вкладки называются
           `tab-docs`, `tab-keys`, и по `#docs` скрипт совпадения не находил:
           человек сохранял документы, а возвращался на брендинг. */
        /* Возврат на адрес раздела: якорей больше нет, у каждого свой путь. */
        redirect($section === 'branding' ? '/admin/settings' : '/admin/settings/section/' . $section);
    }

    /** Логотип (webp, высота 64, прозрачность) и favicon (png 64×64). */
    private function handleBrandingUploads(): void
    {
        $dir = SSO_ROOT . '/storage/avatars/branding';
        if (!is_dir($dir)) mkdir($dir, 0750, true);

        if (!empty($_POST['logo_delete'])) {
            @unlink($dir . '/logo.webp');
            Setting::delete('branding.logo_v');
        } elseif (($f = $_FILES['logo'] ?? null) && $f['error'] === UPLOAD_ERR_OK && $f['size'] <= 2 * 1024 * 1024) {
            $img = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
            if ($img !== false && function_exists('imagewebp')) {
                $w = imagesx($img); $h = imagesy($img);
                $nh = 64;
                $nw = max(1, min(400, (int)round($w * $nh / max(1, $h))));
                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, (int)imagecolorallocatealpha($dst, 0, 0, 0, 127));
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagewebp($dst, $dir . '/logo.webp', 92);
                imagedestroy($dst);
                imagedestroy($img);
                Setting::set('branding.logo_v', (string)time());
            } else {
                Flash::error('Логотип: не удалось обработать файл (нужен GD с webp).');
            }
        }

        if (!empty($_POST['favicon_delete'])) {
            @unlink($dir . '/favicon.png');
            Setting::delete('branding.favicon_v');
        } elseif (($f = $_FILES['favicon'] ?? null) && $f['error'] === UPLOAD_ERR_OK && $f['size'] <= 1024 * 1024) {
            $img = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
            if ($img !== false) {
                $w = imagesx($img); $h = imagesy($img);
                $side = min($w, $h);
                $dst = imagecreatetruecolor(64, 64);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, (int)imagecolorallocatealpha($dst, 0, 0, 0, 127));
                imagecopyresampled($dst, $img, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), 64, 64, $side, $side);
                imagepng($dst, $dir . '/favicon.png');
                imagedestroy($dst);
                imagedestroy($img);
                Setting::set('branding.favicon_v', (string)time());
            } else {
                Flash::error('Favicon: не удалось обработать файл.');
            }
        }
    }

    /** AJAX «отправить тест» из вкладки SMTP. */
    public function smtpTest(): never
    {
        $this->requirePerm('settings.write');
        $this->requireSuperadmin();
        $to = trim((string)($_POST['test_email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            json_response(['ok' => false, 'message' => t('Укажите корректный email для теста.')]);
        }
        try {
            Mailer::transport($to, t(':app — тестовое письмо', ['app' => Setting::appName()]),
                '<p>' . esc(t('SMTP настроен верно. Письмо из /admin/settings.')) . '</p>', t('SMTP настроен верно.'), [
                    'host' => trim((string)($_POST['smtp_host'] ?? '')),
                    'port' => (int)($_POST['smtp_port'] ?? 587),
                    'encryption' => (string)($_POST['smtp_encryption'] ?? 'tls'),
                    'user' => trim((string)($_POST['smtp_user'] ?? '')),
                    'pass' => (string)($_POST['smtp_pass'] ?? '') !== ''
                        ? (string)$_POST['smtp_pass'] : (string)Setting::get('mail.pass', ''),
                    'from_email' => trim((string)($_POST['smtp_from_email'] ?? '')) ?: trim((string)($_POST['smtp_user'] ?? '')),
                    'from_name' => Setting::appName(),
                ]);
            json_response(['ok' => true, 'message' => t('Отправлено — проверьте ящик.')]);
        } catch (\Throwable $e) {
            $debug = Mailer::lastDebug();
            json_response(['ok' => false, 'message' => t('Не ушло: :why', ['why' => $e->getMessage()])
                . ($debug !== '' ? "\n— " . t('SMTP-диалог') . " —\n" . $debug : '')]);
        }
    }

    /** Ротация ключей подписи (§3.4): новый активен, старый в JWKS 48 ч. */
    /**
     * Реквизиты владельца — отдельным экраном, а не девятой вкладкой.
     *
     * Их дюжина, они длинные, и правят их редко и целиком: вкладка среди
     * восьми других превратила бы «заполнить реквизиты» в поиск по вкладкам.
     */
    public function requisites(): never
    {
        $this->requirePerm('settings.read');
        $this->requireGlobalAdmin();
        settings_page('requisites', 'admin/requisites', [
            'pageTitle' => t('Реквизиты'),
            'fields' => \App\Core\Requisites::fields(),
            'filled' => \App\Core\Requisites::filled(),
        ], '<a class="btn btn-secondary" href="/admin/settings/section/docs">' . esc(t('К документам →')) . '</a>');
    }

    public function saveRequisites(): never
    {
        $this->requirePerm('settings.write');
        $this->requireGlobalAdmin();
        \App\Core\Requisites::saveFromPost($_POST);
        Setting::flush();
        Audit::log('admin.settings.update', ['meta' => ['section' => 'requisites']]);
        Flash::success(t('Реквизиты сохранены.'));
        redirect('/admin/settings/requisites');
    }

    public function rotateKeys(): never
    {
        $this->requirePerm('keys.rotate');
        $this->requireSuperadmin();
        $new = Keys::rotate();
        Audit::log('admin.keys.rotate', ['meta' => ['new_kid' => $new]]);
        Flash::success(t('Ключ ротирован: новый kid=:kid. Старый останется в JWKS 48 часов.', ['kid' => $new]));
        redirect('/admin/settings/section/keys');
    }
}
