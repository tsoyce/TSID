<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Обложка панели входа (v4.81): картинка, галерея, видео файлом или видео с
 * YouTube — у установки и у каждого портала одним механизмом.
 *
 * Владелец: «добавь в обложку с панелью входа галерею обложек или постеров —
 * что-то вроде живых обоев, или загрузить видео, например видео из YouTube по
 * ссылке». До 4.81 обложек было две и жили они по-разному: у установки —
 * branding/media.webp с версией в настройке, у портала — cover-{id}.webp с
 * версией в его теме. Теперь набор один: список картинок, видео, ссылка на
 * YouTube, что показывать и как. Хранится настройкой cover.site или
 * cover.c{id}, файлы — в storage/avatars/covers под случайными именами.
 *
 * Показ осторожный. Видео и YouTube подключает скрипт и только тогда, когда
 * панель видна (на телефоне и в окне портала её нет) и человек не просил
 * «без движения». Иначе стоит первая картинка — заставка. Поэтому HTML панели
 * не содержит ни src видео, ни фрейма YouTube.
 */
final class CoverSet
{
    /** Что показывать: картинки (одна — снимок, несколько — по кругу), видео, YouTube. */
    public const KINDS = ['images', 'video', 'youtube'];
    /** Смена кадров: fade — только сменой, drift — ещё и медленным движением («живые обои»). */
    public const MOTIONS = ['fade', 'drift'];
    /** Секунд на кадр. */
    public const EVERY = [5, 8, 12];
    public const MAX_IMAGES = 8;
    public const IMAGE_MAX_BYTES = 6 * 1024 * 1024;
    /** Видео не перекодируется, поэтому потолок жёсткий: экран входа не кинотеатр. */
    public const VIDEO_MAX_BYTES = 40 * 1024 * 1024;
    public const YOUTUBE_ORIGIN = 'https://www.youtube-nocookie.com';
    /** Набор фона страниц (v4.84): картинки и видео за формами, кабинетом и админкой. */
    public const BACKDROP = 'bg';

    /**
     * Ключ набора: site — обложка установки, c{id} — портала, bg — фон
     * страниц (v4.84). Механизм один: что умеет обложка, умеет и фон.
     */
    public static function scope(int|string|null $owner): string
    {
        if ($owner === self::BACKDROP) return 'bg';
        return $owner === null ? 'site' : 'c' . (int)$owner;
    }

    public static function dir(): string
    {
        return SSO_ROOT . '/storage/avatars/covers';
    }

    public static function path(string $scope, string $id, string $ext): string
    {
        return self::dir() . '/' . $scope . '-' . $id . '.' . $ext;
    }

    public static function url(string $scope, string $id, string $ext): string
    {
        return base_url('/cover/' . $scope . '-' . $id . '.' . $ext);
    }

    /**
     * Пустой набор. sound — кнопка звука у видео (v4.83): браузеры не дают
     * видео начать со звуком, звук включает сам посетитель.
     *
     * @return array{kind: string, images: list<string>, video: ?array{id: string, ext: string}, youtube: string, motion: string, every: int, sound: bool}
     */
    public static function blank(): array
    {
        return ['kind' => 'images', 'images' => [], 'video' => null, 'youtube' => '', 'motion' => 'fade', 'every' => 8, 'sound' => true];
    }

