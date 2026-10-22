<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Тексты писем: что можно переписать, а что остаётся нашим.
 *
 * Письма были зашиты в шаблоны целиком. Поменять приветствие, объяснение или
 * подпись — только правкой кода, то есть по ssh и с потерей правки при
 * обновлении. При этом переписать хочется именно их: у одной установки
 * «Здравствуйте», у другой «Привет», у третьей в подписи адрес поддержки.
 *
 * Граница проведена по ответственности, а не по вкусу:
 *
 *  · ТЕКСТ — владельца: тема, приветствие, объяснение, надпись на кнопке,
 *    подпись под письмом. Это его голос, и он о нём знает больше нас;
 *  · УСТРОЙСТВО — наше: сама кнопка, запасная ссылка текстом, срок действия,
 *    вёрстка под почтовые клиенты. Отдать это в поле ввода значит однажды
 *    получить письмо без кнопки и человека, который не может войти.
 *
 * Срок в письме больше не пишется руками: он берётся из настроек тем же кодом,
 * который выдаёт токен. Раньше в тексте стояло «Ссылка действует 3 дня», а срок
 * настраивался отдельно — сократив его, владелец получал письмо, обещающее три
 * дня, и человека с протухшей через час ссылкой.
 *
 * Пусто = как в поставке. Ни одно поле заполнять не обязательно (§1.1).
 *
 * Язык (v4.74). Тексты «как в поставке» переводятся на язык письма, как и всё
 * устройство вокруг них. До 4.74 они шли по-русски всегда: в английском
 * интерфейсе письмо выходило наполовину английским («Or open the link:»,
 * срок) и наполовину русским (тема, приветствие, текст, кнопка, подпись).
 * Переписанный владельцем текст остаётся как есть — это его слова на его языке.
 */
final class MailTemplates
{
    /**
     * Каталог писем.
     *
     * `token` — тип одноразового токена, если у письма есть срок; по нему
     * подставляется `{{ Срок }}`. `vars` — что ещё можно вставить в текст.
     */
    public const LETTERS = [
        'verify' => [
            'title' => 'Подтверждение почты',
            'when' => 'После регистрации и по кнопке «отправить письмо ещё раз».',
            'subject' => 'Подтвердите почту',
            'greeting' => 'Здравствуйте',
            'body' => 'Подтвердите почту для вашей учётной записи — нажмите кнопку:',
            'button' => 'Подтвердить почту',
            'token' => 'email_verify',
        ],
        /* Подтверждение почты до входа (v4.72, v4.74): в письме и код, и
           кнопка — кому как удобнее. */
        'verify_code' => [
            'title' => 'Подтверждение почты новым аккаунтом',
            'when' => 'После регистрации, когда подтверждение почты обязательно.',
            'subject' => 'Код подтверждения: {{ Код }}',
            'greeting' => 'Здравствуйте',
            'body' => 'Введите этот код на экране регистрации — или просто нажмите кнопку:',
            'button' => 'Подтвердить почту',
            'token' => 'email_code',
        ],
        'magic' => [
            'title' => 'Вход по ссылке',
            'when' => 'Когда человек просит ссылку для входа без пароля.',
            'subject' => 'Вход в {{ Название }}',
            'greeting' => 'Здравствуйте',
            'body' => 'Ссылка для входа без пароля:',
            'button' => 'Войти',
            'token' => 'login_link',
        ],
        'reset' => [
            'title' => 'Сброс пароля',
            'when' => 'По запросу восстановления и когда админ выдаёт ссылку сам.',
            'subject' => 'Сброс пароля',
            'greeting' => 'Здравствуйте',
            'body' => 'Вы (или кто-то от вашего имени) запросили сброс пароля. Задайте новый:',
            'button' => 'Задать новый пароль',
            'token' => 'password_reset',
        ],
        'email_change' => [
            'title' => 'Смена почты',
            'when' => 'На новый адрес, когда его меняют в настройках аккаунта.',
            'subject' => 'Подтвердите новую почту',
            'greeting' => 'Здравствуйте',
            'body' => 'Вы меняете почту аккаунта. Подтвердите новый адрес:',
            'button' => 'Подтвердить адрес',
            'token' => 'email_change',
        ],
        'export_ready' => [
            'title' => 'Выгрузка данных готова',
            'when' => 'Когда собран архив с данными по запросу человека.',
            'subject' => 'Выгрузка ваших данных',
            'greeting' => 'Здравствуйте',
            'body' => 'Вы заказали выгрузку своих данных. Скачать архив можно по ссылке:',
            'button' => 'Скачать мои данные',
            'token' => '',
        ],
        'delete_hold' => [
            'title' => 'Аккаунт помечен на удаление',
            'when' => 'Когда человек или админ запросил удаление аккаунта.',
            'subject' => 'Аккаунт будет удалён',
            'greeting' => 'Здравствуйте',
            'body' => 'Ваша учётная запись помечена на удаление. Данные будут анонимизированы безвозвратно. Передумали?',
            'button' => 'Отменить удаление',
            'token' => 'account_delete',
        ],
    ];

