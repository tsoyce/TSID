<?php

declare(strict_types=1);

namespace App\Core;

/**
 * OpenAPI 3.1 для Admin API. Схема собирается из кода, а не пишется руками —
 * значит не разъедется с реальностью. Годится для Postman, генераторов
 * клиентов и как контекст для нейросети.
 */
final class OpenApi
{
    public static function document(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => Setting::appName() . ' — Admin API',
                'version' => SSO_VERSION,
                'description' => "Программное управление SSO: пользователи, порталы, аудит.\n\n"
                    . "Аутентификация — токен из /admin/tools (вкладка «API-токены»):\n"
                    . "`Authorization: Bearer sso_…`. Лимит 120 запросов в минуту на токен.\n"
                    . 'Все изменения попадают в журнал аудита с указанием имени токена.',
            ],
            'servers' => [['url' => base_url('/api/v1')]],
            'components' => [
                'securitySchemes' => [
                    'bearer' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'sso_<random>'],
                ],
                'schemas' => [
                    'User' => ['type' => 'object', 'properties' => [
                        'sub' => ['type' => 'string', 'format' => 'uuid', 'description' => 'вечный идентификатор'],
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'email_verified' => ['type' => 'boolean'],
                        'login' => ['type' => ['string', 'null']],
                        'first_name' => ['type' => ['string', 'null']],
                        'last_name' => ['type' => ['string', 'null']],
                        'status' => ['type' => 'string', 'enum' => ['pending', 'active', 'blocked', 'deleted']],
                        'two_factor' => ['type' => 'boolean'],
                        'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    ]],
                    'Error' => ['type' => 'object', 'properties' => [
                        'error' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                    ]],
                ],
            ],
            'security' => [['bearer' => []]],
            'paths' => self::paths(),
        ];
    }

    private static function paths(): array
    {
        $userRef = ['$ref' => '#/components/schemas/User'];
        $errors = [
            '401' => self::resp('Токен отсутствует или недействителен'),
            '403' => self::resp('Токену не хватает scope'),
            '429' => self::resp('Превышен лимит запросов'),
        ];

        return [
            '/users' => [
                'get' => [
                    'summary' => 'Список пользователей',
                    'description' => 'Требует scope `users:read`.',
                    'parameters' => [
                        self::param('q', 'поиск по email, логину или sub'),
                        self::param('status', 'фильтр: pending|active|blocked|deleted|all. По умолчанию удалённые не показываются — как и в админке'),
                        self::param('limit', 'сколько вернуть (1–200, по умолчанию 50)', 'integer'),
                        self::param('offset', 'смещение', 'integer'),
                    ],
                    'responses' => ['200' => [
                        'description' => 'Страница списка',
                        'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
                            'total' => ['type' => 'integer'],
                            'items' => ['type' => 'array', 'items' => $userRef],
                        ]]]],
                    ]] + $errors,
                ],
                'post' => [
                    'summary' => 'Создать пользователя',
                    'description' => 'Требует scope `users:write`. Без `password` человек задаст его сам по ссылке восстановления.',
                    'requestBody' => ['content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'required' => ['email'],
                        'properties' => [
                            'email' => ['type' => 'string', 'format' => 'email'],
                            'first_name' => ['type' => 'string'],
                            'last_name' => ['type' => 'string'],
                            'login' => ['type' => 'string'],
                            'password' => ['type' => 'string'],
                            'email_verified' => ['type' => 'boolean', 'default' => false],
                        ],
                    ]]]],
                    'responses' => ['201' => ['description' => 'Создан',
                        'content' => ['application/json' => ['schema' => $userRef]]],
                        '409' => self::resp('Email уже занят'),
                        '422' => self::resp('Некорректные данные')] + $errors,
                ],
            ],
            '/users/{sub}' => [
                'get' => [
                    'summary' => 'Пользователь по sub',
                    'parameters' => [self::pathParam('sub')],
                    'responses' => ['200' => ['description' => 'Профиль',
                        'content' => ['application/json' => ['schema' => $userRef]]],
                        '404' => self::resp('Не найден')] + $errors,
                ],
                'patch' => [
                    'summary' => 'Изменить пользователя',
                    'description' => "Требует scope `users:write`. Смена `status` на `blocked` гасит сессии\n"
                        . 'и рассылает порталам событие `user.blocked`.',
                    'parameters' => [self::pathParam('sub')],
                    'requestBody' => ['content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'properties' => [
                            'first_name' => ['type' => 'string'],
                            'last_name' => ['type' => 'string'],
                            'display_name' => ['type' => 'string'],
                            'login' => ['type' => 'string'],
                            'status' => ['type' => 'string', 'enum' => ['active', 'blocked']],
                        ],
                    ]]]],
                    'responses' => ['200' => ['description' => 'Обновлён',
                        'content' => ['application/json' => ['schema' => $userRef]]],
                        '404' => self::resp('Не найден')] + $errors,
                ],
                'delete' => [
                    'summary' => 'Удалить пользователя (мягко, а с ?purge=1 — насовсем)',
                    'description' => "Мягкое удаление: анонимизация через 14 дней, порталы получают `user.deleted`.
"
                        . 'С `?purge=1` — стереть насовсем, но только того, кто УЖЕ помечен: '
                        . 'в интерфейсе от промаха защищает набранное руками имя аккаунта, у токена '
                        . 'такой защиты нет, поэтому защитой работает сам порядок из двух шагов.',
                    'parameters' => [self::pathParam('sub'),
                        self::param('purge', 'стереть насовсем (только для уже помеченных)')],
                    'responses' => ['200' => self::resp('Помечен на удаление или стёрт'),
                        '403' => self::resp('Суперадмина удалить нельзя'),
                        '409' => self::resp('purge=1 для непомеченного')] + $errors,
                ],
            ],
            '/clients' => [
                'get' => [
                    'summary' => 'Список порталов',
                    'description' => 'Требует scope `clients:read`.',
                    'responses' => ['200' => self::resp('Список порталов')] + $errors,
                ],
                'post' => [
                    'summary' => 'Подключить портал',
                    'description' => "Требует scope `clients:write`. Headless-версия мастера §6:\n"
                        . '`client_secret` возвращается ОДИН раз — сохраните его сразу. '
                        . 'Принимаются те же поля, что и при изменении: '
                        . implode(', ', array_keys(\App\Models\Client::EDITABLE)) . '.',
                    'requestBody' => ['content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'required' => ['name', 'base_url'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'base_url' => ['type' => 'string', 'format' => 'uri'],
                            'redirect_uris' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'post_logout_uris' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'backchannel_uri' => ['type' => 'string'],
                            'access_policy' => ['type' => 'string', 'enum' => ['open', 'approval', 'invite']],
                        ],
                    ]]]],
                    'responses' => ['201' => self::resp('Портал создан, секрет внутри')] + $errors,
                ],
            ],
            /* Роли, организации, доступы и настройки (v4.51). До этого агент
               мог завести человека и портал, но не мог соединить их между
               собой: автоматизировалась ровно половина работы. */
            '/clients/{clientId}' => [
                'get' => ['summary' => 'Портал целиком',
                    'description' => 'Требует scope `clients:read`.',
                    'parameters' => [self::pathParam('clientId')],
                    'responses' => ['200' => self::resp('Карточка портала')] + $errors],
                'patch' => ['summary' => 'Изменить портал',
                    /* Список полей берём из карты, а не переписываем: описание,
                       отставшее от кода, хуже отсутствующего — по нему пишут клиентов. */
                    'description' => 'Требует scope `clients:write`. Поля те же, что в карточке портала: '
                        . implode(', ', array_keys(\App\Models\Client::EDITABLE)) . '.',
                    'parameters' => [self::pathParam('clientId')],
                    'responses' => ['200' => self::resp('Изменён')] + $errors],
                'delete' => ['summary' => 'Удалить портал',
                    'description' => 'Требует scope `clients:write`. Вместе с ним уходят его роли, выдачи и доступы.',
                    'parameters' => [self::pathParam('clientId')],
                    'responses' => ['200' => self::resp('Удалён')] + $errors],
            ],
            '/clients/{clientId}/secret' => [
                'post' => ['summary' => 'Перевыпустить секрет',
                    'description' => 'Требует scope `clients:write`. Старый секрет живёт ещё сутки — '
                        . 'портал успевает подхватить новый, обмен токенами не рвётся на середине выкладки.',
                    'parameters' => [self::pathParam('clientId')],
                    'responses' => ['200' => self::resp('Новый секрет, отдаётся один раз')] + $errors],
            ],
            '/roles' => [
                'get' => ['summary' => 'Роли',
                    'description' => 'Требует scope `roles:read`. С параметром `scope` отвечает не всем '
                        . 'справочником, а тем, что осмысленно выдать ИМЕННО ЗДЕСЬ, — то же самое, что '
                        . 'видит человек в интерфейсе. `scope=client&client_id=cli_…`, '
                        . '`scope=org&org_id=3` или `scope=system`.',
                    'parameters' => [
                        self::param('scope', 'system | org | client'),
                        self::param('client_id', 'для scope=client'),
                        self::param('org_id', 'для scope=org', 'integer'),
                    ],
                    'responses' => ['200' => self::resp('Список ролей')] + $errors],
                'post' => ['summary' => 'Завести роль',
                    'description' => 'Требует scope `roles:write`. Без `client_id` роль системная. Уровень '
                        . 'берётся из справочника по имени, если он там есть: «blog_editor» опознаётся '
                        . 'как редактор. Роль портала прав SSO не несёт никогда.',
                    'responses' => ['201' => self::resp('Заведена'),
                        '409' => self::resp('Роль с таким slug здесь уже есть')] + $errors],
            ],
            '/roles/{id}' => [
                'delete' => ['summary' => 'Удалить роль',
                    'description' => 'Требует scope `roles:write`. Системную роль удалить нельзя.',
                    'parameters' => [self::pathParam('id')],
                    'responses' => ['200' => self::resp('Удалена')] + $errors],
            ],
            '/users/{sub}/roles' => [
                'get' => ['summary' => 'Что человеку выдано и где',
                    'description' => 'Требует scope `roles:read`. У каждой выдачи названа область.',
                    'parameters' => [self::pathParam('sub')],
                    'responses' => ['200' => self::resp('Выдачи с областями')] + $errors],
                'post' => ['summary' => 'Выдать роль',
                    'description' => 'Требует scope `roles:write`. Тело: `role` (слаг) или `role_id`, плюс '
                        . 'область — `scope` и, если нужно, `client_id`/`org_id`. Роль, не относящаяся '
                        . 'к этой области, не выдаётся даже токеном с `*`: это про смысл, а не про права.',
                    'parameters' => [self::pathParam('sub')],
                    'responses' => ['201' => self::resp('Выдана'),
                        '422' => self::resp('Роль к этой области не относится')] + $errors],
                'delete' => ['summary' => 'Отозвать роль',
                    'description' => 'Требует scope `roles:write`. Снять выданное можно всегда.',
                    'parameters' => [self::pathParam('sub')],
                    'responses' => ['200' => self::resp('Отозвана')] + $errors],
            ],
            '/users/{sub}/access' => [
                'post' => ['summary' => 'Открыть доступ к порталу',
                    'description' => 'Требует scope `access:write`. Тело: `client_id`.',
                    'parameters' => [self::pathParam('sub')],
                    'responses' => ['201' => self::resp('Доступ открыт')] + $errors],
                'delete' => ['summary' => 'Закрыть доступ',
                    'description' => 'Требует scope `access:write`. Портал получает событие.',
                    'parameters' => [self::pathParam('sub')],
                    'responses' => ['200' => self::resp('Доступ закрыт')] + $errors],
            ],
            '/orgs' => [
                'get' => ['summary' => 'Организации',
                    'description' => 'Требует scope `orgs:read`.',
                    'responses' => ['200' => self::resp('Список организаций')] + $errors],
                'post' => ['summary' => 'Завести организацию',
                    'description' => 'Требует scope `orgs:write`. Тело: `name`, необязательно `slug`.',
                    'responses' => ['201' => self::resp('Заведена')] + $errors],
            ],
            '/orgs/{id}' => [
                'delete' => ['summary' => 'Удалить организацию',
                    'description' => 'Требует scope `orgs:write`. Вместе с ней уходят состав и выданные ею доступы.',
                    'parameters' => [self::pathParam('id')],
                    'responses' => ['200' => self::resp('Удалена')] + $errors],
            ],
            '/orgs/{id}/members' => [
                'get' => ['summary' => 'Состав организации',
                    'description' => 'Требует scope `orgs:read`.',
                    'parameters' => [self::pathParam('id')],
                    'responses' => ['200' => self::resp('Состав')] + $errors],
                'post' => ['summary' => 'Добавить в состав',
                    'description' => 'Требует scope `orgs:write`. Тело: `sub` либо `email`, и `role` (owner|admin|member).',
                    'parameters' => [self::pathParam('id')],
                    'responses' => ['201' => self::resp('Добавлен')] + $errors],
            ],
            '/orgs/{id}/members/{sub}' => [
                'delete' => ['summary' => 'Убрать из состава',
                    'description' => 'Требует scope `orgs:write`.',
                    'parameters' => [self::pathParam('id'), self::pathParam('sub')],
                    'responses' => ['200' => self::resp('Убран')] + $errors],
            ],
            '/settings' => [
                'get' => ['summary' => 'Настройки',
                    'description' => 'Требует scope `settings:read`. Значения ключей, похожих на секрет '
                        . '(secret, password, token, key), заменяются на `***` даже для токена с `*`.',
                    'responses' => ['200' => self::resp('Настройки')] + $errors],
                'patch' => ['summary' => 'Изменить настройки',
                    'description' => 'Требует scope `settings:write`. Тело — объект «ключ: значение».',
                    'responses' => ['200' => self::resp('Изменены')] + $errors],
            ],
            '/audit' => [
                'get' => [
                    'summary' => 'Журнал аудита',
                    'description' => 'Требует scope `audit:read`.',
                    'parameters' => [
                        self::param('event', 'префикс события, например `login.` или `admin.`'),
                        self::param('from', 'с даты (YYYY-MM-DD)'),
                        self::param('to', 'по дату (YYYY-MM-DD)'),
                        self::param('limit', 'до 500', 'integer'),
                    ],
                    'responses' => ['200' => self::resp('События')] + $errors,
                ],
            ],
        ];
    }

    private static function param(string $name, string $desc, string $type = 'string'): array
    {
        return ['name' => $name, 'in' => 'query', 'description' => $desc,
            'schema' => ['type' => $type]];
    }

    private static function pathParam(string $name): array
    {
        return ['name' => $name, 'in' => 'path', 'required' => true,
            'schema' => ['type' => 'string'], 'description' => 'идентификатор `sub` пользователя'];
    }

    private static function resp(string $desc): array
    {
        return ['description' => $desc,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
    }
}
