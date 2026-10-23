<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Client;

/**
 * Редактор оформления экрана входа (v4.71): разбор формы и сохранение.
 *
 * Один редактор на два места — раздел «Экран входа» в карточке портала и
 * «Оформление сайта» для собственного входа установки. Поля одни и те же,
 * разбор один и тот же, и итог проходит тот же Bridge::sanitize(), что и
 * манифест портала. Отдельного пути для «своих» нет: оформление, заданное
 * администратором, проверяется так же строго, как присланное чужим сайтом.
 *
 * Показ — в Bridge. Здесь только то, что приходит из формы и уходит в базу.
 */
final class LoginLook
{
    /**
     * Как портал открывает вход (v4.72): popup — окном на странице портала
     * (фрейм, v4.73), window — отдельным окном браузера, page — всей страницей.
     */
    public const DISPLAYS = ['popup', 'window', 'page'];


    /**
     * Поля формы — массивом для Bridge::sanitize().
     *
     * Та же функция разбирает и предпросмотр (GET), и сохранение (POST):
     * предпросмотр не может показать того, чего не сохранит сохранение.
     *
     * @param array $in содержимое look[...] из формы
     */
    public static function fromForm(array $in, string $scope): array
    {
        $str = static fn(string $k): string => is_string($in[$k] ?? null) ? trim((string)$in[$k]) : '';
        $hex = static function (string $v): ?string {
            $v = strtoupper(ltrim($v, '#'));
            if (preg_match('~^[0-9A-F]{3}$~', $v)) $v = $v[0] . $v[0] . $v[1] . $v[1] . $v[2] . $v[2];
            return preg_match('~^[0-9A-F]{6}$~', $v) ? '#' . $v : null;
        };
        $raw = [];
        /* Тёмная тема — по выбору: без неё тёмная остаётся родной, а цвета
           портала живут только в светлой. Выключенный переключатель убирает
           все тёмные поля разом, а не оставляет половину палитры. */
        $withDark = ($in['dark'] ?? '') === '1';
        foreach (Bridge::COLORS as $name) {
            if (($c = $hex($str($name))) !== null) $raw[$name] = $c;
            if ($withDark && ($c = $hex($str($name . '_dark'))) !== null) $raw[$name . '_dark'] = $c;
        }
        if ($str('radius') !== '' && ctype_digit($str('radius'))) $raw['radius'] = (int)$str('radius');
        foreach (array_merge(['font', 'font_display', 'layout', 'headline', 'headline_note', 'tagline'], array_keys(Bridge::BUILDER)) as $k) {
            if ($str($k) !== '') $raw[$k] = $str($k);
        }
        /* Подкраска по умолчанию есть, поэтому храним только отказ от неё. */
        if (($in['wash'] ?? null) === '0') $raw['wash'] = false;
        return Bridge::sanitize($raw, $scope);
    }

    /**
     * Способы входа из формы: null — как на всём сайте, иначе список.
     *
     * @return ?list<string>
     */
    public static function methodsFromForm(array $post): ?array
    {
        if (($post['methods_mode'] ?? 'all') !== 'some') return null;
        return array_values(array_intersect(Appearance::METHODS,
            array_map('strval', (array)($post['methods'] ?? []))));
    }

    /**
     * Сохранить оформление портала.
     *
     * @return array{ok: bool, error: ?string, warnings: list<string>}
     */
    public static function saveForClient(array $client, array $post, array $files): array
    {
        $id = (int)$client['id'];
        $mode = (string)($post['look_mode'] ?? 'portal');
        if (!in_array($mode, Bridge::MODES, true)) $mode = 'portal';

        $methods = self::methodsFromForm($post);
        if ($methods !== null) {
            $alive = array_filter($methods, static fn(string $m): bool => Appearance::method($m)
                && ($m !== 'social' || \App\Social\Provider::enabled() !== []));
            if ($alive === []) {
                return ['ok' => false, 'warnings' => [],
                    'error' => t('Отметьте хотя бы один способ входа из включённых на сайте — иначе на этом портале войти будет нечем.')];
            }
        }

        $theme = self::fromForm((array)($post['look'] ?? []), 'portal');
        /* Обложка — набором (v4.81): картинки, видео, YouTube. До записи темы:
           набор забирает обложку прежнего вида, пока номер её ещё в базе. */
        $warnings = CoverSet::saveFromForm($id, $post, $files);

        $fields = [
            'look_mode' => $mode,
            'look_theme' => $theme !== [] ? json_encode($theme, JSON_UNESCAPED_UNICODE) : null,
            'login_methods' => $methods !== null ? implode(',', $methods) : null,
        ];
        /* Как портал открывает вход и «Вы вошли» (v4.72). На отставшей схеме
           колонок нет — сохраняем остальное. */
        if (DB::columnExists('clients', 'login_display')) {
            $display = (string)($post['login_display'] ?? '');
            $fields['login_display'] = in_array($display, self::DISPLAYS, true) ? $display : null;
            $fields['login_welcome'] = !empty($post['login_welcome']) ? 1 : 0;
        }
        Client::update($id, $fields);
        Audit::log('admin.client.look', ['client' => $id, 'meta' => [
            'mode' => $mode, 'fields' => count($theme), 'methods' => $methods !== null ? implode(',', $methods) : 'all',
        ]]);

        $fresh = Client::find($id);
        if ($mode === 'custom') $warnings = array_merge($warnings, Bridge::themeOf($fresh)['why'] ?? []);
        return ['ok' => true, 'error' => null, 'warnings' => $warnings];
    }

