<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Шрифты из Google Fonts — у себя (v4.78).
 *
 * Владелец: «подвязать Google Fonts, чтобы можно было больше шрифтов». В
 * редакторе оформления было четыре шрифта, зашитых в поставку. Теперь —
 * весь каталог Google с кириллицей (около трёхсот семейств), но шрифт не
 * грузится с Google у каждого посетителя: при установке SSO один раз
 * скачивает его файлы к себе и отдаёт со своего домена. Браузеры людей к
 * Google не ходят (адреса посетителей не уходят третьей стороне), политика
 * безопасности не открывается (font-src остаётся 'self').
 *
 * Берём только кириллицу и латиницу (с расширениями) и начертания 400–700:
 * интерфейсу больше не нужно, а каждый лишний файл — вес страницы входа.
 */
final class FontLibrary
{
    private const CATALOG = 'https://fonts.google.com/metadata/fonts';
    private const CSS_API = 'https://fonts.googleapis.com/css2';
    private const SUBSETS = ['cyrillic', 'cyrillic-ext', 'latin', 'latin-ext'];
    private const WEIGHTS = [400, 500, 600, 700];
    private const CATALOG_TTL = 604800;
    private const MAX_FONT = 1048576;
    /** Современный браузер: иначе API отдаёт ttf вместо woff2. */
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
    /** Запасной стек по категории — пока файл шрифта не пришёл. */
    private const FALLBACK = [
        'Sans Serif' => "system-ui, -apple-system, 'Segoe UI', sans-serif",
        'Serif' => "Georgia, 'Times New Roman', serif",
        'Display' => "system-ui, sans-serif",
        'Handwriting' => "cursive",
        'Monospace' => "ui-monospace, 'Cascadia Mono', monospace",
    ];

    public static function dir(): string
    {
        return SSO_ROOT . '/storage/fonts';
    }

    /** Ключ установленного шрифта в оформлении: «g-manrope». */
    public static function key(string $slug): string
    {
        return 'g-' . $slug;
    }

    public static function slugOf(string $family): string
    {
        return trim((string)preg_replace('~[^a-z0-9]+~', '-', strtolower($family)), '-');
    }

    /**
     * Установленные шрифты: slug => {family, category, faces: [{file, weight, range}]}.
     *
     * @return array<string, array{family: string, category: string, faces: list<array{file: string, weight: string, range: string}>}>
     */
    public static function installed(): array
    {
        $raw = json_decode((string)Setting::get('fonts.installed', ''), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $slug => $f) {
            if (!is_string($slug) || !preg_match('~^[a-z0-9-]{1,60}$~', $slug) || !is_array($f)) continue;
            $out[$slug] = [
                'family' => (string)($f['family'] ?? $slug),
                'category' => (string)($f['category'] ?? 'Sans Serif'),
                'faces' => array_values(array_filter((array)($f['faces'] ?? []), 'is_array')),
            ];
        }
        return $out;
    }

    /** Установленный шрифт по ключу оформления («g-manrope») или null. */
    public static function byKey(string $key): ?array
    {
        if (!str_starts_with($key, 'g-')) return null;
        return self::installed()[substr($key, 2)] ?? null;
    }

    /** Стек CSS для ключа оформления или null, если шрифта нет. */
    public static function stack(string $key): ?string
    {
        $f = self::byKey($key);
        if ($f === null) return null;
        return "'" . str_replace(["'", '\\'], '', $f['family']) . "', " . (self::FALLBACK[$f['category']] ?? self::FALLBACK['Sans Serif']);
    }

    /** Годится ли для текста интерфейса: только рубленые, как и шрифты поставки. */
    public static function forUi(string $key): bool
    {
        $f = self::byKey($key);
        return $f !== null && $f['category'] === 'Sans Serif';
    }

    /** @font-face для перечисленных ключей (только установленных). */
    public static function faces(array $keys): string
    {
        $css = '';
        foreach (array_unique($keys) as $key) {
            $f = is_string($key) ? self::byKey($key) : null;
            if ($f === null) continue;
            $slug = substr((string)$key, 2);
            $family = str_replace(["'", '\\'], '', $f['family']);
            foreach ($f['faces'] as $face) {
                if (!preg_match('~^[a-f0-9]{16}\.woff2$~', (string)($face['file'] ?? ''))) continue;
                $css .= "@font-face{font-family:'" . $family . "';font-style:normal;font-weight:" . preg_replace('~[^0-9 ]~', '', (string)$face['weight'])
                    . ";font-display:swap;src:url(/fonts/" . $slug . '/' . $face['file'] . ") format('woff2');"
                    . 'unicode-range:' . preg_replace('~[^0-9A-Fa-fU+?, -]~', '', (string)$face['range']) . '}';
            }
        }
        return $css;
    }

