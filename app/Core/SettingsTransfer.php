<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Слепок настроек: выгрузить одним файлом и загрузить в другую установку.
 *
 * Переезд сейчас переносит базу целиком — вместе с людьми, сессиями и ключами.
 * А нужно бывает другое: поднять вторую установку (тестовую, филиала, запасную)
 * с теми же правилами, тем же оформлением и теми же документами, но со своими
 * людьми. Собирать это руками по четырнадцати разделам — час работы и три
 * забытых тумблера.
 *
 * Что переносится, решает БЕЛЫЙ список префиксов, а не чёрный.
 * Чёрный список — та же ошибка, что запрещающий список доменов: завтра
 * появится `system.новый_секрет`, и он уедет в файл по умолчанию, потому что
 * его забыли запретить. Белый ошибается в безопасную сторону: забытое не
 * уедет, о нём скажут.
 *
 * Три вида ключей, и путать их нельзя:
 *  · переносимые — правила, оформление, документы, сроки;
 *  · секреты — пароль SMTP, secret соцвхода, ключ капчи. Уезжают только по
 *    отдельной просьбе: файл настроек ходит по почте и лежит в загрузках;
 *  · состояние ЭТОЙ установки — ключи подписи, владелец, отметки cron,
 *    домен переезда. Не переносятся никогда: в чужой базе `owner_user_id`
 *    указывает на другого человека, а `keys.registry` — на ключи, которых там
 *    нет. Такой перенос не «частично сработает», а сломает вход.
 */
final class SettingsTransfer
{
    /** Формат файла. Меняется, если поменяется смысл полей. */
    public const FORMAT = 1;

    /** Что переносится. Префикс с точкой на конце — всё семейство. */
    public const PORTABLE = [
        'appearance.',      // витрина, состав формы входа, обложка
        'branding.',        // имя, цвет, подписи (сами картинки — файлы, не настройки)
        'captcha.',
        'docs.',            // тексты документов и их версии
        'mail.',            // SMTP: сервер, отправитель, драйвер
        'maintenance.',
        'policy.',
        'req.',             // реквизиты оператора данных
        'retention.',
        'social.',
        'timeout.',
        'app.timezone',
        'notify.admin.to',
        'notify.admin.window',
    ];

    /**
     * Ключи, чьё ЗНАЧЕНИЕ — секрет.
     *
     * Проверяется по окончанию, а не по полному имени: провайдеров соцвхода
     * четыре, и каждый раз дописывать сюда `social.<новый>.secret` значит
     * однажды забыть.
     */
    public const SECRET_SUFFIX = ['.secret', '.pass', '.password'];

    /**
     * Секреты, названные не по соглашению. Разрослись бы — стал бы префикс.
     *
     * `health.api_token` появился раньше правила и переименованию не подлежит:
     * ключ лежит в базах установок, и переименование молча обнулило бы его.
     */
    public const SECRET_KEYS = ['health.api_token'];

    /** Переносим ли этот ключ вообще. */
    public static function portable(string $key): bool
    {
        foreach (self::PORTABLE as $p) {
            if (str_ends_with($p, '.') ? str_starts_with($key, $p) : $key === $p) return true;
        }
        /* Уведомления владельцу — семейство наполовину: тумблеры и адреса это
           настройка и должны переезжать, а `notify.admin.at.*` и `.held.*` —
           счётчики этой установки, которым в чужой базе делать нечего. Целым
           префиксом сюда не возьмёшь, поэтому перечисляем по каталогу событий:
           появится новое событие — переедет само. */
        foreach (array_keys(AdminNotify::EVENTS) as $event) {
            if ($key === 'notify.admin.' . $event) return true;
        }
        return false;
    }

    /**
     * Секрет ли это.
     *
     * Окончание проверяется ВМЕСТЕ С ТОЧКОЙ, и это не мелочь. Правило, которое
     * смотрело на хвост имени целиком (`~(secret|password|token|key)$~`), на
     * живой установке пометило секретами `appearance.method_password` и
     * `appearance.method_passkey` — тумблеры «показывать ли этот способ входа».
     * Читающий видел `***` вместо `0`/`1`, а записав прочитанное обратно,
     * положил бы в тумблер строку `***`, которая читается как «включено»:
     * способ входа появился бы на экране сам собой. Маскировка, придуманная
     * ради безопасности, включала бы вход по паролю.
     *
     * Поэтому секрет — это соглашение об имени (`<семейство>.secret`), а не
     * догадка по буквам. Исключения перечислены поимённо.
     */
    public static function secret(string $key): bool
    {
        if (in_array($key, self::SECRET_KEYS, true)) return true;
        foreach (self::SECRET_SUFFIX as $s) {
            if (str_ends_with($key, $s)) return true;
        }
        return false;
    }