    /**
     * Сохранить оформление экрана входа самой установки.
     *
     * @return list<string> предупреждения: что из палитры не применится и почему
     */
    public static function saveForSite(array $post): array
    {
        $theme = self::fromForm((array)($post['look'] ?? []), 'site');
        if ($theme === []) Setting::delete('look.theme');
        else Setting::set('look.theme', (string)json_encode($theme, JSON_UNESCAPED_UNICODE));
        Setting::flush();
        return Bridge::themeOf(null)['why'] ?? [];
    }

    /**
     * Перекодировать картинку через GD и положить webp.
     *
     * Перекодирование — не только приведение к формату: оно убивает всё, что
     * не является картинкой, — SVG со скриптом, полиглоты, метаданные. Кадр
     * вписывается в рамку, а не режется: пропорцию задаёт вёрстка через
     * object-fit, и середину покажет она.
     */
    public static function storeImage(string $tmp, string $dest, int $maxW, int $maxH, int $quality): bool
    {
        if (!function_exists('imagewebp') || !is_file($tmp)) return false;
        $img = \App\Core\Image::fromBytes((string)file_get_contents($tmp));
        if ($img === false) return false;
        $w = imagesx($img); $h = imagesy($img);
        $scale = min(1.0, $maxW / max(1, $w), $maxH / max(1, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $dir = dirname($dest);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $ok = imagewebp($dst, $dest, $quality);
        imagedestroy($dst);
        imagedestroy($img);
        return $ok;
    }

    /**
     * Предпросмотр экрана входа с черновиком — страница для фрейма редактора.
     *
     * Рисуется НАСТОЯЩИЙ экран входа тем же шаблоном и той же раскладкой, что
     * видят люди, только с несохранённым оформлением и без возможности что-то
     * отправить. Отдельный «макет для превью» разошёлся бы с живым экраном при
     * первой же правке.
     */
    public static function renderPreview(?array $client, array $get): never
    {
        /* Без полей формы — сохранённое как есть: так открывается «отдельно» и
           так выглядит фрейм, пока скрипт редактора не прислал черновик. */
        if (isset($get['look']) || isset($get['look_mode'])) {
            $theme = self::fromForm((array)($get['look'] ?? []), $client !== null ? 'portal' : 'site');
            $mode = $client !== null ? (string)($get['look_mode'] ?? Bridge::mode($client)) : 'off';
            if ($client !== null) {
                Bridge::preview((int)$client['id'], $mode, $theme, self::methodsFromForm($get));
            } else {
                /* Установка: и состав способов, и подпись панели — из той же формы. */
                $methods = array_values(array_filter(Appearance::METHODS,
                    static fn(string $m): bool => !empty($get['method_' . $m])));
                Bridge::preview(null, 'off', $theme, $methods, [
                    'title' => mb_substr(trim((string)($get['media_title'] ?? '')), 0, 80),
                    'text' => mb_substr(trim((string)($get['media_text'] ?? '')), 0, 160),
                ], !empty($get['social_first']));
            }
        }
        Csp::allowSelfFraming();
        $previewMeta = [
            'scheme' => ($get['scheme'] ?? '') === 'dark' ? 'dark' : 'light',
            'why' => Bridge::themeOf($client)['why'] ?? [],
        ];
        if ($client !== null && ($get['screen'] ?? '') === 'welcome') {
            View::render('auth/welcome', [
                'pageTitle' => t('Вы вошли'), 'client' => $client,
                'user' => Auth::user() ?? ['first_name' => t('Иван'), 'email' => 'ivan@example.ru'],
                'continue' => '#', 'popup' => false, 'preview' => $previewMeta,
            ], 'layouts/auth');
        }
        View::render('auth/login', [
            'pageTitle' => t('Вход'),
            'client' => $client,
            'popup' => false,
            'identity' => '',
            'captcha' => false,
            'preview' => [
                'scheme' => ($get['scheme'] ?? '') === 'dark' ? 'dark' : 'light',
                'why' => Bridge::themeOf($client)['why'] ?? [],
            ],
        ], 'layouts/auth');
    }

    /**
     * Оформление в виде блока branding для манифеста портала.
     *
     * Для разработчика портала: то же самое можно держать в его манифесте, и
     * тогда оно поедет с порталом при переезде. Обложки в манифесте нет:
     * картинку по ссылке SSO не берёт, её загружают только здесь.
     */
    public static function manifestJson(array $theme): string
    {
        $out = Bridge::sanitize($theme, 'manifest');
        if (!empty($theme['tagline'])) $out['tagline'] = (string)$theme['tagline'];
        return (string)json_encode(['branding' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
