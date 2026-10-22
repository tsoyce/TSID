<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Цветовая математика для белого лейбла.
 *
 * Проблема, ради которой класс написан: акцент задаёт владелец установки, а в CSS
 * до v3.1 был прибит `color: #fff` поверх акцента. Из восьми правдоподобных
 * акцентов шесть давали контраст 1.5–2.8 при норме WCAG AA 4.5 — жёлтая или
 * бирюзовая кнопка «Войти» становилась нечитаемой. Считать цвета на глаз нельзя,
 * поэтому здесь честная формула яркости и подбор производных значений.
 *
 * Из одного акцента выводятся три переменные:
 *   --ink       заливка (кнопка, активная вкладка) — акцент, при нужде осветлённый
 *               до видимости на тёмном фоне;
 *   --on-ink    текст поверх заливки — белый или почти чёрный, что контрастнее;
 *   --ink-text  акцент как текст на фоне страницы (ссылки, badge, активная вкладка) —
 *               затемнённый или осветлённый до 4.5.
 */
final class Palette
{
    /** Норма WCAG AA для обычного текста. */
    private const AA_TEXT = 4.5;
    /** Норма для крупных элементов и заливок — их достаточно различать. */
    private const AA_FILL = 3.0;

    /**
     * Худший фон, на котором акцент стоит текстом, — в каждой теме свой.
     *
     * В светлой это НЕ карточка `--surface` (#FFFFFF), а фон страницы `--bg`
     * (#F5F5F4): ссылка в подвале, подпись под полем и активная вкладка живут
     * прямо на нём. Пока считали от белого, запас съедался ровно на этой
     * разнице — `Palette` тянет до 4.5 и останавливается, поэтому на фоне
     * страницы выходило меньше нормы: оранжевый 4.34, розовый 4.21,
     * жёлтый 4.46. Одна константа чинит все ссылки продукта разом.
     *
     * В тёмной теме наоборот: карточка (#292524) светлее фона (#1C1917), и
     * худший случай — карточка. Поэтому здесь остаётся surface.
     */
    private const SURFACE_LIGHT = '#F5F5F4';
    private const SURFACE_DARK = '#292524';

    private const WHITE = '#FFFFFF';
    /** Не чистый чёрный: на цветной заливке он выглядит грязно. */
    private const NEAR_BLACK = '#12100E';

    /**
     * Фон, против которого посчитан контраст текста этой темы.
     *
     * Наружу отдаётся затем, что образец в настройках обязан лежать на ТОЙ
     * подложке, о которой рассказывает. Пока его не было, образец ссылки брал
     * фон у страницы: владелец, открывший брендинг в тёмной теме, видел
     * светлый акцент на тёмной карточке — 2.26 при норме 4.5, то есть
     * предпросмотр читаемости сам был нечитаем.
     */
    public static function surface(bool $dark = false): string
    {
        return $dark ? self::SURFACE_DARK : self::SURFACE_LIGHT;
    }

    /**
     * Производные акцента для одной темы.
     *
     * @return array{ink: string, on_ink: string, ink_text: string}
     */
    public static function derive(string $accent, bool $dark = false): array
    {
        $accent = self::normalize($accent);
        $surface = $dark ? self::SURFACE_DARK : self::SURFACE_LIGHT;

        /* Заливка должна отличаться от фона — иначе кнопка сливается со страницей. */
        $ink = self::ensureContrast($accent, $surface, self::AA_FILL, $dark);

        /* Текст поверх заливки: что контрастнее — белый или почти чёрный. */
        $onInk = self::contrast($ink, self::WHITE) >= self::contrast($ink, self::NEAR_BLACK)
            ? self::WHITE : self::NEAR_BLACK;

        /* Акцент как текст: требования жёстче, тянем до 4.5. */
        $inkText = self::ensureContrast($accent, $surface, self::AA_TEXT, $dark);

        return ['ink' => $ink, 'on_ink' => $onInk, 'ink_text' => $inkText];
    }

    /**
     * CSS-блок для подстановки в <head>. Обе темы сразу: тёмную нельзя оставлять
     * на том же акценте — тёмно-синий на тёмном фоне не виден.
     */
    public static function css(string $accent): string
    {
        $light = self::derive($accent, false);
        $dark = self::derive($accent, true);
        return ':root{'
            . '--ink:' . $light['ink'] . ';'
            . '--on-ink:' . $light['on_ink'] . ';'
            . '--ink-text:' . $light['ink_text'] . ';'
            . '}'
            . ':root[data-theme="dark"]{'
            . '--ink:' . $dark['ink'] . ';'
            . '--on-ink:' . $dark['on_ink'] . ';'
            . '--ink-text:' . $dark['ink_text'] . ';'
            . '}';
    }

