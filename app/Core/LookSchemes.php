<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Свои схемы оформления (v4.79) — оформление целиком под именем.
 *
 * Владелец: «сохранять свои схемы вместе со шрифтами и скруглением и одним
 * щелчком применять их к порталам». Готовые схемы (Bridge::PRESETS) — это
 * только палитра; своя схема — всё, что задаёт вид: палитра обеих тем,
 * шрифты, скругление, подкраска фона и конструктор (кнопки, поля, тени).
 * Содержание экрана — заголовок, подпись, обложка — в схему не входит: это
 * слова портала, а не его вид.
 *
 * Схемы общие на всю установку: их видят редактор сайта и редакторы
 * порталов. Сохраняет и удаляет их тот, кто правит настройки установки.
 */
final class LookSchemes
{
    private const MAX = 24;
    /** Что из оформления входит в схему — вид, без содержания. */
    private const STYLE_KEYS = ['radius', 'font', 'font_display', 'wash'];

    /** @return list<array{id: string, name: string, theme: array<string, mixed>}> */
    public static function all(): array
    {
        $raw = json_decode((string)Setting::get('look.schemes', ''), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $s) {
            if (!is_array($s) || !preg_match('~^[a-f0-9]{10}$~', (string)($s['id'] ?? ''))) continue;
            $theme = self::style(Bridge::sanitize($s['theme'] ?? [], 'site'));
            if ($theme === []) continue;
            $out[] = ['id' => (string)$s['id'], 'name' => mb_substr(trim((string)($s['name'] ?? '')), 0, 40) ?: t('Без имени'), 'theme' => $theme];
        }
        return $out;
    }

    /** Только вид: палитра, шрифты, скругление, подкраска, конструктор. */
    public static function style(array $theme): array
    {
        $keys = array_merge(self::STYLE_KEYS, array_keys(Bridge::BUILDER));
        foreach (Bridge::COLORS as $c) { $keys[] = $c; $keys[] = $c . '_dark'; }
        return array_intersect_key($theme, array_flip($keys));
    }

    /**
     * Сохранить схему; то же имя — заменить.
     *
     * @return array{ok: bool, detail: string, scheme?: array{id: string, name: string, theme: array<string, mixed>}}
     */
    public static function save(string $name, array $theme): array
    {
        $name = mb_substr(trim($name), 0, 40);
        if ($name === '') return ['ok' => false, 'detail' => t('Назовите схему.')];
        $theme = self::style($theme);
        if ($theme === []) return ['ok' => false, 'detail' => t('В оформлении пока нечего сохранять — выберите цвета, шрифты или форму.')];
        $all = array_values(array_filter(self::all(), static fn(array $s): bool => mb_strtolower($s['name']) !== mb_strtolower($name)));
        if (count($all) >= self::MAX) return ['ok' => false, 'detail' => t('Схем уже :n — уберите лишние.', ['n' => self::MAX])];
        $scheme = ['id' => substr(sha1($name . microtime()), 0, 10), 'name' => $name, 'theme' => $theme];
        $all[] = $scheme;
        Setting::set('look.schemes', (string)json_encode($all, JSON_UNESCAPED_UNICODE));
        Setting::flush();
        return ['ok' => true, 'detail' => t('Схема «:name» сохранена — она есть и у порталов.', ['name' => $name]), 'scheme' => $scheme];
    }

    public static function remove(string $id): bool
    {
        $all = self::all();
        $left = array_values(array_filter($all, static fn(array $s): bool => $s['id'] !== $id));
        if (count($left) === count($all)) return false;
        Setting::set('look.schemes', (string)json_encode($left, JSON_UNESCAPED_UNICODE));
        Setting::flush();
        return true;
    }

    /**
     * Что подставить из готовой схемы (v4.80): только цвета обеих тем — шрифты
     * и форма остаются, как были. Базовая (пустая палитра) сбрасывает цвета к
     * установке; контрастные ещё и задают тему для новых посетителей.
     *
     * @return array<string, mixed>
     */
    public static function paletteValues(array $pal): array
    {
        $out = [];
        $hasDark = false;
        foreach (Bridge::COLORS as $c) {
            $out[$c] = (string)($pal[$c] ?? '');
            if (isset($pal[$c . '_dark'])) { $out[$c . '_dark'] = (string)$pal[$c . '_dark']; $hasDark = true; }
        }
        $out['dark'] = $hasDark;
        if (array_key_exists('theme_default', $pal) || $pal === []) $out['theme_default'] = (string)($pal['theme_default'] ?? '');
        return $out;
    }

    /**
     * Что подставить в поля редактора: все поля вида, и незаданные — пустыми,
     * чтобы схема не смешалась с тем, что было в форме до неё.
     *
     * @return array<string, mixed>
     */
    public static function formValues(array $theme): array
    {
        $out = [];
        $hasDark = false;
        foreach (Bridge::COLORS as $c) {
            $out[$c] = (string)($theme[$c] ?? '');
            if (isset($theme[$c . '_dark'])) { $out[$c . '_dark'] = (string)$theme[$c . '_dark']; $hasDark = true; }
        }
        $out['dark'] = $hasDark;
        foreach (array_merge(['radius', 'font', 'font_display'], array_keys(Bridge::BUILDER)) as $k) {
            $out[$k] = isset($theme[$k]) ? (string)$theme[$k] : '';
        }
        $out['wash'] = ($theme['wash'] ?? true) !== false;
        return $out;
    }
}
