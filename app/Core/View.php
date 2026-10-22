<?php

declare(strict_types=1);

namespace App\Core;

/**
 * SSR-шаблоны: templates/. Автоэкранирование — дисциплиной esc() в шаблонах,
 * View лишь собирает страницу: шаблон → $content → layout.
 */
final class View
{
    /** Рендер с layout'ом. $data доступна в шаблоне и в layout. */
    public static function render(string $template, array $data = [], string $layout = 'layouts/base'): never
    {
        $content = self::fetch($template, $data);
        if ($layout !== '') {
            $data['content'] = $content;
            $content = self::fetch($layout, $data);
        }
        echo $content;
        exit;
    }

    /** Рендер в строку (partials, письма). */
    public static function fetch(string $template, array $data = []): string
    {
        $file = SSO_ROOT . '/templates/' . $template . '.php';
        if (!is_file($file)) {
            /* Путь, а не только имя: по имени «admin/orgs» разобрать причину
               нельзя, а по пути видно, что файла попросту нет на диске — и
               страница 500 назовёт его владельцу сама (см. Delivery::blame). */
            throw new \RuntimeException("Шаблон не найден: templates/$template.php");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }

    /** Письмо: шаблон + обёртка emails/base; возвращает [html, text]. */
    public static function email(string $template, array $data = []): array
    {
        $inner = self::fetch('emails/' . $template, $data);
        $html = self::fetch('emails/base', ['inner' => $inner] + $data);
        $text = trim(html_entity_decode(strip_tags(
            preg_replace(['~<br\s*/?>~i', '~</p>~i', '~<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>~is'], ["\n", "\n\n", "$2 → $1"], $inner)
        ), ENT_QUOTES, 'UTF-8'));
        return [$html, $text];
    }
}
