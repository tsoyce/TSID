<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Уведомления хозяину установки: что происходит, пока он не смотрит.
 *
 * Notify рядом — про человека и его аккаунт: «в вас вошли с нового
 * устройства». Здесь другое: про установку целиком. Регистрации, блокировки,
 * новые порталы, серии неудачных входов. Узнать об этом можно было только
 * зайдя в журнал — то есть заранее заподозрив, что смотреть есть на что.
 *
 * Всё выключено по умолчанию (§1.1): письмо о каждой регистрации нужно не
 * всем, а установке на тысячу человек в день — точно нет.
 *
 * ЗАЩИТА ОТ ПОТОКА — половина смысла этого класса. Бот, заводящий пятьсот
 * аккаунтов, не должен прислать пятьсот писем: тогда владелец заведёт правило
 * «в папку», и уведомления перестанут работать ровно в тот день, когда
 * понадобятся. Поэтому письмо уходит сразу, а следующие того же вида
 * складываются в счётчик и приезжают одной строкой «и ещё столько-то».
 */
final class AdminNotify
{
    /** событие => [заголовок, пояснение] */
    public const EVENTS = [
        'user.registered' => [
            'Новая регистрация',
            'Кто-то завёл аккаунт сам. Полезно, пока установка закрытая или только запускается.',
        ],
        'user.blocked' => [
            'Аккаунт заблокирован',
            'Заблокировал администратор или автоматика после серии неудачных входов.',
        ],
        'login.burst' => [
            'Подбор пароля',
            'Серия неудачных входов подряд по одному аккаунту.',
        ],
        'client.created' => [
            'Подключён портал',
            'Кто-то из администраторов завёл новый сервис.',
        ],
        'client.installed' => [
            'Мост с порталом проложен',
            'Портал забрал свои реквизиты по ссылке установки. Вместе с ними уходит секрет, поэтому знать об этом стоит.',
        ],
        'bulk.purged' => [
            'Массовая чистка',
            'Разом удалено несколько помеченных аккаунтов. Необратимо, поэтому стоит знать.',
        ],
        'settings.imported' => [
            'Загружены чужие настройки',
            'Кто-то применил файл настроек. Меняет правила входа разом, поэтому стоит знать.',
        ],
        'health.self' => [
            'SSO перестал отвечать по стандартным адресам',
            'Discovery, ключи подписи или экран входа недоступны снаружи. Чаще всего панель хостинга перезаписала конфиг веб-сервера. Письмо приходит, когда ломается, а не каждый час.',
        ],
    ];

    /**
     * События, о которых пишем, даже если владелец ничего не включал.
     *
     * Сторож, который по умолчанию молчит, — не сторож: включать его идут
     * тогда, когда уже сломалось, то есть ровно тогда, когда он был нужен.
     */
    public const ON_BY_DEFAULT = ['health.self'];

    /** Окно склейки по умолчанию, минут. */
    public const WINDOW_DEFAULT = 15;

    public static function enabled(string $event): bool
    {
        return isset(self::EVENTS[$event])
            && Setting::get('notify.admin.' . $event,
                in_array($event, self::ON_BY_DEFAULT, true) ? '1' : '0') === '1';
    }

    public static function windowMinutes(): int
    {
        return max(1, min(1440, Setting::getInt('notify.admin.window', self::WINDOW_DEFAULT)));
    }

    /**
     * Кому писать.
     *
     * Пусто — владельцу установки. Так уведомления работают сразу после
     * включения тумблера: заставлять вписывать собственный адрес, который
     * система и так знает, — лишний шаг ради ничего.
     */
    public static function recipients(): array
    {
        $raw = trim((string)Setting::get('notify.admin.to', ''));
        if ($raw !== '') {
            $out = [];
            foreach (preg_split('~[\s,;]+~u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $a) {
                if (filter_var($a, FILTER_VALIDATE_EMAIL)) $out[] = mb_strtolower($a);
            }
            if ($out !== []) return array_values(array_unique($out));
        }
        $owner = Owner::user();
        $email = trim((string)($owner['email'] ?? ''));
        return $email !== '' ? [$email] : [];
    }

    /**
     * Сообщить о событии.
     *
     * $message — одна строка о том, что случилось. Подробности идут таблицей,
     * как в письмах безопасности: «когда», «кто», «откуда».
     *
     * Оба можно передать функцией (v4.74) — тогда текст собирается заново на
     * языке каждого получателя. Иначе оповещение шло на языке того, кто его
     * вызвал: человек регистрировался с английским интерфейсом — владелец
     * получал письмо с русской темой и английским текстом.
     */
    public static function say(string $event, string|\Closure $message, array|\Closure $details = []): void
    {
        if (!self::enabled($event)) return;
        $to = self::recipients();
        if ($to === []) return;

        $held = self::hold($event);
        if ($held !== null) {
            /* Окно ещё не закрылось — только считаем. Письмо об этом уйдёт
               следующим, вместе со строкой «и ещё столько-то». */
            Setting::set('notify.admin.held.' . $event, (string)($held + 1));
            return;
        }

        $skipped = (int)Setting::get('notify.admin.held.' . $event, '0');
        Setting::set('notify.admin.held.' . $event, '0');
        Setting::set('notify.admin.at.' . $event, now());
        Setting::flush();

        foreach ($to as $addr) {
            Mailer::as($addr, static function () use ($addr, $event, $message, $details, $skipped): void {
                $title = t(self::EVENTS[$event][0]);
                $rows = $details instanceof \Closure ? $details() : $details;
                if ($skipped > 0) {
                    $rows[t('И ещё')] = t(':n за последние :min мин', [
                        'n' => $skipped, 'min' => self::windowMinutes(),
                    ]);
                }
                Mailer::send($addr, $title . ' — ' . Setting::appName(), 'admin_alert', [
                    'name' => '',
                    'title' => $title,
                    'message' => $message instanceof \Closure ? $message() : $message,
                    'rows' => $rows,
                    'securityNote' => '',
                ]);
            });
        }
        Log::info('admin_notify', ['event' => $event, 'to' => count($to), 'skipped' => $skipped]);
    }

    /**
     * Сколько событий придержано, если окно ещё не закрылось; null — можно слать.
     */
    private static function hold(string $event): ?int
    {
        $last = (string)Setting::get('notify.admin.at.' . $event, '');
        if ($last === '') return null;
        $passed = time() - strtotime($last . ' UTC');
        if ($passed >= self::windowMinutes() * 60) return null;
        return (int)Setting::get('notify.admin.held.' . $event, '0');
    }
}