    /**
     * Каталог Google с кириллицей: [family, category, popularity] — из кеша
     * на неделю. Без сети и без кеша — пустой список, установленные шрифты
     * работают и так.
     *
     * @return list<array{family: string, category: string, popularity: int}>
     */
    public static function catalog(bool $refresh = false): array
    {
        $cache = SSO_ROOT . '/storage/cache/google-fonts.json';
        if (!$refresh && is_file($cache) && filemtime($cache) > time() - self::CATALOG_TTL) {
            $list = json_decode((string)file_get_contents($cache), true);
            if (is_array($list)) return $list;
        }
        return self::fetchCatalog($cache);
    }

    /**
     * Каталог для страницы оформления — только из кеша (v4.78): 2,7 МБ от
     * Google — это секунды, а страница открывается сразу. Нет кеша или он
     * устарел — свежий качается после ответа, и при следующем открытии
     * подсказки уже есть.
     *
     * @return list<array{family: string, category: string, popularity: int}>
     */
    public static function catalogCached(): array
    {
        $cache = SSO_ROOT . '/storage/cache/google-fonts.json';
        $list = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
        if (!is_file($cache) || filemtime($cache) <= time() - self::CATALOG_TTL) {
            Defer::after(static fn() => self::catalog(true));
        }
        return is_array($list) ? $list : [];
    }

    /** @return list<array{family: string, category: string, popularity: int}> */
    private static function fetchCatalog(string $cache): array
    {
        $raw = self::http(self::CATALOG, 8388608, 20);
        $j = json_decode((string)preg_replace('~^\)\]\}\'\s*~', '', $raw), true);
        $list = [];
        foreach ((array)($j['familyMetadataList'] ?? []) as $f) {
            if (!is_array($f) || !in_array('cyrillic', (array)($f['subsets'] ?? []), true)) continue;
            $family = (string)($f['family'] ?? '');
            if ($family === '' || !preg_match('~^[A-Za-z0-9 ]{1,60}$~', $family)) continue;
            $list[] = ['family' => $family, 'category' => (string)($f['category'] ?? 'Sans Serif'), 'popularity' => (int)($f['popularity'] ?? 9999)];
        }
        if ($list === []) {
            $old = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
            return is_array($old) ? $old : [];
        }
        usort($list, static fn(array $a, array $b): int => $a['popularity'] <=> $b['popularity']);
        @mkdir(dirname($cache), 0750, true);
        file_put_contents($cache, (string)json_encode($list, JSON_UNESCAPED_UNICODE));
        return $list;
    }

