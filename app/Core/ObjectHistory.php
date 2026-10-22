<?php

declare(strict_types=1);

namespace App\Core;

/**
 * История одного объекта: кто, что и когда с ним делал (v4.4).
 *
 * Журнал аудита существовал и раньше, но отвечал только на вопрос «что вообще
 * происходило в системе». Вопрос «что происходило вот с этим порталом» решался
 * фильтрами на общей странице логов — то есть человеку нужно было знать, что
 * такая страница есть, дойти до неё и правильно выбрать фильтр.
 *
 * Здесь тот же журнал, но повёрнутый к объекту: одна выборка по индексу
 * (измерение, время). Никакого нового хранилища — история не дублируется, она
 * читается под другим углом.
 *
 * Три измерения, по одному на сущность, — колонками, а не разбором JSON:
 * синтаксис JSON-запросов у MySQL и SQLite разный, и проверка в разработке
 * ничего не сказала бы о боевой (см. миграцию 015).
 */
final class ObjectHistory
{
    /** Колонка журнала для каждого типа объекта. */
    private const COLUMN = [
        'user' => 'on_user_id',
        'client' => 'client_id',
        'org' => 'org_id',
    ];

    /**
     * Последние события объекта, новые сверху.
     *
     * @return list<array{created_at:string,event:string,actor:?string,meta:?array}>
     */
    public static function of(string $type, int $id, int $limit = 30): array
    {
        $column = self::COLUMN[$type] ?? null;
        if ($column === null || $id <= 0) return [];

        /* У человека история двусторонняя: и что делали с ним, и что делал он
           сам. Для портала и организации такого различия нет — они не бывают
           действующим лицом. Условие собирается здесь, а не в вызывающем коде,
           чтобы «история пользователя» везде означала одно и то же. */
        $where = $type === 'user'
            ? '(a.on_user_id = ? OR a.actor_user_id = ?)'
            : 'a.' . $column . ' = ?';
        $params = $type === 'user' ? [$id, $id] : [$id];

        /* Имя того, кто действовал, приезжает джойном, а не вторым запросом:
           строк тридцать, и тридцать запросов на карточку — цена, которой
           «история» не стоит. */
        $rows = DB::all(
            'SELECT a.created_at, a.event, a.meta,
                    u.first_name AS actor_first, u.last_name AS actor_last, u.email AS actor_email
               FROM {audit_log} a
          LEFT JOIN {users} u ON u.id = a.actor_user_id
              WHERE ' . $where . '
           ORDER BY a.created_at DESC, a.id DESC
              LIMIT ' . max(1, min(200, $limit)),
            $params
        );

        $out = [];
        foreach ($rows as $r) {
            $name = trim(((string)($r['actor_first'] ?? '')) . ' ' . ((string)($r['actor_last'] ?? '')));
            if ($name === '') $name = (string)($r['actor_email'] ?? '');
            $out[] = [
                'created_at' => (string)$r['created_at'],
                'event' => (string)$r['event'],
                /* Пусто — значит сделала система: cron, вебхук, миграция.
                   «—» честнее выдуманного имени. */
                'actor' => $name !== '' ? $name : null,
                'meta' => is_string($r['meta'] ?? null)
                    ? (json_decode((string)$r['meta'], true) ?: null)
                    : null,
            ];
        }
        return $out;
    }

    /**
     * Человеческая подпись события.
     *
     * Словарь неполон намеренно: событий под сотню, и половина из них
     * встречается раз в жизни. Незнакомое показываем как есть — это машинный
     * идентификатор, по нему всё равно ищут в общих логах.
     */
    public static function title(string $event): string
    {
        /* Словарь переехал в Audit (v4.70): он один на историю объекта,
           дашборд и журнал аудита. */
        return Audit::title($event);
    }
}
