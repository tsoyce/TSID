<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Карта разделов настроек — один список на весь интерфейс (v4.9).
 *
 * До этого настройки жили в шести разных местах: восемь вкладок в
 * `/admin/settings`, отдельные экраны оформления, вебхуков, здоровья,
 * инструментов и системы. Каждый заводился по своему поводу и в своё время, и
 * по отдельности каждый выглядел уместным. Вместе — «настройки где-то есть».
 *
 * Найти настройку человек может только одним способом: перебрать разделы
 * глазами. Значит разделы обязаны лежать в одном перечне, а перечень — быть
 * один и тот же на всех экранах: и в боковой колонке, и в поиске, и в
 * хлебных крошках. Два списка рядом разошлись бы при первой правке, и
 * разошлись бы молча.
 *
 * Здесь — только карта: что за раздел, кому виден, чем рисуется. Обработчики
 * остаются каждый в своём контроллере: сведение экранов в один список не повод
 * сваливать в одну кучу разбор форм.
 */
final class SettingsMap
{
    /**
     * Группы. Порядок — от повседневного к опасному: вниз по списку растёт
     * цена ошибки, и владелец добирается до сброса проекта последним, а не
     * встречает его между почтой и вебхуками.
     */
    public const GROUPS = [
        'look' => 'Как выглядит',
        'auth' => 'Как входят',
        'legal' => 'Документы и данные',
        'tech' => 'Техника',
        'danger' => 'Хозяину',
    ];

