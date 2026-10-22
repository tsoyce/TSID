<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Карта разделов организации — рабочее место, а не карточка (v4.29).
 *
 * До этого организация была одним экраном с вкладками. Вкладка — не навигация:
 * у неё нет своего адреса, на неё нельзя сослаться, её не найдёт поиск, и после
 * сохранения человек оказывается на первой. Ровно по этой причине настройки
 * когда-то переехали из вкладок в оболочку с колонкой (SettingsMap), и здесь
 * повторяется тот же ответ на ту же задачу.
 *
 * Смысл разделения по областям (SCOPES.md) — в том, чтобы у организации была
 * своя работа: её сервисы, её люди, её роли, её журнал. Механизм для этого
 * появился в v4.23–4.27, а места, где им пользуются, не было: администратор
 * заходил в организацию и по-прежнему управлял всем из общих списков.
 *
 * Здесь — только карта: что за раздел и чем рисуется. Обработчики остаются в
 * контроллере.
 */
final class OrgMap extends ObjectMap
{
    /** Группы: сперва то, чем управляют каждый день, опасное — последним. */
    public const GROUPS = [
        'main' => 'Организация',
        'people' => 'Люди и доступ',
        'watch' => 'Наблюдение',
        'danger' => 'Опасное',
    ];

    public static function groups(): array
    {
        return self::GROUPS;
    }

    public static function base(array $object): string
    {
        return '/admin/orgs/' . (int)$object['id'];
    }

    public static function title(array $object): string
    {
        return (string)$object['name'];
    }

    public static function marks(array $object): array
    {
        return [(string)$object['slug'], 'org_' . (int)$object['id']];
    }

    public static function backLink(): array
    {
        return [t('Организации'), '/admin/orgs'];
    }

    /**
     * Знак организации: две буквы названия на подложке акцента. Своего
     * логотипа у организации нет, а пустое место рядом с именем читается как
     * «картинка не загрузилась».
     */
    public static function badge(array $object): string
    {
        /* v4.90: тот же знак, что в списке организаций, и той же формы, что у
           портала, — раньше здесь был бледный круг, а в списке знака не было. */
        return org_icon_html($object, 40);
    }

    /**
     * @return list<array{key:string,title:string,group:string,icon:string,
     *                    desc:string,path:string,perm:string,owner:bool}>
     */
    public static function sections(): array
    {
        return [
            ['key' => 'overview', 'group' => 'main', 'icon' => 'portal',
             'title' => 'Обзор', 'path' => '',
             'desc' => 'Числа организации, название и состояние',
             'perm' => 'orgs.read', 'owner' => false],
            ['key' => 'projects', 'group' => 'main', 'icon' => 'desktop',
             'title' => 'Сервисы', 'path' => '/projects',
             'desc' => 'Порталы, принадлежащие организации. Роль на организацию действует во всех них',
             'perm' => 'orgs.read', 'owner' => false],

            ['key' => 'people', 'group' => 'people', 'icon' => 'users',
             'title' => 'Пользователи', 'path' => '/people',
             /* «Роли» отдельным разделом больше нет (v4.68): своих ролей у
                организации нет, а выдача на её область — в строке участника. */
             'desc' => 'Состав организации, роли участников и люди её сервисов',
             'perm' => 'orgs.read', 'owner' => false],
            ['key' => 'access', 'group' => 'people', 'icon' => 'key',
             'title' => 'Доступы', 'path' => '/access',
             'desc' => 'Чужие порталы, куда пускают участников организации',
             'perm' => 'orgs.write', 'owner' => false],

            ['key' => 'logs', 'group' => 'watch', 'icon' => 'log',
             'title' => 'Журнал', 'path' => '/logs',
             'desc' => 'События организации и её сервисов',
             'perm' => 'logs.read', 'owner' => false],

            ['key' => 'danger', 'group' => 'danger', 'icon' => 'alert-triangle',
             'title' => 'Опасная зона', 'path' => '/danger',
             'desc' => 'Удаление организации со всем, что к ней привязано',
             'perm' => 'orgs.delete', 'owner' => false],
        ];
    }



}
