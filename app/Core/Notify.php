<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Уведомления безопасности. Каждый тип — отдельный переключатель у пользователя,
 * всё можно выключить (принцип опциональности). Дефолты — только критичное.
 */
final class Notify
{
    /** event => [заголовок в настройках, включено по умолчанию] */
    public const EVENTS = [
        'login.new_device' => ['Вход с нового устройства', true],
        'password.change' => ['Смена пароля', true],
        'passkey.change' => ['Добавлен или удалён passkey', true],
        '2fa.change' => ['Включена или выключена 2FA', true],
        'access.granted' => ['Выдан доступ к порталу', false],
        'login.failed_burst' => ['Серия неудачных попыток входа', false],
    ];

    public static function enabled(int $userId, string $event): bool
    {
        $default = self::EVENTS[$event][1] ?? false;
        /* Схема может отставать от кода: миграцию 003 ещё не применили.
           Тогда работаем на значениях по умолчанию, а не роняем вход. */
        if (!DB::tableExists('notification_prefs')) return $default;
        $row = DB::row('SELECT enabled FROM {notification_prefs} WHERE user_id = ? AND event = ?',
            [$userId, $event]);
        return $row === null ? $default : (bool)$row['enabled'];
    }

    /** Все настройки пользователя для экрана «Уведомления». */
    public static function prefsFor(int $userId): array
    {
        $out = [];
        foreach (self::EVENTS as $event => [$label, $default]) {
            $out[$event] = ['label' => $label, 'enabled' => self::enabled($userId, $event)];
        }
        return $out;
    }

    public static function setPref(int $userId, string $event, bool $enabled): void
    {
        if (!isset(self::EVENTS[$event])) return;
        $exists = DB::row('SELECT 1 AS x FROM {notification_prefs} WHERE user_id = ? AND event = ?', [$userId, $event]);
        if ($exists) {
            DB::update('notification_prefs', ['enabled' => $enabled ? 1 : 0],
                'user_id = ? AND event = ?', [$userId, $event]);
        } else {
            DB::insert('notification_prefs', ['user_id' => $userId, 'event' => $event, 'enabled' => $enabled ? 1 : 0]);
        }
    }

    /**
     * Отправить письмо, если пользователь этого не запретил.
     * $data попадает в шаблон emails/security_alert.
     */
    /* Тема и текст функцией (v4.74) — собираются на языке получателя: письмо о
       подборе пароля будит незнакомец, и собранное заранее оно шло на языке
       его браузера. */
    public static function send(array $user, string $event, string|\Closure $subject, string|\Closure $message, array $extra = []): void
    {
        if (!self::enabled((int)$user['id'], $event)) return;
        /* Писать некуда — молча выходим. У аккаунта, перенесённого из портала с
           регистрацией по логину, почты нет вовсе, и попытка отправки стала бы
           исключением на каждом входе. Это не потеря: уведомление о событии
           безопасности и так лишь дополняет журнал, который никуда не делся. */
        if (empty($user['email'])) return;
        try {
            Mailer::as($user['email'], static fn() => Mailer::send($user['email'],
                $subject instanceof \Closure ? $subject() : $subject, 'security_alert', [
                'name' => user_name($user),
                'message' => $message instanceof \Closure ? $message() : $message,
                'event' => $event,
                'when' => fmt_dt(now(), 'd.m.Y H:i') . ' (' . tz_gmt_label($user['timezone']) . ')',
                'device' => device_text($extra['device'] ?? device_label()),
                'ip' => $extra['ip'] ?? ip_masked(ip_bin()),
                'action_url' => $extra['action_url'] ?? base_url('/profile/security'),
                'action_label' => $extra['action_label'] ?? t('Проверить безопасность'),
            ]));
        } catch (\Throwable $e) {
            Log::warning('notify_failed', ['event' => $event, 'err' => $e->getMessage()]);
        }
    }

    /**
     * Вход: запоминаем устройство и предупреждаем, если оно новое.
     * Отпечаток — device_label + подсеть /24: смена IP в пределах провайдера
     * не поднимает ложную тревогу, а новый ноутбук или город — поднимает.
     */
    public static function afterLogin(array $user): void
    {
        /* Без таблицы известных устройств просто не шлём письмо о новом входе —
           это уведомление, а не условие входа. */
        if (!DB::tableExists('known_devices')) return;
        $fp = self::fingerprint();
        $known = DB::row('SELECT id FROM {known_devices} WHERE user_id = ? AND fingerprint = ?',
            [(int)$user['id'], $fp]);

        if ($known) {
            DB::update('known_devices', ['last_seen_at' => now()], 'id = ?', [(int)$known['id']]);
            return;
        }

        $first = (int)DB::val('SELECT COUNT(*) FROM {known_devices} WHERE user_id = ?', [(int)$user['id']]) === 0;
        DB::insert('known_devices', [
            'user_id' => (int)$user['id'],
            'fingerprint' => $fp,
            'device_label' => device_label(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        // Самый первый вход — не «новое устройство», а начало истории
        if ($first) return;

        self::send($user, 'login.new_device',
            t('Новый вход в :app', ['app' => Setting::appName()]),
            t('В ваш аккаунт вошли с устройства, которое мы видим впервые. Если это были вы — ничего делать не нужно.'),
            ['action_url' => base_url('/profile/sessions'), 'action_label' => t('Посмотреть устройства')]);
    }

    private static function fingerprint(): string
    {
        $ip = request_ip();
        $subnet = $ip;
        if (str_contains($ip, '.')) {
            $p = explode('.', $ip);
            $subnet = ($p[0] ?? '') . '.' . ($p[1] ?? '') . '.' . ($p[2] ?? '') . '.0';
        } elseif (str_contains($ip, ':')) {
            $subnet = implode(':', array_slice(explode(':', $ip), 0, 4));
        }
        return hash('sha256', device_label() . '|' . $subnet);
    }
}
