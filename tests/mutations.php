<?php
/**
 * Проверка проверок: каждая обязана уметь провалиться.
 *
 * Зелёный прогон означает одно из двух — «дефектов нет» или «проверки их не
 * видят», — и по самому прогону эти два случая неразличимы. Именно так в
 * проекте жили: сканер словаря, не читавший каталоги (51 строка без перевода
 * при нулевом счётчике); ревизия проекта, печатавшая найденное и выходившая с
 * кодом 0; ревизия настроек, знавшая только одинарные кавычки. Все трое были
 * зелены годами, и ни один прогон об этом не сказал.
 *
 * Здесь дефект возвращается НАРОЧНО — по одному, — и проверка обязана
 * покраснеть. Не покраснела — значит она украшение, а не проверка, и это
 * важнее любого дефекта, который она якобы стережёт.
 *
 * Каждая мутация — не выдумка, а настоящий дефект из истории проекта или из
 * ревизии v4.45. Файлы правятся на месте и возвращаются обратно всегда:
 * восстановление стоит в finally, и оно же сверяется побайтно.
 *
 * Запуск: php tests/mutations.php [--list]
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$php = PHP_BINARY . ' -d extension=pdo_sqlite';

/**
 * Мутации: что ломаем, чем ломаем и кто обязан это заметить.
 *
 * `check` — ровно одна проверка, а не вся батарея: гонять всё ради каждой
 * мутации значит превратить осмысленную вещь в получасовую, которую никто не
 * станет запускать.
 */
