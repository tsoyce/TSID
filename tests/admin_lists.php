<?php
/**
 * Списки порталов и организаций (v4.90).
 *
 * Владелец 27.09: «переходим к основным экранам — изучаем, смотрим, логично ли,
 * красиво ли, формируем изменения, переписываем». Первым шагом — списки. Здесь
 * проверяется, что:
 *   · у организации свой знак той же формы, что у портала, — в списке и в карточке;
 *   · плитка портала говорит, как входят, чей он, сколько людей и не застряли
 *     ли события; пустой — «пока никого», а не «0 пользователей»;
 *   · плитка организации — сколько участников и сервисов, без машинного слага;
 *   · объяснение над организациями — строкой под заголовком, а не плашкой;
 *   · дни — словом («вчера», «3 дня назад»), статусы — по-русски.
 *
 * Запуск: php tests/admin_lists.php  (сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\DB;
use App\Core\Lang;
use App\Core\View;
use App\Models\Client;
use App\Models\Org;

$passed = 0;
$failed = 0;
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);
$ago = static fn(int $sec): string => gmdate('Y-m-d H:i:s', time() - $sec);

echo "── Время и статусы словами ──\n";
ok(fmt_ago($ago(30)) === 'только что' && fmt_ago($ago(3 * 3600)) === '3 ч назад', 'минуты и часы — как были');
ok(fmt_ago($ago(36 * 3600)) === 'вчера', 'сутки назад — «вчера», а не «1 дн назад»');
ok(fmt_ago($ago(3 * 86400)) === '3 дня назад' && fmt_ago($ago(5 * 86400)) === '5 дней назад' && fmt_ago($ago(21 * 86400)) === '21 день назад',
    'дни — словом и по числу');
ok(Lang::using('en', static fn(): string => fmt_ago(gmdate('Y-m-d H:i:s', time() - 3 * 86400))) === '3 days ago'
    && Lang::using('en', static fn(): string => fmt_ago(gmdate('Y-m-d H:i:s', time() - 36 * 3600))) === 'yesterday',
    'и по-английски');
ok(str_contains(access_badge('active'), 'открыт') && str_contains(access_badge('pending'), 'заявка') && str_contains(access_badge('blocked'), 'закрыт'),
    'доступ к порталу — одной плашкой и по-русски');
ok(str_contains(mail_status_badge('sent'), 'отправлено') && str_contains(mail_status_badge('failed'), 'не ушло'), 'письма — тоже');
ok(str_contains($src('templates/admin/user/access.php'), '<?= access_badge((string)$a[\'status\']) ?>')
    && str_contains($src('templates/admin/client/people.php'), '<?= access_badge((string)$u[\'access_status\']) ?>')
    && str_contains($src('templates/admin/logs_mail.php'), '<?= mail_status_badge((string)$m[\'status\']) ?>'),
    'в карточке человека, в людях портала и в журнале почты — одна и та же плашка');

echo "\n── Знак организации ──\n";
$mark = org_icon_html(['name' => 'Комбинат «Заря»', 'slug' => 'zarya'], 36);
ok(str_contains($mark, '>КЗ</span>') && str_contains($mark, 'width:36px;height:36px;border-radius:9px'),
    'две буквы названия (кавычки не в счёт), форма — как у портала');
ok(\App\Core\OrgMap::badge(['name' => 'Комбинат Заря', 'slug' => 'zarya']) === org_icon_html(['name' => 'Комбинат Заря', 'slug' => 'zarya'], 40),
    'в карточке — тот же знак, что в списке');

echo "\n── Плитка портала ──\n";
$base = ['id' => 1, 'slug' => 'p', 'icon_path' => null, 'is_active' => 1, 'is_trusted' => 1, 'access_policy' => 'open',
    'require_2fa' => 0, 'same_site_family' => 0, 'login_display' => null, 'last_activity' => null, 'events_stuck' => 0, 'org_name' => null];
$list = View::fetch('admin/clients', ['canCreate' => false, 'wizardOrgId' => null, 'clients' => [
    ['name' => 'Портфолио', 'base_url' => 'https://portfolio.example/', 'login_display' => 'popup', 'users_count' => 4,
        'last_activity' => $ago(3 * 86400), 'events_stuck' => 2, 'org_name' => 'Комбинат Заря', 'is_trusted' => 0] + $base,
    ['name' => 'Пустой', 'base_url' => 'https://empty.example', 'users_count' => 0] + $base,
]]);
ok(str_contains($list, '<div class="hint list-card-sub">portfolio.example · Комбинат Заря</div>'), 'под именем — адрес без протокола и владелец');
ok(str_contains($list, 'вход окном поверх сайта') && str_contains($list, 'сторонний'), 'метки — как входят и чем отличается');
ok(str_contains($list, '4 человека') && str_contains($list, 'вход 3 дня назад') && str_contains($list, '2 события не доставлены'),
    'внизу — люди, последний вход и застрявшие события');
ok(str_contains($list, 'пока никого') && !str_contains($list, '0 пользователей'), 'пустой портал — «пока никого»');

echo "\n── Плитка организации ──\n";
$orgs = View::fetch('admin/orgs', ['orgs' => [
    ['id' => 1, 'name' => 'Комбинат Заря', 'slug' => 'kombinat-zarya', 'description' => 'Производство', 'is_active' => 1, 'members' => 6, 'projects' => 3],
    ['id' => 2, 'name' => 'Отдел кадров', 'slug' => 'otdel', 'description' => null, 'is_active' => 1, 'members' => 1, 'projects' => 0],
]]);
ok(str_contains($orgs, '>КЗ</span>') && str_contains($orgs, '6 участников') && str_contains($orgs, '3 сервиса') && str_contains($orgs, 'сервисов нет'),
    'знак, участники и сервисы');
ok(!str_contains($orgs, 'kombinat-zarya'), 'машинного слага в плитке нет — его копируют из карточки');
ok(str_contains($orgs, '<p class="muted mb-16 page-lede">Что выдано организации') && !str_contains($orgs, 'class="callout'),
    'объяснение — строкой под заголовком, а не плашкой над списком');
$oid = Org::create('Проба списков ' . getmypid(), null, null);
[$cid] = Client::create(['name' => 'Проба списков', 'slug' => 'lists-' . getmypid(), 'base_url' => 'https://lists.example',
    'redirect_uris' => ['https://lists.example/cb'], 'post_logout_uris' => [], 'backchannel_uri' => '']);
DB::run('UPDATE {clients} SET org_id = ? WHERE id = ?', [$oid, (int)$cid]);
$row = array_values(array_filter(Org::all(), static fn(array $o): bool => (int)$o['id'] === $oid))[0] ?? [];
ok((int)($row['projects'] ?? -1) === 1, 'число сервисов считает список организаций сам, одним запросом');
Client::purge((int)$cid);
Org::purge($oid);

$ctl = $src('app/Controllers/Admin/ClientsController.php');
ok(str_contains($ctl, "SELECT client_id, COUNT(*) AS n FROM {client_events} WHERE status <> 'sent' GROUP BY client_id"),
    'застрявшие события — одним запросом на весь список, а не по запросу на плитку');
$css = $src('public/assets/css/app.css');
ok(str_contains($css, '.list-card-foot .is-warn, .list-card-foot .is-warn .icon { color: var(--warning); }')
    && !str_contains($css, '.rail-letters {'), 'предупреждение — цветом; бледный круг вместо знака убран');

/* v4.93: список ролей и карточка роли. */
echo "\n── Список ролей ──\n";
$role = static fn(array $r): array => $r + ['description' => null, 'users_count' => 0, 'access_level' => 20,
    'is_system' => 1, 'client_id' => null, 'client_slug' => null];
