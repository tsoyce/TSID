<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

/**
 * Генератор артефактов подключения (§6): integration.md (Приложение A)
 * и PHP-kit (.zip) из templates/kit/*. Плейсхолдеры {{…}} → реальные значения.
 * client_secret попадает в файлы только явно (иначе {{CLIENT_SECRET}}).
 */
final class KitBuilder
{
    private static function vars(array $client, ?string $secret): array
    {
        /* Кнопка входа живёт в чужом интерфейсе и наших переменных там нет —
           значения запекаются при сборке. Акцент берём не сырой из настроек, а
           пересчитанный: Palette подбирает заливку и текст поверх неё по
           контрасту, и светлый фирменный цвет владельца не должен превратиться
           в белые буквы на жёлтом у сорока порталов сразу. */
        $accent = \App\Core\Palette::derive((string)\App\Core\Setting::get('branding.accent', '#1D4ED8'));
        $appName = \App\Core\Setting::appName();

        return [
            '{{APP_NAME}}' => $appName,
            '{{INK}}' => $accent['ink'],
            '{{ON_INK}}' => $accent['on_ink'],
            /* Кольцо фокуса — тот же акцент прозрачностью 30%, как в интерфейсе
               SSO. Записываем восьмизначным hex, а не color-mix: файл уезжает
               на чужие сайты, в том числе в браузеры постарше наших. */
            '{{INK_FOCUS}}' => $accent['ink'] . '4D',
            /* Две буквы на знаке. Та же логика, что у brand_mark(). */
            '{{MARK}}' => self::markLetters($appName),
            '{{CLIENT_NAME}}' => $client['name'],
            '{{CLIENT_URL}}' => $client['base_url'],
            '{{ISSUER}}' => base_url(),
            '{{CLIENT_ID}}' => $client['client_id'],
            /* Слаг нужен в тексте: роли портала приезжают в claim с префиксом
               `слаг:роль`, и без примера с настоящим слагом разбирать их на
               той стороне приходится наугад. */
            '{{CLIENT_SLUG}}' => $client['slug'],
            '{{CLIENT_SECRET}}' => $secret ?? '{{CLIENT_SECRET}}',
            /* Ключ переноса, в отличие от client_secret, лежит у нас открытым —
               подпись считают обе стороны. Значит, в набор его можно запечь:
               скачал архив после выдачи ключа — и переносить можно сразу, без
               «скопируйте вот это вот сюда». Не выдан — остаётся плейсхолдер,
               и выгрузка честно отвечает, что не настроена. */
            '{{USERS_SECRET}}' => (string)($client['users_secret'] ?? '') !== ''
                ? (string)$client['users_secret'] : '{{USERS_SECRET}}',
            '{{REDIRECT_URI}}' => $client['redirect_uris'][0] ?? ($client['base_url'] . '/sso/callback.php'),
            '{{POST_LOGOUT_URI}}' => $client['post_logout_uris'][0] ?? ($client['base_url'] . '/'),
            '{{BACKCHANNEL_URI}}' => $client['backchannel_uri'] ?? ($client['base_url'] . '/sso/backchannel.php'),
            '{{SCOPES}}' => implode(' ', array_diff($client['allowed_scopes'], ['offline_access'])),
            '{{GENERATED_AT}}' => gmdate('Y-m-d H:i') . ' UTC',
        ];
    }

    /** «Комбинат Заря» → «КЗ», «Единый вход» → «ЕВ». Одно слово даёт две буквы. */
    private static function markLetters(string $name): string
    {
        $name = trim($name);
        $words = preg_split('~[\s·—-]+~u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = count($words) >= 2
            ? mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1)
            : mb_substr($name, 0, 2);
        return mb_strtoupper($letters);
    }

    public static function integrationMd(array $client, ?string $secret): string
    {
        $tpl = (string)file_get_contents(SSO_ROOT . '/templates/kit/integration.md');
        return strtr($tpl, self::vars($client, $secret));
    }

    /** Однострочник-промпт для нейросети (§6.1, кнопка «Скопировать промпт»). */
    public static function prompt(array $client): string
    {
        return 'Вот файл integration.md. Подключи сайт ' . $client['base_url']
            . ' к SSO по этой спецификации, ничего не выдумывай сверх неё. '
            . 'Если чего-то не хватает — спроси, а не догадывайся.' . "\n\n---\n\n"
            . self::integrationMd($client, null);
    }

    /** Сборка kit.zip во временный файл. Вызывающий отдаёт и удаляет. */
    public static function buildZip(array $client, ?string $secret): string
    {
        $vars = self::vars($client, $secret);
        $zipPath = tempnam(sys_get_temp_dir(), 'ssokit');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Не удалось создать zip');
        }
        $files = [
            'config.php', 'SsoClient.php', 'login.php', 'callback.php',
            'logout.php', 'backchannel.php', 'widget.php',
            /* Единственный файл, который портал обязан дописать. Пустые заглушки
               лучше комментария «TODO» в приёмнике: там их не видно, а здесь
               они названы по имени и проверяются самопроверкой. */
            'hooks.php',
            /* Общее начало: одна точка доступа к конфигу, секреты вне
               веб-корня, сессия приложения вместо своей. Каждый из трёх
               пунктов иначе ломается молча (v4.22). */
            'bootstrap.php',
            /* Установщик: ради него всё и затевалось — реквизиты приезжают
               по одноразовой ссылке, а не переписываются руками. */
            'install.php',
            /* Обязательные экраны портала (v4.63): настройки SSO — ввод секрета
               клиента и ключа переноса — и проверка связи. Проверка одна на
               экран, установщик и консоль и живёт отдельным файлом; прежний
               selftest.php, открытый всем и с просьбой удалить его, ушёл сюда. */
            'checks.php', 'admin.php',
            /* Поведение виджета отдельным файлом: на сайте со строгой политикой
               безопасности inline-обработчик не выполняется, и меню не
               открывается молча, без ошибки в консоли. */
            'sso-widget.js',
            /* Вход без редиректа; в браузерах без FedCM просто не сработает
               и пользователь пойдёт обычной кнопкой (v3.2) */
            'fedcm.php', 'fedcm-finish.php',
            /* Кнопка входа: один самодостаточный css, значения запечены под этот
               экземпляр. Без неё каждый портал рисует свою — и группа сервисов
               перестаёт выглядеть группой. */
            'sso-button.css',
            /* Выгрузка людей: единственный файл набора, который портал доделывает
               руками. Кладём всегда — переносить людей рано или поздно нужно
               каждому, а объяснять постфактум «скачайте набор ещё раз» хуже,
               чем положить сразу с пустой заглушкой (v4.6). */
            'users.php',
        ];
        foreach ($files as $f) {
            $body = (string)file_get_contents(SSO_ROOT . '/templates/kit/' . $f . '.tpl');
            $zip->addFromString('sso/' . $f, strtr($body, $vars));
        }
        $zip->addFromString('sso/README.md',
            strtr((string)file_get_contents(SSO_ROOT . '/templates/kit/README.md'), $vars));
        /* Схема требуется словами в §3, а словами её каждый переведёт в DDL
           по-своему — и половина ошибётся на UNIQUE поверх NULL-able почты. */
        $zip->addFromString('sso/migration.sql.example',
            strtr((string)file_get_contents(SSO_ROOT . '/templates/kit/migration.sql.example'), $vars));
        $zip->addFromString('integration.md', self::integrationMd($client, $secret));
        $zip->close();
        return $zipPath;
    }
}