$mutations = [
    [
        'why'   => 'удаление пользователя перестало быть одним куском',
        'file'  => 'app/Models/User.php',
        'from'  => '[$counts, $pub] = DB::tx(static function () use ($userId): array {',
        'to'    => '$__mut = static function () use ($userId): array {',
        'from2' => "            return [\$counts, \$pub];\n        });",
        'to2'   => "            return [\$counts, \$pub];\n        }; [\$counts, \$pub] = \$__mut();",
        'check' => 'tests/atomicity.php',
    ],
    [
        'why'   => 'приглушённый текст вернулся к цвету без запаса контраста',
        'file'  => 'public/assets/css/tokens.css',
        'from'  => '--text-3: #666159;',
        'to'    => '--text-3: #736E69;',
        'check' => 'tools/contrast_audit.php',
    ],
    [
        'why'   => 'градиент знака снова светлеет в сторону цвета своего текста',
        'file'  => 'public/assets/css/identity.css',
        'from'  => 'color-mix(in srgb, var(--ink) 72%, var(--text))',
        'to'    => 'color-mix(in srgb, var(--ink) 72%, var(--on-ink))',
        'check' => 'tools/contrast_audit.php',
    ],
    [
        'why'   => 'у документа пропал перевод заголовка',
        'file'  => 'app/Lang/en-admin.php',
        'from'  => "'Политика конфиденциальности' => 'Privacy policy',",
        'to'    => '',
        'check' => 'tools/i18n_coverage.php',
    ],
    [
        'why'   => 'настройку читают, а задать её негде',
        'file'  => 'app/Core/Signup.php',
        'from'  => "Setting::get('policy.signup_open', '1')",
        'to'    => "Setting::get('zzzmut.only_read', '1')",
        'check' => 'tools/settings_audit.php',
    ],
    [
        'why'   => 'экраны входа снова остались без заголовка первого уровня',
        'file'  => 'app/helpers.php',
        'from'  => '<h1 class="sr-only">',
        'to'    => '<span class="sr-only">',
        'check' => 'tools/structure_audit.php',
    ],
    [
        'why'   => 'в список выдачи снова полезли роли чужих областей',
        'file'  => 'app/Core/RoleOffer.php',
        'from'  => '        return in_array($scopeType, $entry[\'scopes\'], true);',
        'to'    => '        return true;',
        'check' => 'tests/role_offer.php',
    ],
    [
        'why'   => 'право «*» перестало покрывать всё — суперадминский токен ослеп',
        'file'  => 'app/Models/ApiToken.php',
        'from'  => 'return in_array(self::SCOPE_ALL, $scopes, true) || in_array($need, $scopes, true);',
        'to'    => 'return in_array($need, $scopes, true);',
        'check' => 'tests/api_superadmin.php',
    ],
    [
        'why'   => 'max_age=0 снова не переспрашивает пароль',
        'file'  => 'app/Oidc/AuthorizeFlow.php',
        'from'  => '&& ($maxAge === 0 || $authAge > $maxAge);',
        'to'    => '&& ($authAge > $maxAge);',
        'check' => 'tests/oidc_adversarial.php',
    ],
    [
        'why'   => 'в схеме появился AUTO_INCREMENT без ключа — MySQL такое отвергнет',
        'file'  => 'migrations/001_init.sql',
        'from'  => "  PRIMARY KEY (id),
  UNIQUE KEY uq_public_id (public_id),",
        'to'    => "  UNIQUE KEY uq_public_id (public_id),",
        'check' => 'tools/mysql_lint.php',
    ],
    [
        /* Заглушка хранит правила .htaccess второй копией — иначе восстанавливать
           нечем. Копия тем и опасна: правку боевого файла легко сделать мимо неё,
           и кнопка «починить» начнёт чинить вчерашним, молча. */
        'why'   => 'правила в заглушке разошлись с боевым .htaccess',
        'file'  => '.htaccess',
        'from'  => 'RewriteRule ^public/ - [L]',
        'to'    => 'RewriteRule ^public/ - [L] # правка мимо заглушки',
        'check' => 'tests/stub_test.php',
    ],
    [
        /* Право на запись у заглушки ограничено словом «нет файла». Уберите его —
           и она затрёт .htaccess, который человек правил под свой хостинг. */
        'why'   => 'починка стала перезаписывать существующий .htaccess',
        'file'  => 'index.php',
        'from'  => '$mayWriteRoot = $hasPublic && !$hasRootHt && is_writable($dir);',
        'to'    => '$mayWriteRoot = $hasPublic && is_writable($dir);',
        'check' => 'tests/stub_test.php',
    ],
    [
        /* Настоящий случай, найденный прогоном по живой установке: маскировка
           секретов в API угадывала секрет по хвосту имени и пометила `***`
           тумблеры `appearance.method_password` и `.method_passkey`. Проверка
           при этом была зелёной — она повторяла у себя то же правило, что и
           код, то есть сверяла реализацию с её же копией. */
        'why'   => 'маскировка секретов вернулась к догадке по буквам',
        'file'  => 'app/Core/SettingsTransfer.php',
        'from'  => '        if (in_array($key, self::SECRET_KEYS, true)) return true;',
        'to'    => '        if (preg_match(\'~(secret|password|token|key)$~i\', $key)) return true;',
        'check' => 'tests/api_superadmin.php',
    ],
    [
        /* Карта полей портала — единственное, что удерживает карточку и API
           вместе. Убери из неё поле — и снаружи его снова не станет, ровно
           как было с `same_site_family` до v4.54. */
        'why'   => 'из карты полей портала пропало поле, которое правит карточка',
        'file'  => 'app/Models/Client.php',
        'from'  => "        'same_site_family' => 'bool',
",
        'to'    => '',
        'check' => 'tests/api_superadmin.php',
    ],
    [
        /* Списком людей заведуют двое — админка и API, — и однажды они уже
           разошлись: человек видел троих, программа отвечала «семь», потому
           что считала и удалённых. */
        'why'   => 'API снова показывает удалённых в общем списке людей',
        'file'  => 'app/Models/User.php',
        'from'  => "        return [\"\$col != ?\", ['deleted']];",
        'to'    => "        return ['1=1', []];",
        'check' => 'tests/api_superadmin.php',
    ],
    [
        /* Боевой отказ при подключении портала: «1265 Data truncated for
           column 'type'». Тип токена завели в коде и не дописали в ENUM из
           001_init. На SQLite перечисление не действует вовсе — ни разработка,
           ни батарея этого не видели, а на боевой не работали ни ссылка
           установки портала, ни передача владения. */
        'why'   => 'ENUM вернулся в схему — ограничение, которого нет на SQLite',
        'file'  => 'migrations/005_orgs.sql',
        'from'  => "  role VARCHAR(16) NOT NULL DEFAULT 'member'",
        'to'    => "  role ENUM('owner','admin','member') NOT NULL DEFAULT 'member'",
        'check' => 'tools/mysql_lint.php',
    ],
    [
        /* Код авторизации привязан к порталу, которому выдан. Снимите
           привязку — и перехваченный код обменяет любой другой портал: свой
           секрет у него есть, чужой код теперь тоже подойдёт. Дверь, которую
           батарея не дёргала до v4.57. */
        'why'   => 'код авторизации перестал быть привязан к своему порталу',
        'file'  => 'app/Oidc/TokenFlow.php',
        'from'  => '        if (!$row || (int)$row[\'client_id\'] !== (int)$client[\'id\']) {',
        'to'    => '        if (!$row) {',
        'check' => 'tests/oidc_adversarial.php',
    ],
    [
        /* Адрес со своего origin портала запоминаем, чужой — нет. Снимите
           проверку — и любой прохожий заваливает карточку адресами, среди которых
           администратор однажды нажмёт «добавить» не глядя. */
        'why'   => 'запоминание присланного адреса перестало проверять origin',
        'file'  => 'app/Core/RedirectMiss.php',
        'from'  => '        if ($uri === \'\' || strlen($uri) > 255 || !self::sameOrigin($client, $uri)) return false;',
        'to'    => '        if ($uri === \'\' || strlen($uri) > 255) return false;',
        'check' => 'tests/portal_connect.php',
    ],
    [
        /* 404, который отдал сам nginx, — главная причина недоступного
           манифеста на панельном хостинге. Без этой ветки карточка снова говорит
           «манифеста нет», и администратор ищет модуль, которого не терял. */
        'why'   => 'разбор перестал узнавать 404 от самого nginx',
        'file'  => 'app/Core/PortalManifest.php',
        'from'  => '        if ($code === 404 && $byNginx) {',
        'to'    => '        if (false) {',
        'check' => 'tests/portal_connect.php',
    ],
    [
        /* После неудачи ждали сутки: сервис чинил путь, а «не отвечает» висело
           до завтра. */
        'why'   => 'после неудачи с манифестом снова ждём сутки',
        'file'  => 'app/Core/PortalManifest.php',
        'from'  => '        return (int)min(86400, 3600 * (2 ** min(10, $fails - 1)));',
        'to'    => '        return 86400;',
        'check' => 'tests/portal_connect.php',
    ],
    [
        /* Наша же инструкция советовала правило, которое отдаёт /.well-known с
           диска и роняет манифест, собираемый приложением. */
        'why'   => 'в заготовку nginx вернулось правило, забирающее весь /.well-known',
        'file'  => 'templates/kit/integration.md',
        'from'  => 'location ^~ /.well-known/acme-challenge/ { allow all; }
# общий запрет',
        'to'    => 'location ^~ /.well-known/ { allow all; }
# общий запрет',
        'check' => 'tests/portal_connect.php',
    ],
    [
        /* Подключение угадывало «…/sso/callback.php», а Архив слал
           «…/sso/callback». */
        'why'   => 'подключение портала снова угадывает адрес возврата',
        'file'  => 'app/Controllers/Admin/ClientsController.php',
        'from'  => '        $redirects = $this->linesToUris((string)($_POST[\'redirect_uris\'] ?? \'\'), null);',
        'to'    => '        $redirects = $this->linesToUris((string)($_POST[\'redirect_uris\'] ?? \'\'), $baseUrl . \'/sso/callback.php\');',
        'check' => 'tests/portal_connect.php',
    ],
    [
        /* Импорт не проверял занятый телефон: второй человек с тем же номером
           падал на UNIQUE, терялся, а отчёт показывал сырой текст базы. */
        'why'   => 'импорт снова не проверяет, занят ли телефон',
        'file'  => 'app/Controllers/Admin/ToolsController.php',
        'from'  => '            $phoneDropped = $phone !== \'\' && User::phoneTaken($phone);',
        'to'    => '            $phoneDropped = false;',
        'check' => 'tests/portal_connect.php',
    ],
    [
        /* Запись в changelog без перевода строки в конце склеила заголовок
           следующего выпуска с чужой строкой — и выпуск пропал из ленты,
           а все проверки ленты остались зелёными. */
        'why'   => 'заголовок выпуска приклеен к чужой строке — выпуск пропадает из ленты',
        'file'  => 'CHANGELOG.md',
        'from'  => '

## [4.57.0]',
        'to'    => '## [4.57.0]',
        'check' => 'tests/unit.php',
    ],
    [
        /* form-action 'self' обрывал возврат на портал после «Продолжить»:
           Chrome сверяет его с каждым шагом редиректа. Живой дефект —
           кнопка крутила спиннер вечно. */
        'why'   => 'форма снова не пускает на портал, куда человек возвращается',
        'file'  => 'app/Core/Csp.php',
        'from'  => '        return array_values(array_unique($out));',
        'to'    => '        return [];',
        'check' => 'tests/portal_connect.php',
    ],
    [
        /* Первое нажатие «Войти» гасило ключ возврата — второе уводило в
           личный кабинет. Живой случай 10.09. */
        'why'   => 'вход снова гасит ключ возврата — повторное нажатие уводит в кабинет',
        'file'  => 'app/Core/LoginFlow.php',
        'from'  => '            unset($req[\'prompt\'], $req[\'max_age\']);
',
        'to'    => '            unset($req[\'prompt\'], $req[\'max_age\']);
            \\App\\Models\\OneTimeToken::consume(Resume::fromRequest(), Resume::TYPE);
',
        'check' => 'tests/portal_connect.php',
    ],
    [
        /* Без подсказки SSO не узнавал портал на выходе и отправлял человека
           на свой вход вместо сервиса. */
        'why'   => 'выход снова не узнаёт портал без id_token_hint',
        'file'  => 'app/Controllers/AuthController.php',
        'from'  => '            $client = Client::byPostLogoutUri($postLogout) ?? Client::byOrigin($postLogout);',
        'to'    => '            $client = null;',
        'check' => 'tests/portal_connect.php',
    ],
    [
        /* Ключ возврата больше не одноразовый (v4.60) — держит его только срок.
           Уберём проверку срока, и ключ вернёт на портал хоть через сутки. */
        'why'   => 'ключ возврата живёт вечно — срок не проверяется',
        'file'  => 'app/Models/OneTimeToken.php',
        'from'  => '        if (strtotime($row[\'expires_at\'] . \' UTC\') <= time()) return null;
',
        'to'    => '',
        'check' => 'tests/portal_connect.php',
    ],
    [
        /* С v4.61 «admin» портала — 70. Войди роль портала снова в общий
           уровень, и администратор Архива стал бы старшим во всём SSO. */
        'why'   => 'роль портала снова засчитывается в общий уровень человека',
        'file'  => 'app/Models/Role.php',
        'from'  => '                $sql .= \' AND r.client_id IS NULL\';',
        'to'    => '                $sql .= \'\';',
        'check' => 'tests/role_offer.php',
    ],
    [
        'why'   => 'роль портала снова засчитывается в соседнем портале',
        'file'  => 'app/Models/Role.php',
        'from'  => '                $sql .= \' AND (r.client_id = ? OR r.client_id IS NULL)\';
                $params[] = $clientDbId;
',
        'to'    => '',
        'check' => 'tests/role_offer.php',
    ],
    [
        'why'   => 'резолвер областей снова засчитывает роль чужого портала',
        'file'  => 'app/Core/AccessResolver.php',
        'from'  => '            if ($r[\'client_id\'] !== null && (int)$r[\'client_id\'] !== $clientDbId) continue;
',
        'to'    => '',
        'check' => 'tests/role_offer.php',
    ],
    [
        /* Живой случай 11.09: «admin» Архива заведён с уровнем 20. */
        'why'   => '«admin» портала снова получает двадцатку',
        'file'  => 'app/Core/RoleCatalog.php',
        'from'  => '            if (in_array($candidate, self::SERVICE_ADMIN_NAMES, true)) return self::SERVICE_CEILING;
',
        'to'    => '',
        'check' => 'tests/role_offer.php',
    ],
    [
        /* До v4.62 одно user.deleted на четыре случая: портал не отличал
           «вернётся» от «стереть всё». */
        'why'   => 'user.deleted снова без mode',
        'file'  => 'app/Oidc/EventDispatcher.php',
        'from'  => '        $payload = [\'mode\' => $mode] + $extra;',
        'to'    => '        $payload = $extra;',
        'check' => 'tests/deletion_events.php',
    ],
    [
        'why'   => 'отмена удаления снова молчит порталам',
        'file'  => 'app/Controllers/AuthController.php',
        'from'  => '            \\App\\Oidc\\EventDispatcher::userRestored($user);',
        'to'    => '',
        'check' => 'tests/deletion_events.php',
    ],
    [
        'why'   => 'обезличивание по сроку снова молчит порталам',
        'file'  => 'app/Core/CronTasks.php',
        'from'  => '            EventDispatcher::userDeleted($u, \'final\');',
        'to'    => '',
        'check' => 'tests/deletion_events.php',
    ],
    [
        /* Живой случай 11.09: «Убрать» в карточке Архива не сказало ему ничего. */
        'why'   => 'отзыв доступа снова не доходит до приёмника портала',
        'file'  => 'app/Models/Access.php',
        'from'  => '        if ($event === \'access.revoked\') \\App\\Oidc\\EventDispatcher::accessRevoked($user, $client, $mode !== \'\' ? $mode : \'closed\');',
        'to'    => '',
        'check' => 'tests/deletion_events.php',
    ],
    [
        'why'   => 'отклонённая заявка снова выдаётся за отзыв доступа',
        'file'  => 'app/Models/Access.php',
        'from'  => '        if (in_array($was[\'status\'] ?? \'\', [\'active\', \'blocked\'], true)) {',
        'to'    => '        if (true) {',
        'check' => 'tests/deletion_events.php',
    ],
    [
        'why'   => 'стирание администратором снова выдаётся за «можно вернуть»',
        'file'  => 'app/Controllers/Admin/UsersController.php',
        'from'  => '                EventDispatcher::userDeleted($user, \'purged\');',
        'to'    => '                EventDispatcher::userDeleted($user, \'scheduled\');',
        'check' => 'tests/deletion_events.php',
    ],
    [
        /* Решение владельца 12.09: «Закрыть» — в архив, «Убрать» — стереть. */
        'why'   => '«Закрыть» снова выдаётся за «стереть»',
        'file'  => 'app/Models/Access.php',
        'from'  => '            self::announce(\'access.revoked\', $userId, $clientDbId, \'closed\');',
        'to'    => '            self::announce(\'access.revoked\', $userId, $clientDbId, \'removed\');',
        'check' => 'tests/deletion_events.php',
    ],
    [
        /* Живой случай 12.09: test «закрыли», потом «убрали» — второе не ушло вовсе. */
        'why'   => '«Убрать» после «Закрыть» снова молчит',
        'file'  => 'app/Models/Access.php',
        'from'  => '        if (in_array($was[\'status\'] ?? \'\', [\'active\', \'blocked\'], true)) {',
        'to'    => '        if (in_array($was[\'status\'] ?? \'\', [\'active\'], true)) {',
        'check' => 'tests/deletion_events.php',
    ],
    [
        'why'   => 'возврат доступа снова не доходит до сервиса',
        'file'  => 'app/Models/Access.php',
        'from'  => '        if ($event === \'access.granted\') \\App\\Oidc\\EventDispatcher::accessGranted($user, $client);',
        'to'    => '',
        'check' => 'tests/deletion_events.php',
    ],
    [
        'why'   => 'отказ от сервиса в профиле снова стирает данные',
        'file'  => 'app/Controllers/ProfileController.php',
        'from'  => '            Access::remove((int)$user[\'id\'], (int)$client[\'id\'], \'closed\');',
        'to'    => '            Access::remove((int)$user[\'id\'], (int)$client[\'id\']);',
        'check' => 'tests/deletion_events.php',
    ],
    [
        /* Тема портала на экране входа (v4.65): параметры, а не CSS. */
        'why'   => 'тема портала снова применяется без проверки контраста',
        'file'  => 'app/Core/Bridge.php',
        'from'  => '            if ($c < self::AA_TEXT) {',
        'to'    => '            if (false) {',
        'check' => 'tests/portal_theme.php',
    ],
    [
        'why'   => 'светлая палитра снова проходит под видом тёмной',
        'file'  => 'app/Core/Bridge.php',
        'from'  => '        if ((Palette::luminance($text) > Palette::luminance($bg)) !== $dark) {',
        'to'    => '        if (false) {',
        'check' => 'tests/portal_theme.php',
    ],
    [
        'why'   => 'шрифт темы снова принимается не из нашего списка',
        'file'  => 'app/Core/Bridge.php',
        'from'  => '        if (in_array($font, self::UI_FONTS, true) || ($own && FontLibrary::forUi($font))) $t[\'font\'] = $font;',
        'to'    => '        if ($font !== \'\') $t[\'font\'] = $font;',
        'check' => 'tests/portal_theme.php',
    ],
    [
        'why'   => 'тема из базы снова уходит в <style> без повторного разбора',
        'file'  => 'app/Core/Bridge.php',
        'from'  => '        $t = self::sanitize(self::decode($client[\'brand_theme\'] ?? null));',
        'to'    => '        $t = self::decode($client[\'brand_theme\'] ?? null);',
        'check' => 'tests/portal_theme.php',
    ],
    [
        'why'   => 'цитата портала снова выводится без экранирования',
        'file'  => 'app/helpers.php',
        'from'  => '$quote = $theme[\'headline\'] !== null ? \'<p class="portal-pane-quote">\' . esc($theme[\'headline\']) . \'</p>\' : \'\';',
        'to'    => '$quote = $theme[\'headline\'] !== null ? \'<p class="portal-pane-quote">\' . $theme[\'headline\'] . \'</p>\' : \'\';',
        'check' => 'tests/portal_theme.php',
    ],
    [
        'why'   => 'экран входа снова не надевает тему портала',
        'file'  => 'templates/layouts/auth.php',
        'from'  => '<?= App\Core\Bridge::themeStyle($portal) ?>',
        'to'    => '',
        'check' => 'tests/portal_theme.php',
    ],
    [
        /* «Не помню» портал в шаблон не передаёт — тема обязана браться из потока. */
        'why'   => 'соседние экраны потока снова теряют тему портала',
        'file'  => 'templates/layouts/auth.php',
        'from'  => '$portal = !empty($client) ? $client : ($preview === null ? \App\Core\LoginFlow::pendingClient() : null);',
        'to'    => '$portal = $client ?? null;',
        'check' => 'tests/portal_theme.php',
    ],
    [
        /* Живой случай 12.09: людей Портфолио перенесли без ролей (v4.66). */
        'why'   => 'перенос снова не читает роли портала',
        'file'  => 'app/Core/PortalUsers.php',
        'from'  => '            \'roles\' => $roles,',
        'to'    => '            \'roles\' => [],',
        'check' => 'tests/portal_roles.php',
    ],
    [
        'why'   => 'портал снова может раздать общую роль SSO через перенос',
        'file'  => 'app/Core/PortalUsers.php',
        'from'  => '                    : $clientAdmin !== null && (int)$m[\'id\'] === (int)$clientAdmin[\'id\'];',
        'to'    => '                    : true;',
        'check' => 'tests/portal_roles.php',
    ],
    [
        'why'   => 'роль из переноса снова ложится на всю систему, а не на портал',
        'file'  => 'app/Core/PortalUsers.php',
        'from'  => '        $scope = Scope::client($clientId);',
        'to'    => '        $scope = Scope::system();',
        'check' => 'tests/portal_roles.php',
    ],
    [
        'why'   => 'роли без пары снова пропадают молча',
        'file'  => 'app/Core/PortalUsers.php',
        'from'  => '                $stat[$why][$slug] = ($stat[$why][$slug] ?? 0) + 1;',
        'to'    => '',
        'check' => 'tests/portal_roles.php',
    ],
    [
        /* Прогон 12.09 (v4.67). */
        'why'   => 'отклонённая заявка снова «ушла в архив»',
        'file'  => 'app/Controllers/Admin/UsersController.php',
        'from'  => '                    \'pending\' => t(\'Заявка отклонена — сюда человек не войдёт; передумаете — «Открыть».\'),',
        'to'    => '',
        'check' => 'tests/deletion_events.php',
    ],
    [
        'why'   => 'администратор снова может закрыть доступ себе',
        'file'  => 'app/Controllers/Admin/UsersController.php',
        'from'  => '        if ((int)$user[\'id\'] === $actorId && in_array($action, [\'revoke\', \'deny\'], true)) {',
        'to'    => '        if (false) {',
        'check' => 'tests/deletion_events.php',
    ],
    [
        'why'   => 'плашка роли снова показывает слаг вместо названия',
        'file'  => 'templates/admin/_role_cell.php',
        'from'  => '<?= esc(($r[\'name\'] ?? \'\') !== \'\' ? $r[\'name\'] : $r[\'slug\']) ?>',
        'to'    => '<?= esc($r[\'slug\']) ?>',
        'check' => 'tests/deletion_events.php',
    ],
    [
        'why'   => 'у «Доставки» снова висит число доставленного',
        'file'  => 'app/Controllers/Admin/ClientsController.php',
        'from'  => '            \'events\' => (int)\App\Core\DB::val("SELECT COUNT(*) FROM {client_events} WHERE client_id = ? AND status <> \'sent\'", [$id]),',
        'to'    => '            \'events\' => count(ClientEvent::recentForClient($id)),',
        'check' => 'tests/deletion_events.php',
    ],
    [
        'why'   => 'версия PHP снова уходит наружу',
        'file'  => 'public/index.php',
        'from'  => 'header_remove(\'X-Powered-By\');',
        'to'    => '',
        'check' => 'tests/portal_theme.php',
    ],
    [
        /* Разделы карточки портала по местам (v4.68). */
        'why'   => 'перенос людей снова пропал из «Пользователей»',
        'file'  => 'templates/admin/client/people.php',
        'from'  => '<?php if (\App\Core\Auth::can(\'clients.write\')): $pu = App\Core\PortalUsers::linkedCount((int)$c[\'id\']); ?>',
        'to'    => '<?php if (false): $pu = 0; ?>',
        'check' => 'tests/deletion_events.php',
    ],
    [
        'why'   => '«Спросить у портала» снова пропало из «Подключения»',
        'file'  => 'templates/admin/client/artifacts.php',
        'from'  => '    <button class="btn btn-secondary" type="submit"><?= icon(\'refresh\', 16) ?> <?= esc(t(\'Спросить у портала\')) ?></button>',
        'to'    => '',
        'check' => 'tests/deletion_events.php',
    ],
    [
        /* Карточки объектов (v4.69), замечания владельца 13.09. */
        'why'   => 'в карту ролей снова попадают одноимённые роли портала',
        'file'  => 'app/Models/ClientRoleMap.php',
        'from'  => '        $roles = DB::all(\'SELECT id, slug FROM {roles} WHERE client_id IS NULL\');',
        'to'    => '        $roles = DB::all(\'SELECT id, slug FROM {roles} WHERE client_id IS NULL OR client_id = ?\', [$clientId]);',
        'check' => 'tests/admin_cards.php',
    ],
    [
        'why'   => 'роли самого портала снова идут через карту и теряются',
        'file'  => 'app/Models/ClientRoleMap.php',
        'from'  => '            if ($own !== \':\' && str_starts_with($slug, $own)) {',
        'to'    => '            if (false) {',
        'check' => 'tests/admin_cards.php',
    ],
    [
        'why'   => 'роль на организацию снова не делает человека участником',
        'file'  => 'app/Controllers/Admin/OrgsController.php',
        'from'  => '                if ($joined) Org::addMember($orgId, $uid, \'member\', (int)Auth::id());',
        'to'    => '',
        'check' => 'tests/admin_cards.php',
    ],
    [
        'why'   => 'в состав организации снова ищут только по почте',
        'file'  => 'app/Controllers/Admin/OrgsController.php',
        'from'  => '                    : User::findByLoginOrEmail(trim((string)($_POST[\'email\'] ?? \'\')));',
        'to'    => '                    : User::findByEmail(trim((string)($_POST[\'email\'] ?? \'\')));',
        'check' => 'tests/admin_cards.php',
    ],
    [
        'why'   => 'имя объекта снова повторяется в крошках',
        'file'  => 'templates/admin/object_shell.php',
        'from'  => '  <a href="<?= esc($backTo) ?>"><?= icon(\'arrow-left\', 14, \'icon-muted\') ?> <?= esc($backTitle) ?></a>',
        'to'    => '  <a href="<?= esc($backTo) ?>"><?= icon(\'arrow-left\', 14, \'icon-muted\') ?> <?= esc($backTitle) ?></a> <?= esc($map::title($object)) ?>',
        'check' => 'tests/admin_cards.php',
    ],
    [
        'why'   => 'у объекта снова нет лица в шапке',
        'file'  => 'templates/admin/object_shell.php',
        'from'  => '      <span class="rail-badge"><?= $badge ?></span>',
        'to'    => '',
        'check' => 'tests/admin_cards.php',
    ],

    /* ---- v4.70: ревизия ядра. Семейство «проверить, потом пометить». ---- */
    [
        'why'   => 'код авторизации снова гасится безусловно — два обмена проходят оба',
        'file'  => 'app/Models/AuthCode.php',
        'from'  => "'id = ? AND consumed_at IS NULL', [\$id]) === 1;",
        'to'    => "'id = ?', [\$id]) === 1;",
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'одноразовая ссылка снова гасится безусловно',
        'file'  => 'app/Models/OneTimeToken.php',
        'from'  => "'id = ? AND consumed_at IS NULL', [\$id]) === 1;",
        'to'    => "'id = ?', [\$id]) === 1;",
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'код восстановления снова входит дважды',
        'file'  => 'app/Models/User.php',
        'from'  => "'user_id = ? AND code_hash = ? AND used_at IS NULL', [\$userId, \$hash]) === 1;",
        'to'    => "'user_id = ? AND code_hash = ?', [\$userId, \$hash]) === 1;",
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'повторная ротация refresh снова выдаёт новый токен',
        'file'  => 'app/Models/RefreshToken.php',
        'from'  => "\$won = \$row['rotated_at'] === null",
        'to'    => "\$won = true || \$row['rotated_at'] === null",
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'повтор кода снова только пишется в аудит, а refresh по нему живёт',
        'file'  => 'app/Oidc/TokenFlow.php',
        'from'  => '             WHERE user_id = ? AND client_id = ? AND session_id = ? AND revoked_at IS NULL\',',
        'to'    => '             WHERE 1 = 0 AND user_id = ? AND client_id = ? AND session_id = ? AND revoked_at IS NULL\',',
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'refresh снова выкидывает человека, ждущего подтверждения почты',
        'file'  => 'app/Oidc/TokenFlow.php',
        'from'  => "        if (!\\App\\Models\\User::canSignIn(\$user)) {\n            RefreshToken::revokeFamily(",
        'to'    => "        if (!\$user || \$user['status'] !== 'active') {\n            RefreshToken::revokeFamily(",
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'лимит писем на адрес снова считается и не применяется',
        'file'  => 'app/Controllers/AuthController.php',
        'from'  => ", 3, 3600)) {\n            \$user = User::findByEmail(\$email);",
        'to'    => ", 3, 3600) || true) {\n            \$user = User::findByEmail(\$email);",
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'TOTP-код снова входит второй раз в свои полторы минуты',
        'file'  => 'app/Controllers/AuthController.php',
        'from'  => "if (\$step !== null && User::claimTotpStep((int)\$user['id'], \$step)) {",
        'to'    => "if (\$step !== null) {",
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'перебор кода 2FA снова ограничен только сессией',
        'file'  => 'app/Controllers/AuthController.php',
        'from'  => '        RateLimiter::guard($bucket, 10, 900);',
        'to'    => '',
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'табуляция в адресе возврата снова уводит на //чужой.сайт',
        'file'  => 'app/helpers.php',
        'from'  => 'if (preg_match(\'~[\x00-\x1F\x7F]~\', $to)) return $fallback;',
        'to'    => 'if (preg_match(\'~[\r\n\x00]~\', $to)) return $fallback;',
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'путь из Referer снова уходит в Location без проверки',
        'file'  => 'app/helpers.php',
        'from'  => "    return local_path(\$path . (\$query !== '' ? '?' . \$query : ''), \$fallback);",
        'to'    => "    return \$path !== '' ? \$path . (\$query !== '' ? '?' . \$query : '') : \$fallback;",
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'выход снова уходит без стандартного имени события — чужая библиотека его не видит',
        'file'  => 'app/Oidc/IdToken.php',
        'from'  => "            \$events[self::BACKCHANNEL_LOGOUT] = new \\stdClass();",
        'to'    => '',
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'наш набор снова получает стандартный ключ и копит «необработанные» на каждом выходе',
        'file'  => 'app/Oidc/IdToken.php',
        'from'  => "if (\$event === 'logout' && empty(\$client['manifest_fetched_at'])) {",
        'to'    => "if (\$event === 'logout') {",
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'ответ авторизации снова не называет себя (RFC 9207)',
        'file'  => 'app/Oidc/AuthorizeFlow.php',
        'from'  => "'state' => \$q['state'], 'iss' => base_url()]);",
        'to'    => "'state' => \$q['state']]);",
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'ссылку из письма снова гасит GET — почтовый сканер входит вместо человека',
        'file'  => 'app/Controllers/AuthController.php',
        'from'  => "        if (OneTimeToken::peek(\$token, 'login_link') === null) {",
        'to'    => "        if (OneTimeToken::consume(\$token, 'login_link') === null) {",
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'удалённая организация снова оставляет роли на пустоту',
        'file'  => 'app/Models/Org.php',
        'from'  => "\$n = DB::run('DELETE FROM {user_roles} WHERE scope_type = ? AND scope_id = ?',",
        'to'    => "\$n = DB::run('DELETE FROM {user_roles} WHERE 1 = 0 AND scope_type = ? AND scope_id = ?',",
        'check' => 'tests/core_hardening.php',
    ],
    [
        'why'   => 'галочка согласия снова обязательна без документов',
        'file'  => 'app/Controllers/OidcController.php',
        'from'  => "\n            && \\App\\Core\\Legal::requiringConsent() !== []) {",
        'to'    => ') {',
        'check' => 'tests/core_hardening.php',
    ],

    /* ---- v4.70: интерфейс ---- */
    [
        'why'   => 'identity.css снова объявил класс, занятый в app.css другим компонентом',
        'file'  => 'public/assets/css/identity.css',
        'from'  => '.tile-list { display: grid; gap: var(--s-2); }',
        'to'    => ".tile-list { display: grid; gap: var(--s-2); }\n.list-card { color: inherit; }",
        'check' => 'tests/templates_test.php',
    ],
    [
        'why'   => 'у события аудита пропал перевод — словарь событий снова вне проверки',
        'file'  => 'app/Lang/en-admin.php',
        'from'  => "    'Уборка осиротевших записей' => 'Orphaned records cleaned up',\n",
        'to'    => '',
        'check' => 'tools/i18n_coverage.php',
    ],
    [
        'why'   => 'на плитке портала снова «first-party» по-английски',
        'file'  => 'templates/admin/clients.php',
        'from'  => "    if (!\$c['is_trusted']) \$tags[] = ['badge-muted', t('сторонний')];",
        'to'    => "    if (\$c['is_trusted']) \$tags[] = ['badge-muted', 'first-party'];",
        'check' => 'tests/admin_cards.php',
    ],
    [
        'why'   => 'выдача роли снова раскрывается внутри строки',
        'file'  => 'templates/admin/_role_cell.php',
        'from'  => '  <div class="role-add" id="<?= $popId ?>" popover>',
        'to'    => '  <details class="role-add" id="<?= $popId ?>">',
        'check' => 'tests/admin_cards.php',
    ],
    /* ---- v4.71: экран входа настраивается в SSO ---- */
    [
        'why'   => 'предпросмотр оформления снова нельзя показать во фрейме редактора',
        'file'  => 'app/Core/LoginLook.php',
        'from'  => '        Csp::allowSelfFraming();',
        'to'    => '',
        'check' => 'tests/login_look.php',
    ],
    [
        'why'   => 'живой экран входа снова можно встроить во фрейм — clickjacking',
        'file'  => 'public/index.php',
        'from'  => "    'frame-ancestors' => [\"'none'\"],",
        'to'    => "    'frame-ancestors' => [\"'self'\"],",
        'check' => 'tests/login_look.php',
    ],
    [
        'why'   => 'манифест портала снова может прислать обложку и подпись в тему',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "        if (\$scope === 'portal') {",
        'to'    => "        if (\$scope !== 'site') {",
        'check' => 'tests/login_look.php',
    ],
    [
        'why'   => 'раскладка из базы снова берётся без проверки по списку',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "        if (in_array(\$layout, self::LAYOUTS, true)) \$t['layout'] = \$layout;",
        'to'    => "        if (\$layout !== '') \$t['layout'] = \$layout;",
        'check' => 'tests/login_look.php',
    ],
    [
        'why'   => 'своё оформление портала снова не действует — только манифест',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "            if (\$mode === 'custom') {",
        'to'    => "            if (\$mode === 'never') {",
        'check' => 'tests/login_look.php',
    ],
    [
        'why'   => 'выбор способов входа портала снова не доходит до экрана',
        'file'  => 'app/Core/Bridge.php',
        'from'  => '        return $only === null || in_array($method, $only, true);',
        'to'    => '        return true;',
        'check' => 'tests/login_look.php',
    ],
    [
        'why'   => 'портал снова можно оставить без единого способа входа',
        'file'  => 'app/Core/LoginLook.php',
        'from'  => '            if ($alive === []) {',
        'to'    => '            if (false) {',
        'check' => 'tests/login_look.php',
    ],
    [
        /* v4.81: обложка — набором CoverSet, у установки и у порталов. */
        'why'   => 'обложка снова кладётся как пришла — без перекодирования',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "            if (!LoginLook::storeImage(\$f['tmp_name'], self::path(\$scope, \$id, 'webp'), 1600, 1400, 82)) {",
        'to'    => "            if (!@copy(\$f['tmp_name'], self::path(\$scope, \$id, 'webp'))) {",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'картинки удалённого портала снова остаются на диске',
        'file'  => 'app/Models/Client.php',
        'from'  => '            if (is_file($dir . $f)) @unlink($dir . $f);',
        'to'    => '',
        'check' => 'tests/login_look.php',
    ],
    [
        'why'   => 'палитра из цвета снова без запаса по контрасту текста',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "        \$text = Palette::fit(Palette::blend('#16140F', \$a, 0.10), \$grounds, 7.0, false);",
        'to'    => "        \$text = Palette::blend('#16140F', \$a, 0.75);",
        'check' => 'tests/login_look.php',
    ],
    [
        'why'   => 'в списке пользователей снова машинные слаги ролей',
        'file'  => 'templates/admin/users.php',
        'from'  => "<span class=\"badge badge-muted\" title=\"<?= esc(\$r['slug']) ?>\"><?= esc((\$r['name'] ?? '') !== '' ? \$r['name'] : \$r['slug']) ?></span>",
        'to'    => "<span class=\"badge badge-muted mono\"><?= esc(\$r['slug']) ?></span>",
        'check' => 'tests/login_look.php',
    ],
    /* ---- v4.72: защита — подтверждение почты, блокировки, журнал; вход окном ---- */
    [
        'why'   => 'подтверждение почты снова держит и действующих, кто пришёл до политики',
        'file'  => 'app/Core/EmailGate.php',
        'from'  => "            && (int)(\$user['email_gate'] ?? 0) === 1\n",
        'to'    => '',
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => 'вход паролем снова пропускает неподтверждённого нового',
        'file'  => 'app/Core/LoginFlow.php',
        'from'  => '        if (EmailGate::holds($user)) EmailGate::stop($user, $remember);',
        'to'    => '',
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => 'код из письма снова можно перебирать',
        'file'  => 'app/Controllers/EmailGateController.php',
        'from'  => "        RateLimiter::guard('gate:code:' . (int)\$user['id'], 10, 900);",
        'to'    => "        RateLimiter::guard('gate:code:' . (int)\$user['id'], 1000, 900);",
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => 'администратор снова может заблокировать собственный адрес',
        'file'  => 'app/Core/IpBlock.php',
        'from'  => "        if (\$mine !== null && self::inRange(\$mine, \$r['from'], \$r['to'])) {",
        'to'    => "        if (false) {",
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => 'одна опечатка в подсети снова закрывает полинтернета',
        'file'  => 'app/Core/IpBlock.php',
        'from'  => '        if ($prefix < self::MIN_PREFIX[$len]) {',
        'to'    => '        if ($prefix < 1) {',
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => 'заблокированный адрес снова открывает страницы',
        'file'  => 'public/index.php',
        'from'  => "    \\App\\Core\\IpBlock::enforce();\n",
        'to'    => '',
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => 'блокировка со сроком снова не кончается',
        'file'  => 'app/Core/IpBlock.php',
        'from'  => "WHERE expires_at IS NULL OR expires_at > ?', [now()])",
        'to'    => "WHERE expires_at IS NULL OR expires_at <> ?', [now()])",
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => 'токены из ссылок в письмах снова ложатся в журнал активности',
        'file'  => 'app/Core/ActivityLog.php',
        'from'  => "'~/[A-Za-z0-9_\\-]{16,}(?=/|\$)~'",
        'to'    => "'~/[A-Za-z0-9_\\-]{999,}(?=/|\$)~'",
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => 'значения из строки запроса (state, коды) снова пишутся в журнал',
        'file'  => 'app/Core/ActivityLog.php',
        'from'  => '        $keys = array_keys($q);',
        'to'    => "        \$keys = array_map(static fn(\$k, \$v) => \$k . '=' . (is_array(\$v) ? '' : \$v), array_keys(\$q), \$q);",
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => 'статика снова забивает журнал активности',
        'file'  => 'app/Core/ActivityLog.php',
        'from'  => "'~^/(assets|brand|",
        'to'    => "'~^/(nothing|brand|",
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => 'новый аккаунт снова остаётся без логина из почты',
        'file'  => 'app/Models/User.php',
        'from'  => "        if ((\$data['login'] ?? null) === null && !empty(\$data['email'])) {",
        'to'    => '        if (false) {',
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => '«Вы вошли» снова показывается порталу, который его не включал',
        'file'  => 'app/Oidc/AuthorizeFlow.php',
        'from'  => "        if (\$fresh && (int)(\$client['login_welcome'] ?? 0) === 1 && (\$q['prompt'] ?? '') !== 'none') {",
        'to'    => "        if (\$fresh && (\$q['prompt'] ?? '') !== 'none') {",
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => '«Вы вошли» снова останавливает тихий вход prompt=none',
        'file'  => 'app/Oidc/AuthorizeFlow.php',
        'from'  => "        if (\$fresh && (int)(\$client['login_welcome'] ?? 0) === 1 && (\$q['prompt'] ?? '') !== 'none') {",
        'to'    => "        if (\$fresh && (int)(\$client['login_welcome'] ?? 0) === 1) {",
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => '«Вы вошли» снова показывается порталу, ради которого не входили',
        'file'  => 'app/Core/LoginFlow.php',
        'from'  => "        return is_array(\$f) && (\$f['client'] ?? '') === \$clientId && ",
        'to'    => "        return is_array(\$f) && ",
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => 'способ открытия входа снова сохраняется без проверки по списку',
        'file'  => 'app/Core/LoginLook.php',
        'from'  => "            \$fields['login_display'] = in_array(\$display, self::DISPLAYS, true) ? \$display : null;",
        'to'    => "            \$fields['login_display'] = \$display !== '' ? \$display : null;",
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => 'страница портала снова не может прочитать, как открывать вход',
        'file'  => 'app/Controllers/OidcController.php',
        'from'  => "        \$headers = ['Access-Control-Allow-Origin' => '*'];",
        'to'    => '        $headers = [];',
        'check' => 'tests/guard.php',
    ],
    /* ---- v4.72.1: предпросмотр экрана входа не роняет вкладку ---- */
    [
        'why'   => 'предпросмотр снова создаёт новый документ во фрейме на каждое изменение',
        'file'  => 'public/assets/js/app.js',
        'from'  => '        .then(swap)',
        'to'    => '        .then(function () { frame.contentWindow.location.replace(url); })',
        'check' => 'tests/login_look.php',
    ],
    [
        'why'   => 'новый адрес предпросмотра снова обрывает недогруженный',
        'file'  => 'public/assets/js/app.js',
        'from'  => '      if (busy) { again = true; return; }',
        'to'    => '',
        'check' => 'tests/login_look.php',
    ],
    /* ---- v4.72.2: старт входа из sso.js несёт поля формы кнопки ---- */
    [
        'why'   => 'sso.js снова шлёт пустой POST — CSRF-токен портала не доезжает',
        'file'  => 'public/assets/js/sso.js',
        'from'  => "      new FormData(form).forEach(function (v, k) { if (typeof v === 'string') body.append(k, v); });",
        'to'    => '',
        'check' => 'tests/guard.php',
    ],
    /* ---- v4.73: вход окном на странице портала; акцент портала — тема ---- */
    [
        'why'   => 'портал на своём домене снова получает отдельную cookie фрейма — общий вход теряется',
        'file'  => 'app/Core/Framed.php',
        'from'  => "            && (\$_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe'\n            && (\$_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site';",
        'to'    => "            && (\$_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe';",
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'cookie фрейма снова без Partitioned — браузеры без сторонних cookie её не примут',
        'file'  => 'app/Core/Framed.php',
        'from'  => "        return self::\$partitioned ? 'None; Partitioned' : 'Lax';",
        'to'    => "        return self::\$partitioned ? 'None' : 'Lax';",
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'во фрейм снова пускается всё, а не только экраны входа',
        'file'  => 'app/Core/Framed.php',
        'from'  => "            if (\$path === \$p || str_starts_with(\$path, \$p . '/')) return true;",
        'to'    => '            return true;',
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'экран входа снова может встроить любой сайт',
        'file'  => 'app/Core/Framed.php',
        'from'  => '        Csp::setFrameAncestors($origins);',
        'to'    => "        Csp::setFrameAncestors(['*']);",
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'X-Frame-Options: DENY снова спорит с разрешением встраивания',
        'file'  => 'app/Core/Csp.php',
        'from'  => "        if (!headers_sent()) header_remove('X-Frame-Options');",
        'to'    => '',
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'ответ /authorize во фрейме снова не разрешает встраивание',
        'file'  => 'app/Oidc/AuthorizeFlow.php',
        'from'  => "        if ((\$q['display'] ?? '') === Framed::DISPLAY) Framed::allowFraming(\$q);",
        'to'    => '',
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'после входа во фрейме колбэк портала снова открывается внутри окна',
        'file'  => 'app/Oidc/AuthorizeFlow.php',
        'from'  => "        if (\$origin !== null) self::handoff(\$client, \$back, \$origin, t('Вы вошли'));",
        'to'    => '',
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'отказ из фрейма снова открывается внутри окна',
        'file'  => 'app/Oidc/AuthorizeFlow.php',
        'from'  => "                self::handoff(\$client, \$url, \$origin, t('Возвращаемся в сервис'));",
        'to'    => '',
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => '«Вы вошли» во фрейме снова уводит фрейм, а не отдаёт адрес странице',
        'file'  => 'app/Oidc/AuthorizeFlow.php',
        'from'  => "                'popup' => LoginFlow::compact(\$q), 'origin' => \$origin,",
        'to'    => "                'popup' => LoginFlow::compact(\$q),",
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'браузер без cookie во фрейме снова получает форму, которая не войдёт',
        'file'  => 'app/Controllers/AuthController.php',
        'from'  => '        if (\App\Core\Framed::cookiesBlocked()) {',
        'to'    => '        if (false) {',
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'sso.js снова принимает сообщения не только от своего фрейма SSO',
        'file'  => 'public/assets/js/sso.js',
        'from'  => '    if (!modal || e.origin !== issuer || e.source !== modal.frame.contentWindow) return;',
        'to'    => '    if (!modal) return;',
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'адрес с кодом снова уходит любой странице, а не порталу',
        'file'  => 'public/assets/js/app.js',
        'from'  => "      if (framed) window.parent.postMessage({ type: 'sso:code', url: a.href }, origin);",
        'to'    => "      if (framed) window.parent.postMessage({ type: 'sso:code', url: a.href }, '*');",
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'портал, приславший один акцент, снова не получает свой цвет',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "        return preg_match('~^#[0-9A-Fa-f]{6}\$~', \$accent) ? ['accent' => strtoupper(\$accent)] : [];",
        'to'    => '        return [];',
        'check' => 'tests/frame.php',
    ],
    /* ---- v4.74: письма и интерфейс на одном языке; подтверждение почты — одним письмом ---- */
    [
        'why'   => 'тексты писем «как в поставке» снова идут по-русски в английском письме',
        'file'  => 'app/Core/MailTemplates.php',
        'from'  => "        return \$default !== '' ? t(\$default) : '';",
        'to'    => '        return $default;',
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'подпись под письмом снова не переводится',
        'file'  => 'app/Core/MailTemplates.php',
        'from'  => "        return \$own !== '' ? \$own : t(self::FOOTER_DEFAULT);",
        'to'    => "        return \$own !== '' ? \$own : self::FOOTER_DEFAULT;",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'у письма снова зашит язык ru',
        'file'  => 'templates/emails/base.php',
        'from'  => '<html lang="<?= esc(lang()) ?>">',
        'to'    => '<html lang="ru">',
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'письмо, отправленное за человека, снова на языке отправителя',
        'file'  => 'app/Core/Mailer.php',
        'from'  => "            if (!\$self) \$locale = DB::val(",
        'to'    => "            if (false) \$locale = DB::val(",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'тост на экране входа снова не переводится',
        'file'  => 'templates/layouts/auth.php',
        'from'  => "<?= esc(t(\$f['message'])) ?>",
        'to'    => "<?= esc(\$f['message']) ?>",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'заголовок экрана входа снова не переводится',
        'file'  => 'templates/layouts/auth.php',
        'from'  => "<title><?= esc(t(\$pageTitle ?? 'Вход') . ' — ' . \$appName) ?></title>",
        'to'    => "<title><?= esc((\$pageTitle ?? 'Вход') . ' — ' . \$appName) ?></title>",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'после обновления с 4.72 правило подтверждения почты снова выключается само',
        'file'  => 'app/Core/EmailGate.php',
        'from'  => "        return in_array(\$m, ['on', 'link', 'code'], true) ? 'on' : 'off';",
        'to'    => "        return \$m === 'on' ? 'on' : 'off';",
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => 'в письме подтверждения снова только код, без ссылки',
        'file'  => 'app/Core/EmailGate.php',
        'from'  => "            'name' => user_name(\$user), 'code' => \$code, 'url' => base_url('/verify/' . \$token),",
        'to'    => "            'name' => user_name(\$user), 'code' => \$code, 'url' => '',",
        'check' => 'tests/guard.php',
    ],
    [
        'why'   => 'оповещение владельцу снова на языке того, кто его разбудил',
        'file'  => 'app/Core/AdminNotify.php',
        'from'  => "            Mailer::as(\$addr, static function () use (\$addr, \$event, \$message, \$details, \$skipped): void {",
        'to'    => "            Lang::using(null, static function () use (\$addr, \$event, \$message, \$details, \$skipped): void {",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'письмо за другого снова на языке отправителя',
        'file'  => 'app/Core/Mailer.php',
        'from'  => "            if (!\$self) \$locale = DB::val('SELECT locale FROM {users} WHERE email = ?', [mb_strtolower(trim(\$to))]);",
        'to'    => "            if (!\$self) \$locale = null;",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'plural() снова отдаёт русское слово в английской панели',
        'file'  => 'app/helpers.php',
        'from'  => "    if (lang() !== 'ru') return t(abs(\$n) === 1 ? \$one : (\$many !== \$one ? \$many : \$few));",
        'to'    => "    /* снято */",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => '«3 человек» по-английски снова «3 person»',
        'file'  => 'app/helpers.php',
        'from'  => "    if (lang() !== 'ru') return t(abs(\$n) === 1 ? \$one : (\$many !== \$one ? \$many : \$few));",
        'to'    => "    if (lang() !== 'ru') return t(abs(\$n) === 1 ? \$one : \$many);",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'подписи списка «ключ — значение» снова не переводятся',
        'file'  => 'app/helpers.php',
        'from'  => "        \$out .= '<dt>' . esc(t((string)\$k)) . '</dt>",
        'to'    => "        \$out .= '<dt>' . esc(\$k) . '</dt>",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'подписи уведомлений в профиле снова без перевода',
        'file'  => 'templates/profile/settings.php',
        'from'  => "t(\$p['label']), (bool)\$p['enabled'])",
        'to'    => "\$p['label'], (bool)\$p['enabled'])",
        'check' => 'tests/mail_i18n.php',
    ],
    /* Сторож перевода видит то, что до 4.74 видел только разбор токенов:
       без перевода строки он обязан покраснеть. */
    [
        'why'   => 'сторож перевода снова не видит пару в столбик (оповещения владельцу)',
        'file'  => 'app/Lang/en-admin.php',
        'from'  => "    'Мост с порталом проложен' => 'Portal bridge established',\n",
        'to'    => "",
        'check' => 'tools/i18n_coverage.php',
    ],
    [
        'why'   => 'сторож перевода снова не видит подписи уведомлений человеку',
        'file'  => 'app/Lang/en-admin.php',
        'from'  => "    'Вход с нового устройства' => 'Sign-in from a new device',\n",
        'to'    => "",
        'check' => 'tools/i18n_coverage.php',
    ],
    [
        'why'   => 'сторож перевода снова не видит слова склонения',
        'file'  => 'app/Lang/en-admin.php',
        'from'  => "    'ключ' => 'key', 'ключа' => 'keys', 'ключей' => 'keys',\n",
        'to'    => "",
        'check' => 'tools/i18n_coverage.php',
    ],
    [
        'why'   => 'кнопка «показать пароль» снова пишет по-русски «скрыть»',
        'file'  => 'public/assets/js/app.js',
        'from'  => "    eye.textContent = input.type === 'password' ? L('show', 'показать') : L('hide', 'скрыть');",
        'to'    => "    eye.textContent = input.type === 'password' ? 'показать' : 'скрыть';",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'экраны входа снова без словаря для app.js',
        'file'  => 'templates/layouts/auth.php',
        'from'  => "<?= js_i18n() ?>\n<script src=",
        'to'    => "<script src=",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'в словаре для app.js снова нет подписи, которую он просит',
        'file'  => 'app/helpers.php',
        'from'  => "        'hide' => t('скрыть'),\n",
        'to'    => "",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'фраза письма с выделенным адресом снова мимо перевода',
        'file'  => 'app/helpers.php',
        'from'  => "    return strtr(esc(t(\$key)), \$map);",
        'to'    => "    return strtr(esc(\$key), \$map);",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'подпись устройства снова «Яндекс Браузер» в английском интерфейсе',
        'file'  => 'app/helpers.php',
        'from'  => "    return strtr(\$label, ['Яндекс Браузер' => t('Яндекс Браузер'), 'Браузер' => t('Браузер')]);",
        'to'    => "    return \$label;",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'отпечаток устройства зависит от языка — смена языка шлёт ложное «новое устройство»',
        'file'  => 'app/helpers.php',
        'from'  => "'YaBrowser' => 'Яндекс Браузер',",
        'to'    => "'YaBrowser' => t('Яндекс Браузер'),",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'кнопка входа через Яндекс снова по-русски в английском интерфейсе',
        'file'  => 'app/Social/YandexProvider.php',
        'from'  => "    public function title(): string { return t('Яндекс ID'); }",
        'to'    => "    public function title(): string { return 'Яндекс ID'; }",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'щелчок по ссылке во фрейме снова создаёт новый документ (падение вкладки в Chrome 153)',
        'file'  => 'public/assets/js/app.js',
        'from'  => "    go(a.href, null, !framed);",
        'to'    => "    location.href = a.href;",
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'подмена во фрейме снова показывает страницу, которую портал встраивать не вправе',
        'file'  => 'public/assets/js/app.js',
        'from'  => "        if (framed && !framable(res.r)) throw new Error('not-framable');\n",
        'to'    => "",
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'запрос из фрейма снова идёт по переадресации на чужой адрес',
        'file'  => 'public/assets/js/app.js',
        'from'  => "fetch(url, Object.assign({ credentials: 'same-origin', mode: 'same-origin' }, init || {}))",
        'to'    => "fetch(url, Object.assign({ credentials: 'same-origin' }, init || {}))",
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'код из шести цифр снова уходит form.submit() мимо подмены',
        'file'  => 'public/assets/js/app.js',
        'from'  => "        if (form) submitForm(form);",
        'to'    => "        if (form) form.submit();",
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'вход по passkey во фрейме снова уходит новым документом',
        'file'  => 'public/assets/js/passkey.js',
        'from'  => "(window.ssoGo || function (u) { location.href = u; })(j.redirect)",
        'to'    => "location.href = j.redirect",
        'check' => 'tests/frame.php',
    ],
    [
        'why'   => 'подписи passkey снова вшиты по-русски',
        'file'  => 'public/assets/js/passkey.js',
        'from'  => "    say(L('pkWaiting', 'Ждём подтверждение…'));",
        'to'    => "    say('Ждём подтверждение…');",
        'check' => 'tests/mail_i18n.php',
    ],
    [
        'why'   => 'обложка снова берётся с внутреннего адреса по ссылке со страницы портала',
        'file'  => 'app/Core/PortalCover.php',
        'from'  => "                    if (!filter_var(\$ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {",
        'to'    => "                    if (false) {",
        'check' => 'tests/portal_cover.php',
    ],
    [
        'why'   => 'картинка-бомба снова разворачивается GD целиком',
        'file'  => 'app/Core/PortalCover.php',
        'from'  => "        return \$info[0] > 0 && \$info[1] > 0 && \$info[0] * \$info[1] <= self::MAX_PIXELS;",
        'to'    => "        return \$info[0] > 0 && \$info[1] > 0;",
        'check' => 'tests/portal_cover.php',
    ],
    [
        'why'   => 'картинка с сайта снова затирает свою картинку администратора',
        'file'  => 'app/Core/PortalCover.php',
        'from'  => "        if (\$meta['source'] === 'upload') return ['ok' => false, 'detail' => t('Стоит своя картинка — сайт её не заменяет.')];\n",
        'to'    => "",
        'check' => 'tests/portal_cover.php',
    ],
    [
        'why'   => 'сайт портала снова опрашивается за обложкой каждый час',
        'file'  => 'app/Core/PortalCover.php',
        'from'  => "            if (\$last !== '' && strtotime(\$last . ' UTC') > time() - self::REFRESH) continue;\n",
        'to'    => "",
        'check' => 'tests/portal_cover.php',
    ],
    [
        'why'   => 'без og:image витрина снова не берёт снимок главной портала',
        'file'  => 'app/Core/PortalCover.php',
        'from'  => "        if (self::shotsOn()) {\n            \$shot = self::fromShot(\$id, \$base . '/');",
        'to'    => "        if (false) {\n            \$shot = self::fromShot(\$id, \$base . '/');",
        'check' => 'tests/portal_cover.php',
    ],
    [
        'why'   => 'форма на главной снова не слушает шрифты и скругления из оформления',
        'file'  => 'templates/layouts/guest.php',
        'from'  => "<?= App\\Core\\Bridge::themeStyle(null) ?>\n",
        'to'    => "",
        'check' => 'tests/site_look.php',
    ],
    [
        'why'   => 'оформление «на весь сайт» снова не доходит до кабинета и панели',
        'file'  => 'templates/layouts/base.php',
        'from'  => "<?= App\\Core\\Appearance::lookEverywhere() ? App\\Core\\Bridge::themeStyle(null) : '' ?>\n",
        'to'    => "",
        'check' => 'tests/site_look.php',
    ],
    [
        'why'   => 'выключенное «на весь сайт» снова красит кабинет',
        'file'  => 'templates/layouts/base.php',
        'from'  => "<?= App\\Core\\Appearance::lookEverywhere() ? App\\Core\\Bridge::themeStyle(null) : '' ?>\n",
        'to'    => "<?= App\\Core\\Bridge::themeStyle(null) ?>\n",
        'check' => 'tests/site_look.php',
    ],
    [
        'why'   => 'страницы ошибок снова без оформления сайта',
        'file'  => 'templates/layouts/minimal.php',
        'from'  => "<?= \$look ?>\n",
        'to'    => "",
        'check' => 'tests/site_look.php',
    ],
    [
        'why'   => 'окно входа на странице портала снова закрывается только крестиком',
        'file'  => 'public/assets/js/sso.js',
        'from'  => "      if (ev.target === wrap && downOnVeil) closeModal();\n",
        'to'    => "",
        'check' => 'tests/site_look.php',
    ],
    [
        'why'   => 'форма на главной снова уезжает к краю широкого экрана',
        'file'  => 'public/assets/css/identity.css',
        'from'  => ".guest-body { justify-content: flex-end; padding: 24px max(var(--gutter), calc((100% - 1120px) / 2)) 40px; }",
        'to'    => ".guest-body { justify-content: flex-end; padding: 24px 64px 40px; }",
        'check' => 'tests/site_look.php',
    ],
    [
        'why'   => 'шрифт из Google снова тащит все наборы символов (греческий, вьетнамский)',
        'file'  => 'app/Core/FontLibrary.php',
        'from'  => "            if (!in_array(\$subset, self::SUBSETS, true)) continue;\n",
        'to'    => "",
        'check' => 'tests/fonts_presets.php',
    ],
    [
        'why'   => 'установка шрифта снова принимает файл, который не woff2',
        'file'  => 'app/Core/FontLibrary.php',
        'from'  => "            if (!str_starts_with(\$bytes, 'wOF2')) {",
        'to'    => "            if (\$bytes === '') {",
        'check' => 'tests/fonts_presets.php',
    ],
    [
        'why'   => 'манифест портала снова может назвать шрифт, установленный в SSO',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "        \$own = \$scope !== 'manifest';",
        'to'    => "        \$own = true;",
        'check' => 'tests/fonts_presets.php',
    ],
    [
        'why'   => 'снова можно убрать шрифт, который стоит в оформлении',
        'file'  => 'app/Core/FontLibrary.php',
        'from'  => "        if (\$used !== []) {\n            return ['ok' => false,",
        'to'    => "        if (false) {\n            return ['ok' => false,",
        'check' => 'tests/fonts_presets.php',
    ],
    [
        'why'   => 'отдача шрифта снова подставляет имя из адреса в путь',
        'file'  => 'app/Core/FontLibrary.php',
        'from'  => "        if (!preg_match('~^[a-z0-9-]{1,60}\$~', \$slug) || !preg_match('~^[a-f0-9]{16}\\.woff2\$~', \$file)) return null;",
        'to'    => "",
        'check' => 'tests/fonts_presets.php',
    ],
    [
        'why'   => 'установленные шрифты снова не попадают на экран входа',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "        \$css = FontLibrary::faces(array_filter([\$th['font'], \$th['font_display']]))\n            . (",
        'to'    => "        \$css = (",
        'check' => 'tests/fonts_presets.php',
    ],
    [
        'why'   => 'готовые схемы снова пропали из редактора',
        'file'  => 'templates/admin/_look_editor.php',
        'from'  => "<?php foreach (Bridge::PRESETS as \$pk => [\$pname, \$pal]) echo \$presetTile(\$pk, \$pname, \$pal); ?>",
        'to'    => "<?php foreach ([] as \$pk => [\$pname, \$pal]) echo \$presetTile(\$pk, \$pname, \$pal); ?>",
        'check' => 'tests/fonts_presets.php',
    ],
    [
        'why'   => 'конструктор снова пропускает значение не из списка',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "            if (in_array(\$v, \$allowed, true)) \$t[\$key] = \$v;\n",
        'to'    => "            if (\$v !== '') \$t[\$key] = \$v;\n",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'выбранная форма кнопок и полей снова не доходит до экрана',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "        [\$light, \$dark, \$rules] = self::builderStyle(\$th['builder'] ?? [], \$light, \$dark);\n",
        'to'    => "        \$rules = '';\n",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'толщина заголовков снова зашита в CSS',
        'file'  => 'public/assets/css/app.css',
        'from'  => "h1, h2, h3 { line-height: 1.2; margin: 0 0 12px; font-weight: var(--fw-heading, 600); }",
        'to'    => "h1, h2, h3 { line-height: 1.2; margin: 0 0 12px; font-weight: 600; }",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'своя схема снова тащит заголовок и подпись портала',
        'file'  => 'app/Core/LookSchemes.php',
        'from'  => "        return array_intersect_key(\$theme, array_flip(\$keys));",
        'to'    => "        return \$theme;",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'применение своей схемы снова смешивает её с прежней формой',
        'file'  => 'app/Core/LookSchemes.php',
        'from'  => "            \$out[\$k] = isset(\$theme[\$k]) ? (string)\$theme[\$k] : '';",
        'to'    => "            if (isset(\$theme[\$k])) \$out[\$k] = (string)\$theme[\$k];",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'своих схем снова нет в редакторе портала',
        'file'  => 'templates/admin/_look_editor.php',
        'from'  => "        \$mySchemes = App\\Core\\LookSchemes::all();",
        'to'    => "        \$mySchemes = [];",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'предпросмотр в одну колонку снова прилипает и закрывает настройки',
        'file'  => 'public/assets/css/app.css',
        'from'  => ".look-preview { position: static; min-width: 0; order: -1; }",
        'to'    => ".look-preview { position: sticky; top: calc(var(--isle-h, 56px) + 24px); min-width: 0; order: -1; }",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'удалённый портал снова оставляет обложку витрины на диске',
        'file'  => 'app/Models/Client.php',
        'from'  => "        \\App\\Core\\PortalCover::remove(\$clientDbId);\n",
        'to'    => "",
        'check' => 'tests/portal_cover.php',
    ],
    [
        'why'   => 'карточка без картинки на витрине снова пустая штриховка',
        'file'  => 'templates/layouts/guest.php',
        /* v4.80: на стене — вторая строка карточки своя, у ленты телефона — одной строкой. */
        'from'  => "          <?= portal_card_shot(\$p) ?>\n          <div class=\"pcard-body\">\n",
        'to'    => "          <div class=\"pcard-shot\"></div>\n          <div class=\"pcard-body\">\n",
        'check' => 'tests/portal_cover.php',
    ],
    [
        'why'   => 'предпросмотр экрана входа снова в теме администратора',
        'file'  => 'public/assets/js/app.js',
        'from'  => "  if (!isPreview) {\n    applyTheme(themeGet());",
        'to'    => "  applyTheme(themeGet());\n  if (!isPreview) {",
        'check' => 'tests/login_look.php',
    ],
    [
        'why'   => 'сторож перевода снова не видит группы разделов карточки',
        'file'  => 'app/Lang/en-admin.php',
        'from'  => "    'Люди и роли' => 'People and roles',\n",
        'to'    => "",
        'check' => 'tools/i18n_coverage.php',
    ],
    /* v4.80 «Витрина и интерфейс». */
    [
        'why'   => 'колонка стены снова пустует при малом числе проектов',
        'file'  => 'templates/layouts/guest.php',
        'from'  => 'for ($k = $c + 1; count($list) < 3; $k++) $list[] = $portals[$k % $n];',
        'to'    => '',
        'check' => 'tests/portal_cover.php',
    ],
    [
        'why'   => 'проекты стены снова копятся в одной колонке',
        'file'  => 'templates/layouts/guest.php',
        'from'  => 'foreach ($portals as $i => $p) if ($i % 3 === $c) $list[] = $p;',
        'to'    => 'foreach ($portals as $i => $p) if ($c === 0) $list[] = $p;',
        'check' => 'tests/portal_cover.php',
    ],
    [
        'why'   => 'карточка стены снова тянется на высоту колонки',
        'file'  => 'public/assets/css/identity.css',
        'from'  => '.pwall-track { display: grid; gap: 24px; align-content: start;',
        'to'    => '.pwall-track { display: grid; gap: 24px;',
        'check' => 'tests/portal_cover.php',
    ],
    [
        'why'   => 'стена снова едет у того, кто просил без движения',
        'file'  => 'public/assets/css/identity.css',
        'from'  => '@media (prefers-reduced-motion: reduce) { .pwall-track { animation: none; } }',
        'to'    => '',
        'check' => 'tests/portal_cover.php',
    ],
    [
        'why'   => 'базовые схемы снова пропали из редактора',
        'file'  => 'templates/admin/_look_editor.php',
        'from'  => "<?php foreach (Bridge::BASE_PRESETS as \$pk => [\$pname, \$pal]) echo \$presetTile(\$pk, \$pname, \$pal); ?>",
        'to'    => "<?php foreach ([] as \$pk => [\$pname, \$pal]) echo \$presetTile(\$pk, \$pname, \$pal); ?>",
        'check' => 'tests/fonts_presets.php',
    ],
    [
        'why'   => 'готовая схема снова сбрасывает выбранные шрифты и форму',
        'file'  => 'templates/admin/_look_editor.php',
        'from'  => "'payload' => App\\Core\\LookSchemes::paletteValues(\$pal), 'canDrop' => false]",
        'to'    => "'payload' => null, 'canDrop' => false]",
        'check' => 'tests/fonts_presets.php',
    ],
    [
        'why'   => 'базовая схема снова не сбрасывает тему для новых',
        'file'  => 'app/Core/LookSchemes.php',
        'from'  => "if (array_key_exists('theme_default', \$pal) || \$pal === [])",
        'to'    => "if (array_key_exists('theme_default', \$pal))",
        'check' => 'tests/fonts_presets.php',
    ],
    [
        'why'   => 'бело-чёрная снова встречает светлой темой',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "            'theme_default' => 'dark']],",
        'to'    => "            'theme_default' => 'light']],",
        'check' => 'tests/fonts_presets.php',
    ],
    [
        'why'   => 'тема для новых посетителей снова не принимается',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "        'theme_default'  => ['light', 'dark'],",
        'to'    => "        'theme_default'  => [],",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'тема для новых снова не доходит до страницы',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "return in_array(\$v, ['light', 'dark'], true) ? \$v : null;",
        'to'    => "return null;",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'главная снова без темы для новых',
        'file'  => 'templates/layouts/guest.php',
        'from'  => "<html lang=\"<?= esc(lang()) ?>\"<?= \$themeDefault !== null ? ' data-theme-default=\"' . esc(\$themeDefault) . '\"' : '' ?>>",
        'to'    => "<html lang=\"<?= esc(lang()) ?>\">",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'системные страницы снова берут тему установки без «на весь сайт»',
        'file'  => 'templates/layouts/minimal.php',
        'from'  => "try { if (\\App\\Core\\Appearance::lookEverywhere()) { \$look = \\App\\Core\\Bridge::themeStyle(null); \$themeDefault = \\App\\Core\\Bridge::defaultTheme(null); } }",
        'to'    => "try { \$themeDefault = \\App\\Core\\Bridge::defaultTheme(null); if (\\App\\Core\\Appearance::lookEverywhere()) { \$look = \\App\\Core\\Bridge::themeStyle(null); } }",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'переключатель темы снова забывает тему установки',
        'file'  => 'public/assets/js/app.js',
        'from'  => "var fallback = document.documentElement.getAttribute('data-theme-default') || 'auto';",
        'to'    => "var fallback = 'auto';",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'узор «сетка» снова не встаёт',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "if ((\$b['pattern'] ?? '') === 'grid') {",
        'to'    => "if ((\$b['pattern'] ?? '') === 'gird') {",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'узор снова не ложится на фон главной и входа',
        'file'  => 'public/assets/css/identity.css',
        'from'  => ".guest { position: relative; min-height: 100dvh; display: flex; flex-direction: column; background: var(--bg); background-image: var(--pattern), var(--wash); }",
        'to'    => ".guest { position: relative; min-height: 100dvh; display: flex; flex-direction: column; background: var(--bg); background-image: var(--wash); }",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'стеклянные карточки снова не встают',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "            'glass' => '.bento-card,.auth-card{background:color-mix(in srgb,var(--surface) 72%,transparent);'",
        'to'    => "            'glas' => '.bento-card,.auth-card{background:color-mix(in srgb,var(--surface) 72%,transparent);'",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'пилюли вариантов снова рвут подписи посреди слова',
        'file'  => 'public/assets/css/app.css',
        'from'  => ".choices-seg .choice-title { white-space: nowrap; }\n",
        'to'    => "",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'кнопка «Установить» снова стоит ниже поля шрифта',
        'file'  => 'public/assets/css/app.css',
        'from'  => ".font-add-row { display: flex; gap: 8px; align-items: center; }",
        'to'    => ".font-add-row { display: grid; gap: 8px; align-items: end; }",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'пояснение раздела снова висит абзацем далеко от заголовка',
        'file'  => 'public/assets/css/app.css',
        'from'  => ".page-head + .page-lede { margin-top: -12px; }",
        'to'    => "",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'адрес портала в списке снова рвётся на дефисе',
        'file'  => 'public/assets/css/app.css',
        'from'  => ".checkbox .hint.mono { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; }",
        'to'    => "",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'варианты строками снова разной формы',
        'file'  => 'public/assets/css/app.css',
        'from'  => ".choices-rows .choice-body { gap: 2px; padding: 8px 12px; }",
        'to'    => ".choices-rows .choice-body { flex-direction: row; align-items: baseline; flex-wrap: wrap; gap: 2px 8px; padding: 8px 12px; }",
        'check' => 'tests/look_builder.php',
    ],
    /* v4.81 «Живая обложка». */
    [
        'why'   => 'обложка снова принимает «YouTube» с любого адреса со словом youtube',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "} elseif (\$host === 'youtube.com' || \$host === 'youtube-nocookie.com') {",
        'to'    => "} elseif (str_contains(\$host, 'youtube')) {",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'ссылка YouTube снова проходит с логином и портом в адресе',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "if (isset(\$p['user']) || isset(\$p['pass']) || isset(\$p['port'])) return null;",
        'to'    => '',
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'видео обложки снова узнаётся по имени, а не по байтам',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "if (strlen(\$head) >= 12 && substr(\$head, 4, 4) === 'ftyp') return 'mp4';",
        'to'    => "return 'mp4';",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'картинки обложки снова копятся без счёта',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "if (count(\$set['images']) >= self::MAX_IMAGES) {",
        'to'    => "if (false) {",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'убранная картинка обложки снова остаётся на диске',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "            @unlink(self::path(\$scope, \$id, 'webp'));\n            unset(\$set['images'][\$i]);",
        'to'    => "            unset(\$set['images'][\$i]);",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'набор обложки снова верит любому имени из настройки',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "if (is_string(\$id) && self::validId(\$id) && is_file(self::path(\$scope, \$id, 'webp'))) \$out['images'][] = \$id;",
        'to'    => "if (is_string(\$id)) \$out['images'][] = \$id;",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'прежнее видео обложки снова копится на диске',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "                    if (\$set['video'] !== null) @unlink(self::path(\$scope, \$set['video']['id'], \$set['video']['ext']));\n",
        'to'    => '',
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'экран входа снова грузит все картинки обложки разом',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "\$html .= '<img data-src=\"' . esc(\$u) . '\" alt=\"\">';",
        'to'    => "\$html .= '<img src=\"' . esc(\$u) . '\" alt=\"\">';",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'видео обложки снова качается разметкой, даже где панели нет',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "' data-cover-video=\"' . esc(self::url(",
        'to'    => "' src=\"' . esc(self::url(",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'политика снова пускает YouTube с cookie',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "Csp::allow('frame-src', self::YOUTUBE_ORIGIN);",
        'to'    => "Csp::allow('frame-src', 'https://www.youtube.com');",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'прежняя обложка снова остаётся на диске после переезда',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "        @unlink(\$old);\n",
        'to'    => '',
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'портал на виде установки снова показывает свою обложку',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "if (\$client === null || self::look(\$client)['source'] !== 'custom') return null;",
        'to'    => "if (\$client === null) return null;",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'обложка удалённого портала снова остаётся на диске',
        'file'  => 'app/Models/Client.php',
        'from'  => "        \\App\\Core\\CoverSet::purge(\$clientDbId);\n",
        'to'    => '',
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'файл обложки снова отдаётся по любому имени',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "'~^(site|bg|c\\d{1,9})-([0-9a-f]{12})\\.(webp|mp4|webm)\$~'",
        'to'    => "'~^(.+)-(.+)\\.(webp|mp4|webm)\$~'",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'кусок за пределами видео снова отдаётся вместо 416',
        'file'  => 'app/helpers.php',
        'from'  => "return \$a <= \$b && \$a < \$size ? [\$a, \$b] : false;",
        'to'    => "return [\$a, \$b];",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'видео обложки снова играет у того, кто просил без движения',
        'file'  => 'public/assets/js/app.js',
        'from'  => "        return;\n      }\n      pane.querySelectorAll('[data-cover-slides]')",
        'to'    => "      }\n      pane.querySelectorAll('[data-cover-slides]')",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => '«живые обои» снова плывут у того, кто просил без движения',
        'file'  => 'public/assets/css/identity.css',
        'from'  => "@media (prefers-reduced-motion: reduce) {\n  .cover-slides.is-drift img { animation: none; }\n",
        'to'    => "@media (prefers-reduced-motion: reduce) {\n",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'у портала снова своя форма обложки, не та, что у установки',
        'file'  => 'templates/admin/client/look.php',
        'from'  => "    <?= App\\Core\\View::fetch('admin/_cover_field', ['clientId' => (int)\$c['id']]) ?>",
        'to'    => "    <?= field_image('look_cover', t('Обложка'), '', false) ?>",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'карточка витрины снова показывает кириллический домен в punycode',
        'file'  => 'templates/layouts/guest.php',
        'from'  => "<span class=\"pcard-host\"><?= esc(host_label((string)\$p['base_url'])) ?></span>",
        'to'    => "<span class=\"pcard-host\"><?= esc((string)parse_url(\$p['base_url'], PHP_URL_HOST)) ?></span>",
        'check' => 'tests/portal_cover.php',
    ],
    [
        'why'   => 'длинный адрес снова ужимает название портала до «К…»',
        'file'  => 'public/assets/css/identity.css',
        'from'  => "white-space: nowrap; flex: 0 10 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; }",
        'to'    => "white-space: nowrap; }",
        'check' => 'tests/portal_cover.php',
    ],
    /* v4.82: оформление портала поверх оформления сайта. */
    [
        'why'   => 'вход портала из манифеста снова не слушает оформление сайта',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "return ['source' => 'portal', 'theme' => \$policy === 'site' ? self::siteFirst(\$t) : self::overSite(\$t)];",
        'to'    => "return ['source' => 'portal', 'theme' => \$t];",
        'check' => 'tests/look_builder.php',
    ],
    /* v4.85: чей дизайн главнее на входе в порталы. */
    [
        'why'   => 'дизайн сайта снова не главнее темы из манифеста портала',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "\$policy === 'site' ? self::siteFirst(\$t) : self::overSite(\$t)",
        'to'    => "self::overSite(\$t)",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => '«Только дизайн сайта» снова пропускает темы порталов',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "if (\$t !== [] && \$policy !== 'only') {",
        'to'    => "if (\$t !== []) {",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'при «Дизайн сайта главнее» портал снова теряет свои цвета',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "            if (!\$own(\$k)) \$out[\$k] = \$v;",
        'to'    => "            \$out[\$k] = \$v;",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'при «Дизайн сайта главнее» портал снова ставит то, чего сайт не задал',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "\$out = array_filter(self::overSite(\$portal), \$own, ARRAY_FILTER_USE_KEY);",
        'to'    => "\$out = self::overSite(\$portal);",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'при «Дизайн сайта главнее» цитата портала снова пропадает',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "            || in_array(\$k, ['headline', 'headline_note', 'tagline'], true);",
        'to'    => ";",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'выбор «Вход в порталы» снова принимает что угодно',
        'file'  => 'app/Core/Appearance.php',
        'from'  => "return in_array(\$v, self::PORTAL_LOOKS, true) ? \$v : 'site';",
        'to'    => "return \$v;",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'форма оформления снова сохраняет в «Вход в порталы» что прислали',
        'file'  => 'app/Controllers/Admin/AppearanceController.php',
        'from'  => "if (in_array(\$_POST['portal_look'] ?? '', Appearance::PORTAL_LOOKS, true)) {",
        'to'    => "if (isset(\$_POST['portal_look'])) {",
        'check' => 'tests/login_look.php',
    ],
    [
        'why'   => 'выбор «Вход в порталы» снова не сохраняется',
        'file'  => 'app/Controllers/Admin/AppearanceController.php',
        'from'  => "Setting::set('appearance.portal_look', (string)\$_POST['portal_look']);",
        'to'    => "",
        'check' => 'tests/login_look.php',
    ],
    [
        'why'   => 'своё оформление портала снова не слушает оформление сайта',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "return ['source' => 'custom', 'theme' => self::overSite(self::sanitize(\$raw, 'portal'))];",
        'to'    => "return ['source' => 'custom', 'theme' => self::sanitize(\$raw, 'portal')];",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'палитра портала снова собирается из двух — его и сайта',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "            if (array_intersect_key(\$portal, array_flip(\$group)) !== []) continue;\n",
        'to'    => '',
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'тёмный акцент портала снова берётся у сайта',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "if (!isset(\$portal['accent_dark']) && !isset(\$portal['accent']) && isset(\$site['accent_dark'])) {",
        'to'    => "if (!isset(\$portal['accent_dark']) && isset(\$site['accent_dark'])) {",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'оформление сайта снова перебивает заданное порталом',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "            if (!array_key_exists(\$k, \$out)) \$out[\$k] = \$v;\n",
        'to'    => "            \$out[\$k] = \$v;\n",
        'check' => 'tests/look_builder.php',
    ],
    [
        'why'   => 'редактор портала снова подсказывает родной цвет вместо цвета сайта',
        'file'  => 'templates/admin/_look_editor.php',
        'from'  => "    \$native = \$siteLook[\$name] ?? (\$key === 'accent' ? (\$dark ? '#60A5FA' : \$siteAccent) : Bridge::NATIVE[\$name]);",
        'to'    => "    \$native = \$key === 'accent' ? (\$dark ? '#60A5FA' : \$siteAccent) : Bridge::NATIVE[\$name];",
        'check' => 'tests/look_builder.php',
    ],
    /* v4.83: контрольный прогон 26.09. */
    [
        'why'   => 'экран входа по ключу возврата снова безымянный',
        'file'  => 'app/Core/LoginFlow.php',
        'from'  => "        \$q = \$rt !== '' ? Resume::peek(\$rt) : null;\n",
        'to'    => "        \$q = null;\n",
        'check' => 'tests/ui_fixes.php',
    ],
    [
        'why'   => 'действие системы в журнале портала снова висячим тире',
        'file'  => 'app/helpers.php',
        'from'  => "esc((\$r['actor'] ?? '') !== '' ? (string)\$r['actor'] : t('система'))",
        'to'    => "esc(\$r['actor'] ?? '—')",
        'check' => 'tests/ui_fixes.php',
    ],
    [
        'why'   => 'ссылка в предупреждении «Документов» снова выходит HTML-кодом',
        'file'  => 'templates/admin/settings/docs.php',
        'from'  => "esc(t('Заполнить →')) . '</a>', 'warning', true) ?>",
        'to'    => "esc(t('Заполнить →')) . '</a>', 'warning') ?>",
        'check' => 'tests/templates_test.php',
    ],
    [
        'why'   => 'лишний </div> снова выбрасывает полосу «Сохранить» из колонки раздела',
        'file'  => 'templates/admin/settings/signup.php',
        'from'  => "    </div>\n    <?= form_actions() ?>\n  </form>",
        'to'    => "    </div>\n      </div>\n    <?= form_actions() ?>\n  </form>",
        'check' => 'tests/templates_test.php',
    ],
    [
        'why'   => 'перевод строки после ?> снова съедает пробел перед ссылкой',
        'file'  => 'templates/admin/logs_mail.php',
        'from'  => "\n  <a href=\"/admin/health\"><?= esc(t('«Здоровье»')) ?></a>.</div>",
        'to'    => "\n<a href=\"/admin/health\"><?= esc(t('«Здоровье»')) ?></a>.</div>",
        'check' => 'tests/templates_test.php',
    ],
    [
        'why'   => 'проверка разметки снова не видит кнопки, собранные в классах ядра',
        'file'  => 'tests/templates_test.php',
        'from'  => "foreach ((array)glob(\$root . '/app/Core/*.php') as \$coreFile) \$phpMarkup .= (string)file_get_contents((string)\$coreFile);\n",
        'to'    => '',
        'check' => 'tests/templates_test.php',
    ],
    [
        'why'   => '«Опасная зона» человека снова без колонки разделов',
        'file'  => 'app/Controllers/Admin/UsersController.php',
        'from'  => "        \$this->personPage(\$user, 'danger', [",
        'to'    => "        View::render('admin/user/danger', ['u' => \$user, 'pageTitle' => '',",
        'check' => 'tests/ui_fixes.php',
    ],
    [
        'why'   => 'шапка карточки на телефоне снова раздувает страницу шире экрана',
        'file'  => 'public/assets/css/app.css',
        'from'  => ".set-rail.has-head { grid-auto-flow: row; grid-auto-columns: minmax(0, 1fr); overflow: visible;",
        'to'    => ".set-rail.has-head { grid-auto-flow: row; overflow: visible;",
        'check' => 'tests/ui_fixes.php',
    ],
    [
        'why'   => 'значки предпросмотра на телефоне снова встают башенками',
        'file'  => 'public/assets/css/app.css',
        'from'  => "  .segmented.look-seg { grid-auto-flow: column; }\n",
        'to'    => '',
        'check' => 'tests/ui_fixes.php',
    ],
    [
        'why'   => 'YouTube на обложке снова плейлистом из одного ролика — с кнопками «назад · вперёд»',
        'file'  => 'public/assets/js/app.js',
        'from'  => "if (state === 0) { box.classList.remove('is-playing'); cmd('seekTo', [0, true]); cmd('playVideo'); return; }",
        'to'    => "if (state === 0) { return; }",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'заставка YouTube снова уходит раньше, чем плеер отыграл подписи',
        'file'  => 'public/assets/js/app.js',
        'from'  => "box.classList.toggle('is-playing', info.currentTime >= 3);",
        'to'    => "box.classList.toggle('is-playing', info.currentTime >= 0);",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'обложка снова слушает сообщения от любого окна',
        'file'  => 'public/assets/js/app.js',
        'from'  => "if (e.origin !== yt || e.source !== f.contentWindow) return;",
        'to'    => "if (e.origin !== yt) return;",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'заставка YouTube снова под плеером, а не поверх',
        'file'  => 'public/assets/css/identity.css',
        'from'  => ".cover-yt > img, .cover-yt > .cover-veil { position: absolute; inset: 0; z-index: 1;",
        'to'    => ".cover-yt > img, .cover-yt > .cover-veil { position: absolute; inset: 0;",
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'кнопка звука снова не выключается',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "        if (isset(\$post[\$k('sound')])) \$set['sound'] = (string)\$post[\$k('sound')] === '1';\n",
        'to'    => '',
        'check' => 'tests/cover_set.php',
    ],
    [
        'why'   => 'кнопка звука снова ничего не включает у видео файлом',
        'file'  => 'public/assets/js/app.js',
        'from'  => "soundReady(function (on) { v.muted = !on; if (on) { v.volume = 0.7; v.play(); } });",
        'to'    => "soundReady(function (on) { });",
        'check' => 'tests/cover_set.php',
    ],
    /* v4.84: фон страниц. */
    [
        'why'   => 'фон снова принимает любую область из формы',
        'file'  => 'app/Core/Backdrop.php',
        'from'  => "            'areas' => array_values(array_intersect(self::AREAS, array_map('strval', (array)(\$post['backdrop_areas'] ?? [])))),",
        'to'    => "            'areas' => array_map('strval', (array)(\$post['backdrop_areas'] ?? [])),",
        'check' => 'tests/backdrop.php',
    ],
    [
        'why'   => 'фон снова показывается там, где не отмечен',
        'file'  => 'app/Core/Backdrop.php',
        'from'  => "        if (!in_array(\$area, \$s['areas'], true)) return '';\n",
        'to'    => '',
        'check' => 'tests/backdrop.php',
    ],
    [
        'why'   => 'у видео на фоне снова кнопка звука',
        'file'  => 'app/Core/Backdrop.php',
        'from'  => "CoverSet::frame(CoverSet::BACKDROP, false)",
        'to'    => "CoverSet::frame(CoverSet::BACKDROP)",
        'check' => 'tests/backdrop.php',
    ],
    [
        'why'   => 'видео фона снова грузится в окне входа на странице портала',
        'file'  => 'public/assets/js/app.js',
        'from'  => "liveCover(bd, function () { return wideNow.matches && !saveData && !framed; });",
        'to'    => "liveCover(bd, function () { return wideNow.matches && !saveData; });",
        'check' => 'tests/backdrop.php',
    ],
    [
        'why'   => 'видео на фоне снова качается и на телефоне',
        'file'  => 'public/assets/js/app.js',
        'from'  => "liveCover(bd, function () { return wideNow.matches && !saveData && !framed; });",
        'to'    => "liveCover(bd, function () { return !framed; });",
        'check' => 'tests/backdrop.php',
    ],
    [
        'why'   => '«живой фон» снова двигается у того, кто просил без движения',
        'file'  => 'public/assets/js/app.js',
        'from'  => "if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;",
        'to'    => "if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;",
        'check' => 'tests/backdrop.php',
    ],
    [
        'why'   => 'вуаль фона снова не приглушает кадр',
        'file'  => 'public/assets/css/identity.css',
        'from'  => ".page-backdrop::after { content: ''; position: absolute; inset: 0; background: var(--bg); opacity: var(--pb-veil); }",
        'to'    => ".page-backdrop::after { content: ''; position: absolute; inset: 0; }",
        'check' => 'tests/backdrop.php',
    ],
    [
        'why'   => 'частичное сохранение оформления снова стирает фон',
        'file'  => 'app/Controllers/Admin/AppearanceController.php',
        'from'  => "            if (isset(\$_POST['backdrop_veil'])) {",
        'to'    => "            if (true) {",
        'check' => 'tests/backdrop.php',
    ],
    [
        'why'   => 'поля фона снова читаются как поля обложки',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "\$k = static fn(string \$name): string => \$prefix . '_' . \$name;",
        'to'    => "\$k = static fn(string \$name): string => 'cover_' . \$name;",
        'check' => 'tests/backdrop.php',
    ],
    [
        'why'   => 'набор фона снова смешивается с обложкой портала',
        'file'  => 'app/Core/CoverSet.php',
        'from'  => "        if (\$owner === self::BACKDROP) return 'bg';\n",
        'to'    => '',
        'check' => 'tests/backdrop.php',
    ],
    [
        'why'   => 'второй редактор на странице снова переключает блоки первого',
        'file'  => 'public/assets/js/app.js',
        'from'  => "var kindName = (box.getAttribute('data-cover-edit') || 'cover') + '_kind';",
        'to'    => "var kindName = 'cover_kind';",
        'check' => 'tests/backdrop.php',
    ],
    [
        'why'   => 'панель входа снова тянется на всю страницу, и кнопка звука уходит за экран',
        'file'  => 'public/assets/css/identity.css',
        'from'  => "    display: block; position: sticky; top: 0; align-self: start; height: 100vh; height: 100dvh;\n",
        'to'    => "    display: block; position: relative;\n",
        'check' => 'tests/backdrop.php',
    ],
    [
        'why'   => 'полоса «Сохранить» снова лента во всю ширину',
        'file'  => 'public/assets/css/app.css',
        'from'  => "  width: fit-content; max-width: calc(100% + 18px);\n",
        'to'    => "",
        'check' => 'tests/ui_fixes.php',
    ],
    [
        'why'   => 'полоса «Сохранить» снова с подложкой и на своём месте',
        'file'  => 'public/assets/css/app.css',
        'from'  => ".form-actions.is-rest {\n  background: transparent; border-color: transparent; box-shadow: none;",
        'to'    => ".form-actions.is-rest {\n  border-color: transparent;",
        'check' => 'tests/ui_fixes.php',
    ],
    [
        'why'   => 'пилюля снова не отличает «прилипла» от «стоит на месте»',
        'file'  => 'public/assets/js/app.js',
        'from'  => "mark(e.intersectionRatio < 1 && e.boundingClientRect.bottom >= edge() - off - 1.5);",
        'to'    => "mark(true);",
        'check' => 'tests/ui_fixes.php',
    ],
    [
        'why'   => 'плитка пользователей на дашборде снова кончается пустым полем',
        'file'  => 'templates/admin/dashboard.php',
        'from'  => "    <div class=\"sparkline\"><?= DashboardController::sparkSvg(\$regSpark, 120, 36, t('Регистрации за период')) ?></div></div>",
        'to'    => "    </div>",
        'check' => 'tests/ui_fixes.php',
    ],
    [
        'why'   => 'кривая входов снова считает не те события, что число над ней',
        'file'  => 'app/Controllers/Admin/DashboardController.php',
        'from'  => "\$loginSpark = array_values(Stats::series('audit_log', \$login[0], \$login[1], \$days));",
        'to'    => "\$loginSpark = array_values(Stats::series('audit_log', 'event = ?', ['login.success'], \$days));",
        'check' => 'tests/ui_fixes.php',
    ],
    /* v4.86: кнопки сервисов входа — знак, «Продолжить с …», idp_hint, порядок. */
    [
        'why'   => 'кнопка сервиса снова со значком «наружу» вместо знака сервиса',
        'file'  => 'app/Social/Provider.php',
        'from'  => "return 'brand-' . \$this->id();",
        'to'    => "return 'external';",
        'check' => 'tests/social_buttons.php',
    ],
    [
        'why'   => 'на кнопке сервиса снова одно имя вместо «Продолжить с …»',
        'file'  => 'templates/auth/login.php',
        'from'  => "<span class=\"oauth-long\"><?= esc(t('Продолжить с :name', ['name' => \$p->title()])) ?></span>",
        'to'    => "<span class=\"oauth-long\"><?= esc(\$p->title()) ?></span>",
        'check' => 'tests/social_buttons.php',
    ],
    [
        'why'   => 'в два столбца снова полная подпись, которая не влезает',
        'file'  => 'public/assets/css/identity.css',
        'from'  => "  .oauth-row-2 .oauth-long { display: none; }\n",
        'to'    => "",
        'check' => 'tests/social_buttons.php',
    ],
    [
        'why'   => 'idp_hint снова не ведёт сразу на сервис',
        'file'  => 'app/Oidc/AuthorizeFlow.php',
        'from'  => "                redirect('/social/' . \$hinted->id() . '/start');",
        'to'    => "",
        'check' => 'tests/social_buttons.php',
    ],
    [
        'why'   => 'idp_hint снова ведёт на сервис и во фрейме, где страницы сервисов запрещены',
        'file'  => 'app/Oidc/AuthorizeFlow.php',
        'from'  => "if (\$hinted !== null && (\$q['display'] ?? '') !== Framed::DISPLAY\n",
        'to'    => "if (\$hinted !== null\n",
        'check' => 'tests/social_buttons.php',
    ],
    [
        'why'   => 'idp_hint снова даёт сервис порталу, которому сервисы не показаны',
        'file'  => 'app/Oidc/AuthorizeFlow.php',
        'from'  => "                && \\App\\Core\\Bridge::methodShown(\$client, 'social')) {",
        'to'    => "                ) {",
        'check' => 'tests/social_buttons.php',
    ],
    [
        'why'   => 'idp_hint снова включает выключенный сервис',
        'file'  => 'app/Social/Provider.php',
        'from'  => "        foreach (self::enabled() as \$p) {\n            if (\$p->id() === \$id) return \$p;",
        'to'    => "        foreach (self::all() as \$p) {\n            if (\$p->id() === \$id) return \$p;",
        'check' => 'tests/social_buttons.php',
    ],
    [
        'why'   => 'подсказка сервиса снова остаётся в сохранённом запросе и срабатывает после входа',
        'file'  => 'app/Oidc/AuthorizeFlow.php',
        'from'  => "            unset(\$stored['idp_hint']); // и подсказка сервиса: после входа она не нужна\n",
        'to'    => "",
        'check' => 'tests/social_buttons.php',
    ],
    [
        'why'   => 'фрейм снова не говорит, какой сервис выбран, — в окне выбирать заново',
        'file'  => 'public/assets/js/app.js',
        'from'  => "toPortal({ state: 'window', social: out.getAttribute('data-social') || '' });",
        'to'    => "toPortal({ state: 'window' });",
        'check' => 'tests/social_buttons.php',
    ],
    [
        'why'   => 'сервис из фрейма снова открывает отдельное окно, а не ведёт вкладку на сервис',
        'file'  => 'public/assets/js/sso.js',
        'from'  => "if (typeof d.social === 'string' && /^[a-z]{2,20}\$/.test(d.social)) toService(d.social);\n      else toWindow();",
        'to'    => "toWindow();",
        'check' => 'tests/login_window.php',
    ],
    [
        'why'   => 'в адрес вкладки снова пишется что угодно из сообщения фрейма',
        'file'  => 'public/assets/js/sso.js',
        'from'  => "if (typeof d.social === 'string' && /^[a-z]{2,20}\$/.test(d.social)) toService(d.social);",
        'to'    => "if (typeof d.social === 'string') toService(d.social);",
        'check' => 'tests/login_window.php',
    ],
    [
        'why'   => '«Сервисы — первыми» снова не поднимает кнопки над формой',
        'file'  => 'templates/auth/login.php',
        'from'  => "\$socialFirst = \$social !== [] && App\\Core\\Bridge::socialFirst(\$client ?? null);",
        'to'    => "\$socialFirst = false;",
        'check' => 'tests/social_buttons.php',
    ],
    [
        'why'   => 'предпросмотр снова не показывает порядок сервисов из формы',
        'file'  => 'app/Core/Bridge.php',
        'from'  => "        if (\$client === null && self::drafted(null) && self::\$draft['social_first'] !== null) return self::\$draft['social_first'];\n",
        'to'    => "",
        'check' => 'tests/social_buttons.php',
    ],
    [
        'why'   => '«Сервисы — первыми» снова не сохраняется',
        'file'  => 'app/Controllers/Admin/AppearanceController.php',
        'from'  => "            Setting::set('appearance.social_first', !empty(\$_POST['social_first']) ? '1' : '0');\n",
        'to'    => "",
        'check' => 'tests/login_look.php',
    ],
    /* v4.87: окно входа без лишних окон — Telegram переходом, вкладка вместо окна, раскладка. */
    [
        'why'   => 'Telegram снова открывается страницей с виджетом, которая открывает ещё окно',
        'file'  => 'app/Controllers/SocialController.php',
        'from'  => "if (\$p instanceof TelegramProvider && \$p->botId() === '') {",
        'to'    => "if (\$p instanceof TelegramProvider) {",
        'check' => 'tests/login_window.php',
    ],
    [
        'why'   => 'возврат из Telegram снова без state — чужую подписанную ссылку можно подсунуть',
        'file'  => 'app/Controllers/SocialController.php',
        'from'  => "if (!\$p instanceof TelegramProvider || \$p->botId() !== '') {",
        'to'    => "if (!\$p instanceof TelegramProvider) {",
        'check' => 'tests/login_window.php',
    ],
    [
        'why'   => 'возврат из Telegram снова ведёт не туда, где его ждут',
        'file'  => 'app/Social/TelegramProvider.php',
        'from'  => "'return_to' => base_url('/social/telegram/return?state=' . rawurlencode(\$state)),",
        'to'    => "'return_to' => base_url('/social/telegram/callback'),",
        'check' => 'tests/login_window.php',
    ],
    [
        'why'   => 'номер бота снова берётся из чего попало',
        'file'  => 'app/Social/TelegramProvider.php',
        'from'  => "return preg_match('~^(\\d{3,20}):[\\w-]{20,}\$~', trim(\$this->clientSecret()), \$m) ? \$m[1] : '';",
        'to'    => "return preg_match('~^(\\d+)~', trim(\$this->clientSecret()), \$m) ? \$m[1] : '';",
        'check' => 'tests/login_window.php',
    ],
    [
        'why'   => 'страница возврата из Telegram снова пустая без данных',
        'file'  => 'templates/auth/telegram_return.php',
        'from'  => "<p class=\"hint text-center\" data-tg-fail hidden>",
        'to'    => "<p class=\"hint text-center\" hidden>",
        'check' => 'tests/login_window.php',
    ],
    [
        'why'   => 'возврат из Telegram снова теряет state по дороге к обработчику',
        'file'  => 'public/assets/js/app.js',
        'from'  => "      tgQuery.set('state', new URLSearchParams(location.search).get('state') || '');\n",
        'to'    => "",
        'check' => 'tests/login_window.php',
    ],
    [
        'why'   => 'имена по-русски из Telegram снова ломаются: байты читаются как латиница',
        'file'  => 'public/assets/js/app.js',
        'from'  => "tgData = JSON.parse(new TextDecoder().decode(tgBytes));",
        'to'    => "tgData = JSON.parse(tgBin);",
        'check' => 'tests/login_window.php',
    ],
    [
        'why'   => 'в окне входа снова «● Название» вместо знака',
        'file'  => 'templates/layouts/auth.php',
        'from'  => "    <main id=\"main\" class=\"auth-card\">\n      <?php if (empty(\$client)): ?>\n      <div class=\"auth-brandline\"><?= brand_mark() ?>",
        'to'    => "    <main id=\"main\" class=\"auth-card\">\n      <?php if (empty(\$client)): ?>\n      <div class=\"auth-brandline\"><?= branding_logo_html() ?>",
        'check' => 'tests/login_window.php',
    ],
    [
        'why'   => 'в окне входа снова карточка в карточке',
        'file'  => 'public/assets/css/identity.css',
        'from'  => ".auth-shell.is-popup > .auth-card { background: transparent; border: 0; box-shadow: none; padding: 4px; }\n",
        'to'    => "",
        'check' => 'tests/login_window.php',
    ],
    [
        'why'   => 'окно поверх сайта снова только растёт — высота по странице, а не по содержимому',
        'file'  => 'public/assets/js/app.js',
        'from'  => "    toPortal({ state: 'size', h: w ? Math.ceil(w.getBoundingClientRect().height)\n      : document.documentElement.scrollHeight });",
        'to'    => "    toPortal({ state: 'size', h: document.documentElement.scrollHeight });",
        'check' => 'tests/login_window.php',
    ],
    [
        'why'   => 'фон страниц снова не доходит до фрейма и окна входа',
        'file'  => 'templates/layouts/auth.php',
        'from'  => "\$backdrop = App\\Core\\Backdrop::layer('auth');",
        'to'    => "\$backdrop = \$popup ? '' : App\\Core\\Backdrop::layer('auth');",
        'check' => 'tests/backdrop.php',
    ],
    /* v4.88: дубли — подсказка, «Войти и привязать», объединение в админке; две строки; знак слева. */
    [
        'why'   => 'похожий аккаунт снова не находится по номеру телефона',
        'file'  => 'app/Core/DuplicateHint.php',
        'from'  => "if (\$u && User::canSignIn(\$u)) return ['user' => \$u, 'why' => 'phone'];",
        'to'    => "",
        'check' => 'tests/duplicates.php',
    ],
    [
        'why'   => '«Пётр» и «ПЁТР» снова разные люди',
        'file'  => 'app/Core/DuplicateHint.php',
        'from'  => "        \$s = mb_strtolower(trim((string)preg_replace('~\\s+~u', ' ', \$s)));\n        return strtr(\$s, ['ё' => 'е']);",
        'to'    => "        return trim(\$s);",
        'check' => 'tests/duplicates.php',
    ],
    [
        'why'   => 'в заблокированный аккаунт снова зовут войти',
        'file'  => 'app/Core/DuplicateHint.php',
        'from'  => "if (User::canSignIn(\$u) && self::norm((string)\$u['first_name']) === \$first",
        'to'    => "if (self::norm((string)\$u['first_name']) === \$first",
        'check' => 'tests/duplicates.php',
    ],
    [
        'why'   => 'найденный аккаунт снова называется полной почтой',
        'file'  => 'app/Core/DuplicateHint.php',
        'from'  => "return mb_substr(\$local, 0, 1) . '•••@' . \$domain;",
        'to'    => "return \$email;",
        'check' => 'tests/duplicates.php',
    ],
    [
        'why'   => 'при похожем аккаунте главным снова остаётся «Создать новый»',
        'file'  => 'templates/auth/signup.php',
        'from'  => "<?= empty(\$dup) ? 'btn-primary' : 'btn-secondary' ?>",
        'to'    => "btn-primary",
        'check' => 'tests/duplicates.php',
    ],
    [
        'why'   => 'после «Войти и привязать» сервис снова не привязывается',
        'file'  => 'app/Core/LoginFlow.php',
        'from'  => "        PendingLink::applyTo(\$user);\n",
        'to'    => "",
        'check' => 'tests/duplicates.php',
    ],
    [
        'why'   => '«Войти и привязать» снова теряет данные сервиса по дороге ко входу',
        'file'  => 'app/Controllers/SocialController.php',
        'from'  => "        \\App\\Core\\PendingLink::stash(\$pending);\n",
        'to'    => "",
        'check' => 'tests/duplicates.php',
    ],
    [
        'why'   => 'отложенная привязка снова перебивает связку с другим аккаунтом',
        'file'  => 'app/Core/PendingLink.php',
        'from'  => "        if (\$taken) {",
        'to'    => "        if (false) {",
        'check' => 'tests/duplicates.php',
    ],
    [
        'why'   => 'отложенная привязка снова живёт вечно',
        'file'  => 'app/Core/PendingLink.php',
        'from'  => "if (!is_array(\$p) || (int)(\$p['t'] ?? 0) < time() - self::TTL) {",
        'to'    => "if (!is_array(\$p)) {",
        'check' => 'tests/duplicates.php',
    ],
    [
        'why'   => 'объединение в админке снова без пароля',
        'file'  => 'app/Controllers/Admin/UsersController.php',
        'from'  => "        if (!\\App\\Core\\DangerZone::unlocked('user:' . (int)\$user['id'])) {\n            Flash::error(t('Сначала подтвердите пароль — слияние необратимо.'));",
        'to'    => "        if (false) {\n            Flash::error(t('Сначала подтвердите пароль — слияние необратимо.'));",
        'check' => 'tests/duplicates.php',
    ],
    [
        'why'   => '«Оставить второй» снова вливает не туда',
        'file'  => 'app/Controllers/Admin/UsersController.php',
        'from'  => "[\$main, \$dup] = (\$_POST['keep'] ?? '') === 'other' ? [\$other, \$user] : [\$user, \$other];",
        'to'    => "[\$main, \$dup] = [\$user, \$other];",
        'check' => 'tests/duplicates.php',
    ],
    [
        'why'   => 'объединение из админки снова записано от имени самого человека',
        'file'  => 'app/Controllers/Admin/UsersController.php',
        'from'  => "\\App\\Core\\AccountMerge::into((int)\$main['id'], (int)\$dup['id'], (int)Auth::id());",
        'to'    => "\\App\\Core\\AccountMerge::into((int)\$main['id'], (int)\$dup['id']);",
        'check' => 'tests/duplicates.php',
    ],
    [
        'why'   => 'пароль опасной зоны снова уводит с объединения в удаление',
        'file'  => 'app/Controllers/Admin/UsersController.php',
        'from'  => "        if ((\$_POST['back'] ?? '') === 'merge') {",
        'to'    => "        if (false) {",
        'check' => 'tests/duplicates.php',
    ],
    [
        'why'   => 'возможные дубли снова не показаны в карточке',
        'file'  => 'app/Controllers/Admin/UsersController.php',
        'from'  => "'hints' => \\App\\Core\\DuplicateHint::forUser(\$user),",
        'to'    => "'hints' => [],",
        'check' => 'tests/duplicates.php',
    ],
    [
        'why'   => 'две мысли в пояснении снова слиплись в одну строку',
        'file'  => 'app/helpers.php',
        'from'  => "'<p>' . nl2br(esc(\$sub), false) . '</p>'",
        'to'    => "'<p>' . esc(\$sub) . '</p>'",
        'check' => 'tests/duplicates.php',
    ],
    [
        'why'   => 'знак в окне входа снова по центру, а над основной формой — слева',
        'file'  => 'public/assets/css/identity.css',
        'from'  => ".auth-shell.is-popup .auth-brandline { margin-bottom: var(--s-6); }",
        'to'    => ".auth-shell.is-popup .auth-brandline { justify-content: center; margin-bottom: var(--s-6); }",
        'check' => 'tests/login_window.php',
    ],
    /* v4.90: списки порталов и организаций, время и статусы словами. */
    [
        'why'   => 'сутки назад снова «1 дн назад», а не «вчера»',
        'file'  => 'app/helpers.php',
        'from'  => "    if (\$diff < 86400 * 2) return t('вчера');\n",
        'to'    => "",
        'check' => 'tests/admin_lists.php',
    ],
    [
        'why'   => 'дни снова обрубком «дн»',
        'file'  => 'app/helpers.php',
        'from'  => "return t(':n :days назад', ['n' => \$d, 'days' => plural(\$d, 'день', 'дня', 'дней')]);",
        'to'    => "return t(':n дн назад', ['n' => \$d]);",
        'check' => 'tests/admin_lists.php',
    ],
    [
        'why'   => 'статус доступа снова голым «active»',
        'file'  => 'app/helpers.php',
        'from'  => "        'active' => ['badge-ok', t('открыт')],",
        'to'    => "        'active' => ['badge-ok', 'active'],",
        'check' => 'tests/admin_lists.php',
    ],
    [
        'why'   => 'статус письма снова голым «sent»',
        'file'  => 'app/helpers.php',
        'from'  => "        'sent' => ['badge-ok', t('отправлено')],",
        'to'    => "        'sent' => ['badge-ok', 'sent'],",
        'check' => 'tests/admin_lists.php',
    ],
    [
        'why'   => 'знак организации снова одной буквой',
        'file'  => 'app/helpers.php',
        'from'  => "    \$letters = count(\$words) >= 2 ? mb_substr(\$words[0], 0, 1) . mb_substr(\$words[1], 0, 1) : mb_substr(\$name, 0, 2);\n    \$style",
        'to'    => "    \$letters = mb_substr(\$name, 0, 1);\n    \$style",
        'check' => 'tests/admin_lists.php',
    ],
    [
        'why'   => 'в карточке организации снова не тот знак, что в списке',
        'file'  => 'app/Core/OrgMap.php',
        'from'  => "        return org_icon_html(\$object, 40);",
        'to'    => "        return '<span class=\"rail-letters\">' . esc(mb_substr((string)(\$object['name'] ?? ''), 0, 2)) . '</span>';",
        'check' => 'tests/admin_lists.php',
    ],
    [
        'why'   => 'плитка портала снова не называет владельца',
        'file'  => 'templates/admin/clients.php',
        'from'  => "<?= !empty(\$c['org_name']) ? ' · ' . esc(\$c['org_name']) : '' ?>",
        'to'    => "",
        'check' => 'tests/admin_lists.php',
    ],
    [
        'why'   => 'пустой портал снова «0 пользователей»',
        'file'  => 'templates/admin/clients.php',
        'from'  => "          : esc(t('пока никого')) ?></span>",
        'to'    => "          : '0 ' . esc(plural(0, 'пользователь', 'пользователя', 'пользователей')) ?></span>",
        'check' => 'tests/admin_lists.php',
    ],
    [
        'why'   => 'застрявшие события портала снова не видны в списке',
        'file'  => 'templates/admin/clients.php',
        'from'  => "<?php if ((int)\$c['events_stuck'] > 0): ?>",
        'to'    => "<?php if (false): ?>",
        'check' => 'tests/admin_lists.php',
    ],
    [
        'why'   => 'список организаций снова не знает, сколько у них сервисов',
        'file'  => 'app/Models/Org.php',
        'from'  => "            ? ', (SELECT COUNT(*) FROM {clients} c WHERE c.org_id = o.id) AS projects' : ', 0 AS projects';",
        'to'    => "            ? ', 0 AS projects' : ', 0 AS projects';",
        'check' => 'tests/admin_lists.php',
    ],
    /* v4.91: обзоры портала и организации. */
    [
        'why'   => 'карточка портала снова открывается длинной формой реквизитов',
        'file'  => 'app/Controllers/Admin/ClientsController.php',
        'from'  => "        \$this->clientPage(\$client, 'overview', [",
        'to'    => "        \$this->clientPage(\$client, 'req', ['orgs' => [], 'allOrgs' => [],",
        'check' => 'tests/admin_cards.php',
    ],
    [
        'why'   => 'после сохранения реквизитов снова выбрасывает в обзор',
        'file'  => 'app/Controllers/Admin/ClientsController.php',
        'from'  => "            Flash::success('Портал обновлён.');\n        }\n        redirect('/admin/clients/' . \$client['id'] . '/req');",
        'to'    => "            Flash::success('Портал обновлён.');\n        }\n        redirect('/admin/clients/' . \$client['id']);",
        'check' => 'tests/admin_cards.php',
    ],
    [
        'why'   => 'выключить организацию из обзора снова нельзя',
        'file'  => 'app/Controllers/Admin/OrgsController.php',
        'from'  => "                if (isset(\$_POST['active_present'])) {",
        'to'    => "                if (false) {",
        'check' => 'tests/admin_cards.php',
    ],
    /* v4.92: экраны людей. */
    [
        'why'   => '«Отозвать» снова снимает роль со всех сервисов разом',
        'file'  => 'app/Controllers/Admin/UsersController.php',
        'from'  => "                Role::revokeScoped((int)\$user['id'], (int)\$role['id'], \$scope);",
        'to'    => "                Role::revoke((int)\$user['id'], (int)\$role['id']);",
        'check' => 'tests/admin_people.php',
    ],
    [
        'why'   => 'форма строки роли не шлёт свою область',
        'file'  => 'templates/admin/user/roles.php',
        'from'  => '<input type="hidden" name="scope_type"',
        'to'    => '<input type="hidden" name="scope_kind"',
        'check' => 'tests/admin_people.php',
    ],
    [
        'why'   => 'выдача на удалённый сервис снова «сервис #N»',
        'file'  => 'templates/admin/user/roles.php',
        'from'  => "        ? ['label' => t('удалённый сервис #:id', ['id' => \$id]), 'href' => null, 'gone' => true]",
        'to'    => "        ? ['label' => t('сервис :name', ['name' => '#' . \$id]), 'href' => null, 'gone' => false]",
        'check' => 'tests/admin_people.php',
    ],
    [
        'why'   => 'один браузер с разных адресов складывается в одну строку',
        'file'  => 'app/Models/SessionModel.php',
        'from'  => "            \$key = (string)(\$s['device_label'] ?? '') . '|' . bin2hex((string)(\$s['ip'] ?? ''));",
        'to'    => "            \$key = (string)(\$s['device_label'] ?? '');",
        'check' => 'tests/admin_people.php',
    ],
    [
        'why'   => 'текущее устройство теряется среди остальных',
        'file'  => 'app/Models/SessionModel.php',
        'from'  => "=> [\$b['current'], \$b['last_seen_at']] <=> [\$a['current'], \$a['last_seen_at']]);",
        'to'    => "=> \$b['last_seen_at'] <=> \$a['last_seen_at']);",
        'check' => 'tests/admin_people.php',
    ],
    [
        'why'   => 'строкой списка можно закрыть свою текущую сессию',
        'file'  => 'app/Models/SessionModel.php',
        'from'  => "            if (!isset(\$want[\$sid]) || \$sid === \$exceptId) continue;",
        'to'    => "            if (!isset(\$want[\$sid])) continue;",
        'check' => 'tests/admin_people.php',
    ],
    [
        'why'   => 'админ со своей карточки закрывает собственный вход',
        'file'  => 'app/Controllers/Admin/UsersController.php',
        'from'  => "                \$done = SessionModel::endMany((int)\$user['id'], \$ids, (int)(Auth::sessionRecord()['id'] ?? 0));",
        'to'    => "                \$done = SessionModel::endMany((int)\$user['id'], \$ids, 0);",
        'check' => 'tests/admin_people.php',
    ],
    [
        'why'   => '«Устройства» в профиле не понимают список сессий устройства',
        'file'  => 'app/Controllers/ProfileController.php',
        'from'  => "(string)(\$_POST['session_ids'] ?? \$_POST['session_id'] ?? '')",
        'to'    => "(string)(\$_POST['session_id'] ?? '')",
        'check' => 'tests/admin_people.php',
    ],
    [
        'why'   => 'суперадмину снова предлагают заблокировать и удалить',
        'file'  => 'templates/admin/user/actions.php',
        'from'  => "\$targetSuper = in_array('superadmin', App\\Models\\User::globalRoleSlugs(\$uid), true);",
        'to'    => "\$targetSuper = false;",
        'check' => 'tests/admin_people.php',
    ],
    [
        'why'   => 'колонка имени снова раздувается по полному имени',
        'file'  => 'templates/admin/users.php',
        'from'  => '<a class="cell-main truncate" href=',
        'to'    => '<a class="cell-main" href=',
        'check' => 'tests/admin_people.php',
    ],
    /* v4.93: роли и полоса «Сохранить». */
    [
        'why'   => 'в списке ролей число людей снова голой цифрой',
        'file'  => 'templates/admin/roles.php',
        'from'  => "<?php if (\$n > 0): ?><a href=\"/admin/users?role=<?= (int)\$r['id'] ?>\">",
        'to'    => "<?php if (\$n > 0): ?><a href=\"/admin/roles/<?= (int)\$r['id'] ?>\">",
        'check' => 'tests/admin_lists.php',
    ],
    [
        'why'   => 'у роли портала снова машинное «client_N»',
        'file'  => 'templates/admin/role_card.php',
        'from'  => "    [\$role['slug']],",
        'to'    => "    array_values(array_filter([\$role['slug'], \$role['client_id'] ? 'client_' . (int)\$role['client_id'] : null])),",
        'check' => 'tests/admin_lists.php',
    ],
    [
        'why'   => 'полоса «Сохранить» снова плывёт над нетронутой формой',
        'file'  => 'public/assets/css/app.css',
        'from'  => '.form-actions.is-clean { position: static; }',
        'to'    => '.form-actions.is-clean { }',
        'check' => 'tests/admin_lists.php',
    ],
    /* v4.94: журналы. */
    [
        'why'   => 'обложки и шрифты снова пишутся в журнал запросов',
        'file'  => 'app/Core/ActivityLog.php',
        'from'  => "|avatars|avatar|photo|pwa|cover|fonts)/~',",
        'to'    => "|avatars|avatar|photo|pwa)/~',",
        'check' => 'tests/admin_logs.php',
    ],
    [
        'why'   => 'стуки сканеров снова забивают «Запросы»',
        'file'  => 'app/Core/ActivityLog.php',
        'from'  => "if ((\$f['knocks'] ?? true) === false) { \$where[] = 'NOT (' . self::KNOCK . ')'; }",
        'to'    => "",
        'check' => 'tests/admin_logs.php',
    ],
    [
        'why'   => 'стуки скрыты и при поиске по адресу',
        'file'  => 'app/Controllers/Admin/LogsController.php',
        'from'  => "            'knocks' => (\$_GET['show'] ?? '') === 'all' || trim((string)(\$_GET['ip'] ?? '')) !== ''",
        'to'    => "            'knocks' => (\$_GET['show'] ?? '') === 'all'",
        'check' => 'tests/admin_logs.php',
    ],
    [
        'why'   => 'не сказано, сколько стуков скрыто и кто стучал',
        'file'  => 'templates/admin/logs_requests.php',
        'from'  => "<?php if (\$knocks !== null && \$knocks['n'] > 0): ?>",
        'to'    => "<?php if (false): ?>",
        'check' => 'tests/admin_logs.php',
    ],
    [
        'why'   => 'журнал приложения снова «Ошибки приложения»',
        'file'  => 'app/helpers.php',
        'from'  => "\$tabs += ['errors' => ['/admin/logs/errors', t('Журнал приложения')],",
        'to'    => "\$tabs += ['errors' => ['/admin/logs/errors', t('Ошибки приложения')],",
        'check' => 'tests/admin_logs.php',
    ],
    [
        'why'   => 'срок хранения 0 снова «Хранится 0 дн.»',
        'file'  => 'templates/admin/logs_requests.php',
        'from'  => "<?= esc(\$days > 0 ? t('Хранится :n :days.'",
        'to'    => "<?= esc(\$days >= 0 ? t('Хранится :n :days.'",
        'check' => 'tests/admin_logs.php',
    ],
    /* v4.95: боты на регистрации. */
    [
        'why'   => 'регистрация с устаревшего браузера снова проходит',
        'file'  => 'app/Controllers/RegistrationController.php',
        'from'  => "\$outdated = \\App\\Core\\BotSignal::refuseOutdated() ? \\App\\Core\\BotSignal::outdated(request_ua()) : null;",
        'to'    => "\$outdated = null;",
        'check' => 'tests/admin_bots.php',
    ],
    [
        'why'   => 'Chrome 39 снова считается живым браузером',
        'file'  => 'app/Core/BotSignal.php',
        'from'  => "private const FLOOR = ['chrome' => 100,",
        'to'    => "private const FLOOR = ['chrome' => 30,",
        'check' => 'tests/admin_bots.php',
    ],
    [
        'why'   => 'Internet Explorer снова не узнаётся',
        'file'  => 'app/Core/BotSignal.php',
        'from'  => "if (preg_match('~MSIE |Trident/~', \$ua)) return 'Internet Explorer';",
        'to'    => "",
        'check' => 'tests/admin_bots.php',
    ],
    [
        'why'   => 'в «Похоже на ботов» снова попадают подтвердившие почту',
        'file'  => 'app/Core/BotSignal.php',
        'from'  => "WHERE u.status IN ('pending', 'active') AND u.email_verified_at IS NULL\n               AND NOT EXISTS (SELECT 1 FROM {user_client_access} x WHERE x.user_id = u.id)\n             ORDER BY",
        'to'    => "WHERE u.status IN ('pending', 'active')\n               AND NOT EXISTS (SELECT 1 FROM {user_client_access} x WHERE x.user_id = u.id)\n             ORDER BY",
        'check' => 'tests/admin_bots.php',
    ],
    [
        'why'   => 'в «Похоже на ботов» снова попадают пришедшие в портал',
        'file'  => 'app/Core/BotSignal.php',
        'from'  => "AND u.email_verified_at IS NULL\n               AND NOT EXISTS (SELECT 1 FROM {user_client_access} x WHERE x.user_id = u.id)\n             ORDER BY",
        'to'    => "AND u.email_verified_at IS NULL\n             ORDER BY",
        'check' => 'tests/admin_bots.php',
    ],
    [
        'why'   => 'толпа с одной подсети снова не сильный признак',
        'file'  => 'app/Core/BotSignal.php',
        'from'  => "(\$crowd[\$r['net']] ?? 0) >= self::CROWD",
        'to'    => "(\$crowd[\$r['net']] ?? 0) > 99",
        'check' => 'tests/admin_bots.php',
    ],
    [
        'why'   => 'удаление пачкой снова берёт любые id из формы',
        'file'  => 'app/Controllers/Admin/BotsController.php',
        'from'  => "\$pick = array_values(array_filter(BotSignal::suspects(),\n            static fn(array \$s): bool => in_array((int)\$s['id'], \$want, true)));",
        'to'    => "\$pick = array_values(array_filter(array_map(static fn(int \$id) => \\App\\Models\\User::find(\$id), \$want)));",
        'check' => 'tests/admin_bots.php',
    ],
    [
        'why'   => '«Удалить и закрыть их подсети» снова не закрывает',
        'file'  => 'app/Controllers/Admin/BotsController.php',
        'from'  => "if ((\$_POST['block'] ?? '') === '1') {",
        'to'    => "if (false) {",
        'check' => 'tests/admin_bots.php',
    ],
    [
        'why'   => 'адреса снова не рядом с журналами',
        'file'  => 'app/helpers.php',
        'from'  => "\$tabs['blocks'] = ['/admin/blocks', t('Адреса')];",
        'to'    => "\$tabs += [];",
        'check' => 'tests/admin_bots.php',
    ],
    [
        'why'   => 'правила снова не снимаются пачкой',
        'file'  => 'app/Controllers/Admin/BlocksController.php',
        'from'  => "foreach (\$ids as \$id) IpBlock::remove(\$id);",
        'to'    => "",
        'check' => 'tests/admin_bots.php',
    ],
    /* v4.96: регистрация по шагам. */
    [
        'why'   => 'неверный код снова пускает дальше',
        'file'  => 'app/Controllers/RegistrationController.php',
        'from'  => "hash_equals((string)(\$row['payload']['code'] ?? ''), \\App\\Core\\Crypto::hashToken(\$s['email'] . ':' . \$code))",
        'to'    => "true",
        'check' => 'tests/signup_steps.php',
    ],
    [
        'why'   => 'аккаунт после кода снова рождается неподтверждённым',
        'file'  => 'app/Controllers/RegistrationController.php',
        'from'  => "'email_verified_at' => \$verified ? now() : null,",
        'to'    => "'email_verified_at' => null,",
        'check' => 'tests/signup_steps.php',
    ],
    [
        'why'   => 'ссылка из письма снова срабатывает от одного открытия',
        'file'  => 'app/Controllers/RegistrationController.php',
        'from'  => "        View::render('auth/confirm', [\n            'pageTitle' => 'Регистрация',",
        'to'    => "        \$this->continueByLink(\$token);\n        View::render('auth/confirm', [\n            'pageTitle' => 'Регистрация',",
        'check' => 'tests/signup_steps.php',
    ],
    [
        'why'   => 'на занятый адрес снова уходит код — регистрация выдаёт, кто у нас есть',
        'file'  => 'app/Controllers/RegistrationController.php',
        'from'  => "if (\$gated && !\$taken) self::sendCode();",
        'to'    => "if (\$gated) self::sendCode();",
        'check' => 'tests/signup_steps.php',
    ],
    [
        'why'   => '«Другой адрес» снова стирает введённое',
        'file'  => 'app/Controllers/RegistrationController.php',
        'from'  => "\$_SESSION['signup_retry'] = (string)(\$s['email'] ?? '');",
        'to'    => "\$_SESSION['signup_retry'] = '';",
        'check' => 'tests/signup_steps.php',
    ],
    [
        'why'   => 'капча «при регистрации» снова не спрашивается',
        'file'  => 'app/Controllers/RegistrationController.php',
        'from'  => "if (\\App\\Core\\Captcha::requiredForRegister() && !\\App\\Core\\Captcha::verify(",
        'to'    => "if (false && !\\App\\Core\\Captcha::verify(",
        'check' => 'tests/signup_steps.php',
    ],
    [
        'why'   => 'капча «при восстановлении» снова не спрашивается',
        'file'  => 'app/Controllers/RegistrationController.php',
        'from'  => "if (\\App\\Core\\Captcha::requiredForRecover() && !\\App\\Core\\Captcha::verify(",
        'to'    => "if (false && !\\App\\Core\\Captcha::verify(",
        'check' => 'tests/signup_steps.php',
    ],
    [
        'why'   => 'длинный адрес снова распирает регистрацию шире телефона',
        'file'  => 'public/assets/css/app.css',
        'from'  => "  contain: inline-size; padding: 4px 4px 4px 12px;",
        'to'    => "  padding: 4px 4px 4px 12px;",
        'check' => 'tests/signup_steps.php',
    ],
    [
        'why'   => 'приглашение снова теряется к последнему шагу',
        'file'  => 'app/Controllers/RegistrationController.php',
        'from'  => "if (!empty(\$s['invite'])) \$_POST['invite'] = \$s['invite'];",
        'to'    => "",
        'check' => 'tests/signup_steps.php',
    ],
    /* v4.97: вкладки входа без перезагрузки, вход на алиасе. */
    [
        'why'   => 'вкладки входа снова перезагружают страницу',
        'file'  => 'public/assets/js/app.js',
        'from'  => "if (!framed && !tabLink(a)) return;",
        'to'    => "if (!framed) return;",
        'check' => 'tests/login_swap.php',
    ],
    [
        'why'   => 'смена вкладки снова перерисовывает панель с обложкой',
        'file'  => 'public/assets/js/app.js',
        'from'  => "document.getElementById('main').innerHTML = next.getElementById('main').innerHTML;",
        'to'    => "document.body.innerHTML = next.body.innerHTML;",
        'check' => 'tests/login_swap.php',
    ],
    [
        'why'   => 'вкладка снова не попадает в историю — «назад» уводит со входа',
        'file'  => 'public/assets/js/app.js',
        'from'  => "if (push) history.pushState({ sso: 1 }, '', url);",
        'to'    => "if (false) history.pushState({ sso: 1 }, '', url);",
        'check' => 'tests/login_swap.php',
    ],
    [
        'why'   => 'подпись активной вкладки снова пропадает под плашкой в переходе',
        'file'  => 'public/assets/css/app.css',
        'from'  => ".auth-tabs > a:nth-of-type(2) { view-transition-name: screen-tab-2; }",
        'to'    => "",
        'check' => 'tests/login_swap.php',
    ],
    [
        'why'   => 'на алиасе портала снова пустое окно «не позволяет установить соединение»',
        'file'  => 'public/assets/js/sso.js',
        'from'  => "if (back && back !== 'null' && back !== location.origin && !e.auto) {",
        'to'    => "if (false) {",
        'check' => 'tests/login_swap.php',
    ],
    [
        'why'   => 'набор портала снова возвращает с алиаса на основной адрес',
        'file'  => 'templates/kit/SsoClient.php.tpl',
        'from'  => "if (\$host === '' || !in_array(\$host, \$aliases, true)) return \$base;",
        'to'    => "return \$base;",
        'check' => 'tests/login_swap.php',
    ],
    /* v4.98: цифры дашборда. */
    [
        'why'   => 'вход по passkey снова не считается входом',
        'file'  => 'app/Core/Stats.php',
        'from'  => "public const LOGIN_EVENTS = ['login.success', 'login.magic_link', 'login.passkey', 'login.social'];",
        'to'    => "public const LOGIN_EVENTS = ['login.success', 'login.magic_link', 'login.social'];",
        'check' => 'tests/dashboard_stats.php',
    ],
    [
        'why'   => 'дашборд снова считает входы своим списком',
        'file'  => 'app/Controllers/Admin/DashboardController.php',
        'from'  => "\$login = [Stats::loginIn(), Stats::LOGIN_EVENTS];",
        'to'    => "\$login = ['event IN (?, ?)', ['login.success', 'login.magic_link']];",
        'check' => 'tests/dashboard_stats.php',
    ],
    [
        'why'   => 'профиль снова не показывает входы по passkey и соцсетям',
        'file'  => 'app/Controllers/ProfileController.php',
        'from'  => "AND ' . \\App\\Core\\Stats::loginIn() . ' ORDER BY id DESC LIMIT 5',",
        'to'    => "AND event IN (?, ?) ' . ' ORDER BY id DESC LIMIT 5',",
        'check' => 'tests/dashboard_stats.php',
    ],
    [
        'why'   => 'сутки снова режутся по UTC',
        'file'  => 'app/Core/Stats.php',
        'from'  => "\$local = (new DateTimeImmutable('@' . (\$now ?? time())))->setTimezone(self::tz())",
        'to'    => "\$local = (new DateTimeImmutable('@' . (\$now ?? time())))->setTimezone(new DateTimeZone('UTC'))",
        'check' => 'tests/dashboard_stats.php',
    ],
    [
        'why'   => 'час из базы кладётся в UTC-день, а не в местный',
        'file'  => 'app/Core/Stats.php',
        'from'  => "\$d = (new DateTimeImmutable(\$r['h'] . ':00:00', new DateTimeZone('UTC')))->setTimezone(\$tz)->format('Y-m-d');",
        'to'    => "\$d = substr(\$r['h'], 0, 10);",
        'check' => 'tests/dashboard_stats.php',
    ],
    [
        'why'   => 'событие установки снова без подписи',
        'file'  => 'app/Core/Audit.php',
        'from'  => "        'install.done' => 'Установка завершена',\n",
        'to'    => "",
        'check' => 'tests/dashboard_stats.php',
    ],
    /* v4.99: новый пульт. */
    [
        'why'   => 'зелёное здоровье снова занимает «Требует внимания»',
        'file'  => 'app/Controllers/Admin/DashboardController.php',
        'from'  => "            if (\$h['status'] === 'ok') continue;\n",
        'to'    => "",
        'check' => 'tests/dashboard_stats.php',
    ],
    [
        'why'   => 'всплеск неудачных входов не замечен',
        'file'  => 'app/Controllers/Admin/DashboardController.php',
        'from'  => "if (\$fails >= 20 && \$fails > 3 * \$usual) {",
        'to'    => "if (false) {",
        'check' => 'tests/dashboard_stats.php',
    ],
    [
        'why'   => 'шкала графика снова до максимума, а не круглая',
        'file'  => 'app/Controllers/Admin/DashboardController.php',
        'from'  => "\$top = max(\$step, (int)ceil(\$max / \$step) * \$step);",
        'to'    => "\$top = max(1, \$max);",
        'check' => 'tests/dashboard_stats.php',
    ],
    [
        'why'   => '«сегодня» снова по часам UTC',
        'file'  => 'app/Core/Stats.php',
        'from'  => "\$h = (new DateTimeImmutable(\$r['h'] . ':00:00', new DateTimeZone('UTC')))->setTimezone(\$tz)->format('H');",
        'to'    => "\$h = substr(\$r['h'], 11, 2);",
        'check' => 'tests/dashboard_stats.php',
    ],
    [
        'why'   => 'первые шаги снова разворачиваются на весь первый экран',
        'file'  => 'templates/admin/dashboard.php',
        'from'  => "<?= \$onboarding['done'] === 0 ? ' open' : '' ?>",
        'to'    => " open",
        'check' => 'tests/dashboard_stats.php',
    ],
    [
        'why'   => 'падение неудачных входов снова красное',
        'file'  => 'app/helpers.php',
        'from'  => "\$good = \$upIsGood ? \$d >= 0 : \$d <= 0;",
        'to'    => "\$good = \$d >= 0;",
        'check' => 'tests/dashboard_stats.php',
    ],
    /* v5.0: кабинет и админка без перезагрузки. */
    [
        'why'   => 'подгрузка при наведении снова запускает выгрузки и прозвон',
        'file'  => 'public/assets/js/app.js',
        'from'  => "|\\/(export|download|cancel|logout|lang|probe)(\\/|$)/i;",
        'to'    => "|\\/(export|download|logout|lang)(\\/|$)/i;",
        'check' => 'tests/app_swap.php',
    ],
    [
        'why'   => 'ответ-файл или ошибка снова вставляются вместо экрана',
        'file'  => 'public/assets/js/app.js',
        'from'  => "if (!html || !res.r.ok || !appPath(path) ||",
        'to'    => "if (!appPath(path) ||",
        'check' => 'tests/app_swap.php',
    ],
    [
        'why'   => 'островок после подмены отмечает прежний раздел',
        'file'  => 'public/assets/js/app.js',
        'from'  => "      ['.isle', '.dock'].forEach(function (sel) {",
        'to'    => "      [].forEach(function (sel) {",
        'check' => 'tests/app_swap.php',
    ],
    [
        'why'   => 'тосты на подменённом экране снова не уходят',
        'file'  => 'public/assets/js/app.js',
        'from'  => "onPage(function () { document.querySelectorAll('.toast').forEach(function (t) {\n    if (t._ssoToast) return;",
        'to'    => "(function () { document.querySelectorAll('.toast').forEach(function (t) {\n    if (t._ssoToast) return;",
        'check' => 'tests/app_swap.php',
    ],
    [
        'why'   => '«назад» во время перехода снова теряется',
        'file'  => 'public/assets/js/app.js',
        'from'  => "if (swapBusy) { if (!init) queued = { url: url, push: push }; return; }",
        'to'    => "if (swapBusy) return;",
        'check' => 'tests/app_swap.php',
    ],
    [
        'why'   => 'высота окна во фрейме снова застывает на первом экране',
        'file'  => 'public/assets/js/app.js',
        'from'  => "      document.addEventListener('sso:page', watchSize);\n",
        'to'    => "",
        'check' => 'tests/app_swap.php',
    ],
    [
        'why'   => 'поиск снова рисует запоздавший ответ',
        'file'  => 'public/assets/js/palette.js',
        'from'  => "if (mine === seq) render(j.groups || []);",
        'to'    => "render(j.groups || []);",
        'check' => 'tests/app_swap.php',
    ],
    [
        'why'   => 'палитра снова говорит по-русски на английском интерфейсе',
        'file'  => 'public/assets/js/palette.js',
        'from'  => "esc(L('paletteNone', 'Ничего не нашлось'))",
        'to'    => "'Ничего не нашлось'",
        'check' => 'tests/app_swap.php',
    ],
    /* v5.1: аналитика входа и регистрации. */
    [
        'why'   => 'регистрация снова не пишет ввод почты',
        'file'  => 'app/Controllers/RegistrationController.php',
        'from'  => "        Audit::log('signup.started', ['actor' => null]);\n",
        'to'    => "",
        'check' => 'tests/analytics.php',
    ],
    [
        'why'   => 'подтверждение по ссылке снова не попадает в воронку',
        'file'  => 'app/Controllers/RegistrationController.php',
        'from'  => "        Audit::log('signup.verified', ['actor' => null, 'meta' => ['by' => 'link']]);\n",
        'to'    => "",
        'check' => 'tests/analytics.php',
    ],
    [
        'why'   => 'часы недели снова по UTC',
        'file'  => 'app/Core/Stats.php',
        'from'  => "\$t = (new DateTimeImmutable(\$r['h'] . ':00:00', new DateTimeZone('UTC')))->setTimezone(\$tz);",
        'to'    => "\$t = new DateTimeImmutable(\$r['h'] . ':00:00', new DateTimeZone('UTC'));",
        'check' => 'tests/analytics.php',
    ],
    [
        'why'   => 'неудача без причины снова пропадает',
        'file'  => 'app/Core/Stats.php',
        'from'  => "\$why = \$why === '' ? 'other' : \$why;",
        'to'    => "if (\$why === '') continue;",
        'check' => 'tests/analytics.php',
    ],
    [
        'why'   => 'незнакомая система снова теряется',
        'file'  => 'app/Core/Stats.php',
        'from'  => "\$os = \$parts[1] ?? t('Другая');",
        'to'    => "if (!isset(\$parts[1])) continue; \$os = \$parts[1];",
        'check' => 'tests/analytics.php',
    ],
    [
        'why'   => 'аналитика снова открыта любому админу',
        'file'  => 'app/Controllers/Admin/DashboardController.php',
        'from'  => "    public function analytics(): never\n    {\n        \$this->requireGlobalAdmin();\n",
        'to'    => "    public function analytics(): never\n    {\n",
        'check' => 'tests/analytics.php',
    ],
    [
        'why'   => 'период снова уводит с аналитики на пульт',
        'file'  => 'app/helpers.php',
        'from'  => "period_switch(\$tabs[\$current][0], \$days)",
        'to'    => "period_switch('/admin', \$days)",
        'check' => 'tests/analytics.php',
    ],
    [
        'why'   => 'пустая воронка снова молчит',
        'file'  => 'templates/admin/analytics.php',
        'from'  => "<?php if (\$funnel[0]['n'] === 0 && \$funnel[2]['n'] > 0): ?>",
        'to'    => "<?php if (false): ?>",
        'check' => 'tests/analytics.php',
    ],
    [
        'why'   => 'хвост браузеров снова на весь экран',
        'file'  => 'templates/admin/analytics.php',
        'from'  => "    if (count(\$rows) <= \$n + 1) return \$rows;",
        'to'    => "    return \$rows;",
        'check' => 'tests/analytics.php',
    ],
    [
        'why'   => 'ступени тепловой карты снова не по максимуму',
        'file'  => 'templates/admin/analytics.php',
        'from'  => "(int)ceil(\$n / \$heatMax * 4)",
        'to'    => "min(4, \$n)",
        'check' => 'tests/analytics.php',
    ],
    /* v5.2: аналитика портала и системы. */
    [
        'why'   => 'входы портала снова без прошлого периода',
        'file'  => 'app/Core/Stats.php',
        'from'  => "self::count('session_clients', 'client_id = ?', [\$cid], \$prev, \$since)],",
        'to'    => "self::count('session_clients', 'client_id = ?', [\$cid], \$prev)],",
        'check' => 'tests/analytics_portal.php',
    ],
    [
        'why'   => '«активные за неделю» снова считают с сегодняшней полуночи',
        'file'  => 'app/Core/Stats.php',
        'from'  => "'week' => \$access(6)",
        'to'    => "'week' => \$access(0)",
        'check' => 'tests/analytics_portal.php',
    ],
    [
        'why'   => 'обновления токенов снова считаются по всей установке',
        'file'  => 'app/Core/Stats.php',
        'from'  => "\$audit = static fn(string \$e): int => self::count('audit_log', 'event = ? AND client_id = ?', [\$e, \$cid], \$since);",
        'to'    => "\$audit = static fn(string \$e): int => self::count('audit_log', 'event = ?', [\$e], \$since);",
        'check' => 'tests/analytics_portal.php',
    ],
    [
        'why'   => 'стук сканеров снова считается в скорости',
        'file'  => 'app/Core/Stats.php',
        'from'  => "WHERE created_at >= ? AND NOT (user_id IS NULL AND status IN (404, 405)) GROUP BY ' . \$case",
        'to'    => "WHERE created_at >= ? GROUP BY ' . \$case",
        'check' => 'tests/analytics_portal.php',
    ],
    [
        'why'   => 'процентиль снова берётся не по накопленной доле',
        'file'  => 'app/Core/Stats.php',
        'from'  => "            \$acc += \$n;\n            if (\$acc >= \$q * \$total) return \$i;",
        'to'    => "            if (\$n >= \$q * \$total) return \$i;",
        'check' => 'tests/analytics_portal.php',
    ],
    [
        'why'   => 'единичный долгий запрос снова в «медленных»',
        'file'  => 'app/Core/Stats.php',
        'from'  => "GROUP BY path HAVING COUNT(*) >= 5 ORDER BY a DESC",
        'to'    => "GROUP BY path ORDER BY a DESC",
        'check' => 'tests/analytics_portal.php',
    ],
    [
        'why'   => '«Система» снова открыта любому админу',
        'file'  => 'app/Controllers/Admin/DashboardController.php',
        'from'  => "    public function system(): never\n    {\n        \$this->requireGlobalAdmin();\n",
        'to'    => "    public function system(): never\n    {\n",
        'check' => 'tests/analytics_portal.php',
    ],
    [
        'why'   => 'корзины снова «1000–2 с»',
        'file'  => 'templates/admin/analytics_system.php',
        'from'  => "    if ((\$lo >= 1000) === (\$hi >= 1000)) return (\$lo >= 1000 ? \$lo / 1000 : \$lo) . '–' . \$ms(\$hi);",
        'to'    => "    return \$lo . '–' . \$ms(\$hi);",
        'check' => 'tests/analytics_portal.php',
    ],
    [
        'why'   => 'доля доставленных снова округляется до успеха',
        'file'  => 'templates/admin/client/analytics.php',
        'from'  => "(int)floor(\$sent / \$events * 100)",
        'to'    => "(int)round(\$sent / \$events * 100)",
        'check' => 'tests/analytics_portal.php',
    ],
    [
        'why'   => 'у портала снова нет раздела «Аналитика»',
        'file'  => 'app/Core/ClientMap.php',
        'from'  => "            ['key' => 'analytics', 'group' => 'watch',",
        'to'    => "            ['key' => 'analytics-off', 'group' => 'watch',",
        'check' => 'tests/analytics_portal.php',
    ],
    /* v5.3: история в кабинете. */
    [
        'why'   => 'в историю снова попадают действия в админке',
        'file'  => 'app/Core/UserHistory.php',
        'from'  => "private const FOREIGN = ['admin.%', 'system.%',",
        'to'    => "private const FOREIGN = ['zz.%', 'system.%',",
        'check' => 'tests/profile_history.php',
    ],
    [
        'why'   => 'обновление токена снова засоряет историю',
        'file'  => 'app/Core/UserHistory.php',
        'from'  => "public const NOISE = ['code.issued', 'token.refresh'];",
        'to'    => "public const NOISE = ['code.issued'];",
        'check' => 'tests/profile_history.php',
    ],
    [
        'why'   => '«Неудачи» снова показывают всё',
        'file'  => 'app/Core/UserHistory.php',
        'from'  => "        } elseif (\$kind === 'alerts') {",
        'to'    => "        } elseif (\$kind === 'alerts-off') {",
        'check' => 'tests/profile_history.php',
    ],
    [
        'why'   => 'страница снова теряет продолжение',
        'file'  => 'app/Core/UserHistory.php',
        'from'  => "            \$more = (int)end(\$rows)['id'];",
        'to'    => "            \$more = null;",
        'check' => 'tests/profile_history.php',
    ],
    [
        'why'   => 'неудачи снова считаются за всё время',
        'file'  => 'app/Core/UserHistory.php',
        'from'  => "[\$userId, 'login.fail', now_plus(-\$days * 86400)]",
        'to'    => "[\$userId, 'login.fail', '1970-01-01 00:00:00']",
        'check' => 'tests/profile_history.php',
    ],
    [
        'why'   => '«сегодня» снова по UTC',
        'file'  => 'app/Core/UserHistory.php',
        'from'  => "\$day = (new \\DateTimeImmutable(\$utc, new \\DateTimeZone('UTC')))->setTimezone(\$tz)->format('Y-m-d');",
        'to'    => "\$day = (new \\DateTimeImmutable(\$utc, new \\DateTimeZone('UTC')))->format('Y-m-d');",
        'check' => 'tests/profile_history.php',
    ],
    [
        'why'   => 'неудача снова не выделена словами',
        'file'  => 'templates/profile/activity.php',
        'from'  => "<div class=\"act-item<?= \$alarm ? ' is-alarm' : '' ?>\">",
        'to'    => "<div class=\"act-item\">",
        'check' => 'tests/profile_history.php',
    ],
    [
        'why'   => 'перебор пароля снова молчит',
        'file'  => 'templates/profile/activity.php',
        'from'  => "<?php if (\$fails >= 3 && !\$continued): ?>",
        'to'    => "<?php if (false): ?>",
        'check' => 'tests/profile_history.php',
    ],
    [
        'why'   => 'у приложения снова нет ярлыка истории',
        'file'  => 'app/Controllers/PwaController.php',
        'from'  => "                ['name' => t('История'), 'url' => '/profile/activity'],\n",
        'to'    => "",
        'check' => 'tests/profile_history.php',
    ],
];

