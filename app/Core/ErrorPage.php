<?php

declare(strict_types=1);

namespace App\Core;

/** Фирменные страницы ошибок 400/403/404/419/429/500 + OAuth-ошибка на месте (§5.3). */
final class ErrorPage
{
    public static function render(int $code, array $extra = []): never
    {
        http_response_code($code);
        $texts = [
            400 => ['Некорректный запрос', 'Сервер не смог разобрать запрос. Проверьте адрес и попробуйте ещё раз.'],
            403 => ['Доступ закрыт', 'У вас нет прав для просмотра этой страницы.'],
            404 => ['Страница не найдена', 'Такой страницы нет — возможно, ссылка устарела.'],
            419 => ['Форма устарела', 'Сессия формы истекла. Обновите страницу и повторите действие.'],
            /* 423 Locked — не выдумка: сессия жива, но заперта замком, и
               действие ждёт короткой переавторизации. 403 сказал бы «прав
               нет», а права есть. */
            423 => ['Сессия заперта', 'Подтвердите, что это вы, — и повторите действие.'],
            429 => ['Слишком часто', 'Превышен лимит запросов. Подождите немного и повторите.'],
            500 => ['Что-то сломалось', 'Мы уже записали инцидент. Попробуйте обновить страницу.'],
            /* 503 отдельно от 500: «сервис временно недоступен» — не то же
               самое, что «сломался», и мониторинг различает их по коду.
               Раньше обслуживание и недоступная база отвечали 500, потому что
               render(500) перебивал выставленный ранее http_response_code(). */
            503 => ['Сервис недоступен', 'Сейчас сервис не отвечает. Попробуйте через несколько минут.'],
        ];
        [$title, $message] = $texts[$code] ?? $texts[500];
        /* Причину показываем только своему: путь к файлу — внутреннее
           устройство, постороннему его знать незачем. Спрашиваем осторожно:
           страница ошибки обязана нарисоваться даже тогда, когда сломалась
           сама проверка входа. */
        $delivery = null;
        if (!empty($extra['delivery'])) {
            try {
                if (Auth::isAdmin()) $delivery = $extra['delivery'];
            } catch (\Throwable) {
                $delivery = null;
            }
        }
        try {
            View::render('errors/error', [
                'code' => $code,
                'title' => $extra['title'] ?? $title,
                'message' => $extra['message'] ?? $message,
                'incident' => $code === 500 ? (defined('SSO_REQUEST_ID') ? SSO_REQUEST_ID : '') : '',
                'retry_after' => $extra['retry_after'] ?? null,
                'delivery' => $delivery,
                'pageTitle' => $title,
            ], 'layouts/minimal');
        } catch (\Throwable) {
            // Шаблоны недоступны (ранний сбой) — голый минимум
            header('Content-Type: text/html; charset=utf-8');
            echo "<h1>$code — " . esc($title) . '</h1><p>' . esc($message) . '</p>';
            exit;
        }
    }

    /** OAuth-ошибка без редиректа: невалидный client_id / redirect_uri (§3.5). */
    public static function oauth(string $title, string $message, array $extra = []): never
    {
        http_response_code(400);
        View::render('errors/oauth', [
            'title' => $title,
            'message' => $message,
            'uri' => (string)($extra['uri'] ?? ''),
            'noted' => !empty($extra['noted']),
            'pageTitle' => 'Ошибка авторизации',
        ], 'layouts/minimal');
    }
}
