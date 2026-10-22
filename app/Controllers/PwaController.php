<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Palette;
use App\Core\Setting;

/**
 * PWA: манифест, иконки и service worker (v3.7).
 *
 * Всё генерируется на лету, а не лежит статикой, по одной причине: проект
 * white-label. Название, акцент и favicon задаёт владелец инсталляции, значит
 * и манифест с иконками обязаны следовать за ними — иначе на домашнем экране
 * окажется чужой логотип и чужое название у того, кто переименовал установку.
 *
 * Service worker намеренно скупой. Это точка входа в чужие аккаунты: SW может
 * перехватывать все запросы своего origin, поэтому он кэширует ТОЛЬКО
 * версионированную статику из /assets/ и никогда — HTML, OIDC-эндпоинты,
 * API и что-либо, зависящее от cookie.
 */
final class PwaController
{
    /** Версия кэша: меняется вместе с релизом, старые кэши подчищаются сами. */
    private const CACHE_VERSION = SSO_VERSION;

    /** GET /manifest.webmanifest */
    public function manifest(): never
    {
        $name = Setting::appName();
        $accent = branding_accent() ?: '#1D4ED8';
        $derived = Palette::derive($accent);

        header('Content-Type: application/manifest+json; charset=utf-8');
        /* Короткий кэш: сменил владелец акцент — иконка на домашнем экране
           обновится в течение часа, а не «когда-нибудь». */
        header('Cache-Control: public, max-age=3600');
        echo json_encode([
            'id' => '/',
            'name' => $name,
            'short_name' => pwa_short_name($name),
            'description' => branding_footer(),
            'lang' => lang(),
            'dir' => 'ltr',
            /* Вход — с профиля: это то, зачем открывают приложение. */
            'start_url' => '/profile',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => '#FFFFFF',
            'theme_color' => Palette::normalize($accent),
            'icons' => [
                ['src' => '/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                /* maskable — отдельные файлы: у них поля по 20% с каждой стороны,
                   иначе Android обрежет логотип по краям своей маской. */
                ['src' => '/pwa/maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => '/pwa/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => t('Безопасность'), 'url' => '/profile/security'],
                ['name' => t('Сервисы'), 'url' => '/profile/services'],
            ],
            /* Данные пользователя — не для публичного индекса витрин приложений */
            'prefer_related_applications' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * GET /pwa/{file} — иконки. Собираются из favicon инсталляции, а если его
     * нет — из акцента и буквы названия. Результат кэшируется на диск: GD на
     * каждый запрос при 512×512 — расточительство.
     */
    public function icon(string $file): never
    {
        if (!preg_match('~^(icon|maskable)-(192|512)\.png$~', $file, $m)) {
            \App\Core\ErrorPage::render(404);
        }
        $maskable = $m[1] === 'maskable';
        $size = (int)$m[2];

        $accent = Palette::normalize(branding_accent() ?: '#1D4ED8');
        $srcFile = branding_favicon_file();
        $srcStamp = $srcFile !== null ? (string)filemtime($srcFile) : 'none';
        /* Ключ кэша включает акцент и исходник: сменилось любое — картинка новая */
        $key = substr(sha1($accent . '|' . $srcStamp . '|' . Setting::appName() . '|' . self::CACHE_VERSION), 0, 12);
        $cache = SSO_ROOT . '/storage/cache/pwa-' . $m[1] . '-' . $size . '-' . $key . '.png';

        if (!is_file($cache)) {
            $png = $this->renderIcon($size, $accent, $maskable, $srcFile);
            if ($png === null) \App\Core\ErrorPage::render(404);
            if (!is_dir(dirname($cache))) @mkdir(dirname($cache), 0750, true);
            /* Старые версии того же размера убираем, чтобы кэш не разрастался */
            foreach ((array)glob(SSO_ROOT . '/storage/cache/pwa-' . $m[1] . '-' . $size . '-*.png') as $old) {
                @unlink((string)$old);
            }
            file_put_contents($cache, $png);
        }
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=604800');
        header('Content-Length: ' . (string)filesize($cache));
        readfile($cache);
        exit;
    }

    /**
     * GET /sw.js — service worker. Обязан отдаваться с корня: область действия
     * не может быть шире каталога самого скрипта.
     */
    public function serviceWorker(): never
    {
        header('Content-Type: application/javascript; charset=utf-8');
        /* SW не кэшируем: иначе обновление приложения застрянет на старом
           воркере, а он держит старую статику. */
        header('Cache-Control: no-cache');
        header('Service-Worker-Allowed: /');
        echo $this->swSource();
        exit;
    }

    /**
     * GET /offline — что показать, когда сети нет.
     *
     * Layout строго minimal. Эту страницу service worker кладёт в кэш при
     * установке, а установка идёт из уже открытой сессии — то есть с cookie.
     * Возьми она базовый layout с шапкой, в кэш попали бы имя и аватар
     * человека, и их увидел бы следующий, кто откроет приложение на этом
     * устройстве. В minimal нет ничего персонального.
     */
    public function offline(): never
    {
        \App\Core\View::render('offline', ['pageTitle' => t('Нет сети')], 'layouts/minimal');
    }

    /* ---------- Внутренности ---------- */

    /**
     * Рисуем иконку: квадрат в акцентном цвете, внутри favicon инсталляции.
     *
     * Если favicon не загружен, вместо букв рисуется точка — тот же знак «●»,
     * которым интерфейс подменяет ненайденный логотип (см. branding_logo_html).
     * Монограмма отпала не из вредности: масштабируемого шрифта в поставке нет
     * (шрифты лежат в woff2, GD их не читает, TTF тащить в репозиторий незачем),
     * а встроенный растровый — 9×15 пикселей, и растянутый до 512 он выглядит
     * как пиксельная лапша. Точка одинаково честно смотрится в любом размере.
     */
    private function renderIcon(int $size, string $accent, bool $maskable, ?string $src): ?string
    {
        if (!function_exists('imagecreatetruecolor')) return null;

        /* Рисуем в двойном размере и уменьшаем: GD не сглаживает ни залитый
           эллипс, ни дуги скругления, и в честном масштабе край круга и углы
           получаются лесенкой. Уменьшение с интерполяцией и есть сглаживание. */
        $im = self::draw($size * 2, $accent, $maskable, $src);
        $out = imagecreatetruecolor($size, $size);
        /* Смешивание выключено, иначе прозрачные углы смешаются с чёрным
           фоном нового полотна и станут чёрными. */
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $size * 2, $size * 2);
        imagedestroy($im);

        ob_start();
        imagepng($out, null, 9);
        $png = (string)ob_get_clean();
        imagedestroy($out);
        return $png;
    }

    /** Собственно отрисовка в заданном размере; вызывается с двойным. */
    private static function draw(int $size, string $accent, bool $maskable, ?string $src): \GdImage
    {
        $im = imagecreatetruecolor($size, $size);
        imagesavealpha($im, true);

        [$r, $g, $b] = self::rgb($accent);

        /* Заливка прозрачным работает только с выключенным смешиванием: с
           включённым «прозрачный поверх чёрного» даёт чёрный, и у иконки
           появлялись глухие чёрные углы вместо скругления. */
        imagealphablending($im, false);
        imagefilledrectangle($im, 0, 0, $size, $size, (int)imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);

        $bg = (int)imagecolorallocate($im, $r, $g, $b);
        /* Обычная иконка — со скруглением, maskable — во весь квадрат: её форму
           задаёт система, и свои углы дали бы двойное скругление. */
        if ($maskable) {
            imagefilledrectangle($im, 0, 0, $size, $size, $bg);
        } else {
            self::roundedRect($im, $size, (int)round($size * 0.22), $bg);
        }

        /* Безопасная зона maskable — вписанный круг диаметром 80% от стороны.
           Квадрат, влезающий в этот круг, имеет сторону 0.8/√2 ≈ 0.57 стороны
           иконки — отсюда и множители. */
        $inner = (int)round($size * ($maskable ? 0.56 : 0.72));
        $offset = (int)round(($size - $inner) / 2);

        if ($src !== null) {
            $logo = @imagecreatefrompng($src);
            if ($logo !== false) {
                imagealphablending($logo, true);
                imagecopyresampled($im, $logo, $offset, $offset, 0, 0,
                    $inner, $inner, imagesx($logo), imagesy($logo));
                imagedestroy($logo);
            }
        } else {
            /* Цвет знака — тот же, что Palette подбирает для текста на акценте
               во всём интерфейсе: контраст считается, а не угадывается. */
            [$tr, $tg, $tb] = self::rgb(Palette::derive($accent)['on_ink']);
            $fg = (int)imagecolorallocate($im, $tr, $tg, $tb);
            /* У maskable видимая площадь меньше, поэтому и точка мельче —
               относительно того, что реально видно, она того же размера. */
            $dot = (int)round($size * ($maskable ? 0.34 : 0.42));
            imagefilledellipse($im, (int)($size / 2), (int)($size / 2), $dot, $dot, $fg);
        }

        return $im;
    }

    /** Скруглённый квадрат: GD не умеет из коробки, собираем из частей. */
    private static function roundedRect(\GdImage $im, int $size, int $radius, int $color): void
    {
        $d = $radius * 2;
        imagefilledrectangle($im, $radius, 0, $size - $radius, $size, $color);
        imagefilledrectangle($im, 0, $radius, $size, $size - $radius, $color);
        imagefilledarc($im, $radius, $radius, $d, $d, 180, 270, $color, IMG_ARC_PIE);
        imagefilledarc($im, $size - $radius, $radius, $d, $d, 270, 360, $color, IMG_ARC_PIE);
        imagefilledarc($im, $radius, $size - $radius, $d, $d, 90, 180, $color, IMG_ARC_PIE);
        imagefilledarc($im, $size - $radius, $size - $radius, $d, $d, 0, 90, $color, IMG_ARC_PIE);
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        return [(int)hexdec(substr($h, 0, 2)), (int)hexdec(substr($h, 2, 2)), (int)hexdec(substr($h, 4, 2))];
    }

    /**
     * Исходник service worker'а.
     *
     * Правила, из которых он состоит, важнее самого кода:
     *  1. Кэшируется только /assets/** — версионированная статика с ?v=mtime.
     *  2. HTML не кэшируется никогда. Страница содержит персональные данные и
     *     CSRF-токен; отданная из кэша, она в лучшем случае покажет чужое имя,
     *     в худшем — сломает отправку формы просроченным токеном.
     *  3. Всё, что относится к протоколу и API, проходит мимо воркера целиком.
     *  4. Только GET. POST через SW не проходит — иначе вход и выход
     *     становятся заложниками кэша.
     */
    private function swSource(): string
    {
        $v = self::CACHE_VERSION;
        return <<<JS
/* SSO service worker · v{$v}
   Генерируется PwaController: версия кэша совпадает с версией приложения. */
const CACHE = 'sso-static-v{$v}';
const OFFLINE_URL = '/offline';

/* Пути, которые воркер не касается ни при каких условиях: протокол, API,
   аутентификация. Кэш здесь означал бы утечку или сломанный вход. */
const BYPASS = [
  '/authorize', '/token', '/userinfo', '/introspect', '/revoke', '/logout',
  '/login', '/register', '/recover', '/consent', '/social/', '/passkey/',
  '/fedcm/', '/api/', '/scim/', '/metrics', '/cron', '/install',
  '/.well-known/', '/jwks.json', '/manifest.webmanifest', '/sw.js',
];

self.addEventListener('install', (event) => {
  /* Офлайн-страницу кладём заранее: когда сеть пропадёт, скачать её уже нельзя */
  event.waitUntil(
    caches.open(CACHE).then((c) => c.add(new Request(OFFLINE_URL, { cache: 'reload' })))
      .catch(() => null).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;                    // правило 4

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;     // чужие домены — не наше дело
  if (BYPASS.some((p) => url.pathname.startsWith(p))) return;   // правило 3

  /* Статика: сначала кэш, иначе сеть с записью в кэш. Файлы версионированы
     через ?v=mtime, поэтому устаревшее содержимое отдать невозможно. */
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res.ok && res.type === 'basic') {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
        }
        return res;
      }))
    );
    return;
  }

  /* Всё остальное — только сеть (правило 2). Если сети нет и просят страницу,
     показываем офлайн-заглушку вместо браузерного «динозавра». */
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match(OFFLINE_URL).then((hit) => hit
        || new Response('Нет сети', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } })))
    );
  }
});
JS;
    }
}
