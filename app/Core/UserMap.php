<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Карта разделов человека (v4.30).
 *
 * Была карточка с пятью вкладками. Причина переезда та же, что у организации и
 * сервиса: на вкладку нельзя сослаться. Для человека это чувствуется острее
 * прочих — карточкой делятся в переписке чаще всего: «посмотри его сессии»,
 * «глянь, что у него с доступами».
 */
final class UserMap extends ObjectMap
{
    public const GROUPS = [
        'main' => 'Человек',
        'access' => 'Доступ',
        'watch' => 'Наблюдение',
        'danger' => 'Опасное',
    ];

    public static function groups(): array
    {
        return self::GROUPS;
    }

    public static function base(array $object): string
    {
        return '/admin/users/' . (int)$object['id'];
    }

    public static function title(array $object): string
    {
        /* Только имя (v4.70): почта и логин стоят меткой ниже и в реквизитах —
           в шапке колонки «Иван Петров · admin@test.local» рвался посреди адреса. */
        return user_name($object);
    }

    public static function marks(array $object): array
    {
        $marks = [];
        /* Как везде в интерфейсе (v4.70): логин с @, номер — группами. */
        if (!empty($object['login'])) $marks[] = '@' . $object['login'];
        if (!empty($object['account_number'])) $marks[] = \App\Core\AccountNumber::format((string)$object['account_number']);
        $marks[] = 'user_' . (int)$object['id'];
        return $marks;
    }

    public static function backLink(): array
    {
        return [t('Пользователи'), '/admin/users'];
    }

    /** Аватар человека — тот же, что в списках. */
    public static function badge(array $object): string
    {
        return avatar_html($object, 40);
    }

    public static function sections(): array
    {
        return [
            ['key' => 'profile', 'group' => 'main', 'icon' => 'user',
             'title' => 'Профиль', 'path' => '',
             'desc' => 'Имя, почта, логин, статус',
             'perm' => 'users.read'],

            ['key' => 'access', 'group' => 'access', 'icon' => 'portal',
             'title' => 'Сервисы', 'path' => '/access',
             'desc' => 'Куда человек допущен и через что — сам, организацию или приглашение',
             'perm' => 'users.read'],
            ['key' => 'roles', 'group' => 'access', 'icon' => 'role',
             'title' => 'Роли', 'path' => '/roles',
             'desc' => 'Что и в какой области ему выдано',
             'perm' => 'users.read'],
            ['key' => 'sessions', 'group' => 'access', 'icon' => 'desktop',
             'title' => 'Сессии', 'path' => '/sessions',
             'desc' => 'Живые входы и устройства',
             'perm' => 'users.read'],

            ['key' => 'audit', 'group' => 'watch', 'icon' => 'log',
             'title' => 'Журнал', 'path' => '/audit',
             'desc' => 'Что происходило с этим человеком и что делал он сам',
             'perm' => 'logs.read'],
            /* Активность (v4.72): не события, а каждый запрос — какие страницы
               открывал, куда стучался без прав, откуда приходил. */
            ['key' => 'activity', 'group' => 'watch', 'icon' => 'activity',
             'title' => 'Активность', 'path' => '/activity',
             'desc' => 'Откуда приходил и что открывал: каждый запрос',
             'perm' => 'logs.read'],

            /* Действия и опасная зона — разное. Первое повседневно: заблокировать,
               выдать ссылку для входа, посмотреть систему его глазами. Второе за
               паролем и необратимо. Класть их вместе значит либо прятать за
               паролем обычное, либо ставить необратимое рядом с обычным. */
            ['key' => 'actions', 'group' => 'danger', 'icon' => 'tool',
             'title' => 'Действия', 'path' => '/actions',
             'desc' => 'Блокировка, ссылка для входа, вход от имени',
             'perm' => 'users.write'],
            /* Объединение (v4.88): человек завёл второй аккаунт — через другой
               Яндекс, другую почту. Механизм тот же, что у самого человека в
               профиле (AccountMerge), только обе стороны выбирает администратор. */
            ['key' => 'merge', 'group' => 'danger', 'icon' => 'users',
             'title' => 'Объединить', 'path' => '/merge',
             'desc' => 'Слить дубль в этот аккаунт или этот — в другой',
             'perm' => 'users.delete'],
            ['key' => 'danger', 'group' => 'danger', 'icon' => 'alert-triangle',
             'title' => 'Опасная зона', 'path' => '/danger',
             'desc' => 'Окончательное удаление мимо 14-дневного холда',
             'perm' => 'users.delete'],
        ];
    }
}
