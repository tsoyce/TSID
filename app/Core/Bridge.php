<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Client;

/**
 * Совместное оформление «мостика» — экрана входа, открытого из портала (v4.1).
 *
 * Задача: человек сидел на site.com, нажал «войти» и попал на чужой домен.
 * Если экран выглядит совершенно иначе, это читается как подмена. Значит, на
 * мостике должны быть оба лица — и того сервиса, куда он идёт, и наше.
 *
 * ГРАНИЦА, которая не обсуждается: экран входа обязан **всегда оставаться
 * узнаваемо экраном SSO**. Портал добавляет своё лицо рядом с нашим, но не
 * вместо него.
 *
 * Отсюда следует главное техническое решение — **CSS от портала не принимается
 * ни в каком виде**. Заказчик предлагал забирать «css макета страницы
 * авторизации»; сделать так нельзя. Произвольный CSS позволяет спрятать наш
 * логотип, перекрыть строку с адресом, подменить подписи полей и нарисовать
 * поверх формы что угодно — то есть портал А оформился бы под портал Б и
 * собирал бы пароли от него. Провайдер идентичности такого позволять не может,
 * каким бы красивым ни выглядел результат.
 *
 * Вместо CSS портал отдаёт **параметры**: цвет, логотип, короткую подпись.
 * Результат тот же — «братья из одной оперы», — а поверхность для
 * злоупотреблений нулевая.
 */
final class Bridge
{
    /** Логотип портала: больше — не логотип. */
    private const MAX_LOGO_BYTES = 512 * 1024;

    /** Сторона квадрата, в который вписывается логотип портала. */
    private const LOGO_SIDE = 128;

    /**
     * Принять брендинг из манифеста.
     *
     * @param  array $branding сырой блок branding из чужого JSON
     * @return bool  приняли ли хоть что-то
     */
    public static function storeBranding(array $client, mixed $branding): bool
    {
        if (!is_array($branding)) return false;
        $fill = [];

        /* Цвет — только шестизначный hex. Никаких «rgb(...)», «var(--x)» и уж
           тем более произвольных строк: значение подставляется в атрибут style,
           и всё, что не проверено, там становится вектором. */
        $accent = trim((string)($branding['accent'] ?? ''));
        if (preg_match('~^#[0-9A-Fa-f]{6}$~', $accent)) {
            $fill['brand_accent'] = strtoupper($accent);
        }

        /* Подпись — короткий текст. Экранируется при выводе, как и всё
           остальное; длина ограничена, чтобы не ломать вёрстку карточки. */
        $tagline = trim((string)($branding['tagline'] ?? ''));
        if ($tagline !== '') $fill['brand_tagline'] = mb_substr($tagline, 0, 120);

        $logo = trim((string)($branding['logo'] ?? ''));
        if ($logo !== '') {
            $saved = self::fetchLogo($client, $logo);
            if ($saved !== null) $fill['brand_logo'] = $saved;
        }

        /* Тема экрана входа (v4.65). Поля проверяются здесь по одному; годится ли
           тема целиком — контраст, — считается при показе, в themeOf(). Портал
           убрал тему из манифеста — убираем и у себя: оформление, от которого он
           отказался, не должно жить у нас вечно. */
        $theme = [];
        if (DB::columnExists('clients', 'brand_theme')) {
            $theme = self::sanitize($branding, 'manifest');
            /* Один акцент живёт своей колонкой (brand_accent) — темой его делает
               portalTheme() при показе (v4.73), в brand_theme он не дублируется. */
            if (array_diff_key($theme, ['accent' => 1]) === []) $theme = [];
            $fill['brand_theme'] = $theme !== [] ? json_encode($theme, JSON_UNESCAPED_UNICODE) : null;
        }

        if ($fill === []) return false;
        Client::update((int)$client['id'], $fill);
        return array_diff_key($fill, ['brand_theme' => 1]) !== [] || $theme !== [];
    }