$rl = View::fetch('admin/roles', ['isGlobalAdmin' => true, 'clients' => [], 'byClient' => [], 'system' => [
    $role(['id' => 7, 'slug' => 'client_admin', 'name' => 'Админ портала', 'description' => 'Люди и роли своего портала',
        'users_count' => 6, 'access_level' => 70]),
    $role(['id' => 8, 'slug' => 'editor', 'name' => 'Редактор', 'is_system' => 0]),
]]);
ok(strpos($rl, '>Админ портала</a>') !== false && strpos($rl, '>Админ портала</a>') < strpos($rl, '<span class="mono">client_admin</span>'),
    'название роли впереди, машинное имя — строкой под ним');
ok(str_contains($rl, '<a href="/admin/users?role=7">6 человек</a>') && str_contains($rl, '>никого<'),
    'число людей — ссылкой на список с этой ролью; пустая — «никого», а не 0');
ok(!str_contains($rl, '>Права<')
    && substr_count($rl, '/delete"') === 1, 'кнопки «Права» нет — название и есть ссылка; удалить можно только свою роль');

ok(substr_count($rl, '<td class="text-right"></td>') === 1
    && str_contains($css, '.table-cards td:not([data-label]):not(.cell-avatar):empty { display: none; }'),
    'у встроенной роли ячейка действий пустая — на телефоне под карточкой нет лишнего отступа');

echo "\n── Карточка роли ──\n";
$rc = View::fetch('admin/role_card', ['role' => ['id' => 9, 'slug' => 'reader', 'name' => 'Читатель', 'client_id' => 987654,
    'access_level' => 20, 'is_system' => 0, 'description' => null], 'perms' => [], 'usersCount' => 3, 'locked' => false]);
ok(!str_contains($rc, 'client_987654') && str_contains($rc, 'роль портала #987654'),
    'у роли портала — портал, а не машинное «client_N»');
ok(str_contains($rc, '<a class="badge badge-muted" href="/admin/users?role=9">3 человека</a>'), 'число людей — ссылкой');

echo "\n── Полоса «Сохранить» ──\n";
$js = $src('public/assets/js/app.js');
ok(str_contains($css, '.form-actions.is-clean { position: static; }')
    && str_contains($js, "if (form && others.length === 0) {\n        bar.classList.add('is-clean');"),
    'нетронутая форма: полоса стоит в конце и не плывёт поверх содержимого');
ok(str_contains($js, "form.addEventListener('input', touched);") && str_contains($js, "if (b && b.type !== 'submit' && !bar.contains(b)) touched();"),
    'первая правка — поле или кнопка, заполняющая форму, — и полоса прилипает');
ok(str_contains($js, "bar.classList.toggle('is-rest', !stuck || bar.classList.contains('is-clean'));"),
    'на месте полоса без подложки');

echo "\n" . ($failed === 0 ? "✓ все $passed проверок" : "✗ провалов: $failed из " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