if (in_array('--list', $argv, true)) {
    foreach ($mutations as $i => $m) printf("%d. %s → %s\n", $i + 1, $m['why'], $m['check']);
    exit(0);
}

echo "═══ Проверка проверок ═══\n";
echo "Возвращаем дефект и смотрим, покраснеет ли тот, кто обязан.\n";

$passed = 0;
$failed = 0;

foreach ($mutations as $i => $m) {
    $path = SSO_ROOT . '/' . $m['file'];
    $original = (string)file_get_contents($path);

    printf("\n%d. %s\n   ломаем %s → ждём красного от %s\n",
        $i + 1, $m['why'], $m['file'], $m['check']);

    if (!str_contains($original, $m['from'])) {
        echo "   ✗ мутация не приложилась: искомого текста в файле нет\n";
        echo "     (файл изменили, а мутацию не поправили — она давно ничего не проверяет)\n";
        $failed++;
        continue;
    }

    try {
        $broken = str_replace($m['from'], $m['to'], $original);
        if (isset($m['from2'])) {
            if (!str_contains($broken, $m['from2'])) {
                echo "   ✗ вторая часть мутации не приложилась\n";
                $failed++;
                continue;
            }
            $broken = str_replace($m['from2'], $m['to2'], $broken);
        }
        file_put_contents($path, $broken);

        $out = [];
        exec("$php " . escapeshellarg(SSO_ROOT . '/' . $m['check']) . ' 2>&1', $out, $code);

        if ($code !== 0) {
            echo "   ✓ поймано (код $code)\n";
            $passed++;
        } else {
            /* Вывод проверки печатаем ИМЕННО ЗДЕСЬ. Раньше он выбрасывался
               (`unset($out)`), и сообщение «проверка слепа» приходило без
               единой улики: какая именно проверка прошла там, где должна была
               упасть, — оставалось гадать. Один раз так и вышло: слепота
               случилась внутри полного прогона, не повторилась ни в шести
               последующих запусках, и разбирать было нечего.

               Хвоста хватает: у наших батарей последние строки — это итог и
               те проверки, что шли перед ним. */
            echo "   ✗ ПРОВЕРКА СЛЕПА: дефект внесён, а она зелёная\n";
            echo "     ── что она напечатала (последние строки):\n";
            foreach (array_slice($out, -18) as $line) echo '     │ ' . $line . "\n";
            $failed++;
        }
    } finally {
        /* Возврат безусловный и сверенный: оставить проект сломанным ради
           проверки проверок — худшее, что этот файл мог бы сделать. */
        file_put_contents($path, $original);
        if ((string)file_get_contents($path) !== $original) {
            fwrite(STDERR, "НЕ ВОССТАНОВИЛСЯ: {$m['file']} — восстановите из git!\n");
            exit(2);
        }
    }
}

/* И контрольный выстрел: на нетронутом дереве все они обязаны быть зелены.
   Иначе «покраснела» ничего не значит — краснела бы и без мутации. */
echo "\n── На чистом дереве ──\n";
$seen = [];
foreach ($mutations as $m) {
    if (isset($seen[$m['check']])) continue;
    $seen[$m['check']] = true;
    exec("$php " . escapeshellarg(SSO_ROOT . '/' . $m['check']) . ' 2>&1', $out, $code);
    unset($out);
    if ($code === 0) { echo "  ✓ {$m['check']} зелена\n"; $passed++; }
    else { echo "  ✗ {$m['check']} красная БЕЗ мутации (код $code)\n"; $failed++; }
}

echo "\n══ Итог ══\n";
printf("Пройдено: %d, провалено: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
