<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Карта разделов «Здоровья» (v4.32).
 *
 * Было — один экран со светофором хозяйства: жива база, есть ключи, пишется
 * storage. Это отвечает на вопрос «в порядке ли сервер» и не отвечает на
 * вопрос «работает ли вход». Разница не теоретическая: на боевой не доехал
 * один файл соцвхода, и экран входа отдавал 500 — при полностью зелёном
 * светофоре.
 *
 * Поэтому разделов четыре, и каждый отвечает на свой вопрос:
 *   хозяйство — база, ключи, диск, почта;
 *   поставка  — всё ли доехало при заливке;
 *   прозвон   — работают ли адреса, за которыми к нам ходят;
 *   ошибки    — что ломается и как часто.
 *
 * Оболочка та же, что у настроек и объектов: у каждого раздела свой адрес.
 */
final class HealthMap extends ObjectMap
{
    public const GROUPS = ['now' => 'Сейчас', 'deep' => 'Разбор'];

    public static function groups(): array
    {
        return self::GROUPS;
    }

    /** Здоровье — не объект: адрес у него один на всю установку. */
    public static function base(array $object): string
    {
        return '/admin/health';
    }

    public static function title(array $object): string
    {
        return Setting::appName();
    }

    public static function marks(array $object): array
    {
        return ['v' . SSO_VERSION];
    }

    public static function backLink(): array
    {
        return [t('Настройки'), '/admin/settings'];
    }

    /** Знак самой установки: здоровье — её, а не чьё-то. */
    public static function badge(array $object): string
    {
        return brand_mark();
    }

    public static function sections(): array
    {
        return [
            /* «Хозяйство» было названием ни о чём: раздел отвечает на вопрос
               «что проверено и в каком оно состоянии», а слово отсылало к
               завхозу. Называем тем, что там лежит, — списком проверок. */
            ['key' => 'overview', 'group' => 'now', 'icon' => 'activity',
             'title' => 'Проверки', 'path' => '',
             'desc' => 'База, ключи, диск, почта, очереди — и что из этого в порядке',
             'perm' => 'settings.read'],
            ['key' => 'errors', 'group' => 'now', 'icon' => 'alert-triangle',
             'title' => 'Ошибки', 'path' => '/errors',
             'desc' => 'Что ломается и как часто — за сутки и за неделю',
             'perm' => 'logs.read'],

            ['key' => 'delivery', 'group' => 'deep', 'icon' => 'download',
             'title' => 'Поставка', 'path' => '/delivery',
             'desc' => 'Все ли файлы доехали при заливке',
             'perm' => 'settings.read'],
            ['key' => 'probe', 'group' => 'deep', 'icon' => 'wifi-off',
             'title' => 'Прозвон', 'path' => '/probe',
             'desc' => 'Живые запросы к своим адресам и к порталам',
             'perm' => 'settings.read'],
        ];
    }
}