    /**
     * Установить шрифт из каталога.
     *
     * @return array{ok: bool, detail: string, key?: string}
     */
    public static function install(string $family): array
    {
        $family = trim($family);
        $entry = null;
        foreach (self::catalog() as $f) {
            if (strcasecmp($f['family'], $family) === 0) { $entry = $f; break; }
        }
        if ($entry === null) return ['ok' => false, 'detail' => t('Шрифта «:f» нет в каталоге Google с кириллицей.', ['f' => $family])];
        $slug = self::slugOf($entry['family']);
        if (isset(self::installed()[$slug])) return ['ok' => true, 'detail' => t('Шрифт «:f» уже установлен.', ['f' => $entry['family']]), 'key' => self::key($slug)];

        /* Начертания 400–700; у шрифта без какого-то из них API отвечает
           ошибкой — тогда просим только 400. */
        $css = '';
        foreach ([implode(';', self::WEIGHTS), '400'] as $w) {
            $css = self::http(self::CSS_API . '?family=' . rawurlencode($entry['family']) . ':wght@' . $w . '&display=swap', 262144, 10, self::UA);
            if (str_contains($css, '@font-face')) break;
        }
        if (!str_contains($css, '@font-face')) return ['ok' => false, 'detail' => t('Google Fonts не отдал шрифт «:f».', ['f' => $entry['family']])];

        $faces = [];
        $files = [];
        preg_match_all('~/\*\s*([a-z-]+)\s*\*/\s*@font-face\s*\{(.*?)\}~s', $css, $blocks, PREG_SET_ORDER);
        foreach ($blocks as [, $subset, $body]) {
            if (!in_array($subset, self::SUBSETS, true)) continue;
            if (!preg_match('~font-weight:\s*([0-9 ]+);~', $body, $wm) || !preg_match('~url\((https://fonts\.gstatic\.com/[^)\s]+\.woff2)\)~', $body, $um)) continue;
            if (preg_match('~font-style:\s*italic~', $body)) continue;
            $range = preg_match('~unicode-range:\s*([^;]+);~', $body, $rm) ? trim($rm[1]) : '';
            $file = substr(sha1($um[1]), 0, 16) . '.woff2';
            $files[$file] = $um[1];
            $faces[] = ['file' => $file, 'weight' => trim($wm[1]), 'range' => $range];
        }
        if ($faces === []) return ['ok' => false, 'detail' => t('В ответе Google Fonts нет файлов с кириллицей и латиницей.')];

        $dir = self::dir() . '/' . $slug;
        @mkdir($dir, 0750, true);
        foreach ($files as $file => $url) {
            $bytes = self::http($url, self::MAX_FONT, 15);
            /* woff2 начинается с «wOF2» — остальное не шрифт. */
            if (!str_starts_with($bytes, 'wOF2')) {
                self::wipe($slug);
                return ['ok' => false, 'detail' => t('Файл шрифта «:f» не скачался.', ['f' => $entry['family']])];
            }
            file_put_contents($dir . '/' . $file, $bytes);
        }
        $all = self::installed();
        $all[$slug] = ['family' => $entry['family'], 'category' => $entry['category'], 'faces' => $faces];
        Setting::set('fonts.installed', (string)json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Setting::flush();
        Log::info('font.installed', ['family' => $entry['family'], 'files' => count($files)]);
        return ['ok' => true, 'detail' => t('Шрифт «:f» установлен — выберите его в оформлении.', ['f' => $entry['family']]), 'key' => self::key($slug)];
    }

    /**
     * Где шрифт используется: «сайт» и названия порталов. Удалять такой нельзя
     * — экран входа молча сменил бы шрифт на запасной.
     *
     * @return list<string>
     */
    public static function usedBy(string $slug): array
    {
        $key = self::key($slug);
        $where = [];
        $site = json_decode((string)Setting::get('look.theme', ''), true);
        if (is_array($site) && in_array($key, [$site['font'] ?? null, $site['font_display'] ?? null], true)) $where[] = t('оформление сайта');
        foreach (\App\Models\Client::all() as $c) {
            $t = json_decode((string)($c['look_theme'] ?? ''), true);
            if (is_array($t) && in_array($key, [$t['font'] ?? null, $t['font_display'] ?? null], true)) $where[] = (string)$c['name'];
        }
        return $where;
    }

    /** @return array{ok: bool, detail: string} */
    public static function remove(string $slug): array
    {
        $all = self::installed();
        if (!isset($all[$slug])) return ['ok' => false, 'detail' => t('Такого шрифта нет.')];
        $used = self::usedBy($slug);
        if ($used !== []) {
            return ['ok' => false, 'detail' => t('Шрифт «:f» используется: :where. Сначала выберите там другой.', ['f' => $all[$slug]['family'], 'where' => implode(', ', $used)])];
        }
        unset($all[$slug]);
        Setting::set('fonts.installed', (string)json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Setting::flush();
        self::wipe($slug);
        return ['ok' => true, 'detail' => t('Шрифт убран.')];
    }

    private static function wipe(string $slug): void
    {
        $dir = self::dir() . '/' . $slug;
        foreach ((array)glob($dir . '/*.woff2') as $f) @unlink((string)$f);
        @rmdir($dir);
    }

    /** Путь к файлу шрифта для отдачи или null — имя разбирается по шаблону, не подставляется. */
    public static function file(string $slug, string $file): ?string
    {
        if (!preg_match('~^[a-z0-9-]{1,60}$~', $slug) || !preg_match('~^[a-f0-9]{16}\.woff2$~', $file)) return null;
        $path = self::dir() . '/' . $slug . '/' . $file;
        return is_file($path) ? $path : null;
    }

    /** GET к Google: только https, без переходов, с потолком размера. */
    private static function http(string $url, int $max, int $timeout, ?string $ua = null): string
    {
        /* Проверки подставляют свой адрес вместо Google переменной окружения —
           не настройкой: задать её в интерфейсе было бы негде. */
        $base = (string)(getenv('SSO_FONTS_BASE') ?: getenv('TSOY_FONTS_BASE') ?: '');
        if ($base !== '') $url = (string)preg_replace('~^https://(fonts\.google\.com|fonts\.googleapis\.com|fonts\.gstatic\.com)~', rtrim($base, '/') . '/$1', $url);
        if (!preg_match('~^https?://~', $url)) return '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | ($base !== '' ? CURLPROTO_HTTP : 0),
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_MAXFILESIZE => $max,
            CURLOPT_USERAGENT => $ua ?? ('SSO/' . SSO_VERSION),
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn($c, $t, $d): int => $d > $max ? 1 : 0,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return is_string($body) && $code === 200 && strlen($body) <= $max ? $body : '';
    }
}
