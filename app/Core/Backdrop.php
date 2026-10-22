<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Фон страниц (v4.84): картинка, картинки по кругу, видео или YouTube за
 * формами входа, кабинетом и админкой.
 *
 * Владелец 26.09: «было бы неплохо выносить изображения и видео на фон форм,
 * ЛК, админки — чтобы было и смотрелось интереснее и интерактивнее». Что
 * показывать — тот же набор, что у обложки (CoverSet, владелец 'bg'), с той
 * же осторожностью: видео и YouTube подключает скрипт, только на широком
 * экране и только если человек не просил «без движения».
 *
 * Читаемость — прежде всего. Между фоном и страницей лежит вуаль цвета фона
 * темы: текст вне карточек стоит на ней, а не на чужом кадре. По умолчанию
 * вуаль плотная (92%), ослабить её можно — и тогда об этом сказано рядом.
 * Карточки остаются карточками; со «стеклом» в конструкторе сквозь них
 * размыто виден фон.
 */
final class Backdrop
{
    /** Где показывать: экраны входа и главная, кабинет, админка. */
    public const AREAS = ['auth', 'cabinet', 'admin'];
    /** Плотность вуали: чем плотнее, тем спокойнее и читаемее. */
    public const VEILS = ['strong', 'medium', 'light'];
    public const BLURS = ['none', 'soft', 'strong'];

    /** @return array{areas: list<string>, veil: string, blur: string, parallax: bool} */
    public static function settings(): array
    {
        $in = json_decode((string)Setting::get('appearance.backdrop', ''), true);
        $in = is_array($in) ? $in : [];
        return [
            'areas' => array_values(array_intersect(self::AREAS, array_map('strval', (array)($in['areas'] ?? [])))),
            'veil' => in_array($in['veil'] ?? '', self::VEILS, true) ? (string)$in['veil'] : 'strong',
            'blur' => in_array($in['blur'] ?? '', self::BLURS, true) ? (string)$in['blur'] : 'none',
            'parallax' => !empty($in['parallax']),
        ];
    }

    /**
     * Сохранить из формы «Оформления сайта»: где, вуаль, размытие, параллакс
     * и сам набор (поля bg_*).
     *
     * @return list<string> что из файлов не принято и почему
     */
    public static function saveFromForm(array $post, array $files): array
    {
        $s = [
            'areas' => array_values(array_intersect(self::AREAS, array_map('strval', (array)($post['backdrop_areas'] ?? [])))),
            'veil' => in_array($post['backdrop_veil'] ?? '', self::VEILS, true) ? (string)$post['backdrop_veil'] : 'strong',
            'blur' => in_array($post['backdrop_blur'] ?? '', self::BLURS, true) ? (string)$post['backdrop_blur'] : 'none',
            'parallax' => ($post['backdrop_parallax'] ?? '') === '1',
        ];
        Setting::set('appearance.backdrop', (string)json_encode($s));
        return CoverSet::saveFromForm(CoverSet::BACKDROP, $post, $files, 'bg');
    }

    /**
     * Слой фона для макета или пустая строка: область не отмечена или
     * показывать нечего.
     */
    public static function layer(string $area): string
    {
        $s = self::settings();
        if (!in_array($area, $s['areas'], true)) return '';
        $frame = CoverSet::frame(CoverSet::BACKDROP, false);
        if ($frame === null) return '';
        return '<div class="page-backdrop pb-veil-' . $s['veil'] . ' pb-blur-' . $s['blur'] . '" aria-hidden="true" data-backdrop'
            . ($s['parallax'] ? ' data-parallax' : '') . '><div class="pb-media">' . $frame . '</div></div>';
    }
}