    /**
     * Набор обложки. Файлы, которых нет на диске (перенос настроек без файлов,
     * ручная чистка), в набор не попадают.
     *
     * @return array{kind: string, images: list<string>, video: ?array{id: string, ext: string}, youtube: string, motion: string, every: int}
     */
    public static function get(int|string|null $owner): array
    {
        $scope = self::scope($owner);
        $raw = Setting::get('cover.' . $scope);
        if ($raw === null) return is_string($owner) ? self::blank() : self::adoptLegacy($owner);
        $in = json_decode((string)$raw, true);
        if (!is_array($in)) return self::blank();
        $out = self::blank();
        if (in_array($in['kind'] ?? '', self::KINDS, true)) $out['kind'] = (string)$in['kind'];
        if (in_array($in['motion'] ?? '', self::MOTIONS, true)) $out['motion'] = (string)$in['motion'];
        if (in_array((int)($in['every'] ?? 0), self::EVERY, true)) $out['every'] = (int)$in['every'];
        if (array_key_exists('sound', $in)) $out['sound'] = (bool)$in['sound'];
        foreach ((array)($in['images'] ?? []) as $id) {
            if (is_string($id) && self::validId($id) && is_file(self::path($scope, $id, 'webp'))) $out['images'][] = $id;
        }
        $out['images'] = array_slice(array_values(array_unique($out['images'])), 0, self::MAX_IMAGES);
        $v = $in['video'] ?? null;
        if (is_array($v) && self::validId((string)($v['id'] ?? '')) && in_array($v['ext'] ?? '', ['mp4', 'webm'], true)
            && is_file(self::path($scope, (string)$v['id'], (string)$v['ext']))) {
            $out['video'] = ['id' => (string)$v['id'], 'ext' => (string)$v['ext']];
        }
        $yt = (string)($in['youtube'] ?? '');
        if (preg_match('~^[A-Za-z0-9_-]{11}$~', $yt)) $out['youtube'] = $yt;
        return $out;
    }

    /**
     * Что будет на панели на самом деле: выбранное, если для него есть что
     * показать, иначе картинки. Видео без файла или YouTube без ссылки не
     * оставляют панель пустой.
     */
    public static function shown(array $set): string
    {
        if ($set['kind'] === 'youtube' && $set['youtube'] !== '') return 'youtube';
        if ($set['kind'] === 'video' && $set['video'] !== null) return 'video';
        return 'images';
    }

    /** Адрес первой картинки — для карточки витрины и заставки видео. */
    public static function firstImageUrl(int|string|null $owner): ?string
    {
        $set = self::get($owner);
        return $set['images'] !== [] ? self::url(self::scope($owner), $set['images'][0], 'webp') : null;
    }

