<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\DB;
use App\Models\Client;

/**
 * Источник данных для командной палитры (Ctrl+K): разделы, пользователи,
 * порталы, быстрые действия. Права соблюдаются: client_admin видит только
 * свой скоуп, обычный пользователь — только разделы профиля.
 */
final class SearchController
{
    private const LIMIT = 6;

    public function search(): never
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $isAdmin = Auth::isAdmin();
        $scope = $isAdmin ? null : Auth::clientAdminIds();
        $groups = [];

        /* Разделы — всегда, фильтруются по подстроке */
        $sections = $this->sections($isAdmin, $scope !== [] && $scope !== null);
        $matched = $this->filter($sections, $q);
        if ($matched !== []) {
            $groups[] = ['title' => t('Разделы'), 'items' => array_slice($matched, 0, self::LIMIT)];
        }

        /* Дальше — только для админской зоны */
        if ($isAdmin || ($scope !== null && $scope !== [])) {
            if ($q !== '') {
                $users = $this->users($q, $scope);
                if ($users !== []) $groups[] = ['title' => t('Пользователи'), 'items' => $users];

                $clients = $this->clients($q, $scope);
                if ($clients !== []) $groups[] = ['title' => t('Порталы'), 'items' => $clients];
            }
            $actions = $this->filter($this->actions($isAdmin), $q);
            if ($actions !== []) {
                $groups[] = ['title' => t('Действия'), 'items' => array_slice($actions, 0, self::LIMIT)];
            }
        }

