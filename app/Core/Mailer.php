<?php

declare(strict_types=1);

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Почта: всё через очередь {mail_queue} + немедленная попытка отправки.
 * Транспорт: SMTP (PHPMailer) или 'log' (файлы .eml — для разработки).
 */
final class Mailer
{
    /** Лог последнего SMTP-сеанса (заполняется в тестовом режиме). */
    private static array $debugLines = [];

    public static function lastDebug(int $tailLines = 8): string
    {
        return implode("\n", array_slice(self::$debugLines, -$tailLines));
    }

    /**
     * Текст письма для журнала «Почта» — без действующих ссылок и кодов (v4.97.2).
     *
     * В очереди лежат письма сброса пароля, входа по ссылке и коды
     * подтверждения. Журнал видит и роль «только чтение»: аудитор запрашивал
     * сброс на почту владельца и входил по ссылке из его письма. Токен в
     * ссылке и цифровой код маскируются; что за письмо и кому — видно.
     */
    public static function redact(string $text): string
    {
        $text = (string)preg_replace('~(https?://\S+?/)[A-Za-z0-9_-]{20,}(?=[\s?#&"\')\]]|$)~u', '$1•••', $text);
        $text = (string)preg_replace('~([?&](?:token|code|rt)=)[^\s&#]+~iu', '$1•••', $text);
        return (string)preg_replace('~(?<!\d)(?<!\d[.:/-])\d{3}[ -]?\d{3}(?!\d|[.:/-]\d)~u', '••••••', $text);
    }

    /** Положить в очередь и сразу попытаться отправить. */
    public static function queue(string $to, string $subject, string $html, string $text): int
    {
        $id = DB::insert('mail_queue', [
            'to_email' => $to,
            'subject' => $subject,
            'body_html' => $html,
            'body_text' => $text,
            'status' => 'pending',
            'created_at' => now(),
        ]);
        /* Отправляем сразу, но ЗА ответом: разговор с чужим SMTP — это секунды,
           и держать на них человека нельзя. Раньше попытка стояла прямо здесь,
           и вход из сервиса ждал почту, прежде чем вернуть человека на портал. */
        Defer::after(static function () use ($id): void {
            try {
                self::attempt($id);
            } catch (\Throwable $e) {
                Log::warning('mail_immediate_failed', ['id' => $id, 'err' => $e->getMessage()]);
            }
        });
        return $id;
    }

    /** Рендер письма из шаблона + постановка в очередь. */
    public static function send(string $to, string $subject, string $template, array $data = []): int
    {
        [$html, $text] = View::email($template, $data + ['subject' => $subject]);
        return self::queue($to, $subject, $html, $text);
    }

    /**
     * Письмо из каталога: тему берём оттуда же, откуда текст.
     *
     * До этого тема собиралась на месте вызова — «'Подтвердите почту — ' .
     * appName()», — и переписать её из админки было нельзя, даже переписав
     * само письмо. Тема и текст — одно письмо, и жить им положено рядом.
     */
    public static function sendLetter(string $to, string $letter, array $data = []): int
    {
        /* Письмо из каталога просит тот, кто сейчас на экране: код, ссылку,
           сброс. Гость здесь и есть получатель. */
        return self::as($to, static fn(): int => self::letterNow($to, $letter, $data), true);
    }

    /**
     * Собрать письмо на языке получателя (v4.74) — всё, что внутри $fn: тему,
     * текст, подпись, строки таблицы.
     *
     * Человек просит письмо сам (вошёл под этим адресом или, для писем по его
     * же запросу, не вошёл вовсе) — на языке, который у него сейчас на экране.
     * За него отправляет другой — администратор, фоновая задача, незнакомец,
     * подбирающий пароль, человек, чья регистрация будит оповещение
     * владельцу, — на сохранённом языке получателя. Иначе англоязычный
     * администратор получал оповещение на языке того, кто регистрировался, а
     * человек — письмо на языке админа.
     *
     * Один механизм на все письма: раньше правило жило в sendLetter(), а
     * Mailer::send() с темой, собранной у вызывающего, шёл на языке
     * отправителя.
     */
    public static function as(string $to, callable $fn, bool $guestIsRecipient = false): mixed
    {
        $locale = null;
        try {
            $self = PHP_SAPI !== 'cli' && (Auth::check()
                ? strcasecmp((string)(Auth::user()['email'] ?? ''), trim($to)) === 0
                : $guestIsRecipient);
            if (!$self) $locale = DB::val('SELECT locale FROM {users} WHERE email = ?', [mb_strtolower(trim($to))]);
        } catch (\Throwable) {
            // нет базы или колонки — на текущем языке
        }
        return Lang::using(is_string($locale) ? $locale : null, $fn);
    }

