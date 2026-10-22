<?php
/**
 * Письма на языке получателя — целиком (v4.74).
 *
 * Замечание владельца 24.09: «Если включить английский — часть письма на
 * русском, часть на английском». Тексты «как в поставке» (тема, приветствие,
 * объяснение, кнопка, подпись) шли по-русски всегда, а устройство вокруг них
 * («Или откройте ссылку», срок) — на языке письма. Здесь каждое письмо
 * рисуется на английском и в нём не должно остаться ни одной русской буквы;
 * на русском оно остаётся русским. Письмо, которое за человека отправляет
 * другой (администратор, фоновая задача), — на языке получателя; своё слово
 * владельца (переписанный текст) не переводится.
 *
 * Запуск: php tests/mail_i18n.php  (сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\Lang;
use App\Core\MailTemplates;
use App\Core\Setting;
use App\Core\View;
use App\Models\User;

$passed = 0;
$failed = 0;
$cyr = static function (string $s): string {
    $plain = html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8');
    return preg_match('~[А-Яа-яЁё][А-Яа-яЁё ,.:—«»-]{0,40}~u', $plain, $m) ? $m[0] : '';
};
/* Название установки может быть русским у кого-то — здесь оно своё, латиницей. */
$appWas = Setting::get('app.name');
Setting::set('app.name', 'Probe ID');
Setting::flush();
register_shutdown_function(static function () use ($appWas): void {
    Setting::set('app.name', $appWas);
    Setting::flush();
});

/* Каждое письмо и примерные данные к нему — латиницей, чтобы ловить только наше. */
$data = [
    'name' => 'John', 'url' => 'https://sso.example/x', 'cancel_url' => 'https://sso.example/y', 'code' => '482913',
    'new_email' => 'new@example.org', 'revert_url' => 'https://sso.example/r', 'new_issuer' => 'https://new.example',
    'old_issuer' => 'https://old.example', 'portal' => 'Portal', 'level' => 'data reset', 'title' => 'Title',
    'message' => 'Message', 'rows' => ['Key' => 'Value'], 'action_label' => 'Open', 'action_url' => 'https://sso.example/a',
    'device' => 'Chrome · Windows', 'ip' => '203.0.113.5', 'when' => '2026-09-25 10:00',
];
$letters = array_map(static fn(string $f): string => basename($f, '.php'), (array)glob(SSO_ROOT . '/templates/emails/*.php'));
$letters = array_values(array_diff($letters, ['base']));

echo "── Каждое письмо по-английски — без русских букв ──\n";
foreach ($letters as $letter) {
    try {
        [$html, $text] = Lang::using('en', static fn(): array => View::email($letter, $data));
        $left = $cyr($html) ?: $cyr($text);
        ok($left === '', "письмо «{$letter}» целиком по-английски", "осталось: «{$left}»");
    } catch (\Throwable $e) {
        ok(false, "письмо «{$letter}» рисуется", $e->getMessage());
    }
}
foreach (array_keys(MailTemplates::LETTERS) as $letter) {
    $subject = Lang::using('en', static fn(): string => MailTemplates::render($letter, $data)['subject']);
    ok($cyr($subject) === '', "тема «{$letter}» по-английски", $subject);
}
$en = Lang::using('en', static fn(): array => View::email('verify', $data));
ok(str_contains($en[0], 'lang="en"'), 'у письма указан язык письма, а не зашитый ru');

echo "\n── По-русски письмо остаётся русским ──\n";
[$ru] = Lang::using('ru', static fn(): array => View::email('verify', $data));
ok(str_contains($ru, 'Здравствуйте') && str_contains($ru, 'Подтвердить почту') && str_contains($ru, 'lang="ru"'),
    'русское письмо — на русском');

echo "\n── Своё слово владельца не переводится ──\n";
MailTemplates::save('verify', 'body', 'Своё объяснение владельца');
$own = Lang::using('en', static fn(): array => View::email('verify', $data));
ok(str_contains($own[0], 'Своё объяснение владельца'), 'переписанный текст идёт как написан');
MailTemplates::save('verify', 'body', '');
Setting::flush();

echo "\n── Тосты и заголовки страниц переводятся при выводе ──\n";
/* Контроллеры передают их строкой — раскладка зовёт t() (v4.74). До этого
   английский интерфейс показывал «Неверный логин или пароль.» по-русски. */
