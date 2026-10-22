<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Рабочее место объекта: колонка разделов слева, содержимое справа (v4.30).
 *
 * Один механизм на три вида объектов — организацию, сервис, человека. До этого
 * каждый был экраном со вкладками, и каждый по-своему: у портала семь вкладок,
 * у человека пять, у организации четыре, и ни одна не давала ссылку на раздел.
 * Вкладка — не навигация: на неё нельзя сослаться, её не найдёт поиск, после
 * сохранения человек оказывается на первой.
 *
 * Настройки прошли этот путь в v4.10 и с тех пор устроены единственно верно
 * для такой задачи: у раздела свой адрес, своя форма и одна полоса сохранения.
 * Здесь то же самое, но параметризованное объектом, — чтобы «единый SSO» был
 * не лозунгом, а одной реализацией на всех.
 *
 * Наследник описывает ЧТО у объекта за разделы; как это рисуется, куда ведут
 * ссылки и кому что видно — общее и живёт здесь.
 */
abstract class ObjectMap
{
    /**
     * Группы разделов: ключ → подпись. Порядок — от повседневного к опасному.
     *
     * @return array<string, string>
     */
    abstract public static function groups(): array;

    /**
     * Разделы. Каждый: key, group, icon, title, path, desc, perm.
     * `path` дописывается к базовому адресу объекта; пустой — сам объект.
     *
     * @return list<array{key:string,group:string,icon:string,title:string,
     *                    path:string,desc:string,perm:string}>
     */
    abstract public static function sections(): array;

    /** Базовый адрес объекта: `/admin/orgs/12`. */
    abstract public static function base(array $object): string;

    /** Имя объекта для шапки колонки. */
    abstract public static function title(array $object): string;

    /**
     * Машинные имена — слаг, идентификатор для конфигов и логов.
     *
     * Стоят рядом с человеческим именем, а не прячутся во вкладке: их
     * подставляют в чужие конфиги и ищут в журналах (решение v4.13).
     *
     * @return list<string>
     */
    abstract public static function marks(array $object): array;

    /**
     * Лицо объекта в шапке колонки (v4.69): иконка портала, аватар человека,
     * знак организации — то, по чему объект узнают в других местах. Разметка
     * уже экранирована. По умолчанию лица нет.
     */
    public static function badge(array $object): string
    {
        return '';
    }

    public static function find(string $key): ?array
    {
        foreach (static::sections() as $s) if ($s['key'] === $key) return $s;
        return null;
    }

    /**
     * Разделы, доступные текущему человеку, по группам.
     *
     * Прячем то, на что нет права: раздел, который открывается только чтобы
     * ответить 403, — обещание, которого интерфейс не держит.
     *
     * @return array<string, list<array>>
     */
    public static function visible(): array
    {
        $out = [];
        foreach (static::sections() as $s) {
            if (($s['perm'] ?? '') !== '' && !Auth::can($s['perm'])) continue;
            $out[$s['group']][] = $s;
        }
        return $out;
    }

    /** Адрес раздела у конкретного объекта. */
    public static function route(array $object, string $key): string
    {
        $s = static::find($key);
        return static::base($object) . ($s['path'] ?? '');
    }

    /**
     * Куда ведёт первая хлебная крошка: подпись и адрес списка.
     *
     * @return array{0:string,1:string}
     */
    abstract public static function backLink(): array;
}