    /** Ключ настройки: `mail.tpl.<письмо>.<часть>`. */
    private static function key(string $letter, string $part): string
    {
        return 'mail.tpl.' . $letter . '.' . $part;
    }

    /** Значение части письма: своё, если задано, иначе как в поставке. */
    public static function part(string $letter, string $part): string
    {
        $own = trim((string)Setting::get(self::key($letter, $part), ''));
        if ($own !== '') return $own;
        $default = (string)(self::LETTERS[$letter][$part] ?? '');
        return $default !== '' ? t($default) : '';
    }

    /** Переписана ли хоть одна часть письма. */
    public static function customized(string $letter): bool
    {
        foreach (['subject', 'greeting', 'body', 'button'] as $part) {
            if (trim((string)Setting::get(self::key($letter, $part), '')) !== '') return true;
        }
        return false;
    }

    public static function save(string $letter, string $part, string $value): void
    {
        if (!isset(self::LETTERS[$letter])) return;
        Setting::set(self::key($letter, $part), mb_substr(trim($value), 0, 600));
    }

    /** Подпись под письмом — одна на все. */
    public static function footer(): string
    {
        $own = trim((string)Setting::get('mail.footer', ''));
        return $own !== '' ? $own : t(self::FOOTER_DEFAULT);
    }

    public const FOOTER_DEFAULT =
        'Это письмо отправлено автоматически — отвечать на него не нужно. Если действие запрашивали не вы, просто проигнорируйте письмо.';

    /**
     * Подстановки. `{{ Имя }}` — как в документах: по-русски и в фигурных
     * скобках, чтобы забытая подстановка была видна в письме, а не молчала.
     */
    public static function fill(string $text, string $letter, array $data): string
    {
        $token = (string)(self::LETTERS[$letter]['token'] ?? '');
        $map = [
            '{{ Имя }}' => (string)($data['name'] ?? ''),
            '{{ Название }}' => Setting::appName(),
            '{{ Код }}' => (string)($data['code'] ?? ''),
            '{{ Срок }}' => $token !== ''
                ? fmt_duration(\App\Models\OneTimeToken::lifetime($token))
                : '',
        ];
        return strtr($text, $map);
    }

    /**
     * Готовое письмо: тема и куски текста с подставленными значениями.
     *
     * @return array{subject:string, greeting:string, body:string, button:string, expires:string}
     */
    public static function render(string $letter, array $data): array
    {
        $token = (string)(self::LETTERS[$letter]['token'] ?? '');
        $name = trim((string)($data['name'] ?? ''));
        $greeting = self::fill(self::part($letter, 'greeting'), $letter, $data);
        return [
            'subject' => self::fill(self::part($letter, 'subject'), $letter, $data),
            /* Имя приклеивается к приветствию здесь, а не в тексте: человек без
               имени не должен получить «Здравствуйте, !». Кто хочет иначе —
               ставит `{{ Имя }}` в приветствие сам, и тогда мы не вмешиваемся. */
            'greeting' => str_contains(self::part($letter, 'greeting'), '{{ Имя }}')
                ? $greeting
                : $greeting . ($name !== '' ? ', ' . $name : '') . '!',
            'body' => self::fill(self::part($letter, 'body'), $letter, $data),
            'button' => self::fill(self::part($letter, 'button'), $letter, $data),
            'expires' => $token !== ''
                ? fmt_duration(\App\Models\OneTimeToken::lifetime($token)) : '',
        ];
    }

    /** Данные для предпросмотра: настоящие поля, но выдуманные значения. */
    public static function sample(string $letter): array
    {
        return [
            'name' => t('Иван'),
            'url' => base_url('/example-link'),
            'cancel_url' => base_url('/example-link'),
            'code' => '482913',
        ];
    }
}