$_SESSION['_flash'] = [['type' => 'error', 'message' => 'Неверный логин или пароль.']];
$layout = Lang::using('en', static fn(): string => View::fetch('layouts/auth', ['content' => '', 'pageTitle' => 'Вход', 'popup' => true]));
ok(!str_contains($layout, 'Неверный логин') && str_contains($layout, Lang::using('en', static fn(): string => t('Неверный логин или пароль.'))),
    'тост на экране входа — по-английски');
ok((bool)preg_match('~<title>' . preg_quote(Lang::using('en', static fn(): string => t('Вход')), '~') . ' — ~u', $layout),
    'заголовок страницы — по-английски');

echo "\n── Письмо за человека — на его языке ──\n";
$email = 'mail-i18n' . substr((string)time(), -6) . '@test.local';
DB::run('DELETE FROM {users} WHERE email = ?', [$email]);
$uid = User::create(['email' => $email, 'first_name' => 'John', 'status' => 'active', 'locale' => 'en']);
$mailId = Lang::using('ru', static fn(): int => \App\Core\Mailer::sendLetter($email, 'reset', ['name' => 'John', 'url' => 'https://sso.example/r']));
$row = DB::row('SELECT subject, body_html FROM {mail_queue} WHERE id = ?', [$mailId]);
ok($row !== null && $cyr((string)$row['subject']) === '' && $cyr((string)$row['body_html']) === '',
    'администратор на русском отправил англоязычному — письмо по-английски', (string)($row['subject'] ?? 'нет письма'));
DB::run('DELETE FROM {mail_queue} WHERE id = ?', [$mailId]);

echo "\n── Оповещение, которое будит другой, — на языке получателя ──\n";
/* Незнакомец подбирает пароль из браузера на русском: письмо владельцу
   аккаунта и оповещение администратору обязаны прийти на их языке. До 4.74
   текст собирался до отправки — на языке того, кто разбудил событие, а тема
   оповещения не переводилась вовсе. */
$keep = [];
foreach (['notify.admin.user.registered', 'notify.admin.to', 'notify.admin.at.user.registered', 'notify.admin.held.user.registered'] as $k) {
    $keep[$k] = Setting::get($k);
}
Setting::set('notify.admin.user.registered', '1');
Setting::set('notify.admin.to', $email);
Setting::set('notify.admin.at.user.registered', '');
Setting::flush();
$before = (int)DB::val('SELECT COALESCE(MAX(id), 0) FROM {mail_queue}');
Lang::using('ru', static fn() => \App\Core\AdminNotify::say('user.registered',
    fn() => t('Новый аккаунт: :who.', ['who' => 'probe@example.org']),
    fn() => [t('Когда') => '2026-09-25 10:00']));
$alert = DB::row('SELECT subject, body_html FROM {mail_queue} WHERE id > ? AND to_email = ? ORDER BY id DESC LIMIT 1', [$before, $email]);
ok($alert !== null, 'оповещение поставлено в очередь');
ok($alert !== null && $cyr((string)$alert['subject']) === '' && $cyr((string)$alert['body_html']) === '',
    'оповещение англоязычному администратору — по-английски, с темой',
    $alert ? ($cyr((string)$alert['subject']) ?: $cyr((string)$alert['body_html'])) : 'нет письма');
DB::run('DELETE FROM {mail_queue} WHERE id > ? AND to_email = ?', [$before, $email]);
foreach ($keep as $k => $v) Setting::set($k, $v);
Setting::flush();

echo "\n── Склонение и подписи списков — на языке интерфейса ──\n";
/* plural() отдавал русское слово всегда: «5 ключей» в английской панели. */
ok(Lang::using('en', static fn(): string => plural(5, 'ключ', 'ключа', 'ключей')) === 'keys'
    && Lang::using('en', static fn(): string => plural(1, 'ключ', 'ключа', 'ключей')) === 'key'
    && Lang::using('en', static fn(): string => plural(21, 'ключ', 'ключа', 'ключей')) === 'keys',
    'plural по-английски: 1 key, 5 keys, 21 keys');
ok(Lang::using('en', static fn(): string => plural(3, 'человек', 'человека', 'человек')) === 'people',
    'одинаковые «один» и «много» — множественное из «few»');
ok(Lang::using('ru', static fn(): string => plural(22, 'ключ', 'ключа', 'ключей')) === 'ключа',
    'по-русски — три формы, как было');
/* Подпись устройства (v4.74.1): на экране — на языке экрана, а сама
   device_label() от языка не зависит — из неё собирается отпечаток
   «знакомого устройства». Переведи её на месте — смена языка стала бы
   «входом с нового устройства» и ложным письмом. */
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 YaBrowser/24.7 Safari/537.36';
ok(Lang::using('en', static fn(): string => device_text(device_label($ua))) === 'Yandex Browser · Windows',
    'подпись устройства по-английски: Yandex Browser · Windows');
