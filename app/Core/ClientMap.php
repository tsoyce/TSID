<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Карта разделов сервиса (v4.30).
 *
 * Была карточка с семью вкладками — реквизиты, артефакты, заявки, роли,
 * приглашения, доставка, история. Семь вкладок на одном адресе означают, что
 * сослаться нельзя ни на одну: ни коллеге в переписке, ни себе в закладку, ни
 * поиску внутри админки. После сохранения человек всякий раз оказывался на
 * первой и искал, где он был.
 *
 * Разделы те же, что были вкладками: их состав придуман не сегодня и проверен
 * работой. Меняется навигация, а не смысл.
 */
final class ClientMap extends ObjectMap
{
    public const GROUPS = [
        'setup' => 'Подключение',
        'people' => 'Люди и роли',
        'watch' => 'Наблюдение',
        'danger' => 'Опасное',
    ];

    public static function groups(): array
    {
        return self::GROUPS;
    }

    public static function base(array $object): string
    {
        return '/admin/clients/' . (int)$object['id'];
    }

    public static function title(array $object): string
    {
        return (string)$object['name'];
    }

    public static function marks(array $object): array
    {
        return [(string)$object['slug'], (string)$object['client_id']];
    }

    public static function backLink(): array
    {
        return [t('Порталы'), '/admin/clients'];
    }

    /** Иконка портала — та же, что в списке порталов и на экране входа. */
    public static function badge(array $object): string
    {
        return client_icon_html($object, 40);
    }

    public static function sections(): array
    {
        return [
            /* Обзор (v4.91): карточка открывается сводкой «как дела у сервиса»,
               а не длинной формой реквизитов — как карточка организации. */
            ['key' => 'overview', 'group' => 'setup', 'icon' => 'portal',
             'title' => 'Обзор', 'path' => '',
             'desc' => 'Люди, вход, связь с порталом и что требует внимания',
             'perm' => 'clients.read'],
            ['key' => 'req', 'group' => 'setup', 'icon' => 'settings',
             'title' => 'Реквизиты', 'path' => '/req',
             'desc' => 'Название, адреса, политика доступа, организация-владелец',
             'perm' => 'clients.read'],
            ['key' => 'artifacts', 'group' => 'setup', 'icon' => 'download',
             'title' => 'Подключение', 'path' => '/artifacts',
             'desc' => 'Связь с порталом, ссылка установки, набор файлов, ключи',
             'perm' => 'clients.read'],
            /* Экран входа (v4.71): оформление жило только в манифесте портала,
               и поменять его можно было лишь правкой чужого сайта. */
            ['key' => 'look', 'group' => 'setup', 'icon' => 'palette',
             'title' => 'Экран входа', 'path' => '/look',
             'desc' => 'Как открывается и выглядит вход в этот сервис: окно или страница, цвета, шрифты, способы входа',
             'perm' => 'clients.read'],

            ['key' => 'people', 'group' => 'people', 'icon' => 'users',
             'title' => 'Пользователи', 'path' => '/people',
             'desc' => 'Кто ходит в сервис, их роли, заявки и перенос людей с портала',
             'perm' => 'users.read'],
            ['key' => 'roles', 'group' => 'people', 'icon' => 'role',
             'title' => 'Роли', 'path' => '/roles',
             'desc' => 'Роли сервиса и карта соответствия с его собственными',
             'perm' => 'clients.read'],
            ['key' => 'invites', 'group' => 'people', 'icon' => 'mail',
             'title' => 'Приглашения', 'path' => '/invites',
             'desc' => 'Ссылки-приглашения в этот сервис',
             'perm' => 'clients.write'],

            ['key' => 'events', 'group' => 'watch', 'icon' => 'activity',
             'title' => 'Доставка', 'path' => '/events',
             'desc' => 'Очередь событий порталу: выход, блокировка, отзыв доступа',
             'perm' => 'clients.read'],
            ['key' => 'history', 'group' => 'watch', 'icon' => 'log',
             'title' => 'Журнал', 'path' => '/history',
             'desc' => 'Что происходило с этим сервисом',
             'perm' => 'logs.read'],

            ['key' => 'danger', 'group' => 'danger', 'icon' => 'alert-triangle',
             'title' => 'Опасная зона', 'path' => '/danger',
             'desc' => 'Удаление сервиса со всем, что к нему привязано',
             'perm' => 'clients.delete'],
        ];
    }
}