    /**
     * Разобрать ссылку YouTube: код видео из 11 символов или null.
     *
     * Принимаются только адреса самого YouTube: watch?v=, youtu.be/, shorts/,
     * embed/, live/ — и голый код. Показывается видео всегда с
     * youtube-nocookie.com, какую бы ссылку ни вставили.
     */
    public static function youtubeId(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('~^[A-Za-z0-9_-]{11}$~', $url)) return $url;
        if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
        $p = parse_url($url);
        if (!is_array($p) || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true)) return null;
        if (isset($p['user']) || isset($p['pass']) || isset($p['port'])) return null;
        $host = (string)preg_replace('~^(www|m|music)\.~', '', strtolower((string)($p['host'] ?? '')));
        $path = (string)($p['path'] ?? '');
        $id = null;
        if ($host === 'youtu.be') {
            $id = explode('/', ltrim($path, '/'))[0];
        } elseif ($host === 'youtube.com' || $host === 'youtube-nocookie.com') {
            if ($path === '/watch') {
                parse_str((string)($p['query'] ?? ''), $q);
                $id = is_string($q['v'] ?? null) ? $q['v'] : null;
            } elseif (preg_match('~^/(?:shorts|embed|live|v)/([^/]+)~', $path, $m)) {
                $id = $m[1];
            }
        }
        return is_string($id) && preg_match('~^[A-Za-z0-9_-]{11}$~', $id) ? $id : null;
    }

    /** Видео по первым байтам, а не по имени и не по типу из браузера: mp4, webm или null. */
    public static function videoType(string $file): ?string
    {
        $head = (string)@file_get_contents($file, false, null, 0, 16);
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') return 'mp4';
        if (strncmp($head, "\x1A\x45\xDF\xA3", 4) === 0) return 'webm';
        return null;
    }

    /** Сколько байт видео пропустит сервер: наш потолок или меньший из лимитов PHP. */
    public static function videoLimit(): int
    {
        $bytes = static function (string $v): int {
            $v = trim($v);
            if ($v === '' || $v === '-1' || $v === '0') return PHP_INT_MAX;
            $n = (int)$v;
            return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
        };
        return min(self::VIDEO_MAX_BYTES, $bytes((string)ini_get('upload_max_filesize')), $bytes((string)ini_get('post_max_size')));
    }

    /**
     * Сохранить из формы редактора: {prefix}_kind, _motion, _every, _sound,
     * _add[] (картинки), _drop[] (какие убрать), _video, _video_delete,
     * _youtube. Приставка — чтобы на одной странице жили обложка (cover) и
     * фон страниц (bg, v4.84).
     *
     * @return list<string> что не принято и почему
     */
    public static function saveFromForm(int|string|null $owner, array $post, array $files, string $prefix = 'cover'): array
    {
        $scope = self::scope($owner);
        $set = self::get($owner);
        $k = static fn(string $name): string => $prefix . '_' . $name;
        $warn = [];
        if (in_array($post[$k('kind')] ?? '', self::KINDS, true)) $set['kind'] = (string)$post[$k('kind')];
        if (in_array($post[$k('motion')] ?? '', self::MOTIONS, true)) $set['motion'] = (string)$post[$k('motion')];
        if (in_array((int)($post[$k('every')] ?? 0), self::EVERY, true)) $set['every'] = (int)$post[$k('every')];
        if (isset($post[$k('sound')])) $set['sound'] = (string)$post[$k('sound')] === '1';

        $drop = array_map('strval', (array)($post[$k('drop')] ?? []));
        foreach ($set['images'] as $i => $id) {
            if (!in_array($id, $drop, true)) continue;
            @unlink(self::path($scope, $id, 'webp'));
            unset($set['images'][$i]);
        }
        $set['images'] = array_values($set['images']);

        foreach (self::uploads($files[$k('add')] ?? null) as $f) {
            if (count($set['images']) >= self::MAX_IMAGES) {
                $warn[] = t('Картинок в обложке не больше :n — лишние не добавлены.', ['n' => self::MAX_IMAGES]);
                break;
            }
            if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > self::IMAGE_MAX_BYTES) {
                $warn[] = t('Картинка «:name» не принята: больше 6 МБ или не дошла.', ['name' => $f['name']]);
                continue;
            }
            $id = self::newId();
            if (!LoginLook::storeImage($f['tmp_name'], self::path($scope, $id, 'webp'), 1600, 1400, 82)) {
                $warn[] = t('Картинка «:name» не принята: это не картинка или на сервере нет GD с webp.', ['name' => $f['name']]);
                continue;
            }
            $set['images'][] = $id;
        }

        if (!empty($post[$k('video_delete')]) && $set['video'] !== null) {
            @unlink(self::path($scope, $set['video']['id'], $set['video']['ext']));
            $set['video'] = null;
        }
        $v = self::uploads($files[$k('video')] ?? null)[0] ?? null;
        if ($v !== null) {
            $type = $v['error'] === UPLOAD_ERR_OK ? self::videoType($v['tmp_name']) : null;
            if ($v['error'] !== UPLOAD_ERR_OK || $v['size'] > self::videoLimit()) {
                $warn[] = t('Видео не принято: больше :mb МБ или не дошло.', ['mb' => (int)floor(self::videoLimit() / 1048576)]);
            } elseif ($type === null) {
                $warn[] = t('Видео не принято: нужен файл MP4 или WebM.');
            } else {
                $id = self::newId();
                if (!is_dir(self::dir())) @mkdir(self::dir(), 0750, true);
                if (@copy($v['tmp_name'], self::path($scope, $id, $type))) {
                    if ($set['video'] !== null) @unlink(self::path($scope, $set['video']['id'], $set['video']['ext']));
                    $set['video'] = ['id' => $id, 'ext' => $type];
                } else {
                    $warn[] = t('Видео не сохранилось: нет места или прав на запись.');
                }
            }
        }

        if (array_key_exists($k('youtube'), $post)) {
            $link = trim((string)$post[$k('youtube')]);
            $yt = $link === '' ? '' : self::youtubeId($link);
            if ($yt === null) $warn[] = t('Ссылка не похожа на видео YouTube — оставлена прежняя.');
            else $set['youtube'] = $yt;
        }
        if ($set['kind'] === 'video' && $set['video'] === null) $warn[] = t('Выбрано видео, но файла нет — на панели будут картинки.');
        if ($set['kind'] === 'youtube' && $set['youtube'] === '') $warn[] = t('Выбран YouTube, но ссылки нет — на панели будут картинки.');

        Setting::set('cover.' . $scope, (string)json_encode($set, JSON_UNESCAPED_SLASHES));
        return $warn;
    }

    /** Удалить набор портала целиком — вместе с порталом. */
    public static function purge(int $clientId): void
    {
        foreach ((array)glob(self::dir() . '/c' . $clientId . '-*') as $f) @unlink((string)$f);
        Setting::delete('cover.c' . $clientId);
    }

    /**
     * Кадр панели входа — содержимое .media-frame или null, если показывать
     * нечего (тогда панель — заставка со знаком). У фона страниц кнопки
     * звука нет: звук за админкой — не то, чего ждут ($withSound = false).
     */
    public static function frame(int|string|null $owner, bool $withSound = true): ?string
    {
        $scope = self::scope($owner);
        $set = self::get($owner);
        $imgs = array_map(static fn(string $id): string => self::url($scope, $id, 'webp'), $set['images']);
        /* lazy: на телефоне панели нет, и скрытая картинка тогда не качается
           (замер 25.09 — без него первая грузилась и там). */
        $poster = $imgs !== [] ? '<img src="' . esc($imgs[0]) . '" alt="" loading="lazy">' : '';
        /* Кнопка звука (v4.83): видео начинает без звука — со звуком браузеры
           его просто не запустят, — а включить звук посетитель может сам.
           Скрыта, пока видео не заиграло. */
        $sound = $set['sound'] && $withSound ? '<button class="cover-sound" type="button" hidden data-cover-sound aria-pressed="false"'
            . ' data-on="' . esc(t('Выключить звук')) . '" data-off="' . esc(t('Включить звук')) . '" aria-label="' . esc(t('Включить звук')) . '">'
            . icon('volume-off', 18, 'cs-off') . icon('volume', 18, 'cs-on') . '</button>' : '';
        switch (self::shown($set)) {
            case 'youtube':
                /* Фрейм создаёт скрипт; политике — только адрес без cookie.
                   Заставка лежит ПОВЕРХ фрейма и уходит, когда плеер уже
                   отыграл свои подписи (v4.83): на боевой в первые секунды и на
                   каждом повторе поверх обложки стояли название ролика и кнопки
                   «назад · пауза · вперёд». Без картинки — ровная подложка. */
                Csp::allow('frame-src', self::YOUTUBE_ORIGIN);
                return '<div class="cover-live cover-yt" data-cover-yt="' . esc($set['youtube']) . '"'
                    . ' data-cover-title="' . esc(t('Видео на обложке')) . '">'
                    . ($poster !== '' ? $poster : '<span class="cover-veil"></span>') . '</div>' . $sound;
            case 'video':
                /* Заставка — картинкой под видео, а не атрибутом poster: poster
                   качается и там, где панели не видно (замер 25.09, телефон). */
                return $poster . '<video class="cover-video" muted loop playsinline preload="none" aria-hidden="true"'
                    . ' data-cover-video="' . esc(self::url($scope, $set['video']['id'], $set['video']['ext'])) . '"></video>' . $sound;
        }
        if ($imgs === []) return null;
        $drift = $set['motion'] === 'drift' ? ' is-drift' : '';
        if (count($imgs) === 1) return '<div class="cover-slides' . $drift . '">' . $poster . '</div>';
        /* Первая — сразу, остальные — когда до них дойдёт очередь: экран входа
           не грузит восемь снимков ради первого. */
        $html = '<div class="cover-slides' . $drift . '" data-cover-slides="' . $set['every'] . '">'
            . '<img class="is-on" src="' . esc($imgs[0]) . '" alt="" loading="lazy">';
        foreach (array_slice($imgs, 1) as $u) $html .= '<img data-src="' . esc($u) . '" alt="">';
        return $html . '</div>';
    }

    /** Отдать файл обложки: GET /cover/{file}. Картинки целиком, видео — и по кускам (Range): без этого Safari его не играет. */
    public static function serve(string $file): never
    {
        if (!preg_match('~^(site|bg|c\d{1,9})-([0-9a-f]{12})\.(webp|mp4|webm)$~', $file, $m)) ErrorPage::render(404);
        $path = self::path($m[1], $m[2], $m[3]);
        if (!is_file($path)) ErrorPage::render(404);
        send_file($path, ['webp' => 'image/webp', 'mp4' => 'video/mp4', 'webm' => 'video/webm'][$m[3]],
            'public, max-age=31536000, immutable');
    }

    /**
     * Обложка до 4.81 — в новый набор. Файл копируется под новым именем,
     * старая запись у установки убирается; у портала номер в теме уходит при
     * следующем сохранении его оформления. Имя выводится из старой версии —
     * два одновременных запроса кладут один и тот же файл.
     */
    private static function adoptLegacy(?int $clientId): array
    {
        $set = self::blank();
        if ($clientId === null) {
            $v = (string)Setting::get('branding.media_v', '');
            $old = SSO_ROOT . '/storage/avatars/branding/media.webp';
        } else {
            $row = DB::row('SELECT look_theme FROM {clients} WHERE id = ?', [$clientId]);
            $v = (string)((json_decode((string)($row['look_theme'] ?? ''), true) ?: [])['cover'] ?? '');
            $old = Bridge::coverPath($clientId);
        }
        if ($v === '' || !is_file($old)) return $set;
        $scope = self::scope($clientId);
        $id = substr(hash('sha256', $scope . '|' . $v), 0, 12);
        if (!is_dir(self::dir())) @mkdir(self::dir(), 0750, true);
        if (!is_file(self::path($scope, $id, 'webp')) && !@copy($old, self::path($scope, $id, 'webp'))) return $set;
        $set['images'] = [$id];
        Setting::set('cover.' . $scope, (string)json_encode($set, JSON_UNESCAPED_SLASHES));
        @unlink($old);
        if ($clientId === null) Setting::delete('branding.media_v');
        return $set;
    }

    private static function validId(string $id): bool
    {
        return preg_match('~^[0-9a-f]{12}$~', $id) === 1;
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(6));
    }

    /**
     * Файлы поля — списком, одно поле или несколько (name[]).
     *
     * @return list<array{name: string, tmp_name: string, error: int, size: int}>
     */
    private static function uploads(mixed $f): array
    {
        if (!is_array($f) || !isset($f['error'])) return [];
        $out = [];
        foreach ((array)$f['error'] as $i => $err) {
            if ((int)$err === UPLOAD_ERR_NO_FILE) continue;
            $pick = static fn(string $k): mixed => is_array($f[$k] ?? null) ? ($f[$k][$i] ?? '') : ($f[$k] ?? '');
            $out[] = ['name' => mb_substr(basename((string)$pick('name')), 0, 80), 'tmp_name' => (string)$pick('tmp_name'),
                'error' => (int)$err, 'size' => (int)$pick('size')];
        }
        return $out;
    }
}
