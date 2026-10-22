<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Кириллица → латиница.
 *
 * Проект живёт в русскоязычной экосистеме, а слаги до v3.2 собирались так:
 *
 *     strtolower(preg_replace('~[^a-z0-9-]+~', '-', $name))
 *
 * То есть кириллица просто выбрасывалась. «Календарь событий» и
 * «Департамент образования» давали один и тот же слаг «-», домен
 * «календарьсобытий.рф» превращался в «portal», а «новости.example» — в
 * «example», сталкиваясь с любым другим порталом на том же домене.
 *
 * Таблиц две, потому что задачи разные:
 *
 *  · slug()  — читаемость. Схема BGN/PCGN, привычная по русскому вебу:
 *              «Календарь событий» → «kalendar-sobytiy».
 *  · mrz()   — машиночитаемая зона документа, ИКАО Doc 9303. Там свои
 *              правила: ж→ZH, х→KH, щ→SHCH, ъ→IE, ю→IU, я→IA, ь опускается.
 *              Совпадение с читаемой схемой неполное, и подменять одну
 *              другой нельзя: MRZ обязана быть предсказуемой побуквенно.
 *
 * Кроме русского знает украинские и белорусские буквы — в общей экосистеме
 * они попадаются, а стоят четыре строки в таблице.
 */