        json_response(['groups' => $groups]);
    }

    /**
     * Разделы для палитры — из тех же списков, что рисуют навигацию.
     *
     * Здесь стоял третий список тех же разделов, набранный руками. Он и разошёлся
     * с остальными: настроек стало четырнадцать, а палитра знала о трёх —
     * «Реквизиты», «Оформление сайта», «Политики» и восемь других не находились
     * вовсе. Причём выглядело это исправным: пункт «Настройки» в палитре был.
     *
     * Теперь источников два, и оба уже существуют: `nav_sections()` для разделов
     * навигации и `SettingsMap` для настроек. Новый раздел появляется в поиске
     * сам, потому что появляется в них.
     *
     * @return array<int, array{label:string, url:string, icon:string, hint?:string}>
     */
    private function sections(bool $isAdmin, bool $isClientAdmin): array
    {
        $items = [];
        $seen = [];
        /* Один и тот же раздел приезжает сюда дважды: из навигации и из карты
           настроек. Дубль отсекался по адресу, и выигрывал ПЕРВЫЙ — то есть
           навигационный, у которого пояснения нет. Пять разделов, попавших в
           навигацию (система, инструменты, здоровье, вебхуки, оформление),
           теряли из-за этого своё пояснение целиком, и поиск по нему молчал:
           «домен» не находил «Систему», хотя её подпись начинается со слова
           «Домен». Поэтому повтор не отбрасывается, а дополняет: пришло
           пояснение — забираем, остальное оставляем как было. */
        $add = static function (string $label, string $url, string $icon, string $hint = '')
            use (&$items, &$seen): void {
            if (isset($seen[$url])) {
                if ($hint !== '' && ($items[$seen[$url]]['hint'] ?? '') === '') {
                    $items[$seen[$url]]['hint'] = $hint;
                }
                return;
            }
            $seen[$url] = count($items);
            $items[] = array_filter(['label' => $label, 'url' => $url, 'icon' => $icon, 'hint' => $hint]);
        };

        foreach (nav_sections(false)['primary'] as $n) $add($n['label'], $n['href'], $n['icon']);

        if ($isAdmin || $isClientAdmin) {
            $nav = nav_sections(true);
            foreach ([...$nav['primary'], ...$nav['more']] as $n) $add($n['label'], $n['href'], $n['icon']);
            $add(t('Дашборд'), '/admin', 'chart');
        }
        if ($isAdmin) {
            /* Журналы навигация показывает одним пунктом, а ищут их по именам
               вкладок: «ошибки» и «почта» — то, что человек набирает. */
            $add(t('Журнал приложения'), '/admin/logs/errors', 'alert-triangle');
            $add(t('Почта'), '/admin/logs/mail', 'mail');
            /* Разделы настроек: подпись раздела становится подсказкой — по ней
               находят то, чьё название не помнят («где менять срок сессии»). */
            foreach (\App\Core\SettingsMap::visible() as $group) {
                foreach ($group as $s) $add(t($s['title']), $s['route'], $s['icon'], t($s['desc']));
            }
        }
        return $items;
    }

    private function actions(bool $isAdmin): array
    {
        $items = [
            /* `?do=` доводит до дела, а не до страницы: мастер подключения —
               модалка на списке порталов, и пункт палитры высаживал человека
               рядом с ней. Открывать её теперь умеет сам адрес (см. app.js). */
            ['label' => t('Подключить сервис'), 'url' => '/admin/clients?do=wizard', 'icon' => 'plus', 'hint' => t('мастер за два клика')],
        ];
        if ($isAdmin) {
            $items[] = ['label' => t('Импорт пользователей'), 'url' => '/admin/tools?do=import', 'icon' => 'upload'];
            $items[] = ['label' => t('Проверить SMTP'), 'url' => '/admin/settings/section/smtp?do=smtp-out', 'icon' => 'mail'];
            $items[] = ['label' => t('Экспорт аудита в CSV'), 'url' => '/admin/logs/export.csv', 'icon' => 'download'];
            $items[] = ['label' => t('Ротация ключей'), 'url' => '/admin/settings/section/keys', 'icon' => 'refresh'];
            $items[] = ['label' => t('Применить миграции'), 'url' => '/admin/tools?do=migrations', 'icon' => 'refresh'];
        }
        $items[] = ['label' => t('Выйти'), 'url' => '/logout', 'icon' => 'logout'];
        return $items;
    }

    private function users(string $q, ?array $scope): array
    {
        $where = ['u.status != ?'];
        $params = ['deleted'];
        if ($scope !== null) {
            /* Пустой список сужения означал «условие не добавляем», то есть
               поиск показывал ВСЕХ людей установки тому, кому не видно ни
               одного портала. Пустой список — это «ничего», а не «всё». */
            $where[] = 'u.id IN (SELECT user_id FROM {user_client_access} WHERE '
                . $this->inIds('client_id', $scope) . ')';
        }
        [$sqlPart, $qParams] = user_search_clause($q);
        $where[] = $sqlPart;
        array_push($params, ...$qParams);

        $rows = DB::all('SELECT u.* FROM {users} u WHERE ' . implode(' AND ', $where)
            . ' ORDER BY u.last_login_at DESC LIMIT ' . self::LIMIT, $params);

        return array_map(fn($u) => [
            'label' => user_name($u),
            'hint' => $u['email'],
            'url' => '/admin/users/' . $u['id'],
            'icon' => 'user',
        ], $rows);
    }

    private function clients(string $q, ?array $scope): array
    {
        $out = [];
        foreach (Client::all() as $c) {
            if ($scope !== null && $scope !== [] && !in_array((int)$c['id'], $scope, true)) continue;
            if (mb_stripos($c['name'], $q) === false && mb_stripos($c['slug'], $q) === false
                && mb_stripos($c['base_url'], $q) === false) continue;
            $out[] = [
                'label' => $c['name'],
                'hint' => $c['base_url'],
                'url' => '/admin/clients/' . $c['id'],
                'icon' => 'portal',
            ];
            if (count($out) >= self::LIMIT) break;
        }
        return $out;
    }

    /** Подстрочный фильтр по label и hint (регистронезависимо). */
    /**
     * Отбор с порядком: сначала совпавшие названием, потом подсказкой.
     *
     * Группа обрезается шестью пунктами, и без порядка в эти шесть попадало
     * что придётся: раздел, у которого запрос стоит в начале названия, мог не
     * попасть вовсе, потому что раньше в список встал другой — с совпадением в
     * длинном пояснении. Внешне это выглядело как «поиск не находит».
     */
    private function filter(array $items, string $q): array
    {
        if ($q === '') return $items;

        /* Запрос из нескольких слов раньше искался целиком одной строкой, и
           «срок сессии» не находил ничего: в подписи стоит «Сессия, токены», а
           в названии «Сроки» — целиком такой строки нет нигде. Человек при этом
           набрал ровно те слова, которыми раздел и описан.
           Ищем по словам. Достаточно одного совпавшего — совпавших больше
           поднимает выше. Слов из одной буквы не берём: они совпадают со всем
           подряд и перемешивают выдачу. */
        $words = array_values(array_filter(
            preg_split('~\s+~u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn(string $w): bool => mb_strlen($w) > 1
        ));
        if ($words === []) $words = [$q];

        $ranked = [];
        foreach ($items as $i) {
            $label = (string)$i['label'];
            $hint = (string)($i['hint'] ?? '');
            $best = null;
            $hits = 0;
            foreach ($words as $w) {
                $atLabel = mb_stripos($label, $w);
                $atHint = mb_stripos($hint, $w);
                if ($atLabel === false && $atHint === false) continue;
                $hits++;
                /* 0 — название начинается со слова, 1 — название содержит,
                   2 — совпало только пояснение. Берём лучшее из слов. */
                $rank = $atLabel === 0 ? 0 : ($atLabel !== false ? 1 : 2);
                $best = $best === null ? $rank : min($best, $rank);
            }
            if ($best === null) continue;
            $ranked[] = [$best, -$hits, mb_strlen($label), $i];
        }
        usort($ranked, static fn(array $a, array $b): int
            => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        return array_column($ranked, 3);
    }
}