    /**
     * Слепок.
     *
     * @return array{format:int, app:string, version:string, exported_at:string,
     *               settings:array<string,string>, secrets_skipped:array<int,string>}
     */
    public static function export(bool $withSecrets = false): array
    {
        $rows = DB::all('SELECT `key`, `value` FROM {settings} ORDER BY `key`');
        $out = [];
        $skipped = [];
        foreach ($rows as $r) {
            $key = (string)$r['key'];
            if (!self::portable($key)) continue;
            if (self::secret($key)) {
                /* Пустой секрет пропускаем молча: сообщать «не увёз пустое»
                   значит пугать без повода. */
                if ((string)$r['value'] === '') continue;
                if (!$withSecrets) { $skipped[] = $key; continue; }
            }
            $out[$key] = (string)$r['value'];
        }
        return [
            'format' => self::FORMAT,
            'app' => Setting::appName(),
            'version' => SSO_VERSION,
            'exported_at' => now(),
            'settings' => $out,
            'secrets_skipped' => $skipped,
        ];
    }

    /**
     * Что произойдёт при загрузке этого файла.
     *
     * Отдельным шагом, а не «нажал и посмотрим»: файл может быть от другой
     * версии, от другого продукта или собран руками. Человек должен увидеть
     * список до того, как настройки поменяются.
     *
     * @return array{ok:bool, error:string, change:array<string,array{0:string,1:string}>,
     *                add:array<string,string>, same:int, refused:array<int,string>}
     */
    public static function plan(mixed $json): array
    {
        $empty = ['change' => [], 'add' => [], 'same' => 0, 'refused' => []];

        if (!is_array($json) || !isset($json['settings']) || !is_array($json['settings'])) {
            return ['ok' => false, 'error' => t('Это не файл настроек: в нём нет раздела settings.')] + $empty;
        }
        if ((int)($json['format'] ?? 0) !== self::FORMAT) {
            return ['ok' => false, 'error' => t('Файл другого формата (:got вместо :want) — загрузить его нельзя.', [
                'got' => (string)($json['format'] ?? '—'), 'want' => (string)self::FORMAT,
            ])] + $empty;
        }

        $change = $add = $refused = [];
        $same = 0;
        foreach ($json['settings'] as $key => $value) {
            $key = (string)$key;
            if (!self::portable($key)) { $refused[] = $key; continue; }
            if (!is_scalar($value) && $value !== null) { $refused[] = $key; continue; }
            $value = (string)$value;
            $current = DB::val('SELECT `value` FROM {settings} WHERE `key` = ?', [$key]);
            if ($current === null) {
                $add[$key] = $value;
            } elseif ((string)$current === $value) {
                $same++;
            } else {
                $change[$key] = [(string)$current, $value];
            }
        }
        return ['ok' => true, 'error' => '', 'change' => $change, 'add' => $add,
                'same' => $same, 'refused' => $refused];
    }

    /**
     * Применить. Возвращает, сколько ключей записано.
     *
     * Применяем ровно то, что показал `plan()`: список строится заново тем же
     * кодом, чтобы «увидел одно, применилось другое» было невозможно.
     */
    public static function apply(mixed $json): int
    {
        $plan = self::plan($json);
        if (!$plan['ok']) return 0;
        $n = 0;
        foreach ($plan['add'] as $key => $value) { Setting::set($key, $value); $n++; }
        foreach ($plan['change'] as $key => [, $value]) { Setting::set($key, $value); $n++; }
        Setting::flush();
        if ($n > 0) {
            Audit::log('admin.settings.import', ['meta' => ['keys' => $n]]);
            AdminNotify::say('settings.imported',
                fn() => t('Применён файл настроек: изменено :n.', [
                    'n' => $n . ' ' . plural($n, 'ключ', 'ключа', 'ключей')]),
                fn() => [t('Кто') => user_handle(Auth::user() ?? []), t('Когда') => fmt_dt(now())]);
            Log::warning('settings.import', ['keys' => $n]);
        }
        return $n;
    }
}