ok(Lang::using('en', static fn(): string => device_label($ua)) === Lang::using('ru', static fn(): string => device_label($ua)),
    'отпечаток устройства одинаков на любом языке — смена языка не «новое устройство»');
ok(Lang::using('en', static fn(): string => (new \App\Social\YandexProvider())->title()) === 'Yandex ID',
    'кнопка входа через Яндекс ID по-английски — Yandex ID');
$html = Lang::using('en', static fn(): string => kv_list(['Создан' => '2026-09-25']));
ok(!str_contains($html, 'Создан'), 'подпись в списке «ключ — значение» переводится');
/* Подписи уведомлений в профиле человека выводились без t() — английский
   профиль показывал «Вход с нового устройства». Названия языков в выборе
   языка — на своём языке нарочно, их не считаем. */
$labels = Lang::using('en', static fn(): string => View::fetch('profile/settings', [
    'user' => User::find($uid), 'timezones' => tz_options(), 'tzSelected' => 'UTC',
    'exportJob' => null, 'cronAlive' => true, 'exportReady' => true,
    'notifyPrefs' => \App\Core\Notify::prefsFor($uid),
]));
$left = $cyr(preg_replace('~<option[^>]*>[^<]*</option>~u', '', $labels));
ok($left === '', 'настройки профиля по-английски, с подписями уведомлений', "осталось: «{$left}»");
DB::run('DELETE FROM {users} WHERE id = ?', [$uid]);

echo "\n── Строки app.js — из словаря страницы ──\n";
/* До 4.74 словарь для JS выдавала только раскладка панели, а часть строк
   была вшита в скрипт: на английской форме входа «show» после щелчка
   становился «скрыть», кнопка темы подписывалась «Тема: авто». Русская строка
   в app.js допустима только запасной — вторым аргументом L() или после ||. */
/* passkey.js (v4.75) — тот же словарь: его подписи стоят на экране входа. */
$code = (string)file_get_contents(SSO_ROOT . '/public/assets/js/app.js')
    . "\n" . (string)file_get_contents(SSO_ROOT . '/public/assets/js/passkey.js');
$code = (string)preg_replace('~/\*.*?\*/~s', '', $code);
$code = (string)preg_replace('~(^|[^:\'"\\\\])//[^\n]*~m', '$1', $code);
preg_match_all('~\'((?:[^\'\\\\\n]|\\\\.)*[А-Яа-яЁё](?:[^\'\\\\\n]|\\\\.)*)\'~u', $code, $lits, PREG_OFFSET_CAPTURE);
$bare = [];
foreach ($lits[0] as $i => [$lit, $pos]) {
    $before = substr($code, max(0, $pos - 40), min(40, $pos));
    if (preg_match('~(L\(\'[a-zA-Z]+\',\s*|\|\|\s*)$~', $before)) continue;
    $bare[] = $lits[1][$i][0];
}
/* Данные, а не подписи: слабые пароли для счётчика и адрес модалки. */
$bare = array_values(array_diff($bare, ['пароль', 'йцукен', '#открыть-']));
ok($bare === [], 'в app.js и passkey.js нет русских строк мимо словаря', implode(' | ', $bare));
preg_match_all("~\bL\('([a-zA-Z]+)'~", $code, $used);
$page = Lang::using('en', static fn(): string => js_i18n());
$dict = preg_match('~window\.ssoI18n = (\{.*\});~s', $page, $jm) ? (array)json_decode($jm[1], true) : [];
$absent = array_values(array_diff(array_unique($used[1]), array_keys($dict)));
ok($dict !== [] && $absent === [], 'каждый ключ app.js и passkey.js есть в словаре страницы', implode(', ', $absent));
$ruLeft = array_filter($dict, static fn($v): bool => (bool)preg_match('~[А-Яа-яЁё]~u', (string)$v));
ok($ruLeft === [], 'словарь страницы по-английски целиком', implode(' | ', $ruLeft));
foreach (['auth', 'base', 'guest', 'minimal'] as $layout) {
    $src = (string)file_get_contents(SSO_ROOT . "/templates/layouts/{$layout}.php");
    ok(str_contains($src, '<?= js_i18n() ?>'), "раскладка «{$layout}» отдаёт словарь для app.js");
}

echo "\n" . ($failed === 0 ? "✓ все $passed проверок" : "✗ провалов: $failed из " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
