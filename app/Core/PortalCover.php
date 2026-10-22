<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Обложка портала на витрине главной (v4.76).
 *
 * Карточки витрины рисовались пустыми — штриховкой: у портала был только
 * значок, а обложку загрузить было негде. Задумано было иначе — SSO сам берёт
 * картинку у портала, как мессенджер берёт превью ссылки. Так и сделано:
 *
 * 1. своя картинка из карточки портала — главнее всего, сайт её не заменит;
 * 2. картинка, которую портал сам отдаёт для превью ссылок: og:image
 *    (twitter:image, image_src) на его главной. SSO забирает её раз в сутки
 *    и перекодирует в свою копию — чужой файл наружу не отдаётся;
 * 2½. снимок его главной сервисом снимков (v4.77), если картинки превью нет:
 *    у Портфолио, Архива и Календаря её не было, и витрина стояла в цветных
 *    карточках. Выключается на «Главной» в оформлении;
 * 3. обложка экрана входа портала, если её загружали;
 * 4. ничего — карточка в цветах портала с его знаком (рисует шаблон).
 *
 * Забирать картинку по адресу со страницы чужого сайта — это запрос нашего
 * сервера по ссылке, которую дал не администратор. Поэтому: страница — только
 * с адреса портала (его вписал администратор) и переходы только в пределах
 * этого же хоста; картинка — с того же хоста или по https с хоста, у которого
 * все адреса публичные, и соединение идёт на проверенный адрес (подмена DNS
 * между проверкой и запросом не проходит). Размер, тип и пиксели проверяются
 * до разбора: картинка-бомба не должна съесть память.
 */
final class PortalCover
{
    private const W = 1200;
    private const H = 900;
    private const QUALITY = 82;
    private const MAX_PAGE = 1048576;
    private const MAX_IMAGE = 5242880;
    /** Больше — отказ до разбора: 3000×3000 уже 36 МБ памяти GD. */
    private const MAX_PIXELS = 9000000;
    private const REFRESH = 86400;
    /** Сервис снимков по умолчанию: без ключа, до 50 снимков в день. */
    public const SHOTS_API = 'https://api.microlink.io/';

    public static function path(int $clientId): string
    {
        return SSO_ROOT . '/storage/avatars/clients/card-' . $clientId . '.webp';
    }

    /**
     * Что известно об обложке: откуда (upload | site | ''), когда, с какого
     * адреса, версия файла и почему не вышло в последний раз.
     *
     * @return array{source: string, at: string, url: string, v: string, why: string, tried: string}
     */
    public static function meta(int $clientId): array
    {
        $raw = json_decode((string)Setting::get('portal.cover.' . $clientId, ''), true);
        $raw = is_array($raw) ? $raw : [];
        return [
            'source' => (string)($raw['source'] ?? ''), 'at' => (string)($raw['at'] ?? ''),
            'url' => (string)($raw['url'] ?? ''), 'v' => (string)($raw['v'] ?? ''),
            'why' => (string)($raw['why'] ?? ''), 'tried' => (string)($raw['tried'] ?? ''),
        ];
    }