    private static function letterNow(string $to, string $letter, array $data): int
    {
        $rendered = MailTemplates::render($letter, $data);
        /* Подстановки в письме одни и те же везде — «{{ Название }}», как в
           документах. Второй способ (`:app`) существовал только для темы и
           тем и был плох: в поле настроек он показывался как есть — человек
           видел плейсхолдер «Вход в :app» и не понимал, чинить это или так
           задумано. */
        $subject = $rendered['subject'];
        /* Название установки в теме — чтобы письмо узнавалось в списке входящих
           рядом с десятком таких же. Своя тема, уже назвавшая установку,
           второй раз её не получает. */
        if (!str_contains($subject, Setting::appName())) {
            $subject .= ' — ' . Setting::appName();
        }
        return self::send($to, $subject, $letter, $data);
    }

    /** Одна попытка отправки письма из очереди. */
    public static function attempt(int $mailId): bool
    {
        $mail = DB::row('SELECT * FROM {mail_queue} WHERE id = ?', [$mailId]);
        if (!$mail || $mail['status'] === 'sent') return false;
        /* v4.97.2: письмо берёт тот, чей условный UPDATE сдвинул счётчик попыток.
           Немедленная попытка из Defer и cron (или два cron при медленном SMTP)
           брали одну строку оба — и человек получал два письма с кодом. */
        if (DB::run('UPDATE {mail_queue} SET attempts = attempts + 1 WHERE id = ? AND attempts = ? AND status <> ?',
                [$mailId, (int)$mail['attempts'], 'sent'])->rowCount() !== 1) return false;
        try {
            self::transport($mail['to_email'], $mail['subject'], $mail['body_html'], $mail['body_text']);
            DB::update('mail_queue', ['status' => 'sent', 'sent_at' => now(), 'attempts' => (int)$mail['attempts'] + 1], 'id = ?', [$mailId]);
            return true;
        } catch (\Throwable $e) {
            $attempts = (int)$mail['attempts'] + 1;
            DB::update('mail_queue', [
                'status' => $attempts >= 5 ? 'failed' : 'pending',
                'attempts' => $attempts,
                'last_error' => mb_substr($e->getMessage(), 0, 255),
            ], 'id = ?', [$mailId]);
            return false;
        }
    }

    /** Прямая отправка (тестовое письмо из админки/инсталлятора). */
    public static function transport(string $to, string $subject, string $html, string $text, ?array $smtp = null): void
    {
        $driver = $smtp !== null ? 'smtp' : (string)setting('mail.driver', config('mail.driver', 'smtp'));
        if ($driver === 'log') {
            $dir = SSO_ROOT . '/storage/logs/mail';
            if (!is_dir($dir)) mkdir($dir, 0750, true);
            /* Микросекунды в имени (v4.96): имя — порядок отправки. Два письма в
               одну секунду (попытка регистрации и код) раньше различались только
               случайным хвостом, и «последнее письмо» выходило наугад. */
            $us = sprintf('%06d', (int)(fmod(microtime(true), 1) * 1e6));
            $fname = $dir . '/' . gmdate('Ymd-His') . '-' . $us . '-' . substr(md5($to . $subject . microtime()), 0, 6) . '.eml';
            $eml = "To: $to\r\nSubject: $subject\r\nContent-Type: text/html; charset=utf-8\r\n\r\n$html";
            file_put_contents($fname, $eml);
            return;
        }
        $cfg = $smtp ?? [
            'host' => setting('mail.host', config('mail.host', '')),
            'port' => (int)setting('mail.port', config('mail.port', 587)),
            'encryption' => setting('mail.encryption', config('mail.encryption', 'tls')),
            'user' => setting('mail.user', config('mail.user', '')),
            'pass' => setting('mail.pass', config('mail.pass', '')),
            'from_email' => setting('mail.from_email', config('mail.from.0', 'no-reply@localhost')),
            'from_name' => setting('mail.from_name', config('mail.from.1', Setting::appName())),
        ];
        if (($cfg['host'] ?? '') === '') {
            throw new \RuntimeException('SMTP не настроен');
        }
        $m = new PHPMailer(true);
        $m->CharSet = 'UTF-8';
        $m->isSMTP();
        if ($smtp !== null) {
            // Тестовая отправка из админки/инсталлятора: пишем весь SMTP-диалог,
            // чтобы при ошибке показать, на какой реплике сервер нас завернул
            self::$debugLines = [];
            $m->SMTPDebug = 2;
            $m->Debugoutput = function (string $str) {
                self::$debugLines[] = trim($str);
            };
        }
        $m->Host = $cfg['host'];
        $m->Port = (int)$cfg['port'];
        $m->SMTPAuth = ($cfg['user'] ?? '') !== '';
        $m->Username = (string)($cfg['user'] ?? '');
        $m->Password = (string)($cfg['pass'] ?? '');
        $enc = (string)($cfg['encryption'] ?? 'tls');
        if ($enc === 'ssl') $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        elseif ($enc === 'tls') $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $m->Timeout = 12;
        $m->setFrom((string)$cfg['from_email'], (string)($cfg['from_name'] ?? ''));
        $m->addAddress($to);
        $m->Subject = $subject;
        $m->isHTML(true);
        $m->Body = $html;
        $m->AltBody = $text;
        $m->send();
    }
}