final class Translit
{
    /** Читаемая схема (BGN/PCGN) — для слагов, логинов, имён файлов. */
    private const READABLE = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
        'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
        'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
        'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        /* украинские и белорусские */
        'і' => 'i', 'ї' => 'yi', 'є' => 'ye', 'ґ' => 'g', 'ў' => 'u',
    ];

    /** ИКАО Doc 9303 — для машиночитаемой зоны. Отличается от читаемой. */
    private const ICAO = [
        'а' => 'A', 'б' => 'B', 'в' => 'V', 'г' => 'G', 'д' => 'D', 'е' => 'E',
        'ё' => 'E', 'ж' => 'ZH', 'з' => 'Z', 'и' => 'I', 'й' => 'I', 'к' => 'K',
        'л' => 'L', 'м' => 'M', 'н' => 'N', 'о' => 'O', 'п' => 'P', 'р' => 'R',
        'с' => 'S', 'т' => 'T', 'у' => 'U', 'ф' => 'F', 'х' => 'KH', 'ц' => 'TS',
        'ч' => 'CH', 'ш' => 'SH', 'щ' => 'SHCH', 'ъ' => 'IE', 'ы' => 'Y', 'ь' => '',
        'э' => 'E', 'ю' => 'IU', 'я' => 'IA',
        'і' => 'I', 'ї' => 'I', 'є' => 'IE', 'ґ' => 'G', 'ў' => 'U',
    ];

    /** Кириллические домены верхнего уровня — иначе «.рф» стал бы «.rf». */
    private const IDN_TLD = ['рф' => 'ru', 'укр' => 'ua', 'бел' => 'by', 'срб' => 'rs', 'мкд' => 'mk'];

    /** Читаемая латиница с сохранением регистра. */
    public static function latin(string $text): string
    {
        return self::map($text, self::READABLE, false);
    }

    /**
     * Слаг для URL: латиница, нижний регистр, дефисы.
     *
     * @param string $fallback что вернуть, если после чистки ничего не осталось
     *                         (например, имя из одних эмодзи)
     */
    public static function slug(string $text, string $fallback = 'item', int $maxLength = 50): string
    {
        $s = mb_strtolower(trim($text), 'UTF-8');
        $s = self::map($s, self::READABLE, false);
        /* Всё, что не буква-цифра, становится дефисом; края подрезаем */
        $s = (string)preg_replace('~[^a-z0-9]+~', '-', $s);
        $s = trim((string)preg_replace('~-+~', '-', $s), '-');
        if ($s === '') return $fallback;
        if (mb_strlen($s) > $maxLength) {
            $s = rtrim(mb_substr($s, 0, $maxLength), '-');
        }
        return $s !== '' ? $s : $fallback;
    }

    /**
     * Слаг из адреса портала. Кириллический домен не выбрасываем, а
     * транслитерируем: «календарьсобытий.рф» → «kalendarsobytiy-ru».
     * Зона идёт последней частью — так слаги двух порталов на одном домене
     * различаются поддоменом, а не сливаются в одно имя.
     */
    public static function domainSlug(string $urlOrHost, string $fallback = 'portal'): string
    {
        $host = (string)parse_url($urlOrHost, PHP_URL_HOST);
        if ($host === '') $host = $urlOrHost;
        $host = mb_strtolower(trim($host), 'UTF-8');
        $host = (string)preg_replace('~^www\.~', '', $host);
        if ($host === '') return $fallback;

        $labels = explode('.', $host);
        foreach ($labels as $i => $label) {
            /* Punycode приходит от браузеров и в логах — разворачиваем обратно */
            if (str_starts_with($label, 'xn--') && function_exists('idn_to_utf8')) {
                $decoded = idn_to_utf8($label, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if (is_string($decoded) && $decoded !== '') $label = mb_strtolower($decoded, 'UTF-8');
            }
            $labels[$i] = self::IDN_TLD[$label] ?? $label;
        }
        return self::slug(implode('-', $labels), $fallback);
    }

    /**
     * Строка машиночитаемой зоны в формате ИКАО TD3: 44 знака, разделитель «<».
     * Формат: ID<SSO + фамилия << имя, добито «<» до длины.
     */
    public static function mrz(string $lastName, string $firstName, string $issuer = 'SSO'): string
    {
        $surname = self::icaoName($lastName);
        $given = self::icaoName($firstName);
        /* Издатель — компактный код без разделителей: в настоящем документе на
           этом месте стоит трёхбуквенный код государства, а не фраза. */
        $issuer = substr((string)preg_replace('~[^A-Z]~', '', self::icaoName($issuer)), 0, 10) ?: 'SSO';

        $names = $surname !== '' && $given !== '' ? $surname . '<<' . $given
            : ($surname . $given ?: 'UNKNOWN');
        $line = 'ID<' . $issuer . '<' . $names;
        $line = substr($line, 0, 44);
        return str_pad($line, 44, '<');
    }

    /** Имя для MRZ: только A–Z, пробелы и дефисы — в «<». */
    private static function icaoName(string $name): string
    {
        $s = self::map(mb_strtolower(trim($name), 'UTF-8'), self::ICAO, true);
        $s = strtoupper($s);
        $s = (string)preg_replace('~[^A-Z]+~', '<', $s);
        return trim($s, '<');
    }

    /**
     * Посимвольная замена по таблице.
     *
     * @param array<string, string> $table
     * @param bool $upper таблица уже в верхнем регистре (ИКАО)
     */
    private static function map(string $text, array $table, bool $upper): string
    {
        $out = '';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $i => $ch) {
            $lower = mb_strtolower($ch, 'UTF-8');
            if (!isset($table[$lower])) { $out .= $ch; continue; }
            $repl = $table[$lower];
            if ($upper || $repl === '' || $ch === $lower) { $out .= $repl; continue; }

            /* Заглавная буква внутри слова из заглавных — вся замена заглавная
               (ЩУКА → SHCHUKA), в начале слова — только первая (Щука → Shchuka).
               Без этой проверки получалось «ShchUKA». */
            $out .= self::isShouty($chars, $i) ? mb_strtoupper($repl, 'UTF-8')
                : mb_strtoupper(mb_substr($repl, 0, 1), 'UTF-8') . mb_substr($repl, 1);
        }
        return $out;
    }

    /** Соседняя буква тоже заглавная — значит, слово написано капсом. */
    private static function isShouty(array $chars, int $i): bool
    {
        foreach ([$chars[$i - 1] ?? '', $chars[$i + 1] ?? ''] as $n) {
            if ($n === '' || !preg_match('~\p{L}~u', $n)) continue;
            if ($n === mb_strtoupper($n, 'UTF-8') && $n !== mb_strtolower($n, 'UTF-8')) return true;
        }
        return false;
    }
}