    private static function saveMeta(int $clientId, array $meta): void
    {
        Setting::set('portal.cover.' . $clientId, (string)json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Адрес картинки для карточки витрины — своя копия или обложка экрана входа; иначе null. */
    public static function url(array $client): ?string
    {
        $id = (int)$client['id'];
        if (is_file(self::path($id))) {
            $v = self::meta($id)['v'] ?: (string)filemtime(self::path($id));
            return base_url('/brand/card-' . $id . '.webp?v=' . rawurlencode($v));
        }
        return Bridge::coverUrl($client);
    }

    /** Цвет, в котором рисуется карточка без картинки: акцент портала или наш. */
    public static function tint(array $client): string
    {
        $accent = (string)(Bridge::portalTheme($client)['accent'] ?? '');
        return preg_match('~^#[0-9A-F]{6}$~i', $accent) ? strtoupper($accent)
            : (string)(Setting::get('branding.accent', '') ?: '#1D4ED8');
    }

    /** Своя картинка администратора. Сайт её уже не заменит. */
    public static function upload(int $clientId, string $tmp): bool
    {
        if (!self::fits((string)@file_get_contents($tmp))) return false;
        if (!LoginLook::storeImage($tmp, self::path($clientId), self::W, self::H, self::QUALITY)) return false;
        self::saveMeta($clientId, ['source' => 'upload', 'at' => now(), 'url' => '', 'v' => self::version($clientId), 'why' => '', 'tried' => '']);
        return true;
    }

    /** Убрать картинку: дальше снова решает сайт портала. */
    public static function remove(int $clientId): void
    {
        @unlink(self::path($clientId));
        Setting::delete('portal.cover.' . $clientId);
    }

    /**
     * Забрать картинку превью с главной портала.
     *
     * @return array{ok: bool, detail: string}
     */
    public static function fetchFromSite(array $client): array
    {
        $id = (int)$client['id'];
        $meta = self::meta($id);
        $fail = static function (string $why) use ($id, $meta): array {
            /* Прежняя картинка остаётся: сбой сайта на один день — не повод
               оставить витрину пустой. */
            self::saveMeta($id, ['why' => $why, 'tried' => now()] + $meta);
            return ['ok' => false, 'detail' => $why];
        };
        if ($meta['source'] === 'upload') return ['ok' => false, 'detail' => t('Стоит своя картинка — сайт её не заменяет.')];

        $base = rtrim((string)($client['base_url'] ?? ''), '/');
        $home = parse_url($base);
        $host = strtolower((string)($home['host'] ?? ''));
        if ($host === '' || !in_array($home['scheme'] ?? '', ['http', 'https'], true)) return $fail(t('У портала нет адреса сайта.'));

        $why = self::fromPreview($id, $base, $host);
        if ($why === '') return ['ok' => true, 'detail' => t('Картинка взята с сайта портала.')];

        /* Картинки превью нет или она не пришла — снимок главной (v4.77).
           У Портфолио, Архива и Календаря og:image нет вовсе, и витрина
           оставалась в цветных карточках: «так и не подтягивает проекты». */
        if (self::shotsOn()) {
            $shot = self::fromShot($id, $base . '/');
            if ($shot === '') return ['ok' => true, 'detail' => t('Снят снимок главной сайта портала.')];
            $why .= ' ' . t('Снимок главной тоже не вышел: :why', ['why' => $shot]);
        }
        return $fail($why);
    }

    /** Картинка превью ссылок с главной: '' — взята, иначе причина. */
    private static function fromPreview(int $id, string $base, string $host): string
    {
        $page = self::get($base . '/', 'text/html', self::MAX_PAGE, $host, true);
        if ($page['body'] === '') return t('Сайт портала не ответил: :why', ['why' => $page['error'] ?: 'HTTP ' . $page['code']]);

        $src = self::previewImage($page['body']);
        if ($src === '') return t('На главной портала нет картинки для превью ссылок (og:image).');
        $img = self::resolve($src, $page['url']);
        $same = strtolower((string)parse_url($img, PHP_URL_HOST)) === $host;
        if (!$same && !str_starts_with($img, 'https://')) return t('Картинка с чужого сайта — только по https.');

        $pic = self::get($img, 'image/*', self::MAX_IMAGE, $same ? $host : null, false);
        if ($pic['body'] === '') return t('Картинку не отдали: :why', ['why' => $pic['error'] ?: 'HTTP ' . $pic['code']]);
        return self::keep($id, $pic['body'], 'site', $img);
    }

    /**
     * Снимок главной портала сервисом снимков: '' — снят, иначе причина.
     *
     * Сервису уходит только публичный адрес главной. Адрес самого сервиса —
     * настройка установки, а не данные портала, поэтому ему верим как адресу
     * портала; картинку он отдаёт со своего хоста или по https с публичного.
     */
    private static function fromShot(int $id, string $home): string
    {
        /* Проверки подставляют свой сервис переменной окружения — это не
           настройка установки: задать её в интерфейсе было бы негде (так и
           сказала ревизия настроек). */
        $api = (string)(getenv('SSO_SHOTS_API') ?: getenv('TSOY_SHOTS_API') ?: self::SHOTS_API);
        $apiHost = strtolower((string)parse_url($api, PHP_URL_HOST));
        $q = $api . (str_contains($api, '?') ? '&' : '?') . http_build_query([
            'url' => $home, 'screenshot' => 'true', 'meta' => 'false',
            'viewport.width' => 1280, 'viewport.height' => 800, 'viewport.deviceScaleFactor' => 1,
        ]);
        $res = self::get($q, 'application/json', self::MAX_PAGE, $apiHost, false, 30);
        $j = json_decode($res['body'], true);
        $img = is_array($j) ? (string)($j['data']['screenshot']['url'] ?? '') : '';
        if ($img === '') {
            return is_array($j) && isset($j['message']) ? mb_substr((string)$j['message'], 0, 120)
                : ($res['error'] ?: 'HTTP ' . $res['code']);
        }
        $ih = strtolower((string)parse_url($img, PHP_URL_HOST));
        $pic = self::get($img, 'image/*', self::MAX_IMAGE, $ih === $apiHost ? $apiHost : null, false, 20);
        if ($pic['body'] === '') return t('Картинку не отдали: :why', ['why' => $pic['error'] ?: 'HTTP ' . $pic['code']]);
        return self::keep($id, $pic['body'], 'shot', $home);
    }

    /** Проверить, перекодировать и запомнить: '' — готово, иначе причина. */
    private static function keep(int $id, string $bytes, string $source, string $url): string
    {
        if (!self::fits($bytes)) return t('Это не картинка или она слишком большая.');
        $tmp = (string)tempnam(sys_get_temp_dir(), 'cover');
        file_put_contents($tmp, $bytes);
        $ok = LoginLook::storeImage($tmp, self::path($id), self::W, self::H, self::QUALITY);
        @unlink($tmp);
        if (!$ok) return t('Картинку не удалось перекодировать.');
        self::saveMeta($id, ['source' => $source, 'at' => now(), 'url' => $url, 'v' => self::version($id), 'why' => '', 'tried' => now()]);
        Log::info('portal.cover.fetched', ['client' => $id, 'source' => $source, 'host' => (string)parse_url($url, PHP_URL_HOST)]);
        return '';
    }

    /** Снимать ли главную портала, если картинки превью нет (настройка «Главной»). */
    public static function shotsOn(): bool
    {
        return Setting::get('appearance.cover_shots', '1') !== '0';
    }

    /**
     * Порталам витрины — свежие картинки с их сайтов. Раз в сутки на портал,
     * после неудачи — тоже через сутки: сайт без og:image не должен
     * опрашиваться каждый час вечно.
     */
    public static function refreshDue(int $limit = 3): string
    {
        $done = 0;
        foreach (\App\Models\Client::publicOnes() as $c) {
            if ($done >= $limit) break;
            $m = self::meta((int)$c['id']);
            if ($m['source'] === 'upload') continue;
            $last = $m['tried'] ?: $m['at'];
            if ($last !== '' && strtotime($last . ' UTC') > time() - self::REFRESH) continue;
            self::fetchFromSite($c);
            $done++;
        }
        return 'обложек проверено: ' . $done;
    }

    /** Картинка для превью ссылок в разметке страницы — первая из известных. */
    public static function previewImage(string $html): string
    {
        $head = substr($html, 0, 262144);
        $found = [];
        if (preg_match_all('~<meta\b[^>]*>~i', $head, $tags)) {
            foreach ($tags[0] as $tag) {
                $key = strtolower(self::attr($tag, 'property') ?: self::attr($tag, 'name'));
                $val = trim(html_entity_decode(self::attr($tag, 'content'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($val !== '' && !isset($found[$key])) $found[$key] = $val;
            }
        }
        if (preg_match_all('~<link\b[^>]*>~i', $head, $links)) {
            foreach ($links[0] as $tag) {
                if (strtolower(self::attr($tag, 'rel')) === 'image_src' && !isset($found['image_src'])) {
                    $found['image_src'] = trim(html_entity_decode(self::attr($tag, 'href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
            }
        }
        foreach (['og:image:secure_url', 'og:image', 'og:image:url', 'twitter:image', 'twitter:image:src', 'image_src'] as $k) {
            if (($found[$k] ?? '') !== '') return $found[$k];
        }
        return '';
    }

    private static function attr(string $tag, string $name): string
    {
        return preg_match('~\s' . preg_quote($name, '~') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $tag, $m)
            ? ($m[2] !== '' ? $m[2] : ($m[3] ?? '') . ($m[4] ?? '')) : '';
    }

    /** Адрес со страницы — абсолютным: «//host/x», «/x», «x» и полный. */
    public static function resolve(string $ref, string $page): string
    {
        if (preg_match('~^https?://~i', $ref)) return $ref;
        $p = parse_url($page);
        $scheme = ($p['scheme'] ?? 'https');
        if (str_starts_with($ref, '//')) return $scheme . ':' . $ref;
        $origin = $scheme . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($ref, '/')) return $origin . $ref;
        $dir = preg_replace('~/[^/]*$~', '/', (string)($p['path'] ?? '/')) ?: '/';
        return $origin . $dir . $ref;
    }

    /** Похоже ли это на картинку разумного размера — до того, как GD её развернёт. */
    private static function fits(string $bytes): bool
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE) return false;
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) return false;
        return $info[0] > 0 && $info[1] > 0 && $info[0] * $info[1] <= self::MAX_PIXELS;
    }

    private static function version(int $clientId): string
    {
        return substr((string)@sha1_file(self::path($clientId)), 0, 10);
    }

    /**
     * GET с ограничениями: размер, время, переходы — только в пределах
     * $sameHost (или никаких), и соединение на проверенный адрес для чужого
     * хоста.
     *
     * @return array{code: int, body: string, url: string, error: string}
     */
    private static function get(string $url, string $accept, int $max, ?string $sameHost, bool $follow, int $timeout = 6): array
    {
        for ($hop = 0; $hop < 4; $hop++) {
            $p = parse_url($url);
            $host = strtolower((string)($p['host'] ?? ''));
            $scheme = strtolower((string)($p['scheme'] ?? ''));
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') return self::none($url, t('неверный адрес'));
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_MAXFILESIZE => $max,
                CURLOPT_USERAGENT => 'SSO/' . SSO_VERSION . ' (portal cover)',
                CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
                CURLOPT_NOPROGRESS => false,
                /* Content-Length может не быть — обрываем по факту. */
                CURLOPT_PROGRESSFUNCTION => static fn($c, $dlTotal, $dl): int => $dl > $max ? 1 : 0,
            ];
            if ($host !== $sameHost) {
                /* Чужой хост: только https и только публичные адреса, и
                   соединение — на тот адрес, что проверен. */
                if ($scheme !== 'https') return self::none($url, t('только https'));
                $ips = @gethostbynamel($host) ?: [];
                if ($ips === []) return self::none($url, t('имя сайта не находится'));
                foreach ($ips as $ip) {
                    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return self::none($url, t('адрес во внутренней сети'));
                    }
                }
                $opts[CURLOPT_RESOLVE] = [$host . ':' . ($p['port'] ?? 443) . ':' . $ips[0]];
            }
            $head = '';
            $opts[CURLOPT_HEADERFUNCTION] = static function ($c, string $line) use (&$head): int {
                if (strlen($head) < 8192) $head .= $line;
                return strlen($line);
            };
            $ch = curl_init($url);
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            /* Причину читает администратор в карточке — на его языке, а не
               строкой curl («Resolving timed out after 3000 milliseconds»). */
            $error = match (true) {
                $errno === 0 => '',
                $errno === CURLE_COULDNT_RESOLVE_HOST, str_starts_with($error, 'Resolving') => t('имя сайта не находится'),
                $errno === CURLE_OPERATION_TIMEDOUT => t('не ответил вовремя'),
                $errno === CURLE_COULDNT_CONNECT => t('не принимает соединения'),
                /* 35 — рукопожатие, 51 и 60 — сертификат не прошёл проверку
                   (имена констант у сборок PHP разные, числа — одни). */
                in_array($errno, [35, 51, 60], true) => t('ошибка сертификата https'),
                $errno === CURLE_ABORTED_BY_CALLBACK, $errno === CURLE_FILESIZE_EXCEEDED => t('слишком большой ответ'),
                default => $error,
            };
            if ($follow && $code >= 300 && $code < 400 && preg_match('~^location:\s*(\S+)~mi', $head, $m)) {
                $next = self::resolve(trim($m[1]), $url);
                if (strtolower((string)parse_url($next, PHP_URL_HOST)) !== $sameHost) return self::none($url, t('переадресация на другой сайт'));
                $url = $next;
                continue;
            }
            if (!is_string($body) || $code !== 200 || strlen($body) > $max) {
                return ['code' => $code, 'body' => '', 'url' => $url, 'error' => $error];
            }
            return ['code' => $code, 'body' => $body, 'url' => $url, 'error' => ''];
        }
        return self::none($url, t('слишком много переадресаций'));
    }

    /** @return array{code: int, body: string, url: string, error: string} */
    private static function none(string $url, string $why): array
    {
        return ['code' => 0, 'body' => '', 'url' => $url, 'error' => $why];
    }
}
