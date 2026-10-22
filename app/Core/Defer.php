<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Отложить работу за ответ (v4.38).
 *
 * Вход был медленным, и причина не в SSO, а в том, что мы делали в его запросе.
 * `Mailer::queue()` кладёт письмо в очередь и ТУТ ЖЕ пытается его отправить —
 * а это TLS-рукопожатие и разговор с чужим SMTP, секунды. `WebhookDispatcher`
 * так же ходит HTTP-запросом к порталу. Оба вызываются на успешном входе, и
 * человек, пришедший из сервиса, ждал их, глядя на крутилку, — до редиректа,
 * который и есть весь смысл происходящего.
 *
 * Немедленная попытка сама по себе правильная: она нужна, чтобы письмо с кодом
 * приходило сразу, а не через минуту по расписанию. Неправильно только то, что
 * она стоит ПЕРЕД ответом. Здесь она уезжает за него: FPM умеет закрыть ответ
 * и продолжить работу (`fastcgi_finish_request`), и человек уходит на портал,
 * пока мы разговариваем с почтой.
 *
 * Где такой возможности нет (встроенный сервер, mod_php) — работа делается
 * как раньше, в конце запроса: хуже, чем на FPM, но не хуже, чем было.
 */
final class Defer
{
    /** @var list<callable> */
    private static array $jobs = [];
    private static bool $armed = false;

    /** Сделать после того, как ответ ушёл. */
    public static function after(callable $job): void
    {
        /* В консоли (cron, миграции) откладывать некуда и незачем: там ответа
           нет, а есть задача, которую и надо выполнить целиком. */
        if (PHP_SAPI === 'cli') { self::run1($job); return; }

        self::$jobs[] = $job;
        if (self::$armed) return;
        self::$armed = true;
        register_shutdown_function([self::class, 'flush']);
    }

    /** Закрыть ответ и доделать отложенное. Вызывается на завершении запроса. */
    public static function flush(): void
    {
        if (self::$jobs === []) return;

        /* Соединение закрываем сами: иначе браузер ждёт конца ответа, и вся
           затея теряет смысл. `ignore_user_abort` — чтобы работа не оборвалась
           вместе с соединением. */
        @ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) @ob_end_flush();
            @flush();
        }

        $jobs = self::$jobs;
        self::$jobs = [];
        foreach ($jobs as $job) self::run1($job);
    }

    private static function run1(callable $job): void
    {
        try {
            $job();
        } catch (\Throwable $e) {
            /* Отложенное — вспомогательное. Его падение не должно уронить
               ничего: ответ уже ушёл, ронять попросту нечего. */
            Log::warning('deferred_failed', ['err' => $e->getMessage()]);
        }
    }
}