    /**
     * Скачать логотип портала и положить у себя.
     *
     * Ссылкой его брать нельзя по двум причинам. Первая: чужой адрес на нашей
     * странице раскрывает порталу IP каждого, кто открыл экран входа, — в том
     * числе тех, кто в этот портал не собирался. Вторая: картинку по ссылке
     * можно подменить в любой момент после того, как мы её посмотрели.
     *
     * @return string|null путь относительно storage/avatars или null
     */
    private static function fetchLogo(array $client, string $logo): ?string
    {
        /* Относительный путь достраиваем от базового адреса портала; абсолютный
           обязан вести на его же хост. Чужой хост в манифесте — это либо
           ошибка, либо попытка заставить нас ходить куда не надо. */
        $base = rtrim((string)($client['base_url'] ?? ''), '/');
        $url = str_starts_with($logo, 'http') ? $logo : $base . '/' . ltrim($logo, '/');

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $baseHost = strtolower((string)parse_url($base, PHP_URL_HOST));
        if ($host === '' || $host !== $baseHost) {
            Log::warning('bridge.logo_foreign_host', ['host' => $host, 'expected' => $baseHost]);
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,   // см. PortalManifest::download()
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'SSO/' . SSO_VERSION . ' (portal logo)',
        ]);
        $bytes = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($bytes) || $code !== 200 || strlen($bytes) > self::MAX_LOGO_BYTES) return null;

        /* Перекодируем через GD. Это не только приведение к одному формату:
           перекодирование убивает всё, что не является картинкой, — SVG со
           скриптом внутри, полиглот-файлы, метаданные. */
        $img = @imagecreatefromstring($bytes);
        if ($img === false) return null;

        $w = imagesx($img); $h = imagesy($img);
        $scale = min(self::LOGO_SIDE / max(1, $w), self::LOGO_SIDE / max(1, $h), 1);
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, (int)imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagealphablending($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        $dir = SSO_ROOT . '/storage/avatars/clients';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $name = 'brand-' . (int)$client['id'] . '.webp';
        imagewebp($dst, $dir . '/' . $name, 90);
        imagedestroy($dst);

        return 'clients/' . $name;
    }

    /**
     * Что показать на мостике для конкретного портала.
     *
     * @return array{logo: ?string, accent: ?string, tagline: ?string, name: string}|null
     */
    public static function brandingOf(?array $client): ?array
    {
        if (!$client) return null;
        $logo = (string)($client['brand_logo'] ?? '');
        $accent = (string)($client['brand_accent'] ?? '');
        $tagline = (string)($client['brand_tagline'] ?? '');
        /* Своё оформление в карточке портала задаёт и подпись над формой (v4.71):
           пустая там — значит, без подписи, а не «возьми из манифеста». */
        $look = self::look($client);
        if ($look['source'] === 'custom') $tagline = (string)($look['theme']['tagline'] ?? '');
        if ($logo === '' && $accent === '' && $tagline === '') return null;

        return [
            'name' => (string)$client['name'],
            /* Отдаём наш собственный адрес файла, а не то, что прислал портал */
            'logo' => $logo !== '' && is_file(SSO_ROOT . '/storage/avatars/' . $logo)
                ? base_url('/brand/' . (int)$client['id'] . '.webp') : null,
            /* Ещё раз проверяем формат цвета на выводе: колонка могла быть
               заполнена из бэкапа, миграции или чужой рукой в базе. */
            'accent' => preg_match('~^#[0-9A-Fa-f]{6}$~', $accent) ? $accent : null,
            'tagline' => $tagline !== '' ? $tagline : null,
        ];
    }

    /* ---- Тема экрана входа (v4.65, v4.71) ----------------------------------
       Владелец захотел, чтобы вход в Портфолио выглядел как Портфолио. Форма при этом
       остаётся нашей: пароль видит только SSO, работают ключи доступа, 2FA и
       единый вход. Оформление — это ПАРАМЕТРЫ: цвета, скругление, шрифт из
       нашего списка, раскладка, цитата, — а CSS собираем мы, и каждое значение
       в нём либо проверенный hex, либо наше число, либо наша строка. Знак и
       имя SSO в связке над формой и внизу панели убрать нельзя.

       Параметры приходят из трёх мест (v4.71):
         · из манифеста портала — как было с 4.65;
         · из раздела «Экран входа» в карточке портала — задаёт администратор;
         · из «Оформления сайта» — собственный экран входа установки.
       Какое из них действует для портала, решает переключатель в его
       карточке. Разбор у всех трёх один и тот же.                          */

    /** Откуда оформление портала: как прислал портал, своё или родное установки. */
    public const MODES = ['portal', 'custom', 'off'];

    /** Где панель: слева от формы, справа или её нет вовсе. */
    public const LAYOUTS = ['left', 'right', 'none'];

    /**
     * Шрифты, которые установка раздаёт сама.
     *
     * Портал называет шрифт КЛЮЧОМ, а не адресом: чужой адрес шрифта раскрыл бы
     * порталу каждого, кто открыл экран входа, и позволил бы подменить файл
     * после проверки — та же причина, по которой логотип хранится копией.
     * `system` — шрифт устройства: ничего не грузит и выглядит родным (v4.71).
     */
    public const FONTS = [
        'golos'    => "'Golos Text', system-ui, -apple-system, 'Segoe UI', sans-serif",
        'inter'    => "'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif",
        'system'   => "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
        'garamond' => "'EB Garamond', Georgia, 'Times New Roman', serif",
    ];

    /**
     * Готовые схемы (v4.78) — палитры обеих тем одним щелчком в редакторе.
     *
     * Владелец: «создать коллажи для цветовых схем». Подбирать фон, карточку
     * и текст двух тем с нормой контраста — работа не на пять минут; здесь
     * она сделана заранее, и каждую схему проверка прогоняет через тот же
     * разбор, что и ручную палитру: ни одна не теряет ни одной темы.
     *
     * @var array<string, array{0: string, 1: array<string, string>}>
     */
    public const PRESETS = [
        'terracotta' => ['Терракота', ['accent' => '#A53718', 'background' => '#F1ECDC', 'surface' => '#FFFFFF', 'text' => '#1E1812',
            'accent_dark' => '#E08A6B', 'background_dark' => '#17120E', 'surface_dark' => '#221B15', 'text_dark' => '#F3ECE2']],
        'sea'        => ['Море', ['accent' => '#0B6E99', 'background' => '#EEF5F8', 'surface' => '#FFFFFF', 'text' => '#0F1D24',
            'accent_dark' => '#5FB7DE', 'background_dark' => '#0B1419', 'surface_dark' => '#122028', 'text_dark' => '#E6F1F6']],
        'forest'     => ['Лес', ['accent' => '#2F6B3A', 'background' => '#EEF3EC', 'surface' => '#FFFFFF', 'text' => '#14201A',
            'accent_dark' => '#7CC48A', 'background_dark' => '#0E1510', 'surface_dark' => '#16201A', 'text_dark' => '#E7F0E8']],
        'plum'       => ['Слива', ['accent' => '#6D3AA8', 'background' => '#F4F0FA', 'surface' => '#FFFFFF', 'text' => '#1B1426',
            'accent_dark' => '#B792E8', 'background_dark' => '#130F1C', 'surface_dark' => '#1C1629', 'text_dark' => '#EFE9F8']],
        'graphite'   => ['Графит', ['accent' => '#1F2937', 'background' => '#F3F4F6', 'surface' => '#FFFFFF', 'text' => '#111827',
            'accent_dark' => '#A5B4FC', 'background_dark' => '#0B0D12', 'surface_dark' => '#151922', 'text_dark' => '#E5E7EB']],
        'amber'      => ['Янтарь', ['accent' => '#B45309', 'background' => '#FBF6EC', 'surface' => '#FFFFFF', 'text' => '#1F160A',
            'accent_dark' => '#F59E0B', 'background_dark' => '#15100A', 'surface_dark' => '#1F1810', 'text_dark' => '#F6EEDF']],
        'rose'       => ['Роза', ['accent' => '#BE185D', 'background' => '#FCF1F5', 'surface' => '#FFFFFF', 'text' => '#26101A',
            'accent_dark' => '#F472B6', 'background_dark' => '#170C11', 'surface_dark' => '#22131A', 'text_dark' => '#F8E8EF']],
        'sky'        => ['Небо', ['accent' => '#1D4ED8', 'background' => '#F5F5F4', 'surface' => '#FFFFFF', 'text' => '#1C1917',
            'accent_dark' => '#60A5FA', 'background_dark' => '#0C0A09', 'surface_dark' => '#1C1917', 'text_dark' => '#F5F5F4']],
        'mint'       => ['Мята', ['accent' => '#0F766E', 'background' => '#EEF7F5', 'surface' => '#FFFFFF', 'text' => '#0E1F1C',
            'accent_dark' => '#5EEAD4', 'background_dark' => '#0A1513', 'surface_dark' => '#11201D', 'text_dark' => '#E3F5F1']],
        'paper'      => ['Бумага', ['accent' => '#7C5A3A', 'background' => '#F4EFE6', 'surface' => '#FFFDF8', 'text' => '#2A2118',
            'accent_dark' => '#D4A373', 'background_dark' => '#16120D', 'surface_dark' => '#211B14', 'text_dark' => '#EFE6D8']],
        'neon'       => ['Неон', ['accent' => '#7C3AED', 'background' => '#F5F3FF', 'surface' => '#FFFFFF', 'text' => '#18122B',
            'accent_dark' => '#22D3EE', 'background_dark' => '#0A0A14', 'surface_dark' => '#13131F', 'text_dark' => '#E9E9F5']],
        'lime'       => ['Уголь и лайм', ['accent' => '#4D7C0F', 'background' => '#F4F7EC', 'surface' => '#FFFFFF', 'text' => '#171E0C',
            'accent_dark' => '#A3E635', 'background_dark' => '#0C0F08', 'surface_dark' => '#151A0F', 'text_dark' => '#EEF5E0']],
        /* v4.80: ещё восемь — от глубоких до пастельных. */
        'midnight'   => ['Полночь', ['accent' => '#3B5BDB', 'background' => '#EEF1F8', 'surface' => '#FFFFFF', 'text' => '#101526',
            'accent_dark' => '#8CA2FF', 'background_dark' => '#070A16', 'surface_dark' => '#0F1428', 'text_dark' => '#E4E8FA']],
        'sand'       => ['Песок', ['accent' => '#9A6B2F', 'background' => '#F7F1E6', 'surface' => '#FFFCF6', 'text' => '#2B2116',
            'accent_dark' => '#E0B573', 'background_dark' => '#17130D', 'surface_dark' => '#221C14', 'text_dark' => '#F3EADB']],
        'coffee'     => ['Кофе', ['accent' => '#6F4E37', 'background' => '#F3EEE9', 'surface' => '#FFFFFF', 'text' => '#24190F',
            'accent_dark' => '#C8A27F', 'background_dark' => '#140F0B', 'surface_dark' => '#1F1812', 'text_dark' => '#EFE5DB']],
        'lavender'   => ['Лаванда', ['accent' => '#7E57C2', 'background' => '#F6F3FB', 'surface' => '#FFFFFF', 'text' => '#1E1829',
            'accent_dark' => '#C4A8F0', 'background_dark' => '#110E18', 'surface_dark' => '#1B1725', 'text_dark' => '#F0EAF9']],
        'emerald'    => ['Изумруд', ['accent' => '#047857', 'background' => '#ECF7F2', 'surface' => '#FFFFFF', 'text' => '#0B1F18',
            'accent_dark' => '#34D399', 'background_dark' => '#07130F', 'surface_dark' => '#0E1E18', 'text_dark' => '#E2F5EC']],
        'coral'      => ['Коралл', ['accent' => '#C2410C', 'background' => '#FDF3EE', 'surface' => '#FFFFFF', 'text' => '#27150D',
            'accent_dark' => '#FB8C62', 'background_dark' => '#170E0A', 'surface_dark' => '#221610', 'text_dark' => '#F9E9E1']],
        'steel'      => ['Сталь', ['accent' => '#475569', 'background' => '#F1F4F7', 'surface' => '#FFFFFF', 'text' => '#0F172A',
            'accent_dark' => '#94A3B8', 'background_dark' => '#0B0F17', 'surface_dark' => '#131A26', 'text_dark' => '#E2E8F0']],
        'ocean'      => ['Океан', ['accent' => '#0369A1', 'background' => '#EAF4FA', 'surface' => '#FFFFFF', 'text' => '#0A1B26',
            'accent_dark' => '#38BDF8', 'background_dark' => '#06131C', 'surface_dark' => '#0C1E2B', 'text_dark' => '#E0F2FE']],
    ];

    /**
     * Базовые схемы (v4.80) — стоят первыми.
     *
     * «Базовая» — сброс к цветам установки. Контрастные — чёрное на белом и
     * белое на чёрном: у бело-чёрной тёмная тема ещё и тема по умолчанию
     * (светлой у неё нет по определению — светлая тема обязана быть светлой,
     * поэтому новому посетителю сразу показывается тёмная).
     *
     * @var array<string, array{0: string, 1: array<string, string>}>
     */
    public const BASE_PRESETS = [
        'base'    => ['Базовая', []],
        'mono'    => ['Чёрно-белая', ['accent' => '#111111', 'background' => '#FFFFFF', 'surface' => '#FFFFFF', 'text' => '#0A0A0A',
            'accent_dark' => '#F5F5F5', 'background_dark' => '#000000', 'surface_dark' => '#0E0E0E', 'text_dark' => '#FFFFFF',
            'theme_default' => 'light']],
        'inverse' => ['Бело-чёрная', ['accent' => '#111111', 'background' => '#FFFFFF', 'surface' => '#FFFFFF', 'text' => '#0A0A0A',
            'accent_dark' => '#FFFFFF', 'background_dark' => '#000000', 'surface_dark' => '#0A0A0A', 'text_dark' => '#FFFFFF',
            'theme_default' => 'dark']],
    ];

    /**
     * Конструктор (v4.79) — форма кнопок и полей, толщина шрифта, тени.
     *
     * Владелец: «можно больше настроек». Всё — выбором из списка, а не CSS:
     * любое значение здесь проверено заранее, и сломать экран входа им
     * нельзя. Пустое — «как у установки».
     *
     * @var array<string, list<string>>
     */
    public const BUILDER = [
        'weight_heading' => ['400', '500', '600', '700'],
        'weight_button'  => ['400', '500', '600', '700'],
        'button'         => ['fill', 'soft', 'outline'],
        'field_border'   => ['1', '1.5', '2'],
        'shadows'        => ['none', 'soft', 'strong'],
        'control'        => ['compact', 'normal', 'large'],
        /* v4.80: тема для тех, кто её ещё не выбирал; узор фона; вид карточек. */
        'theme_default'  => ['light', 'dark'],
        'pattern'        => ['grid', 'lines'],
        'card'           => ['flat', 'glass'],
    ];

    /** Высота полей и кнопок: обычная и малая, px. */
    private const CONTROL_H = ['compact' => [36, 30], 'normal' => [40, 32], 'large' => [48, 38]];

    /** Для текста интерфейса — только рубленые: подписи полей в 13px антиквой не читаются. */
    public const UI_FONTS = ['golos', 'inter', 'system'];

    /** Нормы — те же, что у всего интерфейса: текст 4.5, рамка поля 3. */
    private const AA_TEXT = 4.5;
    private const AA_CONTROL = 3.0;

    /** Цвета темы: светлые ключи и их тёмные пары. */
    public const COLORS = ['accent', 'background', 'surface', 'text'];

    /**
     * Родные цвета установки (tokens.css) — подсказки в пустых полях редактора.
     * Пустое поле значит «как у установки», и подсказка показывает, что это.
     */
    public const NATIVE = [
        'background' => '#F5F5F4', 'surface' => '#FFFFFF', 'text' => '#1C1917',
        'background_dark' => '#1C1917', 'surface_dark' => '#292524', 'text_dark' => '#FAFAF9',
    ];

    /**
     * Черновик предпросмотра: не сохранённое оформление, которое на этот
     * запрос подменяет сохранённое. client — id портала, null — сама установка.
     *
     * @var array{client: ?int, mode: string, theme: array, methods: ?list<string>}|null
     */
    private static ?array $draft = null;

    /**
     * Показать на этом запросе черновик вместо сохранённого (предпросмотр).
     *
     * Разбор тот же, что у сохранённого: предпросмотр не может показать того,
     * чего не покажет живой экран.
     *
     * @param ?list<string> $methods
     */
    public static function preview(?int $clientId, string $mode, array $theme, ?array $methods = null, ?array $pane = null,
        ?bool $socialFirst = null): void
    {
        self::$draft = [
            'client' => $clientId,
            'mode' => in_array($mode, self::MODES, true) ? $mode : 'portal',
            'theme' => $theme,
            'methods' => $methods,
            'pane' => $pane,
            'social_first' => $socialFirst,
        ];
    }

    /** Сервисы над формой (v4.86) — сохранённое или из черновика «Оформления сайта». */
    public static function socialFirst(?array $client): bool
    {
        if ($client === null && self::drafted(null) && self::$draft['social_first'] !== null) return self::$draft['social_first'];
        return Appearance::socialFirst();
    }

    /**
     * Заголовок и строка панели установки — сохранённые или из черновика.
     *
     * @return array{title: string, text: string}
     */
    public static function sitePane(): array
    {
        if (self::drafted(null) && self::$draft['pane'] !== null) return self::$draft['pane'];
        return [
            'title' => trim((string)Setting::get('branding.media_title', '')),
            'text' => trim((string)Setting::get('branding.media_text', '')),
        ];
    }

    /** Черновик относится к этому порталу (или к установке, если $client — null). */
    private static function drafted(?array $client): bool
    {
        return self::$draft !== null && self::$draft['client'] === ($client !== null ? (int)$client['id'] : null);
    }

    /** Откуда портал берёт оформление: portal, custom или off. */
    public static function mode(?array $client): string
    {
        if ($client === null) return 'off';
        if (self::drafted($client)) return self::$draft['mode'];
        $m = (string)($client['look_mode'] ?? '');
        return in_array($m, self::MODES, true) ? $m : 'portal';
    }

    /**
     * Только прошедшие проверку поля оформления.
     *
     * Через этот же разбор проходит и то, что уже лежит в базе, — на каждом
     * показе: колонка могла прийти из бэкапа или быть заполнена чужой рукой, а
     * её значения уходят в <style>.
     *
     * Кому что можно:
     *   manifest — портал о себе: цвета, скругление, шрифты, подкраска,
     *              раскладка, цитата. Подпись у него своей колонкой, а обложки
     *              он прислать не может: картинку по ссылке мы не берём;
     *   portal   — администратор о портале: всё это плюс подпись и обложка;
     *   site     — оформление самой установки: без цитаты и подписи — у панели
     *              установки свои заголовок и строка в «Оформлении сайта».
     *
     * @return array<string, string|int|bool>
     */
    public static function sanitize(mixed $b, string $scope = 'manifest'): array
    {
        if (!is_array($b)) return [];
        $str = static fn(mixed $v): string => is_string($v) ? trim($v) : '';
        $t = [];
        foreach (self::COLORS as $name) {
            foreach ([$name, $name . '_dark'] as $key) {
                $v = $str($b[$key] ?? null);
                if (preg_match('~^#[0-9A-Fa-f]{6}$~', $v)) $t[$key] = strtoupper($v);
            }
        }
        $r = $b['radius'] ?? null;
        if (is_int($r) && $r >= 0 && $r <= 16) $t['radius'] = $r;
        /* Шрифты из Google, установленные у нас (v4.78), — для оформления сайта
           и порталов, которое правит администратор. Манифест портала их не
           назовёт: ставить шрифты в чужую установку портал не вправе. */
        $own = $scope !== 'manifest';
        $font = strtolower($str($b['font'] ?? null));
        if (in_array($font, self::UI_FONTS, true) || ($own && FontLibrary::forUi($font))) $t['font'] = $font;
        $display = strtolower($str($b['font_display'] ?? null));
        if (array_key_exists($display, self::FONTS) || ($own && FontLibrary::byKey($display) !== null)) $t['font_display'] = $display;
        if (is_bool($b['wash'] ?? null)) $t['wash'] = $b['wash'];
        /* Конструктор (v4.79): только значения из списка. */
        foreach (self::BUILDER as $key => $allowed) {
            $v = $str($b[$key] ?? null);
            if (in_array($v, $allowed, true)) $t[$key] = $v;
        }
        $layout = strtolower($str($b['layout'] ?? null));
        if (in_array($layout, self::LAYOUTS, true)) $t['layout'] = $layout;
        if ($scope !== 'site') {
            foreach (['headline' => 160, 'headline_note' => 60] as $key => $max) {
                $v = (string)preg_replace('~\s+~u', ' ', $str($b[$key] ?? null));
                if ($v !== '') $t[$key] = mb_substr($v, 0, $max);
            }
        }
        if ($scope === 'portal') {
            $v = (string)preg_replace('~\s+~u', ' ', $str($b['tagline'] ?? null));
            if ($v !== '') $t['tagline'] = mb_substr($v, 0, 120);
            /* Обложка с 4.81 живёт отдельным набором (CoverSet), а не номером в теме. */
        }
        return $t;
    }

    /**
     * Оформление, которое прислал портал, — полная тема или один акцент (v4.73).
     *
     * До 4.73 один акцент темой не считался: его присылал каждый портал с v4.1,
     * и в 4.65 его нарочно не применяли — иначе кнопки прежних порталов молча
     * перекрасились бы после обновления. Живой случай 24.09: Архив и
     * Календарь событий присылают ровно акцент, владелец ждёт их цвета на
     * экране входа — и не видит. Порталу, который прислал акцент, нужен его
     * цвет; выключить можно режимом «Как у сайта».
     *
     * @return array<string, mixed>
     */
    public static function portalTheme(array $client): array
    {
        $t = self::sanitize(self::decode($client['brand_theme'] ?? null));
        if ($t !== []) return $t;
        $accent = (string)($client['brand_accent'] ?? '');
        return preg_match('~^#[0-9A-Fa-f]{6}$~', $accent) ? ['accent' => strtoupper($accent)] : [];
    }

    /** JSON из колонки или настройки — массивом; мусор — пустым. */
    private static function decode(mixed $json): array
    {
        if (is_array($json)) return $json;
        $raw = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        return is_array($raw) ? $raw : [];
    }

    /**
     * Оформление, которое действует на экране входа, и откуда оно.
     *
     * Портал без своего и без присланного, а также портал в режиме «родное»
     * выглядят как экран входа самой установки — если владелец его оформил.
     *
     * @return array{source: string, theme: array<string, string|int|bool>}
     *   source: custom — задано в карточке портала, portal — из манифеста,
     *   site — оформление установки, none — ничего не задано
     */
    public static function look(?array $client): array
    {
        if ($client !== null) {
            $mode = self::mode($client);
            if ($mode === 'custom') {
                $raw = self::drafted($client) ? self::$draft['theme'] : self::decode($client['look_theme'] ?? null);
                return ['source' => 'custom', 'theme' => self::overSite(self::sanitize($raw, 'portal'))];
            }
            if ($mode === 'portal') {
                $t = self::portalTheme($client);
                /* Чей дизайн главнее (v4.85) — решает «Оформление сайта»; тема
                   из карточки портала («Своё») задана здесь же руками и остаётся
                   как настроена. */
                $policy = Appearance::portalLook();
                if ($t !== [] && $policy !== 'only') {
                    return ['source' => 'portal', 'theme' => $policy === 'site' ? self::siteFirst($t) : self::overSite($t)];
                }
            }
        }
        $t = self::siteLook();
        return ['source' => $t !== [] ? 'site' : 'none', 'theme' => $t];
    }

    /** Оформление сайта из «Оформления сайта» — сохранённое или черновик его предпросмотра. */
    public static function siteLook(): array
    {
        return self::sanitize(self::drafted(null) ? self::$draft['theme'] : self::decode(Setting::get('look.theme')), 'site');
    }

    /**
     * Оформление портала поверх оформления сайта (v4.82).
     *
     * Владелец 25.09: «на форму авторизации сервисов не распространяются
     * настройки /admin/appearance». Своя тема портала заменяла тему сайта
     * целиком: Календарь событий присылает один акцент — и на его входе не было ни
     * скругления, ни шрифтов, ни стекла, ни раскладки сайта. Теперь заданное
     * порталом главнее, а чего он не задал — берётся у сайта.
     *
     * Цвета — по темам: фон, карточка и текст наследуются тройкой и только
     * если портал не задал из неё ничего — иначе палитра собралась бы из двух
     * и разошлась по контрасту. Акцент портала ложится на подложки сайта и
     * подгоняется под них тем же расчётом, что всегда; тёмный акцент, если
     * портал задал только светлый, выводится из его светлого, а не берётся у
     * сайта. Цитата и подпись панели — только портала: у сайта свои поля.
     */
    private static function overSite(array $portal): array
    {
        $site = self::siteLook();
        if ($site === []) return $portal;
        $out = $portal;
        foreach (['', '_dark'] as $sfx) {
            $group = ['background' . $sfx, 'surface' . $sfx, 'text' . $sfx];
            if (array_intersect_key($portal, array_flip($group)) !== []) continue;
            foreach ($group as $k) if (isset($site[$k])) $out[$k] = $site[$k];
        }
        if (!isset($portal['accent']) && isset($site['accent'])) $out['accent'] = $site['accent'];
        if (!isset($portal['accent_dark']) && !isset($portal['accent']) && isset($site['accent_dark'])) {
            $out['accent_dark'] = $site['accent_dark'];
        }
        foreach ($site as $k => $v) {
            if (in_array((string)preg_replace('~_dark$~', '', $k), self::COLORS, true)) continue;
            if (!array_key_exists($k, $out)) $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Дизайн сайта главнее (v4.85): шрифты, форма, карточки, узор, фон,
     * раскладка и тема для новых — как у сайта, даже если портал прислал
     * свои; чего сайт не задал — стоит по умолчанию, а не берётся у портала.
     * От портала — цвета (он узнаётся по ним), дополненные сайтом по правилам
     * overSite, и тексты панели: у сайта таких полей нет.
     */
    private static function siteFirst(array $portal): array
    {
        $own = static fn(string $k): bool => in_array((string)preg_replace('~_dark$~', '', $k), self::COLORS, true)
            || in_array($k, ['headline', 'headline_note', 'tagline'], true);
        $out = array_filter(self::overSite($portal), $own, ARRAY_FILTER_USE_KEY);
        foreach (self::siteLook() as $k => $v) {
            if (!$own($k)) $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Тема экрана входа для портала (или установки) — посчитанная и проверенная.
     *
     * Каждая из двух тем применяется целиком или не применяется вовсе: фон без
     * текста или текст без фона дали бы смесь двух палитр, в которой контраст
     * никто не считал. Не прошедшая тема не ломает вход — остаётся родная, а
     * причина видна администратору.
     *
     * @return array{source: string, light: ?array<string, string>, dark: ?array<string, string>,
     *   font: ?string, font_display: ?string, radius: ?int, wash: ?bool, layout: string,
     *   headline: ?string, headline_note: ?string, tagline: ?string, cover: ?string,
     *   why: list<string>}|null
     */
    public static function themeOf(?array $client): ?array
    {
        $look = self::look($client);
        $t = $look['theme'];
        if ($t === []) return null;
        $why = [];
        return [
            'source' => $look['source'],
            'light' => self::scheme($t, false, $why),
            'dark' => self::scheme($t, true, $why),
            'font' => $t['font'] ?? null,
            'font_display' => $t['font_display'] ?? null,
            'radius' => $t['radius'] ?? null,
            'wash' => $t['wash'] ?? null,
            'layout' => $t['layout'] ?? 'left',
            'headline' => $t['headline'] ?? null,
            'headline_note' => $t['headline_note'] ?? null,
            'tagline' => $t['tagline'] ?? null,
            'cover' => $t['cover'] ?? null,
            'builder' => array_intersect_key($t, self::BUILDER),
            'why' => $why,
        ];
    }

    /**
     * Цвета одной темы или null — с причиной в $why.
     *
     * Один акцент без палитры — тоже тема (v4.71): кнопки и ссылки портала в
     * его цвете на родном фоне установки. Контраст заливки и ссылки считается
     * против родных подложек — так же, как для акцента владельца.
     *
     * @param  list<string> $why
     * @return array<string, string>|null
     */
    private static function scheme(array $t, bool $dark, array &$why): ?array
    {
        $sfx = $dark ? '_dark' : '';
        $label = $dark ? t('Тёмная тема') : t('Светлая тема');
        $bg = $t['background' . $sfx] ?? null;
        $surface = $t['surface' . $sfx] ?? null;
        $text = $t['text' . $sfx] ?? null;
        $accent = $t['accent' . $sfx] ?? $t['accent'] ?? null;
        if ($bg === null && $surface === null && $text === null) {
            if ($accent === null) return null;
            $d = Palette::derive($accent, $dark);
            return ['ink' => $d['ink'], 'on-ink' => $d['on_ink'], 'ink-text' => $d['ink_text']];
        }
        if ($bg === null || $surface === null || $text === null) {
            $why[] = t(':scheme: нужны фон, карточка и текст вместе — часть палитры не применяется.',
                ['scheme' => $label]);
            return null;
        }
        /* Тёмная тема обязана быть тёмной, светлая — светлой: иначе человек с
           тёмной темой в системе получил бы слепящий экран, а вся арифметика
           ниже тянула бы производные цвета не в ту сторону. */
        if ((Palette::luminance($text) > Palette::luminance($bg)) !== $dark) {
            $why[] = $dark
                ? t(':scheme: текст должен быть светлее фона.', ['scheme' => $label])
                : t(':scheme: текст должен быть темнее фона.', ['scheme' => $label]);
            return null;
        }
        /* Текст лежит на трёх подложках: фон страницы, карточка и промежуточная
           между ними. Норма — на каждой, а не на самой удобной. */
        $surface2 = Palette::blend($surface, $bg, 0.5);
        $grounds = [$bg, $surface, $surface2];
        foreach ($grounds as $ground) {
            $c = Palette::contrast($text, $ground);
            if ($c < self::AA_TEXT) {
                $why[] = t(':scheme: текст :text на :ground — контраст :c при норме 4.5.',
                    ['scheme' => $label, 'text' => $text, 'ground' => $ground, 'c' => number_format($c, 2)]);
                return null;
            }
        }
        /* Акцент тёмной темы, которого не прислали, выводится из светлого, а без
           акцента вовсе — из акцента установки. Приглушённый текст, рамки и
           текст на кнопке — наши производные с той же гарантией контраста, что
           во всём интерфейсе. */
        $accent ??= (string)Setting::get('branding.accent', '#1D4ED8');
        $ink = Palette::deriveOn($accent, $grounds, $dark);
        return [
            'bg' => $bg, 'surface' => $surface, 'surface-2' => $surface2, 'text' => $text,
            'text-2' => Palette::fit(Palette::blend($text, $bg, 0.28), $grounds, self::AA_TEXT, $dark),
            'text-3' => Palette::fit(Palette::blend($text, $bg, 0.42), $grounds, self::AA_TEXT, $dark),
            'line' => Palette::blend($text, $surface, 0.88),
            'line-strong' => Palette::blend($text, $surface, 0.78),
            'control-line' => Palette::fit(Palette::blend($text, $surface, 0.6), $grounds, self::AA_CONTROL, $dark),
            'ink' => $ink['ink'], 'on-ink' => $ink['on_ink'], 'ink-text' => $ink['ink_text'],
        ];
    }

    /**
     * <style> темы для экрана входа или пустая строка.
     *
     * Ставится после акцента владельца и перекрывает его токены. Строк от
     * портала в нём нет: только проверенные hex, наши числа и наши стеки шрифтов.
     */
    public static function themeStyle(?array $client): string
    {
        $th = self::themeOf($client);
        if ($th === null) return '';
        $light = $th['light'] ?? [];
        $dark = $th['dark'] ?? [];
        if ($th['font'] !== null) $light['font-ui'] = self::fontStack($th['font']);
        if ($th['font_display'] !== null) $light['font-display'] = self::fontStack($th['font_display']);
        if ($th['radius'] !== null) {
            $r = $th['radius'];
            $light += ['radius-xs' => min($r, 5) . 'px', 'radius-s' => $r . 'px',
                'radius-m' => (int)round($r * 1.5) . 'px', 'radius-l' => $r * 2 . 'px', 'radius-xl' => $r * 3 . 'px'];
        }
        /* Подкраска фона — наша, из акцента; отказаться от неё можно только
           в обеих темах сразу. */
        if ($th['wash'] === false) $light['wash'] = $dark['wash'] = 'none';
        [$light, $dark, $rules] = self::builderStyle($th['builder'] ?? [], $light, $dark);

        /* Файлы шрифта из Google — с нашего домена, тем же стилем. */
        $css = FontLibrary::faces(array_filter([$th['font'], $th['font_display']]))
            . ($light !== [] ? ':root{' . self::declarations($light) . '}' : '')
            . ($dark !== [] ? ':root[data-theme="dark"]{' . self::declarations($dark) . '}' : '')
            . $rules;
        return $css !== '' ? '<style>' . $css . '</style>' : '';
    }

    /**
     * Конструктор — в токены и правила (v4.79). Правила только наши, из
     * заранее написанных строк: от выбора зависит лишь, какая из них встанет.
     *
     * @return array{0: array<string, string>, 1: array<string, string>, 2: string}
     */
    private static function builderStyle(array $b, array $light, array $dark): array
    {
        if (isset($b['weight_heading'])) $light['fw-heading'] = $b['weight_heading'];
        if (isset($b['weight_button'])) $light['fw-button'] = $b['weight_button'];
        if (isset($b['field_border'])) $light['control-border'] = $b['field_border'] . 'px';
        if (isset($b['control'])) {
            [$h, $hs] = self::CONTROL_H[$b['control']];
            $light['control-h'] = $h . 'px';
            $light['control-h-s'] = $hs . 'px';
        }
        if (($b['shadows'] ?? '') === 'none') {
            foreach (['shadow-1', 'shadow-2', 'shadow-3'] as $s) $light[$s] = $dark[$s] = 'none';
        } elseif (($b['shadows'] ?? '') === 'strong') {
            $light += ['shadow-1' => '0 1px 3px rgb(0 0 0 / .12)', 'shadow-2' => '0 2px 6px rgb(0 0 0 / .10), 0 10px 24px rgb(0 0 0 / .12)',
                'shadow-3' => '0 6px 12px rgb(0 0 0 / .12), 0 20px 48px rgb(0 0 0 / .18)'];
            $dark += ['shadow-1' => '0 1px 3px rgb(0 0 0 / .5)', 'shadow-2' => '0 2px 6px rgb(0 0 0 / .5), 0 10px 24px rgb(0 0 0 / .5)',
                'shadow-3' => '0 6px 12px rgb(0 0 0 / .55), 0 20px 48px rgb(0 0 0 / .65)'];
        }
        /* Узор фона (v4.80) — едва заметный, в цвет текста (--pat): в тёмной
           теме он сам становится светлым, контраст текста не трогает. */
        if (($b['pattern'] ?? '') === 'grid') {
            $light['pattern'] = 'repeating-linear-gradient(0deg,var(--pat) 0 1px,transparent 1px 28px),repeating-linear-gradient(90deg,var(--pat) 0 1px,transparent 1px 28px)';
        } elseif (($b['pattern'] ?? '') === 'lines') {
            $light['pattern'] = 'repeating-linear-gradient(-45deg,var(--pat) 0 1px,transparent 1px 14px)';
        }
        /* Главная кнопка: мягкая и контурная берут текст ссылки (--ink-text),
           у которого контраст на подложках уже проверен палитрой. */
        $rules = match ($b['button'] ?? '') {
            'soft' => '.btn-primary{background:var(--ink-soft);color:var(--ink-text);border-color:var(--ink-border);box-shadow:none}'
                . '.btn-primary:hover:not(:disabled){background:var(--ink-soft-2);box-shadow:none}'
                . '.btn-primary.is-loading::after{color:var(--ink-text)}',
            'outline' => '.btn-primary{background:transparent;color:var(--ink-text);border-color:var(--ink);box-shadow:none}'
                . '.btn-primary:hover:not(:disabled){background:var(--ink-soft);box-shadow:none}'
                . '.btn-primary.is-loading::after{color:var(--ink-text)}',
            default => '',
        };
        $rules .= match ($b['card'] ?? '') {
            'flat' => '.bento-card,.auth-card,.pcard{box-shadow:none}',
            /* Стекло: карточка полупрозрачна и размывает фон. Контраст текста
               проверен и на карточке, и на фоне, и между ними — стекло лежит там же. */
            'glass' => '.bento-card,.auth-card{background:color-mix(in srgb,var(--surface) 72%,transparent);'
                . '-webkit-backdrop-filter:blur(16px) saturate(1.4);backdrop-filter:blur(16px) saturate(1.4)}',
            default => '',
        };
        return [$light, $dark, $rules];
    }

    /** Тема для посетителя, который её ещё не выбирал: light, dark или null — как у устройства (v4.80). */
    public static function defaultTheme(?array $client): ?string
    {
        $v = (string)(self::look($client)['theme']['theme_default'] ?? '');
        return in_array($v, ['light', 'dark'], true) ? $v : null;
    }

    /** Стек CSS шрифта: из поставки или установленный из Google (v4.78). */
    public static function fontStack(string $key): string
    {
        return self::FONTS[$key] ?? FontLibrary::stack($key) ?? self::FONTS['system'];
    }

    /** @param array<string, string> $vars */
    private static function declarations(array $vars): string
    {
        $out = '';
        foreach ($vars as $name => $value) $out .= '--' . $name . ':' . $value . ';';
        return $out;
    }

    /** Где панель на экране входа: left, right или none. */
    public static function layout(?array $client): string
    {
        $l = (string)(self::look($client)['theme']['layout'] ?? 'left');
        return in_array($l, self::LAYOUTS, true) ? $l : 'left';
    }

    /**
     * Обложка панели портала — адрес первой картинки её набора или null.
     *
     * Только у своего оформления: портал картинку прислать не может, а
     * администратор загружает её здесь, и она перекодируется, как все снимки.
     * Сам набор — картинки, видео, YouTube — в CoverSet (v4.81).
     */
    public static function coverUrl(?array $client): ?string
    {
        if ($client === null || self::look($client)['source'] !== 'custom') return null;
        return CoverSet::firstImageUrl((int)$client['id']);
    }

    /** Файл обложки до 4.81 — его забирает CoverSet при первом обращении. */
    public static function coverPath(int $clientId): string
    {
        return SSO_ROOT . '/storage/avatars/clients/cover-' . $clientId . '.webp';
    }

    /**
     * Способы входа, оставленные этому порталу, или null — «как на всём сайте».
     *
     * Никогда не запирает: если после выключения способов на всём сайте от
     * выбора портала ничего не осталось, экран показывает то, что включено
     * везде. Пустой экран входа хуже любого лишнего способа.
     *
     * @return ?list<string>
     */
    public static function methodsOf(?array $client): ?array
    {
        if ($client === null) return null;
        $raw = self::drafted($client) ? self::$draft['methods'] : (
            ($client['login_methods'] ?? null) === null ? null : explode(',', (string)$client['login_methods']));
        if ($raw === null) return null;
        $only = array_values(array_intersect(Appearance::METHODS, array_map('trim', $raw)));
        $alive = array_filter($only, static fn(string $m): bool => Appearance::method($m)
            && ($m !== 'social' || \App\Social\Provider::enabled() !== []));
        return $alive === [] ? null : $only;
    }

    /** Показывать ли способ входа на экране этого портала. */
    public static function methodShown(?array $client, string $method): bool
    {
        /* Предпросмотр оформления сайта показывает состав из формы, ещё не
           сохранённый: снятый тумблер пропадает с экрана сразу. */
        if ($client === null && self::drafted(null) && self::$draft['methods'] !== null) {
            return in_array($method, self::$draft['methods'], true);
        }
        if (!Appearance::method($method)) return false;
        $only = self::methodsOf($client);
        return $only === null || in_array($method, $only, true);
    }

    /**
     * Палитра обеих тем из одного цвета (v4.71).
     *
     * Кнопка «Собрать из цвета» в редакторе: администратор знает цвет бренда,
     * но не обязан подбирать к нему фон, карточку и текст двух тем. Фон —
     * лёгкая подкраска тем же цветом, карточка — белая или глубокая тёмная,
     * текст дотягивается до контраста 7 на всех трёх подложках — с запасом
     * над нормой 4.5, чтобы приглушённые оттенки тоже прошли.
     *
     * @return array<string, string>
     */
    public static function paletteFrom(string $accent): array
    {
        $a = Palette::normalize($accent);
        $bg = Palette::blend('#FFFFFF', $a, 0.07);
        $surface = '#FFFFFF';
        $grounds = [$bg, $surface, Palette::blend($surface, $bg, 0.5)];
        $text = Palette::fit(Palette::blend('#16140F', $a, 0.10), $grounds, 7.0, false);

        $bgD = Palette::blend('#131315', $a, 0.06);
        $surfaceD = Palette::blend('#1C1C20', $a, 0.07);
        $groundsD = [$bgD, $surfaceD, Palette::blend($surfaceD, $bgD, 0.5)];
        $textD = Palette::fit(Palette::blend('#F4F3EF', $a, 0.05), $groundsD, 7.0, true);
        /* Акцент тёмной темы светлее: тёмно-красная кнопка на почти чёрном фоне
           не видна. Тянем до заметности заливки — 3:1. */
        $accentD = Palette::fit($a, $groundsD, 3.0, true);

        return [
            'accent' => $a, 'background' => $bg, 'surface' => $surface, 'text' => $text,
            'accent_dark' => $accentD, 'background_dark' => $bgD, 'surface_dark' => $surfaceD, 'text_dark' => $textD,
        ];
    }
}