    /**
     * Отчёт для настроек брендинга: что получилось и не пришлось ли править акцент.
     *
     * @return array{ink: string, on_ink: string, ink_text: string,
     *               contrast_fill: float, contrast_text: float, adjusted: bool}
     */
    public static function report(string $accent, bool $dark = false): array
    {
        $accent = self::normalize($accent);
        $d = self::derive($accent, $dark);
        $surface = $dark ? self::SURFACE_DARK : self::SURFACE_LIGHT;
        return $d + [
            'contrast_fill' => round(self::contrast($d['ink'], $d['on_ink']), 2),
            'contrast_text' => round(self::contrast($d['ink_text'], $surface), 2),
            'adjusted' => strcasecmp($d['ink_text'], $accent) !== 0,
        ];
    }

    /**
     * Производные акцента против ЧУЖИХ подложек (v4.65).
     *
     * derive() считает против фона и карточки SSO. Экран входа в теме
     * портала стоит на его фоне и его карточке, и гарантия контраста обязана
     * даваться против худшей из них: ссылка, которая читается на карточке,
     * тонет на фоне страницы, если считать только от одного.
     *
     * @param  list<string> $surfaces
     * @return array{ink: string, on_ink: string, ink_text: string}
     */
    public static function deriveOn(string $accent, array $surfaces, bool $dark): array
    {
        $accent = self::normalize($accent);
        $ink = self::fit($accent, $surfaces, self::AA_FILL, $dark);
        $onInk = self::contrast($ink, self::WHITE) >= self::contrast($ink, self::NEAR_BLACK)
            ? self::WHITE : self::NEAR_BLACK;
        return ['ink' => $ink, 'on_ink' => $onInk, 'ink_text' => self::fit($accent, $surfaces, self::AA_TEXT, $dark)];
    }

    /** Довести цвет до контраста $need со всеми подложками: к чёрному в светлой теме, к белому в тёмной. */
    public static function fit(string $color, array $surfaces, float $need, bool $dark): string
    {
        foreach ($surfaces as $s) $color = self::ensureContrast($color, $s, $need, $dark);
        return $color;
    }

    /** Смешать два цвета: $t = 0 — первый, 1 — второй. */
    public static function blend(string $a, string $b, float $t): string
    {
        return self::mix(self::normalize($a), self::normalize($b), max(0.0, min(1.0, $t)));
    }

    /** Контраст по WCAG 2.1: (L1 + 0.05) / (L2 + 0.05). */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        if ($la < $lb) [$la, $lb] = [$lb, $la];
        return ($la + 0.05) / ($lb + 0.05);
    }

    /** Относительная яркость по WCAG 2.1. */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);
        $f = static function (int $c): float {
            $v = $c / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    /**
     * Тянет цвет к белому (в тёмной теме) или к чёрному (в светлой), пока он не
     * наберёт нужный контраст с фоном. Шаг мелкий, чтобы не улететь в блёклый
     * пастель там, где хватило бы лёгкой правки.
     */
    private static function ensureContrast(string $color, string $surface, float $need, bool $dark): string
    {
        if (self::contrast($color, $surface) >= $need) return $color;

        $target = $dark ? self::WHITE : '#000000';
        $best = $color;
        for ($step = 1; $step <= 20; $step++) {
            $candidate = self::mix($color, $target, $step * 0.05);
            $best = $candidate;
            if (self::contrast($candidate, $surface) >= $need) return $candidate;
        }
        return $best;
    }

    /** Смешивание в sRGB: предсказуемо и без сюрпризов на серых. */
    private static function mix(string $a, string $b, float $t): string
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);
        return sprintf('#%02X%02X%02X',
            (int)round($r1 + ($r2 - $r1) * $t),
            (int)round($g1 + ($g2 - $g1) * $t),
            (int)round($b1 + ($b2 - $b1) * $t));
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        return [
            (int)hexdec(substr($h, 0, 2)),
            (int)hexdec(substr($h, 2, 2)),
            (int)hexdec(substr($h, 4, 2)),
        ];
    }

    /** Приводит к #RRGGBB; мусор превращается в дефолтный акцент, а не в фатал. */
    public static function normalize(string $hex): string
    {
        $h = '#' . strtoupper(ltrim(trim($hex), '#'));
        if (preg_match('~^#[0-9A-F]{3}$~', $h)) {
            return '#' . $h[1] . $h[1] . $h[2] . $h[2] . $h[3] . $h[3];
        }
        return preg_match('~^#[0-9A-F]{6}$~', $h) ? $h : '#1D4ED8';
    }
}