    /**
     * @return list<array{key:string,title:string,group:string,icon:string,
     *                    desc:string,route:string,perm:?string,owner:bool}>
     */
    public static function sections(): array
    {
        return [
            ['key' => 'branding', 'group' => 'look', 'icon' => 'settings',
             'title' => 'Брендинг', 'route' => '/admin/settings',
             'desc' => 'Имя установки, акцентный цвет, логотип, favicon',
             'perm' => 'settings.read', 'owner' => false],
            ['key' => 'appearance', 'group' => 'look', 'icon' => 'eye',
             'title' => 'Оформление сайта', 'route' => '/admin/appearance',
             'desc' => 'Главная, экран входа и вид всего сайта: схемы, цвета, шрифты, форма',
             'perm' => 'settings.read', 'owner' => false],

            ['key' => 'policies', 'group' => 'auth', 'icon' => 'shield',
             'title' => 'Вход', 'route' => '/admin/settings/section/policies',
             'desc' => 'Пароль, блокировки, 2FA, замок после простоя, данные профиля',
             'perm' => 'settings.read', 'owner' => false],
            ['key' => 'signup', 'group' => 'auth', 'icon' => 'user',
             'title' => 'Регистрация', 'route' => '/admin/settings/section/signup',
             'desc' => 'Кто может завести аккаунт, домены почты, логин, капча',
             'perm' => 'settings.read', 'owner' => false],
            ['key' => 'timeouts', 'group' => 'auth', 'icon' => 'clock',
             'title' => 'Сроки', 'route' => '/admin/settings/section/timeouts',
             'desc' => 'Сессия, токены, ссылки из писем',
             'perm' => 'settings.read', 'owner' => false],
            ['key' => 'social', 'group' => 'auth', 'icon' => 'external',
             'title' => 'Вход через сервисы', 'route' => '/admin/settings/section/social',
             'desc' => 'Telegram, VK, Яндекс, GitHub',
             'perm' => 'settings.read', 'owner' => false],
            /* Блокировки (v4.72): адреса, с которых не открывается ни одна страница. */
            ['key' => 'blocks', 'group' => 'auth', 'icon' => 'lock',
             'title' => 'Блокировки', 'route' => '/admin/blocks',
             'desc' => 'Адреса и подсети, с которых доступ закрыт',
             'perm' => 'settings.read', 'owner' => false],
            ['key' => 'keys', 'group' => 'auth', 'icon' => 'key',
             'title' => 'Ключи', 'route' => '/admin/settings/section/keys',
             'desc' => 'Подписи токенов, ротация JWKS',
             'perm' => 'settings.read', 'owner' => false],

            ['key' => 'requisites', 'group' => 'legal', 'icon' => 'badge-check',
             'title' => 'Реквизиты', 'route' => '/admin/settings/requisites',
             'desc' => 'Данные владельца: заполняются один раз и подставляются во все документы',
             'perm' => 'settings.read', 'owner' => false],
            ['key' => 'docs', 'group' => 'legal', 'icon' => 'log',
             'title' => 'Документы', 'route' => '/admin/settings/section/docs',
             'desc' => 'Политика, согласие, соглашение, реквизиты',
             'perm' => 'settings.read', 'owner' => false],
            ['key' => 'smtp', 'group' => 'legal', 'icon' => 'mail',
             'title' => 'Почта', 'route' => '/admin/settings/section/smtp',
             'desc' => 'SMTP-сервер, отправитель, проверка отправки',
             'perm' => 'settings.read', 'owner' => false],
            ['key' => 'letters', 'group' => 'legal', 'icon' => 'mail',
             'title' => 'Письма', 'route' => '/admin/settings/section/letters',
             'desc' => 'Тексты писем, тема, подпись, предпросмотр',
             'perm' => 'settings.read', 'owner' => false],

            ['key' => 'alerts', 'group' => 'tech', 'icon' => 'bell',
             'title' => 'Уведомления', 'route' => '/admin/settings/section/alerts',
             'desc' => 'Письма владельцу: регистрации, блокировки, подбор пароля',
             'perm' => 'settings.read', 'owner' => false],
            ['key' => 'webhooks', 'group' => 'tech', 'icon' => 'activity',
             'title' => 'Webhooks', 'route' => '/admin/webhooks',
             'desc' => 'События наружу с подписью HMAC',
             'perm' => 'settings.read', 'owner' => false],
            ['key' => 'health', 'group' => 'tech', 'icon' => 'chart',
             'title' => 'Здоровье', 'route' => '/admin/health',
             'desc' => 'Проверки окружения, очереди, планировщик',
             'perm' => 'settings.read', 'owner' => false],
            ['key' => 'tools', 'group' => 'tech', 'icon' => 'tool',
             'title' => 'Инструменты', 'route' => '/admin/tools',
             'desc' => 'Импорт людей, бэкап, чистка, миграции',
             'perm' => 'settings.read', 'owner' => false],
            ['key' => 'maintenance', 'group' => 'tech', 'icon' => 'refresh',
             'title' => 'Обслуживание', 'route' => '/admin/settings/section/maintenance',
             'desc' => 'Режим обслуживания, хранение журналов',
             'perm' => 'settings.read', 'owner' => false],

            /* Системный раздел — только владельцу. Не потому, что он сложный,
               а потому, что там смена домена, смена базы и сброс: суперадмина,
               заведшего портал, существование этих кнопок не касается. */
            ['key' => 'system', 'group' => 'danger', 'icon' => 'shield-check',
             'title' => 'Система', 'route' => '/admin/system',
             'desc' => 'Домен, база данных, владение, сброс',
             'perm' => null, 'owner' => true],
        ];
    }

    /** Разделы, доступные текущему человеку, сгруппированные для показа. */
    public static function visible(): array
    {
        $out = [];
        foreach (self::sections() as $s) {
            if ($s['owner'] && !Owner::isCurrent()) continue;
            if ($s['perm'] !== null && !Auth::can($s['perm'])) continue;
            $out[$s['group']][] = $s;
        }
        return $out;
    }

    /** Раздел по ключу — для заголовка и крошек. */
    public static function find(string $key): ?array
    {
        foreach (self::sections() as $s) if ($s['key'] === $key) return $s;
        return null;
    }
}
